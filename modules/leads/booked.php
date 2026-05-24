<?php
require_once 'config.php';
require_once 'includes/folder_parser.php';
require_once 'includes/mail_helper.php';
requireLogin();
if (!in_array(current_user()['role_name'] ?? '', ['admin'])) { http_response_code(403); exit('Access denied'); }

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'preview_email') {
        $req_id = (int)$_POST['request_id'];
        $tpl_id = (int)$_POST['template_id'];

        $req = $pdo->prepare("SELECT r.*, a.name AS agent_name, u.email AS agent_email
            FROM requests r
            LEFT JOIN agents a ON a.id = r.agent_id
            LEFT JOIN users  u ON u.agent_id = r.agent_id
            WHERE r.id = ?");
        $req->execute([$req_id]);
        $row = $req->fetch(PDO::FETCH_ASSOC);

        $tpl = $pdo->prepare("SELECT * FROM email_templates WHERE id=? AND active=1");
        $tpl->execute([$tpl_id]);
        $t = $tpl->fetch(PDO::FETCH_ASSOC);

        if (!$row || !$t) { echo json_encode(['ok'=>false,'msg'=>'Not found']); exit; }

        $dates       = parse_folder_dates($row['group_folder'] ?? '');
        $agent_name  = $row['agent_name']  ?? 'Savannah Explorers';
        $agent_email = $row['agent_email'] ?? '';

        echo json_encode([
            'ok'      => true,
            'subject' => substitute_vars($t['subject'],   $row, $dates, $agent_name, $agent_email),
            'body'    => substitute_vars($t['body_html'], $row, $dates, $agent_name, $agent_email),
            'to'      => $row['email'] ?? '',
        ]);
        exit;
    }

    if ($action === 'send_email') {
        $req_id  = (int)$_POST['request_id'];
        $to      = trim($_POST['to']      ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body']    ?? '');

        if (!$to || !$subject || !$body) {
            echo json_encode(['ok'=>false,'msg'=>'Missing required fields.']); exit;
        }

        $req = $pdo->prepare("SELECT r.*, a.name AS agent_name, u.email AS agent_email
            FROM requests r
            LEFT JOIN agents a ON a.id = r.agent_id
            LEFT JOIN users  u ON u.agent_id = r.agent_id
            WHERE r.id = ?");
        $req->execute([$req_id]);
        $row = $req->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok'=>false,'msg'=>'Request not found.']); exit; }

        $from_name  = $row['agent_name']  ?? 'Savannah Explorers';
        $from_email = $row['agent_email'] ?? '';
        if (!$from_email) {
            echo json_encode(['ok'=>false,'msg'=>'No email address found for the agent linked to this request.']);
            exit;
        }

        $sent = send_hub_email($to, $subject, $body, $from_name, $from_email, $from_email);

        if ($sent) {
            log_email_note($pdo, $req_id, $_SESSION['user_id'] ?? null, $subject, $body);
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false,'msg'=>'mail() returned false. Check server mail configuration.']);
        }
        exit;
    }

    if ($action === 'get_notes') {
        $req_id = (int)$_POST['request_id'];
        $notes  = $pdo->prepare(
            "SELECT n.*, u.full_name AS user_name
             FROM request_notes n LEFT JOIN users u ON u.id = n.created_by
             WHERE n.request_id = ? ORDER BY n.created_at DESC"
        );
        $notes->execute([$req_id]);
        echo json_encode(['ok'=>true,'notes'=> $notes->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

// ── Fetch & sort booked requests ──────────────────────────────────────────────
$rows = $pdo->query(
    "SELECT r.id, r.customer_name, r.email, r.destination, r.pax,
            r.group_folder, r.period, r.status,
            a.name AS agent_name,
            (SELECT COUNT(*) FROM request_notes rn WHERE rn.request_id = r.id) AS note_count
     FROM requests r
     LEFT JOIN agents a ON a.id = r.agent_id
     WHERE r.group_folder IS NOT NULL AND r.group_folder != ''
     ORDER BY r.id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$row) {
    $d = parse_folder_dates($row['group_folder']);
    $row['start_date'] = $d['start_date'];
    $row['end_date']   = $d['end_date'];
    $row['start_ts']   = $d['start_ts'] ?? PHP_INT_MAX;
}
unset($row);
usort($rows, fn($a,$b) => $a['start_ts'] <=> $b['start_ts']);

$cu          = current_user();
$my_agent_id = (int)($cu['agent_id'] ?? 0);

// Templates: public ones + current user's private ones
$stmt = $pdo->prepare(
    "SELECT id, name, category FROM email_templates
     WHERE active=1 AND (visibility='public' OR (visibility='private' AND agent_id=?))
     ORDER BY visibility ASC, sort_order ASC, name ASC"
);
$stmt->execute([$my_agent_id]);
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group templates by category for the select
$tpl_by_cat = [];
foreach ($templates as $t) {
    $tpl_by_cat[$t['category'] ?: 'General'][] = $t;
}
ksort($tpl_by_cat);

$today_ts  = mktime(0,0,0);
$page_title = 'Booked Requests';

$extra_css = '
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto}
.modal-overlay.hidden{display:none}
.modal-box{background:var(--white);border-radius:10px;box-shadow:0 8px 40px rgba(0,0,0,.2);width:100%}
.modal-header{padding:16px 24px;border-bottom:1px solid var(--grey-lt);display:flex;align-items:center;justify-content:space-between}
.modal-header h3{font-family:"Merriweather",serif;font-size:1rem;font-weight:700;color:var(--black);margin:0}
.modal-body{padding:24px}
.modal-footer{padding:16px 24px;border-top:1px solid var(--grey-lt);display:flex;justify-content:flex-end;gap:12px}
.modal-close{background:none;border:none;font-size:1.4rem;cursor:pointer;color:var(--grey-mid);line-height:1}
.tabs{display:flex;gap:0;border-bottom:2px solid var(--grey-lt)}
.tab-btn{background:none;border:none;padding:8px 18px;font-size:.78rem;font-weight:600;cursor:pointer;color:var(--grey-mid);border-bottom:2px solid transparent;margin-bottom:-2px}
.tab-btn.active{color:var(--red);border-bottom-color:var(--red)}
.tab-pane{display:none}.tab-pane.active{display:block}
.note-card{background:var(--off-white);border-radius:8px;padding:14px 16px;margin-bottom:10px;border-left:3px solid var(--grey-lt)}
.note-card.email-sent{border-left-color:var(--navy)}
.chip{display:inline-block;padding:2px 10px;border-radius:20px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
.chip-email{background:var(--navy-lt);color:var(--navy)}
.chip-note{background:var(--grey-lt);color:var(--grey-dk)}
.date-cell{font-weight:700;color:var(--green)}
.date-past{color:var(--grey-mid);font-weight:400}
.search-bar{display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap}
';
include 'includes/header.php';
?>

<main>
  <div class="page-title">
    ✈️ Booked Requests
    <span id="rowCount" style="font-size:.8rem;font-weight:400;color:var(--grey-mid);margin-left:4px"></span>
  </div>

  <div class="search-bar">
    <input type="text" id="searchBox" class="form-control" placeholder="Search customer, agent, destination…"
           style="max-width:280px">
    <label class="form-check" style="margin:0">
      <input type="checkbox" id="showPast" checked>
      <span style="font-size:.85rem">Show past arrivals</span>
    </label>
    <a href="email_templates.php" class="btn btn-secondary btn-sm" style="margin-left:auto">
      ✉ Manage Email Templates
    </a>
  </div>

  <div class="card">
    <div style="overflow-x:auto">
      <table class="data-table" id="bookedTable">
        <thead>
          <tr>
            <th>Arrival</th><th>Departure</th><th>Customer</th><th>Email</th>
            <th>Agent</th><th>Destination</th><th style="text-align:center">Pax</th>
            <th style="text-align:center">Notes</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" style="text-align:center;color:var(--grey-mid);padding:32px">No booked requests found.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
            $is_past = $r['start_ts'] !== PHP_INT_MAX && $r['start_ts'] < $today_ts;
        ?>
          <tr data-start="<?= $r['start_ts'] ?>"
              data-search="<?= e(strtolower($r['customer_name'].' '.($r['agent_name']??'').' '.($r['destination']??''))) ?>"
              style="<?= $is_past ? 'color:var(--grey-mid)' : '' ?>">
            <td class="<?= $is_past ? 'date-past' : 'date-cell' ?>">
              <?= $r['start_date'] ? date('d M Y', strtotime($r['start_date']))
                                   : '<span style="color:var(--red);font-size:.75rem">?</span>' ?>
            </td>
            <td style="font-size:.82rem;<?= $is_past ? 'color:var(--grey-mid)' : '' ?>">
              <?= $r['end_date'] ? date('d M Y', strtotime($r['end_date'])) : '—' ?>
            </td>
            <td class="td-name" style="<?= $is_past ? 'color:var(--grey-mid);font-weight:400' : '' ?>"><?= e($r['customer_name']) ?></td>
            <td style="font-size:.78rem"><?= e($r['email'] ?? '') ?></td>
            <td style="font-size:.78rem"><?= e($r['agent_name'] ?? '') ?></td>
            <td style="font-size:.78rem"><?= e($r['destination'] ?? '') ?></td>
            <td style="text-align:center;font-size:.82rem"><?= $r['pax'] ?></td>
            <td style="text-align:center">
              <?php if ($r['note_count'] > 0): ?>
                <span class="badge badge-staff" style="cursor:pointer"
                      onclick="viewNotes(<?= $r['id'] ?>, '<?= addslashes(e($r['customer_name'])) ?>')">
                  <?= $r['note_count'] ?>
                </span>
              <?php else: ?>
                <span style="color:var(--grey-lt)">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['email']): ?>
                <button class="btn btn-secondary btn-sm"
                        onclick="openSendModal(<?= $r['id'] ?>, '<?= addslashes(e($r['customer_name'])) ?>', '<?= addslashes(e($r['email'])) ?>')">
                  ✉ Send
                </button>
              <?php else: ?>
                <span style="font-size:.72rem;color:var(--grey-mid)">no email</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<!-- ── Send Email Modal ────────────────────────────────────────────────────── -->
<div class="modal-overlay hidden" id="sendOverlay">
  <div class="modal-box" style="max-width:860px">
    <div class="modal-header">
      <h3>Send Email — <span id="sendCustomer"></span></h3>
      <button class="modal-close" onclick="closeSend()">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="send_req_id">

      <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:flex-end;margin-bottom:16px">
        <div>
          <label class="form-label">To</label>
          <input type="email" id="send_to" class="form-control">
        </div>
        <div>
          <label class="form-label">Template</label>
          <select id="send_tpl" class="form-control">
            <option value="">— select template —</option>
            <?php foreach ($tpl_by_cat as $cat => $items): ?>
              <optgroup label="<?= e($cat) ?>">
                <?php foreach ($items as $t): ?>
                  <option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-secondary" onclick="loadTemplate()">Load</button>
      </div>

      <div class="form-group">
        <label class="form-label">Subject</label>
        <input type="text" id="send_subject" class="form-control">
      </div>

      <label class="form-label">Body</label>
      <div class="tabs" style="margin-bottom:0">
        <button class="tab-btn active" onclick="sendTab('edit',this)">Edit</button>
        <button class="tab-btn" onclick="sendTab('preview',this)">Preview</button>
      </div>
      <div class="tab-pane active" id="spane-edit"
           style="border:1.5px solid var(--grey-lt);border-top:none;border-radius:0 0 6px 6px">
        <textarea id="send_body"
                  style="width:100%;min-height:260px;font-family:monospace;font-size:.78rem;border:none;padding:12px;resize:vertical;outline:none;border-radius:0 0 6px 6px"
                  ></textarea>
      </div>
      <div class="tab-pane" id="spane-preview">
        <div id="send_preview"
             style="border:1.5px solid var(--grey-lt);border-top:none;border-radius:0 0 6px 6px;padding:16px;min-height:260px;font-size:.85rem"></div>
      </div>

      <div id="sendAlert" style="margin-top:12px;padding:10px 14px;border-radius:6px;display:none;font-size:.82rem"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeSend()">Cancel</button>
      <button class="btn btn-primary" id="btnSend" onclick="doSend()">✉ Send Email</button>
    </div>
  </div>
</div>

<!-- ── Notes Modal ────────────────────────────────────────────────────────── -->
<div class="modal-overlay hidden" id="notesOverlay">
  <div class="modal-box" style="max-width:680px">
    <div class="modal-header">
      <h3>Notes — <span id="notesCustomer"></span></h3>
      <button class="modal-close" onclick="closeNotes()">&times;</button>
    </div>
    <div class="modal-body" id="notesBody" style="min-height:120px">
      <p style="color:var(--grey-mid);text-align:center;padding:24px">Loading…</p>
    </div>
  </div>
</div>

<script>
const TODAY_TS = <?= $today_ts ?>;
const INT_MAX  = <?= PHP_INT_MAX ?>;

// ── Filter / search ───────────────────────────────────────────────────────────
function updateTable() {
  const showPast = document.getElementById('showPast').checked;
  const search   = document.getElementById('searchBox').value.toLowerCase().trim();
  let vis = 0;
  document.querySelectorAll('#bookedTable tbody tr[data-start]').forEach(tr => {
    const ts     = parseInt(tr.dataset.start);
    const isPast = ts !== INT_MAX && ts < TODAY_TS;
    const ok     = (!isPast || showPast) && (!search || tr.dataset.search.includes(search));
    tr.style.display = ok ? '' : 'none';
    if (ok) vis++;
  });
  document.getElementById('rowCount').textContent = `(${vis})`;
}
document.getElementById('showPast').addEventListener('change', updateTable);
document.getElementById('searchBox').addEventListener('input', updateTable);

// Close modals on overlay click
['sendOverlay','notesOverlay'].forEach(id => {
  document.getElementById(id).addEventListener('click', e => {
    if (e.target.id === id) { closeSend(); closeNotes(); }
  });
});

// ── Send modal ────────────────────────────────────────────────────────────────
function openSendModal(id, customer, to) {
  document.getElementById('send_req_id').value  = id;
  document.getElementById('sendCustomer').textContent = customer;
  document.getElementById('send_to').value      = to;
  document.getElementById('send_tpl').value     = '';
  document.getElementById('send_subject').value = '';
  document.getElementById('send_body').value    = '';
  document.getElementById('send_preview').innerHTML = '';
  document.getElementById('sendAlert').style.display = 'none';
  document.getElementById('btnSend').disabled   = false;
  document.getElementById('btnSend').textContent = '✉ Send Email';
  sendTab('edit', document.querySelector('#sendOverlay .tab-btn'));
  document.getElementById('sendOverlay').classList.remove('hidden');
}
function closeSend() { document.getElementById('sendOverlay').classList.add('hidden'); }

function loadTemplate() {
  const tpl_id = document.getElementById('send_tpl').value;
  const req_id = document.getElementById('send_req_id').value;
  if (!tpl_id) { alert('Select a template first.'); return; }

  fetch('booked.php', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:`action=preview_email&request_id=${req_id}&template_id=${tpl_id}`
  }).then(r=>r.json()).then(d => {
    if (!d.ok) { alert(d.msg); return; }
    document.getElementById('send_subject').value = d.subject;
    document.getElementById('send_body').value    = d.body;
  });
}

function doSend() {
  const btn = document.getElementById('btnSend');
  const alrt = document.getElementById('sendAlert');
  const body = new URLSearchParams({
    action:     'send_email',
    request_id: document.getElementById('send_req_id').value,
    to:         document.getElementById('send_to').value,
    subject:    document.getElementById('send_subject').value,
    body:       document.getElementById('send_body').value,
  });
  btn.disabled = true; btn.textContent = 'Sending…';
  alrt.style.display = 'none';

  fetch('booked.php', {method:'POST', body}).then(r=>r.json()).then(d => {
    if (d.ok) {
      alrt.style.cssText = 'display:block;background:var(--green-lt);color:var(--green);margin-top:12px;padding:10px 14px;border-radius:6px;font-size:.82rem';
      alrt.textContent = 'Email sent and logged successfully.';
      setTimeout(() => { closeSend(); location.reload(); }, 1500);
    } else {
      alrt.style.cssText = 'display:block;background:var(--red-lt);color:var(--red-dk);margin-top:12px;padding:10px 14px;border-radius:6px;font-size:.82rem';
      alrt.textContent = d.msg || 'Send failed.';
      btn.disabled = false; btn.textContent = '✉ Send Email';
    }
  });
}

function sendTab(tab, btn) {
  document.querySelectorAll('#sendOverlay .tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('#sendOverlay .tab-pane').forEach(p => p.classList.remove('active'));
  if (btn) btn.classList.add('active');
  document.getElementById('spane-' + tab).classList.add('active');
  if (tab === 'preview') {
    document.getElementById('send_preview').innerHTML =
      document.getElementById('send_body').value || '<em style="color:var(--grey-mid)">Nothing to preview.</em>';
  }
}

// ── Notes modal ───────────────────────────────────────────────────────────────
function viewNotes(id, customer) {
  document.getElementById('notesCustomer').textContent = customer;
  document.getElementById('notesBody').innerHTML = '<p style="color:var(--grey-mid);text-align:center;padding:24px">Loading…</p>';
  document.getElementById('notesOverlay').classList.remove('hidden');

  fetch('booked.php', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:`action=get_notes&request_id=${id}`
  }).then(r=>r.json()).then(d => {
    if (!d.ok || !d.notes.length) {
      document.getElementById('notesBody').innerHTML =
        '<p style="color:var(--grey-mid);text-align:center;padding:24px">No notes.</p>'; return;
    }
    document.getElementById('notesBody').innerHTML = d.notes.map(n => {
      const isEmail = n.note_type === 'email_sent';
      return `<div class="note-card ${isEmail ? 'email-sent' : ''}">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px">
          <div>
            <span class="chip ${isEmail ? 'chip-email' : 'chip-note'}">${isEmail ? '📧 Email sent' : '📝 Note'}</span>
            ${n.subject ? `<span style="font-weight:600;font-size:.85rem;margin-left:8px">${esc(n.subject)}</span>` : ''}
          </div>
          <span style="font-size:.72rem;color:var(--grey-mid);flex-shrink:0;margin-left:12px">
            ${esc(n.created_at)}${n.user_name ? ' — ' + esc(n.user_name) : ''}
          </span>
        </div>
        ${n.body ? `<div style="font-size:.8rem;color:var(--grey-dk);border-top:1px solid var(--grey-lt);padding-top:8px;max-height:180px;overflow-y:auto">${n.body}</div>` : ''}
      </div>`;
    }).join('');
  });
}
function closeNotes() { document.getElementById('notesOverlay').classList.add('hidden'); }

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

updateTable();
</script>

<?php include 'includes/footer.php'; ?>
