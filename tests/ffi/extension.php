<?php

declare(strict_types=1);

require __DIR__ . '/../../ffi/autoload.php';
if (!(new ReflectionClass(LexSift\Matcher::class))->isInternal()) {
    throw new RuntimeException('Composer autoload must preserve the loaded extension class');
}
$matcher = new LexSift\Matcher(['赌博']);
if ($matcher->mask('前赌 博后') !== '前*后') {
    throw new RuntimeException('Extension API failed after loading the FFI autoloader');
}
echo "Extension wins over FFI autoload\n";
