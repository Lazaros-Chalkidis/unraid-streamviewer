#!/usr/bin/php
<?php
/* ============================================================================
   STREAM VIEWER
   Copyright (C) 2026 Lazaros Chalkidis
   License: GPLv3
   ========================================================================= */

declare(strict_types=1);

$cronFile = '/var/spool/cron/crontabs/root';
// older versions left a crontab entry, clear it: the poll daemon drives this now
if (@is_file($cronFile) && strpos((string)@file_get_contents($cronFile), 'streamviewer_cron') !== false) {
    @exec("sed -i '/streamviewer_cron/d' " . escapeshellarg($cronFile));
}

$lockFile = '/tmp/streamviewer_cron.lock';
$lockFp   = fopen($lockFile, 'c');
// lock so two polls never run at once
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {

    exit(0);
}

require_once '/usr/local/emhttp/plugins/streamviewer/include/streamviewer_api.php';

try {
    $count = (new StreamViewerEndpoint())->cronPoll();
} catch (\Throwable $e) {
    $count = 0;
}

@file_put_contents('/tmp/streamviewer_cache/header_count', (string)$count);

flock($lockFp, LOCK_UN);
fclose($lockFp);
