.PHONY: lexsift-force
lexsift-force:

modules/lexsift.so: lexsift-force
	PHP_CONFIG="$(PHP_CONFIG)" PHP="$(PHP_EXECUTABLE)" "$(CARGO)" build --release --locked --manifest-path="$(srcdir)/Cargo.toml" --target-dir="$(top_builddir)/target"
	mkdir -p modules
	case "$$(uname -s)" in Darwin) cp "$(top_builddir)/target/release/liblexsift.dylib" "$@" ;; *) cp "$(top_builddir)/target/release/liblexsift.so" "$@" ;; esac
