<?php

declare(strict_types=1);

// 编译 CI 写入来源与校验值；发布 CI 只校验已提交的产物，不负责重编译。
chdir(dirname(__DIR__));
$mode = $argv[1] ?? '--check';
if (!in_array($mode, ['--write', '--check'], true)) {
    throw new InvalidArgumentException('Usage: ffi-manifest.php --write|--check');
}

function git(array $arguments): string
{
    exec('git ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('FFI provenance Git check failed: ' . implode("\n", $output));
    }
    return trim(implode("\n", $output));
}

$inputs = array_merge(['Cargo.toml', 'Cargo.lock', '.cargo/config.toml', 'ffi/lexsift.h',
    'scripts/build-ffi.sh', 'scripts/ffi-manifest.php', 'docker/Dockerfile.ffi',
    '.github/workflows/ffi.yml', 'tests/ffi/lifecycle.php'], glob('src/*.rs'));
sort($inputs);
$hash = hash_init('sha256');
foreach ($inputs as $input) {
    hash_update($hash, $input . ':' . hash_file('sha256', $input) . "\n");
}
$sourceHash = hash_final($hash);
$binaries = [];
foreach (['aarch64-apple-darwin', 'x86_64-apple-darwin',
    'aarch64-unknown-linux-gnu', 'x86_64-unknown-linux-gnu',
    'aarch64-unknown-linux-musl', 'x86_64-unknown-linux-musl'] as $target) {
    $suffix = str_ends_with($target, 'darwin') ? 'dylib' : 'so';
    $file = "$target/liblexsift.$suffix";
    if (!is_file('ffi/lib/' . $file)) {
        throw new RuntimeException("Missing FFI binary: $file; run the FFI binaries workflow first");
    }
    $binaries[$file] = hash_file('sha256', 'ffi/lib/' . $file);
}
ksort($binaries);
$file = 'ffi/lib/manifest.json';
if ($mode === '--write') {
    $manifest = ['source_commit' => git(['rev-parse', 'HEAD']), 'source_sha256' => $sourceHash, 'binaries' => $binaries];
    // 相同源码和相同字节的重建不制造新的产物提交。
    $previous = is_file($file) ? json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : null;
    if (($previous['source_sha256'] ?? null) !== $sourceHash || ($previous['binaries'] ?? null) !== $binaries) {
        file_put_contents($file, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
    echo "FFI binary manifest written\n";
    exit;
}
if (!is_file($file)) {
    throw new RuntimeException('Missing FFI manifest; release a commit containing the FFI binaries workflow output');
}
$manifest = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
if (($manifest['source_sha256'] ?? null) !== $sourceHash) {
    throw new RuntimeException('FFI binaries are stale for this source; run the FFI binaries workflow on main before tagging');
}
if (($manifest['binaries'] ?? null) !== $binaries) {
    throw new RuntimeException('FFI binary checksums do not match the compiled manifest');
}
$sourceCommit = $manifest['source_commit'] ?? '';
if (!is_string($sourceCommit) || preg_match('/^[a-f0-9]{40}$/', $sourceCommit) !== 1) {
    throw new RuntimeException('Invalid FFI source commit');
}
git(['merge-base', '--is-ancestor', $sourceCommit, 'HEAD']);
git(['ls-files', '--error-unmatch', '--', $file, ...array_map(static fn (string $path): string => 'ffi/lib/' . $path, array_keys($binaries))]);
git(['diff', '--exit-code', 'HEAD', '--', 'ffi/lib', ...$inputs]);
echo "FFI committed binaries match the source and checksums\n";
