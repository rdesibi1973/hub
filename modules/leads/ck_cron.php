<?php
// modules/leads/ck_cron.php
// Scheduled CK-tracker scan, so stage / _CK changes made in Dropbox get their
// real date even when nobody opens ck_tracker.php. It also starts the nightly
// SafariCheck run once a day (ck_nightly_dispatch). Call from the external cron
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
} catch (Throwable $e) {
    http_response_code(500);
    echo 'CK scan failed: ' . $e->getMessage() . "\n";
}
