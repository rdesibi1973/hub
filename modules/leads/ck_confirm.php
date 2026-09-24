<?php
/**
 * ck_confirm.php — human confirmations of SafariCheck checks (INFO / CHECK items
 * such as "Cross-check the arrival details").
 *
 * The confirmations live in SafariCheck_confirmed.json inside the booking's
 * Dropbox folder: the same file the desktop SafariCheck exe reads and writes, so
 * a tick made in either place shows in both. Each entry keeps the fingerprint
 * of what the check showed; SafariCheck voids it when that content changes.
 * The Hub only stores the entries — fingerprints are computed by SafariCheck.
 *
 *   GET  ?id=<ck_checks.id>                         {ok, checks}
 *   POST {id, key, fp, title, on, note, csrf}       {ok, checks}
 *   (note: why an accepted ERROR is fine — the report requires it for errors)
 *
 * Called by ck_report.php (the page framing the report), never by the report.
 */
require_once 'config.php';
require_once 'dropbox_helper.php';
require_once 'includes/ck_lib.php';
requireLogin();
header('Content-Type: application/json');

const CK_CONFIRM_FILE = 'SafariCheck_confirmed.json';

function ck_confirm_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** {key: entry} from the folder's confirmations file ([] if none). */
function ck_confirm_load(string $token, string $path): array {
    $raw  = dropbox_download_text($token, $path);
    $data = $raw === null ? null : json_decode($raw, true);
    return (is_array($data) && is_array($data['checks'] ?? null)) ? $data['checks'] : [];
}

try {
    $db = db();
    ck_ensure_schema($db);
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    $in   = $post ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $_GET;

    $st = $db->prepare("SELECT f.folder_name FROM ck_checks c JOIN ck_folders f ON f.id = c.ck_folder_id
                        WHERE c.id = ? AND f.gone = 0");
    $st->execute([(int)($in['id'] ?? 0)]);
    $folder = $st->fetchColumn();
    if (!$folder) ck_confirm_out(['ok' => false, 'msg' => 'Folder not found (moved or archived?)'], 404);

    $token = dropbox_get_access_token();
    $path  = CK_BASE . '/' . $folder . '/' . CK_CONFIRM_FILE;
    $checks = ck_confirm_load($token, $path);
    if (!$post) ck_confirm_out(['ok' => true, 'checks' => (object)$checks]);

    if (!hash_equals(csrf_token(), (string)($in['csrf'] ?? ''))) {
        ck_confirm_out(['ok' => false, 'msg' => 'Session expired — reload the page.'], 403);
    }
    $key = mb_substr(trim((string)($in['key'] ?? '')), 0, 300);
    $fp  = (string)($in['fp'] ?? '');
    if ($key === '' || !preg_match('/^[0-9a-f]{40}$/', $fp)) ck_confirm_out(['ok' => false, 'msg' => 'Bad request'], 400);

    if (!empty($in['on'])) {
        $cu = current_user();
        $checks[$key] = [
            'fp'    => $fp,
            'title' => mb_substr((string)($in['title'] ?? ''), 0, 300),
            'by'    => ($cu['full_name'] ?? '') ?: ($cu['username'] ?? '?'),
            'at'    => ck_now('Y-m-d H:i'),
        ];
        $note = trim(mb_substr((string)($in['note'] ?? ''), 0, 500));
        if ($note !== '') $checks[$key]['note'] = $note;
    } else {
        unset($checks[$key]);
    }
    dropbox_upload_text($token, $path, json_encode(
        ['version' => 1, 'checks' => (object)$checks],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    ck_confirm_out(['ok' => true, 'checks' => (object)$checks]);
} catch (Throwable $e) {
    ck_confirm_out(['ok' => false, 'msg' => $e->getMessage()], 500);
}
