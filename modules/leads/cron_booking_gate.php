<?php
// modules/leads/cron_booking_gate.php
// Booking gate worker (includes/booking_gate_service.php): reads the pending supplier documents of the queued booking
// checks and evaluates a run when its last document is read. Call from cron-job.org every minute:
//   https://hub.savannahexplorers.com/modules/leads/cron_booking_gate.php?token=XXX
// Token: CK_CRON_TOKEN if defined in includes/config.php, else MEMO_CRON_TOKEN (as ck_cron.php).
// It answers at once (cron-job.org stops waiting after 30 s), then works for up to BG_TICK_SECONDS; a document
// started before that is finished.

require_once __DIR__ . '/config.php';
if (!defined('AGENT_API_KEY') && is_file(__DIR__ . '/../../includes/config.php')) require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/dropbox_helper.php';
require_once __DIR__ . '/includes/booking_gate_service.php';

$expected = defined('CK_CRON_TOKEN') ? CK_CRON_TOKEN : (defined('MEMO_CRON_TOKEN') ? MEMO_CRON_TOKEN : '');
if ($expected === '') { http_response_code(500); die('Cron token not configured'); }
if (!hash_equals($expected, (string)($_GET['token'] ?? ''))) { http_response_code(403); die('Forbidden'); }

header('Content-Type: text/plain');
$db = db();
hub_db_timezone($db);
bg_schema($db);
$pending = (int)$db->query("SELECT COUNT(*) FROM booking_gate_runs WHERE state IN ('queued','running')")->fetchColumn();
echo date('Y-m-d H:i:s') . " booking gate: $pending run(s) waiting\n";
if ($pending === 0) exit;

ignore_user_abort(true);
@set_time_limit(330);
if (function_exists('litespeed_finish_request')) litespeed_finish_request();
elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
else flush();
ob_start();   // nothing more reaches the caller
try {
    $res = bg_process($db, BG_TICK_SECONDS);
    if ($res['errors']) error_log('cron_booking_gate: ' . implode(' | ', $res['errors']));
} catch (Throwable $e) {
    error_log('cron_booking_gate failed: ' . $e->getMessage());
}
ob_end_clean();
