# LexSift FFI

[English](README.md) | [简体中文](README_zh.md)

Install with `composer require vergil-lai/lexsift-ffi`, load `vendor/autoload.php`, and use `LexSift\Matcher`.
If ext-lexsift is already loaded, its implementation takes precedence. Otherwise, the PHP FFI implementation is used. Both expose the same matching API.

Requires PHP >=8.1, a 64-bit environment, ext-ffi, and `ffi.enable=true`. FPM and other web environments usually need FFI enabled in php.ini or pool configuration. No Composer plugins or installation scripts are required.

```php
require 'vendor/autoload.php';

$matcher = new LexSift\Matcher(['赌博'], ['合法博彩说明']);
$matcher->contains('赌 博'); // true
$matcher->scan('前赌 博后'); // term/text/start/end, with UTF-8 byte offsets
$matcher->mask('前赌 博后'); // 前*后
$matcher->replaceTerms(['微信']);
$matcher->replaceWhitelist(['微信支付']);
```

`__construct(array $terms, array $whitelist = [], array $options = [])` accepts six boolean options:
`unicode_nfkc=true`, `lowercase=true`, `remove_whitespace=true`, `remove_punctuation=false`, `remove_symbols=false`, and `remove_emoji=true`.
For `mask(string $text, string $replacement = '*')`, an empty replacement deletes matched ranges.

Dictionaries accept string values only and ignore array keys. Terms that normalize to the same value retain the first original entry. `scan()` returns all valid overlapping matches, ordered by ascending start offset and longer ranges first at the same start. `mask()` merges adjacent or overlapping ranges in the original text. Whitelists exempt only fully contained matches.
Invalid types throw `TypeError`. Invalid UTF-8, unknown options, and terms that become empty after normalization throw `ValueError`. A failed dictionary replacement preserves the previous state.

The package includes precompiled libraries for Linux x86_64/ARM64 (glibc and musl) and macOS x86_64/ARM64. No compilation is required on the target system.
Linux glibc environments require glibc >=2.36; macOS requires macOS >=11.
The `.so`/`.dylib` files are ordinary C ABI libraries, not PHP extensions, and do not need to match a PHP minor version or NTS/ZTS mode.
Unsupported platforms, missing binaries, or disabled FFI produce an exception. Matcher objects cannot be cloned or serialized.
