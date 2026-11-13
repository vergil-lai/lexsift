<?php

declare(strict_types=1);

// 在临时 Git 仓库验证来源校验，不向当前工作区提交任何文件。
$root = dirname(__DIR__, 2);
$temp = sys_get_temp_dir() . '/lexsift-manifest-' . bin2hex(random_bytes(6));
mkdir($temp);
$run = static function (array $command, bool $success = true) use ($temp): string {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $temp);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if (($status === 0) !== $success) {
        throw new RuntimeException("Unexpected status $status: $output");
    }
    return $output;
};
try {
    foreach (['Cargo.toml', 'Cargo.lock', '.cargo/config.toml', 'ffi/lexsift.h',
        'scripts/build-ffi.sh', 'scripts/ffi-manifest.php', 'docker/Dockerfile.ffi',
        '.github/workflows/ffi.yml', 'tests/ffi/lifecycle.php', 'scripts/package.php',
        'package.xml', 'composer.json', 'config.m4', 'Makefile.frag', 'LICENSE', 'README.md', 'README_zh.md',
        'stubs/lexsift.stub.php', 'ffi/autoload.php', 'ffi/composer.json', 'ffi/README.md', 'ffi/README_zh.md',
        ...glob($root . '/ffi/src/*.php'), ...glob($root . '/src/*.rs')] as $file) {
        $relative = str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
        @mkdir(dirname($temp . '/' . $relative), 0777, true);
        if (!is_file($root . '/' . $relative)) {
            throw new RuntimeException('Missing manifest implementation: ' . $relative);
        }
        copy($root . '/' . $relative, $temp . '/' . $relative);
    }
    file_put_contents($temp . '/.gitignore', "/ffi/lib/\n");
    $run(['git', 'init', '-q']);
    $run(['git', 'config', 'user.name', 'Manifest Test']);
    $run(['git', 'config', 'user.email', 'test@example.invalid']);
    $run(['git', 'add', '.']);
    $run(['git', 'commit', '-qm', 'source']);
    $targets = ['aarch64-apple-darwin', 'x86_64-apple-darwin',
        'aarch64-unknown-linux-gnu', 'x86_64-unknown-linux-gnu',
        'aarch64-unknown-linux-musl', 'x86_64-unknown-linux-musl'];
    foreach ($targets as $target) {
        mkdir($temp . '/ffi/lib/' . $target, 0777, true);
        $suffix = str_ends_with($target, 'darwin') ? 'dylib' : 'so';
        file_put_contents($temp . "/ffi/lib/$target/liblexsift.$suffix", 'fixture-' . $target);
    }
    $check = [PHP_BINARY, 'scripts/ffi-manifest.php', '--check'];
    $run([PHP_BINARY, 'scripts/ffi-manifest.php', '--write']);
    $run($check, false); // 本地生成但尚未提交的库不得用于发布。
    $run(['git', 'add', '-f', 'ffi/lib']);
    $run(['git', 'commit', '-qm', 'CI binaries']);
    $run($check);
    $run([PHP_BINARY, 'scripts/package.php', '--release']);
    file_put_contents($temp . '/README.md', "Documentation-only change\n");
    $run(['git', 'add', 'README.md']);
    $run(['git', 'commit', '-qm', 'docs']);
    $run([PHP_BINARY, 'scripts/ffi-manifest.php', '--write']);
    $run($check); // 与原生构建无关的修改不要求重编译。
    $source = $temp . '/src/engine.rs';
    $original = file_get_contents($source);
    file_put_contents($source, $original . "\n// Changed native source\n");
    $run($check, false);
    $run([PHP_BINARY, 'scripts/package.php', '--release'], false);
    file_put_contents($source, $original);
    $binary = $temp . '/ffi/lib/aarch64-apple-darwin/liblexsift.dylib';
    file_put_contents($binary, 'tampered');
    $run($check, false);
    unlink($binary);
    $run([PHP_BINARY, 'scripts/ffi-manifest.php', '--write'], false);
    echo "FFI committed-binary provenance checks passed\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($temp);
}
