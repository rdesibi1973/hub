<?php
/**
 * One-time migration: create agency_contacts table.
 *
 * Each agency can have N named contacts (the people we usually deal with),
 * with role, email, phone, a primary flag and free notes. Useful to quickly
 * find who to call in an emergency.
 *
 * Run once via browser or CLI, then delete.
 */
require_once __DIR__ . '/modules/leads/config.php';

$db = db();

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS agency_contacts (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            agency_id  INT NOT NULL,
            name       VARCHAR(150) NOT NULL,
            role       VARCHAR(100) NULL,
            email      VARCHAR(255) NULL,
            phone      VARCHAR(50)  NULL,
            is_primary TINYINT(1)   NOT NULL DEFAULT 0,
            notes      TEXT         NULL,
            created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            KEY idx_agency (agency_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "Table agency_contacts ready.<br>\n";
} catch (PDOException $e) {
    die("CREATE TABLE failed: " . $e->getMessage());
}

echo "<br><strong>Done.</strong><br>\n";
