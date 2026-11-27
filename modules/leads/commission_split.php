<?php
/**
 * commission_split.php — AJAX (request page "Commission → Change"): Roberto sets a booking's commission split.
 * POST request_id, split (JSON list [{referrer_id, rate}] or null = back to consultant / agency default), reason*.
 * Logic: comm_set_request_split() in includes/commission_service.php (shared with Agent API set_commission_split).
 */
ob_start();
require_once 'config.php';
require_once __DIR__ . '/includes/commission_service.php';
requireLogin();

function cs_out(array $d, int $code = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') cs_out(['ok' => false, 'error' => 'POST only'], 405);
if (!comm_can_edit()) cs_out(['ok' => false, 'error' => 'Only an admin can change the commission split.'], 403);

$db = db();
$reqId = (int)($_POST['request_id'] ?? 0);
$raw = json_decode((string)($_POST['split'] ?? 'null'), true);
$split = null;
if ($raw !== null) {
    [$split, $err] = comm_split_check($db, $raw);
    if ($err) cs_out(['ok' => false, 'error' => $err], 400);
}
$res = comm_set_request_split($db, $reqId, $split, (string)($_POST['reason'] ?? ''));
cs_out($res, $res['ok'] ? 200 : 400);
