<?php
/**
 * backup_tables.php
 * Generates a downloadable SQL dump of the requests and requests_import tables.
 * Admin / manager only. No exec() or mysqldump required — pure PDO.
 */
require_once 'config.php';

if (isLeadsRestricted()) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$db       = db();
$tables   = ['requests', 'requests_import'];
$filename = 'backup_' . date('Ymd_His') . '.sql';

// ── Stream as downloadable file ──────────────────────────────────────────────
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');

// ── Helper: escape a value for SQL INSERT ───────────────────────────────────
function sqlVal($v): string {
    if ($v === null) return 'NULL';
    $v = str_replace(['\\', "'", "\n", "\r", "\x1a"],
                     ['\\\\', "\\'", '\\n', '\\r', '\\Z'], $v);
    return "'" . $v . "'";
}

// ── Helper: dump one table ───────────────────────────────────────────────────
function dumpTable(PDO $db, string $table): void {
    echo "-- ============================================================\n";
    echo "-- Table: `{$table}`\n";
    echo "-- Dumped: " . date('Y-m-d H:i:s') . "\n";
    echo "-- ============================================================\n\n";

    // Fetch CREATE TABLE
    $row = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
    if ($row) {
        echo "DROP TABLE IF EXISTS `{$table}`;\n";
        echo $row[1] . ";\n\n";
    }

    // Fetch rows in batches
    $offset = 0;
    $batch  = 500;
    $first  = true;

    while (true) {
        $stmt = $db->prepare("SELECT * FROM `{$table}` LIMIT ? OFFSET ?");
        $stmt->execute([$batch, $offset]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) break;

        if ($first) {
            // Column names from first batch
            $cols  = array_keys($rows[0]);
            $cList = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
            echo "INSERT INTO `{$table}` ({$cList}) VALUES\n";
            $first = false;
        } else {
            echo ",\n";
        }

        $lines = [];
        foreach ($rows as $r) {
            $vals    = array_map('sqlVal', array_values($r));
            $lines[] = '  (' . implode(', ', $vals) . ')';
        }
        echo implode(",\n", $lines);

        $offset += $batch;
        if (count($rows) < $batch) break;
    }

    if (!$first) echo ";\n\n";
    else         echo "-- (no rows)\n\n";
}

// ── Header ───────────────────────────────────────────────────────────────────
echo "-- Savannah Explorers Hub — SQL Backup\n";
echo "-- Database: " . DB_NAME . "\n";
echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
echo "-- Tables: " . implode(', ', $tables) . "\n";
echo "-- ============================================================\n\n";
echo "SET NAMES utf8mb4;\n";
echo "SET foreign_key_checks = 0;\n\n";

foreach ($tables as $t) {
    dumpTable($db, $t);
}

echo "SET foreign_key_checks = 1;\n";
echo "-- End of backup\n";
exit;
