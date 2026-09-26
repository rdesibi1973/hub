<?php
/**
 * agent_api.php — JSON API for Claude (Cowork / Claude Code / scheduled tasks) to
 * run the booking workflow without driving the web UI. Public entry point:
 * /api/agent/index.php?action=<name>  (which chdir()s here and includes this file).
 * Spec + examples: docs/AGENT_API.md.
 *
 * Auth: header X-Agent-Key = AGENT_API_KEY (includes/config.php, never in the repo).
 * The key acts as the Hub user AGENT_API_USER (username; default 'claude-agent').
 * HTTPS only, 60 requests/min, every call written to agent_audit_log.
 * Outbound / folder-moving actions (confirm_booking, send_booking_email) are
 * dry-runs unless the body carries "confirm": true.
 *
 * All logic lives in includes/booking_service.php, shared with the Hub pages.
 */
ob_start();
date_default_timezone_set('Africa/Dar_es_Salaam');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/booking_service.php';
require_once __DIR__ . '/includes/postpone_lib.php';   // booking_cc_agent_email()
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
    $dry = in_array($action, ['confirm_booking', 'send_booking_email'], true) && empty($payload['confirm']);
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
$agentUsername = defined('AGENT_API_USER') ? (string)AGENT_API_USER : 'claude-agent';
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
} else {
    $agentPayload = $_GET;
    unset($agentPayload['action']);
}
$in = $agentPayload;

function agent_require_method(string $m): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $m) agent_fail('Use ' . $m . ' for this action', 405);
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
        ]);
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

    default:
        agent_fail('Unknown action "' . $agentAction . '"', 400, ['actions' => [
            'find_requests', 'list_agencies', 'create_request', 'update_request', 'list_standard_programs',
            'copy_program', 'confirm_preview', 'confirm_booking', 'send_booking_email',
        ]]);
    }
} catch (Throwable $e) {
    agent_fail('Server error: ' . $e->getMessage(), 500);
}
