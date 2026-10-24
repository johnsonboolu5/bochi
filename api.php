<?php
/*
 * Bochi private accounts API.
 *
 * Do not place real credentials, invite codes, password hashes, or a database
 * path for a real account in this file. Runtime secrets belong in a private
 * config file outside the web root. See DESIGN.md in this folder.
 */

declare(strict_types=1);

const BOCHI_PLAYERS = ['bolu', 'chihiro'];
const BOCHI_PLAYER_TIMEZONES = [
    'bolu' => 'America/Chicago',
    'chihiro' => 'Asia/Tokyo',
];
const BOCHI_PLAYER_NAMES = [
    'bolu' => 'Boru-san',
    'chihiro' => 'Chihiro-san',
];
const BOCHI_SESSION_TTL = 2592000; // 30 days.
const BOCHI_MAX_BODY_BYTES = 65536;
const BOCHI_MAX_XP_EVENT = 100;
const BOCHI_MAX_XP_TOTAL = 1000000;

final class BochiHttpError extends Exception
{
    /** @var int */
    public $status;
    /** @var string */
    public $errorCode;

    public function __construct(int $status, string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
    }
}

function bochi_fail(int $status, string $code, string $message): void
{
    throw new BochiHttpError($status, $code, $message);
}

function bochi_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $base = dirname(__DIR__, 2);
    $config = [
        'db_path' => $base . '/private/bochi/bochi.sqlite',
        'invite_pepper' => '',
        'setup_token_hash' => '',
    ];

    $configFile = getenv('BOCHI_CONFIG');
    if (!$configFile) {
        $configFile = $base . '/private/bochi/config.php';
    }
    if (is_string($configFile) && $configFile !== '' && is_file($configFile)) {
        $loaded = include $configFile;
        if (is_array($loaded)) {
            $config = array_merge($config, $loaded);
        }
    }

    $envDb = getenv('BOCHI_DB_PATH');
    if (is_string($envDb) && $envDb !== '') {
        $config['db_path'] = $envDb;
    }
    $envPepper = getenv('BOCHI_INVITE_PEPPER');
    if (is_string($envPepper) && $envPepper !== '') {
        $config['invite_pepper'] = $envPepper;
    }
    $envSetupHash = getenv('BOCHI_SETUP_TOKEN_HASH');
    if (is_string($envSetupHash) && $envSetupHash !== '') {
        $config['setup_token_hash'] = $envSetupHash;
    }

    return $config;
}

function bochi_now_iso(): string
{
    return gmdate('Y-m-d\TH:i:sP');
}

function bochi_now_ts(): int
{
    return time();
}

function bochi_iso_from_ts(int $ts): string
{
    return gmdate('Y-m-d\TH:i:sP', $ts);
}

function bochi_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function bochi_error_response(BochiHttpError $error): void
{
    bochi_json_response([
        'ok' => false,
        'error' => [
            'code' => $error->errorCode,
            'message' => $error->getMessage(),
        ],
    ], $error->status);
}

function bochi_db(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }

    $config = bochi_config();
    $path = (string)($config['db_path'] ?? '');
    if ($path === '') {
        bochi_fail(500, 'server_not_configured', 'The Bochi database is not configured.');
    }

    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (!is_dir($dir)) {
        bochi_fail(500, 'database_unavailable', 'The Bochi database is unavailable.');
    }

    try {
        $db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA busy_timeout = 5000');
        bochi_ensure_schema($db);
    } catch (Throwable $e) {
        error_log('Bochi database error: ' . $e->getMessage());
        bochi_fail(500, 'database_unavailable', 'The Bochi database is unavailable.');
    }

    return $db;
}

function bochi_ensure_schema(PDO $db): void
{
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_key TEXT NOT NULL UNIQUE CHECK (player_key IN ('bolu', 'chihiro')),
    email TEXT NOT NULL COLLATE NOCASE UNIQUE,
    display_name TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    timezone TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    disabled_at TEXT NULL
)
SQL
    );
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS invites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_key TEXT NOT NULL CHECK (player_key IN ('bolu', 'chihiro')),
    code_hash TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_by_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    used_at TEXT NULL,
    revoked_at TEXT NULL
)
SQL
    );
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS sessions (
    token_hash TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    ip_hash TEXT NULL,
    user_agent_hash TEXT NULL
)
SQL
    );
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at)');
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS progress (
    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    xp INTEGER NOT NULL DEFAULT 0 CHECK (xp >= 0),
    lessons_json TEXT NOT NULL DEFAULT '{}',
    checkpoints_json TEXT NOT NULL DEFAULT '{}',
    last_unlock TEXT NOT NULL DEFAULT '',
    review_json TEXT NOT NULL DEFAULT '{}',
    scripts_json TEXT NOT NULL DEFAULT '{}',
    practice_json TEXT NOT NULL DEFAULT '{}',
    stats_json TEXT NOT NULL DEFAULT '{}',
    version INTEGER NOT NULL DEFAULT 0 CHECK (version >= 0),
    updated_at TEXT NOT NULL
)
SQL
    );
    $cols = $db->query('PRAGMA table_info(progress)')->fetchAll();
    $colNames = [];
    foreach ($cols as $col) { $colNames[] = (string)($col['name'] ?? ''); }
    if (!in_array('scripts_json', $colNames, true)) { $db->exec("ALTER TABLE progress ADD COLUMN scripts_json TEXT NOT NULL DEFAULT '{}'"); }
    if (!in_array('practice_json', $colNames, true)) { $db->exec("ALTER TABLE progress ADD COLUMN practice_json TEXT NOT NULL DEFAULT '{}'"); }
    if (!in_array('stats_json', $colNames, true)) { $db->exec("ALTER TABLE progress ADD COLUMN stats_json TEXT NOT NULL DEFAULT '{}'"); }
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS meetings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    starts_at TEXT NOT NULL,
    duration_min INTEGER NOT NULL DEFAULT 45,
    status TEXT NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled', 'held', 'cancelled')),
    created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
)
SQL
    );
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sessions_starts ON meetings(starts_at)');
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS push_subs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    endpoint TEXT NOT NULL UNIQUE,
    p256dh TEXT NOT NULL,
    auth TEXT NOT NULL,
    created_at TEXT NOT NULL
)
SQL
    );
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS reminder_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    lead_days INTEGER NOT NULL,
    channels TEXT NOT NULL DEFAULT '',
    sent_at TEXT NOT NULL,
    UNIQUE (session_id, user_id, lead_days)
)
SQL
    );
    $ucols = $db->query('PRAGMA table_info(users)')->fetchAll();
    $uNames = [];
    foreach ($ucols as $col) { $uNames[] = (string)($col['name'] ?? ''); }
    if (!in_array('notify_leads', $uNames, true)) { $db->exec("ALTER TABLE users ADD COLUMN notify_leads TEXT NOT NULL DEFAULT '[1]'"); }
    if (!in_array('notify_email', $uNames, true)) { $db->exec("ALTER TABLE users ADD COLUMN notify_email INTEGER NOT NULL DEFAULT 1"); }
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS solo_daily (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    local_date TEXT NOT NULL,
    xp INTEGER NOT NULL DEFAULT 0 CHECK (xp >= 0),
    updated_at TEXT NOT NULL,
    PRIMARY KEY (user_id, local_date)
)
SQL
    );
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS xp_events (
    event_id TEXT PRIMARY KEY,
    actor_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    target_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    delta INTEGER NOT NULL CHECK (delta > 0),
    solo INTEGER NOT NULL DEFAULT 0 CHECK (solo IN (0, 1)),
    source TEXT NOT NULL,
    local_date TEXT NULL,
    last_unlock TEXT NULL,
    created_at TEXT NOT NULL
)
SQL
    );
    $db->exec('CREATE INDEX IF NOT EXISTS idx_xp_events_target ON xp_events(target_user_id, created_at)');
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    ip_hash TEXT NOT NULL,
    attempted_at TEXT NOT NULL,
    success INTEGER NOT NULL DEFAULT 0 CHECK (success IN (0, 1))
)
SQL
    );
    $db->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_pair ON login_attempts(email, ip_hash, attempted_at)');
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS recordings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_player TEXT NOT NULL CHECK (owner_player IN ('bolu', 'chihiro')),
    title TEXT NOT NULL,
    kind TEXT NOT NULL CHECK (kind IN ('upload', 'link', 'summary')),
    session_date TEXT NULL,
    note TEXT NOT NULL DEFAULT '',
    external_url TEXT NULL,
    stored_name TEXT NULL,
    file_size INTEGER NULL,
    mime TEXT NULL,
    created_at TEXT NOT NULL
)
SQL
    );
    $db->exec('CREATE INDEX IF NOT EXISTS idx_recordings_date ON recordings(session_date, id)');
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT '',
    updated_by TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL
)
SQL
    );
}

function bochi_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    $forwarded = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return $forwarded === 'https';
}

function bochi_session_cookie_name(): string
{
    return bochi_is_https() ? '__Host-bochi_session' : 'bochi_session';
}

function bochi_base64url_encode(string $binary): string
{
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

function bochi_secret_hash(string $purpose, string $value): string
{
    $pepper = (string)(bochi_config()['invite_pepper'] ?? '');
    if ($pepper !== '') {
        return hash_hmac('sha256', $purpose . ':' . $value, $pepper);
    }
    return hash('sha256', $purpose . ':' . $value);
}

function bochi_client_ip_hash(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip === '' ? '' : bochi_secret_hash('ip', $ip);
}

function bochi_user_agent_hash(): string
{
    $agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return $agent === '' ? '' : hash('sha256', $agent);
}

function bochi_request_body(): array
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET' || $method === 'HEAD') {
        return [];
    }

    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if ($contentType !== '' && strpos($contentType, 'application/json') === false) {
        bochi_fail(415, 'json_required', 'Requests must use JSON.');
    }
    if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > BOCHI_MAX_BODY_BYTES) {
        bochi_fail(413, 'request_too_large', 'The request is too large.');
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > BOCHI_MAX_BODY_BYTES) {
        bochi_fail(413, 'request_too_large', 'The request is too large.');
    }
    if (trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        bochi_fail(422, 'invalid_json', 'The request body must be a JSON object.');
    }
    return $decoded;
}

function bochi_require_write_guard(): void
{
    if ((string)($_SERVER['HTTP_X_BOCHI_REQUEST'] ?? '') !== '1') {
        bochi_fail(403, 'request_not_allowed', 'This request is not allowed.');
    }

    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $requestHost = (string)($_SERVER['HTTP_HOST'] ?? '');
        $requestHost = preg_replace('/:\\d+$/', '', $requestHost);
        if (!is_string($originHost) || !is_string($requestHost) || !hash_equals(strtolower($requestHost), strtolower($originHost))) {
            bochi_fail(403, 'origin_not_allowed', 'This request origin is not allowed.');
        }
    }
}

function bochi_string($value, string $field, int $min, int $max, bool $trim = true): string
{
    if (!is_string($value)) {
        bochi_fail(422, 'invalid_' . $field, 'The ' . $field . ' field is invalid.');
    }
    if ($trim) {
        $value = trim($value);
    }
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length < $min || $length > $max) {
        bochi_fail(422, 'invalid_' . $field, 'The ' . $field . ' field is invalid.');
    }
    return $value;
}

function bochi_optional_string($value, string $field, int $max): ?string
{
    if ($value === null) {
        return null;
    }
    $text = bochi_string($value, $field, 0, $max);
    return $text === '' ? null : $text;
}

function bochi_int($value, string $field, int $min, int $max): int
{
    if (is_int($value)) {
        $number = $value;
    } elseif (is_float($value) && floor($value) === $value) {
        $number = (int)$value;
    } elseif (is_string($value) && preg_match('/^-?\\d+$/', $value)) {
        $number = (int)$value;
    } else {
        bochi_fail(422, 'invalid_' . $field, 'The ' . $field . ' field is invalid.');
    }
    if ($number < $min || $number > $max) {
        bochi_fail(422, 'invalid_' . $field, 'The ' . $field . ' field is invalid.');
    }
    return $number;
}

function bochi_bool($value, string $field): bool
{
    if (!is_bool($value)) {
        bochi_fail(422, 'invalid_' . $field, 'The ' . $field . ' field is invalid.');
    }
    return $value;
}

function bochi_normalize_email($value): string
{
    $email = bochi_string($value, 'email', 3, 254);
    $email = function_exists('mb_strtolower') ? mb_strtolower($email, 'UTF-8') : strtolower($email);
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        bochi_fail(422, 'invalid_email', 'Enter a valid email address.');
    }
    return $email;
}

function bochi_validate_password($value, string $email): string
{
    if (!is_string($value)) {
        bochi_fail(422, 'invalid_password', 'Choose a password between 12 and 128 characters.');
    }
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length < 12 || $length > 128) {
        bochi_fail(422, 'invalid_password', 'Choose a password between 12 and 128 characters.');
    }
    if (strtolower($value) === strtolower($email)) {
        bochi_fail(422, 'invalid_password', 'The password must not be the email address.');
    }
    return $value;
}

function bochi_password_algorithm()
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function bochi_fetch_user_by_id(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function bochi_fetch_user_by_player(PDO $db, string $playerKey): ?array
{
    $stmt = $db->prepare('SELECT * FROM users WHERE player_key = ? LIMIT 1');
    $stmt->execute([$playerKey]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function bochi_public_account(array $user): array
{
    return [
        'id' => (int)$user['id'],
        'playerKey' => (string)$user['player_key'],
        'email' => (string)$user['email'],
        'displayName' => (string)$user['display_name'],
        'timezone' => (string)$user['timezone'],
    ];
}

function bochi_other_player(string $playerKey): string
{
    return $playerKey === 'bolu' ? 'chihiro' : 'bolu';
}

function bochi_public_partner(PDO $db, array $account): array
{
    $other = bochi_other_player((string)$account['player_key']);
    $partner = bochi_fetch_user_by_player($db, $other);
    if ($partner === null) {
        return [
            'playerKey' => $other,
            'displayName' => BOCHI_PLAYER_NAMES[$other],
            'registered' => false,
        ];
    }
    return [
        'playerKey' => $other,
        'displayName' => (string)$partner['display_name'],
        'registered' => true,
    ];
}

function bochi_create_progress_row(PDO $db, int $userId): void
{
    $stmt = $db->prepare('INSERT OR IGNORE INTO progress (user_id, xp, lessons_json, checkpoints_json, last_unlock, review_json, version, updated_at) VALUES (?, 0, ?, ?, ?, ?, 0, ?)');
    $stmt->execute([$userId, '{}', '{}', '', '{}', bochi_now_iso()]);
}

function bochi_get_progress_row(PDO $db, int $userId): array
{
    bochi_create_progress_row($db, $userId);
    $stmt = $db->prepare('SELECT * FROM progress WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        bochi_fail(500, 'progress_unavailable', 'Progress is unavailable.');
    }
    return $row;
}

function bochi_decode_map($json): array
{
    if (!is_string($json) || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function bochi_decode_scripts($json): array
{
    $out = ['hira' => [], 'kata' => [], 'kanji' => []];
    if (!is_string($json) || trim($json) === '') {
        return $out;
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return $out;
    }
    foreach (['hira', 'kata', 'kanji'] as $key) {
        if (isset($decoded[$key]) && is_array($decoded[$key])) {
            $vals = [];
            foreach ($decoded[$key] as $v) {
                if (is_string($v) && $v !== '') { $vals[] = $v; }
            }
            sort($vals, SORT_STRING);
            $out[$key] = $vals;
        }
    }
    return $out;
}

function bochi_normalize_scripts($value): array
{
    if ($value === null) {
        return ['hira' => [], 'kata' => [], 'kanji' => []];
    }
    if (!is_array($value)) {
        bochi_fail(422, 'invalid_scripts', 'The scripts field is invalid.');
    }
    $caps = ['hira' => 130, 'kata' => 130, 'kanji' => 160];
    $out = ['hira' => [], 'kata' => [], 'kanji' => []];
    foreach ($value as $key => $list) {
        if (!isset($caps[$key]) || !is_array($list)) {
            bochi_fail(422, 'invalid_scripts', 'The scripts field is invalid.');
        }
        if (count($list) > $caps[$key]) {
            bochi_fail(422, 'invalid_scripts', 'Too many memorized characters in one script.');
        }
        $seen = [];
        foreach ($list as $ch) {
            if (!is_string($ch) || preg_match('/^[\p{L}\p{M}]{1,3}$/u', $ch) !== 1) {
                bochi_fail(422, 'invalid_scripts', 'A memorized character is invalid.');
            }
            if (isset($seen[$ch])) {
                bochi_fail(422, 'invalid_scripts', 'Duplicate memorized character.');
            }
            $seen[$ch] = true;
            $out[$key][] = $ch;
        }
        sort($out[$key], SORT_STRING);
    }
    return $out;
}

function bochi_decode_practice($json): array
{
    $out = [];
    if (!is_string($json) || trim($json) === '') {
        return $out;
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return $out;
    }
    foreach ($decoded as $date => $entry) {
        if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || !is_array($entry)) {
            continue;
        }
        $out[$date] = [
            'm' => max(0, min(1440, (int)($entry['m'] ?? 0))),
            'c' => max(0, min(1000, (int)($entry['c'] ?? 0))),
            'l' => max(0, min(200, (int)($entry['l'] ?? 0))),
        ];
    }
    ksort($out, SORT_STRING);
    return $out;
}

function bochi_normalize_practice($value): array
{
    if ($value === null) {
        return [];
    }
    if (!is_array($value)) {
        bochi_fail(422, 'invalid_practice', 'The practice field is invalid.');
    }
    if (count($value) > 200) {
        bochi_fail(422, 'invalid_practice', 'Too many practice days.');
    }
    $out = [];
    foreach ($value as $date => $entry) {
        if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || !is_array($entry)) {
            bochi_fail(422, 'invalid_practice', 'A practice day is invalid.');
        }
        foreach (['m', 'c', 'l'] as $k) {
            if (isset($entry[$k]) && !is_numeric($entry[$k])) {
                bochi_fail(422, 'invalid_practice', 'A practice value is invalid.');
            }
        }
        $out[$date] = [
            'm' => max(0, min(1440, (int)($entry['m'] ?? 0))),
            'c' => max(0, min(1000, (int)($entry['c'] ?? 0))),
            'l' => max(0, min(200, (int)($entry['l'] ?? 0))),
        ];
    }
    ksort($out, SORT_STRING);
    return $out;
}

function bochi_empty_stats(): array
{
    return ['ans' => 0, 'cor' => 0, 'ch' => [], 'src' => []];
}

function bochi_stats_pair($value): array
{
    if (!is_array($value)) {
        return ['a' => 0, 'c' => 0];
    }
    return [
        'a' => max(0, min(10000000, (int)($value['a'] ?? 0))),
        'c' => max(0, min(10000000, (int)($value['c'] ?? 0))),
    ];
}

function bochi_decode_stats($json): array
{
    $out = bochi_empty_stats();
    if (!is_string($json) || trim($json) === '') {
        return $out;
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return $out;
    }
    $out['ans'] = max(0, min(10000000, (int)($decoded['ans'] ?? 0)));
    $out['cor'] = max(0, min(10000000, (int)($decoded['cor'] ?? 0)));
    if (isset($decoded['ch']) && is_array($decoded['ch'])) {
        foreach ($decoded['ch'] as $k => $v) {
            $k = (string)$k;
            if (preg_match('/^[1-6]$/', $k) === 1) {
                $out['ch'][$k] = bochi_stats_pair($v);
            }
        }
    }
    if (isset($decoded['src']) && is_array($decoded['src'])) {
        foreach (['lesson', 'review', 'game'] as $k) {
            if (isset($decoded['src'][$k])) {
                $out['src'][$k] = bochi_stats_pair($decoded['src'][$k]);
            }
        }
    }
    return $out;
}

function bochi_normalize_stats($value): array
{
    if ($value === null) {
        return bochi_empty_stats();
    }
    if (!is_array($value)) {
        bochi_fail(422, 'invalid_stats', 'The stats field is invalid.');
    }
    foreach (['ans', 'cor'] as $k) {
        if (isset($value[$k]) && !is_numeric($value[$k])) {
            bochi_fail(422, 'invalid_stats', 'A stats value is invalid.');
        }
    }
    $out = bochi_empty_stats();
    $out['ans'] = max(0, min(10000000, (int)($value['ans'] ?? 0)));
    $out['cor'] = max(0, min(10000000, (int)($value['cor'] ?? 0)));
    if (isset($value['ch'])) {
        if (!is_array($value['ch'])) {
            bochi_fail(422, 'invalid_stats', 'The stats chapters are invalid.');
        }
        foreach ($value['ch'] as $k => $v) {
            $k = (string)$k;
            if (preg_match('/^[1-6]$/', $k) !== 1 || !is_array($v)) {
                bochi_fail(422, 'invalid_stats', 'A stats chapter is invalid.');
            }
            $out['ch'][$k] = bochi_stats_pair($v);
        }
    }
    if (isset($value['src'])) {
        if (!is_array($value['src'])) {
            bochi_fail(422, 'invalid_stats', 'The stats sources are invalid.');
        }
        foreach ($value['src'] as $k => $v) {
            if (!in_array($k, ['lesson', 'review', 'game'], true) || !is_array($v)) {
                bochi_fail(422, 'invalid_stats', 'A stats source is invalid.');
            }
            $out['src'][$k] = bochi_stats_pair($v);
        }
    }
    return $out;
}

function bochi_stats_object(array $stats): stdClass
{
    $ch = [];
    foreach ($stats['ch'] as $k => $v) {
        $ch[$k] = (object)$v;
    }
    $srcMap = [];
    foreach ($stats['src'] as $k => $v) {
        $srcMap[$k] = (object)$v;
    }
    $obj = new stdClass();
    $obj->ans = (int)$stats['ans'];
    $obj->cor = (int)$stats['cor'];
    $obj->ch = (object)$ch;
    $obj->src = (object)$srcMap;
    return $obj;
}

function bochi_encode_map(array $map): string
{
    if ($map === []) {
        return '{}';
    }
    $json = json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : '{}';
}

function bochi_timezone_for_user(array $user): DateTimeZone
{
    $name = (string)($user['timezone'] ?? '');
    try {
        return new DateTimeZone($name !== '' ? $name : 'UTC');
    } catch (Throwable $e) {
        return new DateTimeZone('UTC');
    }
}

function bochi_today_for_user(array $user): string
{
    return (new DateTimeImmutable('now', bochi_timezone_for_user($user)))->format('Y-m-d');
}

function bochi_level_for_xp(int $xp): int
{
    return min(6, intdiv(max(0, $xp), 100) + 1);
}

function bochi_canonical_progress(PDO $db, array $user): array
{
    $row = bochi_get_progress_row($db, (int)$user['id']);
    $today = bochi_today_for_user($user);
    $stmt = $db->prepare('SELECT xp FROM solo_daily WHERE user_id = ? AND local_date = ? LIMIT 1');
    $stmt->execute([(int)$user['id'], $today]);
    $daily = $stmt->fetch();
    $soloXp = is_array($daily) ? (int)$daily['xp'] : 0;

    $lessons = bochi_decode_map($row['lessons_json']);
    $scripts = bochi_decode_scripts($row['scripts_json'] ?? '{}');
    $practice = bochi_decode_practice($row['practice_json'] ?? '{}');
    $stats = bochi_decode_stats($row['stats_json'] ?? '{}');
    $checkpoints = bochi_decode_map($row['checkpoints_json']);
    $review = bochi_decode_map($row['review_json']);
    $reviewObjects = [];
    foreach ($review as $key => $item) {
        $reviewObjects[$key] = is_array($item) ? (object)$item : $item;
    }

    return [
        'xp' => (int)$row['xp'],
        'level' => bochi_level_for_xp((int)$row['xp']),
        'lessons' => (object)$lessons,
        'checkpoints' => (object)$checkpoints,
        'lastUnlock' => (string)$row['last_unlock'],
        'review' => (object)$reviewObjects,
        'scripts' => $scripts,
        'practice' => (object)$practice,
        'stats' => bochi_stats_object($stats),
        'soloTodayXp' => $soloXp,
        'soloDate' => $today,
        'version' => (int)$row['version'],
        'updatedAt' => (string)$row['updated_at'],
    ];
}

function bochi_empty_progress(string $playerKey): array
{
    $timezone = BOCHI_PLAYER_TIMEZONES[$playerKey] ?? 'UTC';
    try {
        $today = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
    } catch (Throwable $e) {
        $today = gmdate('Y-m-d');
    }
    return [
        'xp' => 0,
        'level' => 1,
        'lessons' => new stdClass(),
        'checkpoints' => new stdClass(),
        'lastUnlock' => '',
        'review' => new stdClass(),
        'scripts' => ['hira' => [], 'kata' => [], 'kanji' => []],
        'practice' => new stdClass(),
        'stats' => bochi_stats_object(bochi_empty_stats()),
        'soloTodayXp' => 0,
        'soloDate' => $today,
        'version' => 0,
        'updatedAt' => null,
    ];
}

function bochi_progress_bundle(PDO $db, array $account): array
{
    $bundle = [];
    foreach (BOCHI_PLAYERS as $playerKey) {
        $user = bochi_fetch_user_by_player($db, $playerKey);
        $bundle[$playerKey] = $user === null ? bochi_empty_progress($playerKey) : bochi_canonical_progress($db, $user);
    }
    return $bundle;
}

function bochi_set_session_cookie(string $token, int $expiresAt): void
{
    $secure = bochi_is_https();
    setcookie(bochi_session_cookie_name(), $token, [
        'expires' => $expiresAt,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function bochi_clear_session_cookie(): void
{
    $secure = bochi_is_https();
    setcookie(bochi_session_cookie_name(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function bochi_create_session(PDO $db, int $userId): array
{
    $previousToken = bochi_read_session_token();
    if ($previousToken !== null) {
        $deletePrevious = $db->prepare('DELETE FROM sessions WHERE token_hash = ?');
        $deletePrevious->execute([hash('sha256', $previousToken)]);
    }
    $token = bochi_base64url_encode(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $now = bochi_now_ts();
    $expires = $now + BOCHI_SESSION_TTL;
    $stmt = $db->prepare('INSERT INTO sessions (token_hash, user_id, created_at, last_seen_at, expires_at, ip_hash, user_agent_hash) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $tokenHash,
        $userId,
        bochi_iso_from_ts($now),
        bochi_iso_from_ts($now),
        bochi_iso_from_ts($expires),
        bochi_client_ip_hash(),
        bochi_user_agent_hash(),
    ]);
    bochi_set_session_cookie($token, $expires);
    return ['token_hash' => $tokenHash, 'expires_at' => bochi_iso_from_ts($expires)];
}

function bochi_read_session_token(): ?string
{
    $names = [bochi_session_cookie_name(), '__Host-bochi_session', 'bochi_session'];
    foreach ($names as $name) {
        if (isset($_COOKIE[$name]) && is_string($_COOKIE[$name]) && preg_match('/^[A-Za-z0-9_-]{43}$/', $_COOKIE[$name])) {
            return $_COOKIE[$name];
        }
    }
    return null;
}

function bochi_current_user(PDO $db): ?array
{
    $token = bochi_read_session_token();
    if ($token === null) {
        return null;
    }
    $tokenHash = hash('sha256', $token);
    $stmt = $db->prepare('SELECT u.*, s.token_hash AS session_token_hash, s.expires_at AS session_expires_at, s.last_seen_at AS session_last_seen_at FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ? LIMIT 1');
    $stmt->execute([$tokenHash]);
    $user = $stmt->fetch();
    if (!is_array($user)) {
        return null;
    }

    $now = bochi_now_ts();
    $expiresTs = strtotime((string)$user['session_expires_at']);
    if ($expiresTs === false || $expiresTs <= $now || !empty($user['disabled_at'])) {
        $delete = $db->prepare('DELETE FROM sessions WHERE token_hash = ?');
        $delete->execute([$tokenHash]);
        return null;
    }

    $lastSeenTs = strtotime((string)$user['session_last_seen_at']);
    if ($lastSeenTs === false || $now - $lastSeenTs >= 300) {
        $newExpires = $expiresTs;
        if ($expiresTs - $now < 1296000) { // Less than 15 days remain.
            $newExpires = $now + BOCHI_SESSION_TTL;
            bochi_set_session_cookie($token, $newExpires);
        }
        $update = $db->prepare('UPDATE sessions SET last_seen_at = ?, expires_at = ? WHERE token_hash = ?');
        $update->execute([bochi_iso_from_ts($now), bochi_iso_from_ts($newExpires), $tokenHash]);
        $user['session_expires_at'] = bochi_iso_from_ts($newExpires);
    }

    return $user;
}

function bochi_require_user(PDO $db): array
{
    $user = bochi_current_user($db);
    if ($user === null) {
        bochi_fail(401, 'sign_in_required', 'Sign in is required.');
    }
    return $user;
}

function bochi_invite_hash(string $code): string
{
    return bochi_secret_hash('invite', $code);
}

function bochi_create_invite(PDO $db, string $playerKey, int $days = 7): string
{
    $code = 'bochi_' . bochi_base64url_encode(random_bytes(24));
    $now = bochi_now_ts();
    $stmt = $db->prepare('INSERT INTO invites (player_key, code_hash, created_at, expires_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        $playerKey,
        bochi_invite_hash($code),
        bochi_iso_from_ts($now),
        bochi_iso_from_ts($now + ($days * 86400)),
    ]);
    return $code;
}

function bochi_active_invite_exists(PDO $db, string $playerKey): bool
{
    $stmt = $db->prepare('SELECT id FROM invites WHERE player_key = ? AND used_at IS NULL AND revoked_at IS NULL AND expires_at > ? LIMIT 1');
    $stmt->execute([$playerKey, bochi_now_iso()]);
    return is_array($stmt->fetch());
}

function bochi_setup(PDO $db, array $body): void
{
    bochi_require_write_guard();
    $setupHash = (string)(bochi_config()['setup_token_hash'] ?? '');
    $token = (string)($_SERVER['HTTP_X_BOCHI_SETUP'] ?? '');
    if ($setupHash === '' || $token === '' || !hash_equals($setupHash, hash('sha256', $token))) {
        bochi_fail(403, 'setup_not_allowed', 'Setup is not allowed.');
    }

    $count = $db->query('SELECT COUNT(*) AS c FROM users')->fetch();
    if (!is_array($count) || (int)$count['c'] !== 0) {
        bochi_fail(409, 'setup_closed', 'Setup is closed.');
    }
    $inviteCount = $db->query('SELECT COUNT(*) AS c FROM invites WHERE used_at IS NULL AND revoked_at IS NULL')->fetch();
    if (is_array($inviteCount) && (int)$inviteCount['c'] > 0) {
        bochi_fail(409, 'invites_already_created', 'Invite codes were already created and cannot be shown again. Rotate them from a server shell.');
    }

    $invites = [];
    foreach (BOCHI_PLAYERS as $playerKey) {
        $invites[$playerKey] = bochi_create_invite($db, $playerKey);
    }

    bochi_json_response([
        'ok' => true,
        'message' => 'Copy these invite codes now. Only their hashes are stored. Remove the setup token hash from the private config after this step.',
        'invites' => $invites,
    ], 201);
}

function bochi_register(PDO $db, array $body): void
{
    $inviteCode = bochi_string($body['inviteCode'] ?? null, 'invite_code', 10, 200);
    $email = bochi_normalize_email($body['email'] ?? null);
    $password = bochi_validate_password($body['password'] ?? null, $email);
    $displayName = isset($body['displayName']) && is_string($body['displayName']) && trim($body['displayName']) !== ''
        ? bochi_string($body['displayName'], 'display_name', 1, 80)
        : null;
    $passwordHash = password_hash($password, bochi_password_algorithm());
    if (!is_string($passwordHash) || $passwordHash === '') {
        bochi_fail(500, 'password_unavailable', 'Account creation is unavailable.');
    }

    $userId = null;
    $db->exec('BEGIN IMMEDIATE');
    try {
        $stmt = $db->prepare('SELECT * FROM invites WHERE code_hash = ? LIMIT 1');
        $stmt->execute([bochi_invite_hash($inviteCode)]);
        $invite = $stmt->fetch();
        if (!is_array($invite) || $invite['used_at'] !== null || $invite['revoked_at'] !== null || strtotime((string)$invite['expires_at']) <= bochi_now_ts()) {
            bochi_fail(403, 'invite_invalid', 'This invite is not valid.');
        }

        $count = $db->query('SELECT COUNT(*) AS c FROM users')->fetch();
        if (!is_array($count) || (int)$count['c'] >= 2) {
            bochi_fail(403, 'registration_closed', 'Registration is closed.');
        }

        $playerKey = (string)$invite['player_key'];
        if (bochi_fetch_user_by_player($db, $playerKey) !== null) {
            bochi_fail(409, 'account_exists', 'An account already exists for this player.');
        }
        $emailStmt = $db->prepare('SELECT id FROM users WHERE email = ? COLLATE NOCASE LIMIT 1');
        $emailStmt->execute([$email]);
        if (is_array($emailStmt->fetch())) {
            bochi_fail(409, 'email_in_use', 'That email is already in use.');
        }

        if ($displayName === null) {
            $displayName = BOCHI_PLAYER_NAMES[$playerKey];
        }
        $now = bochi_now_iso();
        $insert = $db->prepare('INSERT INTO users (player_key, email, display_name, password_hash, timezone, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([
            $playerKey,
            $email,
            $displayName,
            $passwordHash,
            BOCHI_PLAYER_TIMEZONES[$playerKey],
            $now,
            $now,
        ]);
        $userId = (int)$db->lastInsertId();
        bochi_create_progress_row($db, $userId);
        $used = $db->prepare('UPDATE invites SET used_by_user_id = ?, used_at = ? WHERE id = ? AND used_at IS NULL');
        $used->execute([$userId, $now, (int)$invite['id']]);
        if ($used->rowCount() !== 1) {
            bochi_fail(403, 'invite_invalid', 'This invite is not valid.');
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $e;
    }

    $user = $userId === null ? null : bochi_fetch_user_by_id($db, $userId);
    if ($user === null) {
        bochi_fail(500, 'account_unavailable', 'The account could not be loaded.');
    }
    bochi_create_session($db, (int)$user['id']);
    bochi_json_response([
        'ok' => true,
        'account' => bochi_public_account($user),
        'partner' => bochi_public_partner($db, $user),
        'progressByPlayer' => bochi_progress_bundle($db, $user),
    ], 201);
}

function bochi_login_blocked(PDO $db, string $email, string $ipHash): bool
{
    if ($ipHash === '') {
        return false;
    }
    $cutoff = bochi_iso_from_ts(bochi_now_ts() - 900);
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM login_attempts WHERE email = ? AND ip_hash = ? AND success = 0 AND attempted_at >= ?');
    $stmt->execute([$email, $ipHash, $cutoff]);
    $row = $stmt->fetch();
    return is_array($row) && (int)$row['c'] >= 5;
}

function bochi_record_login_attempt(PDO $db, string $email, string $ipHash, bool $success): void
{
    $stmt = $db->prepare('INSERT INTO login_attempts (email, ip_hash, attempted_at, success) VALUES (?, ?, ?, ?)');
    $stmt->execute([$email, $ipHash, bochi_now_iso(), $success ? 1 : 0]);
}

function bochi_dummy_password_hash(): string
{
    static $hash = null;
    if (!is_string($hash) || $hash === '') {
        $hash = password_hash('bochi-dummy-password-for-timing-only', bochi_password_algorithm());
    }
    return is_string($hash) ? $hash : '';
}

function bochi_login(PDO $db, array $body): void
{
    $email = bochi_normalize_email($body['email'] ?? null);
    $password = $body['password'] ?? null;
    if (!is_string($password) || $password === '' || strlen($password) > 512) {
        bochi_fail(401, 'invalid_credentials', 'Email or password is incorrect.');
    }
    $ipHash = bochi_client_ip_hash();
    if (bochi_login_blocked($db, $email, $ipHash)) {
        bochi_fail(429, 'too_many_attempts', 'Too many sign-in attempts. Try again later.');
    }

    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? COLLATE NOCASE LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    $hashToCheck = is_array($user) ? (string)$user['password_hash'] : bochi_dummy_password_hash();
    $passwordOk = password_verify($password, $hashToCheck);
    if (!is_array($user) || !$passwordOk || !empty($user['disabled_at'])) {
        bochi_record_login_attempt($db, $email, $ipHash, false);
        bochi_fail(401, 'invalid_credentials', 'Email or password is incorrect.');
    }

    bochi_record_login_attempt($db, $email, $ipHash, true);
    $delete = $db->prepare('DELETE FROM login_attempts WHERE email = ? AND ip_hash = ? AND success = 0');
    $delete->execute([$email, $ipHash]);

    if (password_needs_rehash((string)$user['password_hash'], bochi_password_algorithm())) {
        $newHash = password_hash($password, bochi_password_algorithm());
        if (is_string($newHash) && $newHash !== '') {
            $update = $db->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?');
            $update->execute([$newHash, bochi_now_iso(), (int)$user['id']]);
            $user['password_hash'] = $newHash;
        }
    }

    bochi_create_session($db, (int)$user['id']);
    bochi_json_response([
        'ok' => true,
        'account' => bochi_public_account($user),
        'partner' => bochi_public_partner($db, $user),
        'progressByPlayer' => bochi_progress_bundle($db, $user),
    ]);
}

function bochi_logout(PDO $db): void
{
    $user = bochi_require_user($db);
    $stmt = $db->prepare('DELETE FROM sessions WHERE token_hash = ?');
    $stmt->execute([(string)$user['session_token_hash']]);
    bochi_clear_session_cookie();
    bochi_json_response(['ok' => true]);
}

function bochi_normalize_bool_map($value, string $field, callable $keyValidator, int $maxItems): array
{
    if (!is_array($value)) {
        bochi_fail(422, 'invalid_' . $field, 'The ' . $field . ' field is invalid.');
    }
    if (count($value) > $maxItems) {
        bochi_fail(422, 'invalid_' . $field, 'The ' . $field . ' field has too many entries.');
    }
    $result = [];
    foreach ($value as $key => $item) {
        $keyText = (string)$key;
        if (!$keyValidator($keyText)) {
            bochi_fail(422, 'invalid_' . $field, 'The ' . $field . ' field contains an invalid key.');
        }
        if ($item !== true) {
            bochi_fail(422, 'invalid_' . $field, 'The ' . $field . ' field must contain only completed entries.');
        }
        $result[$keyText] = true;
    }
    ksort($result, SORT_STRING);
    return $result;
}

function bochi_normalize_review($value): array
{
    if (!is_array($value)) {
        bochi_fail(422, 'invalid_review', 'The review field is invalid.');
    }
    if (count($value) > 500) {
        bochi_fail(422, 'invalid_review', 'The review queue has too many entries.');
    }
    $pools = ['reads', 'means', 'jpwords', 'phrases'];
    $result = [];
    foreach ($value as $ignoredKey => $item) {
        if (!is_array($item)) {
            bochi_fail(422, 'invalid_review', 'A review item is invalid.');
        }
        $front = bochi_string($item['front'] ?? null, 'review_front', 1, 300);
        $answer = bochi_string($item['answer'] ?? null, 'review_answer', 1, 300);
        $sub = bochi_optional_string($item['sub'] ?? '', 'review_sub', 300) ?? '';
        $pool = bochi_string($item['pool'] ?? null, 'review_pool', 1, 40);
        if (!in_array($pool, $pools, true)) {
            bochi_fail(422, 'invalid_review', 'A review item has an invalid pool.');
        }
        $teach = bochi_string($item['teach'] ?? null, 'review_teach', 1, 1000);
        $chapter = bochi_int($item['ch'] ?? 0, 'review_chapter', 0, 6);
        $streak = bochi_int($item['streak'] ?? 0, 'review_streak', 0, 100000);
        $misses = bochi_int($item['misses'] ?? 1, 'review_misses', 0, 100000);
        $key = $front . '|' . $answer;
        if (isset($result[$key])) {
            bochi_fail(422, 'invalid_review', 'The review queue contains a duplicate item.');
        }
        $result[$key] = [
            'front' => $front,
            'sub' => $sub,
            'answer' => $answer,
            'pool' => $pool,
            'teach' => $teach,
            'ch' => $chapter,
            'streak' => $streak,
            'misses' => $misses,
        ];
    }
    ksort($result, SORT_STRING);
    return $result;
}

function bochi_update_detailed_progress(PDO $db, array $account, array $body): void
{
    if (isset($body['progress']['xp']) || isset($body['progress']['soloTodayXp'])) {
        bochi_fail(422, 'xp_is_event_sourced', 'XP is updated through XP events, not detailed progress replacement.');
    }
    $version = bochi_int($body['version'] ?? null, 'version', 0, 2000000000);
    if (!isset($body['progress']) || !is_array($body['progress'])) {
        bochi_fail(422, 'invalid_progress', 'The progress field is required.');
    }
    $progress = $body['progress'];
    $lessons = bochi_normalize_bool_map($progress['lessons'] ?? null, 'lessons', function (string $key): bool {
        return preg_match('/^ch[1-6]-\\d{1,3}$/', $key) === 1;
    }, 500);
    $checkpoints = bochi_normalize_bool_map($progress['checkpoints'] ?? null, 'checkpoints', function (string $key): bool {
        return preg_match('/^[1-6]$/', $key) === 1;
    }, 6);
    $review = bochi_normalize_review($progress['review'] ?? null);
    $lastUnlock = bochi_optional_string($progress['lastUnlock'] ?? '', 'last_unlock', 500) ?? '';
    $hasScripts = array_key_exists('scripts', $progress);
    $scripts = $hasScripts ? bochi_normalize_scripts($progress['scripts']) : null;
    $hasPractice = array_key_exists('practice', $progress);
    $practice = $hasPractice ? bochi_normalize_practice($progress['practice']) : null;
    $hasStats = array_key_exists('stats', $progress);
    $stats = $hasStats ? bochi_normalize_stats($progress['stats']) : null;

    $userId = (int)$account['id'];
    $db->exec('BEGIN IMMEDIATE');
    try {
        $row = bochi_get_progress_row($db, $userId);
        if ((int)$row['version'] !== $version) {
            $db->exec('ROLLBACK');
            bochi_json_response([
                'ok' => false,
                'error' => [
                    'code' => 'progress_conflict',
                    'message' => 'Progress changed on the server. Reload it before saving again.',
                ],
                'progressByPlayer' => bochi_progress_bundle($db, $account),
            ], 409);
        }
        $set = 'lessons_json = ?, checkpoints_json = ?, last_unlock = ?, review_json = ?';
        $params = [
            bochi_encode_map($lessons),
            bochi_encode_map($checkpoints),
            $lastUnlock,
            bochi_encode_map($review),
        ];
        if ($hasScripts) {
            $set .= ', scripts_json = ?';
            $params[] = bochi_encode_map($scripts);
        }
        if ($hasPractice) {
            $set .= ', practice_json = ?';
            $params[] = bochi_encode_map($practice);
        }
        if ($hasStats) {
            $set .= ', stats_json = ?';
            $params[] = json_encode($stats);
        }
        $set .= ', version = version + 1, updated_at = ?';
        $params[] = bochi_now_iso();
        $params[] = $userId;
        $stmt = $db->prepare('UPDATE progress SET ' . $set . ' WHERE user_id = ?');
        $stmt->execute($params);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $e;
    }

    bochi_json_response([
        'ok' => true,
        'progressByPlayer' => bochi_progress_bundle($db, $account),
    ]);
}

function bochi_validate_local_date($value, array $targetUser): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $date = bochi_string($value, 'local_date', 10, 10);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, bochi_timezone_for_user($targetUser));
    $errors = DateTimeImmutable::getLastErrors();
    if ($parsed === false || (is_array($errors) && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $date) {
        bochi_fail(422, 'invalid_local_date', 'The local date is invalid.');
    }
    $today = new DateTimeImmutable('now', bochi_timezone_for_user($targetUser));
    $todayText = $today->format('Y-m-d');
    if ($date > $todayText) {
        bochi_fail(422, 'invalid_local_date', 'The local date cannot be in the future.');
    }
    $earliest = $today->modify('-7 days')->format('Y-m-d');
    if ($date < $earliest) {
        bochi_fail(422, 'invalid_local_date', 'The local date is too old to sync.');
    }
    return $date;
}

function bochi_add_xp_event(PDO $db, array $account, array $body): void
{
    $eventId = bochi_string($body['eventId'] ?? null, 'event_id', 16, 80);
    if (preg_match('/^[A-Za-z0-9-]{16,80}$/', $eventId) !== 1) {
        bochi_fail(422, 'invalid_event_id', 'The event ID is invalid.');
    }
    $playerKey = bochi_string($body['playerKey'] ?? null, 'player_key', 4, 10);
    if (!in_array($playerKey, BOCHI_PLAYERS, true)) {
        bochi_fail(422, 'invalid_player_key', 'The player is invalid.');
    }
    $delta = bochi_int($body['delta'] ?? null, 'delta', 1, BOCHI_MAX_XP_EVENT);
    $solo = bochi_bool($body['solo'] ?? false, 'solo');
    $source = bochi_string($body['source'] ?? null, 'source', 1, 40);
    $sharedSources = ['hiragana', 'duel', 'translate', 'culture', 'roulette', 'story', 'phrases', 'watch', 'shadow', 'news', 'shared'];
    $soloSources = ['solo', 'lesson', 'review', 'checkpoint'];
    if (!in_array($source, array_merge($sharedSources, $soloSources), true)) {
        bochi_fail(422, 'invalid_source', 'The XP source is invalid.');
    }
    if ($solo && !in_array($source, $soloSources, true) && !in_array($source, $sharedSources, true)) {
        bochi_fail(422, 'invalid_source', 'The solo XP source is invalid.');
    }
    if (!$solo && in_array($source, $soloSources, true)) {
        bochi_fail(422, 'invalid_source', 'Solo sources must be marked as solo.');
    }
    if ($solo && $playerKey !== (string)$account['player_key']) {
        bochi_fail(403, 'solo_target_not_allowed', 'Solo XP can only be added to the signed-in account.');
    }

    $target = bochi_fetch_user_by_player($db, $playerKey);
    if ($target === null) {
        bochi_fail(409, 'partner_not_registered', 'That player has not created an account yet.');
    }
    $localDate = bochi_validate_local_date($body['localDate'] ?? null, $target);
    if ($solo && $localDate === null) {
        $localDate = bochi_today_for_user($target);
    }
    $lastUnlock = bochi_optional_string($body['lastUnlock'] ?? null, 'last_unlock', 500);

    $duplicate = false;
    $db->exec('BEGIN IMMEDIATE');
    try {
        $stmt = $db->prepare('SELECT * FROM xp_events WHERE event_id = ? LIMIT 1');
        $stmt->execute([$eventId]);
        $existing = $stmt->fetch();
        if (is_array($existing)) {
            $same = (int)$existing['actor_user_id'] === (int)$account['id']
                && (int)$existing['target_user_id'] === (int)$target['id']
                && (int)$existing['delta'] === $delta
                && (int)$existing['solo'] === ($solo ? 1 : 0)
                && (string)$existing['source'] === $source;
            if (!$same) {
                bochi_fail(409, 'event_id_conflict', 'That event ID was already used for a different XP event.');
            }
            $duplicate = true;
        } else {
            $row = bochi_get_progress_row($db, (int)$target['id']);
            $newXp = (int)$row['xp'] + $delta;
            if ($newXp > BOCHI_MAX_XP_TOTAL) {
                bochi_fail(422, 'xp_limit', 'The XP total is too large.');
            }
            $newLastUnlock = $lastUnlock === null || $lastUnlock === '' ? (string)$row['last_unlock'] : $lastUnlock;
            $update = $db->prepare('UPDATE progress SET xp = ?, last_unlock = ?, version = version + 1, updated_at = ? WHERE user_id = ?');
            $update->execute([$newXp, $newLastUnlock, bochi_now_iso(), (int)$target['id']]);

            if ($solo) {
                $dailyStmt = $db->prepare('SELECT xp FROM solo_daily WHERE user_id = ? AND local_date = ? LIMIT 1');
                $dailyStmt->execute([(int)$target['id'], $localDate]);
                $daily = $dailyStmt->fetch();
                if (is_array($daily)) {
                    $dailyUpdate = $db->prepare('UPDATE solo_daily SET xp = xp + ?, updated_at = ? WHERE user_id = ? AND local_date = ?');
                    $dailyUpdate->execute([$delta, bochi_now_iso(), (int)$target['id'], $localDate]);
                } else {
                    $dailyInsert = $db->prepare('INSERT INTO solo_daily (user_id, local_date, xp, updated_at) VALUES (?, ?, ?, ?)');
                    $dailyInsert->execute([(int)$target['id'], $localDate, $delta, bochi_now_iso()]);
                }
            }

            $insert = $db->prepare('INSERT INTO xp_events (event_id, actor_user_id, target_user_id, delta, solo, source, local_date, last_unlock, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([
                $eventId,
                (int)$account['id'],
                (int)$target['id'],
                $delta,
                $solo ? 1 : 0,
                $source,
                $solo ? $localDate : null,
                $lastUnlock,
                bochi_now_iso(),
            ]);
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $e;
    }

    bochi_json_response([
        'ok' => true,
        'duplicate' => $duplicate,
        'progressByPlayer' => bochi_progress_bundle($db, $account),
    ]);
}

function bochi_cli(array $argv): void
{
    $command = (string)($argv[1] ?? '');
    if ($command === '') {
        fwrite(STDERR, "Usage: php api.php install|status|invite <bolu|chihiro>\n");
        exit(2);
    }
    $db = bochi_db();

    if ($command === 'status') {
        $users = $db->query('SELECT COUNT(*) AS c FROM users')->fetch();
        $invites = $db->query('SELECT COUNT(*) AS c FROM invites WHERE used_at IS NULL AND revoked_at IS NULL AND expires_at > ' . $db->quote(bochi_now_iso()))->fetch();
        echo 'users=' . (is_array($users) ? (int)$users['c'] : 0) . PHP_EOL;
        echo 'active_invites=' . (is_array($invites) ? (int)$invites['c'] : 0) . PHP_EOL;
        return;
    }

    if ($command === 'install') {
        foreach (BOCHI_PLAYERS as $playerKey) {
            if (bochi_fetch_user_by_player($db, $playerKey) !== null) {
                echo $playerKey . ': account already exists' . PHP_EOL;
                continue;
            }
            if (bochi_active_invite_exists($db, $playerKey)) {
                echo $playerKey . ': an active invite already exists, but its code cannot be shown again' . PHP_EOL;
                continue;
            }
            $code = bochi_create_invite($db, $playerKey);
            echo $playerKey . ' invite: ' . $code . PHP_EOL;
        }
        return;
    }

    if ($command === 'invite') {
        $playerKey = (string)($argv[2] ?? '');
        if (!in_array($playerKey, BOCHI_PLAYERS, true)) {
            fwrite(STDERR, "Player must be bolu or chihiro.\n");
            exit(2);
        }
        if (bochi_fetch_user_by_player($db, $playerKey) !== null) {
            fwrite(STDERR, "That player already has an account.\n");
            exit(1);
        }
        $revoke = $db->prepare('UPDATE invites SET revoked_at = ? WHERE player_key = ? AND used_at IS NULL AND revoked_at IS NULL');
        $revoke->execute([bochi_now_iso(), $playerKey]);
        $code = bochi_create_invite($db, $playerKey);
        echo $playerKey . ' invite: ' . $code . PHP_EOL;
        return;
    }

    fwrite(STDERR, "Unknown command.\n");
    exit(2);
}

if (PHP_SAPI === 'cli') {
    try {
        bochi_cli($argv ?? []);
    } catch (BochiHttpError $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    } catch (Throwable $e) {
        error_log('Bochi CLI error: ' . $e->getMessage());
        fwrite(STDERR, "Bochi CLI failed.\n");
        exit(1);
    }
    exit;
}


function bochi_private_file(string $name): string
{
    $config = bochi_config();
    return dirname((string)$config['db_path']) . '/' . $name;
}

function bochi_b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function bochi_b64url_decode(string $s): string
{
    $pad = strlen($s) % 4;
    if ($pad) { $s .= str_repeat('=', 4 - $pad); }
    $out = base64_decode(strtr($s, '-_', '+/'), true);
    return $out === false ? '' : $out;
}

function bochi_vapid(): array
{
    $path = bochi_private_file('vapid.json');
    if (is_file($path)) {
        $data = json_decode((string)@file_get_contents($path), true);
        if (is_array($data) && !empty($data['public']) && !empty($data['private_pem'])) {
            return $data;
        }
    }
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($key === false) { bochi_fail(500, 'vapid_failed', 'Could not create notification keys.'); }
    $details = openssl_pkey_get_details($key);
    openssl_pkey_export($key, $pem);
    $pub = "\x04" . str_pad((string)$details['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad((string)$details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $data = ['public' => bochi_b64url_encode($pub), 'private_pem' => $pem];
    @file_put_contents($path, json_encode($data));
    @chmod($path, 0600);
    return $data;
}

function bochi_cron_token(): string
{
    $path = bochi_private_file('cron-token.txt');
    if (is_file($path)) {
        $t = trim((string)@file_get_contents($path));
        if ($t !== '') { return $t; }
    }
    $t = bin2hex(random_bytes(24));
    @file_put_contents($path, $t);
    @chmod($path, 0600);
    return $t;
}

function bochi_session_row(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'starts_at' => (string)$row['starts_at'],
        'duration_min' => (int)$row['duration_min'],
        'status' => (string)$row['status'],
        'created_by' => (string)$row['created_by'],
    ];
}

function bochi_sessions_list(PDO $db): void
{
    $rows = $db->query('SELECT * FROM meetings ORDER BY starts_at ASC')->fetchAll();
    bochi_json_response(['ok' => true, 'sessions' => array_map('bochi_session_row', $rows ?: [])]);
}

function bochi_sessions_create(PDO $db, array $account, array $body): void
{
    $starts = (string)($body['starts_at'] ?? '');
    $ts = strtotime($starts);
    if ($ts === false) { bochi_fail(422, 'invalid_session', 'A valid session date and time is required.'); }
    $dur = max(15, min(240, (int)($body['duration_min'] ?? 45)));
    $iso = gmdate('Y-m-d\TH:i:s\Z', $ts);
    $stmt = $db->prepare('INSERT INTO meetings (starts_at, duration_min, status, created_by, created_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$iso, $dur, 'scheduled', (string)$account['player_key'], bochi_now_iso()]);
    bochi_json_response(['ok' => true, 'id' => (int)$db->lastInsertId()], 201);
}

function bochi_sessions_update(PDO $db, array $body): void
{
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { bochi_fail(422, 'invalid_session', 'A session id is required.'); }
    $status = (string)($body['status'] ?? '');
    if (!in_array($status, ['scheduled', 'held', 'cancelled'], true)) { bochi_fail(422, 'invalid_session', 'A valid status is required.'); }
    $db->prepare('UPDATE meetings SET status = ? WHERE id = ?')->execute([$status, $id]);
    bochi_json_response(['ok' => true]);
}

function bochi_sessions_delete(PDO $db, array $body): void
{
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { bochi_fail(422, 'invalid_session', 'A session id is required.'); }
    $db->prepare('DELETE FROM meetings WHERE id = ?')->execute([$id]);
    $db->prepare('DELETE FROM reminder_log WHERE session_id = ?')->execute([$id]);
    bochi_json_response(['ok' => true]);
}

function bochi_notify_prefs(PDO $db, array $account): array
{
    $leads = json_decode((string)($account['notify_leads'] ?? '[1]'), true);
    if (!is_array($leads)) { $leads = [1]; }
    $leads = array_values(array_filter(array_map('intval', $leads), function ($v) { return in_array($v, [0, 1, 7], true); }));
    $cnt = 0;
    try { $stmt = $db->prepare('SELECT COUNT(*) FROM push_subs WHERE user_id = ?'); $stmt->execute([(int)$account['id']]); $cnt = (int)$stmt->fetchColumn(); } catch (Throwable $e) {}
    return ['leads' => $leads, 'email' => (int)($account['notify_email'] ?? 1) === 1, 'pushCount' => $cnt];
}

function bochi_notify_prefs_get(PDO $db, array $account): void
{
    bochi_json_response(['ok' => true, 'prefs' => bochi_notify_prefs($db, $account)]);
}

function bochi_notify_prefs_set(PDO $db, array $account, array $body): void
{
    $leads = $body['leads'] ?? null;
    if (!is_array($leads)) { bochi_fail(422, 'invalid_prefs', 'Reminder times are invalid.'); }
    $clean = [];
    foreach ($leads as $v) { $v = (int)$v; if (in_array($v, [0, 1, 7], true) && !in_array($v, $clean, true)) { $clean[] = $v; } }
    sort($clean);
    $email = !empty($body['email']) ? 1 : 0;
    $db->prepare('UPDATE users SET notify_leads = ?, notify_email = ? WHERE id = ?')->execute([json_encode($clean), $email, (int)$account['id']]);
    $account['notify_leads'] = json_encode($clean);
    $account['notify_email'] = $email;
    bochi_json_response(['ok' => true, 'prefs' => bochi_notify_prefs($db, $account)]);
}

function bochi_push_subscribe(PDO $db, array $account, array $body): void
{
    $endpoint = (string)($body['endpoint'] ?? '');
    $p256dh = (string)($body['keys']['p256dh'] ?? '');
    $auth = (string)($body['keys']['auth'] ?? '');
    if (strpos($endpoint, 'https://') !== 0 || $p256dh === '' || $auth === '') { bochi_fail(422, 'invalid_subscription', 'That notification subscription is invalid.'); }
    $stmt = $db->prepare('INSERT INTO push_subs (user_id, endpoint, p256dh, auth, created_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT(endpoint) DO UPDATE SET user_id = excluded.user_id, p256dh = excluded.p256dh, auth = excluded.auth');
    $stmt->execute([(int)$account['id'], $endpoint, $p256dh, $auth, bochi_now_iso()]);
    bochi_json_response(['ok' => true], 201);
}

function bochi_push_unsubscribe(PDO $db, array $account, array $body): void
{
    $endpoint = (string)($body['endpoint'] ?? '');
    $db->prepare('DELETE FROM push_subs WHERE endpoint = ? AND user_id = ?')->execute([$endpoint, (int)$account['id']]);
    bochi_json_response(['ok' => true]);
}

function bochi_der_to_raw_sig(string $der): string
{
    $off = 0;
    if (ord($der[$off]) !== 0x30) { return str_repeat("\x00", 64); }
    $off++;
    $len = ord($der[$off++]);
    if ($len & 0x80) { $n = $len & 0x7f; $off += $n; }
    $readInt = function () use ($der, &$off) {
        $off++; // 0x02
        $l = ord($der[$off++]);
        $v = substr($der, $off, $l);
        $off += $l;
        $v = ltrim($v, "\x00");
        return str_pad($v, 32, "\x00", STR_PAD_LEFT);
    };
    return $readInt() . $readInt();
}

function bochi_vapid_jwt(string $endpoint, array $vapid): string
{
    $aud = (string)parse_url($endpoint, PHP_URL_SCHEME) . '://' . (string)parse_url($endpoint, PHP_URL_HOST);
    $head = bochi_b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = bochi_b64url_encode(json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => 'mailto:Info@bolu.tech']));
    $signing = $head . '.' . $payload;
    $key = openssl_pkey_get_private((string)$vapid['private_pem']);
    openssl_sign($signing, $sig, $key, OPENSSL_ALGO_SHA256);
    return $signing . '.' . bochi_b64url_encode(bochi_der_to_raw_sig($sig));
}

function bochi_ec_public_resource(string $rawPub)
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $rawPub;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
}

function bochi_webpush_send(array $sub, string $payloadJson, array $vapid): int
{
    $uaPublic = bochi_b64url_decode((string)$sub['p256dh']);
    $authSecret = bochi_b64url_decode((string)$sub['auth']);
    if (strlen($uaPublic) !== 65 || strlen($authSecret) < 16) { return 400; }
    $asKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($asKey === false) { return 500; }
    $details = openssl_pkey_get_details($asKey);
    $asPublic = "\x04" . str_pad((string)$details['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad((string)$details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $uaRes = bochi_ec_public_resource($uaPublic);
    if ($uaRes === false) { return 400; }
    $secret = openssl_pkey_derive($uaRes, $asKey, 32);
    if ($secret === false) { return 500; }
    $salt = random_bytes(16);
    // RFC 8291 key schedule: ECDH secret with the auth secret as extract salt,
    $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\x00" . $uaPublic . $asPublic, $authSecret);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
    $plain = $payloadJson . "\x02";
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false) { return 500; }
    $body = $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    $jwt = bochi_vapid_jwt((string)$sub['endpoint'], $vapid);
    $headers = [
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'Content-Length: ' . strlen($body),
        'TTL: 86400',
        'Urgency: high',
        'Authorization: vapid t=' . $jwt . ', k=' . $vapid['public'],
    ];
    if (function_exists('curl_init')) {
        $ch = curl_init((string)$sub['endpoint']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $code;
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => 20, 'ignore_errors' => true]]);
    @file_get_contents((string)$sub['endpoint'], false, $ctx);
    foreach (($http_response_header ?? []) as $h) { if (preg_match('/^HTTP\/\S+ (\d+)/', $h, $m)) { return (int)$m[1]; } }
    return 0;
}

function bochi_reminders_run(PDO $db, array $body): void
{
    $key = (string)($body['key'] ?? '');
    if (!hash_equals(bochi_cron_token(), $key)) { bochi_fail(403, 'cron_forbidden', 'Bad reminder key.'); }
    $vapid = bochi_vapid();
    $now = time();
    $sent = 0;
    $users = $db->query('SELECT * FROM users WHERE disabled_at IS NULL')->fetchAll() ?: [];
    $sessions = $db->query("SELECT * FROM meetings WHERE status = 'scheduled' ORDER BY starts_at ASC")->fetchAll() ?: [];
    foreach ($sessions as $sess) {
        $start = strtotime((string)$sess['starts_at']);
        if ($start === false || $start <= $now) { continue; }
        foreach ($users as $user) {
            $prefs = bochi_notify_prefs($db, $user);
            $tz = new DateTimeZone((string)($user['timezone'] ?? 'UTC'));
            foreach ($prefs['leads'] as $lead) {
                if ($lead === 0) {
                    $local = (new DateTimeImmutable('@' . $start))->setTimezone($tz)->setTime(9, 0);
                    $sendAt = $local->getTimestamp();
                } else {
                    $sendAt = $start - $lead * 86400;
                }
                if ($now < $sendAt || $now >= $start) { continue; }
                $check = $db->prepare('SELECT COUNT(*) FROM reminder_log WHERE session_id = ? AND user_id = ? AND lead_days = ?');
                $check->execute([(int)$sess['id'], (int)$user['id'], $lead]);
                if ((int)$check->fetchColumn() > 0) { continue; }
                $when = (new DateTimeImmutable('@' . $start))->setTimezone($tz)->format('l, M j \a\t g:i A');
                $partnerName = ((string)$user['player_key'] === 'bolu') ? 'Chihiro' : 'Boru-san';
                $leadText = $lead === 7 ? 'in one week' : ($lead === 1 ? 'tomorrow' : 'today');
                $title = 'BoChi session ' . $leadText;
                $text = 'Your language session with ' . $partnerName . ' is ' . $leadText . ', ' . $when . ' (' . (int)$sess['duration_min'] . ' min). Google Meet link is in your Calendly confirmation.';
                $channels = [];
                $subs = $db->prepare('SELECT * FROM push_subs WHERE user_id = ?');
                $subs->execute([(int)$user['id']]);
                $pushOk = false;
                foreach ($subs->fetchAll() ?: [] as $sub) {
                    $code = bochi_webpush_send($sub, json_encode(['title' => $title, 'body' => $text, 'url' => '/']), $vapid);
                    if ($code >= 200 && $code < 300) { $pushOk = true; }
                    if ($code === 404 || $code === 410) { $db->prepare('DELETE FROM push_subs WHERE id = ?')->execute([(int)$sub['id']]); }
                }
                if ($pushOk) { $channels[] = 'push'; }
                if ($prefs['email'] && !$pushOk) {
                    $okMail = @mail((string)$user['email'], $title, $text . "\n\nOpen BoChi: https://bochi.bolu.tech/", 'From: BoChi <info@bolu.tech>');
                    if ($okMail) { $channels[] = 'email'; }
                }
                if ($channels) {
                    $db->prepare('INSERT OR IGNORE INTO reminder_log (session_id, user_id, lead_days, channels, sent_at) VALUES (?, ?, ?, ?, ?)')
                        ->execute([(int)$sess['id'], (int)$user['id'], $lead, implode(',', $channels), bochi_now_iso()]);
                    $sent++;
                }
            }
        }
    }
    bochi_json_response(['ok' => true, 'sent' => $sent]);
}

function bochi_recordings_dir(): string
{
    $config = bochi_config();
    $dir = dirname((string)$config['db_path']) . '/recordings';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function bochi_recording_public(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'owner' => (string)$row['owner_player'],
        'ownerName' => BOCHI_PLAYER_NAMES[$row['owner_player']] ?? $row['owner_player'],
        'title' => (string)$row['title'],
        'kind' => (string)$row['kind'],
        'sessionDate' => $row['session_date'],
        'note' => (string)($row['note'] ?? ''),
        'externalUrl' => $row['external_url'],
        'fileSize' => $row['file_size'] !== null ? (int)$row['file_size'] : null,
        'mime' => $row['mime'],
        'fileUrl' => $row['stored_name'] ? 'api.php?action=recording-file&id=' . (int)$row['id'] : null,
        'createdAt' => (string)$row['created_at'],
    ];
}

function bochi_recordings_list(PDO $db, array $account): void
{
    bochi_require_user($db);
    $stmt = $db->query('SELECT * FROM recordings ORDER BY COALESCE(session_date, substr(created_at, 1, 10)) DESC, id DESC');
    $rows = array_map('bochi_recording_public', $stmt->fetchAll());
    $maxUpload = (int)min(
        bochi_parse_ini_bytes((string)ini_get('upload_max_filesize')),
        bochi_parse_ini_bytes((string)ini_get('post_max_size'))
    );
    bochi_json_response(['ok' => true, 'recordings' => $rows, 'maxUploadBytes' => $maxUpload]);
}

function bochi_parse_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return PHP_INT_MAX;
    }
    $unit = strtoupper(substr($value, -1));
    $num = (float)$value;
    if ($unit === 'G') { $num *= 1073741824; }
    elseif ($unit === 'M') { $num *= 1048576; }
    elseif ($unit === 'K') { $num *= 1024; }
    return (int)$num;
}

function bochi_recordings_create(PDO $db, array $account, array $body): void
{
    $kind = (string)($body['kind'] ?? '');
    if (!in_array($kind, ['link', 'summary'], true)) {
        bochi_fail(422, 'validation_error', 'Only link and summary entries are created this way.');
    }
    $title = trim((string)($body['title'] ?? ''));
    if ($title === '' || strlen($title) > 160) {
        bochi_fail(422, 'validation_error', 'Give the entry a title of up to 160 characters.');
    }
    $sessionDate = isset($body['sessionDate']) && is_string($body['sessionDate']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['sessionDate']) ? $body['sessionDate'] : null;
    $note = trim((string)($body['note'] ?? ''));
    if (strlen($note) > 20000) {
        bochi_fail(422, 'validation_error', 'That note is too long.');
    }
    $url = null;
    if ($kind === 'link') {
        $url = trim((string)($body['externalUrl'] ?? ''));
        if (strpos($url, 'https://') !== 0 || strlen($url) > 2000) {
            bochi_fail(422, 'validation_error', 'Paste a full https link, for example a Google Drive share link.');
        }
    }
    if ($kind === 'summary' && $note === '') {
        bochi_fail(422, 'validation_error', 'The summary text is empty.');
    }
    $stmt = $db->prepare('INSERT INTO recordings (owner_player, title, kind, session_date, note, external_url, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$account['player_key'], $title, $kind, $sessionDate, $note, $url, bochi_now_iso()]);
    bochi_json_response(['ok' => true, 'id' => (int)$db->lastInsertId()], 201);
}

function bochi_recordings_upload(PDO $db, array $account): void
{
    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        bochi_fail(422, 'validation_error', 'Choose a recording file to upload.');
    }
    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $tooBig = in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        bochi_fail($tooBig ? 413 : 422, 'upload_failed', $tooBig
            ? 'That file is larger than this server accepts in one upload. For a full meeting recording, add a Google Drive link instead.'
            : 'The upload did not complete. Try again.');
    }
    $allowed = [
        'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
        'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/ogg' => 'ogg',
    ];
    $mime = (string)($file['type'] ?? '');
    if (!isset($allowed[$mime])) {
        bochi_fail(422, 'validation_error', 'Upload a video (MP4, WebM, MOV) or audio (MP3, M4A, WAV, OGG) file.');
    }
    if ((int)$file['size'] > 536870912) {
        bochi_fail(413, 'upload_failed', 'That file is over 512 MB. For a full meeting recording, add a Google Drive link instead.');
    }
    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '' || strlen($title) > 160) {
        $title = 'Bochi session recording';
    }
    $sessionDate = isset($_POST['sessionDate']) && is_string($_POST['sessionDate']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['sessionDate']) ? $_POST['sessionDate'] : null;
    $note = trim((string)($_POST['note'] ?? ''));
    if (strlen($note) > 2000) { $note = substr($note, 0, 2000); }

    $stored = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $dest = bochi_recordings_dir() . '/' . $stored;
    if (!@move_uploaded_file((string)$file['tmp_name'], $dest)) {
        bochi_fail(500, 'upload_failed', 'The file could not be saved. Try again.');
    }
    @chmod($dest, 0600);
    try {
        $stmt = $db->prepare('INSERT INTO recordings (owner_player, title, kind, session_date, note, stored_name, file_size, mime, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$account['player_key'], $title, 'upload', $sessionDate, $note, $stored, (int)$file['size'], $mime, bochi_now_iso()]);
    } catch (Throwable $e) {
        @unlink($dest);
        throw $e;
    }
    bochi_json_response(['ok' => true, 'id' => (int)$db->lastInsertId()], 201);
}

function bochi_recordings_delete(PDO $db, array $account, array $body): void
{
    $id = (int)($body['id'] ?? 0);
    $stmt = $db->prepare('SELECT stored_name FROM recordings WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        bochi_fail(404, 'not_found', 'That recording no longer exists.');
    }
    $db->prepare('DELETE FROM recordings WHERE id = ?')->execute([$id]);
    if (!empty($row['stored_name'])) {
        @unlink(bochi_recordings_dir() . '/' . $row['stored_name']);
    }
    bochi_json_response(['ok' => true]);
}

function bochi_settings_get(PDO $db): void
{
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute(['meet_folder_url']);
    $row = $stmt->fetch();
    bochi_json_response(['ok' => true, 'meetFolderUrl' => $row ? (string)$row['value'] : '']);
}

function bochi_settings_set(PDO $db, array $account, array $body): void
{
    $url = trim((string)($body['meetFolderUrl'] ?? ''));
    if ($url !== '' && (strpos($url, 'https://') !== 0 || strlen($url) > 2000)) {
        bochi_fail(422, 'validation_error', 'Paste a full https link to the Drive folder.');
    }
    $stmt = $db->prepare('INSERT INTO settings (key, value, updated_by, updated_at) VALUES (?, ?, ?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_by = excluded.updated_by, updated_at = excluded.updated_at');
    $stmt->execute(['meet_folder_url', $url, (string)$account['player_key'], bochi_now_iso()]);
    bochi_json_response(['ok' => true]);
}

function bochi_recording_file(PDO $db): void
{
    bochi_require_user($db);
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare('SELECT stored_name, mime, title FROM recordings WHERE id = ? AND stored_name IS NOT NULL');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        bochi_fail(404, 'not_found', 'That recording file no longer exists.');
    }
    $path = bochi_recordings_dir() . '/' . $row['stored_name'];
    if (!is_file($path)) {
        bochi_fail(404, 'not_found', 'That recording file no longer exists.');
    }
    $size = filesize($path);
    $start = 0;
    $end = $size - 1;
    $status = 200;
    if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string)$_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] !== '') { $start = (int)$m[1]; }
        if ($m[2] !== '') { $end = (int)$m[2]; }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $end = min($end, $size - 1);
        $status = 206;
    }
    http_response_code($status);
    header('Content-Type: ' . $row['mime']);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . ($end - $start + 1));
    if ($status === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$row['title']) . '"');
    header('Cache-Control: private, no-store');
    $fh = fopen($path, 'rb');
    fseek($fh, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($fh)) {
        $chunk = fread($fh, min(1048576, $remaining));
        if ($chunk === false) { break; }
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($fh);
    exit;
}

if (!defined('BOCHI_LIBRARY')) {
try {
    $db = bochi_db();
    $action = (string)($_GET['action'] ?? '');
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($action === 'setup') {
        if ($method !== 'POST') {
            bochi_fail(405, 'method_not_allowed', 'Method not allowed.');
        }
        bochi_setup($db, bochi_request_body());
    }

    if (in_array($action, ['register', 'login', 'logout', 'progress-xp'], true) && $method !== 'POST') {
        bochi_fail(405, 'method_not_allowed', 'Method not allowed.');
    }
    if ($action === 'progress' && !in_array($method, ['GET', 'PUT'], true)) {
        bochi_fail(405, 'method_not_allowed', 'Method not allowed.');
    }
    if ($action === 'me' && $method !== 'GET') {
        bochi_fail(405, 'method_not_allowed', 'Method not allowed.');
    }
    if ($action === 'recordings' && !in_array($method, ['GET', 'POST'], true)) {
        bochi_fail(405, 'method_not_allowed', 'Method not allowed.');
    }
    if ($action === 'recording-upload' && $method !== 'POST') {
        bochi_fail(405, 'method_not_allowed', 'Method not allowed.');
    }
    if ($action === 'recording-file' && $method !== 'GET') {
        bochi_fail(405, 'method_not_allowed', 'Method not allowed.');
    }
    if ($action === 'recording-delete' && $method !== 'POST') {
        bochi_fail(405, 'method_not_allowed', 'Method not allowed.');
    }
    if ($action === 'sessions' && !in_array($method, ['GET', 'POST'], true)) {
        bochi_fail(405, 'method_not_allowed', 'Method not allowed.');
    }
    if ($action === 'session-update' && $method !== 'POST') {
        bochi_fail(405, 'method_not_allowed', 'Method not allowed.');
    }
    if ($action === 'session-delete' && $method !== 'POST') {
        bochi_fail(405, 'method_not_allowed', 'Method not allowed.');
    }
    if ($action === 'notify-prefs' && !in_array($method, ['GET', 'POST'], true)) {
        bochi_fail(405, 'method_not_allowed', 'Method not allowed.');
    }
    if ($action === 'push-config' && $method !== 'GET') {
        bochi_fail(405, 'method_not_allowed', 'Method not allowed.');
    }
    if ($action === 'push-subscribe' && $method !== 'POST') {
        bochi_fail(405, 'method_not_allowed', 'Method not allowed.');
    }
    if ($action === 'push-unsubscribe' && $method !== 'POST') {
        bochi_fail(405, 'method_not_allowed', 'Method not allowed.');
    }
    if ($action === 'reminders-token' && $method !== 'GET') {
        bochi_fail(405, 'method_not_allowed', 'Method not allowed.');
    }
    if ($action === 'reminders-run' && $method !== 'POST') {
        bochi_fail(405, 'method_not_allowed', 'Method not allowed.');
    }
    if ($action === 'settings' && !in_array($method, ['GET', 'POST'], true)) {
        bochi_fail(405, 'method_not_allowed', 'Method not allowed.');
    }
    if (in_array($action, ['register', 'login', 'logout', 'progress-xp', 'recording-upload', 'recording-delete', 'session-update', 'session-delete', 'push-subscribe', 'push-unsubscribe', 'reminders-run'], true) || ($action === 'settings' && $method === 'POST') || ($action === 'progress' && $method === 'PUT') || ($action === 'recordings' && $method === 'POST') || ($action === 'sessions' && $method === 'POST') || ($action === 'notify-prefs' && $method === 'POST')) {
        bochi_require_write_guard();
    }

    switch ($action) {
        case 'register':
            bochi_register($db, bochi_request_body());
            break;
        case 'login':
            bochi_login($db, bochi_request_body());
            break;
        case 'logout':
            bochi_logout($db);
            break;
        case 'me':
            $account = bochi_require_user($db);
            bochi_json_response([
                'ok' => true,
                'account' => bochi_public_account($account),
                'partner' => bochi_public_partner($db, $account),
                'sessionExpiresAt' => (string)$account['session_expires_at'],
            ]);
            break;
        case 'progress':
            $account = bochi_require_user($db);
            if ($method === 'GET') {
                bochi_json_response([
                    'ok' => true,
                    'progressByPlayer' => bochi_progress_bundle($db, $account),
                ]);
            }
            bochi_update_detailed_progress($db, $account, bochi_request_body());
            break;
        case 'progress-xp':
            $account = bochi_require_user($db);
            bochi_add_xp_event($db, $account, bochi_request_body());
            break;
        case 'recordings':
            $account = bochi_require_user($db);
            if ($method === 'GET') {
                bochi_recordings_list($db, $account);
            }
            bochi_recordings_create($db, $account, bochi_request_body());
            break;
        case 'recording-upload':
            $account = bochi_require_user($db);
            bochi_recordings_upload($db, $account);
            break;
        case 'recording-file':
            bochi_recording_file($db);
            break;
        case 'recording-delete':
            $account = bochi_require_user($db);
            bochi_recordings_delete($db, $account, bochi_request_body());
            break;
        case 'sessions':
            $account = bochi_require_user($db);
            if ($method === 'GET') {
                bochi_sessions_list($db);
            }
            bochi_sessions_create($db, $account, bochi_request_body());
            break;
        case 'session-update':
            $account = bochi_require_user($db);
            bochi_sessions_update($db, bochi_request_body());
            break;
        case 'session-delete':
            $account = bochi_require_user($db);
            bochi_sessions_delete($db, bochi_request_body());
            break;
        case 'notify-prefs':
            $account = bochi_require_user($db);
            if ($method === 'GET') {
                bochi_notify_prefs_get($db, $account);
            }
            bochi_notify_prefs_set($db, $account, bochi_request_body());
            break;
        case 'push-config':
            $account = bochi_require_user($db);
            $vapid = bochi_vapid();
            bochi_json_response(['ok' => true, 'publicKey' => $vapid['public']]);
            break;
        case 'push-subscribe':
            $account = bochi_require_user($db);
            bochi_push_subscribe($db, $account, bochi_request_body());
            break;
        case 'push-unsubscribe':
            $account = bochi_require_user($db);
            bochi_push_unsubscribe($db, $account, bochi_request_body());
            break;
        case 'reminders-token':
            $account = bochi_require_user($db);
            if ((string)$account['player_key'] !== 'bolu') { bochi_fail(403, 'forbidden', 'Owner only.'); }
            bochi_json_response(['ok' => true, 'token' => bochi_cron_token()]);
            break;
        case 'reminders-run':
            bochi_reminders_run($db, bochi_request_body());
            break;
        case 'settings':
            $account = bochi_require_user($db);
            if ($method === 'GET') {
                bochi_settings_get($db);
            }
            bochi_settings_set($db, $account, bochi_request_body());
            break;
        default:
            bochi_fail(404, 'endpoint_not_found', 'That API endpoint does not exist.');
    }
} catch (BochiHttpError $e) {
    bochi_error_response($e);
} catch (Throwable $e) {
    error_log('Bochi API error: ' . $e->getMessage());
    bochi_json_response([
        'ok' => false,
        'error' => [
            'code' => 'server_error',
            'message' => 'Something went wrong. Try again later.',
        ],
    ], 500);
}
}
