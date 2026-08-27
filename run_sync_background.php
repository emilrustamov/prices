<?php
require_once __DIR__ . '/config.php';

$lockFile = __DIR__ . '/cache/sync.lock';
$dir = __DIR__ . '/cache';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

try {
    passthru('php ' . escapeshellarg(__DIR__ . '/sync.php'));
    clearReportCache();
} finally {
    if (is_file($lockFile)) {
        unlink($lockFile);
    }
}
