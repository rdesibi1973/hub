<?php
require_once 'config.php';
require_once 'dropbox_helper.php';
requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'POST only']); exit;
}

$ids           = array_map('intval', $_POST['ids'] ?? []);
$ids           = array_filter($ids);
$deleteDropbox = !empty($_POST['delete_dropbox']);

if (!$ids) {
    echo json_encode(['error' => 'No IDs provided']); exit;
}

$db = db();
$dropboxResults = [];

// ── Block deletion of Booked requests ────────────────────────────────────────
// Confirmed bookings (status = Booked) live in 001_Safari and must not be
// deleted from the hub.  Status changes (e.g. BALANCE → CANCELLED) are done
// manually via the Java GUI.
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

// ── Optional: delete Dropbox folder(s) first ─────────────────────────────────
if ($deleteDropbox) {
    try {
        $token = dropbox_get_access_token();

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $db->prepare(
            "SELECT id, practice_code, status, group_folder, dropbox_url, date_received FROM requests WHERE id IN ($placeholders)"
        );
        $rows->execute(array_values($ids));

        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $folder    = $row['practice_code'] ?? '';
            $grpFolder = $row['group_folder']  ?? '';
            $dbxUrl    = $row['dropbox_url']   ?? '';

            // Derive the Dropbox API path to delete.
            //
            // GRP bookings: the physical folder on Dropbox is the PARENT group
            // folder (group_folder), not the subfolder stored in practice_code.
            // dropbox_url may point to the subfolder only, so we must build the
            // path explicitly when group_folder is present.
            $year = date('Y', strtotime($row['date_received'] ?? 'now'));
            $dir  = ($row['status'] === 'Booked') ? '001_Safari' : $year;

            if ($grpFolder !== '') {
                // GRP: delete the parent folder (contains all sub-folders for the group)
                $dbxPath = '/' . $dir . '/' . $grpFolder;
            } elseif ($dbxUrl) {
                // Non-GRP with stored URL: strip web prefix and URL-decode
                $dbxPath = rawurldecode(
                    preg_replace('#^https://www\.dropbox\.com/home#i', '', $dbxUrl)
                );
            } elseif ($folder !== '') {
                // Non-GRP fallback: construct from practice_code
                $dbxPath = '/' . $dir . '/' . $folder;
            } else {
                $dropboxResults[$row['id']] = 'skipped (no folder name)';
                continue;
            }

            try {
                dropbox_delete_folder($token, $dbxPath);
                $dropboxResults[$row['id']] = 'deleted: ' . $dbxPath;
            } catch (RuntimeException $e) {
                $dropboxResults[$row['id']] = 'error: ' . $e->getMessage();
            }
        }
    } catch (RuntimeException $e) {
        $dropboxResults['token_error'] = $e->getMessage();
    }
}

// ── Delete DB record(s) ───────────────────────────────────────────────────────
try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("DELETE FROM requests WHERE id IN ($placeholders)")->execute(array_values($ids));
    echo json_encode([
        'ok'      => true,
        'deleted' => count($ids),
        'dropbox' => $dropboxResults,
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
