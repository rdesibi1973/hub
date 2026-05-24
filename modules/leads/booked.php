<?php
require_once 'config.php';
require_once 'includes/folder_parser.php';
require_once 'includes/mail_helper.php';
requireLogin();
if (!in_array(current_user()['role_name'] ?? '', ['admin'])) { http_response_code(403); exit('Access denied'); }

$cu          = current_user();
$my_agent_id = (int)($cu['agent_id'] ?? 0);

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'preview_email') {
        $req_id = (int)$_POST['request_id'];
        $tpl_id = (int)$_POST['template_id'];

        $req = db()->prepare("SELECT r.*, a.name AS agent_name, u.email AS agent_email
            FROM requests r
            LEFT JOIN agents a ON a.id = r.agent_id
            LEFT JOIN users  u ON u.agent_id = r.agent_id
            WHERE r.id = ?");
        $req->execute([$req_id]);
        $row = $req->fetch(PDO::FETCH_ASSOC);

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

        $req = db()->prepare("SELECT r.*, a.name AS agent_name, u.email AS agent_email
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
            echo json_encode(['ok'=>false,'msg'=>'No email address found for the agent linked to this request.']); exit;
        }

        // Handle attachments
        $attachments = [];
        $attachment_names = [];
        if (!empty($_FILES['attachments'])) {
            $files = $_FILES['attachments'];
            $count = is_array($files['name']) ? count($files['name']) : 1;
            for ($i = 0; $i < $count; $i++) {
                $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                $tmp  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                $err  = is_array($files['error']) ? $files['error'][$i] : $files['error'];
                if ($err === UPLOAD_ERR_OK && $tmp) {
                    $attachments[]      = ['tmp_path' => $tmp, 'name' => $name];
                    $attachment_names[] = $name;
                }
            }
        }

        $sent = send_hub_email($to, $subject, $body, $from_name, $from_email, $from_email, $attachments);

        if ($sent) {
            log_email_note(db(), $req_id, $cu['id'] ?? null, $subject, $body, $attachment_names);
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false,'msg'=>'Send failed. Check server mail configuration.']);
        }
        exit;
    }

    if ($action === 'get_notes') {
        $req_id = (int)$_POST['request_id'];
        $notes  = db()->prepare(
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

// ── Fetch agents for filter ───────────────────────────────────────────────────
$agents = db()->query("SELECT id, name FROM agents WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// ── Fetch ALL booked requests and parse dates ─────────────────────────────────
$rows = db()->query(
    "SELECT r.id, r.customer_name, r.email, r.destination, r.pax,
            r.group_folder, r.practice_code, r.period, r.status, r.agent_id,
            a.name AS agent_name,
            (SELECT COUNT(*) FROM request_notes rn WHERE rn.request_id = r.id) AS note_count
     FROM requests r
     LEFT JOIN agents a ON a.id = r.agent_id
     WHERE r.status IN ('Booked','Paid','Balance','Deposit')
     ORDER BY r.id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$today_ts = mktime(0,0,0);
foreach ($rows as &$row) {
    $d = parse_folder_dates(get_date_folder($row));
    $row['start_date'] = $d['start_date'];
    $row['end_date']   = $d['end_date'];
    $row['start_ts']   = $d['start_ts'] ?? null;
}
unset($row);

// Sort: requests with date first (ascending), then no-date at end
usort($rows, function($a, $b) {
    $a_ts = $a['start_ts'];
    $b_ts = $b['start_ts'];
    if ($a_ts === null && $b_ts === null) return 0;
    if ($a_ts === null) return 1;
    if ($b_ts === null) return -1;
    return $a_ts <=> $b_ts;
});

// Templates: public + agent's private
$stmt = db()->prepare(
    "SELECT id, name, category FROM email_templates
     WHERE active=1 AND (visibility='public' OR (visibility='private' AND agent_id=?))
     ORDER BY visibility ASC, sort_order ASC, name ASC"
);
$stmt->execute([$my_agent_id]);
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tpl_by_cat = [];
foreach ($templates as $t) $tpl_by_cat[$t['category'] ?: 'General'][] = $t;
ksort($tpl_by_cat);

$page_title = 'Booked';
$pageTitle  = 'Booked';

$extra_css = '
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto}
.modal-overlay.hidden{display:none}
.modal-box{background:#fff;border-radius:10px;box-shadow:0 8px 40px rgba(0,0,0,.2);width:100%}
.modal-header{padding:16px 24px;border-bottom:1px solid #E8E8E8;display:flex;align-items:center;justify-content:space-between}
.modal-header h3{font-family:"Merriweather",serif;font-size:1rem;font-weight:700;margin:0}
.modal-body{padding:24px}
.modal-footer{padding:16px 24px;border-top:1px solid #E8E8E8;display:flex;justify-content:flex-end;gap:12px}
.modal-close{background:none;border:none;font-size:1.4rem;cursor:pointer;color:#aaa;line-height:1}
.tabs{display:flex;border-bottom:2px solid #E8E8E8}
.tab-btn{background:none;border:none;padding:8px 18px;font-size:.78rem;font-weight:600;cursor:pointer;color:#aaa;border-bottom:2px solid transparent;margin-bottom:-2px}
.tab-btn.active{color:#C0211B;border-bottom-color:#C0211B}
.tab-pane{display:none}.tab-pane.active{display:block}
.note-card{background:#f8f8f8;border-radius:8px;padding:14px 16px;margin-bottom:10px;border-left:3px solid #ddd}
.note-card.email-sent{border-left-color:#1a3a5c}
.chip{display:inline-block;padding:2px 10px;border-radius:20px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
.chip-email{background:#e8eef5;color:#1a3a5c}
.chip-note{background:#eee;color:#666}
.date-cell{font-weight:700;color:#2e7d32}
.date-past{color:#aaa}
.filters{display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.stat-pill{display:inline-block;padding:2px 8px;border-radius:20px;font-size:.65rem;font-weight:700;text-transform:uppercase}
.pill-booked{background:#e8f5e9;color:#2e7d32}
.pill-paid{background:#e3f2fd;color:#1565c0}
.pill-deposit{background:#fff3e0;color:#e65100}
.pill-balance{background:#f3e5f5;color:#6a1b9a}
.attach-list{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
.attach-chip{display:inline-flex;align-items:center;gap:4px;background:#f0f4f8;border:1px solid #d0dce8;border-radius:4px;padding:2px 8px;font-size:.75rem}
.attach-chip button{background:none;border:none;cursor:pointer;color:#c00;font-size:.9rem;line-height:1;padding:0 2px}
';
include 'includes/header.php';
?>

<div style="padding:24px 40px">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
    <h2 style="font-family:'Merriweather',serif;font-size:1.2rem;font-weight:700;margin:0">
      ✈ Booked Requests
      <span id="rowCount" style="font-size:.8rem;font-weight:400;color:#888;margin-left:6px"></span>
    </h2>
  </div>

  <!-- Filters -->
  <div class="filters">
    <div>
      <label style="font-size:.75rem;font-weight:600;color:#666;display:block;margin-bottom:3px">Agent</label>
      <select id="filterAgent" class="form-control form-control-sm" style="min-width:160px">
        <option value="">All agents</option>
        <?php foreach ($agents as $ag): ?>
          <option value="<?= $ag['id'] ?>" <?= $ag['id'] == $my_agent_id ? 'selected' : '' ?>>
            <?= h($ag['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="font-size:.75rem;font-weight:600;color:#666;display:block;margin-bottom:3px">Search</label>
      <input type="text" id="searchBox" class="form-control form-control-sm" placeholder="Customer, destination…" style="min-width:200px">
    </div>
    <div style="padding-top:20px">
      <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;cursor:pointer">
        <input type="checkbox" id="showPast"> Show past arrivals
      </label>
    </div>
    <div style="padding-top:20px">
      <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;cursor:pointer">
        <input type="checkbox" id="showNoDates" checked> Show without dates
      </label>
    </div>
  </div>

  <!-- Table -->
  <div style="background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);overflow:hidden">
    <div style="overflow-x:auto">
      <table class="data-table" id="bookedTable">
        <thead>
          <tr>
            <th>Arrival</th>
            <th>Departure</th>
            <th>Customer</th>
            <th>Destination</th>
            <th style="text-align:center">Pax</th>
            <th>Agent</th>
            <th style="text-align:center">Status</th>
            <th style="text-align:center">Notes</th>
            <th style="text-align:center">Action</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" style="text-align:center;color:#aaa;padding:32px">No booked requests found.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
            $is_past   = $r['start_ts'] !== null && $r['start_ts'] < $today_ts;
            $has_date  = $r['start_ts'] !== null;
            $status_lc = strtolower($r['status']);
        ?>
          <tr data-agent="<?= (int)$r['agent_id'] ?>"
              data-start="<?= $r['start_ts'] ?? '' ?>"
              data-hasdate="<?= $has_date ? '1' : '0' ?>"
              data-search="<?= h(strtolower($r['customer_name'].' '.($r['agent_name']??'').' '.($r['destination']??''))) ?>"
              style="<?= $is_past ? 'color:#bbb' : '' ?>">
            <td class="<?= $has_date ? ($is_past ? 'date-past' : 'date-cell') : '' ?>">
              <?= $r['start_date'] ? date('d M Y', strtotime($r['start_date']))
                                   : '<span style="color:#ddd;font-size:.75rem">—</span>' ?>
            </td>
            <td style="font-size:.82rem">
              <?= $r['end_date'] ? date('d M Y', strtotime($r['end_date'])) : '—' ?>
            </td>
            <td class="td-name" style="<?= $is_past ? 'color:#bbb;font-weight:400' : '' ?>"><?= h($r['customer_name']) ?></td>
            <td style="font-size:.82rem"><?= h($r['destination'] ?? '') ?></td>
            <td style="text-align:center;font-size:.82rem"><?= $r['pax'] ?></td>
            <td style="font-size:.78rem"><?= h($r['agent_name'] ?? '') ?></td>
            <td style="text-align:center">
              <span class="stat-pill pill-<?= $status_lc ?>"><?= h($r['status']) ?></span>
            </td>
            <td style="text-align:center">
              <?php if ($r['note_count'] > 0): ?>
                <span style="background:#1a3a5c;color:#fff;border-radius:10px;padding:1px 8px;font-size:.7rem;font-weight:700;cursor:pointer"
                      onclick="viewNotes(<?= $r['id'] ?>, '<?= addslashes(h($r['customer_name'])) ?>')">
                  <?= $r['note_count'] ?>
                </span>
              <?php else: ?>
                <span style="color:#ddd">—</span>
              <?php endif; ?>
            </td>
            <td style="text-align:center">
              <?php if ($r['email']): ?>
                <button class="btn btn-secondary btn-sm"
                        onclick="openSend(<?= $r['id'] ?>, '<?= addslashes(h($r['customer_name'])) ?>', '<?= addslashes(h($r['email'])) ?>')">
                  ✉ Send
                </button>
              <?php else: ?>
                <span style="font-size:.72rem;color:#ccc">no email</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── Send Email Modal ────────────────────────────────────────────────────── -->
<div class="modal-overlay hidden" id="sendOverlay">
  <div class="modal-box" style="max-width:860px">
    <div class="modal-header">
      <h3>✉ Send Email — <span id="sendCustomer"></span></h3>
      <button class="modal-close" onclick="closeSend()">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="send_req_id">
      <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:flex-end;margin-bottom:16px">
        <div>
          <label style="font-size:.75rem;font-weight:600;display:block;margin-bottom:4px">To</label>
          <input type="email" id="send_to" style="width:100%;padding:6px 10px;border:1.5px solid #ddd;border-radius:6px;font-size:.85rem">
        </div>
        <div>
          <label style="font-size:.75rem;font-weight:600;display:block;margin-bottom:4px">Template</label>
          <select id="send_tpl" style="width:100%;padding:6px 10px;border:1.5px solid #ddd;border-radius:6px;font-size:.85rem">
            <option value="">— select template —</option>
            <?php foreach ($tpl_by_cat as $cat => $items): ?>
              <optgroup label="<?= h($cat) ?>">
                <?php foreach ($items as $t): ?>
                  <option value="<?= $t['id'] ?>"><?= h($t['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-secondary" onclick="loadTemplate()">Load</button>
      </div>

      <div style="margin-bottom:12px">
        <label style="font-size:.75rem;font-weight:600;display:block;margin-bottom:4px">Subject</label>
        <input type="text" id="send_subject" style="width:100%;padding:6px 10px;border:1.5px solid #ddd;border-radius:6px;font-size:.85rem">
      </div>

      <label style="font-size:.75rem;font-weight:600;display:block;margin-bottom:4px">Body</label>
      <div class="tabs">
        <button class="tab-btn active" onclick="sendTab('edit',this)">Edit</button>
        <button class="tab-btn" onclick="sendTab('preview',this)">Preview</button>
      </div>
      <div class="tab-pane active" id="spane-edit" style="border:1.5px solid #ddd;border-top:none;border-radius:0 0 6px 6px">
        <textarea id="send_body" style="width:100%;min-height:240px;font-family:monospace;font-size:.78rem;border:none;padding:12px;resize:vertical;outline:none"></textarea>
      </div>
      <div class="tab-pane" id="spane-preview">
        <div id="send_preview" style="border:1.5px solid #ddd;border-top:none;border-radius:0 0 6px 6px;padding:16px;min-height:240px;font-size:.85rem"></div>
      </div>

      <!-- Attachments -->
      <div style="margin-top:16px">
        <label style="font-size:.75rem;font-weight:600;display:block;margin-bottom:6px">📎 Attachments</label>
        <input type="file" id="attach_input" multiple style="display:none" onchange="handleFiles(this)">
        <button class="btn btn-secondary btn-sm" onclick="document.getElementById('attach_input').click()">
          + Add attachment
        </button>
        <div class="attach-list" id="attachList"></div>
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
    <div class="modal-body" id="notesBody" style="min-height:120px"></div>
  </div>
</div>

<script>
const TODAY_TS = <?= $today_ts ?>;
let attachedFiles = [];

// ── Filters ───────────────────────────────────────────────────────────────────
function updateTable() {
  const agent    = document.getElementById('filterAgent').value;
  const search   = document.getElementById('searchBox').value.toLowerCase().trim();
  const showPast = document.getElementById('showPast').checked;
  const showNone = document.getElementById('showNoDates').checked;
  let vis = 0;

  document.querySelectorAll('#bookedTable tbody tr[data-start]').forEach(tr => {
    const ts       = tr.dataset.start ? parseInt(tr.dataset.start) : null;
    const hasDate  = tr.dataset.hasdate === '1';
    const isPast   = ts !== null && ts < TODAY_TS;
    const agentOk  = !agent || tr.dataset.agent === agent;
    const searchOk = !search || tr.dataset.search.includes(search);
    const dateOk   = (hasDate && (!isPast || showPast)) || (!hasDate && showNone);
    const show     = agentOk && searchOk && dateOk;
    tr.style.display = show ? '' : 'none';
    if (show) vis++;
  });
  document.getElementById('rowCount').textContent = `(${vis})`;
}

['filterAgent','searchBox','showPast','showNoDates'].forEach(id => {
  document.getElementById(id).addEventListener('change', updateTable);
  document.getElementById(id).addEventListener('input', updateTable);
});

// Close modals on overlay click
['sendOverlay','notesOverlay'].forEach(id => {
  document.getElementById(id).addEventListener('click', e => {
    if (e.target.id === id) { id==='sendOverlay' ? closeSend() : closeNotes(); }
  });
});

// ── Send modal ────────────────────────────────────────────────────────────────
function openSend(id, customer, to) {
  document.getElementById('send_req_id').value = id;
  document.getElementById('sendCustomer').textContent = customer;
  document.getElementById('send_to').value = to;
  document.getElementById('send_tpl').value = '';
  document.getElementById('send_subject').value = '';
  document.getElementById('send_body').value = '';
  document.getElementById('send_preview').innerHTML = '';
  document.getElementById('sendAlert').style.display = 'none';
  document.getElementById('btnSend').disabled = false;
  document.getElementById('btnSend').textContent = '✉ Send Email';
  attachedFiles = [];
  document.getElementById('attachList').innerHTML = '';
  document.getElementById('attach_input').value = '';
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
  const btn  = document.getElementById('btnSend');
  const alrt = document.getElementById('sendAlert');
  alrt.style.display = 'none';
  btn.disabled = true; btn.textContent = 'Sending…';

  const fd = new FormData();
  fd.append('action',     'send_email');
  fd.append('request_id', document.getElementById('send_req_id').value);
  fd.append('to',         document.getElementById('send_to').value);
  fd.append('subject',    document.getElementById('send_subject').value);
  fd.append('body',       document.getElementById('send_body').value);
  attachedFiles.forEach(f => fd.append('attachments[]', f));

  fetch('booked.php', {method:'POST', body:fd})
    .then(r=>r.json()).then(d => {
      if (d.ok) {
        alrt.style.cssText = 'display:block;background:#e8f5e9;color:#2e7d32;margin-top:12px;padding:10px 14px;border-radius:6px;font-size:.82rem';
        alrt.textContent = 'Email sent and logged successfully.';
        setTimeout(() => { closeSend(); location.reload(); }, 1500);
      } else {
        alrt.style.cssText = 'display:block;background:#ffebee;color:#c62828;margin-top:12px;padding:10px 14px;border-radius:6px;font-size:.82rem';
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
      document.getElementById('send_body').value || '<em style="color:#aaa">Nothing to preview.</em>';
  }
}

// ── Attachments ───────────────────────────────────────────────────────────────
function handleFiles(input) {
  Array.from(input.files).forEach(f => {
    if (attachedFiles.find(x => x.name === f.name && x.size === f.size)) return;
    attachedFiles.push(f);
  });
  input.value = '';
  renderAttachments();
}

function removeAttach(idx) {
  attachedFiles.splice(idx, 1);
  renderAttachments();
}

function renderAttachments() {
  const list = document.getElementById('attachList');
  list.innerHTML = attachedFiles.map((f,i) =>
    `<span class="attach-chip">
      📎 ${esc(f.name)} <small style="color:#999">(${(f.size/1024).toFixed(0)}KB)</small>
      <button onclick="removeAttach(${i})">×</button>
    </span>`
  ).join('');
}

// ── Notes modal ───────────────────────────────────────────────────────────────
function viewNotes(id, customer) {
  document.getElementById('notesCustomer').textContent = customer;
  document.getElementById('notesBody').innerHTML = '<p style="color:#aaa;text-align:center;padding:24px">Loading…</p>';
  document.getElementById('notesOverlay').classList.remove('hidden');
  fetch('booked.php', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:`action=get_notes&request_id=${id}`
  }).then(r=>r.json()).then(d => {
    if (!d.ok || !d.notes.length) {
      document.getElementById('notesBody').innerHTML = '<p style="color:#aaa;text-align:center;padding:24px">No notes.</p>'; return;
    }
    document.getElementById('notesBody').innerHTML = d.notes.map(n => {
      const isEmail = n.note_type === 'email_sent';
      return `<div class="note-card ${isEmail?'email-sent':''}">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px">
          <div>
            <span class="chip ${isEmail?'chip-email':'chip-note'}">${isEmail?'📧 Email sent':'📝 Note'}</span>
            ${n.subject ? `<strong style="font-size:.85rem;margin-left:8px">${esc(n.subject)}</strong>` : ''}
          </div>
          <small style="color:#aaa;flex-shrink:0;margin-left:12px">${esc(n.created_at)}${n.user_name?' — '+esc(n.user_name):''}</small>
        </div>
        ${n.body ? `<div style="font-size:.8rem;color:#555;border-top:1px solid #eee;padding-top:8px;max-height:180px;overflow-y:auto">${n.body}</div>` : ''}
      </div>`;
    }).join('');
  });
}
function closeNotes() { document.getElementById('notesOverlay').classList.add('hidden'); }

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

updateTable();
</script>

<?php include 'includes/footer.php'; ?>
