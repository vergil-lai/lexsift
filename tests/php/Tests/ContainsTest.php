<?php

declare(strict_types=1);

test('contains validates the entire UTF-8 tail before an early match', function (): void {
    foreach ([[], ['ab']] as $terms) {
        foreach ([[], ['absafe']] as $whitelist) {
            $matcher = new LexSift\Matcher($terms, $whitelist);
            foreach (["\xff", "\xc3", "\xed\xa0\x80", "\xf4\x90\x80\x80"] as $invalid) {
                $text = 'ab' . str_repeat('文', 10000) . $invalid;
                expect(fn() => $matcher->contains($text))->toThrow(ValueError::class, 'text must contain valid UTF-8');
            }
        }
    }
});

test('contains agrees with scan at Unicode and stream boundaries', function (): void {
    $cases = [
        ['a', "a\u{0301}", false],
        ['à', "a\u{0315}\u{0300}", true],
        ['가', 'ﾡￂ', true],
        ['가', 'ﾡ👨‍👩‍👧‍👦ￂ', true],
        ['ᄀ', 'ﾡￂ', false],
        ['ffi', 'ﬃ', true],
        ["i\u{0307}", 'İ', true],
        ['赌博', '赌1️⃣🇨🇳👍🏽博', true],
        [str_repeat('ab', 600), str_repeat('ab', 600), true],
    ];
    foreach ($cases as [$term, $input, $expected]) {
        $matcher = new LexSift\Matcher([$term]);
        foreach ([0, 250, 255, 256, 257, 8190] as $padding) {
            $text = str_repeat('x', $padding) . $input . str_repeat('文', 300);
            expect($matcher->contains($text))->toBe($expected)->toBe($matcher->scan($text) !== []);
        }
    }
});

test('contains switches whitelist fallback and dictionaries atomically', function (): void {
    $matcher = new LexSift\Matcher(['ab']);
    $text = 'ab' . str_repeat('文', 10000) . 'safe';
    expect($matcher->contains($text))->toBe(true);
    $matcher->replaceWhitelist([$text]);
    expect($matcher->contains($text))->toBe(false);
    expect($matcher->contains($text . 'ab'))->toBe(true);
    $matcher->replaceWhitelist([]);
    expect($matcher->contains($text))->toBe(true);
    $matcher->replaceTerms(['different']);
    expect($matcher->contains($text))->toBe(false);
    $matcher->replaceTerms([]);
    expect($matcher->contains(''))->toBe(false);
});
