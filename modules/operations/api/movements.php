<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_permission('operations');

header('Content-Type: application/json');

$mode  = $_GET['mode']  ?? 'single';
$date  = $_GET['date']  ?? date('Y-m-d');
$from  = $_GET['from']  ?? date('Y-m-d');
$to    = $_GET['to']    ?? date('Y-m-d');
$dates = $_GET['dates'] ?? '';

// When shift=1, movements with a time between midnight and NIGHT_CUTOFF are
// shown on the PREVIOUS day (display_date = move_date - 1 day). This is the
// operational daily view (Tab 1). The editing grid (Tab 2) omits it and keeps
// the real date so rows stay where they were saved.
$NIGHT_CUTOFF = '06:00:00';
$shift = (($_GET['shift'] ?? '') === '1');
$dispExpr = $shift
    ? "CASE WHEN move_time IS NOT NULL AND move_time < '$NIGHT_CUTOFF'
            THEN DATE_SUB(move_date, INTERVAL 1 DAY) ELSE move_date END"
    : "move_date";

try {
    if ($mode === 'single') {
        $stmt = $pdo->prepare("SELECT *, $dispExpr AS display_date FROM movements WHERE $dispExpr = ? ORDER BY movement_type DESC, move_time ASC, id ASC");
        $stmt->execute([$date]);

    } elseif ($mode === 'range') {
        $stmt = $pdo->prepare("SELECT *, $dispExpr AS display_date FROM movements WHERE $dispExpr BETWEEN ? AND ? ORDER BY display_date ASC, movement_type DESC, move_time ASC");
        $stmt->execute([$from, $to]);

    } elseif ($mode === 'multiple') {
        $dateArr = array_filter(array_map('trim', explode(',', $dates)));
        if (empty($dateArr)) {
            echo json_encode(['rows' => [], 'dates' => []]);
            exit;
        }
        $placeholders = implode(',', array_fill(0, count($dateArr), '?'));
        $stmt = $pdo->prepare("SELECT *, $dispExpr AS display_date FROM movements WHERE $dispExpr IN ($placeholders) ORDER BY display_date ASC, movement_type DESC, move_time ASC");
        $stmt->execute($dateArr);

    } else {
        echo json_encode(['error' => 'Invalid mode']);
        exit;
    }

    $rows = $stmt->fetchAll();

    // Format dates and times
    foreach ($rows as &$row) {
        $row['move_date_fmt'] = $row['move_date']
            ? (new DateTime($row['move_date']))->format('D d M Y')
            : '';
        $row['move_time_fmt'] = $row['move_time']
            ? substr($row['move_time'], 0, 5)
            : '';

        // Display date (where the row is grouped) + next-day flag/label
        $row['display_date'] = $row['display_date'] ?: $row['move_date'];
        $row['display_date_fmt'] = $row['display_date']
            ? (new DateTime($row['display_date']))->format('D d M Y')
            : '';
        $row['is_next_day'] = ($row['display_date'] !== $row['move_date']) ? 1 : 0;
        if ($row['is_next_day']) {
            $shortDate = (new DateTime($row['move_date']))->format('d M');
            $time12 = $row['move_time']
                ? (new DateTime($row['move_time']))->format('h:i A')
                : '';
            $row['next_day_label'] = '+1 day, ' . $shortDate . ($time12 ? ' ' . $time12 : '');
        } else {
            $row['next_day_label'] = '';
        }
    }
    unset($row);

    // Get all distinct display-dates with data (for calendar dots)
    $datesWithData = $pdo->query("SELECT DISTINCT $dispExpr AS d FROM movements ORDER BY d")
        ->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(['rows' => $rows, 'dates_with_data' => $datesWithData]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
