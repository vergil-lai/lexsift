<?php

declare(strict_types=1);

namespace LexSift;

use FFI;
use FFI\CData;
use LexSift\Internal\Native;

/**
 * 基于 Rust 引擎的 FFI 关键词匹配器，与扩展版提供相同的匹配 API。
 *
 * 每个实例独立持有词库、白名单和归一化配置；匹配位置均为原文 UTF-8 字节偏移。
 */
class Matcher
{
    /** 当前实例独占的原生引擎指针；未初始化或已释放时为 null。 */
    private ?CData $engine = null;

    /**
     * 编译词库与白名单，未指定的文本处理选项沿用默认值。
     *
     * 数组键不参与匹配。无效类型抛出 TypeError，无效 UTF-8、未知选项或
     * 归一化后为空的词抛出 ValueError；FFI 不可用时抛出 RuntimeException。
     *
     * @param array<array-key, string> $terms
     * @param array<array-key, string> $whitelist
     * @param array{unicode_nfkc?: bool, lowercase?: bool, remove_whitespace?: bool,
     *   remove_punctuation?: bool, remove_symbols?: bool, remove_emoji?: bool} $options
     */
    public function __construct(array $terms, array $whitelist = [], array $options = [])
    {
        $flags = Native::options($options);
        $ffi = Native::library();
        [$termsInput, $termsStorage] = Native::dictionary($terms, 'terms');
        [$whitelistInput, $whitelistStorage] = Native::dictionary($whitelist, 'whitelist');
        $engine = $ffi->new('LexsiftEngine *');
        Native::check($ffi->lexsift_new($termsInput, count($terms), $whitelistInput, count($whitelist), $flags, FFI::addr($engine)));
        // 新词库编译成功后才释放旧引擎，重复构造失败时保留原状态。
        $this->release();
        $this->engine = $engine;
    }

    /** 判断原文是否包含至少一个未被白名单完整覆盖的词。 */
    public function contains(string $text): bool
    {
        [$input, $storage] = Native::slice($text);
        $out = Native::library()->new('unsigned char');
        Native::check(Native::library()->lexsift_contains($this->handle(), $input, FFI::addr($out)));
        return $out->cdata !== 0;
    }

    /**
     * 返回所有有效重叠匹配，包含原词、原文片段及左闭右开的字节范围。
     *
     * @return list<array{term: string, text: string, start: int, end: int}>
     */
    public function scan(string $text): array
    {
        [$input, $storage] = Native::slice($text);
        $ffi = Native::library();
        $out = $ffi->new('LexsiftMatches');
        Native::check($ffi->lexsift_scan($this->handle(), $input, FFI::addr($out)));
        try {
            $rows = [];
            for ($i = 0; $i < $out->len; ++$i) {
                $hit = $out->ptr[$i];
                $rows[] = ['term' => Native::string($hit->term),
                    'text' => substr($text, $hit->start, $hit->end - $hit->start),
                    'start' => $hit->start, 'end' => $hit->end];
            }
            return $rows;
        } finally {
            $ffi->lexsift_matches_free($out);
        }
    }

    /** 合并相邻或重叠的有效原文范围后替换；空 replacement 表示删除。 */
    public function mask(string $text, string $replacement = '*'): string
    {
        [$input, $storage] = Native::slice($text);
        [$replacementInput, $replacementStorage] = Native::slice($replacement);
        $ffi = Native::library();
        $out = $ffi->new('LexsiftBuffer');
        Native::check($ffi->lexsift_mask($this->handle(), $input, $replacementInput, FFI::addr($out)));
        try {
            return Native::string($out);
        } finally {
            $ffi->lexsift_buffer_free($out);
        }
    }

    /**
     * 完整替换本实例词库；编译失败时保留旧词库。
     *
     * @param array<array-key, string> $terms
     */
    public function replaceTerms(array $terms): void
    {
        [$input, $storage] = Native::dictionary($terms, 'terms');
        Native::check(Native::library()->lexsift_replace_terms($this->handle(), $input, count($terms)));
    }

    /**
     * 完整替换本实例白名单；编译失败时保留旧白名单。
     *
     * @param array<array-key, string> $whitelist
     */
    public function replaceWhitelist(array $whitelist): void
    {
        [$input, $storage] = Native::dictionary($whitelist, 'whitelist');
        Native::check(Native::library()->lexsift_replace_whitelist($this->handle(), $input, count($whitelist)));
    }

    /** 销毁对象时归还 Rust 引擎占用的内存。 */
    public function __destruct()
    {
        $this->release();
    }

    /** 释放当前引擎并清空指针，避免重复析构造成二次释放。 */
    private function release(): void
    {
        if ($this->engine !== null) {
            Native::library()->lexsift_free($this->engine);
            $this->engine = null;
        }
    }

    // 禁止子类放开浅克隆，防止多个对象共享并重复释放同一原生指针。
    final protected function __clone() {}

    /** 原生指针不能跨进程保存，因此禁止序列化。 */
    public function __serialize(): array
    {
        throw new \LogicException('LexSift Matcher cannot be serialized');
    }

    /** 禁止通过反序列化恢复无法校验的原生指针。 */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('LexSift Matcher cannot be unserialized');
    }

    /** 取出有效引擎；绕过构造或手动析构后的对象不能再调用原生方法。 */
    private function handle(): CData
    {
        return $this->engine ?? throw new \LogicException('LexSift Matcher is not initialized');
    }
}
