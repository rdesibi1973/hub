<?php
// api_get_agencies.php
// Returns all active agencies with id, nome, short_name, type.
// Method: GET
// Header: X-Api-Key: <API_KEY>

require_once 'config.php';
header('Content-Type: application/json');

if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== API_KEY) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = db();
$rows = $db->query(
    'SELECT id, nome, short_name, type FROM agencies WHERE attiva = 1 ORDER BY nome'
)->fetchAll(PDO::FETCH_ASSOC);

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id'         => (int)$r['id'],
        'nome'       => $r['nome'],
        'short_name' => $r['short_name'],
        'type'       => $r['type'],
    ];
}

echo json_encode($out);
