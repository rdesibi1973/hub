<?php
/**
 * modules/security/mail_scan.php — mail security scan (CLI only). Logic: includes/mail_security_service.php.
 *
 * cPanel → Cron Jobs, every hour at :30 (the server is on US time with DST; the script itself
 * only runs at 06:xx EAT):
 *   30 * * * *  php /home4/savannp5/public_html/hub/modules/security/mail_scan.php --cron
 * or once a day from cron-job.org: mail_scan_cron.php (token access only; logic: mss_run() in the service).
 *
 * Daily: email only if there are alerts. Monday: weekly report, always. Scan error: SCAN FAILED email.
 * First run: seeds the approved baseline and emails the full state.
 * Reads cPanel over HTTPS UAPI with CPANEL_API_TOKEN (includes/config.php; no process started on the
 * account). If the token is missing the uapi command line is used; if the token call fails the scan
 * retries with uapi and says so at the top of the email (an expired / deleted token must not go unseen).
 *
 * Options:
 *   --cron          run only between 06:00 and 06:59 EAT (for the hourly cron line)
 *   --now           ignore the time (same as without --cron)
 *   --weekly        send the weekly report whatever the day
 *   --dry           scan and print the email; no DB write, no email
 *   --uapi=PATH     uapi binary (test: a wrong path must give SCAN FAILED)
 *   --approve=KEY   approve the current value of one item (key from the email), no email
 *   --approve-all   approve the whole current state, no email
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../includes/timezone.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/mail_helper.php';
require_once __DIR__ . '/includes/mail_security_service.php';

$opt = getopt('', ['cron', 'now', 'weekly', 'dry', 'uapi:', 'approve:', 'approve-all']);
if (isset($opt['cron']) && !isset($opt['now']) && (int)date('G') !== 6) exit(0);

$dry     = isset($opt['dry']);
$approve = isset($opt['approve-all']) ? true : ($opt['approve'] ?? null);
$db      = null;
$source  = null;

try {
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
                  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    hub_db_timezone($db);
    mss_schema($db);

    if ($approve !== null) {
        [$snap, $source, $note] = mss_collect_state($opt['uapi'] ?? null, true, $source);
        if ($note !== '') fwrite(STDERR, $note . "\n");
        $items = mss_items($snap);
        $keys = $approve === true ? array_keys($items) : [(string)$approve];
        if ($approve !== true && !isset($items[$approve])) {
            fwrite(STDERR, "Item not found in the current state: $approve\n");
            exit(1);
        }
        $n = $dry ? count($keys) : mss_approve($db, $items, $keys, 'cli');
        echo ($dry ? 'Would approve ' : 'Approved ') . $n . " item(s).\n";
        exit(0);
    }

    $res = mss_run($db, ['dry' => $dry, 'weekly' => isset($opt['weekly']), 'uapi' => $opt['uapi'] ?? null], $source);
    if (!empty($res['note'])) fwrite(STDERR, $res['note'] . "\n");
    echo $res['line'] . "\n";
    exit($res['mailed'] === 'mail_failed' ? 1 : 0);
} catch (Throwable $e) {
    $err = mss_error_text($e, $source);
    fwrite(STDERR, $err . "\n");
    if ($approve !== null) exit(1);   // run by hand: the error is on screen, no SCAN FAILED email / failed run
    mss_run_failed($db, $err, $source, $dry);
    exit(1);
}
