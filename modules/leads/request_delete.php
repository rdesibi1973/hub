<?php
require_once 'config.php';
requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'POST only']); exit;
}

$ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
if (!$ids) {
    echo json_encode(['error' => 'No IDs provided']); exit;
}

$db   = db();
$user = current_user();

// ── Block deletion of Booked requests ────────────────────────────────────────
// Confirmed bookings (status = Booked) live in /001_Safari and must not be
// soft-deleted from the hub.  Status changes (e.g. BALANCE → CANCELLED) are
// done manually via the Java GUI.  Because Booked is blocked here, every
// soft-deletable request lives in the /<year>/ tree — its Dropbox folder is
// therefore left untouched.
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$bookedRows = $db->prepare(
    "SELECT id, customer_name FROM requests WHERE id IN ($placeholders) AND status = 'Booked'"
);
$bookedRows->execute(array_values($ids));
$booked = $bookedRows->fetchAll(PDO::FETCH_ASSOC);
if ($booked) {
    $names = implode(', ', array_column($booked, 'customer_name'));
    echo json_encode([
        'error'  => 'Cannot delete confirmed (Booked) requests: ' . $names
                  . '. To cancel a booking, change the folder status to CANCELLED manually via the Java GUI.',
        'booked' => array_column($booked, 'id'),
    ]);
    exit;
}

// ── Fetch full rows to archive ───────────────────────────────────────────────
$rows = $db->prepare("SELECT * FROM requests WHERE id IN ($placeholders)");
$rows->execute(array_values($ids));
$allRows = $rows->fetchAll(PDO::FETCH_ASSOC);
if (!$allRows) {
    echo json_encode(['error' => 'No matching requests found']); exit;
}

$results = [];

foreach ($allRows as $row) {
    $id = (int)$row['id'];

    // Soft-delete: archive the full row, then remove from requests.
    // The Dropbox folder (in /<year>/) is intentionally left in place.
    try {
        $db->beginTransaction();
        $ins = $db->prepare(
            "INSERT INTO deleted_requests
               (orig_id, practice_code, customer_name, deleted_by, deleted_by_name,
                dropbox_from_path, dropbox_to_path, row_data)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([
            $id,
            $row['practice_code'] ?? null,
            $row['customer_name'] ?? null,
            $user['id'] ?? null,
            $user['full_name'] ?: ($user['username'] ?? ''),
            null,   // folder not moved — nothing to record
            null,
            json_encode($row, JSON_UNESCAPED_UNICODE),
        ]);
        $db->prepare("DELETE FROM requests WHERE id = ?")->execute([$id]);
        $db->commit();
        $results[$id] = 'archived (Dropbox folder left in place)';
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $results[$id] = 'archive failed: ' . $e->getMessage();
    }
}

echo json_encode(['ok' => true, 'results' => $results]);
