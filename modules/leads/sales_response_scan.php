<?php
/**
 * modules/leads/sales_response_scan.php — sales responsiveness scan (CLI only). Logic: includes/sales_response_service.php.
 *
 * Reads the HEADERS of the sellers' and shared mailboxes straight from the Maildirs as the cPanel user
 * (no IMAP, no passwords), links each mail to a request and measures the replies.
 *
 * cPanel → Cron Jobs, every hour at :05 (the server is on US time; the mails only go out at 07:xx EAT):
 *   5 * * * *  php /home4/savannp5/public_html/hub/modules/leads/sales_response_scan.php --cron
 *
 * Every run: incremental scan + relink. 07:xx EAT: daily alert (only with SALES_RESPONSE_LIVE and something to
 * report), Monday: weekly report (always; flagged TEST until LIVE). First run: TEST report with the mapping and
 * the 90-day backfill. Any error: SCAN FAILED email (at most once a day).
 *
 * Options:
 *   --cron          hourly cron mode (above)
 *   --check         print mail root, folders, file counts and the seller mapping; no DB write, no email
 *   --daily         send the daily alert now (TEST in the subject until LIVE)
 *   --weekly        send the weekly report now   (--week=YYYY-MM-DD: that week instead of last week)
 *   --report        send the first-run TEST report (mapping + 90 days) now
 *   --print         print the emails instead of sending them
 *   --no-scan       skip the scan (report on what is already indexed)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../includes/timezone.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/mail_helper.php';
require_once __DIR__ . '/includes/sales_response_service.php';

$opt   = getopt('', ['cron', 'check', 'daily', 'weekly', 'week:', 'report', 'print', 'no-scan']);
$cron  = isset($opt['cron']);
$print = isset($opt['print']);
$today = date('Y-m-d');
$cfg   = sr_cfg();
$db    = null;

function sr_send(array $mail, bool $print): bool {
    [$subject, $html, $text] = $mail;
    if ($print) {
        echo "--- would send to " . sr_cfg()['to'] . " ---\nSubject: $subject\n\n$text\n";
        return true;
    }
    return send_hub_email(sr_cfg()['to'], $subject, $html, 'Savannah Explorers Hub', 'noreply@savannahexplorers.com', '', $text);
}

try {
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
                  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    hub_db_timezone($db);

    if (isset($opt['check'])) {
        $root = sr_mail_root();
        echo "Mail root: $root" . (is_dir($root) ? '' : '  (NOT FOUND)') . "\n";
        foreach (sr_mailboxes() as $mb) {
            [$user, $dom] = explode('@', $mb, 2);
            $dir = "$root/$dom/$user";
            if (!is_dir($dir)) { echo "  $mb: NOT FOUND ($dir)\n"; continue; }
            echo "  $mb:\n";
            foreach (sr_folders($dir) as $name => $path) {
                $n = count((array)@glob($path . '/cur/*')) + count((array)@glob($path . '/new/*'));
                echo "      $name: $n files\n";
            }
        }
        echo "Sellers:\n";
        foreach (sr_sellers($db) as $s) {
            echo '  ' . str_pad($s['name'], 9) . ' ' . str_pad($s['mailbox'], 36) . ' agent '
               . ($s['agent_id'] ? $s['agent_id'] . ' "' . $s['agent_name'] . '"' : 'ERROR: ' . $s['error'])
               . '; leave: ' . ($s['leave_names'] ? implode(', ', $s['leave_names']) : 'none') . "\n";
        }
        echo 'Live: ' . ($cfg['live'] ? 'yes' : 'no (TEST)') . '; mails to ' . $cfg['to'] . "\n";
        exit(0);
    }

    sr_schema($db);
    $first = !(int)$db->query("SELECT COUNT(*) FROM sales_response_scan_state")->fetchColumn();
    $scan = [];
    $counts = [];
    if (!isset($opt['no-scan'])) {
        $scan = sr_scan($db);
        $link = sr_link($db);
        $purged = sr_purge($db);
        $counts = ['files_read' => array_sum(array_column($scan, 'read')), 'new_rows' => array_sum(array_column($scan, 'new')),
                   'folders' => count($scan), 'purged' => $purged] + $link;
    }

    $atSeven = (int)date('G') === 7;
    $send = [];
    if ($first || isset($opt['report'])) $send[] = 'first';
    if (isset($opt['daily']) || ($cron && $atSeven && $cfg['live'] && !sr_mailed_today($db, 'daily'))) $send[] = 'daily';
    if (isset($opt['weekly']) || ($cron && $atSeven && date('N') === '1' && !sr_mailed_today($db, 'weekly'))) $send[] = 'weekly';

    $mailed = [];
    $failed = false;
    foreach ($send as $type) {
        if ($type === 'first') $rep = sr_report($db, $today, null, 12);
        elseif ($type === 'weekly') $rep = sr_report($db, $opt['week'] ?? null);
        else $rep = sr_report($db, null, null, 0);
        if ($type === 'daily' && !$rep['unanswered'] && !isset($opt['daily'])) continue;   // nothing to report
        if (sr_send(sr_email($type, $rep, ['date' => $today, 'scan' => $scan]), $print)) $mailed[] = $type;
        else $failed = true;
    }
    $mailedStr = $failed ? 'mail_failed' : ($mailed ? implode('+', $mailed) : 'none');
    if (!$print) sr_log_run($db, true, null, $counts ?: null, $mailedStr);
    echo date('Y-m-d H:i:s') . ' EAT — SALESRESP: ' . json_encode($counts) . '; mailed=' . ($print ? 'print:' : '') . $mailedStr . "\n";
    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    $err = get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    fwrite(STDERR, $err . "\n");
    if (isset($opt['check'])) exit(1);
    // At most one SCAN FAILED email a day (the cron runs hourly). Without a DB, a stamp file decides.
    $stamp = sys_get_temp_dir() . '/sales_response_failed_' . $today;
    $already = false;
    try {
        $already = $db ? sr_mailed_today($db, 'failed') : is_file($stamp);
    } catch (Throwable $e2) {
        $already = is_file($stamp);
    }
    $sent = $already ? false : sr_send(sr_email('failed', null, ['date' => $today, 'error' => $err]), $print);
    if ($sent && !$print) @touch($stamp);
    if ($db && !$print) {
        try {
            sr_log_run($db, false, $err, null, $already ? 'none' : ($sent ? 'failed' : 'mail_failed'));
        } catch (Throwable $e2) {
            fwrite(STDERR, 'Run log failed: ' . $e2->getMessage() . "\n");
        }
    }
    exit(1);
}
