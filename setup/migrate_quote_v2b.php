<?php
require_once dirname(__DIR__) . '/modules/leads/config.php';

$token = $_GET['token'] ?? '';
if ($token !== 'migrate-v2b-2026') { http_response_code(403); die('Forbidden.'); }

echo "<pre>Quote v2b Migration\n\n";

$db = db();

function runStep(PDO $db, string $label, string $sql): void {
    try {
        $db->exec($sql);
        echo "✓ $label\n";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate column') !== false || stripos($msg, 'already exists') !== false) {
            echo "– $label (already done)\n";
        } else {
            echo "✗ $label — $msg\n";
        }
    }
}

runStep($db, 'quote_days: add jeep_count (NULL = auto from pax÷7)',
    "ALTER TABLE quote_days ADD COLUMN jeep_count TINYINT UNSIGNED NULL AFTER jeep");

echo "\nDone. You can delete this file from the server.\n</pre>";
