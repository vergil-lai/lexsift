<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
if (getenv('LEXSIFT_TEST_BACKEND') === 'ffi') {
    if (extension_loaded('lexsift')) {
        throw new RuntimeException('Disable ext-lexsift to test the FFI backend');
    }
    require __DIR__ . '/../../ffi/autoload.php';
}
