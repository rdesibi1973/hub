<?php
// api_folder_agents.php
// Given a list of folder names (practice_code), returns the agent name for each.
// Method: POST
// Header: X-Api-Key: <API_KEY>
// Body:   {"folders": ["01_Smith(Micky-Drct)", "03_Jones(Sultan-Agency)", ...]}
// Returns: {"01_Smith(Micky-Drct)": "Micky", "03_Jones(Sultan-Agency)": "Sultan", ...}
// Folders not found in DB are omitted from the response.

require_once 'config.php';
header('Content-Type: application/json');

if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$folders = $body['folders'] ?? [];

if (!is_array($folders) || empty($folders)) {
    echo json_encode((object)[]);
    exit;
}

// Sanitise: keep only non-empty strings, limit to 500 entries
$folders = array_values(array_filter(array_slice($folders, 0, 500), 'is_string'));
if (empty($folders)) {
    echo json_encode((object)[]);
    exit;
}

$db = db();

$placeholders = implode(',', array_fill(0, count($folders), '?'));
$stmt = $db->prepare(
    "SELECT r.practice_code, a.name AS agent_name
     FROM requests r
     LEFT JOIN agents a ON a.id = r.agent_id
     WHERE r.practice_code IN ($placeholders)"
);
$stmt->execute($folders);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$result = [];
foreach ($rows as $row) {
    $folder = $row['practice_code'];
    $agent  = $row['agent_name'] ?? 'Unknown';
    $result[$folder] = $agent;
}

echo json_encode($result);
