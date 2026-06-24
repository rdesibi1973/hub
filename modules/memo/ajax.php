<?php
// modules/memo/ajax.php — CRUD for the private Memo Board
// All operations are scoped to the logged-in user (user_id = current user).
// No sharing, no cross-user access.

ob_start(); // prevent stray PHP warnings from corrupting JSON

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

// --- current user id (from session); never trust client-supplied user_id ---
$uid = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
if ($uid <= 0) { out(false, array('error' => 'Not authenticated')); }

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$now    = date('Y-m-d H:i:s');

// helper: parse a client datetime-local value (already EAT) into MySQL DATETIME or null
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

if ($action === 'list') {
    $stmt = $pdo->prepare(
        "SELECT id, title, body, type, status, priority, pinned, color, " .
        "due_date, reminder_at, reminder_sent, recur_rule, sort_order, " .
        "created_at, updated_at " .
        "FROM memos " .
        "WHERE user_id = ? AND deleted_at IS NULL " .
        "ORDER BY pinned DESC, sort_order ASC, updated_at DESC"
    );
    $stmt->execute(array($uid));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    out(true, array('memos' => $rows));
}

if ($action === 'create') {
    $title = trim(isset($_POST['title']) ? $_POST['title'] : '');
    if ($title === '') { out(false, array('error' => 'Title required')); }

    $type      = clean_enum(isset($_POST['type']) ? $_POST['type'] : '', array('memo','todo','note'), 'memo');
    $priority  = clean_enum(isset($_POST['priority']) ? $_POST['priority'] : '', array('low','normal','high'), 'normal');
    $recur     = clean_enum(isset($_POST['recur_rule']) ? $_POST['recur_rule'] : '', array('none','daily','weekly','monthly'), 'none');
    $body      = sanitise_memo_html(isset($_POST['body']) ? $_POST['body'] : '');
    $color     = clean_color(isset($_POST['color']) ? $_POST['color'] : '');
    $due       = parse_date(isset($_POST['due_date']) ? $_POST['due_date'] : '');
    $remind    = parse_dt(isset($_POST['reminder_at']) ? $_POST['reminder_at'] : '');

    $stmt = $pdo->prepare(
        "INSERT INTO memos " .
        "(user_id, title, body, type, status, priority, pinned, color, due_date, reminder_at, reminder_sent, recur_rule, sort_order, created_at, updated_at) " .
        "VALUES (?, ?, ?, ?, 'open', ?, 0, ?, ?, ?, 0, ?, 0, ?, ?)"
    );
    $stmt->execute(array($uid, $title, $body, $type, $priority, $color, $due, $remind, $recur, $now, $now));
    out(true, array('id' => intval($pdo->lastInsertId())));
}

if ($action === 'update') {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($id <= 0) { out(false, array('error' => 'Bad id')); }

    // verify ownership
    $chk = $pdo->prepare("SELECT id FROM memos WHERE id = ? AND user_id = ? AND deleted_at IS NULL");
    $chk->execute(array($id, $uid));
    if (!$chk->fetch()) { out(false, array('error' => 'Not found')); }

    $title = trim(isset($_POST['title']) ? $_POST['title'] : '');
    if ($title === '') { out(false, array('error' => 'Title required')); }

    $type      = clean_enum(isset($_POST['type']) ? $_POST['type'] : '', array('memo','todo','note'), 'memo');
    $priority  = clean_enum(isset($_POST['priority']) ? $_POST['priority'] : '', array('low','normal','high'), 'normal');
    $recur     = clean_enum(isset($_POST['recur_rule']) ? $_POST['recur_rule'] : '', array('none','daily','weekly','monthly'), 'none');
    $body      = sanitise_memo_html(isset($_POST['body']) ? $_POST['body'] : '');
    $color     = clean_color(isset($_POST['color']) ? $_POST['color'] : '');
    $due       = parse_date(isset($_POST['due_date']) ? $_POST['due_date'] : '');
    $remind    = parse_dt(isset($_POST['reminder_at']) ? $_POST['reminder_at'] : '');

    // if reminder changed, reset reminder_sent so it can fire again
    $stmt = $pdo->prepare(
        "UPDATE memos SET title=?, body=?, type=?, priority=?, color=?, due_date=?, " .
        "reminder_at=?, recur_rule=?, reminder_sent=0, updated_at=? " .
        "WHERE id=? AND user_id=?"
    );
    $stmt->execute(array($title, $body, $type, $priority, $color, $due, $remind, $recur, $now, $id, $uid));
    out(true, array());
}

if ($action === 'toggle_pin') {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $stmt = $pdo->prepare("UPDATE memos SET pinned = 1 - pinned, updated_at=? WHERE id=? AND user_id=? AND deleted_at IS NULL");
    $stmt->execute(array($now, $id, $uid));
    out(true, array());
}

if ($action === 'set_status') {
    $id  = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $st  = clean_enum(isset($_POST['status']) ? $_POST['status'] : '', array('open','done','archived'), 'open');
    $stmt = $pdo->prepare("UPDATE memos SET status=?, updated_at=? WHERE id=? AND user_id=? AND deleted_at IS NULL");
    $stmt->execute(array($st, $now, $id, $uid));
    out(true, array());
}

if ($action === 'reorder') {
    // expects ids[] in desired order
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

if ($action === 'delete') {
    // soft-delete
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $stmt = $pdo->prepare("UPDATE memos SET deleted_at=?, updated_at=? WHERE id=? AND user_id=?");
    $stmt->execute(array($now, $now, $id, $uid));
    out(true, array());
}

out(false, array('error' => 'Unknown action'));
