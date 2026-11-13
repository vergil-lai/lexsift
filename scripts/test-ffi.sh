#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
php_bin=${PHP_BINARY:-php}
"$php_bin" -d ffi.enable=true tests/ffi/lifecycle.php
cd tests/php
LEXSIFT_TEST_BACKEND=ffi "$php_bin" -d ffi.enable=true vendor/bin/pest --test-directory=Tests "$@"
