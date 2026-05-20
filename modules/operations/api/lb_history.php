<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_permission('operations');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'POST only']); exit; }
$body = json_decode(file_get_contents('php://input'), true);
$mode = $body['mode'] ?? '';

try {
    if ($mode === 'expired') {
        // Move all records with safari_date < today to history
        $today = date('Y-m-d');
        $rows  = $pdo->prepare('SELECT * FROM lunch_boxes WHERE safari_date < ?');
        $rows->execute([$today]);
        $expired = $rows->fetchAll();

        if (empty($expired)) {
            echo json_encode(['ok' => true, 'archived' => 0]);
            exit;
        }

        $ins = $pdo->prepare(
            'INSERT INTO lunch_boxes_history
             (id, group_ref, client_name, safari_date, travelers, jeeps, guide, box_type,
              extra_details, folder_name, notes, source_file, created_by, created_at, updated_at, archived_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
        );

        $pdo->beginTransaction();
        $count = 0;
        foreach ($expired as $row) {
            $ins->execute([
                $row['id'], $row['group_ref'], $row['client_name'], $row['safari_date'],
                $row['travelers'], $row['jeeps'], $row['guide'] ?? null, $row['box_type'] ?? 'HUMPERS',
                $row['extra_details'], $row['folder_name'], $row['notes'],
                $row['source_file'], $row['created_by'], $row['created_at'], $row['updated_at']
            ]);
            $count++;
        }
        $ids = array_column($expired, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM lunch_boxes WHERE id IN ($placeholders)")->execute($ids);
        $pdo->commit();

        echo json_encode(['ok' => true, 'archived' => $count]);

    } elseif ($mode === 'stage') {        // Move one record from lunch_boxes to lunch_boxes_history
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'Missing id']); exit; }

        $stmt = $pdo->prepare('SELECT * FROM lunch_boxes WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(['error' => 'Record not found']); exit; }

        $pdo->beginTransaction();

        $pdo->prepare(
            'INSERT INTO lunch_boxes_history
             (id, group_ref, client_name, safari_date, travelers, jeeps, guide, box_type,
              extra_details, folder_name, notes, source_file, created_by, created_at, updated_at, archived_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
        )->execute([
            $row['id'], $row['group_ref'], $row['client_name'], $row['safari_date'],
            $row['travelers'], $row['jeeps'], $row['guide'] ?? null, $row['box_type'] ?? 'HUMPERS',
            $row['extra_details'], $row['folder_name'],
            $row['notes'], $row['source_file'], $row['created_by'],
            $row['created_at'], $row['updated_at']
        ]);

        $pdo->prepare('DELETE FROM lunch_boxes WHERE id = ?')->execute([$id]);
        $pdo->commit();

        echo json_encode(['ok' => true, 'staged' => $id]);

    } else {
        echo json_encode(['error' => 'Invalid mode']);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['error' => $e->getMessage()]);
}
