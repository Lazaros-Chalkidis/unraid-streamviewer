#!/usr/bin/php
<?php
/**
 * StreamViewer Poll -- headless session recorder
 * Copyright (C) 2026 Lazaros Chalkidis
 * License: GPLv3
 *
 * Polls all enabled media servers and writes active sessions to SQLite
 * so that statistics are recorded even when no browser is open.
 * Called by streamviewer_poll.sh (background daemon).
 *
 * Safety:
 *   - flock() prevents overlapping runs
 *   - Exits immediately if statistics are disabled in settings
 *   - Uses the same recordSessions() logic as the browser-based poll,
 *     so active_sessions deduplication is handled by ON CONFLICT upsert
 */

declare(strict_types=1);

// Self-cleanup: remove old cron entry from previous versions (one-time, no-op once clean)
$cronFile = '/var/spool/cron/crontabs/root';
if (@is_file($cronFile) && strpos((string)@file_get_contents($cronFile), 'streamviewer_cron') !== false) {
    @exec("sed -i '/streamviewer_cron/d' " . escapeshellarg($cronFile));
}

// Prevent overlapping runs
$lockFile = '/tmp/streamviewer_cron.lock';
$lockFp   = fopen($lockFile, 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    // Another instance is still running, skip this cycle
    exit(0);
}

// Load the main API class (the auto-run guard checks SCRIPT_FILENAME,
// so requiring this file will not trigger an HTTP response)
require_once '/usr/local/emhttp/plugins/streamviewer/include/streamviewer_api.php';

try {
    $count = (new StreamViewerEndpoint())->cronPoll();
} catch (\Throwable $e) {
    $count = 0;
}

// Write live session count for the header indicator
@file_put_contents('/tmp/streamviewer_cache/header_count', (string)$count);

// Release lock
flock($lockFp, LOCK_UN);
fclose($lockFp);
