<?php
// modules/security/mail_scan_cron.php — daily mail security scan from cron-job.org (like followup_cron.php),
// the same as the CLI `mail_scan.php` (logic: mss_run() in includes/mail_security_service.php):
//   daily 06:30 EAT   …/modules/security/mail_scan_cron.php?token=XXX
// Reads cPanel only over HTTPS with CPANEL_API_TOKEN: no uapi process from the web (if the token fails → SCAN FAILED).
// At most one scan a day: a second call the same day (or the cPanel cron at 06:30, if it runs again) is skipped.
// Token: MAIL_SCAN_CRON_TOKEN if defined in includes/config.php (server-only), else MEMO_CRON_TOKEN.

ob_start();
require_once __DIR__ . '/../../includes/db.php';         // $pdo (+ config, timezone)
require_once __DIR__ . '/includes/mail_security_service.php';

$expected = defined('MAIL_SCAN_CRON_TOKEN') ? MAIL_SCAN_CRON_TOKEN : (defined('MEMO_CRON_TOKEN') ? MEMO_CRON_TOKEN : '');
if ($expected === '') { ob_end_clean(); http_response_code(500); die("Cron token not configured\n"); }
if (!hash_equals((string)$expected, (string)($_GET['token'] ?? ''))) { ob_end_clean(); http_response_code(403); die("Forbidden\n"); }

@set_time_limit(300);
$source = null;
try {
    $res = mss_run($pdo, ['allow_uapi' => false, 'once_a_day' => true], $source);
    ob_end_clean();
    header('Content-Type: text/plain; charset=UTF-8');
    if ($res['mailed'] === 'mail_failed') http_response_code(500);
    echo $res['line'] . "\n";
} catch (Throwable $e) {
    $err = mss_error_text($e, $source);
    mss_run_failed($pdo, $err, $source);
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Mail security scan failed: ' . $err . "\n";
}
