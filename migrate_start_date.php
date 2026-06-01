<?php
/**
 * One-time migration: add start_date column to requests and backfill
 * from practice_code / group_folder using folder_parser.php logic.
 *
 * Run once via browser or CLI, then delete.
 */
require_once __DIR__ . '/modules/leads/config.php';
require_once __DIR__ . '/modules/leads/includes/folder_parser.php';

$db = db();

// 1. Add column if not exists
try {
    $db->exec("ALTER TABLE requests ADD COLUMN start_date DATE DEFAULT NULL AFTER confirmation_date");
    echo "Column start_date added.<br>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "Column start_date already exists — skipping ALTER.<br>\n";
    } else {
        die("ALTER failed: " . $e->getMessage());
    }
}

// 2. Fetch all Booked requests with a practice_code or group_folder
$rows = $db->query("
    SELECT id, practice_code, group_folder, dropbox_url
    FROM requests
    WHERE status = 'Booked'
      AND (practice_code IS NOT NULL OR group_folder IS NOT NULL)
")->fetchAll(PDO::FETCH_ASSOC);

$updated = $skipped = $failed = 0;
$upd = $db->prepare("UPDATE requests SET start_date = ? WHERE id = ?");

foreach ($rows as $row) {
    $folder = get_date_folder($row);
    $dates  = parse_folder_dates($folder);

    if ($dates['start_date']) {
        $upd->execute([$dates['start_date'], $row['id']]);
        $updated++;
    } else {
        $failed++;
        echo "No start date parsed for request #{$row['id']} — folder: " . htmlspecialchars($folder) . "<br>\n";
    }
}

echo "<br><strong>Done.</strong> Updated: $updated | No date found: $failed<br>\n";
