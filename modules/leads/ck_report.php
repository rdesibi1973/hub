<?php
/**
 * ck_report.php?id=N — the SafariCheck HTML report of one automatic check
 * (stored by ck_agent_api.php). Served sandboxed: the report is generated from
 * booking files, so it gets no access to the Hub session.
 */
require_once 'config.php';
require_once 'includes/ck_lib.php';
requireLogin();
$db = db();
ck_ensure_schema($db);

$st = $db->prepare("SELECT report_html, error, folder_name, created_at FROM ck_checks WHERE id = ?");
$st->execute([(int)($_GET['id'] ?? 0)]);
$c = $st->fetch(PDO::FETCH_ASSOC);

header('Content-Security-Policy: sandbox allow-popups allow-modals allow-scripts');
header('X-Content-Type-Options: nosniff');
if (!$c) { http_response_code(404); echo 'Report not found.'; exit; }
if ($c['report_html']) { echo $c['report_html']; exit; }

header('Content-Type: text/plain; charset=utf-8');
echo "No report for {$c['folder_name']} ({$c['created_at']}).\n\n" . ($c['error'] ? 'Error: ' . $c['error'] : '');
