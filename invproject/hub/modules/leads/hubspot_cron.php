<?php
// hubspot_cron.php — called by external cron service (e.g. cron-job.org)
// URL: https://hub.savannahexplorers.com/modules/leads/hubspot_cron.php?token=TOKEN

define('CRON_TOKEN', 'hs-cron-72f85600c313d339874cadfb');

if (($_GET['token'] ?? '') !== CRON_TOKEN) {
    http_response_code(403);
    die('Forbidden');
}

// Run sync script via exec
$script = __DIR__ . '/hubspot_sync.php';
$log    = __DIR__ . '/hubspot_sync.log';
$cmd    = "/usr/local/bin/php $script >> $log 2>&1";
exec($cmd, $output, $code);

header('Content-Type: text/plain');
echo date('Y-m-d H:i:s') . " — exit code: $code\n";
echo implode("\n", $output) . "\n";
