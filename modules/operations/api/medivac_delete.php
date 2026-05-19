<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_permission('operations');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'POST only']); exit;
}

$body     = json_decode(file_get_contents('php://input'), true);
$mode     = $body['mode']    ?? '';
$archive  = !empty($body['archive']);
$table    = $archive ? 'medivac_travelers_archive' : 'medivac_travelers';

try {
    if ($mode === 'traveler') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'Missing id']); exit; }
        $pdo->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$id]);
        echo json_encode(['ok' => true, 'deleted' => 'traveler', 'id' => $id]);

    } elseif ($mode === 'group') {
        $ref = trim($body['group_ref'] ?? '');
        if (!$ref) { echo json_encode(['error' => 'Missing group_ref']); exit; }
        $stmt = $pdo->prepare("DELETE FROM `$table` WHERE group_ref = ?");
        $stmt->execute([$ref]);
        echo json_encode(['ok' => true, 'deleted' => 'group', 'group_ref' => $ref, 'count' => $stmt->rowCount()]);

    } else {
        echo json_encode(['error' => 'Invalid mode']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
