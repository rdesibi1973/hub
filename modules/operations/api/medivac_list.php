<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../includes/ops_service.php';   // ops_medivac_groups() — shared with the Agent API
require_permission('operations');

header('Content-Type: application/json');

// Grouped by group_ref. No passport numbers (the Hub never stores them).
$groups = ops_medivac_groups($pdo, $_GET['from'] ?? null, $_GET['to'] ?? null,
                             trim($_GET['q'] ?? ''), !empty($_GET['archive']));

echo json_encode(['ok' => true, 'groups' => $groups]);
