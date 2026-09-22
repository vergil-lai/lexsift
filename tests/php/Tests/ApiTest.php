<?php

declare(strict_types=1);

test('extension smoke and matching API', function (): void {
    expect(extension_loaded('lexsift'))->toBe(true);
    $matcher = new LexSift\Matcher(terms: ['赌博'], options: ['lowercase' => false]);
    expect($matcher->contains('前赌 博后'))->toBe(true);
    expect($matcher->scan('前赌 博后'))->toBe([['term' => '赌博', 'text' => '赌 博', 'start' => 3, 'end' => 10]]);
    expect($matcher->mask(text: '前赌 博后'))->toBe('前*后');
    expect($matcher->mask('前赌 博后', ''))->toBe('前后');
});

test('reflection preserves names defaults and return types', function (): void {
    $expected = [
        '__construct' => [['terms', false], ['whitelist', true], ['options', true]],
        'contains' => [['text', false]],
        'scan' => [['text', false]],
        'mask' => [['text', false], ['replacement', true]],
        'replaceTerms' => [['terms', false]],
        'replaceWhitelist' => [['whitelist', false]],
    ];

    foreach ($expected as $method => $parameters) {
        $reflection = new ReflectionMethod(LexSift\Matcher::class, $method);
        $actual = [];
        foreach ($reflection->getParameters() as $parameter) {
            $actual[] = [
                $parameter->getName(),
                $parameter->isDefaultValueAvailable(),
            ];
        }
        expect($actual)->toBe($parameters, $method . ': ');
    }

    $constructor = new ReflectionMethod(LexSift\Matcher::class, '__construct');
    expect($constructor->getParameters()[1]->getDefaultValue())->toBe([]);
    expect($constructor->getParameters()[2]->getDefaultValue())->toBe([]);
    $mask = new ReflectionMethod(LexSift\Matcher::class, 'mask');
    expect($mask->getParameters()[1]->getDefaultValue())->toBe('*');

    expect((string) (new ReflectionMethod(LexSift\Matcher::class, 'contains'))->getReturnType())->toBe('bool');
    expect((string) (new ReflectionMethod(LexSift\Matcher::class, 'scan'))->getReturnType())->toBe('array');
    expect((string) (new ReflectionMethod(LexSift\Matcher::class, 'mask'))->getReturnType())->toBe('string');
    expect((string) (new ReflectionMethod(LexSift\Matcher::class, 'replaceTerms'))->getReturnType())->toBe('void');
    expect((string) (new ReflectionMethod(LexSift\Matcher::class, 'replaceWhitelist'))->getReturnType())->toBe('void');
});

test('named arguments can skip whitelist', function (): void {
    $matcher = new LexSift\Matcher(terms: ['ABC'], options: ['lowercase' => false]);
    expect($matcher->contains('ABC'))->toBe(true);
    expect($matcher->contains('abc'))->toBe(false);
});

test('terms and whitelist require strings without coercion', function (): void {
    expect(fn () => new LexSift\Matcher([1]))->toThrow(TypeError::class);
    $integer = 1;
    $terms = [&$integer];
    expect(fn () => new LexSift\Matcher($terms))->toThrow(TypeError::class);
    expect(fn () => new LexSift\Matcher(['ok'], [false]))->toThrow(TypeError::class);

    $matcher = new LexSift\Matcher(['ok']);
    expect(fn () => $matcher->replaceTerms([1]))->toThrow(TypeError::class);
    expect(fn () => $matcher->replaceWhitelist([null]))->toThrow(TypeError::class);
});

test('options require known UTF-8 string keys and bool values', function (): void {
    foreach ([
        ['lowercase' => 1],
        ['lowercase' => null],
        ['unknown' => true],
        [0 => true],
        ["\xff" => true],
    ] as $options) {
        $expected = array_key_exists('unknown', $options) || array_key_exists("\xff", $options)
            ? ValueError::class
            : (array_key_exists(0, $options) ? ValueError::class : TypeError::class);
        expect(fn () => new LexSift\Matcher(['ok'], options: $options))->toThrow($expected);
    }
});

test('explicit null is not an omitted optional array', function (): void {
    expect(fn () => new LexSift\Matcher(['ok'], null))->toThrow(TypeError::class);
    expect(fn () => new LexSift\Matcher(['ok'], [], null))->toThrow(TypeError::class);
});

test('every string entry rejects invalid UTF-8 as ValueError', function (): void {
    expect(fn () => new LexSift\Matcher(["\xff"]))->toThrow(ValueError::class);
    expect(fn () => new LexSift\Matcher(['ok'], ["\xff"]))->toThrow(ValueError::class);

    $matcher = new LexSift\Matcher(['ok']);
    expect(fn () => $matcher->contains("\xff"))->toThrow(ValueError::class);
    expect(fn () => $matcher->scan("\xff"))->toThrow(ValueError::class);
    expect(fn () => $matcher->mask("\xff"))->toThrow(ValueError::class);
    expect(fn () => $matcher->mask('ok', "\xff"))->toThrow(ValueError::class);
    expect(fn () => $matcher->replaceTerms(["\xff"]))->toThrow(ValueError::class);
    expect(fn () => $matcher->replaceWhitelist(["\xff"]))->toThrow(ValueError::class);
});

test('dictionary keys are ignored even when they contain invalid UTF-8', function (): void {
    $matcher = new LexSift\Matcher(["\xff" => '赌博']);
    expect($matcher->contains('赌博'))->toBe(true);

    $matcher = new LexSift\Matcher(['赌博'], ["\xff" => '赌博']);
    expect($matcher->contains('赌博'))->toBe(false);

    $matcher = new LexSift\Matcher(['初始']);
    expect($matcher->replaceTerms(["\xff" => '微信']))->toBe(null);
    expect($matcher->contains('微信'))->toBe(true);
    expect($matcher->replaceWhitelist(["\xff" => '微信']))->toBe(null);
    expect($matcher->contains('微信'))->toBe(false);
});

test('empty normalized dictionary entries are ValueError and replacements are atomic', function (): void {
    expect(fn () => new LexSift\Matcher([' ']))->toThrow(ValueError::class);
    $matcher = new LexSift\Matcher(['赌博'], ['微信']);
    expect(fn () => $matcher->replaceTerms(['安全', ' ']))->toThrow(function (ValueError $error): void {
        expect($error->getMessage())->toContain('terms', 'index 1');
    });
    expect($matcher->contains('赌博'))->toBe(true);
    expect(fn () => $matcher->replaceWhitelist(['安全', ' ']))->toThrow(function (ValueError $error): void {
        expect($error->getMessage())->toContain('whitelist', 'index 1');
    });
    expect($matcher->contains('微信'))->toBe(false);
});

test('replace methods update independent state and return void', function (): void {
    $matcher = new LexSift\Matcher(['赌博']);
    expect($matcher->replaceTerms(['微信']))->toBe(null);
    expect($matcher->contains('赌博'))->toBe(false);
    expect($matcher->contains('微信'))->toBe(true);
    expect($matcher->replaceWhitelist(['微信']))->toBe(null);
    expect($matcher->contains('微信'))->toBe(false);
});

test('calling constructor again cannot corrupt initialized state', function (): void {
    $matcher = new LexSift\Matcher(['赌博']);
    expect(fn () => $matcher->__construct([' ']))->toThrow(ValueError::class);
    expect($matcher->contains('赌博'))->toBe(true);
    expect($matcher->contains('微信'))->toBe(false);
});
