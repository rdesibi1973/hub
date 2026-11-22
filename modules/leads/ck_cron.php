<?php
// modules/leads/ck_cron.php
// Scheduled CK-tracker scan, so stage / _CK changes made in Dropbox get their
// real date even when nobody opens ck_tracker.php. It also starts the nightly
// SafariCheck run once a day (ck_nightly_dispatch); at night it also checks the Dropbox conflicted copies,
// after the reply (dbxc_nightly). Call from the external cron
// service (same one as the memo reminders), e.g. every 30 min:
//   https://hub.savannahexplorers.com/modules/leads/ck_cron.php?token=XXX
//
// Token: CK_CRON_TOKEN if defined in includes/config.php (outside the repo),
// otherwise the existing MEMO_CRON_TOKEN.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/dropbox_helper.php';
require_once __DIR__ . '/includes/folder_parser.php';
require_once __DIR__ . '/includes/ck_lib.php';

$expected = defined('CK_CRON_TOKEN') ? CK_CRON_TOKEN : (defined('MEMO_CRON_TOKEN') ? MEMO_CRON_TOKEN : '');
if ($expected === '') {
    http_response_code(500);
    die('Cron token not configured');
}
if (!hash_equals($expected, (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/plain');
try {
    $s = ck_scan(db(), dropbox_get_access_token());
    echo ck_now() . " CK scan: {$s['seen']} folders, {$s['new']} new, {$s['changed']} changed, {$s['gone']} gone\n";
    // Once a day (first call after 05:00 Tanzania): the nightly automatic check.
    echo ck_now() . ' ' . ck_nightly_dispatch(db()) . "\n";
    // Postponed safaris: reminders 60 / 30 days before the deadline and when it
    // passes (each sent once; see includes/postpone_lib.php).
    require_once __DIR__ . '/../../includes/mail_helper.php';
    require_once __DIR__ . '/includes/postpone_lib.php';
    echo ck_now() . ' ' . pp_send_reminders(db()) . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'CK scan failed: ' . $e->getMessage() . "\n";
}

// Dropbox conflicted copies (includes/dbx_conflict_service.php): does something only 01:00–07:00 EAT until the night
// is done, and runs after the reply is closed, so cron-job.org's 30 s limit does not apply. Result in the page.
ignore_user_abort(true);
@set_time_limit(330);
if (function_exists('litespeed_finish_request')) litespeed_finish_request();
elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
else flush();
ob_start();   // nothing more reaches the caller
try {
    if (!defined('AGENT_API_KEY') && is_file(__DIR__ . '/../../includes/config.php')) {
        require_once __DIR__ . '/../../includes/config.php';   // DBX_CONFLICTS_LIVE
    }
    require_once __DIR__ . '/includes/dbx_conflict_service.php';
    $dbxMsg = dbxc_nightly(db());
} catch (Throwable $e) {
    $dbxMsg = 'failed: ' . $e->getMessage();
}
try { dbxc_meta_set(db(), 'last_cron', date('Y-m-d H:i:s') . ' (ck_cron) ' . mb_substr($dbxMsg, 0, 400)); } catch (Throwable $ignored) {}

// Folder links (includes/folder_link_service.php): once a day after 05:00 EAT — requests whose folder cannot be
// resolved + folders no request knows → one memo for Roberto (folders-orphans); folders moved outside the Hub are healed.
try {
    @set_time_limit(150);   // a fresh budget after the conflicted copies (orphan check ≤ 90 s)
    require_once __DIR__ . '/includes/booking_service.php';
    require_once __DIR__ . '/includes/folder_link_service.php';
    error_log('ck_cron ' . folder_orphans_nightly(db()));
} catch (Throwable $e) {
    error_log('ck_cron folders: ' . $e->getMessage());
}

// Parked requests (includes/parked_service.php): wake-ups from client mails linked by Sales Response (every run);
// once a day after 05:00 the auto-close of trips that are over — report-only until PARKED_AUTOCLOSE_LIVE.
try {
    require_once __DIR__ . '/includes/booking_service.php';
    error_log('ck_cron ' . pk_cron(db()));
} catch (Throwable $e) {
    error_log('ck_cron parked: ' . $e->getMessage());
}

// Jeep demand (operations/includes/jeep_service.php, page Operations → Jeep demand): every run lists /001_Safari +
// this year's archives, then re-checks the folders not checked for 20 h and re-reads the Calcs that changed (≤ 60 s,
// resumes on the next run). Also works through a full rebuild started from the page.
try {
    @set_time_limit(120);
    require_once __DIR__ . '/../operations/includes/jeep_service.php';
    $jr = jeep_scan_run(db(), ['mode' => 'incremental', 'budget' => 60]);
    if ($jr['read'] || $jr['failed'] || !empty($jr['discover']['dirs_failed'])) error_log('ck_cron jeeps ' . jeep_run_summary($jr));
} catch (Throwable $e) {
    error_log('ck_cron jeeps: ' . $e->getMessage());
}
ob_end_clean();
