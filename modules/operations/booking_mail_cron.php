<?php
// modules/operations/booking_mail_cron.php — headers of glady@ / operations@ for the Booking mail check, from cron-job.org
// (daily is enough): …/modules/operations/booking_mail_cron.php?token=XXX
// Token: BOOKING_MAIL_CRON_TOKEN if defined in includes/config.php, else MEMO_CRON_TOKEN. Does nothing until BOOKING_MAIL_LIVE.
// The reply goes out at once and the run goes on after it (cron-job.org gives up after 30 s); the result is in booking_mail_run.

ob_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../leads/config.php';
require_once __DIR__ . '/includes/booking_mail_service.php';

$expected = defined('BOOKING_MAIL_CRON_TOKEN') ? BOOKING_MAIL_CRON_TOKEN : (defined('MEMO_CRON_TOKEN') ? MEMO_CRON_TOKEN : '');
if ($expected === '') { ob_end_clean(); http_response_code(500); die("Cron token not configured\n"); }
if (!hash_equals((string)$expected, (string)($_GET['token'] ?? ''))) { ob_end_clean(); http_response_code(403); die("Forbidden\n"); }

ignore_user_abort(true);
@set_time_limit(300);
ob_end_clean();
$reply = date('Y-m-d H:i:s') . " EAT — BOOKINGMAIL: started\n";
header('Content-Type: text/plain; charset=UTF-8');
header('Content-Length: ' . strlen($reply));
header('Connection: close');
echo $reply;
if (function_exists('litespeed_finish_request')) litespeed_finish_request();
elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
else flush();

ob_start();
try {
    hub_db_timezone($pdo);
    bm_run($pdo);
} catch (Throwable $e) {
    error_log('booking_mail_cron: ' . $e->getMessage());
}
ob_end_clean();
