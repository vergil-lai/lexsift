<?php

declare(strict_types=1);

// php benchmarks/compare.php [--php-autoload=/path/to/lexsift-php/vendor/autoload.php]
// 可用 --backend=ext|ffi|php 单独运行一个后端；完整对比始终串行启动独立进程。
$root = dirname(__DIR__);
$options = getopt('', ['backend:', 'php-autoload:', 'output:']);
$phpAutoload = $options['php-autoload'] ?? dirname($root) . '/lexsift-php/vendor/autoload.php';
$output = $options['output'] ?? $root . '/target/benchmarks/compare';

function sourceHash(array $files): string
{
    sort($files);
    $hash = hash_init('sha256');
    foreach ($files as $file) {
        hash_update($hash, basename($file) . ':' . hash_file('sha256', $file) . "\n");
    }
    return hash_final($hash);
}

/** 查询不包含构建；所有结果均在计时内释放，构建也包括实例销毁。 */
function measure(callable $operation, bool $build = false): array
{
    for ($i = 0; $i < 5; ++$i) {
        $operation();
    }
    $start = hrtime(true);
    $operation();
    $pilot = max(1, hrtime(true) - $start);
    $iterations = $build ? 1 : max(10, min(2000, (int) ceil(20_000_000 / $pilot)));
    $samples = [];
    $count = $build ? 5 : 7;
    for ($sample = 0; $sample < $count; ++$sample) {
        gc_collect_cycles();
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; ++$i) {
            $result = $operation();
            unset($result);
        }
        $samples[] = (hrtime(true) - $start) / $iterations / 1000;
    }
    $sorted = $samples;
    sort($sorted, SORT_NUMERIC);
    return ['median_us' => $sorted[intdiv($count, 2)], 'iterations' => $iterations, 'samples_us' => $samples];
}

if (isset($options['backend'])) {
    $backend = $options['backend'];
    if ($backend === 'ext') {
        if (!extension_loaded('lexsift')) {
            throw new RuntimeException('The ext worker requires the freshly built extension');
        }
        $class = LexSift\Matcher::class;
    } elseif ($backend === 'ffi') {
        if (extension_loaded('lexsift')) {
            throw new RuntimeException('The FFI worker must not load ext-lexsift');
        }
        require $root . '/ffi/autoload.php';
        $class = LexSift\Matcher::class;
    } elseif ($backend === 'php') {
        if (extension_loaded('lexsift')) {
            throw new RuntimeException('The PHP worker must not load ext-lexsift');
        }
        require $phpAutoload;
        $class = VergilLai\LexSift\Matcher::class;
    } else {
        throw new InvalidArgumentException('Unknown backend: ' . $backend);
    }
    if ((new ReflectionClass($class))->isInternal() !== ($backend === 'ext')) {
        throw new RuntimeException('Unexpected Matcher implementation');
    }

    $terms = array_map(static fn (int $i): string => sprintf('敏感词%05d', $i), range(0, 9999));
    $hit = $terms[9999];
    $body = str_repeat('文', 10000);
    $plain = new $class($terms);
    $whitelist = new $class($terms, [$hit . 'safe']);
    $scenarios = [
        'scan/zh/short/none' => [$plain, 'scan', str_repeat('文', 100)],
        'scan/zh/long/none' => [$plain, 'scan', $body],
        'scan/ascii/long/none' => [$plain, 'scan', str_repeat('x', 10000)],
        'scan/zh/dense' => [$plain, 'scan', str_repeat($hit, 1000)],
        'contains/zh/short/head' => [$plain, 'contains', $hit . str_repeat('文', 100)],
        'contains/zh/long/head' => [$plain, 'contains', $hit . $body],
        'contains/zh/long/middle' => [$plain, 'contains', str_repeat('文', 5000) . $hit . str_repeat('文', 5000)],
        'contains/zh/long/tail' => [$plain, 'contains', $body . $hit],
        'contains/zh/long/none' => [$plain, 'contains', $body],
        'contains/ascii/long/none' => [$plain, 'contains', str_repeat('x', 10000)],
        'contains/whitelist/head' => [$whitelist, 'contains', $hit . $body],
        'contains/whitelist/exempt' => [$whitelist, 'contains', $hit . 'safe' . $body],
        'contains/whitelist/exempt_then_hit' => [$whitelist, 'contains', $hit . 'safe' . $body . $hit],
        'mask/zh/separated' => [$plain, 'mask', str_repeat($hit . '文', 1000)],
    ];
    $rows = ['build/10000' => measure(static fn () => new $class($terms), true)];
    $checks = [];
    foreach ($scenarios as $name => [$matcher, $method, $text]) {
        $value = $matcher->$method($text);
        $expectedHit = !str_ends_with($name, '/none') && !str_ends_with($name, '/exempt');
        if ($matcher->contains($text) !== $expectedHit) {
            throw new RuntimeException('Unexpected contains result: ' . $name);
        }
        $checks[$name] = hash('sha256', serialize($value));
        $rows[$name] = measure(static fn () => $matcher->$method($text));
        $rows[$name]['bytes'] = strlen($text);
    }
    // 在计时之外额外核对 Unicode 映射、白名单、重叠匹配和词库替换。
    foreach ([
        [['BAD', '赌博'], [], [], 'ＢＡＤ 赌 博'],
        [['é', 'ffi'], [], [], "e\u{0301} ﬃ"],
        [['ab', 'abc', 'bc'], [], [], 'abc'],
        [['赌博'], ['合法赌博说明'], [], '合法赌博说明 赌博'],
        [['赌博'], [], [], "赌\u{200B}博 赌👨‍👩‍👧‍👦博"],
        [['赌博'], [], ['remove_punctuation' => true], '赌-博'],
        [["a\0b"], [], [], "xa\0by"],
    ] as $index => [$dictionary, $allowed, $normalization, $text]) {
        $matcher = new $class($dictionary, $allowed, $normalization);
        foreach (['contains', 'scan', 'mask'] as $method) {
            $checks["semantic/$index/$method"] = hash('sha256', serialize($matcher->$method($text)));
        }
        $matcher->replaceTerms(['替换']);
        $matcher->replaceWhitelist(['替换许可']);
        $checks["replace/$index"] = hash('sha256', serialize($matcher->scan('替换许可 替换')));
    }
    $sourceFiles = $backend === 'php'
        ? glob(dirname($phpAutoload, 2) . '/src/*.php')
        : array_merge(glob($root . '/src/*.rs'), [$root . '/Cargo.toml', $root . '/Cargo.lock']);
    if ($backend === 'php') {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname($phpAutoload, 2) . '/src', FilesystemIterator::SKIP_DOTS));
        $sourceFiles = [];
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $sourceFiles[] = $file->getPathname();
            }
        }
    } elseif ($backend === 'ffi') {
        $sourceFiles = array_merge($sourceFiles, glob($root . '/ffi/src/*.php'), [$root . '/ffi/autoload.php', $root . '/ffi/lexsift.h']);
    }
    echo json_encode([
        'backend' => $backend,
        'environment' => ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'arch' => php_uname('m'),
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
            'extension_version' => phpversion('lexsift'), 'icu' => defined('INTL_ICU_VERSION') ? INTL_ICU_VERSION : null],
        'source_sha256' => sourceHash($sourceFiles),
        'results' => $rows, 'checks' => $checks,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    exit;
}

@mkdir($output, 0777, true);
$runs = [];
foreach (['ext', 'ffi', 'php', 'php', 'ffi', 'ext'] as $index => $backend) {
    $command = [PHP_BINARY, '-n', '-d', 'opcache.enable_cli=0', '-d', 'opcache.jit=0', '-d', 'ffi.enable=true', '-d', 'memory_limit=512M'];
    if ($backend === 'ext') {
        $command[] = '-d';
        $command[] = 'extension=' . $root . '/target/php-build/modules/lexsift.so';
    }
    array_push($command, __FILE__, '--backend=' . $backend, '--php-autoload=' . $phpAutoload);
    fwrite(STDERR, 'Running ' . ($index + 1) . '/6: ' . $backend . "\n");
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes, $root);
    fclose($pipes[0]);
    $json = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Benchmark worker failed: ' . $backend);
    }
    $run = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    if ($runs !== [] && $run['checks'] !== $runs[0]['checks']) {
        throw new RuntimeException('Backend outputs differ: ' . $backend);
    }
    file_put_contents($output . '/' . ($index + 1) . '-' . $backend . '.json', $json);
    $runs[] = $run;
}
$comparison = [];
foreach (array_keys($runs[0]['results']) as $scenario) {
    foreach (['ext', 'ffi', 'php'] as $backend) {
        $medians = [];
        foreach ($runs as $run) {
            if ($run['backend'] === $backend) {
                $medians[] = $run['results'][$scenario]['median_us'];
            }
        }
        $comparison[$scenario][$backend . '_ms'] = array_sum($medians) / count($medians) / 1000;
    }
    $comparison[$scenario]['php_over_ext'] = $comparison[$scenario]['php_ms'] / $comparison[$scenario]['ext_ms'];
    $comparison[$scenario]['php_over_ffi'] = $comparison[$scenario]['php_ms'] / $comparison[$scenario]['ffi_ms'];
    $comparison[$scenario]['ffi_over_ext'] = $comparison[$scenario]['ffi_ms'] / $comparison[$scenario]['ext_ms'];
}
$report = ['environment' => $runs[0]['environment'],
    'method' => ['order' => array_column($runs, 'backend'), 'warmups' => 5, 'query_samples' => 7,
        'query_target_ms' => 20, 'query_iterations_min' => 10, 'query_iterations_max' => 2000,
        'build_samples' => 5, 'build_iterations' => 1, 'dictionary_terms' => 10000, 'options' => 'defaults',
        'aggregate' => 'mean of two process medians', 'matching_checks' => count($runs[0]['checks'])],
    'source_sha256' => array_combine(['ext', 'ffi', 'php'], array_column(array_slice($runs, 0, 3), 'source_sha256')),
    'results' => $comparison];
file_put_contents($output . '/comparison.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo $output . "/comparison.json\n";
