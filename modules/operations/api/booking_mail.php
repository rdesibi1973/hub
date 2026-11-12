<?php
/**
 * api/booking_mail.php — JSON for Operations → Booking mail (booking_mail.php).
 *   GET  ?action=check                                     open lines crossed with the mails (admin)
 *   GET  ?action=attachment&key=&part=&reason=             download one attachment of a thread (admin, logged)
 *   GET  ?action=passports&request_id=N                    guest rows + documents that can be read (booking team)
 *   POST ?action=scan                                      read new headers now (admin)
 *   POST ?action=thread   {key, reason}                    a thread with its text (admin, logged)
 *   POST ?action=reply    {key, body, reason, confirm}     reply from the same mailbox (admin, logged)
 *   POST ?action=proof    {key, line_id, date, save_as?, reason, confirm}   save the message as .eml in the line's mails/ and set
 *                                                          its expiry with it as proof (admin, logged; followup_set_expiry())
 *   POST ?action=passport_read  {request_id, src}          read one document (booking team)
 *   POST ?action=passport_apply {request_id, rows}         write confirmed rows (booking team)
 * Logic: includes/booking_mail_service.php, includes/passport_service.php. Not available through the Agent API.
 */
ob_start();
require_once __DIR__ . '/../../leads/config.php';
if (!defined('AGENT_API_KEY') && is_file(__DIR__ . '/../../../includes/config.php')) require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../leads/dropbox_helper.php';
require_once __DIR__ . '/../../leads/includes/booking_service.php';
require_once __DIR__ . '/../../leads/includes/calc_reader.php';
require_once __DIR__ . '/../../leads/includes/dbx_conflict_service.php';
require_once __DIR__ . '/../../iti/includes/iti_functions.php';
require_once __DIR__ . '/../../iti/includes/iti_ai_service.php';
require_once __DIR__ . '/../includes/booking_mail_service.php';
require_once __DIR__ . '/../includes/passport_service.php';

function bm_json(array $d, int $code = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!is_logged_in()) bm_json(['ok' => false, 'error' => 'Session expired: log in again'], 401);
$user = current_user();
$db = $pdo;
hub_db_timezone($db);
$action = (string)($_GET['action'] ?? '');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'check') {
            if (!bm_can_read($user)) bm_json(['ok' => false, 'error' => 'Admins only'], 403);
            bm_json(['ok' => true, 'lines' => bm_crosscheck($db), 'last_run' => bm_last_run($db)]);
        }
        if ($action === 'passports') bm_json(['ok' => true] + ppt_overview($db, $user, (int)($_GET['request_id'] ?? 0)));
        if ($action === 'attachment') {
            $a = bm_attachment($db, $user, (string)($_GET['key'] ?? ''), (string)($_GET['part'] ?? ''), (string)($_GET['reason'] ?? ''));
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/octet-stream');   // always a download: a mail attachment never runs in the Hub's origin
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($a['name']));
            echo $a['content'];
            exit;
        }
        bm_json(['ok' => false, 'error' => 'Unknown action'], 400);
    }

    // POST: JSON body only (a cross-site form cannot send it).
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) bm_json(['ok' => false, 'error' => 'JSON body expected'], 415);
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) bm_json(['ok' => false, 'error' => 'Invalid JSON'], 400);

    if ($action === 'scan') {
        if (!bm_can_read($user)) bm_json(['ok' => false, 'error' => 'Admins only'], 403);
        @set_time_limit(280);
        bm_json(['ok' => true] + ['run' => bm_run($db)]);
    }
    if ($action === 'thread') bm_json(['ok' => true, 'messages' => bm_open_thread($db, $user, (string)($in['key'] ?? ''), (string)($in['reason'] ?? ''))]);
    if ($action === 'proof') {    // "Save as proof & set expiry": dry run unless confirm
        $res = bm_save_proof($db, $user, (string)($in['key'] ?? ''), (int)($in['line_id'] ?? 0), (string)($in['date'] ?? ''),
                             (string)($in['save_as'] ?? ''), (string)($in['reason'] ?? ''), !empty($in['confirm']));
        bm_json(['ok' => true, 'dry_run' => empty($in['confirm']), 'saved' => $res['saved'] ?? null, 'changes' => $res['changes'] ?? [], 'row' => $res['row'] ?? null]);
    }
    if ($action === 'reply') bm_json(['ok' => true] + bm_reply($db, $user, (string)($in['key'] ?? ''), (string)($in['body'] ?? ''), (string)($in['reason'] ?? ''), !empty($in['confirm'])));
    if ($action === 'passport_read') {
        @set_time_limit(280);
        bm_json(['ok' => true] + ppt_read($db, $user, (int)($in['request_id'] ?? 0), (array)($in['src'] ?? [])));
    }
    if ($action === 'passport_apply') {
        @set_time_limit(280);
        bm_json(['ok' => true] + ppt_apply($db, $user, (int)($in['request_id'] ?? 0), (array)($in['rows'] ?? [])));
    }
    bm_json(['ok' => false, 'error' => 'Unknown action'], 400);
} catch (InvalidArgumentException $e) {
    bm_json(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('booking_mail api ' . $action . ': ' . get_class($e));   // the class only: a message could quote document data
    bm_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
