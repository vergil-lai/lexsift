# LexSift

LexSift 是使用 Rust 和 [ext-php-rs](https://github.com/extphprs/ext-php-rs) 实现的 PHP 扩展，以 Rust [aho-corasick](https://github.com/BurntSushi/aho-corasick) 为后端，适用于敏感词检测、关键词检索和文本替换。支持全角与半角转换、大小写统一、忽略空白等文本处理，并提供重叠匹配、短语白名单和原文脱敏。

通过 `LexSift\Matcher` 创建独立的匹配器实例。词库在构造或替换时编译，后续查询直接复用。

## 环境要求

- PHP 8.1 或以上，以及与目标 PHP 匹配的开发头文件、`phpize`、`php-config`。
- Rust stable 工具链和 Cargo，需支持 Rust 2024 edition；建议使用当前 stable。
- C 编译器、Clang/libclang、make、autoconf 和平台开发工具。
- 通过 PIE 安装时还需 [PIE](https://github.com/php/pie)，并满足 PIE 自身的运行要求。

Linux 通常需要发行版的 PHP 开发包；macOS 需要 Xcode Command Line Tools 和包含开发工具的 PHP 安装。`php`、`phpize`、`php-config` 必须对应同一套 PHP。**源码目录及其父目录不能含空格**，这是本项目使用的 phpize/configure 构建路径的限制。

## 安装

### PIE

使用 PIE 安装并检查扩展：

```sh
pie install vergil-lai/lexsift
php --ri lexsift
```

应用可在 `composer.json` 中声明 `"ext-lexsift": "*"`。本仓库的 Composer 包仅用于 PIE 安装，不包含 PHP 用户态实现。IDE 类型声明见 [stub](stubs/lexsift.stub.php)。

### Docker（install-php-extensions）

在自己的官方 PHP 镜像 Dockerfile 中，安装好 Rust/Cargo 和 Clang/libclang 后，添加：

```dockerfile
COPY --from=ghcr.io/mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions "vergil-lai/lexsift@<commit-or-tag>"
```

将 `<commit-or-tag>` 替换为包含 `package.xml` 的提交或版本标签。安装后会自动启用扩展。当前需使用这种[源码安装方式](https://github.com/mlocati/docker-php-extension-installer#installing-an-extension-from-its-source-code)，暂不支持直接运行 `install-php-extensions lexsift`。

完整的依赖安装和多阶段构建示例见 [Dockerfile](docker/Dockerfile)。如需从本地源码构建验证镜像：

```sh
docker build -f docker/Dockerfile -t lexsift-php .
docker run --rm lexsift-php php --ri lexsift
```

### 源码构建

在不含空格的仓库目录执行：

```sh
sh scripts/build.sh
php -n -d extension="$PWD/target/php-build/modules/lexsift.so" --ri lexsift
```

选择另一套 PHP 开发工具时：

```sh
PHP_CONFIG=/path/to/php-config PHPIZE=/path/to/phpize sh scripts/build.sh
```

构建产物位于 `target/php-build/modules/lexsift.so`。如需持久启用，可执行 `make -C target/php-build install`，在目标 PHP 的配置中添加 `extension=lexsift.so`，并重启对应服务。

## 使用示例

以下示例展示匹配、脱敏和更新词库：

```php
<?php
declare(strict_types=1);

$filter = new LexSift\Matcher(
    terms: ['赌博', '博彩', 'bad word', '微信'],
    whitelist: ['合法博彩说明', '微信支付'],
    options: ['lowercase' => true, 'remove_emoji' => true],
);

$text = '前赌 博后，微信支付';
var_dump($filter->contains($text));
print_r($filter->scan($text));
echo $filter->mask($text), "\n";

$filter->replaceTerms(['新的词语', '另一个词语']);
$filter->replaceWhitelist(['允许出现的完整短语']);
var_dump($filter->contains('新的词语'));
```

`scan()` 对 `赌 博` 的命中如下；空格属于原文匹配范围：

```php
[
    'term' => '赌博',
    'text' => '赌 博',
    'start' => 3,
    'end' => 10,
]
```

## API 与输入约定

| 方法                                                                    | 行为                                       |
| ----------------------------------------------------------------------- | ------------------------------------------ |
| `__construct(array $terms, array $whitelist = [], array $options = [])` | 构建独立实例；未指定的选项使用默认值       |
| `contains(string $text): bool`                                          | 找到首个未被白名单排除的匹配即停止匹配迭代 |
| `scan(string $text): array`                                             | 返回所有有效重叠匹配                       |
| `mask(string $text, string $replacement = '*'): string`                 | 合并有效原文范围后替换                     |
| `replaceTerms(array $terms): void`                                      | 完整替换当前实例词库                       |
| `replaceWhitelist(array $whitelist): void`                              | 完整替换当前实例白名单                     |

词库与白名单只接受字符串值，数组键不参与匹配，顺序采用 PHP 数组遍历顺序。空数组合法；空字符串及经过文本处理后为空的词抛出 `ValueError`。原始重复词和处理后相同的词均保留首次出现者，包括返回的原始 `term`。替换操作成功后立即生效，失败时保留旧状态，不影响其他实例。

所有文本入口，包括词库、白名单、正文和 replacement，必须为合法 UTF-8；损坏字节抛出 `ValueError`，不会被静默修复。类型错误抛出 `TypeError`；未知或非法 options 键抛出 `ValueError`。不提供模糊匹配、拼音、词干分析、持久化或全局词库。

## 文本处理选项

仅接受以下六个布尔选项；可只传其中部分，`0`、`1` 或字符串不代替布尔值。

| 选项                 | 默认值  | 行为                                                       |
| -------------------- | ------- | ---------------------------------------------------------- |
| `unicode_nfkc`       | `true`  | 统一字符形式（Unicode NFKC），包括全角转换、字符展开与组合 |
| `lowercase`          | `true`  | Unicode 逐码点小写转换                                     |
| `remove_whitespace`  | `true`  | 移除 Unicode 空白                                          |
| `remove_punctuation` | `false` | 移除 Unicode 标点类别字符                                  |
| `remove_symbols`     | `false` | 移除 Unicode 符号类别字符                                  |
| `remove_emoji`       | `true`  | 按原始 extended grapheme cluster 整簇移除 emoji            |

词库、白名单和正文始终使用同一套文本处理规则。这些处理只用于匹配，返回的原词、命中文本和未命中的原文不会被改写。

小写使用 Rust `char::to_lowercase()`，允许一字符展开为多字符；不执行 Unicode case folding、语言环境相关转换或希腊 final sigma 等上下文映射，也不删除变音符。

Emoji 使用宽泛规则：原始 grapheme 含 Extended_Pictographic、Emoji_Presentation、Emoji_Modifier、Regional_Indicator、VS16 或 keycap enclosing mark 时整簇删除，涵盖 ZWJ、肤色、旗帜及 keycap。普通数字、`#`、`*` 保留；`©`、`©︎`、`©️` 均会被删除。这不等同于仅删除 RGI emoji；需要保留这些符号时设置 `remove_emoji: false`。

## 白名单与字节偏移

白名单是普通字符串短语。经过文本处理后，敏感词匹配范围完整包含于**某一个**白名单匹配范围时才被忽略；部分重叠不豁免，多个白名单范围也不会联合形成豁免。例如 `微信支付` 可豁免其中的 `微信`，但白名单 `f` 不会豁免原文 `ﬁ` 经 NFKC 展开后的敏感词 `i`。

`scan()` 返回普通数组，每项只有 `term`、`text`、`start`、`end`。`term` 为词库原词，`text` 为命中的原文切片。偏移为原始 UTF-8 字符串的**字节偏移**，采用 `[start, end)`，保证 `substr($text, $start, $end - $start)` 等于匹配项的 `text`，不是字符序号。

支持重叠，按 start 升序、同起点较长范围优先排列；相同范围的不同词按词库顺序排列。同一词映射到同一原文范围时只返回一次。位置映射覆盖完整来源 grapheme，因此组合字符不会被从中截断；跨越被移除字符时，这些内部字符也包含在匹配跨度中。独立的边缘被移除字符不会被扩大包含。

`mask()` 先排除白名单，再合并原文字节空间中重叠或相邻的有效范围，每个合并范围替换为**一次** replacement，不按字符数重复 replacement。空 replacement 表示删除，多字符 replacement 合法。替换不会修改未匹配原文，也不会因前面的替换导致后续偏移失效。

## 验证与 benchmark

运行 Rust 检查、PHP 集成测试与基准测试：

```sh
cargo fmt --all -- --check
cargo clippy --no-default-features --all-targets -- -D warnings
cargo test --no-default-features --locked
cargo clippy --features php --lib -- -D warnings
sh scripts/build.sh
COMPOSER=$(php -r 'echo PHP_VERSION_ID < 80200 ? "composer-php81.json" : (PHP_VERSION_ID >= 80500 ? "composer-php85.json" : "composer.json");') \
  composer install --working-dir=tests/php
sh scripts/test-php.sh
php -n -d extension="$PWD/target/php-build/modules/lexsift.so" benchmarks/run.php
sh scripts/test-pie.sh
```

Benchmark 覆盖 100、1,000、10,000 词，短/长文本，无命中、首部/尾部命中及大量重叠，分别测量构建、`contains()`、`scan()` 和 `mask()`。可追加 `--iterations=100` 调整迭代数；运行结果以 JSON 输出。`contains()` 会在首个有效命中后停止匹配迭代，但仍需完成输入校验、处理全文和白名单定位。

## 平台支持

| 平台    | 支持情况           |
| ------- | ------------------ |
| Linux   | 支持源码构建与 PIE |
| macOS   | 支持源码构建与 PIE |
| Windows | 暂不支持           |

Windows 需要与 PHP ABI、架构及线程安全模式匹配的预构建 DLL；本项目未提供 Windows 构建产物，PIE 元数据仅声明 Linux 和 macOS。

`php --ri lexsift` 可查看扩展版本、Aho–Corasick 后端、Unicode 实现与数据版本。

## 纯 PHP 版本

如果不便安装原生扩展，可以使用 [LexSift PHP](https://github.com/vergil-lai/lexsift-php)。它以 PHP 实现 Aho–Corasick 匹配，方法、参数和返回值约定与本扩展一致。纯 PHP 版本使用 `VergilLai\LexSift\Matcher`，本扩展使用 `LexSift\Matcher`；不同环境的 Unicode 数据版本可能导致个别字符的处理结果不同。

## 协议

[MIT](LICENSE)
