<?php

declare(strict_types=1);

use LexSift\Matcher;

// 使用 --iterations=N 统一覆盖迭代次数；保持五次预热和五次采样。
$iterations = 20;
foreach (array_slice($argv, 1) as $argument) {
    if (!preg_match('/^--iterations=([1-9][0-9]*)$/', $argument, $match)) {
        throw new InvalidArgumentException('Usage: run.php [--iterations=N]');
    }
    $iterations = (int) $match[1];
}

function medianMicros(callable $operation, int $iterations): float
{
    for ($i = 0; $i < 5; ++$i) $operation();
    $samples = [];
    for ($sample = 0; $sample < 5; ++$sample) {
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; ++$i) $result = $operation();
        $samples[] = (hrtime(true) - $start) / $iterations / 1000;
        // 在计时区间外检查并释放结果。
        if ($result === null) throw new RuntimeException('Missing benchmark result');
        unset($result);
    }
    sort($samples, SORT_NUMERIC);
    return round($samples[2], 3);
}

$rows = [];
foreach ([100, 1000, 10000] as $count) {
    $normal = array_map(fn (int $i): string => sprintf('term%05d', $i), range(0, $count - 1));
    $overlap = array_merge(array_map(fn (int $i): string => str_repeat('a', $i), range(1, 16)), array_slice($normal, 16));
    foreach (['normal' => $normal, 'overlap' => $overlap] as $dictionary => $terms) {
        $build = medianMicros(fn (): Matcher => new Matcher($terms), $iterations);
        $matcher = new Matcher($terms);
        $inputs = [];
        if ($dictionary === 'normal') {
            foreach (['short' => 128, 'long' => 65536] as $size => $bytes) {
                $word = $terms[$count - 1];
                $padding = str_repeat('x', $bytes - strlen($word));
                $inputs[$size . '-none'] = [str_repeat('x', $bytes), 0];
                $inputs[$size . '-head'] = [$word . $padding, 1];
                $inputs[$size . '-tail'] = [$padding . $word, 1];
            }
        } else {
            // 对长度 1 到 16 求和：Σ(128 - 长度 + 1) = 1928 个重叠匹配。
            $inputs['short-overlap'] = [str_repeat('a', 128), 1928];
            $inputs['long-overlap'] = [str_repeat('x', 65536 - 128) . str_repeat('a', 128), 1928];
        }
        foreach ($inputs as $scenario => [$input, $hits]) {
            $actual = count($matcher->scan($input));
            if ($actual !== $hits || $matcher->contains($input) !== ($hits > 0)) {
                throw new RuntimeException('Unexpected result in ' . $scenario);
            }
            $rows[] = [
                'terms' => $count,
                'dictionary' => $dictionary,
                'scenario' => $scenario,
                'bytes' => strlen($input),
                'hits' => $actual,
                'build_us' => $build,
                'contains_us' => medianMicros(fn (): bool => $matcher->contains($input), $iterations),
                'scan_us' => medianMicros(fn (): array => $matcher->scan($input), $iterations),
                'mask_us' => medianMicros(fn (): string => $matcher->mask($input), $iterations),
            ];
        }
    }
}
echo json_encode([
    'environment' => ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'arch' => php_uname('m'), 'extension' => phpversion('lexsift')],
    'method' => ['unit' => 'microseconds/op', 'statistic' => 'median of sample means', 'warmups' => 5, 'samples' => 5, 'iterations_per_sample' => $iterations, 'options' => 'defaults', 'build_includes_constructor_and_object_lifecycle' => true],
    'results' => $rows,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
