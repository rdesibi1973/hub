<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_permission('operations');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'POST only']); exit; }
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { echo json_encode(['error' => 'Invalid JSON']); exit; }

$id           = (int)($body['id'] ?? 0);
$clientName   = trim($body['client_name']   ?? '');
$safariDate   = $body['safari_date']   ?? null;
$travelers    = isset($body['travelers'])    && $body['travelers'] !== null ? (int)$body['travelers']    : null;
$jeeps        = isset($body['jeeps'])        && $body['jeeps']    !== null ? (int)$body['jeeps']        : null;
$extraDetails = trim($body['extra_details'] ?? '') ?: null;
$folderName   = trim($body['folder_name']   ?? '') ?: null;
$notes        = trim($body['notes']         ?? '') ?: null;

if (!$id)         { echo json_encode(['error' => 'Missing id']);          exit; }
if (!$clientName) { echo json_encode(['error' => 'Client name required']); exit; }

$dateSql = ($safariDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $safariDate)) ? $safariDate : null;

try {
    $stmt = $pdo->prepare(
        'UPDATE lunch_boxes
         SET client_name=?, safari_date=?, travelers=?, jeeps=?,
             extra_details=?, folder_name=?, notes=?
         WHERE id=?'
    );
    $stmt->execute([$clientName, $dateSql, $travelers, $jeeps, $extraDetails, $folderName, $notes, $id]);
    echo json_encode(['ok' => true, 'affected' => $stmt->rowCount()]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
