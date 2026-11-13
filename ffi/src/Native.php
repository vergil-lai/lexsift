<?php

declare(strict_types=1);

namespace LexSift\Internal;

use FFI;
use FFI\CData;
use RuntimeException;
use TypeError;
use ValueError;

/**
 * C ABI 加载、参数转换和内存管理辅助类，不属于公开的 Matcher API。
 *
 * @internal
 */
final class Native
{
    /** 当前执行期间复用的 FFI 绑定，所有 Matcher 共用函数定义。 */
    private static ?FFI $library = null;

    /** 检查 FFI 扩展，加载当前平台的动态库并验证 ABI 版本。 */
    public static function library(): FFI
    {
        if (self::$library !== null) {
            return self::$library;
        }
        if (!extension_loaded('FFI')) {
            throw new RuntimeException('LexSift FFI requires ext-ffi and ffi.enable=true.');
        }
        $arch = match (strtolower(php_uname('m'))) {
            'aarch64', 'arm64' => 'aarch64',
            'x86_64', 'amd64' => 'x86_64',
            default => throw new RuntimeException('Unsupported LexSift FFI architecture: ' . php_uname('m')),
        };
        // glibc 系统也可能安装 musl 工具链，必须识别当前进程实际加载的 libc。
        $maps = PHP_OS_FAMILY === 'Linux' ? @file_get_contents('/proc/self/maps') : false;
        $musl = $maps !== false
            ? preg_match('~/(?:ld-musl-[^/\s]+|libc\.musl-[^/\s]+)\.so\.1(?:\s|$)~m', $maps) === 1
            : is_file('/etc/alpine-release');
        $platform = match (PHP_OS_FAMILY) {
            'Darwin' => "$arch-apple-darwin/liblexsift.dylib",
            'Linux' => $arch . '-unknown-linux-' . ($musl ? 'musl' : 'gnu') . '/liblexsift.so',
            default => throw new RuntimeException('Unsupported LexSift FFI OS: ' . PHP_OS_FAMILY),
        };
        $path = dirname(__DIR__) . '/lib/' . $platform;
        if (PHP_INT_SIZE !== 8 || !is_file($path)) {
            throw new RuntimeException('LexSift FFI binary is unavailable for this platform: ' . $path);
        }
        try {
            // 以实际调用检查 FFI API 是否可用，不仅比较 ffi.enable 是否为 true：
            // preload 模式允许 CLI 使用；禁用或受限时，cdef 会抛错并在下方转换。
            $library = FFI::cdef(file_get_contents(dirname(__DIR__) . '/lexsift.h'), $path);
        } catch (\Throwable $error) {
            throw new RuntimeException('Cannot load LexSift FFI. Enable ffi.enable=true and check the native library dependencies.', 0, $error);
        }
        if ($library->lexsift_abi_version() !== 1) {
            throw new RuntimeException('Incompatible LexSift FFI ABI; reinstall the matching package binary.');
        }
        return self::$library = $library;
    }

    /**
     * 将 PHP 字符串转换为带字节长度的 C 切片，保留其中的 NUL 字节。
     * 调用方必须持有返回的缓冲区，直到原生调用结束，避免切片指向已释放内存。
     *
     * @return array{0: CData, 1: CData} 切片和持有其数据的缓冲区
     */
    public static function slice(string $text): array
    {
        $ffi = self::library();
        $length = strlen($text);
        $bytes = $ffi->new('char[' . max(1, $length) . ']');
        if ($length !== 0) {
            FFI::memcpy($bytes, $text, $length);
        }
        $slice = $ffi->new('LexsiftSlice');
        $slice->ptr = FFI::addr($bytes[0]);
        $slice->len = $length;
        return [$slice, $bytes];
    }

    /**
     * 校验词库值的字符串类型，并构造 C 切片数组及其存活期间所需的缓冲区。
     * 忽略所有数组键，包括非 UTF-8 键；字符串 UTF-8 合法性由 Rust 入口校验。
     *
     * @return array{0: ?CData, 1: list<CData>}
     */
    public static function dictionary(array $values, string $parameter): array
    {
        $entries = $values === [] ? null : self::library()->new('LexsiftSlice[' . count($values) . ']');
        $storage = [];
        foreach (array_values($values) as $index => $value) {
            if (!is_string($value)) {
                throw new TypeError("{$parameter}[$index] must be of type string");
            }
            [$slice, $bytes] = self::slice($value);
            $storage[] = $bytes;
            $entries[$index] = $slice;
        }
        return [$entries, $storage];
    }

    /** 校验六个布尔选项并转换为 ABI 位标志，默认启用 NFKC、小写、去空白和去 emoji。 */
    public static function options(array $options): int
    {
        $bits = ['unicode_nfkc' => 1, 'lowercase' => 2, 'remove_whitespace' => 4,
            'remove_punctuation' => 8, 'remove_symbols' => 16, 'remove_emoji' => 32];
        $flags = 39;
        foreach ($options as $key => $value) {
            if (!is_string($key) || preg_match('//u', $key) !== 1) {
                throw new ValueError('options keys must be valid UTF-8 strings');
            }
            if (!is_bool($value)) {
                throw new TypeError("options[$key] must be of type bool");
            }
            if (!isset($bits[$key])) {
                throw new ValueError("options contains unknown key $key");
            }
            $flags = $value ? $flags | $bits[$key] : $flags & ~$bits[$key];
        }
        return $flags;
    }

    /** 将原生错误转换为 PHP 异常，读取错误消息后归还 Rust 分配的缓冲区。 */
    public static function check(CData $error): void
    {
        if ($error->code === 0) {
            return;
        }
        try {
            $message = self::string($error->message);
        } finally {
            self::library()->lexsift_buffer_free($error->message);
        }
        throw $error->code === 1 ? new ValueError($message) : new RuntimeException($message);
    }

    /** 按明确的字节长度复制为 PHP 字符串，不能依赖 NUL 结尾。 */
    public static function string(CData $buffer): string
    {
        return $buffer->len === 0 ? '' : FFI::string($buffer->ptr, $buffer->len);
    }
}
