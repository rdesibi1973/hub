<?php
/**
 * get_leaves.php
 * Returns saved leave data from DB (employees + leaves + meta).
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_permission('leave');

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // Check tables exist
    $tables = $pdo->query("SHOW TABLES LIKE 'leave_employees'")->fetchAll();
    if (empty($tables)) {
        echo json_encode(['success' => true, 'employees' => [], 'fileName' => null, 'lastImport' => null]);
        exit;
    }

    $employees = $pdo->query("SELECT * FROM leave_employees ORDER BY palette_index")->fetchAll();
    if (empty($employees)) {
        echo json_encode(['success' => true, 'employees' => [], 'fileName' => null, 'lastImport' => null]);
        exit;
    }

    $leaves = $pdo->query(
        "SELECT employee_name, leave_type, start_date, end_date FROM leave_entries ORDER BY start_date"
    )->fetchAll();

    // Group leaves by employee
    $leaveMap = [];
    foreach ($leaves as $l) {
        $leaveMap[$l['employee_name']][] = [
            'leaveType' => $l['leave_type'],
            'start'     => $l['start_date'],   // YYYY-MM-DD string
            'end'       => $l['end_date'],
        ];
    }

    $result = [];
    foreach ($employees as $emp) {
        $result[] = [
            'name'         => $emp['name'],
            'paletteIndex' => (int)$emp['palette_index'],
            'balance'      => [
                'open'      => $emp['open_balance'],
                'alloc1'    => $emp['alloc1'],
                'used1'     => $emp['used1'],
                'bal1'      => $emp['bal1'],
                'alloc2'    => $emp['alloc2'],
                'used2'     => $emp['used2'],
                'bal2'      => $emp['bal2'],
                'remaining' => $emp['remaining'],
            ],
            'leaves' => $leaveMap[$emp['name']] ?? [],
        ];
    }

    $meta = $pdo->query("SELECT meta_key, meta_value FROM leave_meta")->fetchAll(PDO::FETCH_KEY_PAIR);

    echo json_encode([
        'success'     => true,
        'employees'   => $result,
        'fileName'    => $meta['last_file']   ?? null,
        'lastImport'  => $meta['last_import'] ?? null,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
