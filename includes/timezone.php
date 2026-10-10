<?php
/**
 * Hub time zone: Tanzania (EAT, UTC+3, no daylight saving).
 *
 * The BlueHost server runs on US time, so without this date() and MySQL NOW()
 * return US local time. Loaded by every shared bootstrap (auth.php, db.php,
 * session_boot.php, iti_functions.php) and, on the server, by the off-repo
 * modules/leads/config.php. Safe to include more than once.
 *
 * PHP side: date_default_timezone_set() below.
 * MySQL side: hub_db_timezone($pdo) right after each new PDO, so NOW(),
 * CURDATE() and DEFAULT CURRENT_TIMESTAMP agree with PHP's date().
 * A fixed offset is used because shared MySQL may not have named zones loaded.
 */

if (!defined('HUB_TZ')) {
    define('HUB_TZ', 'Africa/Dar_es_Salaam');
    define('HUB_DB_TZ', '+03:00');
}
date_default_timezone_set(HUB_TZ);

if (!function_exists('hub_db_timezone')) {
    function hub_db_timezone(PDO $pdo) {
        try {
            $pdo->exec("SET time_zone = '" . HUB_DB_TZ . "'");
        } catch (Exception $e) {
            error_log('hub_db_timezone: ' . $e->getMessage());
        }
        return $pdo;
    }
}
