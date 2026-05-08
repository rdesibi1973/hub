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

            // Derive path from stored dropbox_url (most reliable — exact location).
            // URL format: https://www.dropbox.com/home/001_Safari/FolderName
            // → strip the web prefix and URL-decode to get the API path.
            if ($dbxUrl) {
                $dbxPath = rawurldecode(
                    preg_replace('#^https://www\.dropbox\.com/home#i', '', $dbxUrl)
                );
            } elseif ($folder !== '') {
                // Fallback: construct from practice_code (GRP-aware, year from date_received)
                if ($row['status'] === 'Booked') {
                    $dbxPath = $grpFolder
                        ? '/001_Safari/' . $grpFolder . '/' . $folder
                        : '/001_Safari/' . $folder;
                } else {
                    $year    = date('Y', strtotime($row['date_received'] ?? 'now'));
                    $dbxPath = '/' . $year . '/' . $folder;
                }
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
