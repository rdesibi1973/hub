<?php
/**
 * api_get_agent_email.php
 * Returns the email of the agent assigned to a request identified by practice_code.
 * POST: { "folder_name": "01_01JAN_..." }
 * Response: { "success": true, "email": "roberto.capri@savannahexplorers.com" }
 */
define('HS_INCLUDED', true);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
if (!defined('API_KEY')) {
    require_once __DIR__ . '/config.php';
}

header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
$apiKey = '';
if (function_exists('getallheaders')) {
    $hdrs   = getallheaders();
    $apiKey = $hdrs['X-API-Key'] ?? $hdrs['x-api-key'] ?? '';
}
if (empty($apiKey)) $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expectedKey = defined('API_KEY') ? API_KEY : '';
if (empty($expectedKey) || $apiKey !== $expectedKey) {
    http_response_code(401);
    echo json_encode(['success' => false, 'email' => '']);
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────────
$raw        = file_get_contents('php://input');
$data       = json_decode($raw, true) ?: [];
$folderName = trim($data['folder_name'] ?? $_GET['folder_name'] ?? '');

if (empty($folderName)) {
    echo json_encode(['success' => false, 'email' => '']);
    exit;
}

// ── Lookup ────────────────────────────────────────────────────────────────────
try {
    $email = null;

    // Helper: find request id by folder name with multi-level fallback
    $requestId = null;

    // Level 1: exact practice_code match
    $s = $pdo->prepare('SELECT id FROM requests WHERE practice_code = ? ORDER BY id DESC LIMIT 1');
    $s->execute([$folderName]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if ($r) $requestId = (int)$r['id'];

    // Level 2: strip status suffix
    if (!$requestId) {
        $base = $folderName;
        foreach (['_PROGRESS','_PROVISIONAL','_DEPOSIT','_BALANCE-CASH','_BALANCE','_CANCELLED','_CK','_PAID'] as $tag) {
            if (str_ends_with($base, $tag)) { $base = substr($base, 0, -strlen($tag)); break; }
        }
        if ($base !== $folderName) {
            $s->execute([$base]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) $requestId = (int)$r['id'];
        } else { $base = $folderName; }
    } else { $base = $folderName; }

    // Level 3: strip confirmed-folder date wrapper → bare customer name
    if (!$requestId) {
        $bare = preg_replace('/^\d+_\d+[A-Z]+_/i', '', $base);
        $bare = preg_replace('/_START.+$/i', '', $bare);
        if ($bare !== '' && $bare !== $base) {
            $s->execute([$bare]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) $requestId = (int)$r['id'];
        } else { $bare = $base; }
    } else { $bare = $base; }

    // Level 4: dropbox_url LIKE match
    if (!$requestId) {
        $searchName = ($bare !== $base) ? $bare : $base;
        $s2 = $pdo->prepare('SELECT id FROM requests WHERE dropbox_url LIKE ? ORDER BY id DESC LIMIT 1');
        $s2->execute(['%/' . rawurlencode($searchName) . '%']);
        $r = $s2->fetch(PDO::FETCH_ASSOC);
        if ($r) $requestId = (int)$r['id'];
    }

    // Now get the email via agent_id
    if ($requestId) {
        $s3 = $pdo->prepare(
            "SELECT u.email FROM requests r
             JOIN users u ON u.agent_id = r.agent_id
             WHERE r.id = ? AND u.email IS NOT NULL AND u.email <> ''
             ORDER BY u.id ASC LIMIT 1"
        );
        $s3->execute([$requestId]);
        $row = $s3->fetch(PDO::FETCH_ASSOC);
        if ($row) $email = $row['email'];
    }

    echo json_encode($email
        ? ['success' => true,  'email' => $email]
        : ['success' => false, 'email' => '']
    );
} catch (Exception $e) {
    echo json_encode(['success' => false, 'email' => '', 'error' => $e->getMessage()]);
}
