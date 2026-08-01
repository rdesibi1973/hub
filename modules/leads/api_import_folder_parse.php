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
 * Place this file in: /modules/leads/
 */

ob_start();
require_once 'config.php';
require_once 'includes/folder_parser.php';
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

$parsed = parse_import_folder($folder);
$db     = db();

// ── Suggest an agent by matching the handler name against the agents table ────
$agentSuggestId   = null;
$agentSuggestName = '';
if ($parsed['handler'] !== '') {
    $needle = strtolower(str_replace(' ', '', $parsed['handler']));
    $agents = $db->query("SELECT id, name FROM agents WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($agents as $a) {
        $cand = strtolower(str_replace(' ', '', $a['name']));
        if ($cand === $needle || strpos($cand, $needle) === 0) {
            $agentSuggestId   = (int)$a['id'];
            $agentSuggestName = $a['name'];
            break;
        }
    }
}
$parsed['agent_id_suggested']   = $agentSuggestId;
$parsed['agent_name_suggested'] = $agentSuggestName;

// ── Duplicate check ───────────────────────────────────────────────────────────
// Match on: exact folder (group_folder/practice_code), folder stem (status-agnostic),
// or same customer_name (+ start date when available).
$stem      = $parsed['stem'];
$name      = $parsed['customer_name'];
$startDate = $parsed['start_date'];

$sql = "SELECT id, customer_name, status, group_folder, practice_code, start_date,
               period, confirmation_date
        FROM requests
        WHERE group_folder = ?
           OR practice_code = ?
           OR group_folder  LIKE ?
           OR practice_code LIKE ?
           OR (customer_name = ? AND ? <> '')";
$stmt = $db->prepare($sql);
$stmt->execute([$folder, $folder, $stem . '%', $stem . '%', $name, $name]);

$seen    = [];
$matches = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $id = (int)$row['id'];
    if (isset($seen[$id])) continue;
    $seen[$id] = true;

    $level  = 'low';
    $reason = 'Same customer name';

    $rowFolder = (string)($row['group_folder'] ?: $row['practice_code']);
    if ($rowFolder === $folder) {
        $level  = 'exact';
        $reason = 'Identical folder already imported';
    } elseif ($stem !== '' && stripos($rowFolder, $stem) === 0) {
        $level  = 'high';
        $reason = 'Same group + dates (different status suffix)';
    } elseif ($name !== '' && strcasecmp((string)$row['customer_name'], $name) === 0
              && $startDate && $row['start_date'] === $startDate) {
        $level  = 'high';
        $reason = 'Same group name and start date';
    }

    $matches[] = [
        'id'            => $id,
        'customer_name' => $row['customer_name'],
        'status'        => $row['status'],
        'folder'        => $rowFolder,
        'start_date'    => $row['start_date'],
        'period'        => $row['period'],
        'level'         => $level,
        'reason'        => $reason,
    ];
}

// Rank: exact → high → low
$rank = ['exact' => 0, 'high' => 1, 'low' => 2];
usort($matches, function ($a, $b) use ($rank) {
    return $rank[$a['level']] <=> $rank[$b['level']];
});

$parsed['duplicates'] = $matches;

echo json_encode($parsed);
