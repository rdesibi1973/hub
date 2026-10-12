<?php
// api_create_agency.php
// Adds a new agency to the agencies table.
// Method: POST  |  Header: X-Api-Key: <API_KEY>
// Body JSON: { "nome": "...", "short_name": "...", "type": "savannah|promoservice|lamprati", "address", "email" }

require_once 'config.php';
require_once __DIR__ . '/includes/agency_service.php';
header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== API_KEY) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];
$res  = agency_create(db(), $body);   // includes/agency_service.php
if ($res['ok']) {
    echo json_encode([
        'success'    => true,
        'id'         => $res['agency']['id'],
        'nome'       => $res['agency']['nome'],
        'short_name' => $res['agency']['short_name'],
        'type'       => $res['agency']['type'],
    ]);
} else {
    http_response_code(['duplicate' => 409, 'db' => 500][$res['error_code']] ?? 400);
    echo json_encode(['success' => false, 'message' => $res['error']]);
}
