<?php

declare(strict_types=1);

// Exercise Composer's actual library installer, with the release ZIP as dist.
$root = dirname(__DIR__, 2);
$temp = sys_get_temp_dir() . '/lexsift-composer-' . bin2hex(random_bytes(6));
mkdir($temp);
$package = json_decode(file_get_contents($root . '/ffi/composer.json'), true, flags: JSON_THROW_ON_ERROR);
preg_match('/^version = "([^"]+)"/m', file_get_contents($root . '/Cargo.toml'), $version);
$package['version'] = $version[1];
$package['dist'] = ['type' => 'zip', 'url' => 'file://' . $root . '/target/dist/lexsift-ffi.zip'];
file_put_contents($temp . '/composer.json', json_encode([
    'name' => 'lexsift/distribution-test',
    'require' => [$package['name'] => $package['version']],
    'repositories' => [['type' => 'package', 'package' => $package], ['packagist.org' => false]],
    'config' => ['allow-plugins' => false],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
try {
    passthru('composer install --no-interaction --no-plugins --no-scripts --prefer-dist --working-dir=' . escapeshellarg($temp), $status);
    if ($status !== 0) {
        throw new RuntimeException('Composer installation failed');
    }
    $installed = $temp . '/vendor/vergil-lai/lexsift-ffi';
    if (is_file($installed . '/src/engine.rs') || is_file($installed . '/Cargo.toml')) {
        throw new RuntimeException('Rust sources were included in the installed package');
    }
    require $temp . '/vendor/autoload.php';
    if ((new ReflectionClass(LexSift\Matcher::class))->isInternal()) {
        throw new RuntimeException('This test must run without ext-lexsift');
    }
    $matcher = new LexSift\Matcher(['赌博']);
    if ($matcher->mask('前赌 博后') !== '前*后') {
        throw new RuntimeException('Installed FFI runtime failed');
    }
    echo "Composer FFI-only installation passed\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($temp);
}
