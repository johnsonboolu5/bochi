<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
// One-time maintenance: extend the active 'chihiro' invite expiry. Self-deletes on success.
$TOKEN_HASH = '093a633cc939e83df01702d6df5c8112acd0f53de2bdcd6881fc900daba514ef';
$t = (string)($_GET['t'] ?? '');
if (!hash_equals($TOKEN_HASH, hash('sha256', $t))) { http_response_code(403); echo "forbidden\n"; exit; }

$base = dirname(__DIR__, 2);
$dbPath = $base . '/private/bochi/bochi.sqlite';
$configFile = getenv('BOCHI_CONFIG');
if (!is_string($configFile) || $configFile === '') { $configFile = $base . '/private/bochi/config.php'; }
if (is_file($configFile)) { $cfg = include $configFile; if (is_array($cfg) && !empty($cfg['db_path'])) { $dbPath = (string)$cfg['db_path']; } }
$envDb = getenv('BOCHI_DB_PATH');
if (is_string($envDb) && $envDb !== '') { $dbPath = $envDb; }

try {
    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db->exec('PRAGMA busy_timeout = 5000');
    $sel = $db->prepare("SELECT id, player_key, expires_at FROM invites WHERE player_key = 'chihiro' AND used_at IS NULL AND revoked_at IS NULL ORDER BY id DESC LIMIT 1");
    $sel->execute();
    $row = $sel->fetch();
    if (!is_array($row)) { echo "status=no_active_invite\n"; exit; }
    $new = '2026-11-26T02:52:00+00:00';
    $upd = $db->prepare('UPDATE invites SET expires_at = ? WHERE id = ?');
    $upd->execute([$new, (int)$row['id']]);
    $chk = $db->prepare('SELECT id, player_key, expires_at, used_at, revoked_at FROM invites WHERE id = ?');
    $chk->execute([(int)$row['id']]);
    $after = $chk->fetch();
    echo "status=extended\n";
    echo 'invite_id=' . $after['id'] . "\n";
    echo 'player=' . $after['player_key'] . "\n";
    echo 'old_expires_at=' . $row['expires_at'] . "\n";
    echo 'new_expires_at=' . $after['expires_at'] . "\n";
    echo 'used_at=' . ($after['used_at'] === null ? 'NULL' : $after['used_at']) . "\n";
    echo 'revoked_at=' . ($after['revoked_at'] === null ? 'NULL' : $after['revoked_at']) . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'status=error message=' . $e->getMessage() . "\n";
    exit;
}
@unlink(__FILE__);
echo "script=self_deleted\n";
