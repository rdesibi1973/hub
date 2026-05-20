<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_permission('operations');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'POST only']); exit; }
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { echo json_encode(['error' => 'Invalid JSON']); exit; }

$clientName   = trim($body['client_name']   ?? '');
$safariDate   = $body['safari_date']   ?? null;
$travelers    = isset($body['travelers'])    && $body['travelers'] !== null ? (int)$body['travelers']    : null;
$jeeps        = isset($body['jeeps'])        && $body['jeeps']    !== null ? (int)$body['jeeps']        : null;
$guide        = trim($body['guide']         ?? '') ?: null;
$extraDetails = trim($body['extra_details'] ?? '') ?: null;
$folderName   = trim($body['folder_name']   ?? '') ?: null;
$notes        = trim($body['notes']         ?? '') ?: null;
$sourceFile   = trim($body['source_file']   ?? '') ?: null;

if (!$clientName) { echo json_encode(['error' => 'Client name is required']); exit; }

$dateSql = ($safariDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $safariDate)) ? $safariDate : null;
$userId  = current_user()['id'] ?? null;
$groupRef = uniqid('lb_', true);

try {
    $pdo->prepare(
        'INSERT INTO lunch_boxes
         (group_ref, client_name, safari_date, travelers, jeeps, guide,
          extra_details, folder_name, notes, source_file, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $groupRef, $clientName, $dateSql, $travelers, $jeeps, $guide,
        $extraDetails, $folderName, $notes, $sourceFile, $userId
    ]);

    echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId(), 'client_name' => $clientName]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
