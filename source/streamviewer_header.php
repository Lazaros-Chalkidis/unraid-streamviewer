<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache');
$cfg = @parse_ini_file('/boot/config/plugins/streamviewer/streamviewer.cfg') ?: [];
if (((string)($cfg['HEADER_SHOW_BADGE'] ?? '1')) === '0') {
    echo '{"count":0}';
    exit;
}
$f = '/tmp/streamviewer_cache/header_count';
$count = @is_file($f) ? max(0, (int)trim((string)@file_get_contents($f))) : 0;
echo '{"count":' . $count . '}';
