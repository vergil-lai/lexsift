#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
repo_root=$PWD
php_config=${PHP_CONFIG:-$(command -v php-config)}
php_bin=$("$php_config" --php-binary)
phpize_bin=${PHPIZE:-$(dirname "$php_config")/phpize}
task_tmp=$(mktemp -d)
trap 'rm -rf "$task_tmp"' EXIT HUP INT TERM
mkdir -p "$task_tmp/package"
cp Cargo.toml Cargo.lock composer.json config.m4 Makefile.frag README.md LICENSE "$task_tmp/package/"
cp -R .cargo src stubs "$task_tmp/package/"
extension_dir=$("$php_config" --extension-dir)
mkdir -p "$task_tmp/root$extension_dir"
# Homebrew's ini and php-config use different symlinked extension paths.
# Reproduce that alias inside the isolated installation root.
runtime_extension_dir=$("$php_bin" -r 'echo ini_get("extension_dir");')
if [ "$runtime_extension_dir" != "$extension_dir" ]; then
  mkdir -p "$task_tmp/root$(dirname "$runtime_extension_dir")"
  ln -s "$task_tmp/root$extension_dir" "$task_tmp/root$runtime_extension_dir"
fi
cd "$task_tmp/package"
PIE_WORKING_DIRECTORY="$task_tmp/state" INSTALL_ROOT="$task_tmp/root" \
  pie install --skip-enable-extension --no-interaction \
  --with-php-config="$php_config" --with-phpize-path="$phpize_bin"
module="$task_tmp/root$extension_dir/lexsift.so"
"$php_bin" -n -d "extension=$module" --ri lexsift
PHP_BINARY="$php_bin" sh "$repo_root/scripts/test-php.sh" "$module"
