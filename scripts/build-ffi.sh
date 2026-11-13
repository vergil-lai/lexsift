#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
target=${1:-$(rustc -vV | sed -n 's/^host: //p')}
case "$target" in
  aarch64-apple-darwin|x86_64-apple-darwin)
    library=liblexsift.dylib
    export MACOSX_DEPLOYMENT_TARGET=11.0 ;;
  aarch64-unknown-linux-gnu|x86_64-unknown-linux-gnu|aarch64-unknown-linux-musl|x86_64-unknown-linux-musl) library=liblexsift.so ;;
  *) echo "Unsupported FFI target: $target" >&2; exit 1 ;;
esac
# Separate target directory prevents overwriting the PHP extension artifact.
CARGO_TARGET_DIR="$PWD/target/ffi" cargo build --locked --release --no-default-features --features ffi --target "$target"
mkdir -p "ffi/lib/$target"
cp "target/ffi/$target/release/$library" "ffi/lib/$target/$library"
