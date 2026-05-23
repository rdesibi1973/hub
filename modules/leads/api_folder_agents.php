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

// For each requested folder name, also try a version with status suffixes stripped,
// since the on-disk name may have _CK, _BALANCE_CK etc. appended after the DB value.
$statusTags = ['_BALANCE-CASH','_BALANCE_CASH','_BALANCE','_PROGRESS','_PROVISIONAL',
               '_DEPOSIT','_CANCELLED','_PAID','_CK'];

// Build a map: lookup_key → original folder name (one lookup can match multiple originals)
$lookupMap = [];   // lookup_name => [original_names]
foreach ($folders as $orig) {
    $base = $orig;
    // Strip status suffixes from the right
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($statusTags as $tag) {
            if (str_ends_with(strtoupper($base), strtoupper($tag))) {
                $base    = substr($base, 0, strlen($base) - strlen($tag));
                $changed = true;
                break;
            }
        }
    }
    $lookupMap[$base][]  = $orig;
    if ($base !== $orig) {
        $lookupMap[$orig][] = $orig; // also try exact match
    }
}

$allKeys      = array_unique(array_keys($lookupMap));
$placeholders = implode(',', array_fill(0, count($allKeys), '?'));
$stmt = $db->prepare(
    "SELECT r.practice_code, a.name AS agent_name
     FROM requests r
     LEFT JOIN agents a ON a.id = r.agent_id
     WHERE r.practice_code IN ($placeholders)"
);
$stmt->execute(array_values($allKeys));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build practice_code → agent_name from DB results
$dbMap = [];
foreach ($rows as $row) {
    $dbMap[$row['practice_code']] = $row['agent_name'] ?? 'Unknown';
}

// Map each original folder name to its agent
$result = [];
foreach ($folders as $orig) {
    // Try exact match first
    if (isset($dbMap[$orig])) {
        $result[$orig] = $dbMap[$orig];
        continue;
    }
    // Try stripped base
    $base = $orig;
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($statusTags as $tag) {
            if (str_ends_with(strtoupper($base), strtoupper($tag))) {
                $base    = substr($base, 0, strlen($base) - strlen($tag));
                $changed = true;
                break;
            }
        }
    }
    if ($base !== $orig && isset($dbMap[$base])) {
        $result[$orig] = $dbMap[$base];
    }
    // Not found — omit from result (caller uses extractAgent fallback)
}

echo json_encode($result);
