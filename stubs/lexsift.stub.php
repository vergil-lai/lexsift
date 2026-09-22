<?php

declare(strict_types=1);

namespace LexSift;

class Matcher
{
    /**
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

    public function contains(string $text): bool {}

    /** @return list<array{term: string, text: string, start: int, end: int}> */
    public function scan(string $text): array {}

    public function mask(string $text, string $replacement = '*'): string {}

    /** @param array<array-key, string> $terms */
    public function replaceTerms(array $terms): void {}

    /** @param array<array-key, string> $whitelist */
    public function replaceWhitelist(array $whitelist): void {}
}
