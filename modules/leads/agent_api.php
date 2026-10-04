<?php
/**
 * agent_api.php — JSON API for Claude (Cowork / Claude Code / scheduled tasks) to
 * run the booking workflow without driving the web UI. Public entry point:
 * /api/agent/index.php?action=<name>  (which chdir()s here and includes this file).
 * Spec + examples: docs/AGENT_API.md.
 *
 * Auth: header X-Agent-Key = AGENT_API_KEY (includes/config.php, never in the repo).
 * The key acts as the Hub user AGENT_API_USER (username; default 'claude_agent').
 * HTTPS only, 60 requests/min, every call written to agent_audit_log.
 * Outbound / folder-moving actions (confirm_booking, send_booking_email) are
 * dry-runs unless the body carries "confirm": true.
 *
 * All logic lives in includes/booking_service.php (invoices:
 * ../invoices/includes/invoice_service.php), shared with the Hub pages.
 */
ob_start();
date_default_timezone_set('Africa/Dar_es_Salaam');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/booking_service.php';
require_once __DIR__ . '/includes/postpone_lib.php';   // booking_cc_agent_email()
require_once __DIR__ . '/includes/calc_service.php';   // get_rates, fill_calc
require_once __DIR__ . '/../iti/includes/iti_texts.php'; // ITI programme translations
require_once __DIR__ . '/../iti/includes/iti_content_service.php'; // ITI lodges / destinations / photos
// AGENT_API_KEY / AGENT_API_USER live in the root includes/config.php (server-only).
if (!defined('AGENT_API_KEY') && is_file(__DIR__ . '/../../includes/config.php')) {
    require_once __DIR__ . '/../../includes/config.php';
}

const AGENT_RATE_PER_MIN = 60;

$agentStarted = microtime(true);
$agentAction  = (string)($_GET['action'] ?? '');
$agentReqId   = null;       // request_id the call touched (for the audit log)
$agentPayload = [];

/** Emit JSON, write the audit row, stop. */
function agent_out(array $data, int $code = 200): void {
    global $agentAction, $agentReqId, $agentPayload;
    if (ob_get_length()) ob_clean();   // drop any stray warnings/notices
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    echo $json;
    try {
        agent_audit(db(), $agentAction, $agentReqId, $agentPayload, $json, $code);
    } catch (Throwable $e) {
        error_log('agent_api audit failed: ' . $e->getMessage());
    }
    exit;
}

function agent_fail(string $msg, int $code = 400, array $extra = []): void {
    agent_out(array_merge(['ok' => false, 'error' => $msg], $extra), $code);
}

function agent_client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function agent_ensure_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS agent_audit_log (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        ts           DATETIME NOT NULL,
        action       VARCHAR(40) NOT NULL,
        request_id   INT NULL,
        user_id      INT NULL,
        http_code    SMALLINT NOT NULL DEFAULT 200,
        dry_run      TINYINT(1) NOT NULL DEFAULT 0,
        payload_json MEDIUMTEXT NULL,
        result_json  MEDIUMTEXT NULL,
        ip           VARCHAR(45) NULL,
        KEY idx_ts (ts),
        KEY idx_request (request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function agent_audit(PDO $db, string $action, $reqId, array $payload, string $resultJson, int $code): void {
    global $agentUser;
    agent_ensure_schema($db);
    $dry = in_array($action, ['confirm_booking', 'send_booking_email', 'rollback_booking', 'fill_calc', 'iti_save_texts', 'update_rate', 'replace_flight_rates',
                              'cancel_invoice_payment', 'update_folder_status', 'import_zoho_invoice', 'mail_send', 'mail_move',
                              'iti_lodge_photos', 'iti_destination_photo', 'iti_update_lodge', 'iti_update_destination',
                              'iti_create_personal', 'iti_update_program', 'iti_update_day', 'iti_publish', 'iti_final_from_calc', 'iti_save_alias',
                              'iti_create_lodge', 'iti_set_days', 'iti_add_day', 'iti_delete_day', 'iti_update_inclusions',
                              'iti_create_flight_route', 'iti_create_activity', 'iti_create_transfer_route', 'iti_save_as_sample',
                              'create_request', 'add_flight_rates'], true) && empty($payload['confirm']);
    // Mail: keep who/what in the log, not message bodies or attachment content.
    if (in_array($action, ['mail_get', 'mail_attachment'], true) && $code === 200) {
        $res = json_decode($resultJson, true);
        $m   = $res['message'] ?? $res['attachment'] ?? [];
        $resultJson = json_encode(['ok' => true, 'logged' => 'content omitted', 'uid' => $m['uid'] ?? null,
                                   'subject' => $m['subject'] ?? null, 'name' => $m['name'] ?? null,
                                   'saved_to' => $res['saved_to'] ?? null], JSON_UNESCAPED_UNICODE);
    }
    if (!empty($payload['uploads']) && is_array($payload['uploads'])) {      // ITI photos sent as files
        foreach ($payload['uploads'] as &$u) {
            if (is_array($u) && isset($u['content_base64'])) $u['content_base64'] = '[' . strlen((string)$u['content_base64']) . ' chars]';
        }
        unset($u);
    }
    if (!empty($payload['attachments']) && is_array($payload['attachments'])) {
        foreach ($payload['attachments'] as &$a) {
            if (is_array($a) && isset($a['content_base64'])) $a['content_base64'] = '[' . strlen((string)$a['content_base64']) . ' chars]';
        }
        unset($a);
    }
    $db->prepare("INSERT INTO agent_audit_log (ts, action, request_id, user_id, http_code, dry_run, payload_json, result_json, ip)
                  VALUES (?,?,?,?,?,?,?,?,?)")
       ->execute([
           date('Y-m-d H:i:s'), substr($action, 0, 40), $reqId ? (int)$reqId : null,
           isset($agentUser['id']) ? (int)$agentUser['id'] : null, $code, $dry ? 1 : 0,
           json_encode($payload, JSON_UNESCAPED_UNICODE), substr($resultJson, 0, 200000), agent_client_ip(),
       ]);
}

// ── Transport + auth ─────────────────────────────────────────────────────────
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
if (!$isHttps) agent_fail('HTTPS required', 403);

$expected = defined('AGENT_API_KEY') ? (string)AGENT_API_KEY : '';
$given    = (string)($_SERVER['HTTP_X_AGENT_KEY'] ?? '');
if ($expected === '') agent_fail('AGENT_API_KEY not configured', 500);
if ($given === '' || !hash_equals($expected, $given)) agent_fail('Forbidden', 403);

$db = db();
agent_ensure_schema($db);

// Rate limit: calls in the last 60 s (every call is audited, so the log is the counter).
$rl = $db->prepare("SELECT COUNT(*) FROM agent_audit_log WHERE ts >= ?");
$rl->execute([date('Y-m-d H:i:s', time() - 60)]);
if ((int)$rl->fetchColumn() >= AGENT_RATE_PER_MIN) agent_fail('Rate limit exceeded (' . AGENT_RATE_PER_MIN . '/min)', 429);

// The Hub user the key acts as.
$agentUsername = defined('AGENT_API_USER') ? (string)AGENT_API_USER : 'claude_agent';
$us = $db->prepare("SELECT u.id, u.username, u.full_name, u.agent_id, u.role_id, r.name AS role_name
                    FROM users u LEFT JOIN roles r ON r.id = u.role_id
                    WHERE u.username = ? AND u.is_active = 1 LIMIT 1");
$us->execute([$agentUsername]);
$agentUser = $us->fetch(PDO::FETCH_ASSOC);
if (!$agentUser) agent_fail('API user "' . $agentUsername . '" not found or inactive — create it in Hub (see docs/AGENT_API.md)', 500);

// ── Input ────────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $raw = (string)file_get_contents('php://input');
    $agentPayload = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($agentPayload)) agent_fail('Body must be a JSON object');
    // {"b64": "<base64 of the UTF-8 JSON body>"}: same request, but the server firewall
    // (Mod_Security) does not see accents / words that it sometimes blocks.
    if (isset($agentPayload['b64']) && count($agentPayload) === 1) {
        $agentPayload = json_decode((string)base64_decode((string)$agentPayload['b64'], true), true);
        if (!is_array($agentPayload)) agent_fail('b64 must be the base64 of a JSON object');
    }
} else {
    $agentPayload = $_GET;
    unset($agentPayload['action']);
    if (isset($agentPayload['b64'])) {   // ?b64=<base64 of a JSON object of the query parameters>
        $q = json_decode((string)base64_decode(strtr((string)$agentPayload['b64'], '-_', '+/'), true), true);
        if (!is_array($q)) agent_fail('b64 must be the base64 of a JSON object');
        $agentPayload = $q;
    }
}
$in = $agentPayload;

function agent_require_method(string $m): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $m) agent_fail('Use ' . $m . ' for this action', 405);
}

/** ITI programme services (iti_functions.php + Calc → final programme), loaded only for those actions. */
function agent_iti_lib(): void {
    require_once __DIR__ . '/../iti/includes/iti_program_service.php';
    require_once __DIR__ . '/../iti/includes/iti_final.php';
    require_once __DIR__ . '/dropbox_helper.php';          // Calc read from Dropbox
}

/** routes[] {route, origin, destination, airline?, cost, sale?, valid_from, valid_to?, notes?} → flight_routes rows (or 400). */
function agent_flight_rate_rows(array $routes): array {
    $rows = [];
    foreach ($routes as $i => $r) {
        $name = trim((string)($r['route'] ?? ''));
        if ($name === '') agent_fail('routes[' . $i . ']: route is required');
        if (!isset($r['cost']) || !is_numeric($r['cost']) || (float)$r['cost'] < 0) agent_fail('routes[' . $i . ']: cost must be a number ≥ 0');
        if (isset($r['sale']) && $r['sale'] !== null && !is_numeric($r['sale'])) agent_fail('routes[' . $i . ']: sale must be a number');
        $vf = (string)($r['valid_from'] ?? '');
        $vt = isset($r['valid_to']) && $r['valid_to'] !== '' ? (string)$r['valid_to'] : null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vf) || ($vt !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $vt))) {
            agent_fail('routes[' . $i . ']: valid_from / valid_to must be YYYY-MM-DD');
        }
        $rows[] = [mb_substr($name, 0, 200), mb_substr(trim((string)($r['origin'] ?? '')), 0, 100) ?: null,
                   mb_substr(trim((string)($r['destination'] ?? '')), 0, 100) ?: null,
                   mb_substr(trim((string)($r['airline'] ?? '')), 0, 100) ?: null,
                   $vf, $vt, round((float)$r['cost'], 2),
                   isset($r['sale']) && $r['sale'] !== null ? round((float)$r['sale'], 2) : null,
                   mb_substr(trim((string)($r['notes'] ?? '')), 0, 200) ?: null];
    }
    return $rows;
}

/** Request row by id or fail 404. */
function agent_request(PDO $db, $id): array {
    global $agentReqId;
    $id = (int)$id;
    if ($id <= 0) agent_fail('request_id is required');
    $agentReqId = $id;
    $st = $db->prepare("SELECT r.*, a.name AS agent_name FROM requests r LEFT JOIN agents a ON a.id = r.agent_id WHERE r.id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) agent_fail('Request ' . $id . ' not found', 404);
    return $r;
}

/** Compact public shape of a request row. */
function agent_request_out(array $r): array {
    return [
        'id'            => (int)$r['id'],
        'customer_name' => $r['customer_name'],
        'agent'         => $r['agent_name'] ?? null,
        'agency'        => function_exists('folder_agency') ? folder_agency($r) : null,
        'status'        => $r['status'],
        'payment_status'=> $r['payment_status'] ?? null,
        'folder'        => $r['practice_code'],
        'group_folder'  => $r['group_folder'] ?? null,
        'dropbox_path'  => req_folder_path($r),
        'date_received' => $r['date_received'] ?? null,
        'start_date'    => $r['start_date'] ?? null,
        'destination'   => $r['destination'] ?? null,
        'period'        => $r['period'] ?? null,
        'pax'           => isset($r['pax']) ? $r['pax'] : null,
        'value_usd'     => $r['value_usd'] ?? null,
    ];
}

/**
 * Normalise a date for Confirm Safari: ISO "2027-01-19" → "19JAN" (or "19JAN2027"
 * with $withYear); DDMMM / DDMMMYYYY / NA / '' pass through upper-cased.
 */
function agent_cs_date($v, bool $withYear): string {
    $v = strtoupper(trim((string)$v));
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
        $ts = mktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
        return strtoupper(date($withYear ? 'dMY' : 'dM', $ts));
    }
    return $v;
}

/** Destination label from a label, suffix ("ZNZ", "-ZNZ") or '' (= Tanzania safari). */
function agent_dest_label($v): ?string {
    $v = trim((string)$v);
    $dests = bs_confirm_destinations();
    if ($v === '') return (string)array_keys($dests)[0];
    if (isset($dests[$v])) return $v;
    $suf = '-' . ltrim(strtoupper($v), '-');
    foreach ($dests as $label => $pair) {
        if (strcasecmp($label, $v) === 0 || ($pair[0] !== '' && $pair[0] === $suf)) return $label;
    }
    return null;
}

/** Confirm plan input from the API body. */
function agent_confirm_input(array $in): array {
    $dest = agent_dest_label($in['dest'] ?? '');
    if ($dest === null) {
        agent_fail('Unknown dest — use one of the labels in list_standard_programs.destinations', 400,
                   ['destinations' => array_keys(bs_confirm_destinations())]);
    }
    return [
        'start'   => agent_cs_date($in['start'] ?? '', false),
        'mid'     => agent_cs_date(isset($in['mid'])  && $in['mid']  !== '' ? $in['mid']  : 'NA', false),
        'mid2'    => agent_cs_date(isset($in['mid2']) && $in['mid2'] !== '' ? $in['mid2'] : 'NA', false),
        'end'     => agent_cs_date($in['end'] ?? '', true),
        'dest'    => $dest,
        'grp'     => $in['grp'] ?? 'NONE',
        'grpcode' => $in['grp_code'] ?? '',
        'grpmain' => $in['grp_main'] ?? '',
    ];
}

/** The Hub user who owns a request's agent (signature / Reply-To), or 0. */
function agent_user_for_agent(PDO $db, $agentId): array {
    if (!$agentId) return ['id' => 0, 'full_name' => ''];
    $st = $db->prepare("SELECT id, full_name FROM users WHERE agent_id = ? ORDER BY is_active DESC, id LIMIT 1");
    $st->execute([(int)$agentId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    return $u ? ['id' => (int)$u['id'], 'full_name' => (string)$u['full_name']] : ['id' => 0, 'full_name' => ''];
}

// ── Invoices (logic in modules/invoices/includes/invoice_service.php) ────────
/** Loaded only by the invoice actions. */
function agent_invoice_lib(): void {
    require_once __DIR__ . '/../invoices/includes/invoice_service.php';
}

/** Invoice by invoice_id or invoice_number, or fail 404. Audits its request_id. */
function agent_invoice(PDO $db, array $in): array {
    global $agentReqId;
    agent_invoice_lib();
    $id  = (int)($in['invoice_id'] ?? 0);
    $num = trim((string)($in['invoice_number'] ?? ''));
    if (!$id && $num !== '') {
        $st = $db->prepare("SELECT id FROM invoices WHERE invoice_number = ?");
        $st->execute([$num]);
        $id = (int)($st->fetchColumn() ?: 0);
        if (!$id) agent_fail('Invoice ' . $num . ' not found', 404);
    }
    if ($id <= 0) agent_fail('invoice_id (or invoice_number) is required');
    $inv = inv_get($db, $id);
    if (!$inv) agent_fail('Invoice ' . $id . ' not found', 404);
    if (!empty($inv['request_id'])) $agentReqId = (int)$inv['request_id'];
    return $inv;
}

/** Compact public shape of an invoice row. */
function agent_invoice_out(array $i): array {
    return [
        'id'         => (int)$i['id'],
        'number'     => $i['invoice_number'],
        'issuer'     => $i['issuer'],
        'bill_to'    => $i['bill_to_name'],
        'currency'   => $i['currency'],
        'issue_date' => $i['issue_date'],
        'due_date'   => $i['due_date'] ?? null,
        'total'      => round((float)$i['total'], 2),
        'paid'       => round((float)$i['amount_paid'], 2),
        'balance'    => round((float)$i['balance_due'], 2),
        'status'     => $i['status'],
        'request_id' => !empty($i['request_id']) ? (int)$i['request_id'] : null,
        'follow_up'  => !empty($i['follow_up']),
    ];
}

/** The invoice's Dropbox folder as data, or null when no request / folder is linked. */
function agent_invoice_folder(PDO $db, int $invId): ?array {
    $r = inv_linked_request($db, $invId);
    if (!$r) return null;
    return [
        'request_id'     => (int)$r['id'],
        'customer_name'  => $r['customer_name'],
        'folder'         => $r['practice_code'],
        'current_tag'    => $r['practice_code'] ? folder_current_tag($r['practice_code']) : '',
        'payment_status' => $r['payment_status'] ?? null,
        'group_folder'   => $r['group_folder'] ?? null,
        'dropbox_path'   => req_folder_path($r),
    ];
}

/** A request's Dropbox folder (searched by folder name if it moved), or fail 404. */
function agent_request_dropbox_dir(string $token, array $r): string {
    $dir = req_folder_path($r);
    if ($dir === '' || !dropbox_path_exists($token, $dir)) {
        $dir = (string)dropbox_find_folder($token, $r['practice_code']);
        if ($dir === '') agent_fail('Folder ' . $r['practice_code'] . ' not found in Dropbox — pass folder_path', 404);
    }
    return $dir;
}

// ── Mailbox info@ (logic in includes/mailbox_service.php) ───────────────────
/** Loaded only by the mail actions; fails 503 when the mailbox isn't configured. */
function agent_mail_lib(): void {
    require_once __DIR__ . '/../../includes/mailbox_service.php';
    if (!mbx_configured()) {
        agent_fail(function_exists('imap_open')
            ? 'Mailbox not configured — define MAILBOX_USER / MAILBOX_PASS in includes/config.php (see docs/AGENT_API.md)'
            : 'PHP imap extension not available on this server', 503);
    }
}

/** UIDs from "uid" or "uids" (number, list or "1,2,3"), or fail. */
function agent_mail_uids(array $in): array {
    $v = $in['uids'] ?? ($in['uid'] ?? []);
    $list = is_array($v) ? $v : preg_split('/[\s,]+/', (string)$v);
    $uids = array_values(array_unique(array_filter(array_map('intval', $list), function ($u) { return $u > 0; })));
    if (!$uids) agent_fail('uid (or uids) is required');
    if (count($uids) > 100) agent_fail('At most 100 uids per call');
    return $uids;
}

// ── Memo Board (logic in modules/memo/memo_lib.php) ─────────────────────────
/**
 * The Hub user whose Memo Board Claude writes to: AGENT_MEMO_USER (username)
 * in includes/config.php, else the active user sharing the API user's agent.
 */
function agent_memo_owner(PDO $db): array {
    global $agentUser;
    require_once __DIR__ . '/../memo/memo_lib.php';
    memo_schema($db);
    if (defined('AGENT_MEMO_USER')) {
        $st = $db->prepare("SELECT id, username, full_name FROM users WHERE username = ? AND is_active = 1");
        $st->execute([(string)AGENT_MEMO_USER]);
    } else {
        $st = $db->prepare("SELECT id, username, full_name FROM users
                            WHERE agent_id = ? AND id <> ? AND is_active = 1 ORDER BY id LIMIT 1");
        $st->execute([(int)($agentUser['agent_id'] ?? 0), (int)$agentUser['id']]);
    }
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) agent_fail('Memo owner not found — define AGENT_MEMO_USER (Hub username) in includes/config.php', 500);
    return ['id' => (int)$u['id'], 'username' => $u['username'], 'full_name' => $u['full_name']];
}

/** One of the owner's memos by id or ext_key, or null. */
function agent_memo_find(PDO $db, int $ownerId, array $in): ?array {
    if ((int)($in['id'] ?? 0) > 0) {
        $st = $db->prepare("SELECT * FROM memos WHERE id = ? AND user_id = ? AND deleted_at IS NULL");
        $st->execute([(int)$in['id'], $ownerId]);
    } elseif (trim((string)($in['ext_key'] ?? '')) !== '') {
        $st = $db->prepare("SELECT * FROM memos WHERE ext_key = ? AND user_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
        $st->execute([trim($in['ext_key']), $ownerId]);
    } else {
        return null;
    }
    $m = $st->fetch(PDO::FETCH_ASSOC);
    return $m ?: null;
}

/** Public shape of memo rows (with links and pending next steps). */
function agent_memo_rows(PDO $db, string $where, array $args): array {
    $st = $db->prepare("SELECT m.*, " . memo_link_columns() . " FROM memos m" . memo_link_joins() . " WHERE " . $where
                     . " ORDER BY (m.due_date IS NULL), m.due_date, m.id LIMIT 200");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $m) {
        $next = $db->prepare("SELECT id, title, next_offset_days FROM memos WHERE parent_id = ? AND status = 'pending' AND deleted_at IS NULL ORDER BY id");
        $next->execute([(int)$m['id']]);
        $out[] = [
            'id' => (int)$m['id'], 'title' => $m['title'], 'status' => $m['status'], 'waiting_on' => $m['waiting_on'],
            'due_date' => $m['due_date'], 'reminder_at' => $m['reminder_at'], 'priority' => $m['priority'],
            'body' => trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>'], "\n", (string)$m['body'])), ENT_QUOTES, 'UTF-8')),
            'request_id' => $m['request_id'] ? (int)$m['request_id'] : null, 'folder' => $m['req_folder'],
            'invoice_id' => $m['invoice_id'] ? (int)$m['invoice_id'] : null, 'invoice_number' => $m['inv_number'],
            'invoice_balance' => $m['inv_balance'] !== null ? (float)$m['inv_balance'] : null,
            'afrasia' => $m['inv_issuer'] === 'Savannah Holidays Ltd',
            'auto_close_on_payment' => $m['auto_close'] === 'payment',
            'parent_id' => $m['parent_id'] ? (int)$m['parent_id'] : null,
            'next_steps' => array_map(function ($n) { return ['id' => (int)$n['id'], 'title' => $n['title'], 'days_after' => (int)$n['next_offset_days']]; },
                                      $next->fetchAll(PDO::FETCH_ASSOC)),
            'source' => $m['source'], 'ext_key' => $m['ext_key'], 'updated_at' => $m['updated_at'],
        ];
    }
    return $out;
}

/** 'YYYY-MM-DD' or fail. */
function agent_iso_date($v, string $field): string {
    $v = trim((string)$v);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v)) agent_fail($field . ' must be YYYY-MM-DD');
    return $v;
}

// ═════════════════════════════════════════════════════════════════════════════
try {
    switch ($agentAction) {

    // ── find_requests ────────────────────────────────────────────────────────
    case 'find_requests': {
        $where = []; $args = [];
        $q = trim((string)($in['q'] ?? ''));
        if ($q !== '') {
            $where[] = "(r.customer_name LIKE ? OR r.practice_code LIKE ? OR r.group_folder LIKE ? OR r.email LIKE ?)";
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like, $like);
        }
        if (trim((string)($in['status'] ?? '')) !== '') { $where[] = "r.status = ?"; $args[] = trim($in['status']); }
        $agent = trim((string)($in['agent'] ?? ''));
        if ($agent !== '') {
            if (ctype_digit($agent)) { $where[] = "r.agent_id = ?"; $args[] = (int)$agent; }
            else                     { $where[] = "a.name LIKE ?";  $args[] = '%' . $agent . '%'; }
        }
        if (preg_match('/^\d{4}$/', (string)($in['year'] ?? ''))) {
            $where[] = "YEAR(r.date_received) = ?"; $args[] = (int)$in['year'];
        }
        if (!$where) agent_fail('Give at least one filter: q, status, agent or year');
        $limit = max(1, min(100, (int)($in['limit'] ?? 50)));
        $st = $db->prepare("SELECT r.*, a.name AS agent_name FROM requests r LEFT JOIN agents a ON a.id = r.agent_id
                            WHERE " . implode(' AND ', $where) . " ORDER BY r.id DESC LIMIT " . $limit);
        $st->execute($args);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[] = agent_request_out($r);
        agent_out(['ok' => true, 'count' => count($rows), 'requests' => $rows]);
    }

    // ── list_agencies ────────────────────────────────────────────────────────
    case 'list_agencies': {
        $q = trim((string)($in['q'] ?? ''));
        if ($q === '') agent_fail('q is required');
        $like = '%' . $q . '%';
        $st = $db->prepare("SELECT id, nome, short_name, type FROM agencies
                            WHERE nome LIKE ? OR short_name LIKE ? ORDER BY nome LIMIT 50");
        $st->execute([$like, $like]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $out[] = ['id' => (int)$a['id'], 'name' => $a['nome'], 'short_name' => $a['short_name'], 'type' => $a['type']];
        }
        agent_out(['ok' => true, 'agencies' => $out]);
    }

    // ── create_request ───────────────────────────────────────────────────────
    case 'create_request': {
        agent_require_method('POST');
        $channel = strtolower(trim((string)($in['channel'] ?? 'agency')));
        if (!in_array($channel, ['agency', 'direct', 'sb', 'other'], true)) agent_fail('channel must be agency, direct, sb or other');

        // Agency: by id or by short name / name (must resolve to exactly one).
        $agencyId = (int)($in['agency_id'] ?? 0);
        if ($channel === 'agency' && !$agencyId && trim((string)($in['agency_short'] ?? '')) !== '') {
            $s = trim($in['agency_short']);
            $st = $db->prepare("SELECT id FROM agencies WHERE short_name = ? OR nome = ?");
            $st->execute([$s, $s]);
            $ids = $st->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) !== 1) agent_fail(count($ids) ? 'agency_short matches several agencies — pass agency_id' : 'Agency "' . $s . '" not found — use list_agencies');
            $agencyId = (int)$ids[0];
        }
        if ($channel === 'agency' && $agencyId) {
            $st = $db->prepare("SELECT COUNT(*) FROM agencies WHERE id = ?");
            $st->execute([$agencyId]);
            if (!(int)$st->fetchColumn()) agent_fail('agency_id ' . $agencyId . ' not found');
        }

        // Agent: id, name, or default = the API user's own agent.
        $agentId = (int)($in['agent_id'] ?? 0);
        if (!$agentId && trim((string)($in['agent'] ?? '')) !== '') {
            $st = $db->prepare("SELECT id FROM agents WHERE active = 1 AND name = ?");
            $st->execute([trim($in['agent'])]);
            $agentId = (int)($st->fetchColumn() ?: 0);
            if (!$agentId) agent_fail('Agent "' . trim($in['agent']) . '" not found');
        }
        if (!$agentId) $agentId = (int)($agentUser['agent_id'] ?? 0);

        $status = trim((string)($in['status'] ?? 'Inquiry'));
        if (defined('STATUSES') && !array_key_exists($status, STATUSES)) agent_fail('Invalid status "' . $status . '"');

        $v = [
            'date_received'   => $in['date_received']   ?? date('Y-m-d'),
            'customer_name'   => $in['customer_name']   ?? '',
            'email'           => $in['email']           ?? '',
            'whatsapp'        => $in['whatsapp']        ?? '',
            'source'          => $in['source']          ?? 'Email',
            'channel'         => $channel,
            'agency_id'       => $channel === 'agency' ? (string)$agencyId : '',
            'agent_id'        => (string)$agentId,
            'destination'     => $in['destination']     ?? '',
            'period'          => $in['period']          ?? '',
            'pax'             => isset($in['pax']) ? (string)$in['pax'] : '',
            'status'          => $status,
            'value_usd'       => isset($in['value_usd']) ? (string)$in['value_usd'] : '',
            'commission_pct'  => isset($in['commission_pct']) ? (string)$in['commission_pct'] : '',
            'commission_usd'  => '',
            'date_paid'       => $in['date_paid']       ?? '',
            'initial_request' => $in['initial_request'] ?? '',
            'notes'           => $in['notes']           ?? '',
        ];
        $res = bs_create_request($db, $v, [
            'dup_override'    => !empty($in['dup_override']),
            'notify_agent'    => !empty($in['notify_agent']),    // default: no email to the agent
            'creator_user_id' => (int)$agentUser['id'],
            'dry_run'         => empty($in['confirm']),          // checks + folder name only
        ]);
        if ($res['ok'] && !empty($res['dry_run'])) {
            agent_out(['ok' => true, 'dry_run' => true, 'folder_name' => $res['folder_name'], 'dropbox_path' => $res['dropbox_path'],
                       'values' => $res['values'], 'message' => 'Dry run — no folder, no request. Resend with "confirm": true to create.']);
        }
        if (!$res['ok']) {
            $code = $res['error_code'] === 'duplicate' || $res['error_code'] === 'folder_exists' ? 409 : 400;
            if ($res['error_code'] === 'dropbox') $code = 502;
            agent_fail(implode(' ', $res['errors']), $code, [
                'error_code'     => $res['error_code'],
                'folder_name'    => $res['folder_name'],
                'dup_candidates' => $res['dup_candidates'],
                'hint'           => $res['error_code'] === 'duplicate' ? 'Check with find_requests; resend with "dup_override": true if it is really new.' : null,
            ]);
        }
        $agentReqId = $res['request_id'];
        agent_out(['ok' => true, 'request_id' => $res['request_id'], 'folder_name' => $res['folder_name'],
                   'dropbox_path' => $res['dropbox_path'], 'notify' => $res['notify']]);
    }

    // ── update_request ───────────────────────────────────────────────────────
    // Plain data fields only. Status / folder changes go through confirm_booking
    // (or the BackOffice), which keep the Dropbox folder and the DB in sync.
    case 'update_request': {
        agent_require_method('POST');
        $r = agent_request($db, $in['request_id'] ?? 0);
        $allowed = ['customer_name','email','whatsapp','source','destination','period','pax',
                    'value_usd','commission_pct','date_paid','initial_request','notes'];
        $fields = isset($in['fields']) && is_array($in['fields']) ? $in['fields'] : $in;
        $set = []; $args = []; $changed = [];
        foreach ($allowed as $f) {
            if (!array_key_exists($f, $fields)) continue;
            $val = $fields[$f];
            $val = ($val === null || trim((string)$val) === '') ? null : trim((string)$val);
            $set[] = $f . ' = ?'; $args[] = $val; $changed[] = $f;
        }
        $ignored = array_values(array_diff(array_keys($fields), array_merge($allowed, ['request_id', 'fields'])));
        if (!$set) agent_fail('No updatable fields given', 400, ['updatable' => $allowed, 'ignored' => $ignored]);
        // Keep commission_usd consistent with value × pct.
        $val = in_array('value_usd', $changed, true) ? $args[array_search('value_usd', $changed)] : $r['value_usd'];
        $pct = in_array('commission_pct', $changed, true) ? $args[array_search('commission_pct', $changed)] : $r['commission_pct'];
        if ($val !== null && $pct !== null && (in_array('value_usd', $changed, true) || in_array('commission_pct', $changed, true))) {
            $set[] = 'commission_usd = ?'; $args[] = round((float)$val * (float)$pct / 100, 2);
        }
        $args[] = (int)$r['id'];
        $db->prepare("UPDATE requests SET " . implode(', ', $set) . " WHERE id = ?")->execute($args);
        $r = agent_request($db, $r['id']);
        agent_out(['ok' => true, 'updated' => $changed, 'ignored' => $ignored, 'request' => agent_request_out($r)]);
    }

    // ── list_standard_programs ───────────────────────────────────────────────
    case 'list_standard_programs': {
        $groups = [];
        foreach (bs_std_programs() as $g => $progs) {
            $list = [];
            foreach ($progs as $label => $files) {
                $dst = [];
                foreach ($files as $f) $dst[] = $f['dst'];
                $list[] = ['program' => $label, 'files' => $dst];
            }
            $groups[] = ['group' => $g, 'programs' => $list];
        }
        agent_out(['ok' => true, 'groups' => $groups, 'destinations' => array_keys(bs_confirm_destinations())]);
    }

    // ── copy_program ─────────────────────────────────────────────────────────
    case 'copy_program': {
        agent_require_method('POST');
        $r = agent_request($db, $in['request_id'] ?? 0);
        $programs = isset($in['programs']) && is_array($in['programs']) ? $in['programs']
                  : (isset($in['program']) ? [(string)$in['program']] : []);
        $res = bs_copy_programs($db, (int)$r['id'], (string)($in['prognum'] ?? ''), $programs);
        if (!$res['ok']) agent_fail($res['msg']);
        if ($res['unknown'] || $res['missing']) {
            agent_out(array_merge(['ok' => false, 'error' => 'Some programs were not copied: ' . $res['summary']], $res), 422);
        }
        agent_out($res);
    }

    // ── confirm_preview / confirm_booking ────────────────────────────────────
    case 'confirm_preview':
    case 'confirm_booking': {
        agent_require_method('POST');
        $r    = agent_request($db, $in['request_id'] ?? 0);
        $plan = bs_confirm_plan($db, (int)$r['id'], agent_confirm_input($in));
        if (!$plan['found']) agent_fail($plan['error'], 400);
        if ($plan['errs']) agent_fail('Invalid confirm data', 400, ['errors' => $plan['errs']]);

        $checks = bs_confirm_checks($plan);
        // Checks that block an API confirm unless "force": true.
        $blocking = [];
        foreach ($checks as $c) {
            if (in_array(strtolower((string)$c['level']), ['error', 'fail', 'red'], true)) $blocking[] = $c['msg'];
        }
        if ($plan['block']) $blocking[] = $plan['block_msg'];
        $preview = [
            'request_id'   => (int)$r['id'],
            'current'      => $plan['old_folder'],
            'new_name'     => bs_confirm_display_name($plan),
            'grp'          => $plan['grp_action'],
            'grp_code'     => $plan['grp_code'],
            'existing_grps'=> $plan['grps'],
            'destination'  => $plan['dest_value'],
            'start_date'   => $plan['pd']['start_date'],
            'end_date'     => $plan['pd']['end_date'],
            'checks'       => $checks,
            'blocking'     => $blocking,
        ];
        if ($agentAction === 'confirm_preview') agent_out(array_merge(['ok' => true], $preview));

        // confirm_booking
        if (empty($in['confirm'])) {
            agent_out(array_merge(['ok' => true, 'dry_run' => true,
                'message' => 'Dry run — nothing moved. Resend with "confirm": true to confirm.'], $preview));
        }
        $force = !empty($in['force']);
        if ($blocking && !$force) {
            agent_fail('Confirmation blocked — fix the issues or resend with "force": true', 409, $preview);
        }
        $res = bs_confirm_commit($db, $plan, $force);
        if (!$res['ok']) agent_fail($res['msg'], 409, $preview);
        agent_out(['ok' => true, 'dry_run' => false, 'message' => $res['msg'], 'folder' => $res['folder'],
                   'group_folder' => $res['group_folder'], 'dropbox_path' => $res['dropbox_path'],
                   'status' => 'Booked', 'checks' => $checks]);
    }

    // ── send_booking_email ───────────────────────────────────────────────────
    case 'send_booking_email': {
        agent_require_method('POST');
        $r = agent_request($db, $in['request_id'] ?? 0);
        if (($r['status'] ?? '') !== 'Booked') agent_fail('Request is not Booked — confirm it first', 409);

        // Which template variant: from the confirm snapshot (ADD → group variant).
        $pre  = json_decode((string)($r['pre_confirm_json'] ?? ''), true);
        $mact = strtoupper((string)(is_array($pre) ? ($pre['action'] ?? 'NONE') : 'NONE'));
        $folder  = trim($r['practice_code'] ?? '');
        $grpMain = '';
        if     ($mact === 'ADD')    { $grpMain = trim($r['group_folder'] ?? ''); }
        elseif ($mact === 'CREATE') { $folder  = trim($r['group_folder'] ?? '') ?: $folder; }

        // Signed as the request's agent (their name, signature and Reply-To).
        $sender = agent_user_for_agent($db, $r['agent_id'] ?? null);
        $senderName = trim((string)($in['sender_name'] ?? '')) ?: ($sender['full_name'] ?: (string)($r['agent_name'] ?? ''));

        $mail = bo_booking_email($folder, booking_cc_agent_email($db, $r['agent_id'] ?? null), $grpMain, $senderName);

        $to = isset($in['to']) ? bs_parse_emails($in['to']) : bs_parse_emails($mail['to']);
        $cc = isset($in['cc']) ? bs_parse_emails($in['cc']) : bs_parse_emails($mail['cc']);
        if (!empty($in['cc_remove'])) {
            $rm = array_map('strtolower', bs_parse_emails($in['cc_remove']));
            $cc = array_values(array_filter($cc, function ($a) use ($rm) { return !in_array(strtolower($a), $rm, true); }));
        }
        $subject = trim((string)($in['subject'] ?? '')) ?: $mail['subject'];
        $body    = $mail['body'];
        $extra   = trim((string)($in['body_extra'] ?? ''));
        if ($extra !== '') {
            $pos  = strrpos($body, "\nThanks\n");
            $body = $pos !== false ? substr($body, 0, $pos) . "\n" . $extra . "\n" . substr($body, $pos) : $body . "\n\n" . $extra;
        }
        $email = ['to' => $to, 'cc' => $cc, 'subject' => $subject, 'body' => $body, 'sender_user_id' => $sender['id']];

        if (empty($in['confirm'])) {
            agent_out(['ok' => true, 'dry_run' => true,
                       'message' => 'Dry run — not sent. Resend with "confirm": true to send.', 'email' => $email]);
        }
        $res = bs_send_mail($to, $cc, $subject, $body, $sender['id']);
        if (!$res['success']) agent_fail($res['message'], 502, ['email' => $email]);
        agent_out(['ok' => true, 'dry_run' => false, 'message' => 'Sent', 'email' => $email]);
    }

    // ── get_rates ────────────────────────────────────────────────────────────
    // Program prices from the Calc template (per pax sheet) + flight routes and
    // activities/transfers (sale + cost) valid on `date` (default today).
    case 'get_rates': {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['date'] ?? '')) ? $in['date'] : date('Y-m-d');
        $out  = ['ok' => true, 'date' => $date];
        $prog = trim((string)($in['program'] ?? ''));
        if ($prog !== '') {
            require_once __DIR__ . '/dropbox_helper.php';
            try { $out['program'] = array_merge(['program' => $prog], calc_program_prices(dropbox_get_access_token(), $prog)); }
            catch (InvalidArgumentException $e) { agent_fail($e->getMessage(), 400); }
        }
        $q = trim((string)($in['route'] ?? $in['q'] ?? ''));
        $out['flights']    = calc_flight_rates($db, $q, $date);
        $out['activities'] = calc_activity_rates($db, trim((string)($in['activity'] ?? $in['q'] ?? '')), $date);
        agent_out($out);
    }

    // ── update_rate ──────────────────────────────────────────────────────────
    // Change one rate row: flight (flight_routes), activity (activity_rates) or jeep
    // (jeep_rates). Fields: cost (what we pay), sale (price to agency), valid_from,
    // valid_to, active, notes. Dry-run (before/after) unless "confirm": true.
    case 'update_rate': {
        agent_require_method('POST');
        calc_rates_schema($db);
        $types = [
            'flight'   => ['table' => 'flight_routes',  'cols' => ['cost' => 'rate_pax', 'sale' => 'sale_pax', 'valid_from' => 'valid_from', 'valid_to' => 'valid_to', 'active' => 'active', 'notes' => 'notes'], 'name' => 'route_name'],
            'activity' => ['table' => 'activity_rates', 'cols' => ['cost' => 'rate',     'sale' => 'sale',     'valid_from' => 'valid_from', 'valid_to' => 'valid_to', 'active' => 'active', 'notes' => 'notes'], 'name' => 'name'],
            'jeep'     => ['table' => 'jeep_rates',     'cols' => ['cost' => 'rate',                           'valid_from' => 'valid_from', 'valid_to' => 'valid_to',                     'notes' => 'notes'], 'name' => 'type'],
        ];
        $type = (string)($in['type'] ?? '');
        if (!isset($types[$type])) agent_fail('type must be flight, activity or jeep');
        $cfg = $types[$type];
        $rid = (int)($in['id'] ?? 0);
        $st = $db->prepare('SELECT * FROM ' . $cfg['table'] . ' WHERE id = ?');
        $st->execute([$rid]);
        $before = $st->fetch(PDO::FETCH_ASSOC);
        if (!$before) agent_fail(ucfirst($type) . ' rate ' . $rid . ' not found (ids: get_rates)', 404);

        $set = []; $args = []; $after = $before;
        foreach ($cfg['cols'] as $field => $col) {
            if (!array_key_exists($field, $in)) continue;
            $v = $in[$field];
            if ($field === 'cost' || $field === 'sale') {
                if ($v === null || $v === '') { if ($field === 'cost') agent_fail('cost cannot be empty'); $v = null; }
                elseif (!is_numeric($v) || (float)$v < 0) agent_fail($field . ' must be a number ≥ 0');
                else $v = round((float)$v, 2);
            } elseif ($field === 'valid_from' || $field === 'valid_to') {
                if ($v === null || $v === '') { if ($field === 'valid_from') agent_fail('valid_from cannot be empty'); $v = null; }
                elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) agent_fail($field . ' must be YYYY-MM-DD');
            } elseif ($field === 'active') {
                $v = !empty($v) ? 1 : 0;
            } else {
                $v = mb_substr(trim((string)$v), 0, 200);
            }
            $set[] = $col . ' = ?'; $args[] = $v; $after[$col] = $v;
        }
        if (!$set) agent_fail('Nothing to change — give cost, sale, valid_from, valid_to, active or notes', 400, ['fields' => array_keys($cfg['cols'])]);

        $summary = function (array $r) use ($cfg) {
            $out = ['id' => (int)$r['id'], 'name' => $r[$cfg['name']] ?? ''];
            foreach ($cfg['cols'] as $field => $col) $out[$field] = $r[$col] ?? null;
            return $out;
        };
        if (empty($in['confirm'])) {
            agent_out(['ok' => true, 'dry_run' => true, 'type' => $type, 'before' => $summary($before), 'after' => $summary($after),
                       'message' => 'Dry run — nothing saved. Resend with "confirm": true to save.']);
        }
        $args[] = $rid;
        $db->prepare('UPDATE ' . $cfg['table'] . ' SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
        $st->execute([$rid]);
        agent_out(['ok' => true, 'dry_run' => false, 'type' => $type, 'before' => $summary($before), 'after' => $summary($st->fetch(PDO::FETCH_ASSOC))]);
    }

    // ── replace_flight_rates ─────────────────────────────────────────────────
    // Replace the whole flight_routes table with a new price list. The old rows are
    // returned (and kept in the audit log) as a backup. Dry-run unless "confirm": true.
    // routes: [{route, origin, destination, airline?, cost, sale?, valid_from, valid_to?, notes?}, …]
    case 'replace_flight_rates': {
        agent_require_method('POST');
        calc_rates_schema($db);
        $routes = isset($in['routes']) && is_array($in['routes']) ? $in['routes'] : [];
        if (!$routes) agent_fail('routes is required');
        $rows = agent_flight_rate_rows($routes);
        $old = $db->query('SELECT * FROM flight_routes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        if (empty($in['confirm'])) {
            agent_out(['ok' => true, 'dry_run' => true, 'delete' => count($old), 'insert' => count($rows),
                       'message' => 'Dry run — nothing changed. Resend with "confirm": true to replace the table.']);
        }
        $db->beginTransaction();
        try {
            $db->exec('DELETE FROM flight_routes');
            $ins = $db->prepare('INSERT INTO flight_routes (route_name, origin, destination, airline, valid_from, valid_to, rate_pax, sale_pax, active, notes)
                                 VALUES (?,?,?,?,?,?,?,?,1,?)');
            foreach ($rows as $row) $ins->execute($row);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        agent_out(['ok' => true, 'dry_run' => false, 'deleted' => count($old), 'inserted' => count($rows),
                   'flights' => calc_flight_rates($db, '', date('Y-m-d')), 'old_rows_backup' => $old]);
    }

    // Add flight rates to the price list (other rows untouched; same route + valid_from = skipped).
    // routes: as replace_flight_rates. Dry-run unless "confirm": true.
    case 'add_flight_rates': {
        agent_require_method('POST');
        calc_rates_schema($db);
        $routes = isset($in['routes']) && is_array($in['routes']) ? $in['routes'] : [];
        if (!$routes) agent_fail('routes is required');
        $rows = agent_flight_rate_rows($routes);
        $chk = $db->prepare('SELECT id FROM flight_routes WHERE route_name = ? AND valid_from = ?');
        $new = []; $skip = [];
        foreach ($rows as $row) {
            $chk->execute([$row[0], $row[4]]);
            if ($id = $chk->fetchColumn()) $skip[] = ['route' => $row[0], 'valid_from' => $row[4], 'existing_id' => (int)$id];
            else $new[] = $row;
        }
        if (empty($in['confirm'])) {
            agent_out(['ok' => true, 'dry_run' => true, 'insert' => count($new), 'skipped' => $skip,
                       'message' => 'Dry run — nothing changed. Resend with "confirm": true.']);
        }
        $ins = $db->prepare('INSERT INTO flight_routes (route_name, origin, destination, airline, valid_from, valid_to, rate_pax, sale_pax, active, notes)
                             VALUES (?,?,?,?,?,?,?,?,1,?)');
        foreach ($new as $row) $ins->execute($row);
        agent_out(['ok' => true, 'dry_run' => false, 'inserted' => count($new), 'skipped' => $skip]);
    }

    // ── fill_calc ────────────────────────────────────────────────────────────
    // Fill the booking's *_Calc.xlsx server-side with the house rules; dry-run
    // (built + verified on a copy) unless "confirm": true.
    case 'fill_calc': {
        agent_require_method('POST');
        $r = agent_request($db, $in['request_id'] ?? 0);
        require_once __DIR__ . '/dropbox_helper.php';
        try {
            $res = calc_fill($db, $in, !empty($in['confirm']));
        } catch (InvalidArgumentException $e) {
            agent_fail($e->getMessage(), 400);
        } catch (RuntimeException $e) {
            agent_fail($e->getMessage(), stripos($e->getMessage(), 'changed in Dropbox') !== false ? 409 : 502);
        }
        if (empty($res['verify']['passed'])) {
            $res['ok'] = false;
            $res['error'] = 'Verification found errors — see verify.checks.';
            agent_out($res, 422);
        }
        agent_out($res);
    }

    // ── read_calc ────────────────────────────────────────────────────────────
    // Read-only: the booking's Calc as data (days, guests, arrival/departure,
    // prices, Dropbox rev). 422 when the Calc is not finalised (several pax
    // sheets, none marked CONF) or is a group calc.
    case 'read_calc': {
        $r = agent_request($db, $in['request_id'] ?? 0);
        require_once __DIR__ . '/dropbox_helper.php';
        try {
            $res = calc_read_request($db, (int)$r['id'], (string)($in['file'] ?? ''), (string)($in['sheet'] ?? ''));
        } catch (InvalidArgumentException $e) {
            agent_fail($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            agent_fail($e->getMessage(), 502);
        }
        agent_out(array_merge(['ok' => true], $res));
    }

    // ── ITI programmes: list / texts to translate / save translations ────────
    case 'iti_programs': {
        $q = trim((string)($in['q'] ?? ''));
        $sql = "SELECT id, program_type, title_it, title_en, display_language, duration_days, status, is_published, public_token
                  FROM iti_programs WHERE status <> 'cancelled'";
        $args = [];
        if ($q !== '') { $sql .= " AND (title_it LIKE ? OR title_en LIKE ? OR ref_number LIKE ?)"; $l = '%' . $q . '%'; array_push($args, $l, $l, $l); }
        if (!empty($in['type'])) { $sql .= " AND program_type = ?"; $args[] = $in['type']; }
        $st = $db->prepare($sql . " ORDER BY updated_at DESC LIMIT 100");
        $st->execute($args);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['id' => (int)$r['id'], 'type' => $r['program_type'], 'title' => $r['title_it'] ?: $r['title_en'],
                       'language' => $r['display_language'], 'days' => (int)$r['duration_days'], 'status' => $r['status'],
                       'published' => (bool)$r['is_published'],
                       'public_url' => $r['is_published'] && $r['public_token']
                           ? 'https://hub.savannahexplorers.com/modules/iti/itinerary.php?token=' . $r['public_token'] : null];
        }
        agent_out(['ok' => true, 'programs' => $rows]);
    }

    case 'iti_texts': {
        $pid = (int)($in['program_id'] ?? 0);
        $to  = (string)($in['lang'] ?? '');
        try { $c = iti_texts_collect($db, $pid, $to, !empty($in['all'])); }
        catch (InvalidArgumentException $e) { agent_fail($e->getMessage(), 400); }
        $items = [];
        foreach ($c['items'] as $k => $it) $items[] = ['key' => $k, 'source' => $it['source'], 'target' => $it['target']];
        agent_out(['ok' => true, 'program_id' => $pid, 'from' => $c['from'], 'to' => $to, 'count' => count($items), 'items' => $items]);
    }

    case 'iti_save_texts': {
        agent_require_method('POST');
        $pid = (int)($in['program_id'] ?? 0);
        $to  = (string)($in['lang'] ?? '');
        $texts = isset($in['texts']) && is_array($in['texts']) ? $in['texts'] : [];
        if (!$texts) agent_fail('texts is required: {"<key>": "<translated text>", …} (keys from iti_texts)');
        try {
            if (empty($in['confirm'])) {
                $c = iti_texts_collect($db, $pid, $to, true);
                $known = array_intersect(array_keys($texts), array_keys($c['targets']));
                agent_out(['ok' => true, 'dry_run' => true, 'would_write' => count($known),
                           'unknown' => array_values(array_diff(array_keys($texts), array_keys($c['targets']))),
                           'message' => 'Dry run — nothing saved. Resend with "confirm": true to save.']);
            }
            $r = iti_texts_save($db, $pid, $to, $texts, !empty($in['overwrite']));
        } catch (InvalidArgumentException $e) { agent_fail($e->getMessage(), 400); }
        agent_out(array_merge(['ok' => true, 'dry_run' => false], $r));
    }

    // ── ITI master data: lodges / destinations, photos, texts ────────────────
    case 'iti_lodges':
        agent_out(['ok' => true, 'lodges' => iti_cs_lodges($db, $in)]);

    case 'iti_lodge': {
        $r = iti_cs_lodge_row($db, (int)($in['lodge_id'] ?? $in['id'] ?? 0));
        if (!$r) agent_fail('Lodge not found', 404);
        agent_out(['ok' => true, 'lodge' => iti_cs_lodge_out($r, true)]);
    }

    case 'iti_destinations':
        agent_out(['ok' => true, 'destinations' => iti_cs_destinations($db, $in)]);

    case 'iti_destination': {
        $r = iti_cs_destination_row($db, (int)($in['destination_id'] ?? $in['id'] ?? 0));
        if (!$r) agent_fail('Destination not found', 404);
        agent_out(['ok' => true, 'destination' => iti_cs_destination_out($r, true)]);
    }

    // Photos from web links (downloaded into the Hub). Dry-run unless "confirm": true.
    case 'iti_lodge_photos':
    case 'iti_destination_photo': {
        agent_require_method('POST');
        $lodge = $agentAction === 'iti_lodge_photos';
        $go = !empty($in['confirm']);
        try {
            $r = iti_cs_set_photos($db, $lodge ? 'lodge' : 'destination', (int)($in[$lodge ? 'lodge_id' : 'destination_id'] ?? 0), $in, $go);
        } catch (InvalidArgumentException $e) { agent_fail($e->getMessage(), 400); }
        if (!$go) agent_out(array_merge(['ok' => true, 'dry_run' => true], $r, ['message' => 'Dry run — nothing downloaded or saved. Resend with "confirm": true.']));
        agent_out(array_merge(['ok' => !$r['errors'] || $r['photos'], 'dry_run' => false], $r), $r['errors'] ? 422 : 200);
    }

    // New lodge (name, destination_id, category, type, website, contacts, descriptions). Dry-run unless "confirm": true.
    case 'iti_create_lodge': {
        agent_require_method('POST');
        $go = !empty($in['confirm']);
        try { $r = iti_cs_create_lodge($db, isset($in['fields']) && is_array($in['fields']) ? $in['fields'] : [], $go); }
        catch (InvalidArgumentException $e) { agent_fail($e->getMessage(), 400); }
        agent_out(array_merge(['ok' => true, 'dry_run' => !$go], $r, $go ? [] : ['message' => 'Dry run — nothing saved. Resend with "confirm": true.']));
    }

    // Texts / contacts / coordinates. Dry-run unless "confirm": true.
    case 'iti_update_lodge':
    case 'iti_update_destination': {
        agent_require_method('POST');
        $lodge = $agentAction === 'iti_update_lodge';
        $fields = isset($in['fields']) && is_array($in['fields']) ? $in['fields'] : [];
        if (!$fields) agent_fail('fields is required: {"<column>": "<value>", …}');
        $go = !empty($in['confirm']);
        try {
            $r = iti_cs_update($db, $lodge ? 'lodge' : 'destination', (int)($in[$lodge ? 'lodge_id' : 'destination_id'] ?? 0), $fields, $go);
        } catch (InvalidArgumentException $e) { agent_fail($e->getMessage(), 400); }
        agent_out(array_merge(['ok' => true, 'dry_run' => !$go], $r,
            $go ? [] : ['message' => 'Dry run — nothing saved. Resend with "confirm": true.']));
    }

    // ── ITI personal programmes: samples, create (proposal), Calc → final, edit, publish ──
    case 'iti_samples':
        agent_iti_lib();
        agent_out(['ok' => true, 'samples' => iti_ps_samples($db, trim((string)($in['q'] ?? '')))]);

    case 'iti_program': {
        agent_iti_lib();
        try { agent_out(['ok' => true, 'program' => iti_ps_program_out($db, (int)($in['program_id'] ?? 0), (string)($in['lang'] ?? ''))]); }
        catch (InvalidArgumentException $e) { agent_fail($e->getMessage(), 404); }
    }

    case 'iti_create_personal':
    case 'iti_update_program':
    case 'iti_update_day':
    case 'iti_publish':
    case 'iti_save_alias': {
        agent_require_method('POST');
        agent_iti_lib();
        $go = !empty($in['confirm']);
        $who = (string)($agentUser['username'] ?? 'claude_agent');
        if (!empty($in['lead_request_id'])) $agentReqId = (int)$in['lead_request_id'];
        try {
            switch ($agentAction) {
                case 'iti_create_personal': $r = iti_ps_create_personal($db, $in, $who, $go); break;
                case 'iti_update_program':  $r = iti_ps_update_program($db, (int)($in['program_id'] ?? 0), (array)($in['fields'] ?? []), $go); break;
                case 'iti_update_day':      $r = iti_ps_update_day($db, (int)($in['program_id'] ?? 0), (int)($in['day'] ?? 0), (array)($in['fields'] ?? []), $go); break;
                case 'iti_publish':         $r = iti_ps_publish($db, (int)($in['program_id'] ?? 0), !array_key_exists('publish', $in) || !empty($in['publish']), $go); break;
                default:                    $r = $go ? iti_ps_save_alias($in, $who) : ['would_save' => ['type' => $in['type'] ?? null, 'text' => $in['text'] ?? null]];
            }
        } catch (InvalidArgumentException $e) { agent_fail($e->getMessage(), 400); }
        agent_out(array_merge(['ok' => true, 'dry_run' => !$go], $r, $go ? [] : ['message' => 'Dry run — nothing saved. Resend with "confirm": true.']));
    }

    // Days of a personal programme: replace all / add / delete (transfers, activities, flights per day).
    case 'iti_set_days':
    case 'iti_add_day':
    case 'iti_delete_day':
    case 'iti_update_inclusions':
    case 'iti_save_as_sample': {
        agent_require_method('POST');
        agent_iti_lib();
        $go = !empty($in['confirm']);
        $pid = (int)($in['program_id'] ?? 0);
        try {
            switch ($agentAction) {
                case 'iti_set_days':          $r = iti_pb_set_days($db, $pid, (array)($in['days'] ?? []), $go); break;
                case 'iti_add_day':           $r = iti_pb_add_day($db, $pid, (int)($in['after_day'] ?? -1), (array)($in['fields'] ?? []), $go); break;
                case 'iti_delete_day':        $r = iti_pb_delete_day($db, $pid, (int)($in['day'] ?? 0), $go); break;
                case 'iti_update_inclusions': $r = iti_pb_update_inclusions($db, $pid, $in, $go); break;
                default:                      $r = iti_pb_save_as_sample($db, $pid, $in, (string)($agentUser['username'] ?? 'claude_agent'), $go);
            }
        } catch (InvalidArgumentException $e) { agent_fail($e->getMessage(), 400); }
        if ($go && $agentAction !== 'iti_save_as_sample') $r['program'] = iti_ps_program_out($db, $pid);
        agent_out(array_merge(['ok' => true, 'dry_run' => !$go], $r, $go ? [] : ['message' => 'Dry run — nothing saved. Resend with "confirm": true.']));
    }

    // Master data for the days: lists (GET) and new rows (POST, dry-run unless confirm).
    case 'iti_inclusions':
        agent_iti_lib();
        agent_out(['ok' => true] + iti_pb_standard_inclusions($db));
    case 'iti_flight_routes':
        agent_iti_lib();
        agent_out(['ok' => true, 'flight_routes' => iti_pb_flight_routes($db, $in)]);
    case 'iti_activities':
        agent_iti_lib();
        agent_out(['ok' => true, 'activities' => iti_pb_activities($db, $in)]);
    case 'iti_transfer_routes':
        agent_iti_lib();
        agent_out(['ok' => true, 'transfer_routes' => iti_pb_transfer_routes($db, $in)]);
    case 'iti_create_flight_route':
    case 'iti_create_activity':
    case 'iti_create_transfer_route': {
        agent_require_method('POST');
        agent_iti_lib();
        $go = !empty($in['confirm']);
        $f = isset($in['fields']) && is_array($in['fields']) ? $in['fields'] : [];
        try {
            if ($agentAction === 'iti_create_flight_route')  $r = iti_pb_create_flight_route($db, $f, $go);
            elseif ($agentAction === 'iti_create_activity')  $r = iti_pb_create_activity($db, $f, $go);
            else                                             $r = iti_pb_create_transfer_route($db, $f, $go);
        } catch (InvalidArgumentException $e) { agent_fail($e->getMessage(), 400); }
        agent_out(array_merge(['ok' => true, 'dry_run' => !$go], $r, $go ? [] : ['message' => 'Dry run — nothing saved. Resend with "confirm": true.']));
    }

    // Calc of a Hub request → final programme. GET: the plan; POST + confirm: generate.
    case 'iti_calc_plan':
    case 'iti_final_from_calc': {
        agent_iti_lib();
        $r = agent_request($db, $in['request_id'] ?? 0);
        try {
            $plan = iti_ps_calc_plan($db, (int)$r['id'], $in);
            $public = array_filter($plan, function ($k) { return $k[0] !== '_'; }, ARRAY_FILTER_USE_KEY);
            if ($agentAction === 'iti_calc_plan' || empty($in['confirm'])) {
                agent_out(array_merge(['ok' => true, 'dry_run' => true], $public,
                    ['message' => $plan['blocking'] ? 'Map the unmapped texts with iti_save_alias, then generate.' : 'Ready — POST iti_final_from_calc with "confirm": true to generate.']));
            }
            agent_require_method('POST');
            $res = iti_ps_calc_generate($db, (int)$r['id'], $plan, ['id' => $agentUser['id'] ?? null, 'username' => $agentUser['username'] ?? 'claude_agent']);
        } catch (InvalidArgumentException $e) { agent_fail($e->getMessage(), 400); }
        catch (RuntimeException $e) { agent_fail($e->getMessage(), 409); }
        agent_out(array_merge(['ok' => true, 'dry_run' => false], $res));
    }

    // ── rollback_booking ─────────────────────────────────────────────────────
    // Undo a Hub confirmation (same as BackOffice "Rollback…"): folder back to its
    // pre-confirm location, status restored. Dry-run unless "confirm": true.
    case 'rollback_booking': {
        agent_require_method('POST');
        $r   = agent_request($db, $in['request_id'] ?? 0);
        $go  = !empty($in['confirm']);
        $res = bs_rollback($db, (int)$r['id'], $go);
        $out = ['ok' => $res['ok'], 'dry_run' => !$go, 'message' => $res['msg']];
        foreach (['action', 'from', 'to', 'restore'] as $k) { if (isset($res[$k])) $out[$k] = $res[$k]; }
        if (!$res['ok']) { $out['error'] = $res['msg']; agent_out($out, 409); }
        if (!$go) $out['message'] .= ' Dry run — nothing moved. Resend with "confirm": true to roll back.';
        agent_out($out);
    }

    // ── find_invoices ────────────────────────────────────────────────────────
    case 'find_invoices': {
        agent_invoice_lib();
        $where = []; $args = [];
        $q = trim((string)($in['q'] ?? ''));
        if ($q !== '') {
            $where[] = "(i.invoice_number LIKE ? OR i.bill_to_name LIKE ? OR r.customer_name LIKE ? OR r.practice_code LIKE ?)";
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like, $like);
        }
        if ((int)($in['request_id'] ?? 0) > 0) { $where[] = "i.request_id = ?"; $args[] = (int)$in['request_id']; }
        $status = trim((string)($in['status'] ?? ''));
        if ($status !== '') {
            if (!array_key_exists($status, INV_STATUSES)) agent_fail('status must be one of: ' . implode(', ', array_keys(INV_STATUSES)));
            $where[] = "i.status = ?"; $args[] = $status;
        }
        if (!empty($in['unpaid'])) $where[] = "i.status <> 'Cancelled' AND i.balance_due > 0.005";
        if (!$where) agent_fail('Give at least one filter: q (number / customer / folder), request_id, status or unpaid');
        $limit = max(1, min(100, (int)($in['limit'] ?? 50)));
        $st = $db->prepare("SELECT i.*, r.practice_code FROM invoices i LEFT JOIN requests r ON r.id = i.request_id
                            WHERE " . implode(' AND ', $where) . " ORDER BY i.issue_date DESC, i.id DESC LIMIT " . $limit);
        $st->execute($args);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $rows[] = array_merge(agent_invoice_out($i), ['folder' => $i['practice_code']]);
        }
        agent_out(['ok' => true, 'count' => count($rows), 'invoices' => $rows]);
    }

    // ── get_invoice ──────────────────────────────────────────────────────────
    case 'get_invoice': {
        $inv = agent_invoice($db, $in);
        $id  = (int)$inv['id'];
        $items = [];
        foreach (inv_items($db, $id) as $it) {
            $items[] = ['description' => $it['description'], 'quantity' => (float)$it['quantity'],
                        'unit_price' => (float)$it['unit_price'], 'line_total' => (float)$it['line_total']];
        }
        $pays = [];
        foreach (inv_payments($db, $id) as $p) {
            $pays[] = ['id' => (int)$p['id'], 'date' => $p['payment_date'], 'amount' => (float)$p['amount'],
                       'method' => $p['method'], 'reference' => $p['reference'], 'notes' => $p['notes'],
                       'cancelled' => !empty($p['cancelled_at']), 'cancelled_at' => $p['cancelled_at'],
                       'cancellation_reason' => $p['cancellation_reason']];
        }
        $cns = [];
        foreach (inv_credit_notes($db, $id) as $c) {
            $cns[] = ['id' => (int)$c['id'], 'number' => $c['cn_number'], 'date' => $c['issue_date'],
                      'total' => (float)$c['total'], 'status' => $c['status'], 'reason' => $c['reason']];
        }
        agent_out(['ok' => true,
            'invoice' => array_merge(agent_invoice_out($inv), [
                'bill_to_address' => $inv['bill_to_address'], 'terms' => $inv['terms'], 'notes' => $inv['notes'],
                'terms_conditions' => $inv['terms_conditions'], 'follow_up_note' => $inv['follow_up_note'] ?? null,
                'created_by' => $inv['created_by_name'], 'created_at' => $inv['created_at'], 'updated_at' => $inv['updated_at'],
            ]),
            'items' => $items, 'payments' => $pays, 'credit_notes' => $cns,
            'folder' => agent_invoice_folder($db, $id),
            'pdf_name' => inv_pdf_dropbox_name($inv),
            'methods' => INV_METHODS, 'folder_statuses' => array_keys(FOLDER_TAG_OPTIONS),
        ]);
    }

    // ── add_invoice_payment ──────────────────────────────────────────────────
    // Same as "Record Payment" on the invoice page. A payment with the same
    // amount and reference (or, with no reference, the same date) is refused
    // unless "allow_duplicate": true; more than the balance unless "allow_overpayment".
    case 'add_invoice_payment': {
        agent_require_method('POST');
        $inv = agent_invoice($db, $in);
        $id  = (int)$inv['id'];
        if ($inv['status'] === 'Cancelled') agent_fail('Invoice ' . $inv['invoice_number'] . ' is Cancelled — no payments can be added', 409);
        $date = isset($in['date']) && $in['date'] !== '' ? agent_iso_date($in['date'], 'date') : date('Y-m-d');
        if (!isset($in['amount']) || !is_numeric($in['amount']) || (float)$in['amount'] <= 0) agent_fail('amount must be a number > 0');
        $amount = round((float)$in['amount'], 2);
        $method = trim((string)($in['method'] ?? 'Bank Transfer'));
        foreach (INV_METHODS as $m) { if (strcasecmp($m, $method) === 0) $method = $m; }
        if (!in_array($method, INV_METHODS, true)) agent_fail('method must be one of: ' . implode(', ', INV_METHODS));
        $ref   = mb_substr(trim((string)($in['reference'] ?? '')), 0, 100);
        $notes = mb_substr(trim((string)($in['notes'] ?? '')), 0, 255);

        $dup = inv_find_duplicate_payment($db, $id, $amount, $date, $ref);
        if ($dup && empty($in['allow_duplicate'])) {
            agent_fail('A payment of ' . fmt_money($amount, $inv['currency']) . ($ref !== '' ? ' with reference "' . $ref . '"' : ' on ' . $date)
                       . ' is already recorded (payment ' . $dup['id'] . ', ' . $dup['payment_date'] . ')', 409,
                       ['duplicate' => ['id' => (int)$dup['id'], 'date' => $dup['payment_date'], 'amount' => (float)$dup['amount'],
                                        'method' => $dup['method'], 'reference' => $dup['reference']],
                        'hint' => 'Resend with "allow_duplicate": true only if it really is a second payment.']);
        }
        $balance = round((float)$inv['balance_due'], 2);
        if ($amount > $balance + 0.005 && empty($in['allow_overpayment'])) {
            agent_fail('Amount ' . fmt_money($amount, $inv['currency']) . ' is more than the balance due ' . fmt_money($balance, $inv['currency']), 409,
                       ['invoice' => agent_invoice_out($inv), 'hint' => 'Resend with "allow_overpayment": true if that is intended.']);
        }
        try {
            $closedMemos = [];
            $pid = inv_add_payment($db, $id, $date, $amount, $method, $ref, $notes, $closedMemos);
        } catch (InvalidArgumentException $e) {
            agent_fail($e->getMessage());
        }
        agent_out(['ok' => true, 'payment_id' => $pid,
                   'message' => 'Payment of ' . fmt_money($amount, $inv['currency']) . ' recorded.',
                   'memos_closed' => $closedMemos,
                   'invoice' => agent_invoice_out(inv_get($db, $id)), 'folder' => agent_invoice_folder($db, $id)]);
    }

    // ── cancel_invoice_payment ───────────────────────────────────────────────
    case 'cancel_invoice_payment': {
        agent_require_method('POST');
        $inv    = agent_invoice($db, $in);
        $id     = (int)$inv['id'];
        $pid    = (int)($in['payment_id'] ?? 0);
        $reason = mb_substr(trim((string)($in['reason'] ?? '')), 0, 255);
        if ($pid <= 0)      agent_fail('payment_id is required (see get_invoice.payments)');
        if ($reason === '') agent_fail('reason is required');
        $st = $db->prepare("SELECT * FROM invoice_payments WHERE id = ? AND invoice_id = ?");
        $st->execute([$pid, $id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) agent_fail('Payment ' . $pid . ' not found on invoice ' . $inv['invoice_number'], 404);
        if (!empty($p['cancelled_at'])) agent_fail('Payment ' . $pid . ' is already cancelled (' . $p['cancelled_at'] . ')', 409);
        $payment = ['id' => $pid, 'date' => $p['payment_date'], 'amount' => (float)$p['amount'],
                    'method' => $p['method'], 'reference' => $p['reference']];
        if (empty($in['confirm'])) {
            agent_out(['ok' => true, 'dry_run' => true, 'payment' => $payment, 'reason' => $reason,
                       'balance_after' => round((float)$inv['balance_due'] + (float)$p['amount'], 2),
                       'message' => 'Dry run — nothing cancelled. Resend with "confirm": true to cancel the payment.']);
        }
        inv_cancel_payment($db, $pid, $id, $reason);
        agent_out(['ok' => true, 'dry_run' => false, 'payment' => $payment, 'reason' => $reason,
                   'invoice' => agent_invoice_out(inv_get($db, $id)), 'folder' => agent_invoice_folder($db, $id)]);
    }

    // ── update_folder_status ─────────────────────────────────────────────────
    // Rename the invoice's Dropbox folder to …_DEPOSIT / _BALANCE / _PAID …
    // (same as "Update Folder" on the invoice page). Preview unless "confirm": true.
    case 'update_folder_status': {
        agent_require_method('POST');
        $inv   = agent_invoice($db, $in);
        $id    = (int)$inv['id'];
        $label = strtoupper(trim((string)($in['status'] ?? '')));
        if ($label === 'PAID') $label = 'FULLY PAID';
        if (!array_key_exists($label, FOLDER_TAG_OPTIONS)) agent_fail('status must be one of: ' . implode(', ', array_keys(FOLDER_TAG_OPTIONS)));
        $folder = agent_invoice_folder($db, $id);
        if (!$folder || !$folder['folder']) agent_fail('Invoice ' . $inv['invoice_number'] . ' has no linked request / Dropbox folder', 409);
        $preview = ['current' => $folder['folder'], 'new_name' => inv_folder_new_name($folder['folder'], $label),
                    'new_tag' => FOLDER_TAG_OPTIONS[$label], 'group_folder' => $folder['group_folder']];
        if ($preview['new_name'] === $preview['current']) {
            agent_out(array_merge(['ok' => true, 'dry_run' => empty($in['confirm']), 'unchanged' => true,
                                   'message' => 'Folder already has the ' . $preview['new_tag'] . ' tag — nothing to do.'], $preview));
        }
        if (empty($in['confirm'])) {
            agent_out(array_merge(['ok' => true, 'dry_run' => true,
                                   'message' => 'Dry run — nothing renamed. Resend with "confirm": true to rename the folder.'], $preview));
        }
        try {
            $res = inv_update_folder_status($db, $id, $label);
        } catch (Throwable $e) {
            agent_fail($e->getMessage(), 502, $preview);
        }
        agent_out(['ok' => true, 'dry_run' => false, 'old_name' => $preview['current'], 'new_name' => $res['new_name'],
                   'new_tag' => $res['new_tag'], 'dropbox_url' => $res['new_url'], 'group_msg' => $res['group_msg'],
                   'folder' => agent_invoice_folder($db, $id)]);
    }

    // ── save_invoice_pdf ─────────────────────────────────────────────────────
    // Render the invoice PDF (Dompdf, same layout as the emailed one) and upload
    // it as "Invoice <number>.pdf" into the booking folder. An existing file is
    // kept unless "overwrite": true. Optional folder_path overrides the folder.
    case 'save_invoice_pdf': {
        agent_require_method('POST');
        $inv = agent_invoice($db, $in);
        $id  = (int)$inv['id'];
        require_once __DIR__ . '/dropbox_helper.php';
        $token = dropbox_get_access_token();

        $dir = rtrim(trim((string)($in['folder_path'] ?? '')), '/');
        if ($dir !== '') {
            if ($dir[0] !== '/') agent_fail('folder_path must be a full Dropbox path starting with /');
            if (!dropbox_path_exists($token, $dir)) agent_fail('folder_path ' . $dir . ' not found in Dropbox', 404);
        } else {
            $r = inv_linked_request($db, $id);
            if (!$r || !$r['practice_code']) agent_fail('Invoice ' . $inv['invoice_number'] . ' has no linked request / folder — pass folder_path', 409);
            $dir = agent_request_dropbox_dir($token, $r);
        }
        $path      = $dir . '/' . inv_pdf_dropbox_name($inv);
        $overwrite = !empty($in['overwrite']);
        $exists    = dropbox_path_exists($token, $path);
        if ($exists && !$overwrite) {
            agent_fail('File already exists: ' . $path, 409, ['path' => $path, 'hint' => 'Resend with "overwrite": true to replace it (Dropbox keeps the old version).']);
        }
        try {
            $pdf = inv_pdf($inv, inv_items($db, $id), inv_payments($db, $id, true));
        } catch (RuntimeException $e) {
            agent_fail($e->getMessage(), 500);
        }
        try {
            $meta = dropbox_upload_text($token, $path, $pdf, $overwrite ? 'overwrite' : 'add');
        } catch (RuntimeException $e) {
            agent_fail($e->getMessage(), stripos($e->getMessage(), 'conflict') !== false ? 409 : 502, ['path' => $path]);
        }
        agent_out(['ok' => true, 'path' => $meta['path_display'] ?? $path, 'size' => $meta['size'] ?? strlen($pdf),
                   'rev' => $meta['rev'] ?? null, 'overwritten' => $exists,
                   'invoice' => agent_invoice_out($inv)]);
    }

    // ── memo_list ────────────────────────────────────────────────────────────
    // The owner's Memo Board: active memos (open / doing / waiting) by default.
    case 'memo_list': {
        $owner  = agent_memo_owner($db);
        $where  = "m.user_id = ? AND m.deleted_at IS NULL";
        $args   = [$owner['id']];
        $status = array_filter(array_map('trim', explode(',', (string)($in['status'] ?? 'open,doing,waiting'))));
        $status = array_values(array_intersect($status, MEMO_STATUSES));
        if (!$status) agent_fail('status must be a comma list of: ' . implode(', ', MEMO_STATUSES));
        $where .= " AND m.status IN (" . implode(',', array_fill(0, count($status), '?')) . ")";
        $args = array_merge($args, $status);
        $q = trim((string)($in['q'] ?? ''));
        if ($q !== '') { $where .= " AND (m.title LIKE ? OR m.body LIKE ? OR m.waiting_on LIKE ?)"; array_push($args, "%$q%", "%$q%", "%$q%"); }
        if ((int)($in['request_id'] ?? 0) > 0) { $where .= " AND m.request_id = ?"; $args[] = (int)$in['request_id']; }
        if ((int)($in['invoice_id'] ?? 0) > 0) { $where .= " AND m.invoice_id = ?"; $args[] = (int)$in['invoice_id']; }
        if (trim((string)($in['ext_key'] ?? '')) !== '') { $where .= " AND m.ext_key = ?"; $args[] = trim($in['ext_key']); }
        if (!empty($in['follow_up_due'])) { $where .= " AND m.due_date <= ?"; $args[] = date('Y-m-d'); }
        $rows = agent_memo_rows($db, $where, $args);
        agent_out(['ok' => true, 'owner' => $owner['full_name'], 'count' => count($rows), 'memos' => $rows]);
    }

    // ── memo_save ────────────────────────────────────────────────────────────
    // Create, or update when id / ext_key matches one of the owner's memos.
    case 'memo_save': {
        agent_require_method('POST');
        $owner = agent_memo_owner($db);
        $cur   = agent_memo_find($db, $owner['id'], $in);
        if ((int)($in['id'] ?? 0) > 0 && !$cur) agent_fail('Memo ' . (int)$in['id'] . ' not found on ' . $owner['full_name'] . "'s board", 404);
        $f = [];   // column => value, only for the fields given (update) / all (create)
        $has = function ($k) use ($in) { return array_key_exists($k, $in); };

        if ($has('title') || !$cur) {
            $t = trim((string)($in['title'] ?? ''));
            if ($t === '') agent_fail('title is required');
            $f['title'] = mb_substr($t, 0, 255);
        }
        if ($has('body'))      $f['body'] = memo_text_to_html($in['body']);
        if ($has('status') || !$cur) {
            $s = (string)($in['status'] ?? 'open');
            if (!in_array($s, ['open', 'doing', 'waiting'], true)) agent_fail('status must be open, doing or waiting (use memo_set_status to close)');
            $f['status'] = $s;
        }
        if ($has('waiting_on')) $f['waiting_on'] = trim((string)$in['waiting_on']) !== '' ? mb_substr(trim($in['waiting_on']), 0, 120) : null;
        if ($has('priority')) {
            if (!in_array($in['priority'], ['low', 'normal', 'high'], true)) agent_fail('priority must be low, normal or high');
            $f['priority'] = $in['priority'];
        }
        if ($has('due_date'))  $f['due_date'] = $in['due_date'] ? agent_iso_date($in['due_date'], 'due_date') : null;
        if ($has('reminder_at')) {
            $r = trim((string)$in['reminder_at']);
            if ($r !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}$/', $r)) agent_fail('reminder_at must be "YYYY-MM-DD HH:MM" (EAT)');
            $f['reminder_at'] = $r !== '' ? str_replace('T', ' ', $r) . ':00' : null;
            $f['reminder_sent'] = 0;
        }
        if ($has('request_id')) {
            $f['request_id'] = (int)$in['request_id'] > 0 ? (int)agent_request($db, $in['request_id'])['id'] : null;
        }
        if ($has('invoice_id') || $has('invoice_number')) {
            $inv = memo_find_invoice($db, $in['invoice_id'] ?? $in['invoice_number'] ?? '');
            if (!$inv && trim((string)($in['invoice_id'] ?? $in['invoice_number'] ?? '')) !== '') agent_fail('Invoice not found', 404);
            $f['invoice_id'] = $inv ? (int)$inv['id'] : null;
            if ($inv && $inv['request_id'] && !array_key_exists('request_id', $f) && (!$cur || !$cur['request_id'])) $f['request_id'] = (int)$inv['request_id'];
        }
        if ($has('auto_close_on_payment')) $f['auto_close'] = !empty($in['auto_close_on_payment']) ? 'payment' : null;
        if ($has('ext_key')) $f['ext_key'] = trim((string)$in['ext_key']) !== '' ? mb_substr(trim($in['ext_key']), 0, 120) : null;

        // Waiting with a follow-up date and no reminder → email reminder that morning at 08:00.
        $status = $f['status'] ?? ($cur['status'] ?? 'open');
        $due    = array_key_exists('due_date', $f) ? $f['due_date'] : ($cur['due_date'] ?? null);
        $rem    = array_key_exists('reminder_at', $f) ? $f['reminder_at'] : ($cur['reminder_at'] ?? null);
        if ($status === 'waiting' && $due && !$rem && !$has('reminder_at')) { $f['reminder_at'] = $due . ' 08:00:00'; $f['reminder_sent'] = 0; }
        $invId = array_key_exists('invoice_id', $f) ? $f['invoice_id'] : ($cur['invoice_id'] ?? null);
        if (($f['auto_close'] ?? null) === 'payment' && !$invId) agent_fail('auto_close_on_payment needs an invoice (invoice_id or invoice_number)');

        $now = date('Y-m-d H:i:s');
        if ($cur) {
            $id = (int)$cur['id'];
            if ($f) {
                $f['updated_at'] = $now;
                $db->prepare("UPDATE memos SET " . implode(', ', array_map(function ($c) { return $c . ' = ?'; }, array_keys($f))) . " WHERE id = ?")
                   ->execute(array_merge(array_values($f), [$id]));
            }
        } else {
            $f += ['user_id' => $owner['id'], 'type' => 'todo', 'priority' => 'normal', 'pinned' => 0, 'reminder_sent' => 0,
                   'recur_rule' => 'none', 'sort_order' => 0, 'source' => 'claude', 'created_at' => $now, 'updated_at' => $now];
            $db->prepare("INSERT INTO memos (" . implode(', ', array_keys($f)) . ") VALUES (" . implode(',', array_fill(0, count($f), '?')) . ")")
               ->execute(array_values($f));
            $id = (int)$db->lastInsertId();
        }
        if ($has('next_steps')) {
            if (!is_array($in['next_steps'])) agent_fail('next_steps must be a list of {title, days_after?, body?}');
            memo_set_next_steps($db, $id, $in['next_steps']);
        }
        $row = agent_memo_rows($db, "m.id = ?", [$id]);
        agent_out(['ok' => true, 'created' => !$cur, 'owner' => $owner['full_name'], 'memo' => $row[0] ?? null]);
    }

    // ── memo_set_status ──────────────────────────────────────────────────────
    // done → closes it (optional note appended) and opens its next steps.
    case 'memo_set_status': {
        agent_require_method('POST');
        $owner = agent_memo_owner($db);
        $cur   = agent_memo_find($db, $owner['id'], $in);
        if (!$cur) agent_fail('Memo not found (give id or ext_key)', 404);
        $s = (string)($in['status'] ?? '');
        if (!in_array($s, ['open', 'doing', 'waiting', 'done', 'archived'], true)) agent_fail('status must be open, doing, waiting, done or archived');
        $opened = [];
        if ($s === 'done') {
            $opened = memo_close($db, $cur['id'], (string)($in['note'] ?? ''));
        } else {
            $db->prepare("UPDATE memos SET status = ?, updated_at = ? WHERE id = ?")->execute([$s, date('Y-m-d H:i:s'), (int)$cur['id']]);
        }
        $row = agent_memo_rows($db, "m.id = ?", [(int)$cur['id']]);
        agent_out(['ok' => true, 'memo' => $row[0] ?? null,
                   'opened_next' => $opened ? agent_memo_rows($db, "m.id IN (" . implode(',', array_map('intval', $opened)) . ")", []) : []]);
    }

    // ── routine_status / routine_done ────────────────────────────────────────
    // The owner's recurring checks (Memo Board top strip): due / count / last done.
    case 'routine_status': {
        $owner = agent_memo_owner($db);
        agent_out(['ok' => true, 'owner' => $owner['full_name'], 'routines' => memo_routines_status($db, $owner['id'])]);
    }

    case 'routine_done': {
        agent_require_method('POST');
        $owner = agent_memo_owner($db);
        $key   = (string)($in['key'] ?? '');
        if (!memo_routine_done($db, $owner['id'], $key, (string)($in['note'] ?? ''), 'claude')) {
            agent_fail('key must be one of: ' . implode(', ', array_keys(MEMO_ROUTINES)));
        }
        agent_out(['ok' => true, 'routines' => memo_routines_status($db, $owner['id'])]);
    }

    // ── import_zoho_invoice ──────────────────────────────────────────────────
    // Bring an old Zoho invoice (e.g. INV-002417) into Hub with its original
    // number. Fields as api_import.php; Claude reads the Zoho PDF itself.
    // Dry-run unless "confirm": true.
    case 'import_zoho_invoice': {
        agent_require_method('POST');
        agent_invoice_lib();
        $num = trim((string)($in['invoice_number'] ?? ''));
        if ($num === '') agent_fail('invoice_number is required (the Zoho number, e.g. INV-002417)');
        $st = $db->prepare("SELECT id FROM invoices WHERE invoice_number = ?");
        $st->execute([$num]);
        if ($dupId = (int)($st->fetchColumn() ?: 0)) {
            agent_fail('Invoice ' . $num . ' already exists in Hub', 409, ['invoice_id' => $dupId]);
        }
        if ((int)($in['request_id'] ?? 0) > 0) agent_request($db, $in['request_id']);
        $in['issue_date'] = agent_iso_date($in['issue_date'] ?? '', 'issue_date');
        if (!empty($in['due_date']))     $in['due_date']     = agent_iso_date($in['due_date'], 'due_date');
        if (!empty($in['payment_date'])) $in['payment_date'] = agent_iso_date($in['payment_date'], 'payment_date');
        $items = isset($in['items']) && is_array($in['items']) ? $in['items'] : [];
        if (!$items) agent_fail('items is required: [{description, quantity, unit_price, line_total?}, …]');
        $sum = 0.0;
        foreach ($items as $k => $it) {
            if (trim((string)($it['description'] ?? '')) === '') agent_fail('items[' . $k . ']: description is required');
            $qty = (float)($it['quantity'] ?? 1);
            $sum += round((float)($it['line_total'] ?? $qty * (float)($it['unit_price'] ?? 0)), 2);
        }
        $sum = round($sum, 2);
        if (isset($in['total']) && abs((float)$in['total'] - $sum) > 0.01) {
            agent_fail('Items add up to ' . $sum . ', not the given total ' . $in['total'], 422);
        }
        $paid = round((float)($in['payment_amount'] ?? 0), 2);
        $body = array_merge($in, ['invoice_number_mode' => 'original', 'invoice_number' => $num]);
        $summary = ['invoice_number' => $num, 'bill_to' => $in['bill_to_name'] ?? '', 'currency' => $in['currency'] ?? 'USD',
                    'issue_date' => $in['issue_date'], 'items' => count($items), 'total' => $sum,
                    'paid' => $paid, 'balance' => round($sum - $paid, 2), 'request_id' => $agentReqId];
        if (empty($in['confirm'])) {
            agent_out(['ok' => true, 'dry_run' => true, 'would_create' => $summary,
                       'message' => 'Dry run — nothing created. Resend with "confirm": true to import.']);
        }
        try {
            $res = inv_import($db, $body, (int)$agentUser['id']);
        } catch (InvalidArgumentException $e) {
            agent_fail($e->getMessage(), 422);
        } catch (DomainException $e) {
            agent_fail($e->getMessage(), 409);
        }
        agent_out(['ok' => true, 'dry_run' => false, 'invoice' => agent_invoice_out(inv_get($db, $res['invoice_id']))]);
    }

    // ── Mailbox info@ ────────────────────────────────────────────────────────
    // IMAP on the BlueHost mailbox, so Claude doesn't log in to webmail.
    // Reading never marks as seen unless "mark_seen": true. No delete action.
    case 'mail_folders': {
        agent_mail_lib();
        agent_out(['ok' => true, 'mailbox' => (string)MAILBOX_USER, 'folders' => mbx_folders()]);
    }

    case 'mail_list': {
        agent_mail_lib();
        foreach (['since', 'before'] as $k) if (!empty($in[$k])) $in[$k] = agent_iso_date($in[$k], $k);
        $flt = [
            'unseen'  => !empty($in['unseen']) && $in['unseen'] !== '0',
            'flagged' => !empty($in['flagged']) && $in['flagged'] !== '0',
            'from' => $in['from'] ?? '', 'to' => $in['to'] ?? '', 'subject' => $in['subject'] ?? '',
            'text' => $in['q'] ?? '', 'since' => $in['since'] ?? '', 'before' => $in['before'] ?? '',
        ];
        $res = mbx_list((string)($in['folder'] ?? 'INBOX'), $flt, (int)($in['limit'] ?? 30), (int)($in['offset'] ?? 0));
        agent_out(['ok' => true] + $res);
    }

    case 'mail_get': {
        agent_mail_lib();
        $uid = (int)($in['uid'] ?? 0);
        if ($uid <= 0) agent_fail('uid is required');
        $folder = (string)($in['folder'] ?? 'INBOX');
        $markSeen = !empty($in['mark_seen']) && $in['mark_seen'] !== '0';
        $msg = mbx_get($folder, $uid, $markSeen);
        if (!$msg) agent_fail('Message uid ' . $uid . ' not found in ' . $folder, 404);
        agent_out(['ok' => true, 'message' => $msg]);
    }

    // Returns the file as base64, or with request_id / folder_path saves it to
    // Dropbox (booking folder) instead. Existing file kept unless "overwrite": true.
    case 'mail_attachment': {
        agent_mail_lib();
        $uid  = (int)($in['uid'] ?? 0);
        $part = trim((string)($in['part'] ?? ''));
        if ($uid <= 0 || !preg_match('/^\d+(\.\d+)*$/', $part)) agent_fail('uid and part (from mail_get attachments) are required');
        $folder = (string)($in['folder'] ?? 'INBOX');
        try {
            $att = mbx_attachment($folder, $uid, $part);
        } catch (RuntimeException $e) {
            agent_fail($e->getMessage(), 413);
        }
        if (!$att) agent_fail('Attachment ' . $part . ' not found in message ' . $uid, 404);
        $info = ['uid' => $uid, 'part' => $part, 'name' => $att['name'], 'mime' => $att['mime'], 'size' => $att['size']];

        $dir = rtrim(trim((string)($in['folder_path'] ?? '')), '/');
        if ($dir === '' && (int)($in['request_id'] ?? 0) <= 0) {
            agent_out(['ok' => true, 'attachment' => $info + ['content_base64' => base64_encode($att['content'])]]);
        }
        require_once __DIR__ . '/dropbox_helper.php';
        $token = dropbox_get_access_token();
        if ($dir !== '') {
            if ($dir[0] !== '/') agent_fail('folder_path must be a full Dropbox path starting with /');
            if (!dropbox_path_exists($token, $dir)) agent_fail('folder_path ' . $dir . ' not found in Dropbox', 404);
        } else {
            $r = agent_request($db, $in['request_id']);
            if (!$r['practice_code']) agent_fail('Request ' . $r['id'] . ' has no folder — pass folder_path', 409);
            $dir = agent_request_dropbox_dir($token, $r);
        }
        $name = trim((string)($in['save_as'] ?? '')) ?: $att['name'];
        $name = trim(str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $name));
        $path = $dir . '/' . $name;
        $overwrite = !empty($in['overwrite']);
        $exists = dropbox_path_exists($token, $path);
        if ($exists && !$overwrite) {
            agent_fail('File already exists: ' . $path, 409, ['path' => $path, 'hint' => 'Use save_as for another name, or "overwrite": true.']);
        }
        try {
            $meta = dropbox_upload_text($token, $path, $att['content'], $overwrite ? 'overwrite' : 'add');
        } catch (RuntimeException $e) {
            agent_fail($e->getMessage(), 502, ['path' => $path]);
        }
        agent_out(['ok' => true, 'attachment' => $info, 'saved_to' => $meta['path_display'] ?? $path, 'overwritten' => $exists]);
    }

    case 'mail_flag': {
        agent_require_method('POST');
        agent_mail_lib();
        $uids = agent_mail_uids($in);
        $seen = array_key_exists('seen', $in) ? (bool)$in['seen'] : null;
        $flag = array_key_exists('flagged', $in) ? (bool)$in['flagged'] : null;
        if ($seen === null && $flag === null) agent_fail('Give seen and/or flagged (true / false)');
        $folder = (string)($in['folder'] ?? 'INBOX');
        mbx_flag($folder, $uids, $seen, $flag);
        agent_out(['ok' => true, 'folder' => $folder, 'uids' => $uids, 'seen' => $seen, 'flagged' => $flag]);
    }

    // Move to another existing folder (e.g. INBOX.Archive). Dry-run unless "confirm": true.
    case 'mail_move': {
        agent_require_method('POST');
        agent_mail_lib();
        $uids = agent_mail_uids($in);
        $folder = (string)($in['folder'] ?? 'INBOX');
        $to = trim((string)($in['to_folder'] ?? ''));
        if ($to === '') agent_fail('to_folder is required (see mail_folders)');
        if ($to === $folder) agent_fail('to_folder is the same as folder');
        if (empty($in['confirm'])) {
            agent_out(['ok' => true, 'dry_run' => true, 'would_move' => ['from' => $folder, 'to' => $to, 'uids' => $uids],
                       'message' => 'Dry run — nothing moved. Resend with "confirm": true.']);
        }
        try {
            mbx_move($folder, $uids, $to);
        } catch (InvalidArgumentException $e) {
            agent_fail($e->getMessage(), 404);
        }
        agent_out(['ok' => true, 'dry_run' => false, 'moved' => ['from' => $folder, 'to' => $to, 'uids' => $uids]]);
    }

    // Save a message in Drafts: Roberto reviews and sends it from webmail / phone.
    case 'mail_draft':
    // Send from info@ (copy in Sent, original marked answered). Dry-run unless "confirm": true.
    case 'mail_send': {
        agent_require_method('POST');
        agent_mail_lib();
        try {
            $composed = mbx_compose($in);
        } catch (InvalidArgumentException $e) {
            agent_fail($e->getMessage());
        }
        $email = $composed['summary'];
        if ($agentAction === 'mail_draft') {
            $box = mbx_save_draft($composed);
            agent_out(['ok' => true, 'saved_in' => $box, 'email' => $email]);
        }
        if (empty($in['confirm'])) {
            agent_out(['ok' => true, 'dry_run' => true, 'email' => $email,
                       'message' => 'Dry run — not sent. Resend with "confirm": true to send.']);
        }
        $res = mbx_send($composed);
        agent_out(['ok' => true, 'dry_run' => false, 'message' => 'Sent', 'sent_folder' => $res['sent_folder'],
                   'warning' => $res['warning'], 'email' => $email]);
    }

    default:
        agent_fail('Unknown action "' . $agentAction . '"', 400, ['actions' => [
            'find_requests', 'list_agencies', 'create_request', 'update_request', 'list_standard_programs',
            'copy_program', 'get_rates', 'fill_calc', 'read_calc', 'confirm_preview', 'confirm_booking', 'send_booking_email',
            'rollback_booking', 'iti_programs', 'iti_texts', 'iti_save_texts', 'update_rate', 'replace_flight_rates',
            'iti_lodges', 'iti_lodge', 'iti_destinations', 'iti_destination', 'iti_lodge_photos', 'iti_destination_photo',
            'iti_update_lodge', 'iti_update_destination', 'iti_create_lodge', 'iti_samples', 'iti_program', 'iti_create_personal', 'iti_update_program',
            'iti_update_day', 'iti_publish', 'iti_calc_plan', 'iti_final_from_calc', 'iti_save_alias',
            'iti_set_days', 'iti_add_day', 'iti_delete_day', 'iti_inclusions', 'iti_update_inclusions', 'iti_save_as_sample',
            'iti_flight_routes', 'iti_create_flight_route', 'iti_activities', 'iti_create_activity',
            'iti_transfer_routes', 'iti_create_transfer_route', 'add_flight_rates',
            'find_invoices', 'get_invoice', 'add_invoice_payment', 'cancel_invoice_payment', 'update_folder_status',
            'save_invoice_pdf', 'import_zoho_invoice', 'memo_list', 'memo_save', 'memo_set_status',
            'routine_status', 'routine_done',
            'mail_folders', 'mail_list', 'mail_get', 'mail_attachment', 'mail_flag', 'mail_move', 'mail_draft', 'mail_send',
        ]]);
    }
} catch (Throwable $e) {
    agent_fail('Server error: ' . $e->getMessage(), 500);
}
