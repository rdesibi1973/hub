<?php
/**
 * save_leaves.php
 * Receives parsed leave data from SheetJS, truncates and re-inserts into DB.
 */

require_once __DIR__ . '/../../includes/config.php';   // defines DB_HOST, DB_NAME, DB_USER, DB_PASS
require_once __DIR__ . '/../../includes/auth.php';
require_permission('leave');

header('Content-Type: application/json; charset=utf-8');

// ── Parse request body ───────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Empty request body']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['employees']) || !is_array($data['employees'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

$fileName  = isset($data['fileName']) ? trim((string)$data['fileName']) : 'unknown';
$employees = $data['employees'];

foreach ($employees as $i => $emp) {
    if (empty($emp['name'])) {
        echo json_encode(['success' => false, 'error' => "Employee #{$i} has no name"]);
        exit;
    }
}

// ── DB ───────────────────────────────────────────────────────────────────────
try {
    $leavePdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    // Create tables if they don't exist yet
    $leavePdo->exec("CREATE TABLE IF NOT EXISTS leave_employees (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        name           VARCHAR(100)  NOT NULL,
        palette_index  TINYINT       NOT NULL DEFAULT 0,
        open_balance   FLOAT         DEFAULT NULL,
        alloc1         FLOAT         DEFAULT NULL,
        used1          FLOAT         DEFAULT NULL,
        bal1           FLOAT         DEFAULT NULL,
        alloc2         FLOAT         DEFAULT NULL,
        used2          FLOAT         DEFAULT NULL,
        bal2           FLOAT         DEFAULT NULL,
        remaining      FLOAT         DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $leavePdo->exec("CREATE TABLE IF NOT EXISTS leave_entries (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        employee_name   VARCHAR(100) NOT NULL,
        leave_type      VARCHAR(200) DEFAULT NULL,
        start_date      DATE         NOT NULL,
        end_date        DATE         NOT NULL,
        INDEX idx_emp (employee_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $leavePdo->exec("CREATE TABLE IF NOT EXISTS leave_meta (
        meta_key    VARCHAR(60) NOT NULL PRIMARY KEY,
        meta_value  TEXT        DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Truncate and re-insert inside a transaction
    $leavePdo->beginTransaction();

    $leavePdo->exec("DELETE FROM leave_entries");
    $leavePdo->exec("DELETE FROM leave_employees");

    $insEmp = $leavePdo->prepare(
        "INSERT INTO leave_employees
            (name, palette_index, open_balance, alloc1, used1, bal1, alloc2, used2, bal2, remaining)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $insLeave = $leavePdo->prepare(
        "INSERT INTO leave_entries (employee_name, leave_type, start_date, end_date)
         VALUES (?, ?, ?, ?)"
    );

    foreach ($employees as $idx => $emp) {
        $b = $emp['balance'] ?? [];
        $n = fn($k) => (isset($b[$k]) && $b[$k] !== '') ? (float)$b[$k] : null;

        $insEmp->execute([
            $emp['name'],
            (int)($emp['paletteIndex'] ?? $idx),
            $n('open'),
            $n('alloc1'), $n('used1'), $n('bal1'),
            $n('alloc2'), $n('used2'), $n('bal2'),
            $n('remaining'),
        ]);

        foreach (($emp['leaves'] ?? []) as $leave) {
            $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $leave['start'] ?? '') ? $leave['start'] : null;
            $end   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $leave['end']   ?? '') ? $leave['end']   : null;
            if (!$start) continue;
            if (!$end)   $end = $start;

            $insLeave->execute([
                $emp['name'],
                $leave['leaveType'] ?? 'Leave',
                $start,
                $end,
            ]);
        }
    }

    $now = date('d M Y H:i');
    $leavePdo->prepare(
        "INSERT INTO leave_meta (meta_key, meta_value) VALUES ('last_import', ?)
         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
    )->execute([$now]);

    $leavePdo->prepare(
        "INSERT INTO leave_meta (meta_key, meta_value) VALUES ('last_file', ?)
         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
    )->execute([$fileName]);

    $leavePdo->commit();
    echo json_encode(['success' => true, 'imported' => count($employees), 'at' => $now]);

} catch (Throwable $e) {
    if (isset($leavePdo) && $leavePdo->inTransaction()) $leavePdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
