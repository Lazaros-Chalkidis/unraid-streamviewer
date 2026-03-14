<?php
declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/dynamix/include/Helpers.php';

final class StreamViewerEndpoint
{
    // ── Plugin constants ───────────────────────────────────────────────────
    private const PLUGIN_NAME          = 'streamviewer';
    private const CFG_FILE             = '/boot/config/plugins/streamviewer/streamviewer.cfg';
    private const MAX_SERVERS          = 10;
    private const VALID_TYPES          = ['plex', 'jellyfin', 'emby'];

    // ── Plex OAuth ─────────────────────────────────────────────────────────
    private const PLEX_CLIENT_ID       = 'stream-viewer-unraid';
    private const PLEX_PRODUCT         = 'Stream Viewer for Unraid';

    // ── Cache & rate limiting ──────────────────────────────────────────────
    private const CACHE_DIR            = '/tmp/streamviewer_cache';
    private const NONCE_FILE           = '/tmp/streamviewer_cache/nonce';
    private const NONCE_TTL            = 3600;   // seconds
    private const RATE_LIMIT_FILE      = '/tmp/streamviewer_cache/rl';
    private const RATE_LIMIT_MAX       = 120;    // requests per minute per IP
    private const MICRO_CACHE_MS       = 500;    // deduplicate rapid widget refreshes

    // ── HTTP ───────────────────────────────────────────────────────────────
    private const HTTP_TIMEOUT         = 7;
    private const HTTP_CONNECT_TIMEOUT = 4;

    public function __construct()
    {
        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0700, true);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Security — CSRF nonce
    // ══════════════════════════════════════════════════════════════════════

    public static function generateNonce(): string
    {
        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0700, true);
        }
        $now  = time();
        $file = self::NONCE_FILE;

        if (is_file($file)) {
            $data = @json_decode((string)@file_get_contents($file), true);
            if (is_array($data) && isset($data['token'], $data['ts'])
                && ($now - (int)$data['ts']) < self::NONCE_TTL) {
                return (string)$data['token'];
            }
        }

        $token = bin2hex(random_bytes(24));
        @file_put_contents($file, json_encode(['token' => $token, 'ts' => $now]), LOCK_EX);
        @chmod($file, 0600);
        return $token;
    }

    private function verifyNonce(): void
    {
        $provided = (string)(
            $_GET['_svt']  ??
            $_POST['_svt'] ??
            $_SERVER['HTTP_X_SV_TOKEN'] ?? ''
        );
        if ($provided === '') $this->json(['error' => 'Missing token'], 403);
        if (!is_file(self::NONCE_FILE)) $this->json(['error' => 'Invalid token'], 403);

        $data = @json_decode((string)@file_get_contents(self::NONCE_FILE), true);
        if (!is_array($data) || !isset($data['token'], $data['ts'])) {
            $this->json(['error' => 'Invalid token'], 403);
        }
        if ((time() - (int)$data['ts']) > self::NONCE_TTL) $this->json(['error' => 'Token expired'], 403);
        if (!hash_equals((string)$data['token'], $provided)) $this->json(['error' => 'Invalid token'], 403);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Security — rate limiting (file-based, per IP)
    // ══════════════════════════════════════════════════════════════════════

    private function enforceRateLimit(): void
    {
        $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $file = self::RATE_LIMIT_FILE . '_' . hash('sha256', $ip);
        $now  = time();

        $data = ['count' => 0, 'window' => $now];
        if (is_file($file)) {
            $raw = @json_decode((string)@file_get_contents($file), true);
            if (is_array($raw)) $data = $raw;
        }
        if (($now - (int)$data['window']) >= 60) {
            $data = ['count' => 0, 'window' => $now];
        }
        $data['count']++;
        @file_put_contents($file, json_encode($data), LOCK_EX);

        if ((int)$data['count'] > self::RATE_LIMIT_MAX) {
            header('Retry-After: 60');
            $this->json(['error' => 'Rate limit exceeded'], 429);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Security — request middleware
    // ══════════════════════════════════════════════════════════════════════

    private function enforceAjaxGet(): void
    {
        if (!$this->isAjax()) {
            header('HTTP/1.1 403 Forbidden');
            exit('Direct access not allowed');
        }
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
        if ($method !== 'GET' && $method !== 'POST') {
            header('HTTP/1.1 405 Method Not Allowed');
            exit('Only GET/POST');
        }
        $this->enforceLocalOrigin();
    }

    private function enforceLocalOrigin(): void
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '') return;

        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $key) {
            $val = $_SERVER[$key] ?? '';
            if ($val === '') continue;
            $parsed  = parse_url($val);
            $reqHost = ($parsed['host'] ?? '') . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
            if ($reqHost !== '' && $reqHost !== $host) {
                header('HTTP/1.1 403 Forbidden');
                exit('Cross-origin requests not allowed');
            }
        }
    }

    private function isAjax(): bool
    {
        $hdrs = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $xrw  = $hdrs['X-Requested-With'] ?? $hdrs['x-requested-with']
              ?? ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? null);
        return $xrw === 'XMLHttpRequest';
    }

    // ══════════════════════════════════════════════════════════════════════
    // Request router
    // ══════════════════════════════════════════════════════════════════════

    public function run(): void
    {
        $action = (string)($_GET['action'] ?? '');

        if ($action === 'get_thumb') {
            $this->replyGetThumb();
            return;
        }

        $this->enforceAjaxGet();
        $this->verifyNonce();
        $this->enforceRateLimit();

        $action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
        $routes = [
            'get_sessions'    => fn() => $this->replyGetSessions(),
            'get_servers'     => fn() => $this->replyGetServers(),
            'test_connection' => fn() => $this->replyTestConnection(),
            'kill_session'    => fn() => $this->replyKillSession(),
            'plex_create_pin' => fn() => $this->replyPlexCreatePin(),
            'plex_poll_pin'   => fn() => $this->replyPlexPollPin(),
        ];

        if (!isset($routes[$action])) $this->json(['error' => 'Invalid action'], 400);
        $routes[$action]();
    }

    // ══════════════════════════════════════════════════════════════════════
    // Config helpers
    // ══════════════════════════════════════════════════════════════════════

    private function loadCfg(): array
    {
        $cfg = @parse_plugin_cfg(self::PLUGIN_NAME, true);
        return is_array($cfg) ? $cfg : [];
    }

    private function getEnabledServers(array $cfg): array
    {
        $servers = [];
        for ($i = 1; $i <= self::MAX_SERVERS; $i++) {
            if (($cfg["SERVER{$i}_ENABLED"] ?? '0') !== '1') continue;

            $type  = (string)($cfg["SERVER{$i}_TYPE"]  ?? '');
            $url   = rtrim(trim((string)($cfg["SERVER{$i}_URL"]   ?? '')), '/');
            $token = trim((string)($cfg["SERVER{$i}_TOKEN"] ?? ''));
            $name  = trim((string)($cfg["SERVER{$i}_NAME"]  ?? "Server {$i}"));

            if (!in_array($type, self::VALID_TYPES, true)) continue;
            if ($url === '' || $token === '') continue;

            $index = $i;
            $servers[] = compact('index', 'type', 'name', 'url', 'token');
        }
        return $servers;
    }

    // ══════════════════════════════════════════════════════════════════════
    // Action: get_sessions
    // ══════════════════════════════════════════════════════════════════════

    private function replyGetSessions(): void
    {
        $cfg       = $this->loadCfg();
        $cachePath = self::CACHE_DIR . '/sess_' . $this->sessionsCacheKey($cfg) . '.json';
        $cached    = $this->cacheGet($cachePath, self::MICRO_CACHE_MS);
        if ($cached !== null) $this->rawJson($cached);

        $servers     = $this->getEnabledServers($cfg);
        $sessions    = [];
        $serverStats = [];

        foreach ($servers as $srv) {
            $result = $this->fetchSessions($srv);
            $serverStats[] = [
                'name'            => $srv['name'],
                'type'            => $srv['type'],
                'status'          => $result['ok'] ? 'online' : 'error',
                'error'           => $result['error'] ?? null,
                'active_sessions' => count($result['sessions'] ?? []),
            ];
            foreach ($result['sessions'] ?? [] as $s) $sessions[] = $s;
        }

        $json = (string)json_encode([
            'sessions'       => $sessions,
            'servers'        => $serverStats,
            'total_sessions' => count($sessions),
            'timestamp'      => time(),
            'no_servers'     => empty($servers),
        ]);
        $this->cachePut($cachePath, $json);
        $this->rawJson($json);
    }

    private function fetchSessions(array $srv): array
    {
        return match($srv['type']) {
            'plex'     => $this->fetchPlexSessions($srv),
            'jellyfin' => $this->fetchJellyfinSessions($srv),
            'emby'     => $this->fetchEmbySession($srv),
            default    => ['ok' => false, 'sessions' => [], 'error' => 'Unknown server type'],
        };
    }

    // ══════════════════════════════════════════════════════════════════════
    // Plex session fetching
    // ══════════════════════════════════════════════════════════════════════

    private function fetchPlexSessions(array $srv): array
    {
        $url = $srv['url'] . '/status/sessions';
        [$body, $httpCode, $err] = $this->httpGet($url, [
            'X-Plex-Token' => $srv['token'],
            'Accept'       => 'application/json',
        ]);

        // On connection error (not auth), try auto-rediscover once
        $isConnErr = ($err !== null) || ($httpCode !== 0 && $httpCode !== 200 && $httpCode !== 401);
        if ($isConnErr && $httpCode !== 401 && isset($srv['index'])) {
            $newUrl = $this->plexRediscoverUrl($srv);
            if ($newUrl !== '' && $newUrl !== rtrim($srv['url'], '/')) {
                $this->updateServerUrl((int)$srv['index'], $newUrl);
                $srv['url'] = $newUrl;
                $url        = $newUrl . '/status/sessions';
                [$body, $httpCode, $err] = $this->httpGet($url, [
                    'X-Plex-Token' => $srv['token'],
                    'Accept'       => 'application/json',
                ]);
            }
        }

        if ($err !== null) return ['ok' => false, 'sessions' => [], 'error' => $err];
        if ($httpCode === 401) return ['ok' => false, 'sessions' => [], 'error' => 'Invalid Plex token'];
        if ($httpCode !== 200) return ['ok' => false, 'sessions' => [], 'error' => "HTTP {$httpCode}"];

        $data = @json_decode($body, true);
        if (!is_array($data)) return ['ok' => false, 'sessions' => [], 'error' => 'Invalid JSON response'];

        $sessions = [];
        foreach ($data['MediaContainer']['Metadata'] ?? [] as $item) {
            $player   = $item['Player']   ?? [];
            $user     = $item['User']     ?? [];
            $media    = $item['Media'][0] ?? [];
            $stream   = $media['Part'][0] ?? [];
            $videoS   = null;
            $audioS   = null;

            foreach ($stream['Stream'] ?? [] as $s) {
                if (($s['streamType'] ?? 0) == 1) $videoS = $s;
                if (($s['streamType'] ?? 0) == 2) $audioS = $s;
            }

            $playType  = $this->normalizePlexPlayType($item);
            $thumbPath = $item['grandparentThumb'] ?? $item['parentThumb'] ?? $item['thumb'] ?? null;
            $thumbUrl  = ($thumbPath !== null && $thumbPath !== '' && $srv['url'] !== '')
                ? rtrim($srv['url'], '/') . $thumbPath . '?X-Plex-Token=' . urlencode($srv['token'])
                : '';

            $sessions[] = $this->normalizeSession([
                'server_name'           => $srv['name'],
                'server_type'           => 'plex',
                'session_id'            => (string)($item['sessionKey']                   ?? $player['machineIdentifier'] ?? ''),
                'session_key'           => (string)($item['sessionKey']                   ?? ''),
                'plex_session_uuid'     => (string)($item['Session']['id']                ?? ''),
                'title'                 => $this->buildTitle($item),
                'user'                  => (string)($user['title']                        ?? 'Unknown'),
                'device'                => (string)($player['title']                      ?? 'Unknown'),
                'client'                => (string)($player['product']                    ?? ''),
                'platform'              => (string)($player['platform']                   ?? ''),
                'ip_address'            => (string)($player['address']                    ?? ''),
                'state'                 => strtolower((string)($player['state']           ?? 'playing')),
                'play_type'             => $playType,
                'progress_ms'           => (int)($item['viewOffset']                      ?? 0),
                'duration_ms'           => (int)($item['duration']                        ?? 0),
                'bandwidth_kbps'        => (int)($media['bitrate']                        ?? 0),
                'quality'               => (string)($media['videoResolution']             ?? ''),
                'bitrate'               => (int)($media['bitrate']                        ?? 0),
                'container'             => (string)($media['container']                   ?? ''),
                'video_codec'           => (string)($videoS['codec']                      ?? ''),
                'audio_codec'           => (string)($audioS['codec']                      ?? ''),
                'audio_channels'        => (int)($audioS['channels']                      ?? 0),
                'transcode_video_codec' => ($playType === 'transcode') ? (string)($item['TranscodeSession']['videoCodec'] ?? '') : '',
                'transcode_speed'       => ($playType === 'transcode') ? (float)($item['TranscodeSession']['speed']       ?? 0) : 0,
                'thumb_url'             => $thumbUrl,
                'media_type'            => strtolower((string)($item['type']              ?? 'video')),
                'summary'               => (string)($item['summary']                      ?? ''),
            ]);
        }

        return ['ok' => true, 'sessions' => $sessions];
    }

    private function normalizePlexPlayType(array $item): string
    {
        $ts = $item['TranscodeSession'] ?? null;
        if ($ts === null) return 'direct_play';
        return strtolower((string)($ts['videoDecision'] ?? 'copy')) === 'transcode'
            ? 'transcode' : 'direct_stream';
    }

    // ══════════════════════════════════════════════════════════════════════
    // Jellyfin session fetching
    // ══════════════════════════════════════════════════════════════════════

    private function fetchJellyfinSessions(array $srv): array
    {
        $url = $srv['url'] . '/Sessions?ActiveWithinSeconds=960';
        [$body, $httpCode, $err] = $this->httpGet($url, [
            'X-Emby-Token'         => $srv['token'],
            'X-MediaBrowser-Token' => $srv['token'],
            'Accept'               => 'application/json',
        ]);

        if ($err !== null) return ['ok' => false, 'sessions' => [], 'error' => $err];
        if ($httpCode === 401) return ['ok' => false, 'sessions' => [], 'error' => 'Invalid Jellyfin API key'];
        if ($httpCode !== 200) return ['ok' => false, 'sessions' => [], 'error' => "HTTP {$httpCode}"];

        $data = @json_decode($body, true);
        if (!is_array($data)) return ['ok' => false, 'sessions' => [], 'error' => 'Invalid JSON response'];

        $sessions = [];
        foreach ($data as $item) {
            $nowPlaying = $item['NowPlayingItem'] ?? null;
            if ($nowPlaying === null) continue;

            $playState = $item['PlayState']       ?? [];
            $transInfo = $item['TranscodingInfo'] ?? null;
            $playType  = $this->normalizeJfPlayType($transInfo);

            $durationMs = (int)(((int)($nowPlaying['RunTimeTicks'] ?? 0)) / 10000);
            $progressMs = (int)(((int)($playState['PositionTicks'] ?? 0)) / 10000);
            $state      = ($playState['IsPaused'] ?? false) ? 'paused' : 'playing';

            $videoCodec = $audioCodec = $container = $quality = '';
            $bitrate = $audioChannels = 0;

            $mediaSources = $nowPlaying['MediaSources'] ?? [];
            if (!empty($mediaSources)) {
                $src       = $mediaSources[0];
                $container = (string)($src['Container'] ?? '');
                $bitrate   = (int)(($src['Bitrate'] ?? 0) / 1000);
                foreach ($src['MediaStreams'] ?? [] as $s) {
                    if (($s['Type'] ?? '') === 'Video' && $videoCodec === '') {
                        $videoCodec = (string)($s['Codec'] ?? '');
                        $quality    = isset($s['Height']) ? $s['Height'] . 'p' : '';
                    }
                    if (($s['Type'] ?? '') === 'Audio' && $audioCodec === '') {
                        $audioCodec    = (string)($s['Codec']    ?? '');
                        $audioChannels = (int)($s['Channels']    ?? 0);
                    }
                }
            }
            if ($transInfo !== null) {
                if ($videoCodec === '') $videoCodec = (string)($transInfo['VideoCodec'] ?? '');
                if ($audioCodec === '') $audioCodec = (string)($transInfo['AudioCodec'] ?? '');
                if ($bitrate    === 0)  $bitrate    = (int)(($transInfo['Bitrate']      ?? 0) / 1000);
            }

            $imageId  = $nowPlaying['SeriesId'] ?? $nowPlaying['SeasonId'] ?? $nowPlaying['Id'] ?? null;
            $thumbUrl = ($imageId && $srv['url'] !== '')
                ? rtrim($srv['url'], '/') . '/Items/' . urlencode($imageId) . '/Images/Primary?maxHeight=70&maxWidth=50&api_key=' . urlencode($srv['token'])
                : '';

            $sessions[] = $this->normalizeSession([
                'server_name'           => $srv['name'],
                'server_type'           => 'jellyfin',
                'session_id'            => (string)($item['Id']             ?? ''),
                'session_key'           => (string)($item['Id']             ?? ''),
                'title'                 => $this->buildJfTitle($nowPlaying),
                'user'                  => (string)($item['UserName']       ?? 'Unknown'),
                'device'                => (string)($item['DeviceName']     ?? 'Unknown'),
                'client'                => (string)($item['Client']         ?? ''),
                'platform'              => (string)($item['Client']         ?? ''),
                'ip_address'            => (string)($item['RemoteEndPoint'] ?? ''),
                'state'                 => $state,
                'play_type'             => $playType,
                'progress_ms'           => $progressMs,
                'duration_ms'           => $durationMs,
                'bandwidth_kbps'        => $bitrate,
                'quality'               => $quality,
                'bitrate'               => $bitrate,
                'container'             => $container,
                'video_codec'           => $videoCodec,
                'audio_codec'           => $audioCodec,
                'audio_channels'        => $audioChannels,
                'transcode_video_codec' => ($playType === 'transcode') ? (string)($transInfo['VideoCodec'] ?? '') : '',
                'transcode_speed'       => 0,
                'thumb_url'             => $thumbUrl,
                'media_type'            => strtolower((string)($nowPlaying['Type'] ?? 'video')),
                'summary'               => (string)($nowPlaying['Overview']      ?? ''),
            ]);
        }

        return ['ok' => true, 'sessions' => $sessions];
    }

    private function normalizeJfPlayType(?array $transInfo): string
    {
        if ($transInfo === null) return 'direct_play';
        if (!($transInfo['IsVideoCopy'] ?? true)) return 'transcode';
        return 'direct_stream';
    }

    // ══════════════════════════════════════════════════════════════════════
    // Emby (reuses Jellyfin logic, retags server_type)
    // ══════════════════════════════════════════════════════════════════════

    private function fetchEmbySession(array $srv): array
    {
        $result = $this->fetchJellyfinSessions($srv);
        foreach ($result['sessions'] as &$s) $s['server_type'] = 'emby';
        unset($s);
        return $result;
    }

    // ══════════════════════════════════════════════════════════════════════
    // Title builders
    // ══════════════════════════════════════════════════════════════════════

    private function buildTitle(array $item): string
    {
        $type  = strtolower((string)($item['type']  ?? 'video'));
        $title = (string)($item['title'] ?? '');

        if ($type === 'episode') {
            $show = (string)($item['grandparentTitle'] ?? '');
            if ($show !== '') {
                $s = str_pad((string)((int)($item['parentIndex'] ?? 0)), 2, '0', STR_PAD_LEFT);
                $e = str_pad((string)((int)($item['index']       ?? 0)), 2, '0', STR_PAD_LEFT);
                return trim("{$show} - S{$s}E{$e}" . ($title !== '' ? " - {$title}" : ''));
            }
        }
        if ($type === 'track') {
            $artist = (string)($item['grandparentTitle'] ?? '');
            return $artist !== '' ? "{$artist} — {$title}" : $title;
        }
        return $title;
    }

    private function buildJfTitle(array $item): string
    {
        $type  = strtolower((string)($item['Type'] ?? 'video'));
        $title = (string)($item['Name']            ?? '');

        if ($type === 'episode') {
            $show = (string)($item['SeriesName'] ?? '');
            if ($show !== '') {
                $s = str_pad((string)((int)($item['ParentIndexNumber'] ?? 0)), 2, '0', STR_PAD_LEFT);
                $e = str_pad((string)((int)($item['IndexNumber']       ?? 0)), 2, '0', STR_PAD_LEFT);
                return trim("{$show} - S{$s}E{$e}" . ($title !== '' ? " - {$title}" : ''));
            }
        }
        if ($type === 'audio') {
            $artist = (string)($item['AlbumArtist'] ?? '');
            return $artist !== '' ? "{$artist} — {$title}" : $title;
        }
        return $title;
    }

    // ══════════════════════════════════════════════════════════════════════
    // Session normalizer — canonical shape + sanitization
    // ══════════════════════════════════════════════════════════════════════

    private function normalizeSession(array $raw): array
    {
        $ipRaw = (string)($raw['ip_address'] ?? '');
        $ip    = (strpos($ipRaw, ':') !== false && substr_count($ipRaw, ':') === 1)
            ? explode(':', $ipRaw)[0] : $ipRaw;

        $progressMs  = max(0, (int)($raw['progress_ms'] ?? 0));
        $durationMs  = max(0, (int)($raw['duration_ms'] ?? 0));
        $progressPct = ($durationMs > 0) ? round(($progressMs / $durationMs) * 100, 1) : 0.0;

        return [
            'server_name'           => $this->sanitizeStr($raw['server_name']           ?? ''),
            'server_type'           => $this->sanitizeStr($raw['server_type']           ?? ''),
            'session_id'            => $this->sanitizeStr($raw['session_id']            ?? ''),
            'session_key'           => $this->sanitizeStr($raw['session_key']           ?? ''),
            'plex_session_uuid'     => $this->sanitizeStr($raw['plex_session_uuid']     ?? ''),
            'title'                 => $this->sanitizeStr($raw['title']                 ?? 'Unknown'),
            'media_type'            => $this->sanitizeStr($raw['media_type']            ?? 'video'),
            'user'                  => $this->sanitizeStr($raw['user']                  ?? 'Unknown'),
            'device'                => $this->sanitizeStr($raw['device']                ?? 'Unknown'),
            'client'                => $this->sanitizeStr($raw['client']                ?? ''),
            'platform'              => $this->sanitizeStr($raw['platform']              ?? ''),
            'ip_address'            => $this->sanitizeStr($ip),
            'state'                 => $this->sanitizeStr($raw['state']                 ?? 'playing'),
            'play_type'             => $this->sanitizeStr($raw['play_type']             ?? 'direct_play'),
            'progress_ms'           => $progressMs,
            'duration_ms'           => $durationMs,
            'progress_pct'          => $progressPct,
            'quality'               => $this->sanitizeStr($raw['quality']               ?? ''),
            'bitrate'               => (int)($raw['bitrate']                            ?? 0),
            'bandwidth_kbps'        => (int)($raw['bandwidth_kbps']                     ?? 0),
            'container'             => $this->sanitizeStr($raw['container']             ?? ''),
            'video_codec'           => $this->sanitizeStr($raw['video_codec']           ?? ''),
            'audio_codec'           => $this->sanitizeStr($raw['audio_codec']           ?? ''),
            'audio_channels'        => (int)($raw['audio_channels']                     ?? 0),
            'transcode_video_codec' => $this->sanitizeStr($raw['transcode_video_codec'] ?? ''),
            'transcode_speed'       => (float)($raw['transcode_speed']                  ?? 0),
            'thumb_url'             => $this->sanitizeStr($raw['thumb_url']             ?? ''),
            'summary'               => $this->sanitizeStr($raw['summary']               ?? ''),
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // Action: get_thumb (same-origin image proxy)
    // ══════════════════════════════════════════════════════════════════════

    private function replyGetThumb(): void
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $host    = $_SERVER['HTTP_HOST']    ?? '';
        if ($host !== '' && $referer !== '' && strpos($referer, $host) === false) {
            http_response_code(403); exit('Forbidden');
        }

        $url = trim((string)($_GET['u'] ?? ''));
        if ($url === '') { http_response_code(400); exit('No URL'); }

        $allowed = false;
        $cfg = @parse_plugin_cfg('streamviewer', true) ?: [];
        for ($i = 1; $i <= self::MAX_SERVERS; $i++) {
            $srvUrl = rtrim(trim((string)($cfg["SERVER{$i}_URL"] ?? '')), '/');
            if ($srvUrl !== '' && strpos($url, $srvUrl) === 0) { $allowed = true; break; }
        }
        if (!$allowed && preg_match('#^https?://[a-z0-9-]+\.plex\.direct[:/]#i', $url)) {
            $allowed = true;
        }
        if (!$allowed) { http_response_code(403); exit('URL not allowed'); }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'StreamViewer/1.0 Unraid',
        ]);
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime   = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'image/jpeg');
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            http_response_code(502); exit('Proxy error');
        }

        $mime = strtok($mime, ';');
        if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'], true)) {
            $mime = 'image/jpeg';
        }
        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        echo $body;
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════
    // Action: get_servers
    // ══════════════════════════════════════════════════════════════════════

    private function replyGetServers(): void
    {
        $cfg     = $this->loadCfg();
        $servers = [];

        for ($i = 1; $i <= self::MAX_SERVERS; $i++) {
            $type  = (string)($cfg["SERVER{$i}_TYPE"]  ?? '');
            $url   = trim((string)($cfg["SERVER{$i}_URL"]  ?? ''));
            $token = trim((string)($cfg["SERVER{$i}_TOKEN"] ?? ''));
            $name  = trim((string)($cfg["SERVER{$i}_NAME"]  ?? ''));

            if ($type === '' && $url === '' && $name === '') continue;

            $servers[] = [
                'index'     => $i,
                'type'      => $type,
                'name'      => $name !== '' ? $name : "Server {$i}",
                'url'       => $url,
                'enabled'   => ($cfg["SERVER{$i}_ENABLED"] ?? '0') === '1',
                'has_token' => $token !== '',
            ];
        }

        $this->json(['servers' => $servers, 'valid_types' => self::VALID_TYPES]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Action: test_connection
    // ══════════════════════════════════════════════════════════════════════

    private function replyTestConnection(): void
    {
        $index = (int)($_GET['server'] ?? 0);
        if ($index < 1 || $index > self::MAX_SERVERS) $this->json(['error' => 'Invalid server index'], 400);

        $cfg   = $this->loadCfg();
        $type  = (string)($cfg["SERVER{$index}_TYPE"]  ?? '');
        $url   = rtrim(trim((string)($cfg["SERVER{$index}_URL"]   ?? '')), '/');
        $token = trim((string)($cfg["SERVER{$index}_TOKEN"] ?? ''));
        $name  = trim((string)($cfg["SERVER{$index}_NAME"]  ?? "Server {$index}"));

        if (!in_array($type, self::VALID_TYPES, true)) $this->json(['ok' => false, 'error' => 'Invalid or missing server type']);
        if ($url   === '') $this->json(['ok' => false, 'error' => 'No URL configured']);
        if ($token === '') $this->json(['ok' => false, 'error' => 'No token/API key configured']);

        [$testUrl, $headers] = match($type) {
            'plex'             => [$url . '/', ['X-Plex-Token' => $token, 'Accept' => 'application/json']],
            'jellyfin', 'emby' => [$url . '/System/Info/Public', ['Accept' => 'application/json']],
            default            => [$url, []],
        };

        [$body, $httpCode, $err] = $this->httpGet($testUrl, $headers);

        if ($err !== null) $this->json(['ok' => false, 'server' => $name, 'error' => $err]);
        if ($httpCode === 401) $this->json(['ok' => false, 'server' => $name, 'error' => 'Authentication failed — check your token']);

        if ($httpCode >= 200 && $httpCode < 300) {
            $version = '';
            $data    = @json_decode($body, true);
            if (is_array($data)) $version = (string)($data['version'] ?? $data['Version'] ?? '');
            if ($type === 'plex' && $version === '' && str_contains((string)$body, 'MediaContainer')) {
                if (preg_match('/version="([^"]+)"/', (string)$body, $m)) $version = $m[1];
            }
            $this->json(['ok' => true, 'server' => $name, 'type' => $type, 'version' => $version, 'http' => $httpCode]);
        }

        $this->json(['ok' => false, 'server' => $name, 'error' => "HTTP {$httpCode}"]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Action: kill_session
    // ══════════════════════════════════════════════════════════════════════

    private function replyKillSession(): void
    {
        if ((string)($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->json(['error' => 'Method not allowed'], 405);
        }

        $serverIndex = (int)($_POST['server_index']      ?? 0);
        $sessionId   = trim((string)($_POST['session_id']     ?? ''));
        $sessionKey  = trim((string)($_POST['session_key']    ?? ''));
        $plexUuid    = trim((string)($_POST['plex_session_uuid'] ?? ''));
        $reason      = substr(strip_tags(trim((string)($_POST['reason'] ?? 'Stream terminated by administrator'))), 0, 200);

        if ($serverIndex < 1 || $serverIndex > self::MAX_SERVERS) $this->json(['error' => 'Invalid server'], 400);
        if ($sessionId === '' && $sessionKey === '') $this->json(['error' => 'Missing session identifier'], 400);

        $cfg   = $this->loadCfg();
        $type  = (string)($cfg["SERVER{$serverIndex}_TYPE"]  ?? '');
        $url   = rtrim(trim((string)($cfg["SERVER{$serverIndex}_URL"]   ?? '')), '/');
        $token = trim((string)($cfg["SERVER{$serverIndex}_TOKEN"] ?? ''));

        if (!in_array($type, self::VALID_TYPES, true) || $url === '' || $token === '') {
            $this->json(['error' => 'Server not properly configured'], 400);
        }

        $result = match($type) {
            'plex'             => $this->killPlexSession($url, $token, $sessionKey, $plexUuid, $reason),
            'jellyfin', 'emby' => $this->killJfSession($url, $token, $sessionId, $reason),
            default            => ['ok' => false, 'error' => 'Unsupported'],
        };
        $this->json($result);
    }

    private function killPlexSession(string $url, string $token, string $sessionKey, string $sessionUuid, string $reason): array
    {
        if ($sessionUuid === '' && $sessionKey !== '') {
            [$sb, , $se] = $this->httpGet($url . '/status/sessions', ['X-Plex-Token' => $token, 'Accept' => 'application/json']);
            if (!$se && $sb) {
                foreach (@json_decode($sb, true)['MediaContainer']['Metadata'] ?? [] as $item) {
                    if ((string)($item['sessionKey'] ?? '') === $sessionKey) {
                        $sessionUuid = (string)($item['Session']['id'] ?? '');
                        break;
                    }
                }
            }
        }

        $terminateId = $sessionUuid !== '' ? $sessionUuid : $sessionKey;
        if ($terminateId === '') return ['ok' => false, 'error' => 'No session identifier'];

        $killUrl = $url . '/status/sessions/terminate?' . http_build_query([
            'sessionId' => $terminateId, 'reason' => $reason,
        ]);
        [$body, $httpCode, $err] = $this->httpGet($killUrl, ['X-Plex-Token' => $token, 'Accept' => 'application/json']);

        if ($err !== null) return ['ok' => false, 'error' => $err];
        if ($httpCode >= 200 && $httpCode < 300) return ['ok' => true];
        return ['ok' => false, 'error' => "HTTP {$httpCode}"];
    }

    private function killJfSession(string $url, string $token, string $sessionId, string $reason): array
    {
        if ($sessionId === '') return ['ok' => false, 'error' => 'No session ID provided'];

        $stopUrl = $url . '/Sessions/' . rawurlencode($sessionId) . '/Playing/Stop';
        [$body, $httpCode, $err] = $this->httpPost($stopUrl, [], [
            'X-Emby-Token'         => $token,
            'X-MediaBrowser-Token' => $token,
        ]);
        if ($err !== null) return ['ok' => false, 'error' => $err];
        if ($httpCode >= 200 && $httpCode < 300) return ['ok' => true];
        return ['ok' => false, 'error' => "HTTP {$httpCode}"];
    }

    // ══════════════════════════════════════════════════════════════════════
    // Plex OAuth PIN flow
    // ══════════════════════════════════════════════════════════════════════

    private function plexHeaders(string $token = ''): array
    {
        $h = [
            'Accept'                   => 'application/json',
            'X-Plex-Product'           => self::PLEX_PRODUCT,
            'X-Plex-Version'           => '1.0',
            'X-Plex-Client-Identifier' => self::PLEX_CLIENT_ID,
            'X-Plex-Platform'          => 'Web',
            'X-Plex-Device-Name'       => 'Stream Viewer',
        ];
        if ($token !== '') $h['X-Plex-Token'] = $token;
        return $h;
    }

    private function replyPlexCreatePin(): void
    {
        $ch = curl_init('https://plex.tv/api/v2/pins');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'strong=true',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $this->buildCurlHeaders($this->plexHeaders()),
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch) ?: null;
        curl_close($ch);

        if ($err || $body === false) {
            $this->json(['ok' => false, 'error' => 'Cannot reach plex.tv: ' . ($err ?? 'empty')], 502);
        }

        $data = @json_decode($body, true);
        if (!is_array($data) || empty($data['id']) || empty($data['code'])) {
            $this->json(['ok' => false, 'error' => "Bad PIN response from plex.tv (HTTP {$code})"], 502);
        }

        $forwardUrl = trim((string)($_GET['forward_url'] ?? ''));
        if (!preg_match('#^https?://#i', $forwardUrl) || stripos($forwardUrl, 'plex.tv') !== false) {
            $forwardUrl = 'https://unraid.net/';
        }

        $authUrl = 'https://app.plex.tv/auth/#?'
            . 'clientID='    . rawurlencode(self::PLEX_CLIENT_ID)
            . '&code='       . rawurlencode((string)$data['code'])
            . '&forwardUrl=' . rawurlencode($forwardUrl)
            . '&context%5Bdevice%5D%5Bproduct%5D=' . rawurlencode(self::PLEX_PRODUCT);

        @mkdir(self::CACHE_DIR, 0700, true);
        @file_put_contents(self::CACHE_DIR . '/plex_pin_' . (int)$data['id'], (string)$data['code'], LOCK_EX);

        $this->json(['ok' => true, 'pin_id' => (int)$data['id'], 'auth_url' => $authUrl]);
    }

    private function replyPlexPollPin(): void
    {
        $pinId = (int)($_GET['pin_id'] ?? 0);
        if ($pinId <= 0) $this->json(['ok' => false, 'error' => 'Missing pin_id'], 400);

        $pinCode = trim((string)@file_get_contents(self::CACHE_DIR . '/plex_pin_' . $pinId));
        $pollUrl = 'https://plex.tv/api/v2/pins/' . $pinId;
        if ($pinCode !== '') $pollUrl .= '?code=' . rawurlencode($pinCode);

        [$body, $code, $err] = $this->httpGet($pollUrl, $this->plexHeaders());
        if ($err) $this->json(['ok' => false, 'error' => 'plex.tv poll failed: ' . $err], 502);

        $data = @json_decode($body, true);
        if (!is_array($data)) $this->json(['ok' => false, 'error' => "Bad poll response (HTTP {$code})"], 502);
        if (empty($data['authToken'])) $this->json(['ok' => true, 'ready' => false]);

        @unlink(self::CACHE_DIR . '/plex_pin_' . $pinId);
        $servers = $this->plexDiscover((string)$data['authToken']);
        $this->json(['ok' => true, 'ready' => true, 'servers' => $servers]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Plex discovery & URL auto-rediscover
    // ══════════════════════════════════════════════════════════════════════

    private function plexRediscoverUrl(array $srv): string
    {
        $token = $srv['token'] ?? '';
        $name  = $srv['name']  ?? '';
        if ($token === '') return '';

        foreach ($this->plexDiscover($token) as $s) {
            if (trim($s['name']) !== trim($name)) continue;
            $conns = array_filter($s['connections'], fn($c) => !$c['relay']);
            usort($conns, fn($a, $b) => (int)$a['local'] - (int)$b['local']);
            foreach ($conns as $conn) {
                if (!empty($conn['uri'])) return rtrim($conn['uri'], '/');
            }
        }
        return '';
    }

    private function plexDiscover(string $accountToken): array
    {
        [$body, , $err] = $this->httpGet(
            'https://plex.tv/api/v2/resources?includeHttps=1&includeRelay=1&includeIPv6=1',
            $this->plexHeaders($accountToken)
        );
        if ($err || !$body) return [];

        $resources = @json_decode($body, true);
        if (!is_array($resources)) return [];

        $servers = [];
        foreach ($resources as $r) {
            $provides = array_map('trim', explode(',', (string)($r['provides'] ?? '')));
            if (!in_array('server', $provides, true)) continue;

            $conns = [];
            foreach ((array)($r['connections'] ?? []) as $c) {
                if (empty($c['uri'])) continue;
                $conns[] = [
                    'uri'     => (string)$c['uri'],
                    'address' => (string)($c['address'] ?? ''),
                    'port'    => (int)($c['port']       ?? 0),
                    'local'   => (bool)($c['local']     ?? false),
                    'relay'   => (bool)($c['relay']     ?? false),
                ];
            }
            if (!$conns) continue;

            $servers[] = [
                'name'        => (string)($r['name']        ?? 'Plex Server'),
                'owned'       => (bool)($r['owned']         ?? false),
                'accessToken' => (string)($r['accessToken'] ?? ''),
                'connections' => $conns,
            ];
        }
        return $servers;
    }

    private function updateServerUrl(int $index, string $newUrl): bool
    {
        $raw = @file_get_contents(self::CFG_FILE);
        if ($raw === false) return false;

        $key     = "SERVER{$index}_URL";
        $escaped = str_replace('"', '\"', rtrim(trim($newUrl), '/'));
        $pattern = '/^(' . preg_quote($key, '/') . ')=".*"$/m';
        $replace = $key . '="' . $escaped . '"';
        $new     = preg_match($pattern, $raw)
            ? preg_replace($pattern, $replace, $raw)
            : rtrim($raw) . "\n" . $replace . "\n";

        $tmp = self::CFG_FILE . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $new, LOCK_EX) === false) return false;
        return (bool)@rename($tmp, self::CFG_FILE);
    }

    // ══════════════════════════════════════════════════════════════════════
    // HTTP helpers (cURL, no shell)
    // ══════════════════════════════════════════════════════════════════════

    /** @return array{string|null, int, string|null} [body, httpCode, error] */
    private function httpGet(string $url, array $headers = []): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)
            || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return [null, 0, 'Invalid or disallowed URL'];
        }
        if (!function_exists('curl_init')) return [null, 0, 'cURL not available'];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => $this->buildCurlHeaders($headers),
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch) ?: null;
        curl_close($ch);
        return [$body === false ? null : $body, $code, $err];
    }

    private function httpPost(string $url, array $postData, array $headers = []): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return [null, 0, 'Invalid URL'];
        if (!function_exists('curl_init')) return [null, 0, 'cURL not available'];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => $this->buildCurlHeaders($headers),
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch) ?: null;
        curl_close($ch);
        return [$body === false ? null : $body, $code, $err];
    }

    private function buildCurlHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) $out[] = "{$k}: {$v}";
        return $out;
    }

    // ══════════════════════════════════════════════════════════════════════
    // Response helpers
    // ══════════════════════════════════════════════════════════════════════

    private function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'none'");
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    }

    private function json($payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        $this->securityHeaders();
        echo json_encode($payload);
        exit;
    }

    private function rawJson(string $json, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        $this->securityHeaders();
        echo $json;
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════
    // Micro-cache
    // ══════════════════════════════════════════════════════════════════════

    private function sessionsCacheKey(array $cfg): string
    {
        $parts = [];
        for ($i = 1; $i <= self::MAX_SERVERS; $i++) {
            $parts[] = (string)($cfg["SERVER{$i}_ENABLED"] ?? '0');
            $parts[] = (string)($cfg["SERVER{$i}_URL"]     ?? '');
            $parts[] = (string)($cfg["SERVER{$i}_TOKEN"]   ?? '');
        }
        return hash('sha256', implode('|', $parts));
    }

    private function cacheGet(string $path, int $maxAgeMs): ?string
    {
        $st = @stat($path);
        if (!is_array($st) || !isset($st['mtime'])) return null;
        if ((int)round((microtime(true) - (float)$st['mtime']) * 1000) > $maxAgeMs) return null;
        $data = @file_get_contents($path);
        return ($data === false || $data === '') ? null : $data;
    }

    private function cachePut(string $path, string $json): void
    {
        if (!is_dir(self::CACHE_DIR)) @mkdir(self::CACHE_DIR, 0700, true);
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, $path);
        } else {
            @unlink($tmp);
        }
        // 1% chance: prune stale session caches older than 30 s
        if (mt_rand(1, 100) !== 1) return;
        $files = @glob(self::CACHE_DIR . '/sess_*.json');
        if (!is_array($files)) return;
        $now = time();
        foreach ($files as $f) {
            $st = @stat($f);
            if (is_array($st) && isset($st['mtime']) && ($now - (int)$st['mtime']) > 30) @unlink($f);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Utilities
    // ══════════════════════════════════════════════════════════════════════

    private function sanitizeStr(string $s): string
    {
        return htmlspecialchars(trim($s), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    (new StreamViewerEndpoint())->run();
}
