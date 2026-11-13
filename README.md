# LexSift

[English](README.md) | [简体中文](README_zh.md)

LexSift is a PHP text-matching library powered by Rust's [aho-corasick](https://github.com/BurntSushi/aho-corasick). It supports sensitive-word detection, keyword search, and text replacement. This repository maintains two ways to use it:

- **PHP extension**: registers PHP classes through [ext-php-rs](https://github.com/extphprs/ext-php-rs). Install `vergil-lai/lexsift` through PIE, a source build, or Docker with install-php-extensions.
- **FFI**: PHP classes call a precompiled Rust shared library through FFI. Install `vergil-lai/lexsift-ffi` through Composer, without compiling Rust or installing the LexSift extension on the target system.

Both use the same Rust matching engine and expose the same `LexSift\Matcher` API. Features include Unicode normalization, lowercasing, whitespace removal, overlapping matches, phrase whitelists, and masking in the original text. Each matcher owns its dictionaries, which are compiled during construction or replacement and reused for subsequent queries.

## Requirements

### FFI

- PHP 8.1 or later on a 64-bit system.
- ext-ffi with permission to use the FFI API. Web environments need `ffi.enable=true`.
- A precompiled library for a supported platform. Rust and PHP development headers are not required.

### Extension

- PHP 8.1 or later, with matching development headers, `phpize`, and `php-config`.
- A stable Rust toolchain and Cargo supporting the Rust 2024 edition. A current stable toolchain is recommended.
- A C compiler, Clang/libclang, make, autoconf, and platform development tools.
- [PIE](https://github.com/php/pie) for PIE installation, including its own runtime requirements.

Linux usually requires your distribution's PHP development package. macOS requires Xcode Command Line Tools and a PHP installation with development tools. `php`, `phpize`, and `php-config` must belong to the same PHP installation. **The source directory and its parent paths must not contain spaces**, due to the phpize/configure build process.

## Installation

### Composer: FFI, without compiling an extension

```sh
composer require vergil-lai/lexsift-ffi
```

Requires PHP >=8.1, a 64-bit system, ext-ffi, and `ffi.enable=true`. Load `vendor/autoload.php`, then use the same `LexSift\Matcher` API shown below. If ext-lexsift is already loaded, its implementation takes precedence.

The [FFI package](https://github.com/vergil-lai/lexsift-ffi) installed by Composer contains PHP code, precompiled libraries, required metadata, and the license. It includes no Rust sources and runs no installation download scripts. Development takes place in this repository.

Precompiled libraries cover Linux x86_64/ARM64 (glibc >=2.36 and Alpine musl) and macOS x86_64/ARM64 (macOS >=11). They do not depend on a specific PHP minor version or NTS/ZTS mode. Windows and 32-bit systems are not supported. Web environments such as FPM need FFI explicitly enabled in their configuration. See the [FFI README](ffi/README.md).

The following PIE, Docker, and source installation methods install the extension.

### PIE

Install and inspect the extension:

```sh
pie install vergil-lai/lexsift
php --ri lexsift
```

Applications can require `"ext-lexsift": "*"` in `composer.json`. The root package, `vergil-lai/lexsift`, is a `php-ext` package for PIE; the FFI package is `vergil-lai/lexsift-ffi`. IDE declarations are available in the [stub](stubs/lexsift.stub.php).

### Docker: install-php-extensions

After installing Rust/Cargo and Clang/libclang in your official PHP image, add:

```dockerfile
COPY --from=ghcr.io/mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions "vergil-lai/lexsift@<commit-or-tag>"
```

Replace `<commit-or-tag>` with a commit or version tag containing `package.xml`. The installer enables the extension automatically. Use this [source installation method](https://github.com/mlocati/docker-php-extension-installer#installing-an-extension-from-its-source-code); `install-php-extensions lexsift` is not currently supported.

See the [Dockerfile](docker/Dockerfile) for dependency installation and a multi-stage build. To verify an image built from local sources:

```sh
docker build -f docker/Dockerfile -t lexsift-php .
docker run --rm lexsift-php php --ri lexsift
```

### Source build

Run from a repository path without spaces:

```sh
sh scripts/build.sh
php -n -d extension="$PWD/target/php-build/modules/lexsift.so" --ri lexsift
```

To select another PHP development toolchain:

```sh
PHP_CONFIG=/path/to/php-config PHPIZE=/path/to/phpize sh scripts/build.sh
```

The extension is built at `target/php-build/modules/lexsift.so`. To enable it persistently, run `make -C target/php-build install`, add `extension=lexsift.so` to the target PHP configuration, and restart the relevant service.

## Usage

For the Composer FFI package, load `vendor/autoload.php` first. The extension does not need this step. Both implementations follow the same API, default options, exception types, and UTF-8 byte-offset contract described below.

This example shows matching, masking, and dictionary replacement:

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

The match for `赌 博` returned by `scan()` includes the space in its original-text range:

```php
[
    'term' => '赌博',
    'text' => '赌 博',
    'start' => 3,
    'end' => 10,
]
```

## API and input contract

| Method | Behavior |
| ------ | -------- |
| `__construct(array $terms, array $whitelist = [], array $options = [])` | Creates an independent instance; unspecified options use their defaults |
| `contains(string $text): bool` | Stops matching at the first hit not excluded by the whitelist |
| `scan(string $text): array` | Returns all valid overlapping matches |
| `mask(string $text, string $replacement = '*'): string` | Merges valid original-text ranges and replaces them |
| `replaceTerms(array $terms): void` | Replaces the instance's entire term dictionary |
| `replaceWhitelist(array $whitelist): void` | Replaces the instance's entire whitelist |

Term and whitelist arrays accept string values only. Keys are ignored, and values follow PHP array iteration order. Empty arrays are valid; empty strings and terms that become empty after normalization throw `ValueError`. Exact duplicates and terms that normalize to the same value retain the first entry, including its original `term`. Successful replacements take effect immediately; failures preserve the previous state and do not affect other instances.

All text inputs, including dictionaries, whitelist entries, input text, and replacement strings, must be valid UTF-8. Invalid bytes throw `ValueError` rather than being repaired silently. Type errors throw `TypeError`; unknown or invalid option keys throw `ValueError`. Fuzzy matching, pinyin, stemming, persistence, and global dictionaries are not provided.

## Text processing options

Only these six boolean options are accepted. You may supply a subset; `0`, `1`, and strings are not substitutes for booleans.

| Option | Default | Behavior |
| ------ | ------- | -------- |
| `unicode_nfkc` | `true` | Applies Unicode NFKC, including full-width conversion, expansion, and composition |
| `lowercase` | `true` | Lowercases Unicode characters code point by code point |
| `remove_whitespace` | `true` | Removes Unicode whitespace |
| `remove_punctuation` | `false` | Removes characters in Unicode punctuation categories |
| `remove_symbols` | `false` | Removes characters in Unicode symbol categories |
| `remove_emoji` | `true` | Removes whole emoji-containing extended grapheme clusters from the original text |

Dictionaries, whitelist entries, and input text always use the same processing rules. Processing is used for matching only: returned terms, matched original text, and unmatched text are not rewritten.

Lowercasing uses Rust's `char::to_lowercase()`, which may expand one character into several. It does not perform Unicode case folding, locale-specific conversion, contextual mappings such as Greek final sigma, or diacritic removal.

Emoji removal uses a broad rule: an original grapheme cluster is removed if it contains Extended_Pictographic, Emoji_Presentation, Emoji_Modifier, Regional_Indicator, VS16, or a keycap enclosing mark. This covers ZWJ sequences, skin tones, flags, and keycaps. Plain digits, `#`, and `*` are retained; `©`, `©︎`, and `©️` are all removed. This is not limited to RGI emoji. Set `remove_emoji: false` when these symbols must be retained.

## Whitelists and byte offsets

Whitelist entries are ordinary string phrases. A term match is excluded only when its processed range is fully contained in **one** whitelist match. Partial overlaps are not exempt, and multiple whitelist ranges are not combined to grant an exemption. For example, `微信支付` can exempt `微信`, but a whitelist entry `f` does not exempt the term `i` produced by NFKC expansion of the original `ﬁ`.

`scan()` returns a plain array whose rows contain only `term`, `text`, `start`, and `end`. `term` is the original dictionary entry; `text` is the matched slice of the original input. Positions are **byte offsets** in the original UTF-8 string, using `[start, end)`. `substr($text, $start, $end - $start)` always equals the row's `text`. These are not character indexes.

Overlapping matches are supported. Results are ordered by ascending start offset, then by longer ranges first at the same start. Different terms with the same range follow dictionary order. A term mapped to the same original range is returned only once. Position mapping covers complete source graphemes, so combining sequences are not split. Removed characters inside a match remain part of its original-text span; independently removed characters at its edges do not expand the span.

`mask()` excludes whitelisted matches, merges overlapping or adjacent valid ranges in original byte space, and replaces each merged range **once** with the replacement string. It does not repeat the replacement per character. An empty replacement deletes the range; multi-character replacements are valid. Unmatched text is preserved, and earlier replacements do not invalidate later offsets.

## Validation and benchmarks

FFI development and distribution checks require a PHP process without ext-lexsift loaded:

```sh
sh scripts/build-ffi.sh
cargo test --locked --no-default-features --features ffi
sh scripts/test-ffi.sh
php tests/ffi/distribution.php
php -d ffi.enable=true tests/ffi/composer-install.php
```

If your local extension is loaded through scanned INI files, prefix the FFI test commands with `PHP_INI_SCAN_DIR=`. Keep FFI and the other PHP extensions required by Pest available.

Run Rust checks, PHP integration tests, and benchmarks:

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

Benchmarks cover 100, 1,000, and 10,000 terms; short and long text; no matches; head and tail matches; and dense overlaps. They measure construction, `contains()`, `scan()`, and `mask()` separately. Add `--iterations=100` to adjust iterations; results are emitted as JSON. `contains()` still validates its input. Without a whitelist, it can process text as a stream and stop at the first valid hit. With a whitelist, it normalizes the whole input and locates whitelist ranges first.

## Platform support

| Platform | Support |
| -------- | ------- |
| Linux | Source builds and PIE |
| macOS | Source builds and PIE |
| Windows | Not currently supported |

Windows requires prebuilt DLLs matching the PHP ABI, architecture, and thread-safety mode. This project does not provide Windows binaries, and its PIE metadata declares Linux and macOS only.

`php --ri lexsift` reports the extension, Aho–Corasick backend, Unicode implementation, and data versions.

## Pure PHP implementation

If a native extension is inconvenient, [LexSift PHP](https://github.com/vergil-lai/lexsift-php) implements Aho–Corasick in PHP with matching method, parameter, and return-value contracts. Its class is `VergilLai\LexSift\Matcher`; this project's extension uses `LexSift\Matcher`. Different Unicode data versions may cause differences for individual characters.

## Performance comparison

Measured on the same Apple M1 Pro machine running macOS arm64, PHP 8.5.12, ICU 78.3, and Rust 1.96.1. The LexSift extension and FFI libraries were rebuilt from the **0.2.0** implementation used for that measurement; LexSift PHP used a clean local checkout at `1a40074`. Each backend ran in a separate `php -n` process, with CLI OPcache and JIT disabled and a 512 MB memory limit. All three used the same 10,000-term dictionary and default processing options. No whitelist was used unless stated otherwise.

Each scenario has five warmups. Queries use the median of seven sample means; construction uses the median of five samples. Query samples target approximately 20 ms with 10–2,000 iterations; construction uses one iteration per sample. Query times exclude construction. Construction includes object creation and destruction, but excludes initial autoloading and FFI library loading. Backends run serially in the order extension → FFI → PHP → PHP → FFI → extension. The table averages each backend's two process medians. Units are **milliseconds per operation**; lower is better. Both speedup columns divide pure PHP time by the respective native backend's time.

| Scenario | LexSift extension | LexSift FFI | LexSift PHP | Extension speedup | FFI speedup |
| -------- | ----------------: | ----------: | ----------: | ----------------: | ----------: |
| Construct a 10,000-term instance | 28.1199 | 23.3864 | 140.7251 | 5.0× | 6.0× |
| `scan()`: 100 Chinese characters, no match | 0.0094 | 0.0104 | 0.2737 | 29.2× | 26.4× |
| `scan()`: 10,000 Chinese characters, no match | 0.7273 | 0.7319 | 18.1214 | 24.9× | 24.8× |
| `scan()`: 10,000 ASCII characters, no match | 0.4345 | 0.4314 | 2.7742 | 6.4× | 6.4× |
| `scan()`: 1,000 matches | 0.6395 | 0.7830 | 15.9759 | 25.0× | 20.4× |
| `contains()`: head match in short Chinese text | 0.0068 | 0.0077 | 0.1300 | 19.1× | 16.9× |
| `contains()`: head match in long Chinese text | 0.0246 | 0.0262 | 11.8955 | 484.3× | 453.6× |
| `contains()`: middle match in long Chinese text | 0.3735 | 0.3738 | 14.4475 | 38.7× | 38.6× |
| `contains()`: tail match in long Chinese text | 0.7287 | 0.7208 | 17.0517 | 23.4× | 23.7× |
| `contains()`: long Chinese text, no match | 0.7226 | 0.7270 | 17.0481 | 23.6× | 23.4× |
| `contains()`: long ASCII text, no match | 0.3924 | 0.3884 | 2.4507 | 6.2× | 6.3× |
| `contains()`: whitelist present, valid head match | 0.7204 | 0.7270 | 18.1714 | 25.2× | 25.0× |
| `contains()`: whitelist exempts the only match | 0.7347 | 0.7347 | 18.3596 | 25.0× | 25.0× |
| `contains()`: exempt match followed by a valid tail match | 0.7193 | 0.7381 | 18.3544 | 25.5× | 24.9× |
| `mask()`: 1,000 separated matches | 0.6323 | 0.6250 | 17.9642 | 28.4× | 28.7× |

The dictionary contains `敏感词00000` through `敏感词09999`, with the final entry used as the matching term. Chinese padding uses `文`; ASCII padding uses `x`. Short text has 100 padding characters and long text has 10,000, with the matching term inserted at the head, middle, or tail. Dense `scan()` input repeats the term 1,000 times (8,000 code points, 14,000 bytes). The `mask()` scenario adds `文` after each term. The whitelist is `敏感词09999safe`, covering valid matches, complete exemptions, and an exemption followed by another match.

FFI and the extension share the Rust engine and have similar full-text scan times in this test. Returning 1,000 matches through `scan()` was about 22% slower through FFI, including the cost of converting native hits into PHP arrays. FFI constructed instances faster in this run: the extension boundary normalizes dictionaries for validation before the engine normalizes them again during compilation, while FFI leaves that validation to engine compilation. The relationship between the two entry points is not a fixed ratio across operations.

**The approximately 484× extension speedup and 454× FFI speedup apply only to a head match in long Chinese text, not overall performance.** Without a whitelist, the Rust engine can process text as a stream and stop early. The current pure PHP implementation's non-ASCII path collects the full input and performs Unicode composition before matching. All three normalize the full input when a whitelist is present. In the full-text processing scenarios above, FFI was approximately 6–29× faster than pure PHP.

All 42 output comparisons covering performance inputs, Unicode, NUL bytes, whitelists, overlaps, and dictionary replacement agreed across both runs of all three backends. All return byte offsets and replace each merged range once. The largest difference between the two process medians was 3.4% for the extension, 3.7% for FFI, and 6.5% for PHP; differences near 1% should not be treated as stable advantages. These are local, warmed-up tests on fixed synthetic inputs. They do not cover production corpora, cold starts, memory use, concurrent throughput, or JIT-enabled execution, and do not establish equivalence for every Unicode input.

To reproduce, use [benchmarks/compare.php](benchmarks/compare.php). Build the extension and FFI library from the current sources, then supply the pure PHP project's Composer autoloader:

```sh
sh scripts/build.sh
sh scripts/build-ffi.sh
php -n benchmarks/compare.php --php-autoload=/path/to/lexsift-php/vendor/autoload.php
```

The script checks backend identity and output equality, then saves the six runs' raw samples, source hashes, and aggregate results under `target/benchmarks/compare/`. These generated files do not need to be committed.

## License

[MIT](LICENSE)
