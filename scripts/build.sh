#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
repo_root=$PWD
php_config=${PHP_CONFIG:-$(command -v php-config)}
phpize_bin=${PHPIZE:-$(dirname "$php_config")/phpize}
build_dir="$repo_root/target/php-build"
mkdir -p "$build_dir"
cp config.m4 Makefile.frag "$build_dir/"
for entry in Cargo.toml Cargo.lock src .cargo; do
  ln -sfn "$repo_root/$entry" "$build_dir/$entry"
done
cd "$build_dir"
"$phpize_bin" --clean
"$phpize_bin"
./configure --enable-lexsift --with-php-config="$php_config"
make
