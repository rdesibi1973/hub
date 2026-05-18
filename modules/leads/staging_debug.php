<?php
/**
 * staging_debug.php  — TEMPORARY DIAGNOSTIC ONLY
 * Upload to:  hub.savannahexplorers.com/modules/leads/staging_debug.php
 * Visit once, note the error, then DELETE immediately.
 */
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo '<pre style="font-family:monospace;font-size:13px;padding:20px;">';
echo "PHP version: " . PHP_VERSION . "\n";
echo "Document root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'n/a') . "\n\n";

// ── Step 1: config.php ────────────────────────────────────────────────────────
echo "--- Step 1: require config.php ---\n";
try {
    require_once 'config.php';
    echo "OK\n\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine() . "\n";
    echo $e->getTraceAsString();
    die('</pre>');
}

// ── Step 2: db() ─────────────────────────────────────────────────────────────
echo "--- Step 2: db() ---\n";
try {
    $db = db();
    echo "OK\n\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    die('</pre>');
}

// ── Step 3: lead_staging table ───────────────────────────────────────────────
echo "--- Step 3: SELECT COUNT(*) FROM lead_staging ---\n";
try {
    $n = (int)$db->query("SELECT COUNT(*) FROM lead_staging")->fetchColumn();
    echo "OK — {$n} rows\n\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    die('</pre>');
}

// ── Step 4: full staging query ────────────────────────────────────────────────
echo "--- Step 4: full staging ORDER BY query ---\n";
try {
    $leads = $db->query("
        SELECT * FROM lead_staging
        ORDER BY CASE dup_flag WHEN 'definite' THEN 1 WHEN 'possible' THEN 2 ELSE 3 END,
                 created_at DESC
    ")->fetchAll();
    echo "OK — " . count($leads) . " leads\n\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    die('</pre>');
}

// ── Step 5: current_user() ────────────────────────────────────────────────────
echo "--- Step 5: current_user() ---\n";
try {
    $u = current_user();
    echo "OK — role_name: " . ($u['role_name'] ?? '(empty)') . "\n\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    die('</pre>');
}

// ── Step 6: agents query ──────────────────────────────────────────────────────
echo "--- Step 6: agents query ---\n";
try {
    $agents = $db->query("SELECT * FROM agents WHERE active=1 ORDER BY name")->fetchAll();
    echo "OK — " . count($agents) . " agents\n\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    die('</pre>');
}

// ── Step 7: includes/header.php ───────────────────────────────────────────────
echo "--- Step 7: include header.php ---\n";
$pageTitle = 'DEBUG';
ob_start();
try {
    include 'includes/header.php';
    ob_end_clean();
    echo "OK\n\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "FAIL: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine() . "\n";
    die('</pre>');
}

echo "=== ALL STEPS PASSED — the 500 must be in the HTML/JS output section ===\n";
echo '</pre>';
