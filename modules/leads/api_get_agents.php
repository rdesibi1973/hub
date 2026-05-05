<?php
// api_get_agents.php
// Returns active agents for the GUI dropdown.
// Method: GET
// Header: X-Api-Key: <API_KEY>
// Returns JSON array: [ { "id": 1, "name": "Samwel" }, ... ]

require_once 'config.php';
header('Content-Type: application/json');

if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db   = db();
$stmt = $db->query(
    'SELECT DISTINCT a.id, a.name
     FROM agents a
     JOIN users u ON u.agent_id = a.id
     WHERE a.active = 1 AND u.is_active = 1
     ORDER BY a.name ASC'
);
echo json_encode($stmt->fetchAll());
