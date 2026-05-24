<?php
ob_start(); // capture any warnings/notices from includes
require_once 'config.php';
require_once 'includes/folder_parser.php';
require_once 'includes/mail_helper.php';
requireLogin();
if (!in_array(current_user()['role_name'] ?? '', ['admin'])) { http_response_code(403); exit('Access denied'); }

$cu          = current_user();
$my_agent_id = (int)($cu['agent_id'] ?? 0);

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_end_clean(); // discard any stray output before JSON
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
        ob_start();
        set_time_limit(60);
        try {
            $req_id  = (int)$_POST['request_id'];
            $to      = trim($_POST['to']      ?? '');
            $subject = trim($_POST['subject'] ?? '');
            $body    = trim($_POST['body']    ?? '');
            if (!$to || !$subject || !$body) {
                ob_end_clean();
                echo json_encode(['ok'=>false,'msg'=>'Missing required fields.']); exit;
            }
            $req = db()->prepare("SELECT r.*, a.name AS agent_name, u.email AS agent_email
                FROM requests r
                LEFT JOIN agents a ON a.id = r.agent_id
                LEFT JOIN users  u ON u.agent_id = r.agent_id
                WHERE r.id = ?");
            $req->execute([$req_id]);
            $row = $req->fetch(PDO::FETCH_ASSOC);
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

    if ($action === 'get_notes') {
        $req_id = (int)$_POST['request_id'];
        $notes  = db()->prepare("SELECT n.*, u.full_name AS user_name
             FROM request_notes n LEFT JOIN users u ON u.id = n.created_by
             WHERE n.request_id = ? ORDER BY n.created_at DESC");
        $notes->execute([$req_id]);
        echo json_encode(['ok'=>true,'notes'=> $notes->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

// ── Data ──────────────────────────────────────────────────────────────────────
$agents = db()->query("SELECT id, name FROM agents WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$rows = db()->query(
    "SELECT r.id, r.customer_name, r.email, r.destination, r.pax,
            r.group_folder, r.practice_code, r.period, r.status, r.agent_id,
            a.name AS agent_name,
            (SELECT COUNT(*) FROM request_notes rn WHERE rn.request_id = r.id AND rn.note_type='email_sent') AS note_count
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
    $row['start_ts']   = $d['start_ts'];
}
unset($row);
usort($rows, function($a, $b) {
    if ($a['start_ts'] === null && $b['start_ts'] === null) return 0;
    if ($a['start_ts'] === null) return 1;
    if ($b['start_ts'] === null) return -1;
    return $a['start_ts'] <=> $b['start_ts'];
});

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
.tabs{display:flex;border-bottom:2px solid var(--grey-lt);margin-bottom:0}
.tab-btn{background:none;border:none;padding:7px 16px;font-size:.75rem;font-weight:700;cursor:pointer;color:var(--grey-mid);border-bottom:2px solid transparent;margin-bottom:-2px;font-family:"Open Sans",sans-serif}
.tab-btn.active{color:var(--red);border-bottom-color:var(--red)}
.tab-pane{display:none}.tab-pane.active{display:block}
.note-card{background:var(--off-white);border-radius:7px;padding:12px 16px;margin-bottom:10px;border-left:3px solid var(--grey-lt)}
.note-card.email-sent{border-left-color:var(--navy,#1a3a5c)}
.attach-chip{display:inline-flex;align-items:center;gap:4px;background:var(--off-white);border:1px solid var(--grey-lt);border-radius:4px;padding:2px 8px;font-size:.72rem;margin:2px}
.attach-chip button{background:none;border:none;cursor:pointer;color:var(--red);font-size:.9rem;line-height:1;padding:0 1px}
.row-link{cursor:pointer}
.row-link:hover td{background:#f5f0ee}
.status-deposit{background:#fff3e0;color:#c45000}
.status-balance{background:#f3e5f5;color:#6a1b9a}
.status-paid{background:#e3f2fd;color:#1565c0}
';
include 'includes/header.php';
?>

<div class="page-header">
  <h2>✈ Booked Requests <span id="rowCount" style="font-size:.8rem;font-weight:400;color:var(--grey-mid)"></span></h2>
</div>

<div class="filters">
  <div>
    <label>Agent</label>
    <select id="filterAgent">
      <option value="">All agents</option>
      <?php foreach ($agents as $ag): ?>
        <option value="<?= $ag['id'] ?>" <?= $ag['id'] == $my_agent_id ? 'selected' : '' ?>><?= h($ag['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Search</label>
    <input type="text" id="searchBox" placeholder="Customer, destination…">
  </div>
  <div>
    <label>&nbsp;</label>
    <label style="display:flex;align-items:center;gap:6px;padding:8px 0;font-size:.82rem;font-weight:400;cursor:pointer">
      <input type="checkbox" id="showPast"> Show past arrivals
    </label>
  </div>
  <div>
    <label>&nbsp;</label>
    <label style="display:flex;align-items:center;gap:6px;padding:8px 0;font-size:.82rem;font-weight:400;cursor:pointer">
      <input type="checkbox" id="showNoDates" checked> Show without dates
    </label>
  </div>
</div>

<div class="table-wrap">
  <table id="bookedTable">
    <thead>
      <tr>
        <th>Arrival</th>
        <th>Departure</th>
        <th>Customer</th>
        <th>Destination</th>
        <th style="text-align:center">Pax</th>
        <th>Agent</th>
        <th style="text-align:center">Status</th>
        <th style="text-align:center">Sent</th>
        <th style="text-align:center">Action</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="9" style="text-align:center;color:var(--grey-mid);padding:32px">No booked requests found.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r):
        $is_past  = $r['start_ts'] !== null && $r['start_ts'] < $today_ts;
        $has_date = $r['start_ts'] !== null;
        $status_cls = 'status-' . strtolower($r['status']);
    ?>
      <tr class="row-link"
          data-agent="<?= (int)$r['agent_id'] ?>"
          data-start="<?= (string)($r['start_ts'] ?? '') ?>"
          data-hasdate="<?= $has_date ? '1' : '0' ?>"
          data-search="<?= h(strtolower($r['customer_name'].' '.($r['agent_name']??'').' '.($r['destination']??''))) ?>"
          onclick="window.location='request_view.php?id=<?= $r['id'] ?>'"
          style="<?= $is_past ? 'color:var(--grey-mid)' : '' ?>">
        <td style="font-weight:<?= ($has_date && !$is_past) ? '700' : '400' ?>;color:<?= ($has_date && !$is_past) ? 'var(--green)' : 'inherit' ?>">
          <?= $r['start_date'] ? date('d M Y', strtotime($r['start_date'])) : '<span style="color:var(--grey-lt)">—</span>' ?>
        </td>
        <td><?= $r['end_date'] ? date('d M Y', strtotime($r['end_date'])) : '—' ?></td>
        <td style="font-weight:600"><?= h($r['customer_name']) ?></td>
        <td><?= h($r['destination'] ?? '') ?></td>
        <td style="text-align:center"><?= (int)$r['pax'] ?></td>
        <td><?= h($r['agent_name'] ?? '') ?></td>
        <td style="text-align:center">
          <span class="badge <?= $status_cls ?>"><?= h($r['status']) ?></span>
        </td>
        <td style="text-align:center" onclick="event.stopPropagation()">
          <?php if ($r['note_count'] > 0): ?>
            <span class="badge" style="background:var(--navy-lt,#e8eef5);color:var(--navy,#1a3a5c);cursor:pointer"
                  onclick="viewNotes(<?= $r['id'] ?>, '<?= addslashes(h($r['customer_name'])) ?>')">
              <?= $r['note_count'] ?>
            </span>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td style="text-align:center">
          <button class="btn btn-outline btn-sm"
                  onclick="event.stopPropagation();openSend(<?= $r['id'] ?>, '<?= addslashes(h($r['customer_name'])) ?>', '<?= addslashes(h($r['email'] ?? '')) ?>')">
            ✉ Send
          </button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ── Send Email Modal ────────────────────────────────────────────────────── -->
<div class="modal-overlay hidden" id="sendOverlay" style="display:none">
  <div class="modal-box" style="max-width:820px">
    <div class="modal-header">
      <h3>✉ Send Email — <span id="sendCustomer"></span></h3>
      <button class="modal-close" onclick="closeSend()">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="send_req_id">

      <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:flex-end;margin-bottom:14px">
        <div>
          <label class="m-label">To</label>
          <input type="email" id="send_to" class="m-input">
        </div>
        <div>
          <label class="m-label">Template</label>
          <select id="send_tpl" class="m-input">
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
        <button class="btn btn-outline btn-sm" style="white-space:nowrap" onclick="loadTemplate()">Load</button>
      </div>

      <div style="margin-bottom:14px">
        <label class="m-label">Subject</label>
        <input type="text" id="send_subject" class="m-input">
      </div>

      <label class="m-label">Body</label>
      <div style="border:1.5px solid var(--grey-lt);border-radius:6px;overflow:hidden">
        <div id="send-quill" style="min-height:200px;font-size:.88rem;font-family:'Open Sans',sans-serif"></div>
      </div>
      <textarea id="send_body" style="display:none"></textarea>

      <div style="margin-top:14px">
        <label class="m-label">📎 Attachments</label>
        <input type="file" id="attach_input" multiple style="display:none" onchange="handleFiles(this)">
        <button class="btn btn-outline btn-sm" type="button" onclick="document.getElementById('attach_input').click()">+ Add attachment</button>
        <div id="attachList" style="margin-top:8px;display:flex;flex-wrap:wrap;gap:4px"></div>
      </div>

      <div id="sendAlert" style="display:none;margin-top:12px;padding:10px 14px;border-radius:6px;font-size:.82rem"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeSend()">Cancel</button>
      <button class="btn btn-red" id="btnSend" onclick="doSend()">✉ Send Email</button>
    </div>
  </div>
</div>

<!-- ── Notes Modal ────────────────────────────────────────────────────────── -->
<div class="modal-overlay hidden" id="notesOverlay" style="display:none">
  <div class="modal-box" style="max-width:660px">
    <div class="modal-header">
      <h3>Notes — <span id="notesCustomer"></span></h3>
      <button class="modal-close" onclick="closeNotes()">&times;</button>
    </div>
    <div class="modal-body" id="notesBody" style="min-height:100px"></div>
  </div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"></script>
<script>
const TODAY_TS = <?= $today_ts ?>;
let attachedFiles = [];

// ── Quill for send modal ──────────────────────────────────────────────────────
var sendQuill = new Quill('#send-quill', {
  theme: 'snow',
  modules: {
    toolbar: [
      ['bold','italic','underline'],
      [{'list':'ordered'},{'list':'bullet'}],
      ['link','clean'],
      [{'color':[]},{'align':[]}]
    ]
  }
});

function updateTable() {
  const agent    = document.getElementById('filterAgent').value;
  const search   = document.getElementById('searchBox').value.toLowerCase().trim();
  const showPast = document.getElementById('showPast').checked;
  const showNone = document.getElementById('showNoDates').checked;
  let vis = 0;
  document.querySelectorAll('#bookedTable tbody tr.row-link').forEach(tr => {
    const ts      = tr.dataset.start !== '' ? parseInt(tr.dataset.start) : null;
    const hasDate = tr.dataset.hasdate === '1';
    const isPast  = ts !== null && ts < TODAY_TS;
    const agentOk = !agent || tr.dataset.agent === agent;
    const srchOk  = !search || tr.dataset.search.includes(search);
    const dateOk  = (hasDate && (!isPast || showPast)) || (!hasDate && showNone);
    const show    = agentOk && srchOk && dateOk;
    tr.style.display = show ? '' : 'none';
    if (show) vis++;
  });
  document.getElementById('rowCount').textContent = '(' + vis + ')';
}
['filterAgent','searchBox','showPast','showNoDates'].forEach(id => {
  document.getElementById(id).addEventListener('change', updateTable);
  document.getElementById(id).addEventListener('input', updateTable);
});

['sendOverlay','notesOverlay'].forEach(id => {
  document.getElementById(id).addEventListener('click', function(e) {
    if (e.target === this) { id === 'sendOverlay' ? closeSend() : closeNotes(); }
  });
});

function openSend(id, customer, to) {
  document.getElementById('send_req_id').value       = id;
  document.getElementById('sendCustomer').textContent = customer;
  document.getElementById('send_to').value            = to;
  document.getElementById('send_tpl').value           = '';
  document.getElementById('send_subject').value       = '';
  document.getElementById('send_body').value          = '';
  document.getElementById('sendAlert').style.display  = 'none';
  document.getElementById('btnSend').disabled         = false;
  document.getElementById('btnSend').textContent      = '✉ Send Email';
  sendQuill.root.innerHTML = '';
  document.getElementById('send_body').value = '';
  attachedFiles = [];
  document.getElementById('attachList').innerHTML = '';
  document.getElementById('attach_input').value   = '';
  document.getElementById('sendOverlay').style.display = 'flex';
}
function closeSend()  { document.getElementById('sendOverlay').style.display = 'none'; }
function closeNotes() { document.getElementById('notesOverlay').style.display = 'none'; }

function loadTemplate() {
  const tpl_id = document.getElementById('send_tpl').value;
  const req_id = document.getElementById('send_req_id').value;
  if (!tpl_id) { alert('Select a template first.'); return; }
  fetch('booked.php', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=preview_email&request_id='+req_id+'&template_id='+tpl_id
  }).then(r=>r.json()).then(d => {
    if (!d.ok) { alert(d.msg); return; }
    document.getElementById('send_subject').value = d.subject;
    sendQuill.root.innerHTML = '';
    sendQuill.clipboard.dangerouslyPasteHTML(0, d.body || '');
  });
}

function doSend() {
  const btn  = document.getElementById('btnSend');
  const alrt = document.getElementById('sendAlert');
  alrt.style.display = 'none';
  btn.disabled = true;
  btn.textContent = 'Sending…';

  const fd = new FormData();
  fd.append('action',     'send_email');
  fd.append('request_id', document.getElementById('send_req_id').value);
  fd.append('to',         document.getElementById('send_to').value);
  fd.append('subject',    document.getElementById('send_subject').value);
  fd.append('body',       sendQuill.root.innerHTML);
  attachedFiles.forEach(function(f) { fd.append('attachments[]', f); });

  fetch('booked.php', {method:'POST', body:fd})
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.ok) {
        alrt.style.cssText = 'display:block;background:#e8f5e9;color:#2e7d32;margin-top:12px;padding:10px 14px;border-radius:6px;font-size:.82rem';
        alrt.textContent = 'Email sent and logged successfully.';
        setTimeout(function() { closeSend(); location.reload(); }, 1500);
      } else {
        alrt.style.cssText = 'display:block;background:#ffebee;color:#c62828;margin-top:12px;padding:10px 14px;border-radius:6px;font-size:.82rem';
        alrt.textContent = d.msg || 'Send failed.';
        btn.disabled = false;
        btn.textContent = '✉ Send Email';
      }
    })
    .catch(function(err) {
      alrt.style.cssText = 'display:block;background:#ffebee;color:#c62828;margin-top:12px;padding:10px 14px;border-radius:6px;font-size:.82rem';
      alrt.textContent = 'Network error: ' + err.message;
      btn.disabled = false;
      btn.textContent = '✉ Send Email';
    });
}


function handleFiles(input) {
  Array.from(input.files).forEach(function(f) {
    if (!attachedFiles.find(function(x) { return x.name === f.name && x.size === f.size; }))
      attachedFiles.push(f);
  });
  input.value = '';
  renderAttachments();
}
function removeAttach(idx) { attachedFiles.splice(idx, 1); renderAttachments(); }
function renderAttachments() {
  document.getElementById('attachList').innerHTML = attachedFiles.map(function(f, i) {
    return '<span class="attach-chip">📎 ' + esc(f.name) +
           ' <small style="color:var(--grey-mid)">(' + (f.size/1024).toFixed(0) + 'KB)</small>' +
           '<button type="button" onclick="removeAttach(' + i + ')">×</button></span>';
  }).join('');
}

function viewNotes(id, customer) {
  document.getElementById('notesCustomer').textContent = customer;
  document.getElementById('notesBody').innerHTML = '<p style="color:var(--grey-mid);text-align:center;padding:24px">Loading…</p>';
  document.getElementById('notesOverlay').style.display = 'flex';
  fetch('booked.php', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=get_notes&request_id=' + id
  }).then(function(r) { return r.json(); }).then(function(d) {
    if (!d.ok || !d.notes.length) {
      document.getElementById('notesBody').innerHTML = '<p style="color:var(--grey-mid);text-align:center;padding:24px">No notes.</p>';
      return;
    }
    document.getElementById('notesBody').innerHTML = d.notes.map(function(n) {
      var isEmail = n.note_type === 'email_sent';
      return '<div class="note-card ' + (isEmail ? 'email-sent' : '') + '">' +
        '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px">' +
          '<div><span class="badge ' + (isEmail ? 'status-booked' : '') + '">' + (isEmail ? '📧 Email sent' : '📝 Note') + '</span>' +
          (n.subject ? '<strong style="font-size:.83rem;margin-left:8px">' + esc(n.subject) + '</strong>' : '') + '</div>' +
          '<small style="color:var(--grey-mid);flex-shrink:0;margin-left:12px">' + esc(n.created_at) + (n.user_name ? ' — ' + esc(n.user_name) : '') + '</small>' +
        '</div>' +
        (n.body ? '<div style="font-size:.8rem;color:var(--grey-dk);border-top:1px solid var(--grey-lt);padding-top:8px;max-height:180px;overflow-y:auto">' + n.body + '</div>' : '') +
      '</div>';
    }).join('');
  });
}

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

updateTable();
</script>

<?php include 'includes/footer.php'; ?>
