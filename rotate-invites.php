<?php
declare(strict_types=1);
const ROTATE_HASH = '782c253dc67837f7c54d91902cb863e7251cfe921e0426514964fe1d1acbea47';
const ROTATE_MARKER = 'invite_rotation_short_20261021';
function fail_(int $s, string $m): void { http_response_code($s); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode(['ok'=>false,'message'=>$m]); exit; }
function cfg_(): array { $base = dirname(__DIR__, 2); $c = ['db_path'=>$base.'/private/bochi/bochi.sqlite','invite_pepper'=>'']; $f = getenv('BOCHI_CONFIG') ?: $base.'/private/bochi/config.php'; if (is_file($f)) { $x = include $f; if (is_array($x)) $c = array_merge($c,$x); } if (getenv('BOCHI_DB_PATH')) $c['db_path'] = getenv('BOCHI_DB_PATH'); if (getenv('BOCHI_INVITE_PEPPER')) $c['invite_pepper'] = getenv('BOCHI_INVITE_PEPPER'); return $c; }
function ihash_(string $code, string $pepper): string { return $pepper !== '' ? hash_hmac('sha256','invite:'.$code,$pepper) : hash('sha256','invite:'.$code); }
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') fail_(405,'Method not allowed.');
$tok = (string)($_SERVER['HTTP_X_BOCHI_ROTATE'] ?? '');
if ($tok === '' || !hash_equals(ROTATE_HASH, hash('sha256',$tok))) fail_(403,'Rotation is not allowed.');
$c = cfg_(); $path = (string)($c['db_path'] ?? ''); if ($path === '' || !is_file($path)) fail_(500,'Database unavailable.');
try {
  $db = new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
  $db->exec('PRAGMA journal_mode = WAL'); $db->exec('PRAGMA busy_timeout = 5000');
  $db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '', updated_by TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL)");
  $nowTs = time(); $now = gmdate('Y-m-d\TH:i:sP',$nowTs); $exp = gmdate('Y-m-d\TH:i:sP',$nowTs + 7*86400); $pepper = (string)($c['invite_pepper'] ?? '');
  $db->exec('BEGIN IMMEDIATE');
  $m = $db->prepare('SELECT value FROM settings WHERE key = ?'); $m->execute([ROTATE_MARKER]);
  if (is_array($m->fetch())) { $db->exec('ROLLBACK'); fail_(409,'Already rotated.'); }
  $reg = []; foreach ($db->query('SELECT player_key FROM users')->fetchAll() as $u) $reg[(string)$u['player_key']] = true;
  $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; $max = strlen($alpha)-1; $chk = $db->prepare('SELECT id FROM invites WHERE code_hash = ?');
  $out = [];
  foreach (['bolu','chihiro'] as $pk) {
    if (!empty($reg[$pk])) { $out[$pk] = ['registered'=>true,'inviteCode'=>null]; continue; }
    $db->prepare('UPDATE invites SET revoked_at = ? WHERE player_key = ? AND used_at IS NULL AND revoked_at IS NULL')->execute([$now,$pk]);
    do { $ch=''; for($i=0;$i<12;$i++) $ch .= $alpha[random_int(0,$max)]; $code = 'BoChi-'.substr($ch,0,4).'-'.substr($ch,4,4).'-'.substr($ch,8,4); $chk->execute([ihash_($code,$pepper)]); } while (is_array($chk->fetch()));
    $db->prepare('INSERT INTO invites (player_key, code_hash, created_at, expires_at) VALUES (?, ?, ?, ?)')->execute([$pk, ihash_($code,$pepper), $now, $exp]);
    $out[$pk] = ['registered'=>false,'inviteCode'=>$code,'expiresAt'=>$exp];
  }
  $db->prepare('INSERT INTO settings (key, value, updated_by, updated_at) VALUES (?, ?, ?, ?)')->execute([ROTATE_MARKER,$now,'system',$now]);
  $db->exec('COMMIT');
  @unlink(__FILE__);
  http_response_code(201); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
  echo json_encode(['ok'=>true,'invites'=>$out], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) { if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack(); error_log('BoChi invite rotation failed: '.$e->getMessage()); fail_(500,'Invite rotation failed.'); }
