<?php
// modules/leads/sales_response_cron.php — scheduled Sales Response run from cron-job.org (like followup_cron.php),
// the same as the CLI `sales_response_scan.php --cron` (logic: sr_run() in includes/sales_response_service.php):
//   daily 23:30 EAT (scan) and daily 07:05 EAT (mails)   …/modules/leads/sales_response_cron.php?token=XXX
// Scan + relink every call; only a call at 07:xx EAT sends the daily alert (with SALES_RESPONSE_LIVE) and, on Monday,
// the weekly report. A reminder from the page scans again first (sr_send_reminder()).
// One of the two schedulers is enough; if both run, a MySQL lock skips the overlapping call.
// Token: SALES_RESPONSE_CRON_TOKEN if defined in includes/config.php (server-only), else MEMO_CRON_TOKEN.

ob_start();
require_once __DIR__ . '/../../includes/db.php';         // $pdo (+ config, timezone)
require_once __DIR__ . '/includes/sales_response_service.php';

$expected = defined('SALES_RESPONSE_CRON_TOKEN') ? SALES_RESPONSE_CRON_TOKEN : (defined('MEMO_CRON_TOKEN') ? MEMO_CRON_TOKEN : '');
if ($expected === '') { ob_end_clean(); http_response_code(500); die("Cron token not configured\n"); }
if (!hash_equals((string)$expected, (string)($_GET['token'] ?? ''))) { ob_end_clean(); http_response_code(403); die("Forbidden\n"); }

@set_time_limit(300);
try {
    $res = sr_run($pdo, ['cron' => true]);
    ob_end_clean();
    header('Content-Type: text/plain; charset=UTF-8');
    if (!empty($res['failed'])) http_response_code(500);
    echo $res['line'] . "\n";
} catch (Throwable $e) {
    $err = sr_error_text($e);
    sr_run_failed($pdo, $err);
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Sales Response run failed: ' . $err . "\n";
}
