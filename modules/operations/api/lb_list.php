<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../includes/ops_service.php';   // ops_lunch_box_rows() — shared with the Agent API
require_permission('operations');

header('Content-Type: application/json');

$rows = ops_lunch_box_rows($pdo, $_GET['from'] ?? null, $_GET['to'] ?? null,
                           trim($_GET['q'] ?? ''), !empty($_GET['history']));

echo json_encode(['ok' => true, 'records' => $rows]);
