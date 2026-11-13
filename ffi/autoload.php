<?php

declare(strict_types=1);

// 扩展会在 Composer 自动加载前注册同名 API，此时保留扩展类，避免重复定义。
if (!class_exists(\LexSift\Matcher::class, false)) {
    require_once __DIR__ . '/src/Native.php';
    require_once __DIR__ . '/src/Matcher.php';
}
