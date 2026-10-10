<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/timezone.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    hub_db_timezone($pdo);
} catch (PDOException $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    if (defined('DB_FAIL_JSON')) {   // JSON callers (Agent API): tell an outage from a bad call
        while (ob_get_level()) ob_end_clean();
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        die('{"ok":false,"error":"database unavailable"}');
    }
    die('Database connection failed. Please contact your administrator.');
}
