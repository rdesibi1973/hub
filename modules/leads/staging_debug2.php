<?php
/**
 * staging_debug2.php — TEMPORARY DIAGNOSTIC v2
 * No output before session/headers, so warnings are suppressed.
 * Upload to modules/leads/, visit it while LOGGED IN, then delete.
 */
ob_start();                          // buffer all output — no headers-sent issues
ini_set('display_errors', '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$steps = [];
function step(string $label, callable $fn): void {
    global $steps;
    try {
        $result = $fn();
        $steps[] = ['ok', $label, $result ?? 'OK'];
    } catch (Throwable $e) {
        $steps[] = ['fail', $label, get_class($e) . ': ' . $e->getMessage()
                    . ' in ' . basename($e->getFile()) . ' line ' . $e->getLine()];
    }
}

// ── 1. config.php ─────────────────────────────────────────────────────────────
step('require config.php', function() {
    require_once 'config.php';
    return 'OK';
});

// ── 2. db() ───────────────────────────────────────────────────────────────────
step('db()', function() {
    $db = db();
    return 'OK — class: ' . get_class($db);
});

// ── 3. current_user() ─────────────────────────────────────────────────────────
step('current_user()', function() {
    $u = current_user();
    return 'user_id=' . ($u['id'] ?? 'null')
         . ' role=' . ($u['role_name'] ?: '(empty — stale session!)')
         . ' full_name=' . ($u['full_name'] ?: '(empty)');
});

// ── 4. lead_staging count ─────────────────────────────────────────────────────
step('COUNT lead_staging', function() {
    $n = (int)db()->query("SELECT COUNT(*) FROM lead_staging")->fetchColumn();
    return "$n rows";
});

// ── 5. full staging query ─────────────────────────────────────────────────────
step('SELECT * FROM lead_staging (ORDER BY CASE)', function() {
    $leads = db()->query("
        SELECT * FROM lead_staging
        ORDER BY CASE dup_flag WHEN 'definite' THEN 1 WHEN 'possible' THEN 2 ELSE 3 END,
                 created_at DESC
    ")->fetchAll();
    return count($leads) . ' leads';
});

// ── 6. email duplicate lookup ─────────────────────────────────────────────────
step('Email duplicate IN() query against requests', function() {
    $db = db();
    $leads = $db->query("SELECT * FROM lead_staging")->fetchAll();
    $stagedEmails = array_filter(array_column($leads, 'email'));
    if (!$stagedEmails) return 'skipped (no emails in staging)';
    $lower  = array_values(array_map('strtolower', $stagedEmails));
    $ph     = implode(',', array_fill(0, count($lower), '?'));
    $stmt   = $db->prepare(
        "SELECT id, customer_name, email FROM requests WHERE LOWER(email) IN ($ph) ORDER BY id DESC"
    );
    $stmt->execute($lower);
    $rows = $stmt->fetchAll();
    return count($rows) . ' matches found';
});

// ── 7. json_encode of leads ────────────────────────────────────────────────────
step('json_encode LEADS array', function() {
    $leads = db()->query("SELECT * FROM lead_staging")->fetchAll();
    $indexed = array_column($leads, null, 'id');
    $json = json_encode($indexed, JSON_UNESCAPED_UNICODE);
    if ($json === false) return 'FAILED: ' . json_last_error_msg();
    return 'OK — ' . strlen($json) . ' bytes';
});

// ── 8. include header.php ──────────────────────────────────────────────────────
step('include header.php', function() {
    global $pageTitle;
    $pageTitle = 'DEBUG';
    ob_start();
    include 'includes/header.php';
    ob_end_clean();
    return 'OK';
});

// ── 9. requireLogin() directly ────────────────────────────────────────────────
step('requireLogin() directly', function() {
    requireLogin();
    return 'OK (not redirected)';
});

// ── render ────────────────────────────────────────────────────────────────────
ob_end_clean();
header('Content-Type: text/plain; charset=utf-8');
echo "staging_debug2.php — " . date('Y-m-d H:i:s') . "\n";
echo "PHP " . PHP_VERSION . " | " . php_uname('s') . "\n";
echo str_repeat('─', 60) . "\n";
foreach ($steps as [$status, $label, $msg]) {
    $icon = $status === 'ok' ? '✓' : '✗ FAIL';
    echo "$icon  $label\n";
    echo "   → $msg\n";
}
echo str_repeat('─', 60) . "\n";
echo "Done.\n";
