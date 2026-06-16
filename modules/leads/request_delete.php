<?php
require_once 'config.php';
require_once 'dropbox_helper.php';
require_once __DIR__ . '/includes/folder_parser.php';
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

// Status suffix tags, longest first (so _BALANCE-CASH is matched before _BALANCE).
$STATUS_TAGS = [
    '_BALANCE-CASH', '_BALANCE_CASH', '_BALANCE',
    '_DEPOSIT', '_PAID', '_PROGRESS', '_PROVISIONAL', '_CANCELLED',
];

/**
 * Replace the trailing status tag in a folder name with $newTag.
 * If no known tag is present, append $newTag.
 */
function swap_status_tag(string $name, array $tags, string $newTag): string {
    foreach ($tags as $tag) {
        $pos = stripos($name, $tag);
        if ($pos !== false) {
            return substr($name, 0, $pos) . $newTag . substr($name, $pos + strlen($tag));
        }
    }
    return $name . $newTag;
}

// ── Block deletion of Booked requests ────────────────────────────────────────
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

// Dropbox token (folder move). If unavailable we abort: we never delete the DB
// row without successfully relocating the folder, to keep DB/Dropbox in sync.
try {
    $token = dropbox_get_access_token();
} catch (RuntimeException $e) {
    echo json_encode(['error' => 'Dropbox token error: ' . $e->getMessage()]); exit;
}

$results = [];

foreach ($allRows as $row) {
    $id        = (int)$row['id'];
    $folder    = trim($row['practice_code'] ?? '');
    $grpFolder = trim($row['group_folder']  ?? '');
    $dbxUrl    = trim($row['dropbox_url']    ?? '');

    // Departure year drives the CANCELED bucket (00_CANCELED/00_<year>).
    $dates    = parse_folder_dates(get_date_folder($row));
    $startYr  = $dates['start_date'] ? substr($dates['start_date'], 0, 4)
              : ($dates['end_date'] ? substr($dates['end_date'], 0, 4)
              : date('Y', strtotime($row['date_received'] ?? 'now')));

    // Source dir mirrors the (non-soft) layout: Booked → 001_Safari, else <recv year>.
    $recvYear = date('Y', strtotime($row['date_received'] ?? 'now'));
    $srcDir   = ($row['status'] === 'Booked') ? '001_Safari' : $recvYear;

    // Resolve current Dropbox path + folder leaf name.
    if ($grpFolder !== '') {
        $leaf    = $grpFolder;
        $fromPath = '/' . $srcDir . '/' . $grpFolder;
    } elseif ($dbxUrl) {
        $fromPath = rawurldecode(preg_replace('#^https://www\.dropbox\.com/home#i', '', $dbxUrl));
        $leaf     = basename($fromPath);
    } elseif ($folder !== '') {
        $leaf     = $folder;
        $fromPath = '/' . $srcDir . '/' . $folder;
    } else {
        $results[$id] = 'skipped (no folder name) — DB row NOT archived';
        continue;
    }

    $newLeaf = swap_status_tag($leaf, $STATUS_TAGS, '_CANCELLED');
    $destDir = '/001_Safari/00_CANCELED/00_' . $startYr;
    $toPath  = $destDir . '/' . $newLeaf;

    // Ensure destination buckets exist (idempotent).
    try {
        dropbox_create_folder($token, '/001_Safari/00_CANCELED');
    } catch (RuntimeException $e) { /* exists */ }
    try {
        dropbox_create_folder($token, $destDir);
    } catch (RuntimeException $e) { /* exists */ }

    // Move + rename the folder. If this fails, do NOT touch the DB row.
    try {
        dropbox_move_folder($token, $fromPath, $toPath);
    } catch (RuntimeException $e) {
        $results[$id] = 'error moving Dropbox folder (' . $fromPath . '): '
                      . $e->getMessage() . ' — DB row NOT archived';
        continue;
    }

    // Archive + delete inside a transaction.
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
            $fromPath,
            $toPath,
            json_encode($row, JSON_UNESCAPED_UNICODE),
        ]);
        $db->prepare("DELETE FROM requests WHERE id = ?")->execute([$id]);
        $db->commit();
        $results[$id] = 'archived & moved to ' . $toPath;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        // DB failed but folder already moved — try to move it back.
        try { dropbox_move_folder($token, $toPath, $fromPath); } catch (Exception $e2) {}
        $results[$id] = 'DB archive failed: ' . $e->getMessage() . ' (Dropbox folder restored)';
    }
}

echo json_encode(['ok' => true, 'results' => $results]);
