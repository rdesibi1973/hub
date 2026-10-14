<?php
/**
 * api/followup.php — JSON for the Booking follow-up page (booking_followup.php).
 *   GET  ?action=list[&status=open|closed|all]   lines + stat cards + last runs
 *   GET  ?action=events&id=N                     history of a line
 *   POST ?action=update  {id, take?, owner?, contacted_at?, staff_status?, notes?}
 *   POST ?action=scan    {type: supplier|ticket|all}       ("Run check now")
 * Viewing: any logged-in Hub user. Changes: module booking_followup_edit or admin
 * (followup_update() checks; reassign / not_relevant admin only). Logic: includes/followup_service.php.
 */
ob_start();
require_once __DIR__ . '/../../leads/config.php';     // $pdo, auth, Dropbox keys (server-only)
require_once __DIR__ . '/../includes/followup_service.php';

function fu_json(array $d, int $code = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

if (!is_logged_in()) fu_json(['ok' => false, 'error' => 'Session expired — log in again'], 401);
$user = current_user();
$db = $pdo;
hub_db_timezone($db);
$action = (string)($_GET['action'] ?? 'list');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'events') fu_json(['ok' => true, 'events' => followup_events($db, (int)($_GET['id'] ?? 0))]);
        if ($action !== 'list') fu_json(['ok' => false, 'error' => 'Unknown action'], 400);
        fu_json(['ok' => true] + followup_page_data($db, $user));
    }

    // POST: JSON body only (a cross-site form cannot send it).
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) fu_json(['ok' => false, 'error' => 'JSON body expected'], 415);
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) fu_json(['ok' => false, 'error' => 'Invalid JSON'], 400);
    if (!followup_can_edit($user)) fu_json(['ok' => false, 'error' => 'View only — ask Roberto for the booking follow-up edit permission'], 403);

    if ($action === 'update') {
        $ch = array_intersect_key($in, array_flip(['take', 'owner', 'contacted_at', 'staff_status', 'notes']));
        $res = followup_update($db, (int)($in['id'] ?? 0), $ch, $user);
        if (!$res['ok']) fu_json($res, $res['code'] ?? 400);
        fu_json($res);
    }
    if ($action === 'scan') {
        $res = followup_scan($db, ['type' => $in['type'] ?? 'all', 'confirm' => true, 'source' => 'page', 'user_id' => (int)$user['id'],
                                   'budget' => 150]);   // stay inside the browser / PHP time limit; the next run reads the rest
        $out = ['ok' => true, 'summary' => []];
        foreach (['supplier', 'ticket'] as $t) {
            if (!isset($res[$t])) continue;
            $out['summary'][$t] = followup_counts($res[$t])
                + array_intersect_key($res[$t], array_flip(['warning', 'hint', 'errors', 'window']))
                + ['closed_lines' => $res[$t]['closed'], 'reopened_lines' => $res[$t]['reopened']];
        }
        fu_json($out + followup_page_data($db, $user));
    }
    fu_json(['ok' => false, 'error' => 'Unknown action'], 400);
} catch (Throwable $e) {
    fu_json(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
}

