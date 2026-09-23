<?php
declare(strict_types=1);

if (!extension_loaded('lexsift')) {
    throw new RuntimeException('lexsift is not enabled');
}

$matcher = new LexSift\Matcher(['赌博', '微信'], ['微信支付']);
if (!$matcher->contains('前赌 博后') || $matcher->contains('微信支付')) {
    throw new RuntimeException('Matching or whitelist failed');
}
if ($matcher->mask('前赌 博后') !== '前*后') {
    throw new RuntimeException('Masking failed');
}
echo "Docker installation smoke test passed\n";
