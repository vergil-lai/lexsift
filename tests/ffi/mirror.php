<?php

declare(strict_types=1);

// Exercise the real publishing script against a local bare Git repository.
// Only gh's network operations are replaced; Git commits/tags/pushes are real.
$root = dirname(__DIR__, 2);
$temp = sys_get_temp_dir() . '/lexsift-mirror-' . bin2hex(random_bytes(6));
mkdir($temp);
mkdir($temp . '/bin');
$run = static function (string $command, int $expected = 0): void {
    exec($command . ' 2>&1', $output, $status);
    if (($expected === 0 && $status !== 0) || ($expected !== 0 && $status === 0)) {
        throw new RuntimeException("Unexpected status $status: " . implode("\n", $output));
    }
};
file_put_contents($temp . '/bin/gh', <<<'SH'
#!/bin/sh
set -eu
case "$1 $2" in
  'repo view'|'auth setup-git') exit 0 ;;
  'repo clone') exec git clone "$MIRROR_TEST_REMOTE" "$4" ;;
  'release view') test -f "$MIRROR_TEST_RELEASE" ;;
  'release create') touch "$MIRROR_TEST_RELEASE" ;;
  *) echo 'Unexpected network command' >&2; exit 1 ;;
esac
SH);
chmod($temp . '/bin/gh', 0755);
$run('git init --bare ' . escapeshellarg($temp . '/remote.git'));
$environment = 'PATH=' . escapeshellarg($temp . '/bin:' . getenv('PATH'))
    . ' GH_TOKEN=local-test MIRROR_TEST_REMOTE=' . escapeshellarg($temp . '/remote.git')
    . ' MIRROR_TEST_RELEASE=' . escapeshellarg($temp . '/release');
preg_match('/^version = "([^"]+)"/m', file_get_contents($root . '/Cargo.toml'), $version);
$tag = 'v' . $version[1];
$publish = $environment . ' sh ' . escapeshellarg($root . '/scripts/publish-ffi.sh') . ' ' . escapeshellarg($tag);
$staged = $root . '/target/dist/ffi-mirror/autoload.php';
$original = file_get_contents($staged);
try {
    $run($publish);
    $run($publish); // An identical retry must preserve the published tag.
    $run('git --git-dir=' . escapeshellarg($temp . '/remote.git') . ' show ' . escapeshellarg($tag . ':autoload.php'));
    $run('git --git-dir=' . escapeshellarg($temp . '/remote.git') . ' cat-file -e ' . escapeshellarg($tag . ':Cargo.toml'), 1);
    file_put_contents($staged, $original . "\n// Changed artifact\n");
    $run($publish, 1); // An existing version cannot be silently replaced.
    echo "Mirror publish and immutable-tag retry passed\n";
} finally {
    file_put_contents($staged, $original);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($temp);
}
