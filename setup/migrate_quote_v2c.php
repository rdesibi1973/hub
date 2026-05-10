<?php
require_once dirname(__DIR__) . '/modules/leads/config.php';

$token = $_GET['token'] ?? '';
if ($token !== 'migrate-v2c-2026') { http_response_code(403); die('Forbidden.'); }

echo "<pre>Quote v2c Migration — Mixed room types per day\n\n";

$db = db();

function runStep(PDO $db, string $label, string $sql): void {
    try {
        $db->exec($sql);
        echo "✓ $label\n";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'already exists') !== false || stripos($msg, 'Table') !== false && stripos($msg, 'exists') !== false) {
            echo "– $label (already done)\n";
        } else {
            echo "✗ $label — $msg\n";
        }
    }
}

runStep($db, 'Create quote_day_rooms',
    "CREATE TABLE IF NOT EXISTS quote_day_rooms (
      id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      quote_day_id  INT UNSIGNED NOT NULL,
      room_type_id  INT UNSIGNED NULL,
      room_type_name VARCHAR(200) NOT NULL DEFAULT '',
      qty           TINYINT UNSIGNED NOT NULL DEFAULT 1,
      unit_price    DECIMAL(10,2) NOT NULL DEFAULT 0,
      total_price   DECIMAL(10,2) NOT NULL DEFAULT 0,
      KEY idx_quote_day (quote_day_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

echo "\nDone. You can delete this file from the server.\n</pre>";
