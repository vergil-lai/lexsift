# LexSift FFI

[English](README.md) | [简体中文](README_zh.md)

通过 `composer require vergil-lai/lexsift-ffi` 安装，加载 `vendor/autoload.php` 后直接使用 `LexSift\Matcher`。
已加载 ext-lexsift 时自动使用扩展，否则使用此目录中的 PHP FFI 实现；两者匹配 API 一致。

需要 PHP >=8.1、64 位环境、ext-ffi，以及 `ffi.enable=true`。FPM/Web 环境通常需要在 php.ini 或池配置中启用 FFI；无需 Composer 插件或安装脚本。

```php
require 'vendor/autoload.php';

$matcher = new LexSift\Matcher(['赌博'], ['合法博彩说明']);
$matcher->contains('赌 博'); // true
$matcher->scan('前赌 博后'); // term/text/start/end，偏移为 UTF-8 字节
$matcher->mask('前赌 博后'); // 前*后
$matcher->replaceTerms(['微信']);
$matcher->replaceWhitelist(['微信支付']);
```

`__construct(array $terms, array $whitelist = [], array $options = [])` 支持六个布尔选项：
`unicode_nfkc=true`、`lowercase=true`、`remove_whitespace=true`、`remove_punctuation=false`、`remove_symbols=false`、`remove_emoji=true`。
`mask(string $text, string $replacement = '*')` 中空 replacement 表示删除。

词库只接受字符串值，忽略数组键；归一化后的重复词保留首次原词。`scan()` 返回所有有效重叠匹配，按起点升序、同起点更长者优先；`mask()` 合并相邻/重叠原文范围。白名单只排除被完整覆盖的命中。
非法类型抛出 `TypeError`，无效 UTF-8、未知选项和归一化后为空的词抛出 `ValueError`；词库替换失败保留旧状态。

安装包包含 Linux x86_64/ARM64（glibc 与 musl）和 macOS x86_64/ARM64 的预编译动态库，无需在使用环境中编译。
Linux glibc 环境需要 glibc >=2.36；macOS 需要 macOS >=11。
`.so`/`.dylib` 是普通 C ABI 库，不作为 PHP 扩展加载，也无需匹配 PHP 小版本或 NTS/ZTS。
不支持的 OS、架构、缺失二进制或 FFI 被禁用时会抛出明确异常。对象不可克隆或序列化。
