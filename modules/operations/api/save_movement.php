<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_permission('operations');

header('Content-Type: application/json');

function normalise_time($v) {
    $v = trim($v);
    if ($v === '') return null;
    $v = preg_replace('/[,.]/', ':', $v);             // 10,30 → 10:30
    if (preg_match('/^(\d{1,2}):(\d{2})/', $v, $m))
        return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
    if (preg_match('/^(\d{3,4})$/', $v, $m)) {        // 1030 → 10:30
        $s = str_pad($m[1], 4, '0', STR_PAD_LEFT);
        return substr($s, 0, 2) . ':' . substr($s, 2, 2);
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'POST only']);
    exit;
}

$id            = (int)($_POST['id'] ?? 0);
$move_date     = $_POST['move_date']     ?? '';
$movement_type = $_POST['movement_type'] ?? '';
$client_name   = trim($_POST['client_name']   ?? '');
$pax           = max(1, (int)($_POST['pax'] ?? 1));
$flight        = trim($_POST['flight']        ?? '');
$move_time     = normalise_time($_POST['move_time'] ?? '');
$pickup        = trim($_POST['pickup']        ?? '');
$dropoff       = trim($_POST['dropoff']       ?? '');
$driver        = trim($_POST['driver']        ?? '');
$notes         = trim($_POST['notes']         ?? '');
$dropbox_folder = trim($_POST['dropbox_folder'] ?? '');

if (!$move_date || !in_array($movement_type, ['Arrival', 'Departure', 'Transfer'])) {
    echo json_encode(['error' => 'Date and movement type are required']);
    exit;
}

try {
    // Duplicate check — same date + type + client + flight
    $dupSql = 'SELECT id FROM movements WHERE move_date = ? AND movement_type = ? AND client_name = ? AND COALESCE(flight,"") = ?';
    $dupParams = [$move_date, $movement_type, $client_name, $flight];
    if ($id) { $dupSql .= ' AND id != ?'; $dupParams[] = $id; }
    $dup = $pdo->prepare($dupSql);
    $dup->execute($dupParams);
    if ($dup->fetch()) {
        echo json_encode(['error' => 'duplicate', 'duplicate' => true,
            'message' => "Duplicate: a {$movement_type} for \"{$client_name}\" on {$move_date}" . ($flight ? " ({$flight})" : '') . " already exists."]);
        exit;
    }

    if ($id) {
        $pdo->prepare('UPDATE movements SET move_date=?,movement_type=?,client_name=?,pax=?,flight=?,move_time=?,pickup=?,dropoff=?,driver=?,notes=?,dropbox_folder=? WHERE id=?')
            ->execute([$move_date,$movement_type,$client_name,$pax,$flight?:null,$move_time,$pickup,$dropoff,$driver,$notes?:null,$dropbox_folder?:null,$id]);
        echo json_encode(['ok' => true, 'action' => 'updated', 'id' => $id]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO movements (move_date,movement_type,client_name,pax,flight,move_time,pickup,dropoff,driver,notes,dropbox_folder) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$move_date,$movement_type,$client_name,$pax,$flight?:null,$move_time,$pickup,$dropoff,$driver,$notes?:null,$dropbox_folder?:null]);
        echo json_encode(['ok' => true, 'action' => 'created', 'id' => $pdo->lastInsertId()]);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
