<?php
/**
 * ck_tracker.php — CK tracker: confirmed bookings from booking to _CK.
 *
 * Replaces the Java BackOffice "Groups & CK → Missing CK" (MissingCK.bat): every
 * top-level folder in /001_Safari, with how long it has been in its stage, how
 * long it has been waiting for its CK since booking finished, and an urgency
 * band by days to arrival (suppliers start penalties ~90 days out):
 *   red < 60 days · amber 60–90 · grey > 90.
 * Anyone with Leads access can set / remove the _CK marker; the Hub records who.
 */
require_once 'config.php';
require_once 'dropbox_helper.php';
require_once 'includes/folder_parser.php';
require_once 'includes/ck_lib.php';
requireLogin();
$pageTitle = 'CK tracker';
$db = db();
$cu = current_user();
$uid = (int)($cu['id'] ?? 0) ?: null;
ck_ensure_schema($db);

$VIEWS = [
    'missing' => 'Missing CK',
    'chkred'  => 'Check RED',
    'booking' => 'In booking',
    'done'    => 'CK done',
    'all'     => 'All',
];
$view      = isset($VIEWS[$_GET['view'] ?? '']) ? $_GET['view'] : 'missing';
$showPast  = !empty($_GET['past']);    // include trips that already started
$showOther = !empty($_GET['other']);   // include Kenya/Uganda/… (left out by MissingCK.bat)

// ── Notes and emails on a folder (JS, like Payments) ──────────────────────────
// A sent email is logged as a note too. send_modal.php posts the row id as
// request_id: here it is the ck_folders id.
$NOTE_ACTIONS = ['get_notes', 'add_note', 'edit_note', 'delete_note', 'send_email'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', $NOTE_ACTIONS, true)) {
    require_once 'includes/mail_helper.php';
    header('Content-Type: application/json');
    $action = $_POST['action'];
    $fid    = (int)($_POST['ck_id'] ?? $_POST['request_id'] ?? 0);
    $count  = function (int $fid) use ($db): int {
        $st = $db->prepare("SELECT COUNT(*) FROM ck_notes WHERE ck_folder_id = ?");
        $st->execute([$fid]);
        return (int)$st->fetchColumn();
    };
    $folderOk = function (int $fid) use ($db): bool {
        $st = $db->prepare("SELECT 1 FROM ck_folders WHERE id = ?");
        $st->execute([$fid]);
        return (bool)$st->fetchColumn();
    };
    try {
        if ($action === 'get_notes') {
            $st = $db->prepare("SELECT n.*, u.full_name AS user_name FROM ck_notes n
                                LEFT JOIN users u ON u.id = n.created_by
                                WHERE n.ck_folder_id = ? ORDER BY n.created_at DESC, n.id DESC");
            $st->execute([$fid]);
            echo json_encode(['ok' => true, 'notes' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } elseif ($action === 'add_note') {
            $body = trim($_POST['body'] ?? '');
            if ($body === '' || !$folderOk($fid)) { echo json_encode(['ok' => false, 'msg' => 'Empty note.']); exit; }
            $db->prepare("INSERT INTO ck_notes (ck_folder_id, created_by, note_type, body, created_at)
                          VALUES (?, ?, 'manual', ?, ?)")->execute([$fid, $uid, $body, ck_now()]);
            echo json_encode(['ok' => true, 'count' => $count($fid)]);
        } elseif ($action === 'edit_note') {
            // Only manual notes: the logged emails are history.
            $body = trim($_POST['body'] ?? '');
            if ($body === '') { echo json_encode(['ok' => false, 'msg' => 'Empty note.']); exit; }
            $db->prepare("UPDATE ck_notes SET body = ? WHERE id = ? AND note_type = 'manual'")
               ->execute([$body, (int)($_POST['note_id'] ?? 0)]);
            echo json_encode(['ok' => true]);
        } elseif ($action === 'delete_note') {
            $db->prepare("DELETE FROM ck_notes WHERE id = ? AND note_type = 'manual'")
               ->execute([(int)($_POST['note_id'] ?? 0)]);
            echo json_encode(['ok' => true]);
        } else { // send_email
            set_time_limit(60);
            $to      = trim($_POST['to'] ?? '');
            $subject = trim($_POST['subject'] ?? '');
            $body    = trim($_POST['body'] ?? '');
            $addrs   = array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $to))));
            if (!$addrs || $subject === '' || trim(strip_tags($body)) === '') {
                echo json_encode(['ok' => false, 'msg' => 'Missing required fields.']); exit;
            }
            foreach ($addrs as $a) {
                if (!filter_var($a, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok' => false, 'msg' => 'Not a valid address: ' . $a]); exit; }
            }
            if (!$folderOk($fid)) { echo json_encode(['ok' => false, 'msg' => 'Folder not found.']); exit; }
            $st = $db->prepare("SELECT email, full_name FROM users WHERE id = ?");
            $st->execute([$uid]);
            $me = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $fromEmail = trim($me['email'] ?? '');
            if ($fromEmail === '') { echo json_encode(['ok' => false, 'msg' => 'Your user account has no email address — set it in your profile.']); exit; }
            $attachments = []; $attachNames = [];
            if (!empty($_FILES['attachments']['name'][0])) {
                foreach ($_FILES['attachments']['name'] as $i => $name) {
                    if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                        $attachments[] = ['tmp_path' => $_FILES['attachments']['tmp_name'][$i], 'name' => $name];
                        $attachNames[] = $name;
                    }
                }
            }
            ob_start(); // PHPMailer may print warnings; keep the JSON clean
            $sent = send_hub_email(implode(',', $addrs), $subject, $body,
                                   trim($me['full_name'] ?? '') ?: 'Savannah Explorers', $fromEmail, $fromEmail, $attachments);
            ob_end_clean();
            if (!$sent) { echo json_encode(['ok' => false, 'msg' => 'Send failed. Check server mail configuration.']); exit; }
            $note = $body . ($attachNames
                ? "\n\n<p style='font-size:.8rem;color:#888'><strong>Attachments:</strong> " . h(implode(', ', $attachNames)) . '</p>' : '');
            $db->prepare("INSERT INTO ck_notes (ck_folder_id, created_by, note_type, recipients, subject, body, created_at)
                          VALUES (?, ?, 'email_sent', ?, ?, ?, ?)")
               ->execute([$fid, $uid, mb_substr(implode(', ', $addrs), 0, 500), mb_substr($subject, 0, 255), $note, ck_now()]);
            echo json_encode(['ok' => true, 'note_count' => $count($fid)]);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// ── Run the automatic check on one or more folders (JS, no page reload) ───────
// Each folder is dispatched as its own GitHub run, so several checks run in
// parallel and a new one can be started while others are still running.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_check' && !empty($_POST['ajax'])) {
    $ids = array_values(array_unique(array_filter(array_map('intval',
               explode(',', (string)($_POST['ck_ids'] ?? $_POST['ck_id'] ?? ''))))));
    $out = [];
    foreach ($ids as $cid) {
        try {
            $out[$cid] = ck_request_check($db, [$cid], 'manual');
        } catch (Throwable $e) {
            $out[$cid] = ['ok' => false, 'msg' => $e->getMessage()];
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => (bool)$ids, 'results' => (object)$out, 'at' => date('H:i', strtotime(ck_now()))]);
    exit;
}

// ── Actions: set / remove _CK, run the automatic check ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['ck_on', 'ck_off', 'run_check'], true)) {
    try {
        if ($_POST['action'] === 'run_check') {
            // Shown on the row itself (the page reopens scrolled to it, the top flash is out of view).
            $res = ck_request_check($db, [(int)($_POST['ck_id'] ?? 0)], 'manual');
            $_SESSION['ck_row_msg'] = ['id' => (int)($_POST['ck_id'] ?? 0)] + $res;
        } else {
            $token = dropbox_get_access_token();
            $res   = ck_set_marker($db, $token, (int)($_POST['ck_id'] ?? 0), $_POST['action'] === 'ck_on', $uid);
            flash($res['msg'], $res['ok'] ? 'info' : 'error');
        }
    } catch (Throwable $e) {
        flash('Error — nothing was changed: ' . $e->getMessage(), 'error');
    }
    parse_str((string)($_POST['return_qs'] ?? ''), $rq);
    $rq = array_intersect_key($rq, array_flip(['view', 'past', 'other', 'agent']));
    header('Location: ck_tracker.php' . ($rq ? '?' . http_build_query($rq) : '') . '#ck' . (int)($_POST['ck_id'] ?? 0));
    exit;
}

// ── Status poll (JS): is a running check finished? ────────────────────────────
if (isset($_GET['status'])) {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))));
    $out = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT f.id, f.check_requested_at, f.last_check_id,
                                   c.overall, c.n_red, c.n_yellow, c.created_at, c.error
                            FROM ck_folders f LEFT JOIN ck_checks c ON c.id = f.last_check_id
                            WHERE f.id IN ($in)");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $out[$f['id']] = [
                'running' => $f['check_requested_at'] !== null, 'check' => (int)$f['last_check_id'],
                'overall' => $f['overall'], 'red' => (int)$f['n_red'], 'yellow' => (int)$f['n_yellow'],
                'at' => $f['created_at'] ? date('d M H:i', strtotime($f['created_at'])) : '',
                'error' => $f['error'],
            ];
        }
    }
    header('Content-Type: application/json');
    echo json_encode($out);
    exit;
}
$rowMsg = $_SESSION['ck_row_msg'] ?? null;
unset($_SESSION['ck_row_msg']);

// ── Scan Dropbox (records any change since the last scan) ─────────────────────
$scanError = '';
try {
    ck_scan($db, dropbox_get_access_token());
} catch (Throwable $e) {
    $scanError = 'Could not read 001_Safari from Dropbox — showing the last known state. ' . $e->getMessage();
}

// ── Data ──────────────────────────────────────────────────────────────────────
$folders = $db->query("SELECT f.*, u.full_name AS ck_by_name,
                              c.overall AS chk_overall, c.n_red AS chk_red, c.n_yellow AS chk_yellow,
                              c.created_at AS chk_at, c.error AS chk_error,
                              (SELECT COUNT(*) FROM ck_notes n WHERE n.ck_folder_id = f.id) AS note_count,
                              -- \"type|text\": the body for a note, the subject for a sent email.
                              (SELECT CONCAT(n2.note_type, '|', COALESCE(IF(n2.note_type = 'manual', n2.body, n2.subject), ''))
                                 FROM ck_notes n2 WHERE n2.ck_folder_id = f.id
                                 ORDER BY n2.created_at DESC, n2.id DESC LIMIT 1) AS last_note_raw,
                              (SELECT u3.full_name FROM ck_notes n3 LEFT JOIN users u3 ON u3.id = n3.created_by
                                 WHERE n3.ck_folder_id = f.id
                                 ORDER BY n3.created_at DESC, n3.id DESC LIMIT 1) AS last_note_by
                       FROM ck_folders f
                       LEFT JOIN users u ON u.id = f.ck_by
                       LEFT JOIN ck_checks c ON c.id = f.last_check_id
                       WHERE f.gone = 0")->fetchAll(PDO::FETCH_ASSOC);

// Requests per folder: a private safari by practice_code, a GRP by group_folder.
$byPc = []; $byGrp = [];
$rq = $db->query("SELECT r.id, r.customer_name, r.practice_code, r.group_folder, r.pax, a.name AS agent_name
                  FROM requests r LEFT JOIN agents a ON a.id = r.agent_id
                  WHERE (r.group_folder IS NOT NULL AND r.group_folder <> '')
                     OR r.dropbox_url LIKE '%001_Safari%'");
foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (trim($r['group_folder'] ?? '') !== '') $byGrp[mb_strtolower(trim($r['group_folder']))][] = $r;
    elseif (trim($r['practice_code'] ?? '') !== '') $byPc[mb_strtolower(trim($r['practice_code']))][] = $r;
}
$byStem = [];
foreach ($byPc as $k => $list) $byStem[dropbox_folder_stem($k)] = array_merge($byStem[dropbox_folder_stem($k)] ?? [], $list);

$today = new DateTimeImmutable(ck_now('Y-m-d'));
$daysFrom = function (?string $dt) use ($today): ?int {
    if (!$dt) return null;
    return (int)$today->diff(new DateTimeImmutable(substr($dt, 0, 10)))->format('%r%a');
};

$rows = [];
foreach ($folders as $f) {
    $key  = mb_strtolower($f['folder_name']);
    $reqs = $byGrp[$key] ?? $byPc[$key] ?? $byStem[dropbox_folder_stem($f['folder_name'])] ?? [];
    // Sales person: the request's agent when all matched requests agree, else the folder's (…) tag.
    $reqAgents = array_values(array_unique(array_filter(array_map(fn($q) => (string)($q['agent_name'] ?? ''), $reqs))));
    $agent = count($reqAgents) === 1 ? $reqAgents[0] : ck_agent_from_name($f['folder_name']);

    $toArrival = $daysFrom($f['start_date']);           // + = days ahead, − = already started
    $band = null;
    if ($toArrival !== null && $toArrival >= 0) $band = $toArrival < 60 ? 'red' : ($toArrival <= 90 ? 'amber' : 'grey');

    [$nType, $nText] = array_pad(explode('|', (string)($f['last_note_raw'] ?? ''), 2), 2, '');
    $rows[] = $f + [
        'last_note'  => $nType === 'email_sent' ? '📧 ' . $nText : $nText,
        'reqs'       => $reqs,
        'agent'      => $agent,
        'is_grp'     => stripos($f['folder_name'], 'GRP') !== false || isset($byGrp[$key]),
        'other'      => ck_is_other_destination($f['folder_name']),
        'to_arrival' => $toArrival,
        'band'       => $band,
        'in_stage'   => $f['stage_since'] ? -$daysFrom($f['stage_since']) : null,
        'waiting'    => $f['booking_done_at'] ? -$daysFrom($f['booking_done_at']) : null,
        'missing'    => in_array($f['stage'], CK_DONE_STAGES, true) && !(int)$f['has_ck'],
    ];
}

// Agents present (for the filter). Sellers default to their own name.
$agents = array_values(array_unique(array_filter(array_column($rows, 'agent'))));
natcasesort($agents);
$myAgent = '';
if (isLeadsRestricted()) {
    $st = $db->prepare("SELECT a.name FROM users u JOIN agents a ON a.id = u.agent_id WHERE u.id = ?");
    $st->execute([$uid]);
    $myAgent = (string)($st->fetchColumn() ?: '');
}
$agentF = array_key_exists('agent', $_GET) ? trim($_GET['agent']) : $myAgent;

// Base filter (past / other destinations / agent), then the view.
$base = array_filter($rows, function ($r) use ($showPast, $showOther, $agentF) {
    if (!$showPast && $r['to_arrival'] !== null && $r['to_arrival'] < 0) return false;
    if (!$showOther && $r['other']) return false;
    if ($agentF !== '' && strcasecmp($r['agent'], $agentF) !== 0) return false;
    return true;
});
$counts = ['red' => 0, 'amber' => 0, 'grey' => 0, 'booking' => 0, 'done' => 0, 'chkred' => 0];
foreach ($base as $r) {
    if ($r['missing'] && $r['band']) $counts[$r['band']]++;
    if ($r['missing'] && $r['chk_overall'] === 'red') $counts['chkred']++;
    if (in_array($r['stage'], CK_BOOKING_STAGES, true)) $counts['booking']++;
    if ((int)$r['has_ck']) $counts['done']++;
}
$list = array_filter($base, fn($r) => match ($view) {
    'missing' => $r['missing'],
    'chkred'  => $r['missing'] && $r['chk_overall'] === 'red',
    'booking' => in_array($r['stage'], CK_BOOKING_STAGES, true),
    'done'    => (bool)(int)$r['has_ck'],
    default   => true,
});
usort($list, fn($a, $b) => [$a['start_date'] ?? '9999-12-31', $a['folder_name']] <=> [$b['start_date'] ?? '9999-12-31', $b['folder_name']]);

// History for the listed folders.
$events = [];
if ($list) {
    $ids = array_map(fn($r) => (int)$r['id'], $list);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $db->prepare("SELECT e.*, u.full_name AS user_name FROM ck_events e LEFT JOIN users u ON u.id = e.user_id
                         WHERE e.ck_folder_id IN ($in) ORDER BY e.created_at DESC, e.id DESC");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) $events[(int)$e['ck_folder_id']][] = $e;
}

$STAGE_STYLE = [
    'Balance-Cash' => ['#1a3a5c', '#EAF1F8'], 'Balance' => ['#1a3a5c', '#EAF1F8'],
    'Deposit'      => ['#8a6d3b', '#fcf3e3'], 'Paid'    => ['#1A6B3A', '#E6F4EA'],
    'Progress'     => ['#6B7280', '#F3F4F6'], 'Confirmed' => ['#6B7280', '#F3F4F6'],
    'Provisional'  => ['#B26A00', '#FFF4E0'], 'Cancelled' => ['#a33', '#f7dede'],
];
$CHK_STYLE = [
    'green'  => ['#1A6B3A', '#E6F4EA', '🟢'], 'yellow' => ['#8a6d3b', '#fcf3e3', '🟡'],
    'red'    => ['#a33', '#f7dede', '🔴'],    'grey'   => ['#6B7280', '#F3F4F6', '⚪'],
    'error'  => ['#6B7280', '#F3F4F6', '⚠'],
];
$qsKeep = array_filter(['view' => $view, 'past' => $showPast ? '1' : '', 'other' => $showOther ? '1' : ''],
                       fn($v) => $v !== '');
// An explicit (even empty = "All") agent choice is kept; otherwise sellers fall back to their own.
if (array_key_exists('agent', $_GET)) $qsKeep['agent'] = $agentF;
$link = fn(array $over) => 'ck_tracker.php?' . http_build_query(array_filter(array_merge($qsKeep, $over), fn($v) => $v !== null));
$fmtD = fn(?string $d) => $d ? date('d M y', strtotime($d)) : '';
$evLabel = function (array $e): string {
    return match ($e['event']) {
        'first_seen' => 'Tracking started',
        'stage'      => 'Stage ' . ($e['from_value'] ?: '—') . ' → ' . ($e['to_value'] ?: '—'),
        'ck_set'     => '✅ CK set',
        'ck_removed' => '↩ CK removed',
        'renamed'    => 'Renamed → ' . $e['to_value'],
        'gone'       => 'Left 001_Safari',
        'back'       => 'Back in 001_Safari',
        'check'      => 'Automatic check: ' . strtoupper((string)$e['to_value']),
        default      => $e['event'],
    };
};

$extra_css = '
.ck-tiles{display:flex;gap:10px;flex-wrap:wrap;margin:6px 0 16px}
.ck-tile{flex:1;min-width:150px;background:#fff;border:1px solid var(--grey-lt);border-radius:10px;padding:10px 14px;text-decoration:none;color:inherit;border-left-width:5px}
.ck-tile b{display:block;font-size:1.5rem;line-height:1.2}
.ck-tile span{font-size:.72rem;color:var(--grey-mid)}
.ck-tile.red{border-left-color:#C0211B}.ck-tile.red b{color:#C0211B}
.ck-tile.amber{border-left-color:#E87722}.ck-tile.amber b{color:#E87722}
.ck-tile.grey{border-left-color:#9CA3AF}
.ck-tile.blue{border-left-color:#1a3a5c}
.ck-tile.green{border-left-color:#1A6B3A}.ck-tile.green b{color:#1A6B3A}
.ck-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.ck-tabs a{font-size:.8rem;font-weight:600;text-decoration:none;color:var(--grey-mid);padding:6px 12px;border-radius:16px;border:1px solid var(--grey-lt);background:#fff}
.ck-tabs a.on{color:#fff;background:#C0211B;border-color:#C0211B}
.ck-bar label{font-size:.78rem;color:var(--grey-dk);display:flex;gap:4px;align-items:center}
.ck-table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden}
.ck-table th{text-align:left;font-size:.66rem;text-transform:uppercase;letter-spacing:.05em;color:var(--grey-mid);padding:8px 10px;border-bottom:1px solid var(--grey-lt)}
.ck-table td{padding:8px 10px;border-bottom:1px solid var(--grey-lt);font-size:.8rem;vertical-align:top}
.ck-table tr.b-red td:first-child{box-shadow:inset 4px 0 #C0211B}
.ck-table tr.b-amber td:first-child{box-shadow:inset 4px 0 #E87722}
.ck-arr b{display:block;white-space:nowrap}
.ck-arr small{font-size:.7rem;font-weight:700}
.ck-arr .red{color:#C0211B}.ck-arr .amber{color:#E87722}.ck-arr .grey{color:var(--grey-mid)}
.ck-cust{font-weight:700}
.ck-folder{font-family:monospace;font-size:.7rem;color:var(--grey-mid);word-break:break-all;margin-top:2px}
.ck-tag{font-size:.66rem;font-weight:700;border-radius:6px;padding:2px 7px;white-space:nowrap;display:inline-block}
.ck-grp{font-size:.62rem;color:#8a6d3b;background:#fcf3e3;border-radius:6px;padding:1px 5px;margin-left:4px}
.ck-sub{font-size:.7rem;color:var(--grey-mid);margin-top:3px}
.ck-wait{font-weight:700;white-space:nowrap}
.ck-wait.late{color:#C0211B}
.ck-actions a{font-size:.7rem;text-decoration:none;margin-right:8px;white-space:nowrap}
.ck-actions form{display:inline}
.ck-btn{font-size:.72rem;font-weight:700;border-radius:6px;padding:4px 10px;cursor:pointer;border:1px solid #1A6B3A;background:#1A6B3A;color:#fff;white-space:nowrap}
.ck-btn.run{background:#fff;color:#1a3a5c;border-color:#b9cbe0;font-weight:600}
.ck-rep{font-size:.7rem;text-decoration:none;margin-left:6px;white-space:nowrap}
.ck-chk{font-size:.68rem;font-weight:700;border-radius:6px;padding:2px 7px;white-space:nowrap;text-decoration:none;display:inline-block}
.ck-btn.off{background:#fff;color:#a33;border-color:#e4b9b9;font-weight:600}
.ck-hist summary{font-size:.7rem;color:var(--grey-mid);cursor:pointer;margin-top:4px}
.ck-hist ul{list-style:none;margin:4px 0 0;padding:0;font-size:.7rem;color:var(--grey-dk)}
.ck-hist li{padding:1px 0}
.ck-hist li small{color:var(--grey-mid)}
.ck-inprog{font-size:.68rem;font-weight:700;color:#1a3a5c;background:#eef3f9;border-radius:6px;padding:2px 7px;display:inline-block;margin-bottom:3px;white-space:nowrap}
.ck-result.busy a{pointer-events:none;opacity:.4}
.ck-result.busy .ck-rep{display:none}
.ck-lastnote{font-size:.72rem;margin-top:4px;color:var(--grey-dk)}
.ck-lastnote a{color:inherit;text-decoration:none}
.ck-lastnote small{color:var(--grey-mid)}
.ck-ncount{background:#fff3cd;color:#856404;font-weight:700;border-radius:8px;padding:0 6px;font-size:.66rem}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto}
.modal-overlay.hidden{display:none}
.modal-box{background:#fff;border-radius:10px;box-shadow:0 8px 40px rgba(0,0,0,.2);width:100%}
.modal-header{padding:15px 24px;border-bottom:1px solid var(--grey-lt);display:flex;align-items:center;justify-content:space-between}
.modal-header h3{font-family:"Merriweather",serif;font-size:.95rem;font-weight:700;margin:0;color:var(--black)}
.modal-body{padding:22px 24px}
.modal-footer{padding:14px 24px;border-top:1px solid var(--grey-lt);display:flex;justify-content:flex-end;gap:10px}
.modal-close{background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--grey-mid);line-height:1;padding:0}
.m-label{font-size:.72rem;font-weight:700;color:var(--grey-dk);display:block;margin-bottom:4px}
.m-input{width:100%;padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:"Open Sans",sans-serif;font-size:.82rem;color:var(--black);box-sizing:border-box}
.m-input:focus{outline:none;border-color:var(--red)}
.note-card{background:var(--off-white);border-radius:7px;padding:12px 16px;margin-bottom:10px;border-left:3px solid #e0a800}
.note-card.email-sent{border-left-color:#1a3a5c}
.attach-chip{display:inline-flex;align-items:center;gap:4px;background:var(--off-white);border:1px solid var(--grey-lt);border-radius:4px;padding:2px 8px;font-size:.72rem;margin:2px}
.attach-chip button{background:none;border:none;cursor:pointer;color:var(--red);font-size:.9rem;line-height:1;padding:0 1px}
@media (max-width:760px){.ck-table thead{display:none}.ck-table td{display:block;border:0;padding:4px 10px}.ck-table tr{display:block;border-bottom:1px solid var(--grey-lt);padding:6px 0}}
';
include 'includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
  <h2>✅ CK tracker <small style="font-size:.75rem;font-weight:400;color:var(--grey-mid)">— confirmed bookings in 001_Safari, from booking to CK</small></h2>
</div>

<?php if ($scanError): ?>
  <div style="background:#fbeaea;border-left:4px solid #a33;color:#a33;padding:10px 14px;border-radius:8px;margin-bottom:12px;font-size:.82rem"><?= h($scanError) ?></div>
<?php endif; ?>

<div class="ck-tiles">
  <a class="ck-tile red"   href="<?= h($link(['view' => 'missing'])) ?>"><b><?= $counts['red'] ?></b><span>No CK · arrival &lt; 60 days</span></a>
  <a class="ck-tile amber" href="<?= h($link(['view' => 'missing'])) ?>"><b><?= $counts['amber'] ?></b><span>No CK · 60–90 days (penalties)</span></a>
  <a class="ck-tile grey"  href="<?= h($link(['view' => 'missing'])) ?>"><b><?= $counts['grey'] ?></b><span>No CK · more than 90 days</span></a>
  <a class="ck-tile blue"  href="<?= h($link(['view' => 'booking'])) ?>"><b><?= $counts['booking'] ?></b><span>Still in booking (PROGRESS)</span></a>
  <a class="ck-tile green" href="<?= h($link(['view' => 'done'])) ?>"><b><?= $counts['done'] ?></b><span>CK done</span></a>
  <a class="ck-tile red"   href="<?= h($link(['view' => 'chkred'])) ?>"><b><?= $counts['chkred'] ?></b><span>No CK · automatic check RED</span></a>
</div>

<div class="ck-bar">
  <div class="ck-tabs" style="display:flex;gap:6px;flex-wrap:wrap">
    <?php foreach ($VIEWS as $k => $label): ?>
      <a href="<?= h($link(['view' => $k])) ?>" class="<?= $view === $k ? 'on' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>
  <button type="button" id="ckRunSel" class="ck-btn run" disabled
          title="Run the SafariCheck on every ticked row. Each runs in parallel (about 2 minutes); results appear in their rows.">↻ Re-check selected (<span id="ckSelN">0</span>)</button>
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-left:auto">
    <input type="hidden" name="view" value="<?= h($view) ?>">
    <label>Sales
      <select name="agent" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach ($agents as $a): ?>
          <option value="<?= h($a) ?>" <?= strcasecmp($a, $agentF) === 0 ? 'selected' : '' ?>><?= h($a) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label title="Trips that already started"><input type="checkbox" name="past" value="1" <?= $showPast ? 'checked' : '' ?> onchange="this.form.submit()"> Started trips</label>
    <label title="Kenya, Uganda, Namibia, South Africa, Madagascar — left out of the old Missing CK list"><input type="checkbox" name="other" value="1" <?= $showOther ? 'checked' : '' ?> onchange="this.form.submit()"> Other destinations</label>
  </form>
</div>

<?php if (!$list): ?>
  <p style="color:var(--grey-mid);padding:20px">Nothing to show<?= $view === 'missing' ? ' — every booked folder has its CK 🎉' : '' ?>.</p>
<?php else: ?>
<table class="ck-table">
  <thead><tr>
    <th><input type="checkbox" id="ckSelAll" title="Select all rows"> Arrival</th><th>Booking</th><th>Sales</th><th>Stage</th><th>Check</th>
    <th><?= $view === 'done' ? 'CK' : 'Waiting for CK' ?></th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($list as $r):
      $id  = (int)$r['id'];
      $rel = ltrim(CK_BASE, '/') . '/' . $r['folder_name'];
      $st  = $STAGE_STYLE[$r['stage']] ?? ['#6B7280', '#F3F4F6'];
  ?>
    <tr id="ck<?= $id ?>" class="<?= $r['missing'] && $r['band'] ? 'b-' . $r['band'] : '' ?>">
      <td class="ck-arr">
        <input type="checkbox" class="ck-sel" value="<?= $id ?>" title="Select for Re-check selected">
        <b><?= h($fmtD($r['start_date'])) ?: '—' ?></b>
        <?php if ($r['to_arrival'] !== null): ?>
          <small class="<?= h($r['band'] ?? 'grey') ?>"><?= $r['to_arrival'] >= 0 ? 'in ' . $r['to_arrival'] . ' d' : 'started' ?></small>
        <?php endif; ?>
      </td>
      <td>
        <span class="ck-cust"><?= h(ck_customer_label($r['folder_name'])) ?></span><?php if ($r['is_grp']): ?><span class="ck-grp">GRP</span><?php endif; ?>
        <div class="ck-folder"><?= h($r['folder_name']) ?></div>
        <div class="ck-actions" style="margin-top:3px">
          <?php foreach ($r['reqs'] as $q): ?>
            <a href="request_view.php?id=<?= (int)$q['id'] ?>" target="_blank">🔗 <?= h($q['customer_name']) ?><?= (int)$q['pax'] ? ' · ' . (int)$q['pax'] . ' pax' : '' ?></a>
          <?php endforeach; ?>
          <a href="savannah://open?path=<?= h(implode('/', array_map('rawurlencode', explode('/', $rel)))) ?>" title="Open in Windows Explorer">📂 Open</a>
          <a href="#" data-copy="<?= h('%DROPBOX_HOME%\\' . str_replace('/', '\\', $rel)) ?>" onclick="copyPath(this);return false" title="Copy Windows path">📋 Copy path</a>
        </div>
        <div class="ck-lastnote" data-ck="<?= $id ?>">
          <?php if ((int)$r['note_count'] > 0): ?>
            <a href="#" onclick="openNotes(<?= $id ?>);return false" title="<?= h(strip_tags($r['last_note'])) ?>">
              <span class="ck-ncount"><?= (int)$r['note_count'] ?></span> <?= h(mb_strimwidth(strip_tags($r['last_note']), 0, 70, '…')) ?></a>
            <?php if ($r['last_note_by']): ?><small>— <?= h($r['last_note_by']) ?></small><?php endif; ?>
          <?php endif; ?>
        </div>
      </td>
      <td><?= h($r['agent']) ?: '<span style="color:var(--grey-mid)">—</span>' ?></td>
      <td>
        <?php if ($r['stage']): ?>
          <span class="ck-tag" style="color:<?= $st[0] ?>;background:<?= $st[1] ?>"><?= h($r['stage']) ?></span>
        <?php else: ?><span class="ck-tag" style="color:#a33;background:#f7dede" title="No status tag in the folder name">no tag</span><?php endif; ?>
        <div class="ck-sub">
          <?= $r['in_stage'] !== null ? 'for ' . $r['in_stage'] . ' d' : '<span title="Already in this stage when tracking started">before tracking</span>' ?>
        </div>
      </td>
      <?php $running = $r['check_requested_at'] && (strtotime(ck_now()) - strtotime($r['check_requested_at'])) / 60 <= 10; ?>
      <td class="ck-chkcell" data-ck="<?= $id ?>">
        <?php if ($running): ?><div class="ck-inprog">⏳ Report in progress</div><?php endif; ?>
        <div class="ck-result<?= $running ? ' busy' : '' ?>">
        <?php if ($r['chk_overall']): $cs = $CHK_STYLE[$r['chk_overall']] ?? $CHK_STYLE['grey']; ?>
          <a class="ck-chk" style="color:<?= $cs[0] ?>;background:<?= $cs[1] ?>" href="ck_report.php?id=<?= (int)$r['last_check_id'] ?>" target="_blank"
             title="<?= h($r['chk_error'] ?: 'Open the SafariCheck report in a new tab') ?>"><?= $cs[2] ?> <?= h(strtoupper($r['chk_overall'])) ?></a>
          <a class="ck-rep" href="ck_report.php?id=<?= (int)$r['last_check_id'] ?>" target="_blank">📄 Open report</a>
          <div class="ck-sub">
            <?php if ($r['chk_red'] || $r['chk_yellow']): ?><?= (int)$r['chk_red'] ?> red · <?= (int)$r['chk_yellow'] ?> to check<br><?php endif; ?>
            <?= h(date('d M H:i', strtotime($r['chk_at']))) ?>
          </div>
        <?php else: ?>
          <span class="ck-sub">not checked</span>
        <?php endif; ?>
        </div>
        <?php if ($r['check_requested_at']):
            $age = (strtotime(ck_now()) - strtotime($r['check_requested_at'])) / 60; ?>
          <?php if ($age <= 10): ?>
            <div class="ck-sub ck-running" data-ck="<?= $id ?>" data-check="<?= (int)$r['last_check_id'] ?>" style="color:#1a3a5c">⏳ check running (started <?= h(date('H:i', strtotime($r['check_requested_at']))) ?>) — the result appears here</div>
          <?php else: ?>
            <div class="ck-sub" style="color:#a33" title="Requested <?= h($r['check_requested_at']) ?>">⚠ check did not finish — try again</div>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($rowMsg && $rowMsg['id'] === $id && !$rowMsg['ok']): ?>
          <div class="ck-sub" style="color:#a33"><?= h($rowMsg['msg']) ?></div>
        <?php endif; ?>
      </td>
      <td>
        <?php if ((int)$r['has_ck']): ?>
          <span style="color:#1A6B3A;font-weight:700">✅ CK</span>
          <div class="ck-sub"><?= $r['ck_at'] ? h($fmtD($r['ck_at'])) . ($r['ck_by_name'] ? ' · ' . h($r['ck_by_name']) : ' · in Dropbox') : 'before tracking' ?></div>
        <?php elseif ($r['missing']): ?>
          <?php if ($r['waiting'] !== null): ?>
            <span class="ck-wait <?= $r['waiting'] > 7 ? 'late' : '' ?>"><?= $r['waiting'] ?> d</span>
            <div class="ck-sub">booking done <?= h($fmtD($r['booking_done_at'])) ?></div>
          <?php else: ?>
            <span class="ck-sub" title="Booking was already done when tracking started">booking done before tracking</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="ck-sub">—</span>
        <?php endif; ?>
      </td>
      <td class="ck-actions" style="white-space:nowrap">
        <form method="post" onsubmit="return confirm(<?= h(json_encode(((int)$r['has_ck'] ? 'Remove the CK from ' : 'Set CK on ') . $r['folder_name'] . '?\n\nThe Dropbox folder will be renamed.')) ?>)">
          <input type="hidden" name="action" value="<?= (int)$r['has_ck'] ? 'ck_off' : 'ck_on' ?>">
          <input type="hidden" name="ck_id" value="<?= $id ?>">
          <input type="hidden" name="return_qs" value="<?= h(http_build_query($qsKeep)) ?>">
          <?php if ((int)$r['has_ck']): ?>
            <button class="ck-btn off" type="submit">↩ Remove CK</button>
          <?php else: ?>
            <button class="ck-btn" type="submit">✅ Set CK</button>
          <?php endif; ?>
        </form>
        <form method="post" style="margin-top:4px" class="ck-runform">
          <input type="hidden" name="action" value="run_check">
          <input type="hidden" name="ck_id" value="<?= $id ?>">
          <input type="hidden" name="return_qs" value="<?= h(http_build_query($qsKeep)) ?>">
          <button class="ck-btn run" type="submit" title="Run the SafariCheck again on this folder now (about 2 minutes). To see the last result, click the coloured badge / Open report."><?= $r['chk_overall'] ? '↻ Re-check' : '▶ Run check' ?></button>
        </form>
        <div style="margin-top:4px;display:flex;gap:4px">
          <button type="button" class="ck-btn run" title="Notes on this booking (notes and emails sent from here)" onclick="openNotes(<?= $id ?>)">📝 Note</button>
          <button type="button" class="ck-btn run" title="Email the booking team or a colleague — saved as a note" onclick="openSend(<?= $id ?>, <?= h(json_encode(ck_customer_label($r['folder_name']))) ?>, '', <?= h(json_encode('CK ' . ck_customer_label($r['folder_name']) . ($r['start_date'] ? ' — arrival ' . $fmtD($r['start_date']) : ''))) ?>)">✉ Mail</button>
        </div>
        <?php if (!empty($events[$id])): ?>
          <details class="ck-hist">
            <summary>History (<?= count($events[$id]) ?>)</summary>
            <ul>
              <?php foreach ($events[$id] as $e): ?>
                <li><small><?= h(date('d M y H:i', strtotime($e['created_at']))) ?></small> — <?= h($evLabel($e)) ?><?php if ($e['user_name']): ?> <small>· <?= h($e['user_name']) ?></small><?php endif; ?></li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p style="font-size:.72rem;color:var(--grey-mid);margin-top:10px">
  Tracking started on the first visit to this page: stages and CKs that already existed show “before tracking”.
  Changes made in Dropbox are picked up at the next visit (or by the scheduled scan).
</p>
<?php endif; ?>

<?php
// Email: no templates (they need a request); To suggests booking + colleagues.
$templates = [];
$send_to_suggestions = ['operations@savannahexplorers.com' => 'Booking / Operations'];
foreach ($db->query("SELECT full_name, email FROM users
                     WHERE is_active = 1 AND email IS NOT NULL AND email <> '' ORDER BY full_name") as $u) {
    $send_to_suggestions[strtolower(trim($u['email']))] ??= $u['full_name'];
}
$send_ajax_url = 'ck_tracker.php';
include 'includes/send_modal.php';
?>

<!-- ── Notes modal ─────────────────────────────────────────────────────────── -->
<div class="modal-overlay hidden" id="notesOverlay" style="display:none">
  <div class="modal-box" style="max-width:680px">
    <div class="modal-header">
      <h3>CK notes — <span id="notesCustomer"></span></h3>
      <button type="button" class="modal-close" onclick="closeNotes()">&times;</button>
    </div>
    <div class="modal-body">
      <div style="margin-bottom:14px">
        <label class="m-label">Add a note (e.g. "asked booking to fix the Serengeti dates")</label>
        <textarea id="newNote" class="m-input" rows="2" style="resize:vertical"></textarea>
        <div style="text-align:right;margin-top:8px;display:flex;gap:6px;justify-content:flex-end">
          <button type="button" class="btn btn-outline btn-sm" onclick="closeNotes();openSend(notesId, rowLabel(notesId), '', 'CK ' + rowLabel(notesId))">✉ Send an email instead</button>
          <button type="button" class="btn btn-red btn-sm" id="btnAddNote" onclick="saveNote()">＋ Add note</button>
        </div>
      </div>
      <div id="notesList" style="border-top:1px solid var(--grey-lt);padding-top:14px;min-height:60px"></div>
    </div>
  </div>
</div>

<script>
// ── Notes on a folder: manual notes + emails sent from this page ──────────────
var notesId = 0, notesById = {};
var CURRENT_USER = <?= json_encode($cu['full_name'] ?? '') ?>;
function escN(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
function rowLabel(id) { var c = document.querySelector('#ck' + id + ' .ck-cust'); return c ? c.textContent : ''; }
function notesPost(params) {
  return fetch('ck_tracker.php', {
    method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams(params).toString()
  }).then(function (r) { return r.json(); });
}
document.querySelector('#notesOverlay .modal-box').addEventListener('click', function (e) { e.stopPropagation(); });
function closeNotes() { document.getElementById('notesOverlay').style.display = 'none'; }
function openNotes(id) {
  notesId = id;
  document.getElementById('notesCustomer').textContent = rowLabel(id);
  document.getElementById('newNote').value = '';
  document.getElementById('notesList').innerHTML = '<p style="color:var(--grey-mid);text-align:center;padding:16px">Loading…</p>';
  document.getElementById('notesOverlay').style.display = 'flex';
  loadNotes();
}
function loadNotes() {
  notesPost({action: 'get_notes', ck_id: notesId}).then(function (d) {
    var list = document.getElementById('notesList');
    var notes = d.ok ? d.notes : [];
    if (notes.length) {
      var top = notes[0];
      setLastNote(notesId, notes.length, top.note_type === 'email_sent' ? '📧 ' + (top.subject || '') : (top.body || ''), top.user_name || '');
    } else {
      setLastNote(notesId, 0);
    }
    if (!notes.length) { list.innerHTML = '<p style="color:var(--grey-mid);text-align:center;padding:16px">No notes yet.</p>'; return; }
    notesById = {};
    list.innerHTML = notes.map(function (n) {
      notesById[n.id] = n;
      var isEmail = n.note_type === 'email_sent';
      var meta = '<strong style="color:var(--grey-dk)">👤 ' + escN(n.user_name || 'Unknown user') + '</strong> · ' + escN(n.created_at);
      var tools = isEmail ? '' :
        '<button type="button" title="Edit note" onclick="editNote(' + n.id + ')" style="background:none;border:none;color:var(--grey-dk);cursor:pointer;font-size:.85rem">✎</button>' +
        '<button type="button" title="Delete note" onclick="delNote(' + n.id + ')" style="background:none;border:none;color:var(--red);cursor:pointer;font-size:.9rem">×</button>';
      // Manual notes are plain text; a sent email's body is its HTML.
      var body = isEmail ? (n.body || '') : escN(n.body).replace(/\n/g, '<br>');
      return '<div class="note-card ' + (isEmail ? 'email-sent' : '') + '">' +
        '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px;gap:8px">' +
          '<span class="badge" style="background:' + (isEmail ? '#e8eef5;color:#1a3a5c' : '#fff3cd;color:#856404') + '">' +
            (isEmail ? '📧 Email sent' : '📝 Note') + '</span>' +
          '<span style="display:flex;gap:8px;align-items:center"><small style="color:var(--grey-mid)">' + meta + '</small>' + tools + '</span>' +
        '</div>' +
        (isEmail && n.recipients ? '<div style="font-size:.74rem;color:var(--grey-mid)">To: ' + escN(n.recipients) + '</div>' : '') +
        (n.subject ? '<strong style="font-size:.82rem">' + escN(n.subject) + '</strong>' : '') +
        '<div id="noteBody' + n.id + '" style="font-size:.8rem;color:var(--grey-dk);margin-top:4px;max-height:220px;overflow-y:auto">' + body + '</div>' +
      '</div>';
    }).join('');
  });
}
function saveNote() {
  var body = document.getElementById('newNote').value.trim();
  if (!body) return;
  var btn = document.getElementById('btnAddNote');
  btn.disabled = true;
  notesPost({action: 'add_note', ck_id: notesId, body: body}).then(function (d) {
    btn.disabled = false;
    if (!d.ok) { alert(d.msg || 'Could not save the note.'); return; }
    document.getElementById('newNote').value = '';
    loadNotes();
  }).catch(function (e) { btn.disabled = false; alert('Error: ' + e.message); });
}
function delNote(id) {
  if (!confirm('Delete this note?')) return;
  notesPost({action: 'delete_note', note_id: id}).then(loadNotes);
}
function editNote(id) {
  var n = notesById[id], box = document.getElementById('noteBody' + id);
  if (!n || !box) return;
  box.style.maxHeight = 'none';
  box.innerHTML = '<textarea class="m-input" rows="3" style="resize:vertical"></textarea>' +
    '<div style="margin-top:6px;display:flex;gap:6px;justify-content:flex-end">' +
      '<button type="button" class="btn btn-outline btn-sm" onclick="loadNotes()">Cancel</button>' +
      '<button type="button" class="btn btn-red btn-sm">Save</button></div>';
  var ta = box.querySelector('textarea');
  ta.value = n.body || ''; ta.focus();
  box.querySelector('.btn-red').onclick = function () {
    var body = ta.value.trim();
    if (!body) { alert('The note cannot be empty — use × to delete it.'); return; }
    this.disabled = true;
    notesPost({action: 'edit_note', note_id: id, body: body}).then(function (d) {
      if (!d.ok) alert(d.msg || 'Could not save the note.');
      loadNotes();
    });
  };
}
// The row's latest-note line under the booking name.
function setLastNote(id, count, text, by) {
  var el = document.querySelector('.ck-lastnote[data-ck="' + id + '"]');
  if (!el) return;
  if (!count) { el.innerHTML = ''; return; }
  var plain = String(text || '').replace(/<[^>]*>/g, '');
  el.innerHTML = '<a href="#" onclick="openNotes(' + id + ');return false" title="' + escN(plain) + '">' +
    '<span class="ck-ncount">' + count + '</span> ' + escN(plain.length > 70 ? plain.substring(0, 69) + '…' : plain) + '</a>' +
    (by ? ' <small>— ' + escN(by) + '</small>' : '');
}
// After an email is sent from the Mail button: it is the row's latest note.
window.onEmailSent = function (id, subject, d) {
  if (d && d.note_count) setLastNote(id, d.note_count, '📧 ' + subject, CURRENT_USER);
};

function copyPath(el) {
  var t = el.getAttribute('data-copy');
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(t).then(function () { flashCopied(el); }, function () { fallbackCopy(t, el); });
  } else { fallbackCopy(t, el); }
}
function fallbackCopy(t, el) {
  var ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta);
  ta.select(); try { document.execCommand('copy'); flashCopied(el); } catch (e) {} document.body.removeChild(ta);
}
// Automatic checks: start one (row button) or many (ticked rows) without
// reloading the page; each running row is polled and updated in place when
// its result is in, so more checks can be started meanwhile.
(function () {
  var STYLE = <?= json_encode($CHK_STYLE, JSON_UNESCAPED_UNICODE) ?>;
  var running = {};        // ck id -> {prev: last check id before the run, since: ms}
  var timer = null;
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function cell(id) { return document.querySelector('.ck-chkcell[data-ck="' + id + '"]'); }
  function note(id, html, color) {
    var c = cell(id); if (!c) return;
    var n = c.querySelector('.ck-running, .ck-note');
    if (!n) { n = document.createElement('div'); c.appendChild(n); }
    n.className = 'ck-sub ck-note'; n.style.color = color || '#1a3a5c'; n.innerHTML = html;
  }
  // While a check runs the old report is not the current one: grey out its
  // badge, hide "Open report" and show "Report in progress".
  function setBusy(id, on) {
    var c = cell(id); if (!c) return;
    c.querySelector('.ck-result').classList.toggle('busy', on);
    var p = c.querySelector('.ck-inprog');
    if (on && !p) {
      p = document.createElement('div'); p.className = 'ck-inprog'; p.textContent = '⏳ Report in progress';
      c.insertBefore(p, c.firstChild);
    } else if (!on && p) { p.remove(); }
  }
  function showResult(id, s) {
    var c = cell(id); if (!c) return;
    setBusy(id, false);
    var st = STYLE[s.overall] || STYLE.grey;
    c.querySelector('.ck-result').innerHTML =
      '<a class="ck-chk" style="color:' + st[0] + ';background:' + st[1] + '" href="ck_report.php?id=' + s.check +
      '" target="_blank" title="' + esc(s.error || 'Open the SafariCheck report in a new tab') + '">' + st[2] + ' ' +
      esc(String(s.overall || '').toUpperCase()) + '</a> <a class="ck-rep" href="ck_report.php?id=' + s.check +
      '" target="_blank">📄 Open report</a><div class="ck-sub">' +
      (s.red || s.yellow ? s.red + ' red · ' + s.yellow + ' to check<br>' : '') + esc(s.at) + '</div>';
    note(id, '✓ new result', '#1A6B3A');
  }
  function poll() {
    var ids = Object.keys(running);
    if (!ids.length) { clearInterval(timer); timer = null; return; }
    fetch('ck_tracker.php?status=1&ids=' + ids.join(','), {credentials: 'same-origin'})
      .then(function (r) { return r.json(); })
      .then(function (st) {
        ids.forEach(function (id) {
          var s = st[id], r = running[id];
          if (s && (!s.running || s.check !== r.prev)) {
            delete running[id];
            if (s.check && s.check !== r.prev) showResult(id, s);
            else { setBusy(id, false); note(id, '⚠ check did not finish — try again', '#a33'); }
          } else if (Date.now() - r.since > 12 * 60000) {
            delete running[id];
            setBusy(id, false);
            note(id, '⚠ check did not finish — try again', '#a33');
          }
        });
      })
      .catch(function () {});
  }
  function watch(id, prev, since) {
    running[id] = {prev: prev, since: since || Date.now()};
    if (!timer) timer = setInterval(poll, 15000);
  }
  function start(ids) {
    if (!ids.length) return;
    var fd = new FormData();
    fd.append('action', 'run_check'); fd.append('ajax', '1'); fd.append('ck_ids', ids.join(','));
    ids.forEach(function (id) { setBusy(id, true); note(id, '⏳ starting check…'); });
    fetch('ck_tracker.php', {method: 'POST', body: fd, credentials: 'same-origin'})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        ids.forEach(function (id) {
          var res = (d.results || {})[id] || {ok: false, msg: 'not started'};
          var a = cell(id) && cell(id).querySelector('.ck-chk');
          var prev = a ? parseInt((a.getAttribute('href').match(/id=(\d+)/) || [0, 0])[1], 10) : 0;
          if (res.ok) {
            note(id, '⏳ check running (started ' + esc(d.at) + ') — the result appears here');
            watch(id, prev);
          } else {
            setBusy(id, false);
            note(id, '⚠ ' + esc(res.msg || 'could not start the check'), '#a33');
          }
        });
      })
      .catch(function (e) { ids.forEach(function (id) { setBusy(id, false); note(id, '⚠ ' + esc(e.message), '#a33'); }); });
  }
  // Rows already running when the page opened.
  document.querySelectorAll('.ck-running').forEach(function (r) {
    watch(r.getAttribute('data-ck'), parseInt(r.getAttribute('data-check'), 10));
  });
  // Row button.
  document.querySelectorAll('.ck-runform').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      start([f.querySelector('input[name="ck_id"]').value]);
    });
  });
  // Selection.
  var boxes = Array.prototype.slice.call(document.querySelectorAll('.ck-sel'));
  var all = document.getElementById('ckSelAll'), btn = document.getElementById('ckRunSel');
  function picked() { return boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; }); }
  function refresh() {
    var n = picked().length;
    document.getElementById('ckSelN').textContent = n;
    btn.disabled = !n;
    if (all) all.checked = n && n === boxes.length;
  }
  boxes.forEach(function (b) { b.addEventListener('change', refresh); });
  if (all) all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); refresh(); });
  btn.addEventListener('click', function () {
    var ids = picked();
    if (ids.length > 10 && !confirm('Start ' + ids.length + ' checks now?')) return;
    start(ids);
    boxes.forEach(function (b) { b.checked = false; });
    refresh();
  });
})();
function flashCopied(el) { var o = el.textContent; el.textContent = '✓ Copied'; setTimeout(function(){ el.textContent = o; }, 1200); }
</script>

<?php include 'includes/footer.php'; ?>
