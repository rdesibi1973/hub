<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_permission('operations');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'POST only']); exit;
}

$body      = json_decode(file_get_contents('php://input'), true);
$mode      = $body['mode']      ?? 'expired'; // 'expired' or 'group'
$groupRef  = $body['group_ref'] ?? '';

try {
    $pdo->beginTransaction();

    if ($mode === 'group') {
        // Archive a specific group
        if (!$groupRef) { echo json_encode(['error' => 'Missing group_ref']); exit; }
        $where  = 'group_ref = ?';
        $params = [$groupRef];
    } else {
        // Archive all groups where coverage_end < today (and coverage_end is set)
        $where  = 'coverage_end < CURDATE() AND coverage_end IS NOT NULL';
        $params = [];
    }

    // Copy to archive
    $pdo->prepare(
        "INSERT INTO medivac_travelers_archive
         (id, group_ref, group_name, tour_agent, coverage_start, coverage_end,
          full_name, title, dob, passport_number, country,
          insurance_name, policy_number, source_file, notes, created_by, created_at, updated_at, archived_at)
         SELECT id, group_ref, group_name, tour_agent, coverage_start, coverage_end,
                full_name, title, dob, passport_number, country,
                insurance_name, policy_number, source_file, notes, created_by, created_at, updated_at, NOW()
         FROM medivac_travelers
         WHERE $where
         ON DUPLICATE KEY UPDATE archived_at = NOW()"
    )->execute($params);

    $archived = $pdo->rowCount();

    // Delete from active table
    $pdo->prepare("DELETE FROM medivac_travelers WHERE $where")->execute($params);
    $deleted = $pdo->rowCount();

    $pdo->commit();

    echo json_encode([
        'ok'       => true,
        'archived' => $deleted,
        'mode'     => $mode,
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['error' => $e->getMessage()]);
}
