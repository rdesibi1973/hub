<?php
// modules/operations/followup_cron.php — scheduled Booking follow-up check (cron-job.org, like the memo digest):
//   tickets   daily 05:30 EAT   …/modules/operations/followup_cron.php?token=XXX&type=ticket
//   supplier  Monday 05:30 EAT  …/modules/operations/followup_cron.php?token=XXX&type=supplier
//   group     daily 05:40 EAT   …/modules/operations/followup_cron.php?token=XXX&type=group   (GRP / Diamante / private groups arriving within 60 days)
//     &dry=1 → summary only, nothing written
// Token: FOLLOWUP_CRON_TOKEN if defined in includes/config.php (server-only), else MEMO_CRON_TOKEN.
// The supplier check needs a PDF reader on the server (see followup_service.php); without one it
// only closes the lines of trips that ended, and the office PC runs tools/followup_local_scan.php.

ob_start();
require_once __DIR__ . '/../leads/config.php';          // $pdo, Dropbox keys
require_once __DIR__ . '/includes/followup_service.php';

$expected = defined('FOLLOWUP_CRON_TOKEN') ? FOLLOWUP_CRON_TOKEN : (defined('MEMO_CRON_TOKEN') ? MEMO_CRON_TOKEN : '');
if ($expected === '') { ob_end_clean(); http_response_code(500); die("Cron token not configured\n"); }
if (!hash_equals((string)$expected, (string)($_GET['token'] ?? ''))) { ob_end_clean(); http_response_code(403); die("Forbidden\n"); }

$type = in_array($_GET['type'] ?? '', ['supplier', 'ticket', 'group', 'all'], true) ? $_GET['type'] : 'ticket';
$dry  = !empty($_GET['dry']);
hub_db_timezone($pdo);

try {
    $res = followup_scan($pdo, ['type' => $type, 'confirm' => !$dry, 'source' => 'cron']);
    ob_end_clean();
    header('Content-Type: text/plain; charset=UTF-8');
    foreach (['supplier', 'ticket', 'group'] as $t) {
        if (!isset($res[$t])) continue;
        $c = followup_counts($res[$t]);
        echo date('Y-m-d H:i') . " $t" . ($dry ? ' (dry run)' : '') . ": {$c['added']} new, {$c['updated']} updated, {$c['closed']} closed, "
           . "{$c['reopened']} re-opened, {$c['unreadable']} unreadable"
           . (isset($c['units']) ? ", {$c['units_complete']}/{$c['units']} folders read, reader {$c['reader']}" : '') . "\n";
        if (!empty($res[$t]['warning'])) echo '  ' . $res[$t]['warning'] . "\n";
        foreach ($res[$t]['errors'] ?? [] as $e) echo '  not read: ' . $e['folder'] . ' — ' . $e['error'] . "\n";
    }
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Booking follow-up check failed: ' . $e->getMessage() . "\n";
}
