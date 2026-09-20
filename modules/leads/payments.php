<?php
ob_start(); // capture any warnings/notices from includes
require_once 'config.php';
require_once 'includes/folder_parser.php';
require_once 'includes/mail_helper.php';
requireLogin();

$cu   = current_user();
$stmt = db()->prepare("SELECT agent_id FROM users WHERE id=?");
$stmt->execute([$cu['id']]);
$my_agent_id = (int)($stmt->fetchColumn() ?: 0);

// Payment statuses that count as "not yet settled".
const UNPAID_STATUSES = ['Deposit', 'Balance', 'Balance-Cash'];

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_end_clean(); // discard any stray output before JSON
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // ── Email preview / send (reminder to the sales person) ──────────────────
    if ($action === 'preview_email' || $action === 'send_email') {
        $req_id = (int)$_POST['request_id'];
        $req = db()->prepare("SELECT r.*, a.name AS agent_name, u.email AS agent_email
            FROM requests r
            LEFT JOIN agents a ON a.id = r.agent_id
            LEFT JOIN users  u ON u.agent_id = r.agent_id
            WHERE r.id = ?");
        $req->execute([$req_id]);
        $row = $req->fetch(PDO::FETCH_ASSOC);

        if ($action === 'preview_email') {
            $tpl_id = (int)$_POST['template_id'];
            $tpl = db()->prepare("SELECT * FROM email_templates WHERE id=? AND active=1");
            $tpl->execute([$tpl_id]);
            $t = $tpl->fetch(PDO::FETCH_ASSOC);
            if (!$row || !$t) { echo json_encode(['ok'=>false,'msg'=>'Not found']); exit; }
            $dates       = parse_folder_dates(get_date_folder($row));
            $agent_name  = $row['agent_name']  ?? 'Savannah Explorers';
            $agent_email = $row['agent_email'] ?? '';
            echo json_encode([
                'ok'      => true,
                'subject' => substitute_vars($t['subject'],   $row, $dates, $agent_name, $agent_email),
                'body'    => substitute_vars($t['body_html'], $row, $dates, $agent_name, $agent_email),
                // Reminder recipient defaults to the agent (sales person), not the client.
                'to'      => $row['agent_email'] ?? '',
            ]);
            exit;
        }

        // send_email
        ob_start();
        set_time_limit(60);
        try {
            $to      = trim($_POST['to']      ?? '');
            $subject = trim($_POST['subject'] ?? '');
            $body    = trim($_POST['body']    ?? '');
            if (!$to || !$subject || !$body) {
                ob_end_clean();
                echo json_encode(['ok'=>false,'msg'=>'Missing required fields.']); exit;
            }
            if (!$row) { ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'Request not found.']); exit; }
            $from_name  = $row['agent_name']  ?? 'Savannah Explorers';
            $from_email = $row['agent_email'] ?? '';
            if (!$from_email) {
                ob_end_clean();
                echo json_encode(['ok'=>false,'msg'=>'No email address found for the agent linked to this request.']); exit;
            }
            $attachments = []; $attachment_names = [];
            if (!empty($_FILES['attachments']['name'][0])) {
                foreach ($_FILES['attachments']['name'] as $i => $name) {
                    if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                        $attachments[]      = ['tmp_path' => $_FILES['attachments']['tmp_name'][$i], 'name' => $name];
                        $attachment_names[] = $name;
                    }
                }
            }
            $sent = send_hub_email($to, $subject, $body, $from_name, $from_email, $from_email, $attachments);
            ob_end_clean();
            if ($sent) {
                log_email_note(db(), $req_id, $cu['id'] ?? null, $subject, $body, $attachment_names);
                echo json_encode(['ok'=>true]);
            } else {
                echo json_encode(['ok'=>false,'msg'=>'Send failed. Check server mail configuration.']);
            }
        } catch (Throwable $e) {
            ob_end_clean();
            echo json_encode(['ok'=>false,'msg'=>'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── List all notes for a request ─────────────────────────────────────────
    if ($action === 'get_notes') {
        $req_id = (int)$_POST['request_id'];
        $notes  = db()->prepare("SELECT n.*, u.full_name AS user_name
             FROM request_notes n LEFT JOIN users u ON u.id = n.created_by
             WHERE n.request_id = ?
               AND (n.note_type <> 'manual' OR (n.body IS NOT NULL AND TRIM(n.body) <> ''))
             ORDER BY n.created_at DESC");
        $notes->execute([$req_id]);
        echo json_encode(['ok'=>true,'notes'=> $notes->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── Add a manual payment note ────────────────────────────────────────────
    if ($action === 'add_note') {
        $req_id = (int)$_POST['request_id'];
        $body   = trim($_POST['body'] ?? '');
        if ($req_id <= 0 || $body === '') { echo json_encode(['ok'=>false,'msg'=>'Empty note.']); exit; }
        $chk = db()->prepare("SELECT id FROM requests WHERE id=?");
        $chk->execute([$req_id]);
        if (!$chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Request not found.']); exit; }
        $ins = db()->prepare(
            "INSERT INTO request_notes (request_id, created_by, note_type, subject, body)
             VALUES (?, ?, 'manual', NULL, ?)"
        );
        $ins->execute([$req_id, $cu['id'] ?? null, $body]);
        $cnt = db()->prepare("SELECT COUNT(*) FROM request_notes WHERE request_id=? AND note_type='manual'");
        $cnt->execute([$req_id]);
        echo json_encode(['ok'=>true,'count'=>(int)$cnt->fetchColumn()]);
        exit;
    }

    // ── Delete a manual note (own corrections) ───────────────────────────────
    if ($action === 'delete_note') {
        $note_id = (int)$_POST['note_id'];
        // Only manual notes can be removed here (never the logged email history).
        $del = db()->prepare("DELETE FROM request_notes WHERE id=? AND note_type='manual'");
        $del->execute([$note_id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

// ── Data ──────────────────────────────────────────────────────────────────────
$agents = db()->query("SELECT id, name FROM agents WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$rows = db()->query(
    "SELECT r.id, r.customer_name, r.email, r.destination, r.pax,
            r.group_folder, r.practice_code, r.period, r.status, r.agent_id,
            r.source, r.payment_status, r.date_received, r.confirmation_date, r.dropbox_url,
            a.name AS agent_name,
            (SELECT u.email FROM users u
               WHERE u.agent_id = r.agent_id ORDER BY u.id LIMIT 1) AS agent_email,
            (SELECT COUNT(*) FROM request_notes rn
               WHERE rn.request_id = r.id AND rn.note_type='manual'
                 AND rn.body IS NOT NULL AND TRIM(rn.body) <> '') AS note_count,
            (SELECT rn2.body FROM request_notes rn2
               WHERE rn2.request_id = r.id AND rn2.note_type='manual'
                 AND rn2.body IS NOT NULL AND TRIM(rn2.body) <> ''
               ORDER BY rn2.created_at DESC LIMIT 1) AS last_note,
            (SELECT u3.full_name FROM request_notes rn3
               LEFT JOIN users u3 ON u3.id = rn3.created_by
               WHERE rn3.request_id = r.id AND rn3.note_type='manual'
                 AND rn3.body IS NOT NULL AND TRIM(rn3.body) <> ''
               ORDER BY rn3.created_at DESC LIMIT 1) AS last_note_by
     FROM requests r
     LEFT JOIN agents a ON a.id = r.agent_id
     WHERE r.status IN ('Booked','Paid','Balance','Deposit')
       AND (r.payment_status IS NULL OR r.payment_status != 'Cancelled')
     ORDER BY r.id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$today_ts   = mktime(0, 0, 0);
$window_end = strtotime('+12 months', $today_ts);

foreach ($rows as &$row) {
    $d = parse_folder_dates(get_date_folder($row));
    $row['start_date'] = $d['start_date'];
    $row['end_date']   = $d['end_date'];
    $row['start_ts']   = $d['start_ts'];
    // Per-request payment status is authoritative. When it is NULL, fall back to
    // the folder tag (display only): for a private safari that's its own folder,
    // and for a GRP it's the main group folder (folder_payment_status → group_folder).
    $row['_ps_derived'] = $row['payment_status'] ?: folder_payment_status($row);
}
unset($row);

// Keep only NOT-yet-settled pratiche (per-request status).
$rows = array_values(array_filter($rows, fn($r) => in_array($r['_ps_derived'], UNPAID_STATUSES, true)));

// ── Filters (server-side GET, consistent with requests.php) ───────────────────
// Fresh visit (no filter params) pre-selects the current user's own agent and
// the "next 12 months + overdue" window. Submitting the form (or ?clear=1)
// takes the posted values verbatim; an empty value means "all".
$clear   = isset($_GET['clear']);
$fresh   = !$clear && !array_intersect(['agent','status','window','q'], array_keys($_GET));
$fAgent  = $clear ? '' : (isset($_GET['agent'])  ? trim($_GET['agent'])  : ($fresh ? (string)$my_agent_id : ''));
$fStatus = $clear ? '' : (isset($_GET['status']) ? trim($_GET['status']) : '');
$fWindow = $clear ? 'next12' : (isset($_GET['window']) && $_GET['window'] !== '' ? trim($_GET['window']) : 'next12');
$fQ      = $clear ? '' : (isset($_GET['q']) ? trim($_GET['q']) : '');

$rows = array_values(array_filter($rows, function ($r) use ($fAgent, $fStatus, $fWindow, $fQ, $today_ts, $window_end) {
    if ($fAgent  !== '' && (int)$r['agent_id'] !== (int)$fAgent) return false;
    if ($fStatus !== '' && $r['_ps_derived'] !== $fStatus)       return false;
    $has  = $r['start_ts'] !== null;
    $over = $has && $r['start_ts'] <  $today_ts;
    $in12 = $has && $r['start_ts'] >= $today_ts && $r['start_ts'] <= $window_end;
    if ($fWindow === 'next12'   && !($over || $in12)) return false;
    if ($fWindow === 'upcoming' && !$in12)            return false;
    if ($fWindow === 'overdue'  && !$over)            return false;
    // 'all' → no date restriction
    if ($fQ !== '') {
        $hay = strtolower($r['customer_name'] . ' ' . folder_agency($r) . ' '
             . get_date_folder($r) . ' ' . ($r['agent_name'] ?? ''));
        if (strpos($hay, strtolower($fQ)) === false) return false;
    }
    return true;
}));

// Sort strictly by arrival date (chronological). Rows with no parsable date go
// last. Same-day arrivals fall back to customer name for a stable order.
usort($rows, function ($a, $b) {
    $at = $a['start_ts'] ?? PHP_INT_MAX;
    $bt = $b['start_ts'] ?? PHP_INT_MAX;
    if ($at !== $bt) return $at <=> $bt;
    return strcasecmp($a['customer_name'] ?? '', $b['customer_name'] ?? '');
});

// Group rows by arrival month (English month name); no-date rows in a final bucket.
$byMonth = [];
foreach ($rows as $r) {
    if ($r['start_ts'] !== null) {
        $key   = date('Y-m', $r['start_ts']);
        $label = date('F Y', $r['start_ts']);
    } else {
        $key   = '9999-99';
        $label = 'No arrival date';
    }
    if (!isset($byMonth[$key])) $byMonth[$key] = ['label' => $label, 'rows' => []];
    $byMonth[$key]['rows'][] = $r;
}
ksort($byMonth);

// Count how many requests share each group_folder (to flag GRP clusters).
$groupCounts = [];
foreach ($rows as $r) {
    $gf = trim($r['group_folder'] ?? '');
    if ($gf !== '') $groupCounts[$gf] = ($groupCounts[$gf] ?? 0) + 1;
}

// Email templates for the reminder modal.
$stmt = db()->prepare(
    "SELECT id, name, category FROM email_templates
     WHERE active=1 AND (visibility='public' OR (visibility='private' AND agent_id=?))
     ORDER BY visibility ASC, sort_order ASC, name ASC"
);
$stmt->execute([$my_agent_id]);
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Payments';
$pageTitle  = 'Payments';
$extra_css  = '
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto}
.modal-overlay.hidden{display:none}
.modal-box{background:#fff;border-radius:10px;box-shadow:0 8px 40px rgba(0,0,0,.2);width:100%}
.modal-header{padding:15px 24px;border-bottom:1px solid var(--grey-lt);display:flex;align-items:center;justify-content:space-between}
.modal-header h3{font-family:"Merriweather",serif;font-size:.95rem;font-weight:700;margin:0;color:var(--black)}
.modal-body{padding:22px 24px}
.modal-footer{padding:14px 24px;border-top:1px solid var(--grey-lt);display:flex;justify-content:flex-end;gap:10px}
.modal-close{background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--grey-mid);line-height:1;padding:0}
.m-label{font-size:.72rem;font-weight:700;color:var(--grey-dk);display:block;margin-bottom:4px}
.m-input{width:100%;padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:"Open Sans",sans-serif;font-size:.82rem;color:var(--black)}
.m-input:focus{outline:none;border-color:var(--red)}
.note-card{background:var(--off-white);border-radius:7px;padding:12px 16px;margin-bottom:10px;border-left:3px solid var(--grey-lt)}
.note-card.email-sent{border-left-color:var(--navy,#1a3a5c)}
.note-card.manual{border-left-color:#e0a800}
.attach-chip{display:inline-flex;align-items:center;gap:4px;background:var(--off-white);border:1px solid var(--grey-lt);border-radius:4px;padding:2px 8px;font-size:.72rem;margin:2px}
.attach-chip button{background:none;border:none;cursor:pointer;color:var(--red);font-size:.9rem;line-height:1;padding:0 1px}
.agent-group{margin-bottom:22px}
.agent-head{background:#E8F5E9;color:#1A6B3A;font-weight:700;font-size:.9rem;padding:8px 14px;border-radius:8px 8px 0 0;border-left:4px solid #1A6B3A}
.month-group{margin-bottom:22px}
.month-head{background:#EAF1F8;color:#1a3a5c;font-weight:700;font-size:.95rem;padding:9px 14px;border-radius:8px 8px 0 0;border-left:4px solid #1a3a5c}
.pay-table{width:100%;border-collapse:collapse}
.pay-table th{text-align:left;font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;color:var(--grey-mid);padding:8px 10px;border-bottom:1px solid var(--grey-lt)}
.pay-table td{padding:8px 10px;border-bottom:1px solid var(--grey-lt);font-size:.83rem;vertical-align:top}
.pay-row.grp td:first-child{border-left:3px solid #cbb;}
.note-preview{color:var(--grey-mid);font-size:.76rem;max-width:280px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle}
.overdue-pill{background:#f8d7da;color:#842029;font-size:.66rem;font-weight:700;padding:1px 6px;border-radius:8px;margin-left:6px}
.status-deposit{background:#fff3cd;color:#856404}
.status-balance{background:#cfe2ff;color:#0a3678}
.status-balance-cash{background:#d1ecf1;color:#0c5460}
';
include 'includes/header.php';
?>

<div class="page-header">
  <h2>💳 Payments to collect <span id="rowCount" style="font-size:.8rem;font-weight:400;color:var(--grey-mid)">(<?= count($rows) ?>)</span></h2>
</div>

<form method="GET" class="filters">
  <div>
    <label>Agent</label>
    <select name="agent">
      <option value="">All agents</option>
      <?php foreach ($agents as $ag): ?>
        <option value="<?= (int)$ag['id'] ?>" <?= (string)$ag['id'] === (string)$fAgent ? 'selected' : '' ?>><?= h($ag['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach (['Deposit','Balance','Balance-Cash'] as $st): ?>
        <option value="<?= h($st) ?>" <?= $fStatus === $st ? 'selected' : '' ?>><?= h($st) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Window</label>
    <select name="window">
      <option value="next12"   <?= $fWindow === 'next12'   ? 'selected' : '' ?>>Overdue + next 12 months</option>
      <option value="upcoming" <?= $fWindow === 'upcoming' ? 'selected' : '' ?>>Next 12 months only</option>
      <option value="overdue"  <?= $fWindow === 'overdue'  ? 'selected' : '' ?>>Overdue only</option>
      <option value="all"      <?= $fWindow === 'all'      ? 'selected' : '' ?>>All</option>
    </select>
  </div>
  <div>
    <label>Search</label>
    <input type="text" name="q" value="<?= h($fQ) ?>" placeholder="Customer, destination…">
  </div>
  <div>
    <label>&nbsp;</label>
    <button type="submit" class="btn btn-outline">Filter</button>
  </div>
  <div>
    <label>&nbsp;</label>
    <a href="payments.php?clear=1" class="btn btn-outline btn-grey">✕ Clear Filters</a>
  </div>
</form>

<div id="groups">
<?php if (!$rows): ?>
  <p style="text-align:center;color:var(--grey-mid);padding:40px">No outstanding payments found. 🎉</p>
<?php endif; ?>
<?php foreach ($byMonth as $mkey => $mgrp):
    $arows = $mgrp['rows'];
?>
  <div class="month-group">
    <div class="month-head"><?= h($mgrp['label']) ?> <span style="font-weight:400;opacity:.8">(<?= count($arows) ?>)</span></div>
    <table class="pay-table">
      <thead>
        <tr>
          <th style="width:100px">Arrival</th>
          <th>Customer</th>
          <th>Folder</th>
          <th style="width:44px;text-align:center">Pax</th>
          <th style="width:120px;text-align:center">Status</th>
          <th>Latest note</th>
          <th style="width:220px;text-align:right">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($arows as $r):
          $ps       = $r['_ps_derived'];
          $has_date = $r['start_ts'] !== null;
          $is_over  = $has_date && $r['start_ts'] < $today_ts;
          $in_12    = $has_date && $r['start_ts'] >= $today_ts && $r['start_ts'] <= $window_end;
          $gf       = trim($r['group_folder'] ?? '');
          $isGrp    = $gf !== '' && ($groupCounts[$gf] ?? 0) > 1;
          $agency   = folder_agency($r);
          $psCls    = 'status-' . strtolower($ps);
          // Dropbox folder: the real folder name (parent folder for GRP), reliable
          // via get_date_folder(); the subfolder is added for GRP when the URL has it.
          $dbxUrl     = trim($r['dropbox_url'] ?? '');
          $folderMain = get_date_folder($r);
          if ($folderMain === '') $folderMain = $gf ?: trim($r['practice_code'] ?? '');
          $folderSub  = '';
          if ($isGrp && $dbxUrl) {
              $p = urldecode((string)parse_url($dbxUrl, PHP_URL_PATH));
              if (preg_match('#/(?:001_Safari|2026)/[^/]+/([^/]+)#i', $p, $m)) {
                  $sub = trim($m[1]);
                  if ($sub !== '' && strcasecmp($sub, $folderMain) !== 0) $folderSub = $sub;
              }
          }
      ?>
        <tr class="pay-row<?= $isGrp ? ' grp' : '' ?>" style="cursor:pointer"
            onclick="openRequest(<?= (int)$r['id'] ?>)"
            data-reqid="<?= (int)$r['id'] ?>"
            data-agent="<?= (int)$r['agent_id'] ?>"
            data-status="<?= h($ps) ?>"
            data-start="<?= $has_date ? (int)$r['start_ts'] : '' ?>"
            data-overdue="<?= $is_over ? '1' : '0' ?>"
            data-in12="<?= $in_12 ? '1' : '0' ?>"
            data-search="<?= h(strtolower($r['customer_name'].' '.($agency).' '.($folderMain).' '.($folderSub).' '.($r['agent_name'] ?? ''))) ?>">
          <td style="white-space:nowrap;<?= $is_over ? 'color:#842029;font-weight:700' : '' ?>">
            <?= $r['start_date'] ? date('d M Y', strtotime($r['start_date'])) : '<span style="color:var(--grey-lt)">— no date —</span>' ?>
            <?php if ($is_over): ?><span class="overdue-pill">OVERDUE</span><?php endif; ?>
          </td>
          <td>
            <span style="font-weight:600"><?= h($r['customer_name']) ?></span>
            <?php if ($agency): ?><span style="font-size:.73rem;color:var(--grey-mid)">(<?= h($agency) ?>)</span><?php endif; ?>
            <?php if ($isGrp): ?><span style="font-size:.66rem;color:#8a6d3b;background:#fcf3e3;border-radius:6px;padding:1px 5px;margin-left:4px">GROUP</span><?php endif; ?>
            <div style="font-size:.7rem;color:var(--grey-mid);margin-top:2px">👤 <?= h($r['agent_name'] ?: '— no agent —') ?></div>
          </td>
          <td style="font-family:monospace;font-size:.76rem">
            <span style="color:var(--grey-dk)" title="Folder reference">📁 <?= h($folderMain) ?></span>
            <?php if ($folderSub !== ''): ?>
              <div style="color:var(--grey-mid);margin-top:2px">↳ <?= h($folderSub) ?></div>
            <?php endif; ?>
            <?php $sPath = savannah_local_path($r); $sUrl = savannah_open_url($r); ?>
            <?php if ($sPath !== ''): ?>
              <div style="margin-top:3px;font-family:'Open Sans',sans-serif">
                <a href="<?= h($sUrl) ?>" onclick="event.stopPropagation()" title="Open in Windows Explorer" style="font-size:.68rem;text-decoration:none">📂 Open</a>
                <a href="#" class="copy-path" data-path="<?= h($sPath) ?>" onclick="event.stopPropagation();copyPath(this);return false" title="Copy Windows path" style="font-size:.68rem;text-decoration:none;margin-left:8px">📋 Copy path</a>
              </div>
            <?php endif; ?>
          </td>
          <td style="text-align:center"><?= (int)$r['pax'] ?></td>
          <td style="text-align:center"><span class="badge <?= $psCls ?>"><?= h($ps) ?></span></td>
          <td>
            <?php if ($r['note_count'] > 0): ?>
              <span class="note-preview" title="<?= h(strip_tags($r['last_note'] ?? '')) ?>"><?= h(mb_strimwidth(strip_tags($r['last_note'] ?? ''), 0, 60, '…')) ?></span>
              <?php if (!empty($r['last_note_by'])): ?><span style="font-size:.68rem;color:var(--grey-mid)">— <?= h($r['last_note_by']) ?></span><?php endif; ?>
              <span class="badge" style="background:#fff3cd;color:#856404;cursor:pointer" onclick="event.stopPropagation();openNotes(<?= (int)$r['id'] ?>, '<?= addslashes(h($r['customer_name'])) ?>')"><?= (int)$r['note_count'] ?></span>
            <?php else: ?>
              <span style="color:var(--grey-lt)">—</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;white-space:nowrap">
            <button type="button" class="btn btn-outline btn-sm" title="Add / view payment notes" onclick="event.stopPropagation();openNotes(<?= (int)$r['id'] ?>, '<?= addslashes(h($r['customer_name'])) ?>')">📝 Note</button>
            <button type="button" class="btn btn-outline btn-sm" title="Email the client" onclick="event.stopPropagation();openSend(<?= (int)$r['id'] ?>, '<?= addslashes(h($r['customer_name'])) ?>', '<?= addslashes(h($r['email'] ?? '')) ?>')">✉ Mail</button>
            <button type="button" class="btn btn-outline btn-sm" title="Remind the sales agent" onclick="event.stopPropagation();openSend(<?= (int)$r['id'] ?>, '<?= addslashes(h($r['customer_name'])) ?>', '<?= addslashes(h($r['agent_email'] ?? '')) ?>')">✉ Reminder</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endforeach; ?>
</div>

<?php
$send_ajax_url = 'payments.php';
include 'includes/send_modal.php';
?>

<!-- ── Notes Modal ────────────────────────────────────────────────────────── -->
<div class="modal-overlay hidden" id="notesOverlay" style="display:none">
  <div class="modal-box" style="max-width:640px">
    <div class="modal-header">
      <h3>Payment notes — <span id="notesCustomer"></span></h3>
      <button type="button" class="modal-close" onclick="closeNotes()">&times;</button>
    </div>
    <div class="modal-body">
      <div style="margin-bottom:14px">
        <label class="m-label">Add a note (e.g. "asked 10 Sep", "will pay after 30 Sep")</label>
        <textarea id="newNote" class="m-input" rows="2" style="resize:vertical"></textarea>
        <div style="text-align:right;margin-top:8px">
          <button type="button" class="btn btn-red btn-sm" id="btnAddNote" onclick="saveNote()">＋ Add note</button>
        </div>
      </div>
      <div id="notesList" style="border-top:1px solid var(--grey-lt);padding-top:14px;min-height:60px"></div>
    </div>
  </div>
</div>

<script>
let notesReqId  = 0;
const CURRENT_USER = '<?= addslashes($cu['full_name'] ?? $cu['username'] ?? '') ?>';

// Copy a Windows path to the clipboard (paste into Explorer's address bar).
function copyPath(el) {
  var t = el.getAttribute('data-path') || '';
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(t).then(function(){ flashCopied(el); }, function(){ fallbackCopy(t, el); });
  } else { fallbackCopy(t, el); }
}
function fallbackCopy(t, el) {
  var ta = document.createElement('textarea');
  ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0';
  document.body.appendChild(ta); ta.select();
  try { document.execCommand('copy'); flashCopied(el); } catch (e) { prompt('Copy this path:', t); }
  document.body.removeChild(ta);
}
function flashCopied(el) { var o = el.textContent; el.textContent = '✓ Copied'; setTimeout(function(){ el.textContent = o; }, 1200); }

// Open the request in a new tab for editing (row click).
function openRequest(id) { window.open('request_edit.php?id=' + id, '_blank'); }

document.querySelector('#notesOverlay .modal-box').addEventListener('click', function(e){ e.stopPropagation(); });
function closeNotes() { document.getElementById('notesOverlay').style.display = 'none'; }

function openNotes(id, customer) {
  notesReqId = id;
  document.getElementById('notesCustomer').textContent = customer;
  document.getElementById('newNote').value = '';
  document.getElementById('notesList').innerHTML = '<p style="color:var(--grey-mid);text-align:center;padding:16px">Loading…</p>';
  document.getElementById('notesOverlay').style.display = 'flex';
  loadNotes();
}

function loadNotes() {
  fetch('payments.php', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=get_notes&request_id=' + notesReqId
  }).then(function(r){return r.json();}).then(function(d) {
    if (!d.ok || !d.notes.length) {
      document.getElementById('notesList').innerHTML = '<p style="color:var(--grey-mid);text-align:center;padding:16px">No notes yet.</p>';
      return;
    }
    document.getElementById('notesList').innerHTML = d.notes.map(function(n) {
      var isEmail = n.note_type === 'email_sent';
      var author  = n.user_name ? esc(n.user_name) : (isEmail ? 'System' : 'Unknown user');
      var meta = '<strong style="color:var(--grey-dk)">👤 ' + author + '</strong> · ' + esc(n.created_at);
      var del  = isEmail ? '' :
        '<button type="button" title="Delete note" onclick="delNote(' + n.id + ')" ' +
        'style="background:none;border:none;color:var(--red);cursor:pointer;font-size:.9rem;line-height:1">×</button>';
      return '<div class="note-card ' + (isEmail ? 'email-sent' : 'manual') + '">' +
        '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px">' +
          '<span class="badge" style="background:' + (isEmail ? '#e8eef5;color:#1a3a5c' : '#fff3cd;color:#856404') + '">' +
            (isEmail ? '📧 Email sent' : '📝 Note') + '</span>' +
          '<span style="display:flex;gap:8px;align-items:center"><small style="color:var(--grey-mid)">' + meta + '</small>' + del + '</span>' +
        '</div>' +
        (n.subject ? '<strong style="font-size:.82rem">' + esc(n.subject) + '</strong>' : '') +
        (n.body ? '<div style="font-size:.8rem;color:var(--grey-dk);margin-top:4px;max-height:160px;overflow-y:auto">' + n.body + '</div>' : '') +
      '</div>';
    }).join('');
  });
}

function saveNote() {
  var body = document.getElementById('newNote').value.trim();
  if (!body) return;
  var btn = document.getElementById('btnAddNote');
  btn.disabled = true;
  fetch('payments.php', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=add_note&request_id=' + notesReqId + '&body=' + encodeURIComponent(body)
  }).then(function(r){return r.json();}).then(function(d) {
    btn.disabled = false;
    if (!d.ok) { alert(d.msg || 'Could not save note.'); return; }
    document.getElementById('newNote').value = '';
    updateNoteBadge(notesReqId, d.count, body);
    loadNotes();
  }).catch(function(err){ btn.disabled = false; alert('Error: ' + err.message); });
}

function delNote(id) {
  if (!confirm('Delete this note?')) return;
  fetch('payments.php', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=delete_note&note_id=' + id
  }).then(function(r){return r.json();}).then(function(){ loadNotes(); });
}

// Refresh the row's note badge + preview without a full reload.
function updateNoteBadge(reqId, count, lastBody) {
  var tr = document.querySelector('tr[data-reqid="' + reqId + '"]');
  if (!tr) return;
  var td = tr.querySelectorAll('td')[5]; // "Latest note" column
  if (!td) return;
  td.innerHTML =
    '<span class="note-preview" title="' + esc(lastBody) + '">' + esc(lastBody.substring(0, 60)) + '</span> ' +
    (CURRENT_USER ? '<span style="font-size:.68rem;color:var(--grey-mid)">— ' + esc(CURRENT_USER) + '</span> ' : '') +
    '<span class="badge" style="background:#fff3cd;color:#856404;cursor:pointer" onclick="event.stopPropagation();openNotes(' + reqId + ', \'\')">' + count + '</span>';
}

function esc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>

<?php include 'includes/footer.php'; ?>
