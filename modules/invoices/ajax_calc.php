<?php
/**
 * ajax_calc.php — the request's Calc Excel values for invoice_add.php.
 * GET ?request_id=N[&sheet=Name] → JSON from ic_read_request().
 */
require_once 'config.php';
requireInvoiceAccess();
require_once __DIR__ . '/includes/invoice_calc.php';
header('Content-Type: application/json');

$s = db()->prepare("SELECT id, customer_name, practice_code, group_folder, dropbox_url FROM requests WHERE id=?");
$s->execute([(int)($_GET['request_id'] ?? 0)]);
$r = $s->fetch(PDO::FETCH_ASSOC);
if (!$r) { echo json_encode(['status' => 'error', 'msg' => 'Request not found.']); exit; }

echo json_encode(ic_read_request($r, (string)($_GET['sheet'] ?? '')), JSON_UNESCAPED_UNICODE);
