<?php
/**
 * ajax_set_confirmation_date.php
 * Saves (or clears) the confirmation_date for a request.
 *
 * POST params:
 *   id               int     – request ID
 *   confirmation_date string – date in Y-m-d format, or '' to clear
 *
 * Place this file in the same directory as dashboard.php (modules/leads/)
 */

require_once 'config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
    exit;
}

$id   = (int)($_POST['id']   ?? 0);
$date = trim($_POST['confirmation_date'] ?? '');

if (!$id) {
    echo json_encode(['ok'=>false,'error'=>'Missing id']);
    exit;
}

// Validate date format
if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['ok'=>false,'error'=>'Invalid date format']);
    exit;
}

$db = db();

try {
    $stmt = $db->prepare("UPDATE requests SET confirmation_date = ? WHERE id = ?");
    $stmt->execute([$date !== '' ? $date : null, $id]);
    echo json_encode(['ok'=>true, 'confirmation_date'=>$date]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
