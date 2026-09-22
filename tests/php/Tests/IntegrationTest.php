<?php

declare(strict_types=1);

use LexSift\Matcher;

/** Check exact rows and the original-byte contract on every tested scan. */
function scanned(Matcher $matcher, string $input, array $expected): void
{
    $rows = $matcher->scan($input);
    expect($rows)->toBe($expected);
    expect($matcher->contains($input))->toBe($rows !== []);
    foreach ($rows as $row) {
        expect(array_keys($row))->toBe(['term', 'text', 'start', 'end']);
        expect(substr($input, $row['start'], $row['end'] - $row['start']))->toBe($row['text']);
        expect($row['end'] > $row['start'])->toBe(true);
    }
}

function hit(string $term, string $text, int $start, int $end): array
{
    return compact('term', 'text', 'start', 'end');
}

test('instances and replacement isolation', function (): void {
    $a = new Matcher(['赌博']);
    $b = new Matcher(['微信']);
    scanned($a, '赌博微信', [hit('赌博', '赌博', 0, 6)]);
    scanned($b, '赌博微信', [hit('微信', '微信', 6, 12)]);
    $a->replaceTerms(['bad']);
    scanned($a, '赌博微信BAD', [hit('bad', 'BAD', 12, 15)]);
    scanned($b, '赌博微信BAD', [hit('微信', '微信', 6, 12)]);
});
test('atomic terms and whitelist rollback', function (): void {
    $m = new Matcher(['bad'], ['bad safe']);
    foreach ([['new', ' '], ['new', "\xff"], ['new', 1]] as $values) {
        expect(fn () => $m->replaceTerms($values))->toThrow(is_int($values[1]) ? TypeError::class : ValueError::class);
        expect(fn () => $m->replaceWhitelist($values))->toThrow(is_int($values[1]) ? TypeError::class : ValueError::class);
        scanned($m, 'bad safe bad', [hit('bad', 'bad', 9, 12)]);
    }
    $m->replaceWhitelist(['bad']);
    scanned($m, 'bad', []);
    $m->replaceWhitelist([]);
    scanned($m, 'bad', [hit('bad', 'bad', 0, 3)]);
});

$cases = [
    'Chinese English mixed' => [['赌博', 'BAD'], '前赌博 BAD后', [], [hit('赌博', '赌博', 3, 9), hit('BAD', 'BAD', 10, 13)]],
    'Unicode lowercase expansion' => [["i\u{0307}"], '前İ后', [], [hit("i\u{0307}", 'İ', 3, 5)]],
    'lowercase disabled' => [['BAD'], 'bad BAD', ['lowercase' => false], [hit('BAD', 'BAD', 4, 7)]],
    'fullwidth NFKC' => [['bad'], '前ＢＡＤ后', [], [hit('bad', 'ＢＡＤ', 3, 12)]],
    'NFKC disabled' => [['bad'], 'ＢＡＤ', ['unicode_nfkc' => false], []],
    'halfwidth Hangul cross cluster' => [['가'], '前ﾡￂ后', [], [hit('가', 'ﾡￂ', 3, 9)]],
    'NFKC expansion duplicate source' => [['f', 'ffi'], 'ﬃ', [], [hit('f', 'ﬃ', 0, 3), hit('ffi', 'ﬃ', 0, 3)]],
    'whitespace insertions' => [['赌博'], "前赌 \t\n博后", [], [hit('赌博', "赌 \t\n博", 3, 12)]],
    'whitespace preserved' => [['赌博'], '赌 博', ['remove_whitespace' => false], []],
    'punctuation insertions' => [['赌博'], '赌，。博', ['remove_punctuation' => true], [hit('赌博', '赌，。博', 0, 12)]],
    'punctuation retained by default' => [['赌博'], '赌。博', [], []],
    'symbol insertion' => [['赌博'], '赌$博', ['remove_symbols' => true], [hit('赌博', '赌$博', 0, 7)]],
    'canonical composed accent' => [['é'], "前e\u{0301}后", [], [hit('é', "e\u{0301}", 3, 6)]],
    'canonical mark reordering' => [["à\u{0315}"], "a\u{0315}\u{0300}", [], [hit("à\u{0315}", "a\u{0315}\u{0300}", 0, 5)]],
    'partial cluster preserves full provenance' => [['e'], "e\u{0301}", ['unicode_nfkc' => false], [hit('e', "e\u{0301}", 0, 3)]],
    'deleted symbol in source cluster' => [["\u{0301}"], "$\u{0301}", ['unicode_nfkc' => false, 'remove_symbols' => true], [hit("\u{0301}", "$\u{0301}", 0, 3)]],
    'diacritics are not stripped' => [['e'], 'é', [], []],
    'overlap and longer first' => [['ab', 'abc', 'bc'], 'abc', [], [hit('abc', 'abc', 0, 3), hit('ab', 'ab', 0, 2), hit('bc', 'bc', 1, 3)]],
    'first normalized term wins' => [['BAD', 'bad', 'ＢＡＤ', 'BAD'], 'bad', [], [hit('BAD', 'bad', 0, 3)]],
    'NUL byte remains valid' => [["a\0b"], "xa\0by", [], [hit("a\0b", "a\0b", 1, 4)]],
];
foreach ($cases as $name => [$terms, $input, $options, $expected]) {
    test($name, fn () => scanned(new Matcher($terms, options: $options), $input, $expected));
}
test('emoji clusters and emoji option', function (): void {
    $emoji = ['👨‍👩‍👧‍👦', '👍🏽', '🇨🇳', '1️⃣', '©', "©\u{FE0E}", "©\u{FE0F}", "🏴\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}"];
    foreach ($emoji as $cluster) {
        $input = '前赌' . $cluster . '博后';
        $m = new Matcher(['赌博']);
        scanned($m, $input, [hit('赌博', '赌' . $cluster . '博', 3, 9 + strlen($cluster))]);
        expect($m->mask($input))->toBe('前*后');
        scanned(new Matcher(['赌博'], options: ['remove_emoji' => false]), $input, []);
    }
    foreach (['1', '#', '*'] as $plain) {
        scanned(new Matcher([$plain]), $plain, [hit($plain, $plain, 0, 1)]);
    }
});
test('whitelist containment and partial overlap', function (): void {
    scanned(new Matcher(['博彩', '微信'], ['合法博彩说明', '微信支付']), '合法博彩说明 微信支付 博彩', [hit('博彩', '博彩', 32, 38)]);
    scanned(new Matcher(['abcd'], ['bc']), 'abcd', [hit('abcd', 'abcd', 0, 4)]);
    scanned(new Matcher(['abcd'], ['ab', 'cd', 'abc', 'bcd']), 'abcd', [hit('abcd', 'abcd', 0, 4)]);
    scanned(new Matcher(['i'], ['f']), 'ﬁ', [hit('i', 'ﬁ', 0, 3)]);
    scanned(new Matcher(['BAD'], ['bad safe']), 'ＢＡＤ safe', []);
});
test('mask merges overlap adjacency and preserves original', function (): void {
    $m = new Matcher(['ab', 'bc', 'cd']);
    expect($m->mask('前abcd后', '替换'))->toBe('前替换后');
    expect($m->mask('前abcd后', ''))->toBe('前后');
    expect($m->mask('ab-cd'))->toBe('*-*');
    expect((new Matcher(['ab', 'cd']))->mask('abcd'))->toBe('*');
    expect($m->mask('é untouched'))->toBe('é untouched');
    expect((new Matcher(['赌博']))->mask(' 😀赌 博 😀'))->toBe(' 😀* 😀');
    expect((new Matcher(['bad'], ['bad safe']))->mask('bad safe bad'))->toBe('bad safe *');
    expect((new Matcher(['e'], options: ['unicode_nfkc' => false]))->mask("e\u{0301}"))->toBe('*');
});
test('empty dictionaries and empty text', function (): void {
    foreach ([new Matcher([]), new Matcher(['bad'])] as $m) {
        scanned($m, '', []);
        expect($m->mask(''))->toBe('');
    }
    $m = new Matcher([], ['bad']);
    scanned($m, 'bad', []);
    expect($m->mask('bad'))->toBe('bad');
    $m->replaceTerms(['bad']);
    scanned($m, 'bad', []);
    $m->replaceWhitelist([]);
    expect($m->mask('bad'))->toBe('*');
    $m->replaceTerms([]);
    scanned($m, 'bad', []);
});
test('invalid UTF-8 every entry including empty dictionary', function (): void {
    foreach (["\xff", "\xc0\xaf", "\xed\xa0\x80", "\xf4\x90\x80\x80", "\xe4\xb8"] as $bad) {
        expect(fn () => new Matcher([$bad]))->toThrow(ValueError::class);
        expect(fn () => new Matcher([], [$bad]))->toThrow(ValueError::class);
        foreach ([new Matcher([]), new Matcher(['bad'])] as $m) {
            expect(fn () => $m->contains($bad))->toThrow(ValueError::class);
            expect(fn () => $m->scan($bad))->toThrow(ValueError::class);
            expect(fn () => $m->mask($bad))->toThrow(ValueError::class);
            expect(fn () => $m->mask('', $bad))->toThrow(ValueError::class);
            expect(fn () => $m->replaceTerms([$bad]))->toThrow(ValueError::class);
            expect(fn () => $m->replaceWhitelist([$bad]))->toThrow(ValueError::class);
        }
    }
});
test('empty normalized terms and strict parameters', function (): void {
    foreach (['', " \t\n", '👨‍👩‍👧‍👦'] as $empty) {
        expect(fn () => new Matcher([$empty]))->toThrow(ValueError::class);
        expect(fn () => new Matcher([], [$empty]))->toThrow(ValueError::class);
    }
    expect(fn () => new Matcher(['...'], options: ['remove_punctuation' => true]))->toThrow(ValueError::class);
    expect(fn () => new Matcher(['$'], options: ['remove_symbols' => true]))->toThrow(ValueError::class);
    expect(fn () => new Matcher([], options: ['remove_characters' => true]))->toThrow(ValueError::class);
    foreach ([false, 1, null, [], new stdClass()] as $value) {
        expect(fn () => new Matcher([$value]))->toThrow(TypeError::class);
        expect(fn () => new Matcher([], [$value]))->toThrow(TypeError::class);
    }
});
test('10000 terms construct scan and replace', function (): void {
    $terms = array_map(fn (int $i): string => sprintf('term%05d', $i), range(0, 9999));
    $m = new Matcher($terms);
    scanned($m, 'term00000 / term09999', [hit('term00000', 'term00000', 0, 9), hit('term09999', 'term09999', 12, 21)]);
    scanned($m, str_repeat('x', 65536), []);
    $m->replaceTerms(array_reverse($terms));
    expect($m->mask('term00000 / term09999'))->toBe('* / *');
});
