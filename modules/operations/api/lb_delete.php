<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_permission('operations');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'POST only']); exit; }
$body    = json_decode(file_get_contents('php://input'), true);
$id      = (int)($body['id'] ?? 0);
$history = !empty($body['history']);
$table   = $history ? 'lunch_boxes_history' : 'lunch_boxes';

if (!$id) { echo json_encode(['error' => 'Missing id']); exit; }

try {
    $pdo->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$id]);
    echo json_encode(['ok' => true, 'id' => $id]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
