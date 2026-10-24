<?php
// modules/leads/sales_response_cron.php — scheduled Sales Response run from cron-job.org (like followup_cron.php),
// the same as the CLI `sales_response_scan.php --cron` (logic: sr_run() in includes/sales_response_service.php):
//   daily 23:30 EAT (scan) and daily 07:05 EAT (mails)   …/modules/leads/sales_response_cron.php?token=XXX
// Scan + relink every call; only a call at 07:xx EAT sends the daily alert (with SALES_RESPONSE_LIVE) and, on Monday,
// the weekly report. A reminder from the page scans again first (sr_send_reminder()).
// One of the two schedulers is enough; if both run, a MySQL lock skips the overlapping call.
// Token: SALES_RESPONSE_CRON_TOKEN if defined in includes/config.php (server-only), else MEMO_CRON_TOKEN.
// The reply ("started") goes out at once and the run goes on after it: cron-job.org gives up after 30 s and a run
// with many new mails (Gmail backfill, relink) takes longer. A failed run still sends the SCAN FAILED mail and is
// in sales_response_run; cron-job.org only sees token / server errors.

ob_start();
require_once __DIR__ . '/../../includes/db.php';         // $pdo (+ config, timezone)
require_once __DIR__ . '/includes/sales_response_service.php';

$expected = defined('SALES_RESPONSE_CRON_TOKEN') ? SALES_RESPONSE_CRON_TOKEN : (defined('MEMO_CRON_TOKEN') ? MEMO_CRON_TOKEN : '');
if ($expected === '') { ob_end_clean(); http_response_code(500); die("Cron token not configured\n"); }
if (!hash_equals((string)$expected, (string)($_GET['token'] ?? ''))) { ob_end_clean(); http_response_code(403); die("Forbidden\n"); }

ignore_user_abort(true);
@set_time_limit(300);
ob_end_clean();
$reply = date('Y-m-d H:i:s') . " EAT — SALESRESP: started\n";
header('Content-Type: text/plain; charset=UTF-8');
header('Content-Length: ' . strlen($reply));
header('Connection: close');
echo $reply;
if (function_exists('litespeed_finish_request')) litespeed_finish_request();
elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
else flush();

ob_start();   // nothing more reaches the caller; keeps stray warnings away
try {
    sr_run($pdo, ['cron' => true]);
} catch (Throwable $e) {
    sr_run_failed($pdo, sr_error_text($e));
}
ob_end_clean();
