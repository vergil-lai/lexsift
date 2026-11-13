# LexSift

[English](README.md) | [简体中文](README_zh.md)

LexSift 是基于 Rust [aho-corasick](https://github.com/BurntSushi/aho-corasick) 的 PHP 文本匹配库，适用于敏感词检测、关键词检索和文本替换。本仓库同时维护两种使用方式：

- **PHP 扩展**：通过 [ext-php-rs](https://github.com/extphprs/ext-php-rs) 注册 PHP 类，支持 PIE、源码构建和 Docker（install-php-extensions）安装，包名为 `vergil-lai/lexsift`。
- **FFI**：由 PHP 定义类，通过 FFI 调用预编译的 Rust 动态库，使用 Composer 安装 `vergil-lai/lexsift-ffi`，无需在使用环境中编译 Rust 或安装 LexSift 扩展。

两种方式共用同一个 Rust 匹配引擎，提供一致的 `LexSift\Matcher` API。支持全角与半角转换、大小写统一、忽略空白等文本处理，并提供重叠匹配、短语白名单和原文脱敏。每个匹配器实例独立维护词库，词库在构造或替换时编译，后续查询直接复用。

## 环境要求

### FFI 方式

- PHP 8.1 或以上，64 位系统。
- 安装 ext-ffi，并允许使用 FFI API；Web 环境需配置 `ffi.enable=true`。
- 使用受支持平台的预编译动态库，无需 Rust 工具链或 PHP 开发头文件。

### 扩展方式

- PHP 8.1 或以上，以及与目标 PHP 匹配的开发头文件、`phpize`、`php-config`。
- Rust stable 工具链和 Cargo，需支持 Rust 2024 edition；建议使用当前 stable。
- C 编译器、Clang/libclang、make、autoconf 和平台开发工具。
- 通过 PIE 安装时还需 [PIE](https://github.com/php/pie)，并满足 PIE 自身的运行要求。

Linux 通常需要发行版的 PHP 开发包；macOS 需要 Xcode Command Line Tools 和包含开发工具的 PHP 安装。`php`、`phpize`、`php-config` 必须对应同一套 PHP。**源码目录及其父目录不能含空格**，这是本项目使用的 phpize/configure 构建路径的限制。

## 安装

### Composer（FFI，无需编译扩展）

```sh
composer require vergil-lai/lexsift-ffi
```

需要 PHP >=8.1、64 位系统、ext-ffi 和 `ffi.enable=true`。加载 `vendor/autoload.php` 后使用下方相同的 `LexSift\Matcher` API；进程已加载 ext-lexsift 时优先使用扩展。

Composer 安装的 [FFI 包](https://github.com/vergil-lai/lexsift-ffi) 只含 PHP 代码、预编译库及必要元数据和许可证，不含 Rust 源码，也不执行安装下载脚本。开发仍在本仓库进行。

预编译库覆盖 Linux x86_64/ARM64（glibc >=2.36、Alpine musl）和 macOS x86_64/ARM64（macOS >=11）；无需匹配 PHP 小版本或 NTS/ZTS。不支持 Windows/32 位。FPM 等 Web 环境需要在服务配置中显式启用 FFI。详情见 [FFI 说明](ffi/README_zh.md)。

以下 PIE、Docker 和源码安装方式针对扩展版本，均保留。

### PIE

使用 PIE 安装并检查扩展：

```sh
pie install vergil-lai/lexsift
php --ri lexsift
```

应用可在 `composer.json` 中声明 `"ext-lexsift": "*"`。根包 `vergil-lai/lexsift` 保持为 PIE 使用的 `php-ext` 类型，FFI 包为 `vergil-lai/lexsift-ffi`。IDE 类型声明见 [stub](stubs/lexsift.stub.php)。

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

使用 Composer FFI 包时先加载 `vendor/autoload.php`；使用扩展时无需此步骤。两种实现的以下 API、默认选项、异常类型和 UTF-8 字节偏移约定一致。

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

FFI 开发与分发验证（运行 FFI 测试的 PHP 进程不能加载 ext-lexsift）：

```sh
sh scripts/build-ffi.sh
cargo test --locked --no-default-features --features ffi
sh scripts/test-ffi.sh
php tests/ffi/distribution.php
php -d ffi.enable=true tests/ffi/composer-install.php
```

若本机通过扫描额外 ini 文件加载扩展，可在 FFI 测试命令前设置 `PHP_INI_SCAN_DIR=`；需保留 FFI、Pest 所需的其他 PHP 扩展。

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

Benchmark 覆盖 100、1,000、10,000 词，短/长文本，无命中、首部/尾部命中及大量重叠，分别测量构建、`contains()`、`scan()` 和 `mask()`。可追加 `--iterations=100` 调整迭代数；运行结果以 JSON 输出。`contains()` 仍需完成输入校验；无白名单时可流式处理，在首个有效命中后提前结束匹配，有白名单时则需全文归一化和白名单定位。

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

## 性能对比

在同一台 Apple M1 Pro、macOS arm64 机器上实测，PHP 8.5.12、ICU 78.3、Rust 1.96.1。LexSift 扩展和 FFI 使用该轮测量时重新构建的 **0.2.0**；LexSift PHP 使用干净的本地 checkout `1a40074`。三个后端分别通过 `php -n` 在独立进程中运行，关闭 CLI OPcache 和 JIT，内存上限统一为 512 MB。均使用相同的 10,000 词词库和默认文本处理选项；除标明白名单的场景外，均无白名单。

每场景预热 5 次，查询取 7 组均值的中位数，构建取 5 组中位数。查询每组按约 20 ms 自动选择 10–2,000 次迭代，构建每组 1 次；查询耗时不含构建，构建包含实例构造和销毁，但不含首次自动加载或 FFI 动态库加载。按“扩展 → FFI → PHP → PHP → FFI → 扩展”串行运行，下表取每个后端两次进程中位数的平均值，单位为 **毫秒/次**，越低越快。两列加速比均以纯 PHP 耗时为分子。

| 场景                                     | LexSift 扩展 | LexSift FFI | LexSift PHP | 扩展加速比 | FFI 加速比 |
| ---------------------------------------- | -----------: | ----------: | ----------: | ---------: | ---------: |
| 构建 10,000 词实例                       |      28.1199 |     23.3864 |    140.7251 |     5.0 倍 |     6.0 倍 |
| `scan()`：100 中文字，无命中             |       0.0094 |      0.0104 |      0.2737 |    29.2 倍 |    26.4 倍 |
| `scan()`：10,000 中文字，无命中          |       0.7273 |      0.7319 |     18.1214 |    24.9 倍 |    24.8 倍 |
| `scan()`：10,000 ASCII 字符，无命中      |       0.4345 |      0.4314 |      2.7742 |     6.4 倍 |     6.4 倍 |
| `scan()`：1,000 次命中                   |       0.6395 |      0.7830 |     15.9759 |    25.0 倍 |    20.4 倍 |
| `contains()`：短中文首部命中             |       0.0068 |      0.0077 |      0.1300 |    19.1 倍 |    16.9 倍 |
| `contains()`：长中文首部命中             |       0.0246 |      0.0262 |     11.8955 |   484.3 倍 |   453.6 倍 |
| `contains()`：长中文中部命中             |       0.3735 |      0.3738 |     14.4475 |    38.7 倍 |    38.6 倍 |
| `contains()`：长中文尾部命中             |       0.7287 |      0.7208 |     17.0517 |    23.4 倍 |    23.7 倍 |
| `contains()`：长中文无命中               |       0.7226 |      0.7270 |     17.0481 |    23.6 倍 |    23.4 倍 |
| `contains()`：长 ASCII 无命中            |       0.3924 |      0.3884 |      2.4507 |     6.2 倍 |     6.3 倍 |
| `contains()`：有白名单，首部有效命中     |       0.7204 |      0.7270 |     18.1714 |    25.2 倍 |    25.0 倍 |
| `contains()`：有白名单，唯一命中被豁免   |       0.7347 |      0.7347 |     18.3596 |    25.0 倍 |    25.0 倍 |
| `contains()`：白名单豁免后，尾部有效命中 |       0.7193 |      0.7381 |     18.3544 |    25.5 倍 |    24.9 倍 |
| `mask()`：1,000 个分隔的命中             |       0.6323 |      0.6250 |     17.9642 |    28.4 倍 |    28.7 倍 |

词库为 `敏感词00000` 至 `敏感词09999`，命中词为最后一项；中文填充字符为“文”，ASCII 为 `x`。短文本使用 100 个填充字符，长文本使用 10,000 个；首部、中部、尾部场景额外插入命中词。`scan()` 密集命中输入为命中词重复 1,000 次（8,000 码点、14,000 字节），`mask()` 场景在每个命中后加入“文”。白名单为 `敏感词09999safe`，用于对照有效命中、完全豁免和豁免后再次命中。

FFI 与扩展共用 Rust 引擎，本次长文本全文扫描耗时接近。返回 1,000 条命中的 `scan()`，FFI 约慢 22%，包含把原生命中逐项转换成 PHP 数组的成本；本次构建实例时 FFI 反而更快，扩展入口会先归一化校验词库，随后引擎编译时再次归一化，而 FFI 交由引擎编译时校验。不能据此把两种入口的所有操作视为固定倍数关系。

**约 484 倍（扩展）和 454 倍（FFI）仅对应长中文首部命中，不能代表整体性能。** Rust 引擎在无白名单时可以流式处理并提前结束匹配；当前 PHP 实现的非 ASCII 路径则先收集全文字符并完成 Unicode 组合，之后才开始匹配。有白名单时三者均需全文归一化。本次全文处理场景中，FFI 相对纯 PHP 约快 6–29 倍。

本次 42 项性能输入及 Unicode、NUL 字节、白名单、重叠匹配、词库替换的返回值对照，在三个后端的两次独立运行中全部一致。三者均返回字节偏移，并对每个合并命中范围替换一次。两次进程中位数的最大差异分别为扩展 3.4%、FFI 3.7%、PHP 6.5%；接近 1% 的耗时差异不应解读为稳定优势。这是固定合成输入的本机热态测试，未覆盖生产语料、冷启动、内存、并发吞吐或开启 JIT 后的表现，也不代表所有 Unicode 输入等价。

复现脚本为 [benchmarks/compare.php](benchmarks/compare.php)，先构建当前源码的扩展和 FFI 库，再指定纯 PHP 项目的 Composer autoload：

```sh
sh scripts/build.sh
sh scripts/build-ffi.sh
php -n benchmarks/compare.php --php-autoload=/path/to/lexsift-php/vendor/autoload.php
```

脚本会校验后端身份及返回值一致性，并将六次运行的原始采样、源码摘要和汇总结果写入 `target/benchmarks/compare/`，不需要提交这些构建产物。

## 协议

[MIT](LICENSE)
