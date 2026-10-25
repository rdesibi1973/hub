<?php
// modules/leads/conflicts_cron.php — nightly check of the Dropbox conflicted copies in /001_Safari, from cron-job.org
// every 30 min (logic: dbxc_nightly() in includes/dbx_conflict_service.php):
//   …/modules/leads/conflicts_cron.php?token=XXX
// Works only between 01:00 and 07:00 EAT and until the night's run is complete: each call goes on where the last one
// stopped (about 4 min of work per call), then the owners get their memos and mails (with DBX_CONFLICTS_LIVE).
// Token: DBX_CONFLICTS_CRON_TOKEN if defined in includes/config.php (server-only), else MEMO_CRON_TOKEN.
// The reply ("started") goes out at once and the run goes on after it: cron-job.org gives up after 30 s.

ob_start();
require_once __DIR__ . '/config.php';
if (!defined('AGENT_API_KEY') && is_file(__DIR__ . '/../../includes/config.php')) {
    require_once __DIR__ . '/../../includes/config.php';   // DBX_CONFLICTS_* and MEMO_CRON_TOKEN
}
require_once __DIR__ . '/includes/dbx_conflict_service.php';

$expected = defined('DBX_CONFLICTS_CRON_TOKEN') ? DBX_CONFLICTS_CRON_TOKEN : (defined('MEMO_CRON_TOKEN') ? MEMO_CRON_TOKEN : '');
if ($expected === '') { ob_end_clean(); http_response_code(500); die("Cron token not configured\n"); }
if (!hash_equals((string)$expected, (string)($_GET['token'] ?? ''))) { ob_end_clean(); http_response_code(403); die("Forbidden\n"); }

ignore_user_abort(true);
@set_time_limit(330);
ob_end_clean();
$reply = date('Y-m-d H:i:s') . " EAT — DBX CONFLICTS: started\n";
header('Content-Type: text/plain; charset=UTF-8');
header('Content-Length: ' . strlen($reply));
header('Connection: close');
echo $reply;
if (function_exists('litespeed_finish_request')) litespeed_finish_request();
elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
else flush();

ob_start();   // nothing more reaches the caller; keeps stray warnings away
try {
    $msg = dbxc_nightly(db());
} catch (Throwable $e) {
    $msg = 'failed: ' . $e->getMessage();
}
try { dbxc_meta_set(db(), 'last_cron', date('Y-m-d H:i:s') . ' ' . mb_substr($msg, 0, 400)); } catch (Throwable $ignored) {}
ob_end_clean();
