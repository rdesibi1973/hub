<?php
/**
 * modules/security/mail_scan.php — mail security scan (CLI only). Logic: includes/mail_security_service.php.
 *
 * cPanel → Cron Jobs, every hour at :30 (the server is on US time with DST; the script itself
 * only runs at 06:xx EAT):
 *   30 * * * *  php /home4/savannp5/public_html/hub/modules/security/mail_scan.php --cron
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
$to      = defined('MAIL_SCAN_TO') ? MAIL_SCAN_TO : 'savannah.explorers@gmail.com';
$today   = date('Y-m-d');
$weekly  = isset($opt['weekly']) || date('N') === '1';
$approve = isset($opt['approve-all']) ? true : ($opt['approve'] ?? null);
$db      = null;
$source  = null;

function mss_send(string $to, array $mail, bool $dry): bool {
    [$subject, $html, $text] = $mail;
    if ($dry) {
        echo "--- DRY RUN: would send to $to ---\nSubject: $subject\n\n$text\n";
        return true;
    }
    return send_hub_email($to, $subject, $html, 'Savannah Explorers Hub', 'noreply@savannahexplorers.com', '', $text);
}

try {
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
                  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    hub_db_timezone($db);
    mss_schema($db);

    $ctx    = mss_api_context($opt['uapi'] ?? null);
    $source = $ctx['mode'];
    $note   = '';
    try {
        $snap = mss_collect($ctx);
    } catch (RuntimeException $e) {
        // Token rejected (expired / deleted) or cPanel HTTPS down: retry with the uapi command line.
        $cli = $ctx['mode'] === 'token' ? mss_uapi_context() : null;
        if (!$cli) throw $e;
        $note = 'The cPanel token did not work, the scan used uapi instead. Check CPANEL_API_TOKEN in includes/config.php '
              . '(cPanel → Security → Manage API Tokens). Error: ' . $e->getMessage();
        fwrite(STDERR, $note . "\n");
        $ctx    = $cli;
        $source = 'uapi';
        $snap   = mss_collect($ctx);
    }
    $items  = mss_items($snap);

    if ($approve !== null) {
        $keys = $approve === true ? array_keys($items) : [(string)$approve];
        if ($approve !== true && !isset($items[$approve])) {
            fwrite(STDERR, "Item not found in the current state: $approve\n");
            exit(1);
        }
        $n = $dry ? count($keys) : mss_approve($db, $items, $keys, 'cli');
        echo ($dry ? 'Would approve ' : 'Approved ') . $n . " item(s).\n";
        exit(0);
    }

    $last      = mss_last_snapshot($db);
    $prevItems = $last ? mss_items($last['snapshot']) : null;
    $first     = $last === null;

    if ($first && !$dry) {   // seed: everything except HIGH items (those stay alerted until approved)
        mss_approve($db, $items, array_keys(array_filter($items, function ($i) { return !$i['high']; })), 'first_run');
    }
    $baseline = $dry && $first
        ? array_map(function ($i) { return sha1($i['value']); }, array_filter($items, function ($i) { return !$i['high']; }))
        : mss_baseline($db);

    $alerts = mss_alerts($items, $prevItems, $baseline);
    $high   = mss_high_items($items, $baseline);
    $counts = mss_counts($snap) + ['alerts' => count($alerts),
                                   'high' => count(array_filter($alerts, function ($a) { return $a['level'] === 'HIGH'; }))];

    $type = $first ? 'first' : ($weekly ? 'weekly' : ($alerts || $note !== '' ? 'alert' : null));
    $mailed = 'none';
    if ($type !== null) {
        $mail = mss_email($type, ['date' => $today, 'note' => $note, 'counts' => $counts, 'alerts' => $alerts, 'high' => $high, 'items' => $items,
                                  'baseline_rows' => $type === 'weekly' ? mss_baseline_rows($db) : [],
                                  'expected_diff' => $first ? mss_expected_diff($snap) : []]);
        $mailed = mss_send($to, $mail, $dry) ? $type : 'mail_failed';
    }

    if (!$dry) {
        $snapId = mss_save_snapshot($db, $snap, $items, $last);
        $removed = array_column(array_filter($alerts, function ($a) { return $a['change'] === 'removed'; }), 'key');
        if ($removed) mss_unapprove($db, $removed);
        mss_log_run($db, true, null, $snapId, $source, $counts, ['alerts' => $alerts, 'high' => $high], $mailed);
    }
    echo date('Y-m-d H:i:s') . ' EAT — ' . mss_mailscan_line($counts) . '; mailed=' . ($dry ? 'dry' : $mailed) . "\n";
    exit($mailed === 'mail_failed' ? 1 : 0);
} catch (Throwable $e) {
    $err = get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' . ($source ? " [source $source]" : '');
    fwrite(STDERR, $err . "\n");
    if ($approve !== null) exit(1);   // run by hand: the error is on screen, no SCAN FAILED email / failed run
    $sent = mss_send($to, mss_email('failed', ['date' => $today, 'error' => $err]), $dry);
    if ($db && !$dry) {
        try {
            mss_log_run($db, false, $err, null, $source, null, null, $sent ? 'failed' : 'mail_failed');
        } catch (Throwable $e2) {
            fwrite(STDERR, 'Run log failed: ' . $e2->getMessage() . "\n");
        }
    }
    exit(1);
}
