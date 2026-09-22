<?php

declare(strict_types=1);

// 每个扩展在独立进程中运行；查询耗时不包含实例构造时间。
function measure(callable $operation, int $iterations): float
{
    for ($i = 0; $i < 5; ++$i) $operation();
    $samples = [];
    for ($sample = 0; $sample < 7; ++$sample) {
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; ++$i) $operation();
        $samples[] = (hrtime(true) - $start) / $iterations / 1000;
    }
    sort($samples, SORT_NUMERIC);
    return round($samples[3], 3);
}

$rows = [];
foreach (['zh' => ['敏感词', '文'], 'ascii' => ['term', 'x']] as $language => [$prefix, $filler]) {
    $terms = array_map(fn(int $i): string => sprintf('%s%05d', $prefix, $i), range(0, 9999));
    $hit = $terms[9999];
    foreach (['plain' => [], 'whitelist' => [$hit . 'safe']] as $path => $whitelist) {
        $matcher = new LexSift\Matcher($terms, $whitelist);
        $rows["$language/$path/build"] = measure(fn() => new LexSift\Matcher($terms, $whitelist), 1);
        foreach (['short' => 20, 'long' => 10000] as $length => $count) {
            $body = str_repeat($filler, $count);
            $inputs = [
                'none' => $body,
                'head' => $hit . $body,
                'tail' => $body . $hit,
                'dense' => str_repeat($hit, $length === 'short' ? 3 : 1000),
            ];
            if ($whitelist !== []) {
                $inputs['exempt'] = $hit . 'safe' . $body;
                $inputs['exempt_then_hit'] = $hit . 'safe' . $body . $hit;
            }
            foreach ($inputs as $position => $text) {
                $expected = !in_array($position, ['none', 'exempt'], true);
                if ($matcher->contains($text) !== $expected || ($matcher->scan($text) !== []) !== $expected) {
                    throw new RuntimeException("Unexpected result: $language/$path/$length/$position");
                }
                foreach (['contains', 'scan', 'mask'] as $method) {
                    $rows["$language/$path/$length/$position/$method"] = measure(fn() => $matcher->$method($text), 20);
                }
            }
        }
    }
}
echo json_encode(['php' => PHP_VERSION, 'unit' => 'microseconds', 'warmups' => 5, 'samples' => 7,
    'query_iterations' => 20, 'build_iterations' => 1, 'medians' => $rows], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
