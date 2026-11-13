<?php

declare(strict_types=1);

namespace LexSift;

/** 关键词匹配器的 IDE 类型声明；扩展与 FFI 版本使用相同的公开匹配 API。 */
class Matcher
{
    /**
     * 构建独立实例，编译词库、白名单及文本处理配置。
     *
     * @param array<array-key, string> $terms
     * @param array<array-key, string> $whitelist
     * @param array{
     *   unicode_nfkc?: bool,
     *   lowercase?: bool,
     *   remove_whitespace?: bool,
     *   remove_punctuation?: bool,
     *   remove_symbols?: bool,
     *   remove_emoji?: bool
     * } $options
     */
    public function __construct(array $terms, array $whitelist = [], array $options = []) {}

    /** 判断原文是否存在未被白名单完整覆盖的匹配。 */
    public function contains(string $text): bool {}

    /**
     * 返回有效重叠匹配；start/end 为原文左闭右开的 UTF-8 字节偏移。
     *
     * @return list<array{term: string, text: string, start: int, end: int}>
     */
    public function scan(string $text): array {}

    /** 合并相邻或重叠的原文匹配范围后替换；空 replacement 表示删除。 */
    public function mask(string $text, string $replacement = '*'): string {}

    /**
     * 完整替换词库，失败时保留原状态。
     *
     * @param array<array-key, string> $terms
     */
    public function replaceTerms(array $terms): void {}

    /**
     * 完整替换白名单，失败时保留原状态。
     *
     * @param array<array-key, string> $whitelist
     */
    public function replaceWhitelist(array $whitelist): void {}
}
