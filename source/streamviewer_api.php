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
    private const NONCE_TTL            = 14400;  // 4 hours (sliding: renewed on each valid request)
    private const RATE_LIMIT_FILE      = '/tmp/streamviewer_cache/rl';
    private const RATE_LIMIT_MAX       = 120;    // requests per minute per IP
    private const MICRO_CACHE_MS       = 2000;   // deduplicate rapid/parallel widget refreshes

    // ── HTTP ───────────────────────────────────────────────────────────────
    private const HTTP_TIMEOUT         = 7;
    private const HTTP_CONNECT_TIMEOUT = 4;
    private const THUMB_MAX_BYTES      = 5 * 1024 * 1024;  // 5 MB cap for proxied thumbnails

    // ── Rediscover ──────────────────────────────────────────────────────
    private const REDISCOVER_AFTER     = 3;       // consecutive failures before rediscover
    private const DOCKER_SOCKET        = '/var/run/docker.sock';

    // ── Stats / History ─────────────────────────────────────────────────
    private const STATS_DB_NAME        = 'streamviewer.db';
    private const STATS_DEFAULT_PATH   = '/mnt/user/appdata/Stream-Viewer';
    private const STATS_RETENTION_DAYS = 90;
    private const STATS_PRUNE_CHANCE   = 50;    // 1-in-N requests triggers prune
    private const STATS_SCHEMA_VER     = 3;
    private const STATS_BUSY_TIMEOUT   = 3000;  // ms -- SQLite WAL busy wait
    private const LIBRARY_CACHE_TTL    = 300;   // seconds -- 5 min cache for library data

    // ── Runtime state ───────────────────────────────────────────────────
    private bool $verifySsl = false;
    private ?array $cfgCache = null;
    private ?\SQLite3 $db = null;

    public function __construct()
    {
        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0700, true);
        }
        $this->verifySsl = (($this->loadCfg()['VERIFY_SSL'] ?? '0') === '1');
    }

    // ── Cron: headless session recording ──────────────────────────────────

    /**
     * Poll all enabled media servers and record active sessions to SQLite.
     * Called from streamviewer_cron.php (no HTTP context needed).
     * Returns the number of active sessions found (0 if stats disabled).
     */
    public function cronPoll(): int
    {
        $cfg = $this->loadCfg();

        // Bail out immediately if statistics are disabled
        if (($cfg['STATS_ENABLED'] ?? '0') !== '1') return 0;

        $servers = $this->getEnabledServers($cfg);
        if (empty($servers)) return 0;

        $results  = $this->fetchAllSessionsParallel($servers);
        $sessions = [];
        foreach ($results as $result) {
            foreach ($result['sessions'] ?? [] as $s) $sessions[] = $s;
        }

        $this->recordSessions($sessions);

        return count($sessions);
    }

    // ── Config (cached per request) ─────────────────────────────────────

    private function loadCfg(): array
    {
        if ($this->cfgCache !== null) return $this->cfgCache;
        $cfg = @parse_plugin_cfg(self::PLUGIN_NAME, true);
        $this->cfgCache = is_array($cfg) ? $cfg : [];
        return $this->cfgCache;
    }

    // ══════════════════════════════════════════════════════════════════════
    // Stats -- SQLite database layer
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Validate and return the configured stats DB directory.
     * Whitelist: input path must start with /mnt/ (covers /mnt/user/, /mnt/cache/, /mnt/disk*, custom pools).
     * realpath is not used for validation because Unraid's FUSE layer can resolve
     * /mnt/user/ to /mnt/disk1/ or /mnt/cache/ or custom pool paths.
     */
    private function statsDbDir(): ?string
    {
        $cfg  = $this->loadCfg();
        if (($cfg['STATS_ENABLED'] ?? '0') !== '1') return null;

        $dir = trim((string)($cfg['STATS_DB_PATH'] ?? ''));
        if ($dir === '') $dir = self::STATS_DEFAULT_PATH;

        // Security: input path must be under /mnt/ and contain no traversal
        if (strncmp($dir, '/mnt/', 5) !== 0) return null;
        if (strpos($dir, '..') !== false) return null;

        // Create directory if it doesn't exist
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
            if (!is_dir($dir)) return null;
        }

        return $dir;
    }

    /**
     * Open (or create) the SQLite database.  WAL mode, prepared-statements only.
     */
    private function openDb(): ?\SQLite3
    {
        if ($this->db !== null) return $this->db;

        $dir = $this->statsDbDir();
        if ($dir === null) return null;

        $dbFile = $dir . '/' . self::STATS_DB_NAME;
        $isNew  = !is_file($dbFile);

        try {
            $db = new \SQLite3($dbFile);
        } catch (\Exception $e) {
            return null;
        }

        $db->busyTimeout(self::STATS_BUSY_TIMEOUT);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA synchronous  = NORMAL');
        $db->exec('PRAGMA foreign_keys = OFF');

        // Set secure permissions (owner-only read/write)
        @chmod($dbFile, 0600);

        if ($isNew) {
            $this->statsCreateBaseTables($db);
        }

        $this->runMigrations($db);

        $this->db = $db;
        return $db;
    }

    private function getSchemaVersion(\SQLite3 $db): int
    {
        // sv_meta table might not exist yet
        $r = @$db->querySingle("SELECT val FROM sv_meta WHERE key='schema_version'");
        return (int)$r;
    }

    private function setSchemaVersion(\SQLite3 $db, int $ver): void
    {
        $stmt = $db->prepare("INSERT OR REPLACE INTO sv_meta(key,val) VALUES('schema_version', :ver)");
        $stmt->bindValue(':ver', (string)$ver, SQLITE3_TEXT);
        $stmt->execute();
    }

    /**
     * Create all base tables (v1). Idempotent: uses CREATE TABLE IF NOT EXISTS.
     * Called only on first run (new DB file).
     */
    private function statsCreateBaseTables(\SQLite3 $db): void
    {
        $db->exec('BEGIN EXCLUSIVE');
        $db->exec("
            CREATE TABLE IF NOT EXISTS active_sessions (
                session_key   TEXT PRIMARY KEY,
                session_id    TEXT NOT NULL DEFAULT '',
                user          TEXT NOT NULL DEFAULT 'Unknown',
                title         TEXT NOT NULL DEFAULT '',
                media_type    TEXT NOT NULL DEFAULT 'video',
                server_name   TEXT NOT NULL DEFAULT '',
                server_type   TEXT NOT NULL DEFAULT '',
                play_type     TEXT NOT NULL DEFAULT 'direct_play',
                ip_address    TEXT NOT NULL DEFAULT '',
                device        TEXT NOT NULL DEFAULT '',
                quality       TEXT NOT NULL DEFAULT '',
                bandwidth_kbps INTEGER NOT NULL DEFAULT 0,
                duration_ms   INTEGER NOT NULL DEFAULT 0,
                progress_ms   INTEGER NOT NULL DEFAULT 0,
                first_seen    INTEGER NOT NULL DEFAULT 0,
                last_seen     INTEGER NOT NULL DEFAULT 0
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS watch_history (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                session_key  TEXT NOT NULL,
                user         TEXT NOT NULL DEFAULT 'Unknown',
                title        TEXT NOT NULL DEFAULT '',
                media_type   TEXT NOT NULL DEFAULT 'video',
                server_name  TEXT NOT NULL DEFAULT '',
                server_type  TEXT NOT NULL DEFAULT '',
                play_type    TEXT NOT NULL DEFAULT 'direct_play',
                ip_address   TEXT NOT NULL DEFAULT '',
                device       TEXT NOT NULL DEFAULT '',
                quality      TEXT NOT NULL DEFAULT '',
                bandwidth_kbps INTEGER NOT NULL DEFAULT 0,
                duration_sec INTEGER NOT NULL DEFAULT 0,
                started_at   INTEGER NOT NULL DEFAULT 0,
                ended_at     INTEGER NOT NULL DEFAULT 0
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_wh_ended   ON watch_history(ended_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_wh_user    ON watch_history(user)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_wh_server  ON watch_history(server_type)");

        $db->exec("
            CREATE TABLE IF NOT EXISTS library_cache (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                server_index  INTEGER NOT NULL,
                server_name   TEXT NOT NULL DEFAULT '',
                server_type   TEXT NOT NULL DEFAULT '',
                library_id    TEXT NOT NULL DEFAULT '',
                library_name  TEXT NOT NULL DEFAULT '',
                library_type  TEXT NOT NULL DEFAULT '',
                total_items   INTEGER NOT NULL DEFAULT 0,
                episode_count INTEGER NOT NULL DEFAULT 0,
                synced_at     INTEGER NOT NULL DEFAULT 0,
                UNIQUE(server_index, library_id)
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS recently_added (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                server_index  INTEGER NOT NULL,
                server_name   TEXT NOT NULL DEFAULT '',
                server_type   TEXT NOT NULL DEFAULT '',
                title         TEXT NOT NULL DEFAULT '',
                media_type    TEXT NOT NULL DEFAULT '',
                library_name  TEXT NOT NULL DEFAULT '',
                added_at      INTEGER NOT NULL DEFAULT 0,
                synced_at     INTEGER NOT NULL DEFAULT 0
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS sv_meta (
                key TEXT PRIMARY KEY,
                val TEXT NOT NULL DEFAULT ''
            )
        ");

        $this->setSchemaVersion($db, 1);
        $db->exec('COMMIT');
    }

    /**
     * Incremental migration runner.
     * Each migration runs outside a transaction with @ to safely handle
     * re-runs (e.g. ALTER TABLE on a column that already exists).
     * Migrations are never skipped: they run in order from current version + 1.
     * Data is never deleted, only new columns/tables/indexes are added.
     */
    private function runMigrations(\SQLite3 $db): void
    {
        // Ensure sv_meta exists (handles pre-migration DBs)
        @$db->exec("CREATE TABLE IF NOT EXISTS sv_meta (key TEXT PRIMARY KEY, val TEXT NOT NULL DEFAULT '')");

        $currentVer = $this->getSchemaVersion($db);
        if ($currentVer >= self::STATS_SCHEMA_VER) return;

        $migrations = [
            // v1 -> v2: library cache + recently added (already in base tables, safe no-op)
            2 => function(\SQLite3 $db) {
                @$db->exec("
                    CREATE TABLE IF NOT EXISTS library_cache (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        server_index INTEGER NOT NULL, server_name TEXT NOT NULL DEFAULT '',
                        server_type TEXT NOT NULL DEFAULT '', library_id TEXT NOT NULL DEFAULT '',
                        library_name TEXT NOT NULL DEFAULT '', library_type TEXT NOT NULL DEFAULT '',
                        total_items INTEGER NOT NULL DEFAULT 0, episode_count INTEGER NOT NULL DEFAULT 0,
                        synced_at INTEGER NOT NULL DEFAULT 0, UNIQUE(server_index, library_id)
                    )
                ");
                @$db->exec("
                    CREATE TABLE IF NOT EXISTS recently_added (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        server_index INTEGER NOT NULL, server_name TEXT NOT NULL DEFAULT '',
                        server_type TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '',
                        media_type TEXT NOT NULL DEFAULT '', library_name TEXT NOT NULL DEFAULT '',
                        added_at INTEGER NOT NULL DEFAULT 0, synced_at INTEGER NOT NULL DEFAULT 0
                    )
                ");
            },

            // v2 -> v3: add type_label column for S03E04 display in recently added
            3 => function(\SQLite3 $db) {
                @$db->exec("ALTER TABLE recently_added ADD COLUMN type_label TEXT NOT NULL DEFAULT ''");
            },
        ];

        foreach ($migrations as $ver => $fn) {
            if ($currentVer < $ver) {
                try {
                    $fn($db);
                } catch (\Throwable $e) {
                    // Migration failed but continue: next run will retry
                }
                $this->setSchemaVersion($db, $ver);
            }
        }
    }

    // ── Recording hook -- called from replyGetSessions() ─────────────────

    /**
     * Record active sessions into SQLite. Sessions that disappear are
     * moved to watch_history.  Runs passively on every poll cycle.
     */
    private function recordSessions(array $sessions): void
    {
        $db = $this->openDb();
        if ($db === null) return;

        $now = time();
        $cfg = $this->loadCfg();
        $anonymize = (($cfg['STATS_ANONYMIZE_IP'] ?? '0') === '1');

        // Minimum duration to record (avoid partial/accidental plays)
        $minDurationSec = 30;

        $db->exec('BEGIN');

        // 1. Collect current session keys
        $currentKeys = [];
        foreach ($sessions as $s) {
            $key = $this->statsSessionKey($s);
            $currentKeys[$key] = true;

            $ip = $anonymize ? $this->anonymizeIp((string)($s['ip_address'] ?? ''))
                             : (string)($s['ip_address'] ?? '');

            // Upsert into active_sessions
            $stmt = $db->prepare("
                INSERT INTO active_sessions
                    (session_key, session_id, user, title, media_type, server_name, server_type,
                     play_type, ip_address, device, quality, bandwidth_kbps, duration_ms,
                     progress_ms, first_seen, last_seen)
                VALUES (:sk, :sid, :user, :title, :mt, :sn, :st, :pt, :ip, :dev,
                        :qual, :bw, :dur, :prog, :now, :now)
                ON CONFLICT(session_key) DO UPDATE SET
                    progress_ms = :prog,
                    duration_ms = :dur,
                    last_seen   = :now,
                    play_type   = :pt,
                    quality     = :qual,
                    bandwidth_kbps = :bw
            ");
            $stmt->bindValue(':sk',    $key,                                    SQLITE3_TEXT);
            $stmt->bindValue(':sid',   (string)($s['session_id'] ?? ''),        SQLITE3_TEXT);
            $stmt->bindValue(':user',  (string)($s['user'] ?? 'Unknown'),       SQLITE3_TEXT);
            $stmt->bindValue(':title', (string)($s['title'] ?? ''),             SQLITE3_TEXT);
            $stmt->bindValue(':mt',    (string)($s['media_type'] ?? 'video'),   SQLITE3_TEXT);
            $stmt->bindValue(':sn',    (string)($s['server_name'] ?? ''),       SQLITE3_TEXT);
            $stmt->bindValue(':st',    (string)($s['server_type'] ?? ''),       SQLITE3_TEXT);
            $stmt->bindValue(':pt',    (string)($s['play_type'] ?? 'direct_play'), SQLITE3_TEXT);
            $stmt->bindValue(':ip',    $ip,                                     SQLITE3_TEXT);
            $stmt->bindValue(':dev',   (string)($s['device'] ?? ''),            SQLITE3_TEXT);
            $stmt->bindValue(':qual',  (string)($s['quality'] ?? ''),           SQLITE3_TEXT);
            $stmt->bindValue(':bw',    (int)($s['bandwidth_kbps'] ?? 0),        SQLITE3_INTEGER);
            $stmt->bindValue(':dur',   (int)($s['duration_ms'] ?? 0),           SQLITE3_INTEGER);
            $stmt->bindValue(':prog',  (int)($s['progress_ms'] ?? 0),           SQLITE3_INTEGER);
            $stmt->bindValue(':now',   $now,                                    SQLITE3_INTEGER);
            $stmt->execute();
            $stmt->close();
        }

        // 2. Find ended sessions (in active_sessions but not in current poll)
        $ended = $db->query("SELECT * FROM active_sessions");
        $moveStmt = $db->prepare("
            INSERT INTO watch_history
                (session_key, user, title, media_type, server_name, server_type,
                 play_type, ip_address, device, quality, bandwidth_kbps,
                 duration_sec, started_at, ended_at)
            VALUES (:sk, :user, :title, :mt, :sn, :st, :pt, :ip, :dev,
                    :qual, :bw, :dur, :start, :end)
        ");
        $delStmt = $db->prepare("DELETE FROM active_sessions WHERE session_key = :sk");

        while ($row = $ended->fetchArray(SQLITE3_ASSOC)) {
            if (isset($currentKeys[$row['session_key']])) continue;

            $durationSec = max(0, (int)$row['last_seen'] - (int)$row['first_seen']);
            if ($durationSec < $minDurationSec) {
                // Too short -- just delete, don't record
                $delStmt->bindValue(':sk', $row['session_key'], SQLITE3_TEXT);
                $delStmt->execute();
                $delStmt->reset();
                continue;
            }

            $moveStmt->bindValue(':sk',    $row['session_key'],  SQLITE3_TEXT);
            $moveStmt->bindValue(':user',  $row['user'],         SQLITE3_TEXT);
            $moveStmt->bindValue(':title', $row['title'],        SQLITE3_TEXT);
            $moveStmt->bindValue(':mt',    $row['media_type'],   SQLITE3_TEXT);
            $moveStmt->bindValue(':sn',    $row['server_name'],  SQLITE3_TEXT);
            $moveStmt->bindValue(':st',    $row['server_type'],  SQLITE3_TEXT);
            $moveStmt->bindValue(':pt',    $row['play_type'],    SQLITE3_TEXT);
            $moveStmt->bindValue(':ip',    $row['ip_address'],   SQLITE3_TEXT);
            $moveStmt->bindValue(':dev',   $row['device'],       SQLITE3_TEXT);
            $moveStmt->bindValue(':qual',  $row['quality'],      SQLITE3_TEXT);
            $moveStmt->bindValue(':bw',    (int)$row['bandwidth_kbps'], SQLITE3_INTEGER);
            $moveStmt->bindValue(':dur',   $durationSec,         SQLITE3_INTEGER);
            $moveStmt->bindValue(':start', (int)$row['first_seen'], SQLITE3_INTEGER);
            $moveStmt->bindValue(':end',   (int)$row['last_seen'],  SQLITE3_INTEGER);
            $moveStmt->execute();
            $moveStmt->reset();

            $delStmt->bindValue(':sk', $row['session_key'], SQLITE3_TEXT);
            $delStmt->execute();
            $delStmt->reset();
        }
        $moveStmt->close();
        $delStmt->close();

        $db->exec('COMMIT');

        // 3. Probabilistic prune (1-in-N chance)
        if (mt_rand(1, self::STATS_PRUNE_CHANCE) === 1) {
            $this->statsPrune($db);
        }
    }

    /**
     * Build a unique key for a session. Combines server+sessionId so that
     * the same stream across poll cycles is recognized as one session.
     */
    private function statsSessionKey(array $s): string
    {
        $parts = [
            (string)($s['server_type'] ?? ''),
            (string)($s['server_name'] ?? ''),
            (string)($s['session_id']  ?? ''),
            (string)($s['session_key'] ?? ''),
            (string)($s['title']       ?? ''),
        ];
        return hash('sha256', implode('|', $parts));
    }

    private function anonymizeIp(string $ip): string
    {
        if ($ip === '' || $ip === 'Unknown') return $ip;
        // IPv4: zero out last octet.  IPv6: zero out last 80 bits
        if (strpos($ip, ':') !== false) {
            $parts = explode(':', $ip);
            $n = count($parts);
            for ($i = max(0, $n - 5); $i < $n; $i++) $parts[$i] = '0';
            return implode(':', $parts);
        }
        $parts = explode('.', $ip);
        if (count($parts) === 4) $parts[3] = '0';
        return implode('.', $parts);
    }

    /**
     * Check if an IP address is private/local (RFC1918 + loopback).
     */
    private function isPrivateIp(string $ip): bool
    {
        if ($ip === '' || $ip === 'Unknown') return true;
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return true;
        // Returns false for private/reserved IPs, so negate
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * Delete watch_history rows older than retention limit.
     */
    private function statsPrune(\SQLite3 $db): void
    {
        $cfg  = $this->loadCfg();
        $days = (int)($cfg['STATS_RETENTION_DAYS'] ?? self::STATS_RETENTION_DAYS);
        if ($days < 7)   $days = 7;
        if ($days > 365) $days = 365;

        $cutoff = time() - ($days * 86400);
        $stmt = $db->prepare("DELETE FROM watch_history WHERE ended_at < :cutoff AND ended_at > 0");
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->close();
    }

    // ── URL type detection ──────────────────────────────────────────────

    private function isLocalUrl(string $url): bool
    {
        $host = (string)(parse_url($url, PHP_URL_HOST) ?: '');
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1') return true;
        // RFC1918 private ranges
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool)(
                filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            );
        }
        // .local hostnames
        $lower = strtolower($host);
        return substr($lower, -6) === '.local';
    }

    // ── Per-server failure counter (file-based in /tmp) ─────────────────

    private function failFile(int $index): string
    {
        return self::CACHE_DIR . '/fail_' . $index;
    }

    private function getFailureCount(int $index): int
    {
        $f = $this->failFile($index);
        if (!is_file($f)) return 0;
        return (int)@file_get_contents($f);
    }

    private function incrementFailure(int $index): int
    {
        $count = $this->getFailureCount($index) + 1;
        @file_put_contents($this->failFile($index), (string)$count, LOCK_EX);
        return $count;
    }

    private function resetFailure(int $index): void
    {
        @unlink($this->failFile($index));
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

        // Sliding expiration: renew timestamp on every successful check
        // so the token stays alive as long as the widget is actively polling
        $data['ts'] = time();
        @file_put_contents(self::NONCE_FILE, json_encode($data), LOCK_EX);
    }

    /**
     * Read-only nonce verification (no sliding write).
     * Used by high-frequency endpoints (docker stats mini-poll) to avoid
     * race conditions with the main session fetch that does sliding writes.
     */
    private function verifyNonceReadOnly(): void
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

        // Docker stats: lightweight path, verify nonce read-only (no sliding write)
        // to avoid race conditions with concurrent session fetches
        if ($action === 'get_docker_stats') {
            $this->enforceAjaxGet();
            $this->verifyNonceReadOnly();
            $this->replyGetDockerStats();
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
            // Stats endpoints
            'get_stats'       => fn() => $this->replyGetStats(),
            'get_daily_chart' => fn() => $this->replyGetDailyChart(),
            'get_top_media'   => fn() => $this->replyGetTopMedia(),
            'get_top_users'   => fn() => $this->replyGetTopUsers(),
            'get_history'     => fn() => $this->replyGetHistory(),
            // Library endpoints
            'get_libraries'      => fn() => $this->replyGetLibraries(),
            'get_recently_added' => fn() => $this->replyGetRecentlyAdded(),
            'sync_libraries'     => fn() => $this->replySyncLibraries(),
            // User endpoints
            'get_user_stats'     => fn() => $this->replyGetUserStats(),
            // Graph endpoints
            'get_graph_data'     => fn() => $this->replyGetGraphData(),
            // Alert endpoints
            'get_alerts'         => fn() => $this->replyGetAlerts(),
        ];

        if (!isset($routes[$action])) $this->json(['error' => 'Invalid action'], 400);
        $routes[$action]();
    }

    // ── Server list builder ────────────────────────────────────────────

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

        $servers = $this->getEnabledServers($cfg);

        // Parallel fetch: all servers at once via curl_multi
        $results = $this->fetchAllSessionsParallel($servers);

        $sessions    = [];
        $serverStats = [];
        foreach ($results as $i => $result) {
            $srv = $servers[$i];
            $serverStats[] = [
                'name'            => $srv['name'],
                'type'            => $srv['type'],
                'status'          => $result['ok'] ? 'online' : 'error',
                'error'           => $result['error'] ?? null,
                'active_sessions' => count($result['sessions'] ?? []),
            ];
            foreach ($result['sessions'] ?? [] as $s) $sessions[] = $s;
        }

        // Docker container resource stats (cached 15s)
        // On first load (no cache), return empty to avoid ~7s delay from Docker stats API.
        // Next poll cycle will populate the cache.
        $dockerStats = [];
        $dockerCachePath = self::CACHE_DIR . '/docker_stats.json';
        $dockerCached = $this->cacheGet($dockerCachePath, 15000);
        if ($dockerCached !== null) {
            $dockerStats = @json_decode($dockerCached, true) ?: [];
        } elseif (count($sessions) > 0) {
            // Only fetch Docker stats when there are active streams
            try {
                $dockerStats = $this->getDockerStats($servers);
                @file_put_contents($dockerCachePath, json_encode($dockerStats), LOCK_EX);
            } catch (\Throwable $e) {}
        }

        $json = (string)json_encode([
            'sessions'       => $sessions,
            'servers'        => $serverStats,
            'docker_stats'   => $dockerStats,
            'total_sessions' => count($sessions),
            'timestamp'      => time(),
            'no_servers'     => empty($servers),
        ]);
        $this->cachePut($cachePath, $json);

        // Passive recording: log sessions to SQLite for stats (non-blocking)
        try { $this->recordSessions($sessions); } catch (\Throwable $e) { /* never block the widget */ }

        // Write live count for header indicator (works with or without stats)
        @file_put_contents(self::CACHE_DIR . '/header_count', (string)count($sessions));

        $this->rawJson($json);
    }

    // Fire all server session requests simultaneously, parse results per type
    private function fetchAllSessionsParallel(array $servers): array
    {
        if (empty($servers) || !function_exists('curl_multi_init')) {
            // Fallback: sequential
            $results = [];
            foreach ($servers as $srv) $results[] = $this->fetchSessions($srv);
            return $results;
        }

        $mh      = curl_multi_init();
        $handles = [];
        $verify  = $this->verifySsl;

        foreach ($servers as $i => $srv) {
            [$url, $hdrs] = $this->sessionEndpoint($srv);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_SSL_VERIFYPEER => $verify,
                CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
                CURLOPT_HTTPHEADER     => $this->buildCurlHeaders($hdrs),
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }

        // Execute all in parallel
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) curl_multi_select($mh, 1);
        } while ($active && $status === CURLM_OK);

        // Collect results
        $results = [];
        foreach ($handles as $i => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch) ?: null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            $srv   = $servers[$i];
            $index = (int)($srv['index'] ?? 0);

            // Success → reset failure counter, parse response
            if ($err === null && $code >= 200 && $code < 300) {
                if ($index > 0) $this->resetFailure($index);
                $results[$i] = $this->parseSessions($srv, $body ?: '');
                continue;
            }

            // Auth error → don't count as connection failure
            if ($code === 401) {
                $results[$i] = ['ok' => false, 'sessions' => [], 'error' => 'Invalid API key'];
                continue;
            }

            // Connection error → increment failure counter
            $failures = ($index > 0) ? $this->incrementFailure($index) : 999;

            // Below threshold → return error, let next poll retry
            if ($failures < self::REDISCOVER_AFTER) {
                $errMsg = $err ?? "HTTP {$code}";
                $results[$i] = ['ok' => false, 'sessions' => [], 'error' => $errMsg];
                continue;
            }

            // Threshold reached → try rediscover based on server type
            $newUrl = $this->tryRediscover($srv);
            if ($newUrl !== '' && $newUrl !== rtrim($srv['url'], '/') && $index > 0) {
                $this->updateServerUrl($index, $newUrl);
                $srv['url'] = $newUrl;
                // Retry once with new URL
                $results[$i] = $this->fetchSessions($srv);
                if (($results[$i]['ok'] ?? false) && $index > 0) {
                    $this->resetFailure($index);
                }
            } else {
                $errMsg = $err ?? "HTTP {$code}";
                $results[$i] = ['ok' => false, 'sessions' => [], 'error' => $errMsg];
            }
        }

        curl_multi_close($mh);
        return $results;
    }

    // Build session endpoint URL + headers per server type
    private function sessionEndpoint(array $srv): array
    {
        return match($srv['type']) {
            'plex' => [
                $srv['url'] . '/status/sessions',
                ['X-Plex-Token' => $srv['token'], 'Accept' => 'application/json'],
            ],
            default => [
                $srv['url'] . '/Sessions?ActiveWithinSeconds=960',
                ['X-Emby-Token' => $srv['token'], 'X-MediaBrowser-Token' => $srv['token'], 'Accept' => 'application/json'],
            ],
        };
    }

    // Route response body to the correct parser
    private function parseSessions(array $srv, string $body): array
    {
        return match($srv['type']) {
            'plex'     => $this->parsePlexBody($srv, $body),
            'jellyfin' => $this->parseJellyfinBody($srv, $body),
            'emby'     => $this->parseEmbyBody($srv, $body),
            default    => ['ok' => false, 'sessions' => [], 'error' => 'Unknown type'],
        };
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

        if ($err !== null) return ['ok' => false, 'sessions' => [], 'error' => $err];
        if ($httpCode === 401) return ['ok' => false, 'sessions' => [], 'error' => 'Invalid Plex token'];
        if ($httpCode !== 200) return ['ok' => false, 'sessions' => [], 'error' => "HTTP {$httpCode}"];

        return $this->parsePlexBody($srv, $body);
    }

    private function parsePlexBody(array $srv, string $body): array
    {
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
            $subS     = null;

            foreach ($stream['Stream'] ?? [] as $s) {
                if (($s['streamType'] ?? 0) == 1 && $videoS === null) $videoS = $s;
                if (($s['streamType'] ?? 0) == 2 && $audioS === null) $audioS = $s;
                if (($s['streamType'] ?? 0) == 3 && $subS   === null) $subS   = $s;
            }

            $playType  = $this->normalizePlexPlayType($item);
            $thumbPath = $item['grandparentThumb'] ?? $item['parentThumb'] ?? $item['thumb'] ?? null;
            $thumbUrl  = ($thumbPath !== null && $thumbPath !== '' && $srv['url'] !== '')
                ? rtrim($srv['url'], '/') . '/photo/:/transcode?width=600&height=900&minSize=1&url='
                  . urlencode($thumbPath) . '&X-Plex-Token=' . urlencode($srv['token'])
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
                'quality'               => $this->formatPlexQuality((string)($media['videoResolution'] ?? '')),
                'bitrate'               => (int)($media['bitrate']                        ?? 0),
                'container'             => (string)($media['container']                   ?? ''),
                'video_codec'           => (string)($videoS['codec']                      ?? ''),
                'bit_depth'             => (int)($videoS['bitDepth']                      ?? 0),
                'video_range'           => $this->detectPlexVideoRange($videoS),
                'audio_codec'           => (string)($audioS['codec']                      ?? ''),
                'audio_channels'        => (int)($audioS['channels']                      ?? 0),
                'audio_spatial'         => '',
                'subtitle_language'     => (string)($subS['language']                     ?? ''),
                'subtitle_codec'        => (string)($subS['codec']                        ?? ''),
                'transcode_video_codec' => ($playType === 'transcode') ? (string)($item['TranscodeSession']['videoCodec'] ?? '') : '',
                'transcode_speed'       => ($playType === 'transcode') ? (float)($item['TranscodeSession']['speed']       ?? 0) : 0,
                'hw_accel'              => '',
                'transcode_reasons'     => '',
                'transcode_buffer_pct'  => 0,
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

    private function formatPlexQuality(string $res): string
    {
        if ($res === '') return '';
        return $res;
    }

    private function detectPlexVideoRange(?array $videoStream): string
    {
        if ($videoStream === null) return '';
        // Dolby Vision check
        if (!empty($videoStream['DOVIPresent'])) return 'Dolby Vision';
        $colorTrc = strtolower((string)($videoStream['colorTrc'] ?? ''));
        if ($colorTrc === 'smpte2084')    return 'HDR10';
        if ($colorTrc === 'arib-std-b67') return 'HLG';
        // Fallback: check colorSpace for bt2020
        $colorSpace = strtolower((string)($videoStream['colorSpace'] ?? ''));
        if (strpos($colorSpace, 'bt2020') !== false) return 'HDR';
        return 'SDR';
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
        if ($httpCode === 401) return ['ok' => false, 'sessions' => [], 'error' => 'Invalid API key'];
        if ($httpCode !== 200) return ['ok' => false, 'sessions' => [], 'error' => "HTTP {$httpCode}"];

        return $this->parseJellyfinBody($srv, $body);
    }

    private function parseJellyfinBody(array $srv, string $body): array
    {
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
            $audioSpatial = $subLang = $subCodec = '';
            $bitrate = $audioChannels = $bitDepth = 0;
            $videoRange = '';

            $mediaSources = $nowPlaying['MediaSources'] ?? [];
            $streams = [];
            if (!empty($mediaSources)) {
                $src       = $mediaSources[0];
                $container = (string)($src['Container'] ?? '');
                $bitrate   = (int)(($src['Bitrate'] ?? 0) / 1000);
                $streams   = $src['MediaStreams'] ?? [];
            } else {
                $container = (string)($nowPlaying['Container'] ?? '');
                $streams   = $nowPlaying['MediaStreams'] ?? [];
            }

            foreach ($streams as $s) {
                $sType = $s['Type'] ?? '';
                if ($sType === 'Video' && $videoCodec === '') {
                    $videoCodec = (string)($s['Codec'] ?? '');
                    $h = (int)($s['Height'] ?? 0);
                    if ($h > 0) $quality = (string)$h;
                    $bitDepth   = (int)($s['BitDepth'] ?? 0);
                    $vr = (string)($s['VideoRange'] ?? '');
                    $vrt = (string)($s['VideoRangeType'] ?? '');
                    if ($vrt !== '') {
                        $videoRange = $vrt;
                    } elseif ($vr !== '') {
                        $videoRange = $vr;
                    }
                }
                if ($sType === 'Audio' && $audioCodec === '') {
                    $audioCodec    = (string)($s['Codec']    ?? '');
                    $audioChannels = (int)($s['Channels']    ?? 0);
                    $spatial       = (string)($s['AudioSpatialFormat'] ?? '');
                    if ($spatial !== '' && $spatial !== 'None') $audioSpatial = $spatial;
                }
                if ($sType === 'Subtitle' && $subLang === '') {
                    $subLang  = (string)($s['Language']    ?? $s['DisplayTitle'] ?? '');
                    $subCodec = (string)($s['Codec']       ?? '');
                }
            }

            if ($quality === '') {
                $h = (int)($nowPlaying['Height'] ?? 0);
                if ($h > 0) $quality = (string)$h;
            }

            $hwAccel = '';
            $transcodeReasons = '';
            $transcodeBufPct = 0.0;
            if ($transInfo !== null) {
                if ($videoCodec === '') $videoCodec = (string)($transInfo['VideoCodec'] ?? '');
                if ($audioCodec === '') $audioCodec = (string)($transInfo['AudioCodec'] ?? '');
                if ($bitrate    === 0)  $bitrate    = (int)(($transInfo['Bitrate']      ?? 0) / 1000);
                if ($quality    === '') {
                    $h = (int)($transInfo['Height'] ?? 0);
                    if ($h > 0) $quality = (string)$h;
                }
                $hwAccel         = (string)($transInfo['HardwareAccelerationType'] ?? '');
                $transcodeBufPct = (float)($transInfo['CompletionPercentage']      ?? 0);
                $reasons         = $transInfo['TranscodeReasons'] ?? [];
                if (is_array($reasons) && !empty($reasons)) {
                    $transcodeReasons = implode(', ', array_map(function($r) {
                        return preg_replace('/([a-z])([A-Z])/', '$1 \$2', (string)$r);
                    }, $reasons));
                }
            }

            $imageId  = $nowPlaying['SeriesId'] ?? $nowPlaying['SeasonId'] ?? $nowPlaying['Id'] ?? null;
            $thumbUrl = ($imageId && $srv['url'] !== '')
                ? rtrim($srv['url'], '/') . '/Items/' . urlencode($imageId) . '/Images/Primary?maxHeight=600&maxWidth=400&quality=96&api_key=' . urlencode($srv['token'])
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
                'bit_depth'             => $bitDepth,
                'video_range'           => $videoRange,
                'audio_codec'           => $audioCodec,
                'audio_channels'        => $audioChannels,
                'audio_spatial'         => $audioSpatial,
                'subtitle_language'     => $subLang,
                'subtitle_codec'        => $subCodec,
                'transcode_video_codec' => ($playType === 'transcode') ? (string)($transInfo['VideoCodec'] ?? '') : '',
                'transcode_speed'       => 0,
                'hw_accel'              => $hwAccel,
                'transcode_reasons'     => $transcodeReasons,
                'transcode_buffer_pct'  => $transcodeBufPct,
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
        $videoDirect = $transInfo['IsVideoDirect'] ?? true;
        $audioDirect = $transInfo['IsAudioDirect'] ?? true;
        if (!$videoDirect) return 'transcode';
        if (!$audioDirect) return 'direct_stream';
        return 'direct_play';
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

    private function parseEmbyBody(array $srv, string $body): array
    {
        $result = $this->parseJellyfinBody($srv, $body);
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
            'bit_depth'             => (int)($raw['bit_depth']                          ?? 0),
            'video_range'           => $this->sanitizeStr($raw['video_range']           ?? ''),
            'audio_codec'           => $this->sanitizeStr($raw['audio_codec']           ?? ''),
            'audio_channels'        => (int)($raw['audio_channels']                     ?? 0),
            'audio_spatial'         => $this->sanitizeStr($raw['audio_spatial']         ?? ''),
            'subtitle_language'     => $this->sanitizeStr($raw['subtitle_language']     ?? ''),
            'subtitle_codec'        => $this->sanitizeStr($raw['subtitle_codec']        ?? ''),
            'transcode_video_codec' => $this->sanitizeStr($raw['transcode_video_codec'] ?? ''),
            'transcode_speed'       => (float)($raw['transcode_speed']                  ?? 0),
            'hw_accel'              => $this->sanitizeStr($raw['hw_accel']              ?? ''),
            'transcode_reasons'     => $this->sanitizeStr($raw['transcode_reasons']     ?? ''),
            'transcode_buffer_pct'  => round((float)($raw['transcode_buffer_pct']       ?? 0), 1),
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

        // Validate scheme
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            http_response_code(400); exit('Invalid URL scheme');
        }

        // Extract host+port from the requested thumbnail URL
        $reqHost = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        $reqPort = parse_url($url, PHP_URL_PORT);  // null when absent

        // Check against configured server origins (host+port match)
        $allowed = false;
        $cfg = $this->loadCfg();
        for ($i = 1; $i <= self::MAX_SERVERS; $i++) {
            $srvUrl = rtrim(trim((string)($cfg["SERVER{$i}_URL"] ?? '')), '/');
            if ($srvUrl === '') continue;
            $srvHost = strtolower((string)(parse_url($srvUrl, PHP_URL_HOST) ?: ''));
            $srvPort = parse_url($srvUrl, PHP_URL_PORT);
            if ($srvHost !== '' && $srvHost === $reqHost && $srvPort === $reqPort) {
                $allowed = true;
                break;
            }
        }
        // Also allow *.plex.direct (Plex relay/tunnel hostnames)
        if (!$allowed && preg_match('#^[a-z0-9-]+\.plex\.direct$#i', $reqHost)) {
            $allowed = true;
        }
        if (!$allowed) { http_response_code(403); exit('URL not allowed'); }

        $verify = $this->verifySsl;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,             // no redirects — prevent open redirect SSRF
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT,
            CURLOPT_MAXFILESIZE    => self::THUMB_MAX_BYTES,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
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

        if ($index >= 1 && $index <= self::MAX_SERVERS) {
            // Mode 1: test a saved server by config index
            $cfg   = $this->loadCfg();
            $type  = (string)($cfg["SERVER{$index}_TYPE"]  ?? '');
            $url   = rtrim(trim((string)($cfg["SERVER{$index}_URL"]   ?? '')), '/');
            $token = trim((string)($cfg["SERVER{$index}_TOKEN"] ?? ''));
            $name  = trim((string)($cfg["SERVER{$index}_NAME"]  ?? "Server {$index}"));
        } else {
            // Mode 2: test an unsaved server with direct params (add-server forms)
            $type  = (string)($_GET['type']  ?? '');
            $url   = rtrim(trim((string)($_GET['url']   ?? '')), '/');
            $token = trim((string)($_GET['token'] ?? ''));
            $name  = 'Test';
        }

        if (!in_array($type, self::VALID_TYPES, true)) $this->json(['ok' => false, 'error' => 'Invalid or missing server type']);
        if ($url   === '') $this->json(['ok' => false, 'error' => 'No URL configured']);
        if ($token === '') $this->json(['ok' => false, 'error' => 'No token/API key configured']);

        [$testUrl, $headers] = match($type) {
            'plex'             => [$url . '/', ['X-Plex-Token' => $token, 'Accept' => 'application/json']],
            'jellyfin', 'emby' => [$url . '/System/Info', ['X-Emby-Token' => $token, 'Accept' => 'application/json']],
            default            => [$url, []],
        };

        [$body, $httpCode, $err] = $this->httpGet($testUrl, $headers);

        if ($err !== null) $this->json(['ok' => false, 'server' => $name, 'error' => $err]);
        if ($httpCode === 401) $this->json(['ok' => false, 'server' => $name, 'error' => 'Authentication failed — check your token']);

        if ($httpCode >= 200 && $httpCode < 300) {
            $version = '';
            $data    = @json_decode($body, true);
            if (is_array($data)) $version = (string)($data['version'] ?? $data['Version'] ?? '');
            if ($type === 'plex' && $version === '' && strpos((string)$body, 'MediaContainer') !== false) {
                if (preg_match('/version="([^"]+)"/', (string)$body, $m)) $version = $m[1];
            }
            $this->json(['ok' => true, 'server' => $name, 'type' => $type, 'version' => $version, 'http' => $httpCode]);
        }

        $this->json(['ok' => false, 'server' => $name, 'error' => "HTTP {$httpCode}"]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Stats API endpoints
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Helper: validate period param and return Unix cutoff timestamp.
     */
    private function statsPeriodCutoff(): int
    {
        $period = (string)($_GET['period'] ?? '30d');
        $days = match($period) {
            '7d'  => 7,
            '90d' => 90,
            default => 30,
        };
        return time() - ($days * 86400);
    }

    /**
     * GET ?action=get_stats&period=30d
     * Returns summary cards: total plays, hours watched, unique users,
     * peak concurrent, play type breakdown.
     */
    private function replyGetStats(): void
    {
        $db = $this->openDb();
        if ($db === null) $this->json(['error' => 'Stats not enabled'], 400);

        $cutoff = $this->statsPeriodCutoff();

        // Total plays + total duration
        $stmt = $db->prepare("
            SELECT COUNT(*) AS total_plays,
                   COALESCE(SUM(duration_sec), 0) AS total_seconds
            FROM watch_history WHERE ended_at >= :cutoff
        ");
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        $totalPlays   = (int)($r['total_plays'] ?? 0);
        $totalSeconds = (int)($r['total_seconds'] ?? 0);

        // Unique users
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT user) AS cnt FROM watch_history WHERE ended_at >= :cutoff
        ");
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $uniqueUsers = (int)($stmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'] ?? 0);
        $stmt->close();

        // Peak concurrent (approximate via hourly buckets)
        $stmt = $db->prepare("
            SELECT MAX(cnt) AS peak FROM (
                SELECT COUNT(*) AS cnt
                FROM watch_history
                WHERE ended_at >= :cutoff
                GROUP BY CAST(started_at / 3600 AS INTEGER)
            )
        ");
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $peak = (int)($stmt->execute()->fetchArray(SQLITE3_ASSOC)['peak'] ?? 0);
        $stmt->close();

        // Also count currently active
        $activeCnt = (int)$db->querySingle("SELECT COUNT(*) FROM active_sessions");
        if ($activeCnt > $peak) $peak = $activeCnt;

        // Play type breakdown (3 types only, remote is separate)
        $stmt = $db->prepare("
            SELECT play_type, COUNT(*) AS cnt
            FROM watch_history WHERE ended_at >= :cutoff
            GROUP BY play_type
        ");
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $res = $stmt->execute();
        $playTypes = ['direct_play' => 0, 'direct_stream' => 0, 'transcode' => 0];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $pt = (string)($row['play_type'] ?? '');
            if (isset($playTypes[$pt])) $playTypes[$pt] = (int)$row['cnt'];
        }
        $stmt->close();

        // Remote percentage (calculated from IP, not play_type)
        $stmt = $db->prepare("
            SELECT ip_address FROM watch_history WHERE ended_at >= :cutoff AND ip_address != ''
        ");
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $res = $stmt->execute();
        $ipTotal = 0;
        $ipRemote = 0;
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $ipTotal++;
            if (!$this->isPrivateIp((string)$row['ip_address'])) $ipRemote++;
        }
        $stmt->close();
        $remotePct = ($ipTotal > 0) ? round(($ipRemote / $ipTotal) * 100) : 0;

        $this->json([
            'total_plays'    => $totalPlays,
            'hours_watched'  => round($totalSeconds / 3600, 1),
            'unique_users'   => $uniqueUsers,
            'peak_concurrent'=> $peak,
            'play_types'     => $playTypes,
            'remote_pct'     => $remotePct,
        ]);
    }

    /**
     * GET ?action=get_daily_chart&period=30d
     * Returns daily aggregated stream counts per server type.
     */
    private function replyGetDailyChart(): void
    {
        $db = $this->openDb();
        if ($db === null) $this->json(['error' => 'Stats not enabled'], 400);

        $cutoff = $this->statsPeriodCutoff();

        $stmt = $db->prepare("
            SELECT DATE(ended_at, 'unixepoch', 'localtime') AS day,
                   server_type,
                   COUNT(*) AS cnt
            FROM watch_history
            WHERE ended_at >= :cutoff
            GROUP BY day, server_type
            ORDER BY day ASC
        ");
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $res = $stmt->execute();

        $rawDays = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $d = (string)($row['day'] ?? '');
            if (!isset($rawDays[$d])) $rawDays[$d] = ['date' => $d, 'plex' => 0, 'jellyfin' => 0, 'emby' => 0];
            $st = (string)($row['server_type'] ?? '');
            if (isset($rawDays[$d][$st])) $rawDays[$d][$st] = (int)$row['cnt'];
        }
        $stmt->close();

        // Fill gaps: continuous date range from cutoff to today
        $days = [];
        $start = new \DateTime("@{$cutoff}");
        $start->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $end = new \DateTime('now', new \DateTimeZone(date_default_timezone_get()));
        $period = new \DatePeriod($start, new \DateInterval('P1D'), $end->modify('+1 day'));
        foreach ($period as $dt) {
            $d = $dt->format('Y-m-d');
            $days[] = $rawDays[$d] ?? ['date' => $d, 'plex' => 0, 'jellyfin' => 0, 'emby' => 0];
        }

        $this->json(['daily' => $days]);
    }

    /**
     * GET ?action=get_top_media&period=30d&limit=10
     * Returns most watched titles.
     */
    private function replyGetTopMedia(): void
    {
        $db = $this->openDb();
        if ($db === null) $this->json(['error' => 'Stats not enabled'], 400);

        $cutoff = $this->statsPeriodCutoff();
        $limit  = min(50, max(1, (int)($_GET['limit'] ?? 10)));

        $stmt = $db->prepare("
            SELECT title, media_type,
                   COUNT(*) AS plays,
                   COUNT(DISTINCT user) AS users,
                   COALESCE(SUM(duration_sec), 0) AS total_sec
            FROM watch_history
            WHERE ended_at >= :cutoff AND title != ''
            GROUP BY title
            ORDER BY plays DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $stmt->bindValue(':limit',  $limit,  SQLITE3_INTEGER);
        $res = $stmt->execute();

        $items = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $items[] = [
                'title'      => $row['title'],
                'media_type' => $row['media_type'],
                'plays'      => (int)$row['plays'],
                'users'      => (int)$row['users'],
                'hours'      => round((int)$row['total_sec'] / 3600, 1),
            ];
        }
        $stmt->close();

        $this->json(['media' => $items]);
    }

    /**
     * GET ?action=get_top_users&period=30d&limit=10
     * Returns top users by play count and hours.
     */
    private function replyGetTopUsers(): void
    {
        $db = $this->openDb();
        if ($db === null) $this->json(['error' => 'Stats not enabled'], 400);

        $cutoff = $this->statsPeriodCutoff();
        $limit  = min(50, max(1, (int)($_GET['limit'] ?? 10)));

        $stmt = $db->prepare("
            SELECT user,
                   COUNT(*) AS plays,
                   GROUP_CONCAT(DISTINCT server_type) AS servers,
                   COALESCE(SUM(duration_sec), 0) AS total_sec
            FROM watch_history
            WHERE ended_at >= :cutoff
            GROUP BY user
            ORDER BY plays DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $stmt->bindValue(':limit',  $limit,  SQLITE3_INTEGER);
        $res = $stmt->execute();

        $items = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $items[] = [
                'user'    => $row['user'],
                'plays'   => (int)$row['plays'],
                'servers' => $row['servers'],
                'hours'   => round((int)$row['total_sec'] / 3600, 1),
            ];
        }
        $stmt->close();

        $this->json(['users' => $items]);
    }

    /**
     * GET ?action=get_history&period=30d&page=1&per_page=20&user=&server_type=&play_type=&media_type=&search=
     * Returns paginated watch history with summary.
     */
    private function replyGetHistory(): void
    {
        $db = $this->openDb();
        if ($db === null) $this->json(['error' => 'Stats not enabled'], 400);

        $cutoff   = $this->statsPeriodCutoff();
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $perPage  = min(100, max(5, (int)($_GET['per_page'] ?? 20)));
        $offset   = ($page - 1) * $perPage;

        // Optional filters (sanitized via prepared statements)
        $filterUser   = trim((string)($_GET['user'] ?? ''));
        $filterServer = trim((string)($_GET['server_type'] ?? ''));
        $filterPlay   = trim((string)($_GET['play_type'] ?? ''));
        $filterMedia  = trim((string)($_GET['media_type'] ?? ''));
        $filterSearch = trim((string)($_GET['search'] ?? ''));

        $where = "ended_at >= :cutoff";
        $binds = [':cutoff' => [$cutoff, SQLITE3_INTEGER]];

        if ($filterUser !== '') {
            $where .= " AND user = :user";
            $binds[':user'] = [$filterUser, SQLITE3_TEXT];
        }
        if ($filterServer !== '' && in_array($filterServer, self::VALID_TYPES, true)) {
            $where .= " AND server_type = :st";
            $binds[':st'] = [$filterServer, SQLITE3_TEXT];
        }
        if ($filterPlay !== '' && in_array($filterPlay, ['direct_play','direct_stream','transcode'], true)) {
            $where .= " AND play_type = :pt";
            $binds[':pt'] = [$filterPlay, SQLITE3_TEXT];
        }
        if ($filterMedia !== '' && in_array($filterMedia, ['movie','episode','track'], true)) {
            $where .= " AND media_type = :mt";
            $binds[':mt'] = [$filterMedia, SQLITE3_TEXT];
        }
        if ($filterSearch !== '') {
            $safeSearch = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $filterSearch);
            $where .= " AND title LIKE :search ESCAPE '\\'";
            $binds[':search'] = ['%' . $safeSearch . '%', SQLITE3_TEXT];
        }

        // Count + summary
        $sumStmt = $db->prepare("
            SELECT COUNT(*) AS cnt,
                   COALESCE(SUM(duration_sec), 0) AS total_sec
            FROM watch_history WHERE {$where}
        ");
        foreach ($binds as $k => [$v, $t]) $sumStmt->bindValue($k, $v, $t);
        $sumRow = $sumStmt->execute()->fetchArray(SQLITE3_ASSOC);
        $sumStmt->close();
        $total    = (int)($sumRow['cnt'] ?? 0);
        $totalSec = (int)($sumRow['total_sec'] ?? 0);
        $avgSec   = $total > 0 ? (int)round($totalSec / $total) : 0;

        // Remote count for summary
        $remStmt = $db->prepare("
            SELECT ip_address FROM watch_history WHERE {$where} AND ip_address != ''
        ");
        foreach ($binds as $k => [$v, $t]) $remStmt->bindValue($k, $v, $t);
        $remRes = $remStmt->execute();
        $ipCount = 0; $remoteCount = 0;
        while ($rr = $remRes->fetchArray(SQLITE3_ASSOC)) {
            $ipCount++;
            if (!$this->isPrivateIp((string)$rr['ip_address'])) $remoteCount++;
        }
        $remStmt->close();
        $remotePct = ($ipCount > 0) ? (int)round(($remoteCount / $ipCount) * 100) : 0;

        // Distinct users (for user filter dropdown)
        $usersStmt = $db->prepare("
            SELECT DISTINCT user FROM watch_history WHERE ended_at >= :cutoff ORDER BY user ASC
        ");
        $usersStmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $usersRes = $usersStmt->execute();
        $userList = [];
        while ($ur = $usersRes->fetchArray(SQLITE3_ASSOC)) {
            $userList[] = (string)$ur['user'];
        }
        $usersStmt->close();

        // Rows
        $stmt = $db->prepare("
            SELECT user, title, media_type, server_name, server_type,
                   play_type, ip_address, duration_sec, started_at, ended_at
            FROM watch_history
            WHERE {$where}
            ORDER BY ended_at DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($binds as $k => [$v, $t]) $stmt->bindValue($k, $v, $t);
        $stmt->bindValue(':limit',  $perPage, SQLITE3_INTEGER);
        $stmt->bindValue(':offset', $offset,  SQLITE3_INTEGER);
        $res = $stmt->execute();

        $rows = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $ip = (string)$row['ip_address'];
            $rows[] = [
                'user'        => $row['user'],
                'title'       => $row['title'],
                'media_type'  => $row['media_type'],
                'server_name' => $row['server_name'],
                'server_type' => $row['server_type'],
                'play_type'   => $row['play_type'],
                'ip_address'  => $ip,
                'is_local'    => ($ip !== '') ? $this->isPrivateIp($ip) : true,
                'duration'    => $this->formatDurationHMS((int)$row['duration_sec']),
                'duration_sec'=> (int)$row['duration_sec'],
                'started_at'  => (int)$row['started_at'],
                'ended_at'    => (int)$row['ended_at'],
            ];
        }
        $stmt->close();

        $this->json([
            'rows'       => $rows,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'pages'      => (int)ceil($total / $perPage),
            'total_sec'  => $totalSec,
            'avg_sec'    => $avgSec,
            'remote_pct' => $remotePct,
            'user_list'  => $userList,
        ]);
    }

    private function formatDurationHMS(int $sec): string
    {
        if ($sec < 0) $sec = 0;
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;
        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }

    // ══════════════════════════════════════════════════════════════════════
    // User stats endpoint
    // ══════════════════════════════════════════════════════════════════════

    /**
     * GET ?action=get_user_stats&period=30d
     * Returns detailed per-user statistics.
     */
    private function replyGetUserStats(): void
    {
        $db = $this->openDb();
        if ($db === null) $this->json(['error' => 'Stats not enabled'], 400);

        $cutoff = $this->statsPeriodCutoff();

        // Per-user aggregates
        $stmt = $db->prepare("
            SELECT user,
                   COUNT(*) AS plays,
                   COALESCE(SUM(duration_sec), 0) AS total_sec,
                   GROUP_CONCAT(DISTINCT server_type) AS servers,
                   MAX(ended_at) AS last_seen
            FROM watch_history
            WHERE ended_at >= :cutoff
            GROUP BY user
            ORDER BY plays DESC
        ");
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $res = $stmt->execute();

        $users = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $userName = (string)$row['user'];

            // Per-user media type breakdown
            $mtStmt = $db->prepare("
                SELECT media_type, COUNT(*) AS cnt
                FROM watch_history
                WHERE user = :user AND ended_at >= :cutoff
                GROUP BY media_type
            ");
            $mtStmt->bindValue(':user', $userName, SQLITE3_TEXT);
            $mtStmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
            $mtRes = $mtStmt->execute();
            $mediaBreakdown = ['movie' => 0, 'episode' => 0, 'track' => 0];
            while ($mt = $mtRes->fetchArray(SQLITE3_ASSOC)) {
                $key = (string)($mt['media_type'] ?? '');
                if (isset($mediaBreakdown[$key])) $mediaBreakdown[$key] = (int)$mt['cnt'];
            }
            $mtStmt->close();

            // Per-user play type breakdown
            $ptStmt = $db->prepare("
                SELECT play_type, COUNT(*) AS cnt
                FROM watch_history
                WHERE user = :user AND ended_at >= :cutoff
                GROUP BY play_type
            ");
            $ptStmt->bindValue(':user', $userName, SQLITE3_TEXT);
            $ptStmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
            $ptRes = $ptStmt->execute();
            $playBreakdown = ['direct_play' => 0, 'direct_stream' => 0, 'transcode' => 0];
            while ($pt = $ptRes->fetchArray(SQLITE3_ASSOC)) {
                $key = (string)($pt['play_type'] ?? '');
                if (isset($playBreakdown[$key])) $playBreakdown[$key] = (int)$pt['cnt'];
            }
            $ptStmt->close();

            // Last IP and device
            $lastStmt = $db->prepare("
                SELECT ip_address, device
                FROM watch_history
                WHERE user = :user AND ended_at >= :cutoff
                ORDER BY ended_at DESC
                LIMIT 1
            ");
            $lastStmt->bindValue(':user', $userName, SQLITE3_TEXT);
            $lastStmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
            $lastRow = $lastStmt->execute()->fetchArray(SQLITE3_ASSOC);
            $lastStmt->close();

            $lastIp     = (string)($lastRow['ip_address'] ?? '');
            $lastDevice = (string)($lastRow['device'] ?? '');
            $isLocal    = ($lastIp !== '') ? $this->isPrivateIp($lastIp) : true;

            // Check if currently active
            $activeStmt = $db->prepare("
                SELECT COUNT(*) AS cnt FROM active_sessions WHERE user = :user
            ");
            $activeStmt->bindValue(':user', $userName, SQLITE3_TEXT);
            $isActive = ((int)($activeStmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'] ?? 0)) > 0;
            $activeStmt->close();

            $users[] = [
                'user'           => $userName,
                'plays'          => (int)$row['plays'],
                'hours'          => round((int)$row['total_sec'] / 3600, 1),
                'servers'        => (string)$row['servers'],
                'last_seen'      => (int)$row['last_seen'],
                'last_ip'        => $lastIp,
                'last_device'    => $lastDevice,
                'is_local'       => $isLocal,
                'is_active'      => $isActive,
                'media'          => $mediaBreakdown,
                'play_types'     => $playBreakdown,
            ];
        }
        $stmt->close();

        // Summary
        $totalUsers = count($users);
        $totalPlays = 0;
        $totalHours = 0;
        $mostActive = '';
        $maxPlays   = 0;
        foreach ($users as $u) {
            $totalPlays += $u['plays'];
            $totalHours += $u['hours'];
            if ($u['plays'] > $maxPlays) { $maxPlays = $u['plays']; $mostActive = $u['user']; }
        }

        $this->json([
            'users'       => $users,
            'total_users' => $totalUsers,
            'most_active' => $mostActive,
            'avg_plays'   => $totalUsers > 0 ? round($totalPlays / $totalUsers, 1) : 0,
            'avg_hours'   => $totalUsers > 0 ? round($totalHours / $totalUsers, 1) : 0,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Graph data endpoint
    // ══════════════════════════════════════════════════════════════════════

    /**
     * GET ?action=get_graph_data&period=30d
     * Returns all data needed for the Graphs tab charts.
     */
    private function replyGetGraphData(): void
    {
        $db = $this->openDb();
        if ($db === null) $this->json(['error' => 'Stats not enabled'], 400);

        $cutoff = $this->statsPeriodCutoff();

        // 1. Watch time per day per server (line chart)
        $stmt = $db->prepare("
            SELECT DATE(ended_at, 'unixepoch', 'localtime') AS day,
                   server_type,
                   COALESCE(SUM(duration_sec), 0) AS total_sec
            FROM watch_history
            WHERE ended_at >= :cutoff
            GROUP BY day, server_type
            ORDER BY day ASC
        ");
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $res = $stmt->execute();
        $watchDays = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $d = (string)$row['day'];
            if (!isset($watchDays[$d])) $watchDays[$d] = ['date' => $d, 'plex' => 0, 'jellyfin' => 0, 'emby' => 0];
            $st = (string)($row['server_type'] ?? '');
            if (isset($watchDays[$d][$st])) {
                $watchDays[$d][$st] = round((int)$row['total_sec'] / 3600, 2);
            }
        }
        $stmt->close();

        // 2. Peak viewing hours (bar chart, 0-23)
        $stmt = $db->prepare("
            SELECT CAST(strftime('%H', ended_at, 'unixepoch', 'localtime') AS INTEGER) AS hour,
                   COUNT(*) AS cnt
            FROM watch_history
            WHERE ended_at >= :cutoff
            GROUP BY hour
            ORDER BY hour ASC
        ");
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $res = $stmt->execute();
        $peakHours = array_fill(0, 24, 0);
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $h = (int)($row['hour'] ?? 0);
            if ($h >= 0 && $h < 24) $peakHours[$h] = (int)$row['cnt'];
        }
        $stmt->close();

        // 3. Play type distribution (donut)
        $stmt = $db->prepare("
            SELECT play_type, COUNT(*) AS cnt
            FROM watch_history WHERE ended_at >= :cutoff
            GROUP BY play_type
        ");
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $res = $stmt->execute();
        $playDist = ['direct_play' => 0, 'direct_stream' => 0, 'transcode' => 0];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $pt = (string)($row['play_type'] ?? '');
            if (isset($playDist[$pt])) $playDist[$pt] = (int)$row['cnt'];
        }
        $stmt->close();

        // 4. Media type distribution (donut)
        $stmt = $db->prepare("
            SELECT media_type, COUNT(*) AS cnt
            FROM watch_history WHERE ended_at >= :cutoff
            GROUP BY media_type
        ");
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $res = $stmt->execute();
        $mediaDist = ['movie' => 0, 'episode' => 0, 'track' => 0];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $mt = (string)($row['media_type'] ?? '');
            if (isset($mediaDist[$mt])) $mediaDist[$mt] = (int)$row['cnt'];
        }
        $stmt->close();

        // 5. User activity - hours per user (horizontal bar)
        $stmt = $db->prepare("
            SELECT user, COALESCE(SUM(duration_sec), 0) AS total_sec
            FROM watch_history
            WHERE ended_at >= :cutoff
            GROUP BY user
            ORDER BY total_sec DESC
            LIMIT 10
        ");
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $res = $stmt->execute();
        $userActivity = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $userActivity[] = [
                'user'  => (string)$row['user'],
                'hours' => round((int)$row['total_sec'] / 3600, 1),
            ];
        }
        $stmt->close();

        // 6. Local vs remote per day (stacked bar)
        $stmt = $db->prepare("
            SELECT DATE(ended_at, 'unixepoch', 'localtime') AS day,
                   ip_address
            FROM watch_history
            WHERE ended_at >= :cutoff AND ip_address != ''
            ORDER BY day ASC
        ");
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $res = $stmt->execute();
        $lrDays = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $d = (string)$row['day'];
            if (!isset($lrDays[$d])) $lrDays[$d] = ['date' => $d, 'local' => 0, 'remote' => 0];
            if ($this->isPrivateIp((string)$row['ip_address'])) {
                $lrDays[$d]['local']++;
            } else {
                $lrDays[$d]['remote']++;
            }
        }
        $stmt->close();

        // 7. Bandwidth per day (line chart, sum of bandwidth_kbps * duration)
        $stmt = $db->prepare("
            SELECT DATE(ended_at, 'unixepoch', 'localtime') AS day,
                   COALESCE(SUM(bandwidth_kbps), 0) AS total_kbps,
                   COUNT(*) AS cnt
            FROM watch_history
            WHERE ended_at >= :cutoff
            GROUP BY day
            ORDER BY day ASC
        ");
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $res = $stmt->execute();
        $bwDays = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $avgMbps = (int)$row['cnt'] > 0
                ? round(((int)$row['total_kbps'] / (int)$row['cnt']) / 1000, 1)
                : 0;
            $bwDays[] = [
                'date'     => (string)$row['day'],
                'avg_mbps' => $avgMbps,
            ];
        }
        $stmt->close();

        // Fill date gaps for continuous timelines
        $fillDates = function(int $cutoff, array $rawDays, array $template): array {
            $days = [];
            $start = new \DateTime("@{$cutoff}");
            $start->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            $end = new \DateTime('now', new \DateTimeZone(date_default_timezone_get()));
            $period = new \DatePeriod($start, new \DateInterval('P1D'), $end->modify('+1 day'));
            foreach ($period as $dt) {
                $d = $dt->format('Y-m-d');
                $days[] = $rawDays[$d] ?? array_merge(['date' => $d], $template);
            }
            return $days;
        };

        // Convert bwDays array to keyed
        $bwKeyed = [];
        foreach ($bwDays as $b) $bwKeyed[$b['date']] = $b;

        $this->json([
            'watch_time_daily'   => $fillDates($cutoff, $watchDays, ['plex' => 0, 'jellyfin' => 0, 'emby' => 0]),
            'peak_hours'         => $peakHours,
            'play_type_dist'     => $playDist,
            'media_type_dist'    => $mediaDist,
            'user_activity'      => $userActivity,
            'local_remote_daily' => $fillDates($cutoff, $lrDays, ['local' => 0, 'remote' => 0]),
            'bandwidth_daily'    => $fillDates($cutoff, $bwKeyed, ['avg_mbps' => 0]),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Library endpoints
    // ══════════════════════════════════════════════════════════════════════

    /**
     * GET ?action=get_alerts&period=30d
     * Returns alerts: buffering warnings, inactive users, transcode ratio.
     */
    private function replyGetAlerts(): void
    {
        $db = $this->openDb();
        if ($db === null) $this->json(['error' => 'Stats not enabled'], 400);

        $periodMap = ['7d' => 7, '30d' => 30, '90d' => 90];
        $days = $periodMap[$_GET['period'] ?? '30d'] ?? 30;
        $cutoff = time() - ($days * 86400);

        // 1. Buffering warnings: sessions under 120 seconds, grouped by title
        $bufferStmt = $db->prepare("
            SELECT title, user, server_type, COUNT(*) AS cnt,
                   ROUND(AVG(duration_sec)) AS avg_dur
            FROM watch_history
            WHERE ended_at >= :cutoff AND duration_sec > 0 AND duration_sec < 120
            GROUP BY title, user
            HAVING cnt >= 2
            ORDER BY cnt DESC
            LIMIT 20
        ");
        $bufferStmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $bufRes = $bufferStmt->execute();
        $buffering = [];
        while ($row = $bufRes->fetchArray(SQLITE3_ASSOC)) {
            $buffering[] = $row;
        }

        // 2. Inactive users: users who have history but no activity in last N days
        $inactiveStmt = $db->prepare("
            SELECT user, MAX(ended_at) AS last_seen,
                   COUNT(*) AS total_plays
            FROM watch_history
            GROUP BY user
            HAVING last_seen < :cutoff
            ORDER BY last_seen ASC
            LIMIT 20
        ");
        $inactiveStmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $inRes = $inactiveStmt->execute();
        $inactive = [];
        while ($row = $inRes->fetchArray(SQLITE3_ASSOC)) {
            $inactive[] = $row;
        }

        // 3. Transcode ratio
        $totalStmt = $db->prepare("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN play_type = 'transcode' THEN 1 ELSE 0 END) AS tc_count
            FROM watch_history
            WHERE ended_at >= :cutoff
        ");
        $totalStmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $tcRow = $totalStmt->execute()->fetchArray(SQLITE3_ASSOC);
        $total = (int)($tcRow['total'] ?? 0);
        $tcCount = (int)($tcRow['tc_count'] ?? 0);
        $tcPct = $total > 0 ? round($tcCount / $total * 100) : 0;

        // Top transcode users
        $tcUsersStmt = $db->prepare("
            SELECT user, COUNT(*) AS tc_plays,
                   ROUND(COUNT(*) * 100.0 / NULLIF((SELECT COUNT(*) FROM watch_history WHERE ended_at >= :cutoff2 AND user = wh.user), 0)) AS tc_pct
            FROM watch_history wh
            WHERE ended_at >= :cutoff AND play_type = 'transcode'
            GROUP BY user
            ORDER BY tc_plays DESC
            LIMIT 10
        ");
        $tcUsersStmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $tcUsersStmt->bindValue(':cutoff2', $cutoff, SQLITE3_INTEGER);
        $tcUsersRes = $tcUsersStmt->execute();
        $tcUsers = [];
        while ($row = $tcUsersRes->fetchArray(SQLITE3_ASSOC)) {
            $tcUsers[] = $row;
        }

        // Severity levels
        $tcSeverity = 'ok';
        if ($tcPct >= 70) $tcSeverity = 'critical';
        elseif ($tcPct >= 40) $tcSeverity = 'warning';

        $alertCount = count($buffering) + count($inactive) + ($tcSeverity !== 'ok' ? 1 : 0);

        $this->json([
            'alert_count' => $alertCount,
            'buffering'   => $buffering,
            'inactive'    => $inactive,
            'transcode'   => [
                'total'    => $total,
                'tc_count' => $tcCount,
                'tc_pct'   => $tcPct,
                'severity' => $tcSeverity,
                'users'    => $tcUsers,
            ],
        ]);
    }

    /**
     * GET ?action=get_libraries
     * Returns cached library data per server. Auto-syncs if stale.
     */
    private function replyGetLibraries(): void
    {
        $db = $this->openDb();
        if ($db === null) $this->json(['error' => 'Stats not enabled'], 400);

        $cfg     = $this->loadCfg();
        $servers = $this->getEnabledServers($cfg);

        // Check cache freshness, sync if stale
        $now     = time();
        $lastSync = (int)$db->querySingle("SELECT MAX(synced_at) FROM library_cache");
        if (($now - $lastSync) > self::LIBRARY_CACHE_TTL) {
            $this->syncLibraryData($db, $servers);
        }

        // Build response from cache
        $result = [];
        foreach ($servers as $srv) {
            $srvData = [
                'index'     => $srv['index'],
                'name'      => $srv['name'],
                'type'      => $srv['type'],
                'online'    => true,
                'synced_at' => 0,
                'libraries' => [],
            ];

            $stmt = $db->prepare("
                SELECT library_name, library_type, total_items, episode_count, synced_at
                FROM library_cache
                WHERE server_index = :idx
                ORDER BY library_type ASC, library_name ASC
            ");
            $stmt->bindValue(':idx', $srv['index'], SQLITE3_INTEGER);
            $res = $stmt->execute();
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $srvData['libraries'][] = [
                    'name'          => $row['library_name'],
                    'type'          => $row['library_type'],
                    'total_items'   => (int)$row['total_items'],
                    'episode_count' => (int)$row['episode_count'],
                ];
                $srvData['synced_at'] = max($srvData['synced_at'], (int)$row['synced_at']);
            }
            $stmt->close();

            // Watched counts from watch_history (distinct titles per server+media_type)
            // Step 1: get total watched per media_type for this server
            $watchedByType = [];
            $typeToLibs = [];   // group library indices by type
            $typeTotals = [];   // total items per type across all libs
            foreach ($srvData['libraries'] as $idx => &$lib) {
                $lib['watched'] = 0;
                $t = $lib['type'];
                if (!isset($typeToLibs[$t])) { $typeToLibs[$t] = []; $typeTotals[$t] = 0; }
                $typeToLibs[$t][] = $idx;
                $typeTotals[$t] += ($lib['total_items'] ?? 0);
            }
            unset($lib);

            foreach ($typeToLibs as $libType => $indices) {
                $mediaTypes = $this->libraryTypeToMediaTypes($libType);
                if (empty($mediaTypes)) continue;
                $placeholders = implode(',', array_fill(0, count($mediaTypes), '?'));
                $q = $db->prepare("
                    SELECT COUNT(DISTINCT title) AS cnt
                    FROM watch_history
                    WHERE server_name = ? AND media_type IN ({$placeholders})
                ");
                $q->bindValue(1, $srv['name'], SQLITE3_TEXT);
                foreach ($mediaTypes as $k => $mt) $q->bindValue($k + 2, $mt, SQLITE3_TEXT);
                $totalWatched = (int)($q->execute()->fetchArray(SQLITE3_ASSOC)['cnt'] ?? 0);
                $q->close();

                // Step 2: distribute proportionally across libraries of this type
                $totalForType = $typeTotals[$libType];
                if ($totalForType > 0 && $totalWatched > 0) {
                    foreach ($indices as $idx) {
                        $libItems = $srvData['libraries'][$idx]['total_items'] ?? 0;
                        if ($libItems > 0) {
                            $share = round($totalWatched * ($libItems / $totalForType));
                            $srvData['libraries'][$idx]['watched'] = min((int)$share, $libItems);
                        }
                    }
                }
            }

            $result[] = $srvData;
        }

        // Summary
        $totalItems = 0;
        $totalLibs  = 0;
        foreach ($result as $s) {
            foreach ($s['libraries'] as $l) {
                $totalItems += $l['total_items'];
                $totalLibs++;
            }
        }

        $this->json([
            'servers'      => $result,
            'total_items'  => $totalItems,
            'total_libs'   => $totalLibs,
            'server_count' => count($servers),
        ]);
    }

    /**
     * GET ?action=get_recently_added&limit=10
     * Returns recently added items from cache.
     */
    private function replyGetRecentlyAdded(): void
    {
        $db = $this->openDb();
        if ($db === null) $this->json(['error' => 'Stats not enabled'], 400);

        $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));

        $stmt = $db->prepare("
            SELECT title, media_type, server_name, server_type, library_name, added_at,
                   COALESCE(type_label, '-') AS type_label
            FROM recently_added
            ORDER BY added_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $res = $stmt->execute();

        $items = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            // Check if watched (exists in watch_history)
            $wStmt = $db->prepare("
                SELECT COUNT(*) AS cnt FROM watch_history
                WHERE title = :title AND server_name = :sn
            ");
            $wStmt->bindValue(':title', $row['title'], SQLITE3_TEXT);
            $wStmt->bindValue(':sn', $row['server_name'], SQLITE3_TEXT);
            $watched = ((int)($wStmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'] ?? 0)) > 0;
            $wStmt->close();

            $items[] = [
                'title'        => $row['title'],
                'media_type'   => $row['media_type'],
                'type_label'   => $row['type_label'] ?? '-',
                'server_name'  => $row['server_name'],
                'server_type'  => $row['server_type'],
                'library_name' => $row['library_name'],
                'added_at'     => (int)$row['added_at'],
                'watched'      => $watched,
            ];
        }
        $stmt->close();

        $this->json(['items' => $items]);
    }

    /**
     * GET ?action=sync_libraries
     * Force a fresh sync of all library data.
     */
    private function replySyncLibraries(): void
    {
        $db = $this->openDb();
        if ($db === null) $this->json(['error' => 'Stats not enabled'], 400);

        $cfg     = $this->loadCfg();
        $servers = $this->getEnabledServers($cfg);
        $synced  = $this->syncLibraryData($db, $servers);

        $this->json(['ok' => true, 'synced_servers' => $synced]);
    }

    // ── Library sync engine ──────────────────────────────────────────────

    /**
     * Fetch library data from all servers and store in cache.
     * Returns number of successfully synced servers.
     */
    private function syncLibraryData(\SQLite3 $db, array $servers): int
    {
        $now = time();
        $synced = 0;

        foreach ($servers as $srv) {
            try {
                $libraries    = $this->fetchServerLibraries($srv);
                $recentItems  = $this->fetchServerRecentlyAdded($srv);
            } catch (\Throwable $e) {
                continue; // Server offline or error, keep stale cache
            }

            if ($libraries === null) continue;

            $db->exec('BEGIN');

            // Clear old cache for this server
            $delLib = $db->prepare("DELETE FROM library_cache WHERE server_index = :idx");
            $delLib->bindValue(':idx', $srv['index'], SQLITE3_INTEGER);
            $delLib->execute();
            $delLib->close();

            $delRecent = $db->prepare("DELETE FROM recently_added WHERE server_index = :idx");
            $delRecent->bindValue(':idx', $srv['index'], SQLITE3_INTEGER);
            $delRecent->execute();
            $delRecent->close();

            // Insert libraries
            $insLib = $db->prepare("
                INSERT INTO library_cache
                    (server_index, server_name, server_type, library_id, library_name,
                     library_type, total_items, episode_count, synced_at)
                VALUES (:idx, :sn, :st, :lid, :ln, :lt, :ti, :ec, :now)
            ");
            foreach ($libraries as $lib) {
                $insLib->bindValue(':idx',  $srv['index'],                          SQLITE3_INTEGER);
                $insLib->bindValue(':sn',   $this->sanitizeStr($srv['name']),        SQLITE3_TEXT);
                $insLib->bindValue(':st',   $this->sanitizeStr($srv['type']),        SQLITE3_TEXT);
                $insLib->bindValue(':lid',  $this->sanitizeStr($lib['id'] ?? ''),    SQLITE3_TEXT);
                $insLib->bindValue(':ln',   $this->sanitizeStr($lib['name'] ?? ''),  SQLITE3_TEXT);
                $insLib->bindValue(':lt',   $this->sanitizeStr($lib['type'] ?? ''),  SQLITE3_TEXT);
                $insLib->bindValue(':ti',   (int)($lib['total_items'] ?? 0),         SQLITE3_INTEGER);
                $insLib->bindValue(':ec',   (int)($lib['episode_count'] ?? 0),       SQLITE3_INTEGER);
                $insLib->bindValue(':now',  $now,                                    SQLITE3_INTEGER);
                $insLib->execute();
                $insLib->reset();
            }
            $insLib->close();

            // Insert recently added
            if (is_array($recentItems)) {
                $insRecent = $db->prepare("
                    INSERT INTO recently_added
                        (server_index, server_name, server_type, title, media_type,
                         library_name, type_label, added_at, synced_at)
                    VALUES (:idx, :sn, :st, :title, :mt, :ln, :tl, :at, :now)
                ");
                foreach ($recentItems as $item) {
                    $insRecent->bindValue(':idx',   $srv['index'],                              SQLITE3_INTEGER);
                    $insRecent->bindValue(':sn',    $this->sanitizeStr($srv['name']),            SQLITE3_TEXT);
                    $insRecent->bindValue(':st',    $this->sanitizeStr($srv['type']),            SQLITE3_TEXT);
                    $insRecent->bindValue(':title', $this->sanitizeStr($item['title'] ?? ''),    SQLITE3_TEXT);
                    $insRecent->bindValue(':mt',    $this->sanitizeStr($item['media_type'] ?? ''), SQLITE3_TEXT);
                    $insRecent->bindValue(':ln',    $this->sanitizeStr($item['library'] ?? ''),  SQLITE3_TEXT);
                    $insRecent->bindValue(':tl',    $this->sanitizeStr($item['type_label'] ?? '-'), SQLITE3_TEXT);
                    $insRecent->bindValue(':at',    (int)($item['added_at'] ?? 0),               SQLITE3_INTEGER);
                    $insRecent->bindValue(':now',   $now,                                        SQLITE3_INTEGER);
                    $insRecent->execute();
                    $insRecent->reset();
                }
                $insRecent->close();
            }

            $db->exec('COMMIT');
            $synced++;
        }

        return $synced;
    }

    /**
     * Map library_type to media_type values used in watch_history.
     */
    private function libraryTypeToMediaTypes(string $libType): array
    {
        return match($libType) {
            'movie'  => ['movie'],
            'show'   => ['episode'],
            'artist', 'music' => ['track'],
            default  => [],
        };
    }

    // ── Per-server library fetchers ──────────────────────────────────────

    private function fetchServerLibraries(array $srv): ?array
    {
        return match($srv['type']) {
            'plex'     => $this->fetchPlexLibraries($srv),
            'jellyfin' => $this->fetchJfLibraries($srv),
            'emby'     => $this->fetchJfLibraries($srv), // same API
            default    => null,
        };
    }

    private function fetchServerRecentlyAdded(array $srv): ?array
    {
        return match($srv['type']) {
            'plex'     => $this->fetchPlexRecentlyAdded($srv),
            'jellyfin' => $this->fetchJfRecentlyAdded($srv),
            'emby'     => $this->fetchJfRecentlyAdded($srv),
            default    => null,
        };
    }

    // ── Plex library fetch ───────────────────────────────────────────────

    private function fetchPlexLibraries(array $srv): ?array
    {
        $url = $srv['url'] . '/library/sections';
        [$body, $code, $err] = $this->httpGet($url, [
            'X-Plex-Token' => $srv['token'],
            'Accept'        => 'application/json',
        ]);
        if ($body === null || $code < 200 || $code >= 300) return null;

        $data = @json_decode($body, true);
        $dirs = $data['MediaContainer']['Directory'] ?? [];
        if (!is_array($dirs)) return null;

        $libraries = [];
        foreach ($dirs as $dir) {
            $secId   = (string)($dir['key'] ?? '');
            $secType = (string)($dir['type'] ?? '');
            $secName = (string)($dir['title'] ?? '');

            if ($secId === '') continue;
            // Validate section ID (numeric only for Plex)
            if (!ctype_digit($secId)) continue;

            $libType = match($secType) {
                'movie'  => 'movie',
                'show'   => 'show',
                'artist' => 'music',
                'photo'  => 'photo',
                default  => $secType,
            };

            // Fetch count (lightweight, no item data)
            $countUrl = $srv['url'] . '/library/sections/' . $secId . '/all?X-Plex-Container-Size=0&X-Plex-Container-Start=0';
            [$cBody, $cCode] = $this->httpGet($countUrl, [
                'X-Plex-Token' => $srv['token'],
                'Accept'        => 'application/json',
            ]);
            $totalItems = 0;
            $episodeCount = 0;
            if ($cBody !== null && $cCode >= 200 && $cCode < 300) {
                $cData = @json_decode($cBody, true);
                $totalItems = (int)($cData['MediaContainer']['totalSize'] ?? 0);
            }

            // For TV shows, also get episode count
            if ($secType === 'show' && $totalItems > 0) {
                $epUrl = $srv['url'] . '/library/sections/' . $secId . '/all?type=4&X-Plex-Container-Size=0&X-Plex-Container-Start=0';
                [$eBody, $eCode] = $this->httpGet($epUrl, [
                    'X-Plex-Token' => $srv['token'],
                    'Accept'        => 'application/json',
                ]);
                if ($eBody !== null && $eCode >= 200 && $eCode < 300) {
                    $eData = @json_decode($eBody, true);
                    $episodeCount = (int)($eData['MediaContainer']['totalSize'] ?? 0);
                }
            }

            $libraries[] = [
                'id'            => $secId,
                'name'          => $secName,
                'type'          => $libType,
                'total_items'   => $totalItems,
                'episode_count' => $episodeCount,
            ];
        }

        return $libraries;
    }

    private function fetchPlexRecentlyAdded(array $srv): ?array
    {
        $url = $srv['url'] . '/library/recentlyAdded?X-Plex-Container-Size=10&X-Plex-Container-Start=0';
        [$body, $code, $err] = $this->httpGet($url, [
            'X-Plex-Token' => $srv['token'],
            'Accept'        => 'application/json',
        ]);
        if ($body === null || $code < 200 || $code >= 300) return null;

        $data  = @json_decode($body, true);
        $items = $data['MediaContainer']['Metadata'] ?? [];
        if (!is_array($items)) return null;

        $result = [];
        foreach ($items as $item) {
            $type = (string)($item['type'] ?? '');
            $mediaType = match($type) {
                'movie'   => 'movie',
                'episode' => 'episode',
                'season'  => 'episode',
                'track'   => 'track',
                'album'   => 'album',
                default   => $type,
            };

            $typeLabel = '-';
            if ($type === 'season') {
                $title = (string)($item['parentTitle'] ?? $item['title'] ?? '');
                $sNum = (int)($item['index'] ?? 0);
                $leafCount = (int)($item['leafCount'] ?? 0);
                $typeLabel = $sNum > 0
                    ? 'S' . str_pad((string)$sNum, 2, '0', STR_PAD_LEFT)
                      . ($leafCount > 0 ? ' (' . $leafCount . 'ep)' : '')
                    : '-';
            } elseif ($type === 'episode') {
                $title = (string)($item['grandparentTitle'] ?? $item['title'] ?? '');
                $sNum = (int)($item['parentIndex'] ?? 0);
                $eNum = (int)($item['index'] ?? 0);
                $typeLabel = $sNum > 0 || $eNum > 0
                    ? 'S' . str_pad((string)$sNum, 2, '0', STR_PAD_LEFT) . 'E' . str_pad((string)$eNum, 2, '0', STR_PAD_LEFT)
                    : '-';
            } elseif ($type === 'track') {
                $title = (string)($item['grandparentTitle'] ?? $item['title'] ?? '');
            } else {
                $title = (string)($item['title'] ?? '');
            }

            $result[] = [
                'title'      => $title,
                'media_type' => $mediaType,
                'type_label' => $typeLabel,
                'library'    => (string)($item['librarySectionTitle'] ?? ''),
                'added_at'   => (int)($item['addedAt'] ?? 0),
            ];
        }

        return $result;
    }

    // ── Jellyfin/Emby library fetch ──────────────────────────────────────

    private function fetchJfLibraries(array $srv): ?array
    {
        $url = $srv['url'] . '/Library/VirtualFolders';
        [$body, $code, $err] = $this->httpGet($url, [
            'X-Emby-Token'         => $srv['token'],
            'X-MediaBrowser-Token' => $srv['token'],
            'Accept'                => 'application/json',
        ]);
        if ($body === null || $code < 200 || $code >= 300) return null;

        $folders = @json_decode($body, true);
        if (!is_array($folders)) return null;

        $libraries = [];
        foreach ($folders as $folder) {
            $libId   = (string)($folder['ItemId'] ?? '');
            $libName = (string)($folder['Name'] ?? '');
            $collType = strtolower((string)($folder['CollectionType'] ?? ''));

            if ($libId === '' || $libName === '') continue;
            // Validate ID: alphanumeric + hyphens only
            if (!preg_match('/^[a-zA-Z0-9\-]+$/', $libId)) continue;

            $libType = match($collType) {
                'movies'      => 'movie',
                'tvshows'     => 'show',
                'music'       => 'music',
                'photos'      => 'photo',
                'homevideos'  => 'photo',
                default       => $collType,
            };

            // Fetch item count
            $countUrl = $srv['url'] . '/Items?ParentId=' . rawurlencode($libId)
                      . '&Recursive=true&Limit=0&Fields=BasicSyncInfo';
            [$cBody, $cCode] = $this->httpGet($countUrl, [
                'X-Emby-Token'         => $srv['token'],
                'X-MediaBrowser-Token' => $srv['token'],
                'Accept'                => 'application/json',
            ]);
            $totalItems = 0;
            $episodeCount = 0;
            if ($cBody !== null && $cCode >= 200 && $cCode < 300) {
                $cData = @json_decode($cBody, true);
                $totalItems = (int)($cData['TotalRecordCount'] ?? 0);
            }

            // For TV shows, get episode count separately
            if ($libType === 'show' && $totalItems > 0) {
                $epUrl = $srv['url'] . '/Items?ParentId=' . rawurlencode($libId)
                       . '&Recursive=true&IncludeItemTypes=Episode&Limit=0';
                [$eBody, $eCode] = $this->httpGet($epUrl, [
                    'X-Emby-Token'         => $srv['token'],
                    'X-MediaBrowser-Token' => $srv['token'],
                    'Accept'                => 'application/json',
                ]);
                if ($eBody !== null && $eCode >= 200 && $eCode < 300) {
                    $eData = @json_decode($eBody, true);
                    $episodeCount = (int)($eData['TotalRecordCount'] ?? 0);
                }
            }

            $libraries[] = [
                'id'            => $libId,
                'name'          => $libName,
                'type'          => $libType,
                'total_items'   => $totalItems,
                'episode_count' => $episodeCount,
            ];
        }

        return $libraries;
    }

    private function fetchJfRecentlyAdded(array $srv): ?array
    {
        $url = $srv['url'] . '/Items?SortBy=DateCreated&SortOrder=Descending&Limit=10'
             . '&Recursive=true&IncludeItemTypes=Movie,Episode,Season,Audio,MusicAlbum'
             . '&Fields=DateCreated';
        [$body, $code, $err] = $this->httpGet($url, [
            'X-Emby-Token'         => $srv['token'],
            'X-MediaBrowser-Token' => $srv['token'],
            'Accept'                => 'application/json',
        ]);
        if ($body === null || $code < 200 || $code >= 300) return null;

        $data  = @json_decode($body, true);
        $items = $data['Items'] ?? [];
        if (!is_array($items)) return null;

        $result = [];
        foreach ($items as $item) {
            $type = (string)($item['Type'] ?? '');
            $mediaType = match($type) {
                'Movie'      => 'movie',
                'Episode'    => 'episode',
                'Season'     => 'episode',
                'Audio'      => 'track',
                'MusicAlbum' => 'album',
                default      => strtolower($type),
            };

            $typeLabel = '-';
            if ($type === 'Season') {
                $title = (string)($item['SeriesName'] ?? $item['Name'] ?? '');
                $sNum = (int)($item['IndexNumber'] ?? 0);
                $typeLabel = $sNum > 0
                    ? 'S' . str_pad((string)$sNum, 2, '0', STR_PAD_LEFT)
                    : '-';
            } elseif ($type === 'Episode') {
                $title = (string)($item['SeriesName'] ?? $item['Name'] ?? '');
                $sNum = (int)($item['ParentIndexNumber'] ?? 0);
                $eNum = (int)($item['IndexNumber'] ?? 0);
                $typeLabel = $sNum > 0 || $eNum > 0
                    ? 'S' . str_pad((string)$sNum, 2, '0', STR_PAD_LEFT) . 'E' . str_pad((string)$eNum, 2, '0', STR_PAD_LEFT)
                    : '-';
            } elseif ($type === 'Audio') {
                $title = (string)($item['AlbumArtist'] ?? $item['Name'] ?? '');
            } else {
                $title = (string)($item['Name'] ?? '');
            }

            $addedAt = 0;
            $dateStr = (string)($item['DateCreated'] ?? '');
            if ($dateStr !== '') {
                $ts = @strtotime($dateStr);
                if ($ts !== false) $addedAt = $ts;
            }

            $result[] = [
                'title'      => $title,
                'media_type' => $mediaType,
                'type_label' => $typeLabel,
                'library'    => (string)($item['ParentId'] ?? ''),
                'added_at'   => $addedAt,
            ];
        }

        return $result;
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

        // Try /Sessions/{id}/Playing/Stop (Jellyfin + Emby)
        $stopUrl = $url . '/Sessions/' . rawurlencode($sessionId) . '/Playing/Stop?api_key=' . urlencode($token);
        [$body, $httpCode, $err] = $this->httpPostJson($stopUrl, '{}', [
            'X-Emby-Token'         => $token,
            'X-MediaBrowser-Token' => $token,
        ]);
        if ($err !== null) return ['ok' => false, 'error' => $err];
        if ($httpCode >= 200 && $httpCode < 300) return ['ok' => true];

        // Fallback: /Sessions/{id}/Command/Stop (some Emby versions)
        if ($httpCode === 404) {
            $cmdUrl = $url . '/Sessions/' . rawurlencode($sessionId) . '/Command/Stop?api_key=' . urlencode($token);
            [$body, $httpCode, $err] = $this->httpPostJson($cmdUrl, '{}', [
                'X-Emby-Token'         => $token,
                'X-MediaBrowser-Token' => $token,
            ]);
            if ($err !== null) return ['ok' => false, 'error' => $err];
            if ($httpCode >= 200 && $httpCode < 300) return ['ok' => true];
        }

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

        [$body, $code, $err] = $this->httpGet($pollUrl, $this->plexHeaders(), true);
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

    // Route to correct discovery method based on server type
    private function tryRediscover(array $srv): string
    {
        return match($srv['type']) {
            'plex'             => $this->plexRediscoverUrl($srv),
            'jellyfin', 'emby' => $this->isLocalUrl($srv['url'] ?? '')
                                    ? $this->dockerDiscoverUrl($srv)
                                    : '',  // remote JF/Emby cannot be rediscovered
            default            => '',
        };
    }

    private function plexRediscoverUrl(array $srv): string
    {
        $token = $srv['token'] ?? '';
        $name  = $srv['name']  ?? '';
        if ($token === '') return '';

        $currentIsLocal = $this->isLocalUrl($srv['url'] ?? '');

        foreach ($this->plexDiscover($token) as $s) {
            if (trim($s['name']) !== trim($name)) continue;
            // Filter: no relay, and match current URL type (local↔local, remote↔remote)
            $conns = array_filter($s['connections'], function($c) use ($currentIsLocal) {
                if ($c['relay']) return false;
                return $currentIsLocal ? $c['local'] : !$c['local'];
            });
            // Sort: prefer local first (for local), prefer non-local first (for remote)
            usort($conns, fn($a, $b) => $currentIsLocal
                ? (int)$b['local'] - (int)$a['local']
                : (int)$a['local'] - (int)$b['local']
            );
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
            $this->plexHeaders($accountToken),
            true
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

    // ── Docker stats (lightweight independent endpoint) ────────────────────

    private function replyGetDockerStats(): void
    {
        if (function_exists('session_write_close')) @session_write_close();
        $this->securityHeaders();
        $cfg     = $this->loadCfg();
        $servers = $this->getEnabledServers($cfg);
        $stats   = $this->getDockerStats($servers);
        $this->json(['docker_stats' => $stats]);
    }

    // ── Docker container resource stats ────────────────────────────────────

    private function getDockerStats(array $servers): array
    {
        if (!file_exists(self::DOCKER_SOCKET)) return [];

        // 1. List running containers
        $ch = curl_init('http://localhost/containers/json');
        curl_setopt_array($ch, [
            CURLOPT_UNIX_SOCKET_PATH => self::DOCKER_SOCKET,
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_TIMEOUT          => 2,
            CURLOPT_CONNECTTIMEOUT   => 1,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        $containers = @json_decode($body ?: '', true);
        if (!is_array($containers)) return [];

        // 2. Match containers to servers by port
        // 2. Match containers to servers by port OR by name/image keywords
        $matched = [];
        $typeKeywords = [
            'plex'     => ['plex'],
            'jellyfin' => ['jellyfin'],
            'emby'     => ['emby'],
        ];

        foreach ($servers as $srv) {
            $srvType = strtolower($srv['type'] ?? '');
            $port = (int)(parse_url($srv['url'] ?? '', PHP_URL_PORT) ?: 0);
            $portMatched = false;

            // Strategy 1: Match by port (works for bridge networking)
            if ($port > 0) {
                foreach ($containers as $c) {
                    foreach ($c['Ports'] ?? [] as $p) {
                        if ((int)($p['PrivatePort'] ?? 0) === $port || (int)($p['PublicPort'] ?? 0) === $port) {
                            $cId   = substr($c['Id'] ?? '', 0, 12);
                            $cName = ltrim(($c['Names'][0] ?? ''), '/');
                            if ($cId !== '' && !isset($matched[$cId])) {
                                $matched[$cId] = [
                                    'name'        => $cName,
                                    'server_name' => $srv['name'],
                                    'server_type' => $srvType,
                                ];
                            }
                            $portMatched = true;
                            break 2;
                        }
                    }
                }
            }

            // Strategy 2: Match by container name or image (fallback for host/macvlan networking)
            if (!$portMatched && isset($typeKeywords[$srvType])) {
                foreach ($containers as $c) {
                    $cId = substr($c['Id'] ?? '', 0, 12);
                    if ($cId === '' || isset($matched[$cId])) continue;
                    $cName  = strtolower(ltrim(($c['Names'][0] ?? ''), '/'));
                    $cImage = strtolower($c['Image'] ?? '');
                    foreach ($typeKeywords[$srvType] as $kw) {
                        if (strpos($cName, $kw) !== false || strpos($cImage, $kw) !== false) {
                            $matched[$cId] = [
                                'name'        => ltrim(($c['Names'][0] ?? ''), '/'),
                                'server_name' => $srv['name'],
                                'server_type' => $srvType,
                            ];
                            break 2;
                        }
                    }
                }
            }
        }

        if (empty($matched)) return [];

        // 3. Get stats for each matched container in PARALLEL (one-shot, no stream)
        $mh      = curl_multi_init();
        $handles = [];

        foreach ($matched as $cId => $info) {
            if (!preg_match('/^[a-f0-9]+$/i', $cId)) continue;
            $ch = curl_init('http://localhost/containers/' . $cId . '/stats?stream=false');
            curl_setopt_array($ch, [
                CURLOPT_UNIX_SOCKET_PATH => self::DOCKER_SOCKET,
                CURLOPT_RETURNTRANSFER   => true,
                CURLOPT_TIMEOUT          => 4,
                CURLOPT_CONNECTTIMEOUT   => 1,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = ['ch' => $ch, 'id' => $cId, 'info' => $info];
        }

        if (empty($handles)) {
            curl_multi_close($mh);
            return [];
        }

        // Execute all docker stats in parallel
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) curl_multi_select($mh, 0.1);
        } while ($active && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $h) {
            $sBody = curl_multi_getcontent($h['ch']);
            curl_multi_remove_handle($mh, $h['ch']);
            curl_close($h['ch']);
            $st = @json_decode($sBody ?: '', true);
            if (!is_array($st)) continue;

            // CPU %
            $cpuDelta = ((int)($st['cpu_stats']['cpu_usage']['total_usage'] ?? 0))
                      - ((int)($st['precpu_stats']['cpu_usage']['total_usage'] ?? 0));
            $sysDelta = ((int)($st['cpu_stats']['system_cpu_usage'] ?? 0))
                      - ((int)($st['precpu_stats']['system_cpu_usage'] ?? 0));
            $numCpus  = count($st['cpu_stats']['cpu_usage']['percpu_usage'] ?? []);
            if ($numCpus === 0) $numCpus = (int)($st['cpu_stats']['online_cpus'] ?? 1);
            $cpuPct   = ($sysDelta > 0)
                ? round(($cpuDelta / $sysDelta) * 100, 1)
                : 0.0;

            // RAM
            $memUsage = (int)($st['memory_stats']['usage'] ?? 0);
            $memCache = (int)($st['memory_stats']['stats']['cache'] ?? $st['memory_stats']['stats']['inactive_file'] ?? 0);
            $memUsed  = max(0, $memUsage - $memCache);
            $memLimit = (int)($st['memory_stats']['limit'] ?? 0);

            $results[] = [
                'container'   => $h['info']['name'],
                'server_name' => $h['info']['server_name'],
                'server_type' => $h['info']['server_type'],
                'cpu_pct'     => $cpuPct,
                'mem_used'    => $memUsed,
                'mem_limit'   => $memLimit,
            ];
        }
        curl_multi_close($mh);
        return $results;
    }

    // ── Docker socket discovery (Jellyfin/Emby local containers) ────────

    private function dockerDiscoverUrl(array $srv): string
    {
        $currentUrl = $srv['url'] ?? '';
        if (!$this->isLocalUrl($currentUrl)) return '';  // only for local URLs
        if (!file_exists(self::DOCKER_SOCKET)) return '';

        $port = (int)(parse_url($currentUrl, PHP_URL_PORT) ?: 0);
        $scheme = parse_url($currentUrl, PHP_URL_SCHEME) ?: 'http';
        if ($port <= 0) return '';

        // Query Docker Engine API via Unix socket
        $ch = curl_init('http://localhost/containers/json');
        curl_setopt_array($ch, [
            CURLOPT_UNIX_SOCKET_PATH => self::DOCKER_SOCKET,
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_TIMEOUT          => 5,
            CURLOPT_CONNECTTIMEOUT   => 3,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code !== 200) return '';
        $containers = @json_decode($body, true);
        if (!is_array($containers)) return '';

        // Find container exposing our port
        foreach ($containers as $c) {
            $networks = $c['NetworkSettings']['Networks'] ?? [];
            $ports    = $c['Ports'] ?? [];

            // Check if this container exposes the matching port
            $matchPort = false;
            foreach ($ports as $p) {
                $privPort = (int)($p['PrivatePort'] ?? 0);
                $pubPort  = (int)($p['PublicPort']  ?? 0);
                if ($privPort === $port || $pubPort === $port) {
                    $matchPort = true;
                    break;
                }
            }
            if (!$matchPort) continue;

            // Get the container IP from any custom network, or the default
            foreach ($networks as $net) {
                $ip = (string)($net['IPAddress'] ?? '');
                if ($ip !== '' && $ip !== '0.0.0.0') {
                    return $scheme . '://' . $ip . ':' . $port;
                }
            }
        }
        return '';
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
    private function httpGet(string $url, array $headers = [], bool $forceVerify = false): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)
            || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return [null, 0, 'Invalid or disallowed URL'];
        }
        if (!function_exists('curl_init')) return [null, 0, 'cURL not available'];

        $verify = $forceVerify || $this->verifySsl;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_HTTPHEADER     => $this->buildCurlHeaders($headers),
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch) ?: null;
        curl_close($ch);
        return [$body === false ? null : $body, $code, $err];
    }

    private function httpPost(string $url, array $postData, array $headers = [], bool $forceVerify = false): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return [null, 0, 'Invalid URL'];
        if (!function_exists('curl_init')) return [null, 0, 'cURL not available'];

        $verify = $forceVerify || $this->verifySsl;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_HTTPHEADER     => $this->buildCurlHeaders($headers),
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch) ?: null;
        curl_close($ch);
        return [$body === false ? null : $body, $code, $err];
    }

    private function httpPostJson(string $url, string $jsonBody, array $headers = []): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return [null, 0, 'Invalid URL'];
        if (!function_exists('curl_init')) return [null, 0, 'cURL not available'];

        $headers['Content-Type'] = 'application/json';
        $verify = $this->verifySsl;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
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
        return trim($s);
    }
}

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    (new StreamViewerEndpoint())->run();
}
