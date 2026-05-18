<?php
/**
 * staging_debug3.php — TEMPORARY DIAGNOSTIC v3
 * Run WHILE LOGGED IN. Inspects the db() failure specifically.
 */
ob_start();
ini_set('display_errors', '1');
error_reporting(E_ALL);

$out = [];

require_once 'config.php';

// 1. Is $pdo set globally?
$out[] = '$GLOBALS[pdo] set? ' . (isset($GLOBALS['pdo']) ? 'YES — class: ' . get_class($GLOBALS['pdo']) : 'NO (null/unset)');

// 2. What PHP type does db() actually return?
try {
    // Temporarily bypass the return type check using Reflection
    $ref = new ReflectionFunction('db');
    $out[] = 'db() defined in file: ' . $ref->getFileName();
    $out[] = 'db() defined at line: ' . $ref->getStartLine();
} catch (Throwable $e) {
    $out[] = 'ReflectionFunction failed: ' . $e->getMessage();
}

// 3. Try calling db() and capture the TypeError
try {
    $conn = db();
    $out[] = 'db() returned: ' . (is_null($conn) ? 'null' : get_class($conn));
} catch (TypeError $e) {
    $out[] = 'db() TypeError: ' . $e->getMessage();
} catch (Throwable $e) {
    $out[] = 'db() threw ' . get_class($e) . ': ' . $e->getMessage();
}

// 4. Try hub-level db.php directly
$hubDb = dirname(__DIR__, 2) . '/includes/db.php';
$out[] = 'hub db.php path: ' . $hubDb;
$out[] = 'hub db.php exists: ' . (file_exists($hubDb) ? 'YES' : 'NO');
if (file_exists($hubDb) && !isset($GLOBALS['pdo'])) {
    try {
        require_once $hubDb;
        $out[] = 'hub db.php loaded — $pdo class: ' . get_class($GLOBALS['pdo']);
    } catch (Throwable $e) {
        $out[] = 'hub db.php FAILED: ' . $e->getMessage();
    }
} elseif (isset($GLOBALS['pdo'])) {
    $out[] = '$pdo already set, skipping hub db.php load';
}

// 5. Are DB constants defined?
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $c) {
    $out[] = "define $c: " . (defined($c) ? (in_array($c, ['DB_PASS']) ? '***' : constant($c)) : 'NOT DEFINED');
}

// 6. Direct PDO test (if constants defined)
if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
    try {
        $test = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        $out[] = 'Direct PDO test: OK';
        unset($test);
    } catch (PDOException $e) {
        $out[] = 'Direct PDO test FAILED: ' . $e->getMessage();
    }
}

ob_end_clean();
header('Content-Type: text/plain; charset=utf-8');
echo "staging_debug3.php — " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('─', 60) . "\n";
foreach ($out as $line) echo $line . "\n";
echo str_repeat('─', 60) . "\n";
