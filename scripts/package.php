<?php

declare(strict_types=1);

// Stage the FFI-only mirror and build runtime/source archives from this checkout.
$root = dirname(__DIR__);
chdir($root);
preg_match('/^version = "([^"]+)"/m', file_get_contents('Cargo.toml'), $match);
$version = $match[1];
if (!str_contains(file_get_contents('package.xml'), "<release>$version</release>")) {
    throw new RuntimeException('Synchronize Cargo.toml and package.xml before release');
}
if (glob('ffi/lib/*/liblexsift.*') === []) {
    throw new RuntimeException('Build the FFI libraries before packaging');
}
if (in_array('--release', $argv, true)) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/ffi-manifest.php') . ' --check', $status);
    if ($status !== 0) {
        throw new RuntimeException('Release requires committed, up-to-date CI binaries');
    }
}
@mkdir('target/dist', 0777, true);

function files(array $entries): array
{
    $files = [];
    foreach ($entries as $entry) {
        if (is_dir($entry)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($entry, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $name = str_replace('\\', '/', $file->getPathname());
                if ($file->isFile() && !str_contains($name, '/vendor/') && !str_contains($name, '/.phpunit')) {
                    $files[] = $name;
                }
            }
        } else {
            $files[] = $entry;
        }
    }
    sort($files);
    return $files;
}

function archive(string $path, array $files): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Cannot create $path");
    }
    foreach ($files as $name => $file) {
        $zip->addFile($file, is_int($name) ? $file : $name);
    }
    $zip->close();
    echo "$path\n";
}

$runtime = ['LICENSE' => 'LICENSE'];
foreach (files(['ffi/autoload.php', 'ffi/composer.json', 'ffi/lexsift.h', 'ffi/README.md', 'ffi/README_zh.md', 'ffi/src', 'ffi/lib']) as $file) {
    $name = substr($file, 4);
    $runtime[$name] = $file;
}
$mirror = 'target/dist/ffi-mirror';
if (is_dir($mirror)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mirror, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
}
foreach ($runtime as $name => $file) {
    @mkdir(dirname("$mirror/$name"), 0777, true);
    copy($file, "$mirror/$name");
}
archive('target/dist/lexsift-ffi.zip', $runtime);
archive("target/dist/php_lexsift-$version-src.zip", files([
    'Cargo.toml', 'Cargo.lock', 'composer.json', 'config.m4', 'Makefile.frag',
    'package.xml', 'LICENSE', 'README.md', 'README_zh.md', '.cargo', 'src', 'stubs', 'scripts', 'docker', 'tests', 'ffi',
]));
