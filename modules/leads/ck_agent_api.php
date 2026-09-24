<?php
// modules/leads/ck_agent_api.php
// API for the SafariCheck cloud runner (safariagent ci_check.py on GitHub
// Actions). The runner never gets Dropbox credentials: it lists and downloads
// the booking files through here, and only the files the checks open are
// served (top-level Excel/Word/PDF, invoices/*.pdf) — never passports.
//
// Auth: header X-CK-Token (or ?token=) = CK_AGENT_TOKEN from includes/config.php.
//
//   GET  ?action=queue[&ids=1,2]   folders to check
//   GET  ?action=list&id=N         recursive file listing of the folder
//   GET  ?action=file&id=N&path=P  one allowed file (binary)
//   POST ?action=result            JSON result of one folder

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/dropbox_helper.php';
require_once __DIR__ . '/includes/folder_parser.php';
require_once __DIR__ . '/includes/ck_lib.php';

function ck_api_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$expected = defined('CK_AGENT_TOKEN') ? (string)CK_AGENT_TOKEN : '';
$given    = (string)($_SERVER['HTTP_X_CK_TOKEN'] ?? $_GET['token'] ?? '');
if ($expected === '') ck_api_out(['ok' => false, 'msg' => 'CK_AGENT_TOKEN not configured'], 500);
if (!hash_equals($expected, $given)) ck_api_out(['ok' => false, 'msg' => 'Forbidden'], 403);

$db = db();
ck_ensure_schema($db);
$action = $_GET['action'] ?? '';

/** Tracked folder by id (must still be at the top of 001_Safari). */
function ck_api_folder(PDO $db, int $id): array {
    $st = $db->prepare("SELECT * FROM ck_folders WHERE id = ? AND gone = 0");
    $st->execute([$id]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) ck_api_out(['ok' => false, 'msg' => "Folder $id not found"], 404);
    return $f;
}

try {
    // ── Queue ────────────────────────────────────────────────────────────────
    if ($action === 'queue') {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))));
        $out = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT id, folder_name, check_trigger FROM ck_folders WHERE gone = 0 AND id IN ($in)");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $out[] = ['id' => (int)$f['id'], 'folder' => $f['folder_name'],
                          'trigger' => $f['check_trigger'] ?: 'manual', 'only_if_changed' => false];
            }
        } else {
            // Nightly: every pending request, plus upcoming booked folders without CK
            // (re-checked only if their files changed since the last check).
            $st = $db->prepare(
                "SELECT f.id, f.folder_name, f.check_requested_at, f.check_trigger, c.fingerprint
                 FROM ck_folders f LEFT JOIN ck_checks c ON c.id = f.last_check_id
                 WHERE f.gone = 0
                   AND (f.check_requested_at IS NOT NULL
                        OR (f.has_ck = 0 AND f.stage IN ('Deposit','Balance','Balance-Cash','Paid')
                            AND f.start_date >= ?))
                 ORDER BY f.start_date");
            $st->execute([ck_now('Y-m-d')]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $pending = $f['check_requested_at'] !== null;
                $out[] = ['id' => (int)$f['id'], 'folder' => $f['folder_name'],
                          'trigger' => $pending ? ($f['check_trigger'] ?: 'manual') : 'nightly',
                          'only_if_changed' => !$pending && $f['fingerprint'] !== null,
                          'last_fingerprint' => $f['fingerprint']];
            }
        }
        ck_api_out(['ok' => true, 'folders' => $out]);
    }

    // ── Listing ──────────────────────────────────────────────────────────────
    if ($action === 'list') {
        $f = ck_api_folder($db, (int)($_GET['id'] ?? 0));
        $entries = dropbox_list_recursive(dropbox_get_access_token(), CK_BASE . '/' . $f['folder_name']);
        ck_api_out(['ok' => true, 'entries' => $entries]);
    }

    // ── One file (only what the checks open) ─────────────────────────────────
    if ($action === 'file') {
        $f   = ck_api_folder($db, (int)($_GET['id'] ?? 0));
        $rel = trim((string)($_GET['path'] ?? ''), '/');
        if (!ck_agent_file_allowed($rel)) ck_api_out(['ok' => false, 'msg' => 'File not allowed'], 403);
        $data = dropbox_download_text(dropbox_get_access_token(), CK_BASE . '/' . $f['folder_name'] . '/' . $rel);
        if ($data === null) ck_api_out(['ok' => false, 'msg' => 'File not found'], 404);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . strlen($data));
        echo $data;
        exit;
    }

    // ── Result ───────────────────────────────────────────────────────────────
    if ($action === 'result' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $r = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($r) || empty($r['id'])) ck_api_out(['ok' => false, 'msg' => 'Bad payload'], 400);
        $f   = ck_api_folder($db, (int)$r['id']);
        $now = ck_now();

        if (!empty($r['unchanged'])) {
            $db->prepare("UPDATE ck_folders SET last_checked_at = ? WHERE id = ?")->execute([$now, (int)$f['id']]);
            ck_api_out(['ok' => true]);
        }
        $c   = $r['counts'] ?? [];
        $enc = fn($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE);
        $db->prepare("INSERT INTO ck_checks
              (ck_folder_id, created_at, trigger_src, folder_name, overall, n_red, n_yellow, n_green, n_grey,
               fingerprint, checks_json, facts_json, files_json, report_html, error)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               (int)$f['id'], $now, substr((string)($r['trigger'] ?? ''), 0, 12), $f['folder_name'],
               isset($r['error']) ? 'error' : substr((string)($r['overall'] ?? ''), 0, 8),
               (int)($c['red'] ?? 0), (int)($c['yellow'] ?? 0), (int)($c['green'] ?? 0), (int)($c['grey'] ?? 0),
               $r['fingerprint'] ?? null,
               $enc($r['checks'] ?? null), $enc($r['facts'] ?? null), $enc($r['files'] ?? null),
               $r['report_html'] ?? null, $r['error'] ?? null,
           ]);
        $checkId = (int)$db->lastInsertId();
        $db->prepare("UPDATE ck_folders SET last_check_id = ?, last_checked_at = ?,
                             check_requested_at = NULL, check_trigger = NULL WHERE id = ?")
           ->execute([$checkId, $now, (int)$f['id']]);
        ck_log($db, (int)$f['id'], 'check',
               null, isset($r['error']) ? 'error' : ($r['overall'] ?? ''), null, 'agent', $now);
        ck_api_out(['ok' => true, 'check_id' => $checkId]);
    }

    ck_api_out(['ok' => false, 'msg' => 'Unknown action'], 400);
} catch (Throwable $e) {
    ck_api_out(['ok' => false, 'msg' => $e->getMessage()], 500);
}
