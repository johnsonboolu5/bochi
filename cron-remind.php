<?php
// BoChi reminder trigger. Safe to call on a schedule (every 30 minutes):
// it only checks which session reminders are due and sends those not yet
// sent. It returns no account data. Point any cron or uptime pinger here.
declare(strict_types=1);
define('BOCHI_LIBRARY', true);
require __DIR__ . '/api.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $db = bochi_db();
    bochi_reminders_run($db, ['key' => bochi_cron_token()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
