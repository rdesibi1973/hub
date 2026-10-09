<?php
/**
 * api_import_folder_parse.php
 *
 * Same-origin AJAX endpoint for the Import Group Folder page.
 * Given a confirmed-group Dropbox folder name, parses it into request fields,
 * suggests an agent, and runs the duplicate check against `requests`.
 *
 * Auth: session (requireLogin) — called from import_folder.php with the session cookie.
 * Method: POST  { "folder": "03_02MAR_Panorama05_(Diamante-PS-Roberto)_START02MAR_END09MAR2027_CONFIRMED" }
 *
 * Logic: includes/group_import_service.php (shared with the Agent API import_group_folder).
 */

ob_start();
require_once 'config.php';
require_once 'includes/group_import_service.php';
requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$folder = trim($body['folder'] ?? '');
if ($folder === '') {
    echo json_encode(['ok' => false, 'error' => 'No folder name provided.']);
    exit;
}

$db     = db();
$parsed = gi_parse($db, $folder);                              // fields + suggested agent
$parsed['duplicates'] = gi_duplicates($db, $parsed, $folder);  // ranked exact → high → low

echo json_encode($parsed);
