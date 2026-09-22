#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
module=${1:-"$PWD/target/php-build/modules/lexsift.so"}
if [ "$#" -gt 0 ]; then shift; fi
php_bin=${PHP_BINARY:-php}
cd tests/php
exec "$php_bin" -d "extension=$module" vendor/bin/pest --test-directory=Tests "$@"
