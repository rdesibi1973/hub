<?php
/**
 * backfill_email.php — one-time script to populate the email column in requests.
 * Run once from cPanel Terminal, then delete the file.
 *
 * Usage:
 *   php /home4/savannp5/public_html/hub/modules/leads/backfill_email.php
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'savannp5_savannah_leads');
define('DB_USER', 'savannp5_rdesibi');
define('DB_PASS', 'Savannah2026');

$db = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
    DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$rows = $db->query(
    "SELECT id, initial_request, notes FROM requests WHERE email IS NULL"
)->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($rows) . " requests without email.\n";

$updated = 0;
$upd = $db->prepare("UPDATE requests SET email = ? WHERE id = ?");

foreach ($rows as $row) {
    $text  = ($row['initial_request'] ?? '') . ' ' . ($row['notes'] ?? '');
    $email = null;
    if (preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $text, $m)) {
        $email = strtolower(trim($m[0]));
    }
    if ($email) {
        $upd->execute([$email, $row['id']]);
        $updated++;
    }
}

$total   = (int)$db->query("SELECT COUNT(*) FROM requests")->fetchColumn();
$withMail = (int)$db->query("SELECT COUNT(*) FROM requests WHERE email IS NOT NULL")->fetchColumn();

echo "Updated: $updated\n";
echo "Coverage: $withMail / $total requests now have email.\n";
echo "Done — delete this file from the server.\n";
