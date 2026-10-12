<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../includes/ops_service.php';   // ops_movement_rows() — shared with the Agent API
require_permission('operations');

header('Content-Type: application/json');

$mode  = $_GET['mode']  ?? 'single';
$date  = $_GET['date']  ?? date('Y-m-d');
$from  = $_GET['from']  ?? date('Y-m-d');
$to    = $_GET['to']    ?? date('Y-m-d');
$dates = $_GET['dates'] ?? '';

// When shift=1, movements with a time between midnight and 06:00 are shown on
// the PREVIOUS day (display_date = move_date - 1 day). This is the operational
// daily view (Tab 1). The editing grid (Tab 2) omits it and keeps the real date
// so rows stay where they were saved.
$shift = (($_GET['shift'] ?? '') === '1');

try {
    if (!in_array($mode, ['single', 'range', 'multiple'], true)) {
        echo json_encode(['error' => 'Invalid mode']);
        exit;
    }
    $dateArr = array_filter(array_map('trim', explode(',', $dates)));
    if ($mode === 'multiple' && empty($dateArr)) {
        echo json_encode(['rows' => [], 'dates' => []]);
        exit;
    }
    $rows = ops_movement_rows($pdo, $mode, ['date' => $date, 'from' => $from, 'to' => $to, 'dates' => $dateArr], $shift);

    // All distinct display-dates with data (for calendar dots)
    echo json_encode(['rows' => $rows, 'dates_with_data' => ops_movement_dates($pdo, $shift)]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
