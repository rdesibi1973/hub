<?php
// modules/memo/ajax.php — CRUD for the Memo Board (with sharing support)

ob_start();

date_default_timezone_set('Africa/Dar_es_Salaam');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

start_session();

header('Content-Type: application/json');

function out($ok, $data = array()) {
    ob_clean();
    $resp = array('ok' => $ok);
    foreach ($data as $k => $v) { $resp[$k] = $v; }
    echo json_encode($resp);
    exit;
}

$uid = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
if ($uid <= 0) { out(false, array('error' => 'Not authenticated')); }

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$now    = date('Y-m-d H:i:s');

function parse_dt($v) {
    $v = trim((string)$v);
    if ($v === '') { return null; }
    $v = str_replace('T', ' ', $v);
    $ts = strtotime($v);
    if ($ts === false) { return null; }
    return date('Y-m-d H:i:s', $ts);
}
function parse_date($v) {
    $v = trim((string)$v);
    if ($v === '') { return null; }
    $ts = strtotime($v);
    if ($ts === false) { return null; }
    return date('Y-m-d', $ts);
}
function clean_enum($v, $allowed, $default) {
    $v = trim((string)$v);
    return in_array($v, $allowed, true) ? $v : $default;
}
function sanitise_memo_html($html) {
    $html = strip_tags($html, '<b><strong><i><em><u><s><p><br><ol><ul><li><a><span><h1><h2><h3>');
    $stripped = preg_replace('/<[^>]+>/', '', $html);
    if (trim($stripped) === '') { return ''; }
    return $html;
}
function clean_color($v) {
    $v = trim((string)$v);
    if ($v === '') { return null; }
    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $v)) { return $v; }
    return null;
}
// Parse a comma/semicolon/newline separated list of emails into a clean,
// de-duplicated, comma-separated string. Invalid entries are dropped.
function clean_emails($v) {
    $v = trim((string)$v);
    if ($v === '') { return null; }
    $parts = preg_split('/[,;\s]+/', $v);
    $out = array();
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') { continue; }
        if (filter_var($p, FILTER_VALIDATE_EMAIL)) {
            $key = strtolower($p);
            if (!isset($out[$key])) { $out[$key] = $p; }
        }
    }
    if (empty($out)) { return null; }
    return implode(', ', array_values($out));
}

// Returns: is_owner=1 if owner; can_edit=1 if owner or shared with can_edit; 0 if no access.
function memo_access($pdo, $memo_id, $uid) {
    $stmt = $pdo->prepare(
        "SELECT m.user_id,
                MAX(CASE WHEN ms.id IS NOT NULL THEN ms.can_edit ELSE 0 END) AS shared_can_edit
         FROM memos m
         LEFT JOIN memo_shares ms
               ON ms.memo_id = m.id
              AND (ms.shared_with_user_id = ? OR ms.shared_with_user_id IS NULL)
         WHERE m.id = ? AND m.deleted_at IS NULL
         GROUP BY m.id"
    );
    $stmt->execute(array($uid, $memo_id));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { return null; }
    $is_owner = intval($row['user_id']) === $uid;
    $can_edit = $is_owner || intval($row['shared_can_edit']) === 1;
    return array('is_owner' => $is_owner, 'can_edit' => $can_edit);
}

// ---------- list ----------
if ($action === 'list') {
    // Own memos + memos shared with this user (or with everyone)
    $stmt = $pdo->prepare(
        "SELECT m.id, m.user_id, m.title, m.body, m.type, m.status, m.priority,
                m.pinned, m.color, m.due_date, m.reminder_at, m.reminder_emails, m.reminder_sent,
                m.recur_rule, m.sort_order, m.created_at, m.updated_at,
                (m.user_id = ?) AS is_owner,
                MAX(CASE WHEN ms.id IS NOT NULL THEN ms.can_edit ELSE 0 END) AS shared_can_edit,
                (EXISTS (SELECT 1 FROM memo_shares WHERE memo_id = m.id)) AS is_shared,
                u.full_name AS owner_name
         FROM memos m
         LEFT JOIN memo_shares ms
               ON ms.memo_id = m.id
              AND (ms.shared_with_user_id = ? OR ms.shared_with_user_id IS NULL)
         LEFT JOIN users u ON u.id = m.user_id
         WHERE m.deleted_at IS NULL
           AND (m.user_id = ? OR ms.id IS NOT NULL)
         GROUP BY m.id
         ORDER BY m.pinned DESC, m.sort_order ASC, m.updated_at DESC"
    );
    $stmt->execute(array($uid, $uid, $uid));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // compute can_edit for each row
    foreach ($rows as &$r) {
        $r['is_owner']   = intval($r['is_owner']);
        $r['is_shared']  = intval($r['is_shared']);
        $r['can_edit']   = $r['is_owner'] || intval($r['shared_can_edit']);
        unset($r['shared_can_edit']);
    }
    unset($r);
    out(true, array('memos' => $rows));
}

// ---------- create ----------
if ($action === 'create') {
    $title = trim(isset($_POST['title']) ? $_POST['title'] : '');
    if ($title === '') { out(false, array('error' => 'Title required')); }

    $type      = clean_enum(isset($_POST['type'])       ? $_POST['type']       : '', array('memo','todo','note'), 'memo');
    $priority  = clean_enum(isset($_POST['priority'])   ? $_POST['priority']   : '', array('low','normal','high'), 'normal');
    $recur     = clean_enum(isset($_POST['recur_rule']) ? $_POST['recur_rule'] : '', array('none','daily','weekly','monthly'), 'none');
    $body      = sanitise_memo_html(isset($_POST['body'])      ? $_POST['body']      : '');
    $color     = clean_color(isset($_POST['color'])     ? $_POST['color']     : '');
    $due       = parse_date(isset($_POST['due_date'])   ? $_POST['due_date']  : '');
    $remind    = parse_dt(isset($_POST['reminder_at'])  ? $_POST['reminder_at']: '');
    $remEmails = $remind ? clean_emails(isset($_POST['reminder_emails']) ? $_POST['reminder_emails'] : '') : null;

    $stmt = $pdo->prepare(
        "INSERT INTO memos " .
        "(user_id, title, body, type, status, priority, pinned, color, due_date, reminder_at, reminder_emails, reminder_sent, recur_rule, sort_order, created_at, updated_at) " .
        "VALUES (?, ?, ?, ?, 'open', ?, 0, ?, ?, ?, ?, 0, ?, 0, ?, ?)"
    );
    $stmt->execute(array($uid, $title, $body, $type, $priority, $color, $due, $remind, $remEmails, $recur, $now, $now));
    out(true, array('id' => intval($pdo->lastInsertId())));
}

// ---------- update ----------
if ($action === 'update') {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($id <= 0) { out(false, array('error' => 'Bad id')); }

    $access = memo_access($pdo, $id, $uid);
    if (!$access || !$access['can_edit']) { out(false, array('error' => 'Not allowed')); }

    $title = trim(isset($_POST['title']) ? $_POST['title'] : '');
    if ($title === '') { out(false, array('error' => 'Title required')); }

    $type      = clean_enum(isset($_POST['type'])       ? $_POST['type']       : '', array('memo','todo','note'), 'memo');
    $priority  = clean_enum(isset($_POST['priority'])   ? $_POST['priority']   : '', array('low','normal','high'), 'normal');
    $recur     = clean_enum(isset($_POST['recur_rule']) ? $_POST['recur_rule'] : '', array('none','daily','weekly','monthly'), 'none');
    $body      = sanitise_memo_html(isset($_POST['body'])      ? $_POST['body']      : '');
    $color     = clean_color(isset($_POST['color'])     ? $_POST['color']     : '');
    $due       = parse_date(isset($_POST['due_date'])   ? $_POST['due_date']  : '');
    $remind    = parse_dt(isset($_POST['reminder_at'])  ? $_POST['reminder_at']: '');
    $remEmails = $remind ? clean_emails(isset($_POST['reminder_emails']) ? $_POST['reminder_emails'] : '') : null;

    $stmt = $pdo->prepare(
        "UPDATE memos SET title=?, body=?, type=?, priority=?, color=?, due_date=?, " .
        "reminder_at=?, reminder_emails=?, recur_rule=?, reminder_sent=0, updated_at=? " .
        "WHERE id=?"
    );
    $stmt->execute(array($title, $body, $type, $priority, $color, $due, $remind, $remEmails, $recur, $now, $id));
    out(true, array());
}

// ---------- toggle_pin (owner only) ----------
if ($action === 'toggle_pin') {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $stmt = $pdo->prepare("UPDATE memos SET pinned = 1 - pinned, updated_at=? WHERE id=? AND user_id=? AND deleted_at IS NULL");
    $stmt->execute(array($now, $id, $uid));
    out(true, array());
}

// ---------- set_status (owner or can_edit) ----------
if ($action === 'set_status') {
    $id  = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $access = memo_access($pdo, $id, $uid);
    if (!$access || !$access['can_edit']) { out(false, array('error' => 'Not allowed')); }
    $st  = clean_enum(isset($_POST['status']) ? $_POST['status'] : '', array('open','done','archived'), 'open');
    $stmt = $pdo->prepare("UPDATE memos SET status=?, updated_at=? WHERE id=? AND deleted_at IS NULL");
    $stmt->execute(array($st, $now, $id));
    out(true, array());
}

// ---------- reorder (only own memos) ----------
if ($action === 'reorder') {
    $ids = isset($_POST['ids']) ? $_POST['ids'] : array();
    if (!is_array($ids)) { out(false, array('error' => 'ids must be array')); }
    $pos = 0;
    $stmt = $pdo->prepare("UPDATE memos SET sort_order=?, updated_at=? WHERE id=? AND user_id=? AND deleted_at IS NULL");
    foreach ($ids as $mid) {
        $stmt->execute(array($pos, $now, intval($mid), $uid));
        $pos++;
    }
    out(true, array());
}

// ---------- delete (owner only) ----------
if ($action === 'delete') {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $stmt = $pdo->prepare("UPDATE memos SET deleted_at=?, updated_at=? WHERE id=? AND user_id=?");
    $stmt->execute(array($now, $now, $id, $uid));
    out(true, array());
}

// ---------- get_users — list all other active users (for share dialog) ----------
if ($action === 'get_users') {
    $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE is_active = 1 AND id != ? ORDER BY full_name");
    $stmt->execute(array($uid));
    out(true, array('users' => $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// ---------- get_shares — current shares for a memo (owner only) ----------
if ($action === 'get_shares') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    // verify ownership
    $chk = $pdo->prepare("SELECT id FROM memos WHERE id=? AND user_id=? AND deleted_at IS NULL");
    $chk->execute(array($id, $uid));
    if (!$chk->fetch()) { out(false, array('error' => 'Not found')); }

    $stmt = $pdo->prepare(
        "SELECT ms.shared_with_user_id, ms.can_edit, u.full_name
         FROM memo_shares ms
         LEFT JOIN users u ON u.id = ms.shared_with_user_id
         WHERE ms.memo_id = ?"
    );
    $stmt->execute(array($id));
    out(true, array('shares' => $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// ---------- save_shares — replace shares for a memo (owner only) ----------
if ($action === 'save_shares') {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    // verify ownership
    $chk = $pdo->prepare("SELECT id FROM memos WHERE id=? AND user_id=? AND deleted_at IS NULL");
    $chk->execute(array($id, $uid));
    if (!$chk->fetch()) { out(false, array('error' => 'Not found')); }

    // shares is a JSON string: [{user_id: null|int, can_edit: 0|1}, ...]
    $raw = isset($_POST['shares']) ? $_POST['shares'] : '[]';
    $shares = json_decode($raw, true);
    if (!is_array($shares)) { $shares = array(); }

    // delete existing shares then re-insert
    $pdo->prepare("DELETE FROM memo_shares WHERE memo_id=?")->execute(array($id));

    $ins = $pdo->prepare(
        "INSERT INTO memo_shares (memo_id, shared_with_user_id, can_edit, created_at) VALUES (?, ?, ?, ?)"
    );
    foreach ($shares as $s) {
        $suid     = isset($s['user_id']) && $s['user_id'] !== null && $s['user_id'] !== '' ? intval($s['user_id']) : null;
        $can_edit = isset($s['can_edit']) && $s['can_edit'] ? 1 : 0;
        $ins->execute(array($id, $suid, $can_edit, $now));
    }
    out(true, array());
}

out(false, array('error' => 'Unknown action'));
