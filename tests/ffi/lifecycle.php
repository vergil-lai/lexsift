<?php

declare(strict_types=1);

if (extension_loaded('lexsift')) {
    throw new RuntimeException('Run the FFI lifecycle test without ext-lexsift');
}
require __DIR__ . '/../../ffi/autoload.php';

function check(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('FFI lifecycle assertion failed');
    }
}

$cloneGuard = new ReflectionMethod(LexSift\Matcher::class, '__clone');
check($cloneGuard->isFinal() && !$cloneGuard->isPublic());
$matcher = new LexSift\Matcher(["a\0b", '赌博']);
check($matcher->mask("xa\0by", "\0") === "x\0y");
check($matcher->scan("xa\0by")[0]['term'] === "a\0b");
try {
    $matcher->__construct([' ']);
    throw new RuntimeException('Expected ValueError');
} catch (ValueError) {
    check($matcher->contains('赌博'));
}
$matcher->__construct(['微信']);
check(!$matcher->contains('赌博') && $matcher->contains('微信'));
try {
    clone $matcher;
    throw new RuntimeException('Cloning a native handle must be rejected');
} catch (Error) {
}
check($matcher->contains('微信'));
try {
    serialize($matcher);
    throw new RuntimeException('Serializing a native handle must be rejected');
} catch (Throwable $error) {
    check(!($error instanceof RuntimeException));
}
for ($i = 0; $i < 200; ++$i) {
    $instance = new LexSift\Matcher(['赌博']);
    check($instance->contains('赌 博'));
    check($instance->scan('赌 博')[0]['end'] === 7);
    try {
        $instance->replaceTerms([' ']);
    } catch (ValueError) {
        check($instance->mask('赌博') === '*');
    }
    unset($instance);
}
echo "FFI lifecycle passed\n";
