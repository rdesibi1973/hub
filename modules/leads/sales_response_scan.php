<?php
/**
 * modules/leads/sales_response_scan.php — sales responsiveness scan (CLI only). Logic: includes/sales_response_service.php.
 *
 * Reads the HEADERS of the sellers' and shared mailboxes straight from the Maildirs as the cPanel user
 * (no IMAP, no passwords), links each mail to a request and measures the replies.
 *
 * cPanel → Cron Jobs, every hour at :05 (the server is on US time; the mails only go out at 07:xx EAT):
 *   5 * * * *  php /home4/savannp5/public_html/hub/modules/leads/sales_response_scan.php --cron
 * or the same run from cron-job.org: sales_response_cron.php (one of the two is enough; a MySQL lock stops overlaps).
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
$cfg   = sr_cfg();
$db    = null;

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

    $res = sr_run($db, ['cron' => $cron, 'daily' => isset($opt['daily']), 'weekly' => isset($opt['weekly']), 'week' => $opt['week'] ?? null,
                        'report' => isset($opt['report']), 'print' => $print, 'no_scan' => isset($opt['no-scan'])]);
    echo ($res['output'] ?? '') . $res['line'] . "\n";
    exit(!empty($res['failed']) ? 1 : 0);
} catch (Throwable $e) {
    $err = sr_error_text($e);
    fwrite(STDERR, $err . "\n");
    if (isset($opt['check'])) exit(1);
    sr_run_failed($db, $err, $print);
    exit(1);
}
