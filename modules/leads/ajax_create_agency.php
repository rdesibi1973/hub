<?php
/**
 * ajax_create_agency.php
 *
 * Session-authenticated "quick add" for an agency, used by the inline
 * "➕ Add Agency" button on request_add.php and staging.php (Incoming approve). Unlike api_create_agency.php
 * (which needs the X-Api-Key server key), this trusts the logged-in Hub
 * session, so no secret is exposed to the browser.
 *
 * POST (form-urlencoded): nome, short_name (optional), type (savannah|promoservice|lamprati)
 * Returns JSON: { success, id, nome, short_name }  (on duplicate: success=false + the existing id)
 * Logic: agency_create() in includes/agency_service.php.
 */
require_once 'config.php';
require_once __DIR__ . '/includes/agency_service.php';
header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$res = agency_create(db(), ['nome' => $_POST['nome'] ?? '', 'short_name' => $_POST['short_name'] ?? '',
                            'type' => $_POST['type'] ?? 'savannah']);
if ($res['ok']) {
    echo json_encode(['success' => true, 'id' => $res['agency']['id'],
                      'nome' => $res['agency']['nome'], 'short_name' => $res['agency']['short_name']]);
} elseif ($res['error_code'] === 'duplicate') {
    // Duplicate → hand back the existing row so the caller can select it.
    $row = $res['duplicates'][0];
    http_response_code(409);
    echo json_encode([
        'success'    => false,
        'message'    => 'Agency "' . $row['nome'] . '" already exists — selected it.',
        'id'         => $row['id'],
        'nome'       => $row['nome'],
        'short_name' => $row['short_name'] ?: $row['nome'],
    ]);
} else {
    http_response_code($res['error_code'] === 'db' ? 500 : 400);
    echo json_encode(['success' => false, 'message' => $res['error']]);
}
