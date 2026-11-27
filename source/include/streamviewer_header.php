<?php
/* ============================================================================
   STREAM VIEWER
   Copyright (C) 2026 Lazaros Chalkidis
   License: GPLv3
   ========================================================================= */

header('Content-Type: application/json');
header('Cache-Control: no-cache');
$cfg = @parse_ini_file('/boot/config/plugins/streamviewer/streamviewer.cfg') ?: [];
if (((string)($cfg['HEADER_SHOW_BADGE'] ?? '1')) === '0') {
    echo '{"count":0}';
    exit;
}
$f     = '/tmp/streamviewer_cache/header_count';
$count = @is_file($f) ? max(0, (int)trim((string)@file_get_contents($f))) : 0;
$age   = time() - (int)@filemtime($f);
$ttl   = ((string)($cfg['STATS_ENABLED'] ?? '0')) === '1' ? 90 : 30;

if ($age > $ttl) {
    $lock = @fopen('/tmp/streamviewer_cache/header.lock', 'c');
    if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
        $count = 0;
        require_once '/usr/local/emhttp/plugins/streamviewer/include/streamviewer_api.php';
        try {
            $count = (new StreamViewerEndpoint())->headerCount();
        } catch (\Throwable $e) {
        }
        flock($lock, LOCK_UN);
    } elseif ($age > 2 * $ttl) {
        $count = 0;
    }
    if ($lock !== false) fclose($lock);
}
echo '{"count":' . $count . '}';
