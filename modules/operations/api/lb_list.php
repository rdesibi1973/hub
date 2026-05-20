<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_permission('operations');

header('Content-Type: application/json');

$from    = $_GET['from']    ?? null;
$to      = $_GET['to']      ?? null;
$search  = trim($_GET['q']  ?? '');
$history = !empty($_GET['history']);

$table  = $history ? 'lunch_boxes_history' : 'lunch_boxes';
$where  = [];
$params = [];

if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 'safari_date >= ?'; $params[] = $from;
}
if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 'safari_date <= ?'; $params[] = $to;
}
if ($search) {
    $where[] = '(LOWER(client_name) LIKE ? OR LOWER(folder_name) LIKE ? OR LOWER(extra_details) LIKE ?)';
    $like = '%' . strtolower($search) . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$sql = "SELECT * FROM `$table`";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY safari_date DESC, client_name ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

echo json_encode(['ok' => true, 'records' => $rows]);
