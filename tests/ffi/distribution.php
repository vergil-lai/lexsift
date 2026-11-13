<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/package.php'), $status);
if ($status !== 0) {
    throw new RuntimeException('Packaging failed');
}
$zip = new ZipArchive();
if ($zip->open($root . '/target/dist/lexsift-ffi.zip') !== true) {
    throw new RuntimeException('FFI archive is missing');
}
$hasBinary = false;
for ($i = 0; $i < $zip->numFiles; ++$i) {
    $name = $zip->getNameIndex($i);
    if (!str_starts_with($name, 'lib/') && !str_starts_with($name, 'src/')
        && !in_array($name, ['composer.json', 'LICENSE', 'README.md', 'README_zh.md', 'autoload.php', 'lexsift.h'], true)) {
        throw new RuntimeException('Unexpected FFI distribution file: ' . $name);
    }
    $hasBinary = $hasBinary || str_ends_with($name, '.so') || str_ends_with($name, '.dylib');
}
if (!$hasBinary || $zip->locateName('autoload.php') === false) {
    throw new RuntimeException('Missing FFI runtime files');
}
foreach (['README.md', 'README_zh.md'] as $readme) {
    $content = $zip->getFromName($readme);
    if ($content === false || !str_contains($content, '[English](README.md)')
        || !str_contains($content, '[简体中文](README_zh.md)')) {
        throw new RuntimeException('Missing bilingual package documentation: ' . $readme);
    }
}
$metadata = json_decode($zip->getFromName('composer.json'), true, flags: JSON_THROW_ON_ERROR);
if ($metadata['name'] !== 'vergil-lai/lexsift-ffi' || $metadata['type'] !== 'library'
    || $metadata['autoload']['files'] !== ['autoload.php'] || isset($metadata['replace']['ext-lexsift'])) {
    throw new RuntimeException('Incorrect FFI package metadata');
}
$zip->close();
preg_match('/^version = "([^"]+)"/m', file_get_contents($root . '/Cargo.toml'), $match);
$zip->open($root . '/target/dist/php_lexsift-' . $match[1] . '-src.zip');
foreach (['src/engine.rs', 'src/ffi.rs', 'Cargo.lock', 'config.m4', 'package.xml', 'README.md', 'README_zh.md', 'ffi/README.md', 'ffi/README_zh.md'] as $file) {
    if ($zip->locateName($file) === false) {
        throw new RuntimeException('Missing extension source: ' . $file);
    }
}
echo "Distribution contents passed\n";
