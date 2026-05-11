<?php
require_once 'config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$id = (int)($_POST['id'] ?? 0);
if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }

$db = db();

// Access check
$q = $db->prepare("SELECT id, quote_number, agent_id FROM quotes WHERE id = ?");
$q->execute([$id]);
$quote = $q->fetch();
if (!$quote) { echo json_encode(['success'=>false,'message'=>'Quote not found']); exit; }

if (isLeadsRestricted() && (int)$quote['agent_id'] !== getStaffAgentId()) {
    echo json_encode(['success'=>false,'message'=>'Access denied']); exit;
}

// Delete cascade
$dayIds = $db->prepare("SELECT id FROM quote_days WHERE quote_id = ?");
$dayIds->execute([$id]);
$dids = $dayIds->fetchAll(PDO::FETCH_COLUMN);
if ($dids) {
    $ph = implode(',', array_fill(0, count($dids), '?'));
    $db->prepare("DELETE FROM quote_day_rooms WHERE quote_day_id IN ($ph)")->execute($dids);
    $db->prepare("DELETE FROM quote_day_items WHERE quote_day_id IN ($ph)")->execute($dids);
}
$db->prepare("DELETE FROM quote_days         WHERE quote_id = ?")->execute([$id]);
$db->prepare("DELETE FROM quote_safari_items WHERE quote_id = ?")->execute([$id]);
$db->prepare("DELETE FROM quotes             WHERE id = ?")->execute([$id]);

echo json_encode(['success'=>true,'message'=>'Quote '.$quote['quote_number'].' deleted']);
