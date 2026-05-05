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
            "SELECT id, practice_code, status FROM requests WHERE id IN ($placeholders)"
        );
        $rows->execute(array_values($ids));

        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $folder = $row['practice_code'] ?? '';
            if ($folder === '') {
                $dropboxResults[$row['id']] = 'skipped (no folder name)';
                continue;
            }

            // Booked → moved to /001_Safari/; everything else → /2026/
            $dbxPath = ($row['status'] === 'Booked')
                ? '/001_Safari/' . $folder
                : '/2026/' . $folder;

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
