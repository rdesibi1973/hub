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

try {
    if ($mode === 'single') {
        $stmt = $pdo->prepare('SELECT * FROM movements WHERE move_date = ? ORDER BY movement_type DESC, move_time ASC, id ASC');
        $stmt->execute([$date]);

    } elseif ($mode === 'range') {
        $stmt = $pdo->prepare('SELECT * FROM movements WHERE move_date BETWEEN ? AND ? ORDER BY move_date ASC, movement_type DESC, move_time ASC');
        $stmt->execute([$from, $to]);

    } elseif ($mode === 'multiple') {
        $dateArr = array_filter(array_map('trim', explode(',', $dates)));
        if (empty($dateArr)) {
            echo json_encode(['rows' => [], 'dates' => []]);
            exit;
        }
        $placeholders = implode(',', array_fill(0, count($dateArr), '?'));
        $stmt = $pdo->prepare("SELECT * FROM movements WHERE move_date IN ($placeholders) ORDER BY move_date ASC, movement_type DESC, move_time ASC");
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
    }

    // Get all distinct dates with data (for calendar dots)
    $datesWithData = $pdo->query('SELECT DISTINCT move_date FROM movements ORDER BY move_date')
        ->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(['rows' => $rows, 'dates_with_data' => $datesWithData]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
