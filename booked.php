<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/folder_parser.php';
require_once __DIR__ . '/includes/mail_helper.php';

// ── AJAX handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // Preview: substitute vars and return subject + body
    if ($action === 'preview_email') {
        $req_id  = (int)$_POST['request_id'];
        $tpl_id  = (int)$_POST['template_id'];

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

        $dates      = parse_folder_dates($row['group_folder'] ?? '');
        $agent_name  = $row['agent_name']  ?? 'Savannah Explorers';
        $agent_email = $row['agent_email'] ?? (defined('HUB_EMAIL') ? HUB_EMAIL : '');

        echo json_encode([
            'ok'      => true,
            'subject' => substitute_vars($t['subject'],  $row, $dates, $agent_name, $agent_email),
            'body'    => substitute_vars($t['body_html'], $row, $dates, $agent_name, $agent_email),
            'to'      => $row['email'] ?? '',
        ]);
        exit;
    }

    // Send email + log note
    if ($action === 'send_email') {
        $req_id  = (int)$_POST['request_id'];
        $to      = trim($_POST['to'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body'] ?? '');

        if (!$to || !$subject || !$body) {
            echo json_encode(['ok'=>false,'msg'=>'Missing required fields.']); exit;
        }

        // Get agent info for From header
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
        $reply_to = $from_email;

        $sent = send_hub_email($to, $subject, $body, $from_name, $from_email, $reply_to);

        if ($sent) {
            log_email_note($pdo, $req_id, $_SESSION['user_id'] ?? null, $subject, $body);
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false,'msg'=>'mail() returned false. Check server mail configuration.']);
        }
        exit;
    }

    // Notes for a request
    if ($action === 'get_notes') {
        $req_id = (int)$_POST['request_id'];
        $notes  = $pdo->prepare(
            "SELECT n.*, u.full_name AS user_name
             FROM request_notes n
             LEFT JOIN users u ON u.id = n.created_by
             WHERE n.request_id = ?
             ORDER BY n.created_at DESC"
        );
        $notes->execute([$req_id]);
        echo json_encode(['ok'=>true, 'notes'=> $notes->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
    exit;
}

// ── Fetch booked requests (have a group_folder with dates) ───────────────────
// Adjust status filter below if needed (e.g. add "AND r.status='Paid'")
$rows = $pdo->query(
    "SELECT r.id, r.customer_name, r.email, r.destination, r.pax,
            r.group_folder, r.period, r.status, r.notes AS req_notes,
            a.name AS agent_name,
            (SELECT COUNT(*) FROM request_notes rn WHERE rn.request_id = r.id) AS note_count
     FROM requests r
     LEFT JOIN agents a ON a.id = r.agent_id
     WHERE r.group_folder IS NOT NULL AND r.group_folder != ''
     ORDER BY r.id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// Parse dates and sort by start_ts ascending
foreach ($rows as &$row) {
    $d = parse_folder_dates($row['group_folder']);
    $row['start_date'] = $d['start_date'];
    $row['end_date']   = $d['end_date'];
    $row['start_ts']   = $d['start_ts'] ?? PHP_INT_MAX;
}
unset($row);

usort($rows, fn($a,$b) => $a['start_ts'] <=> $b['start_ts']);

// Templates for the send modal
$templates = $pdo->query(
    "SELECT id, name, category FROM email_templates WHERE active=1 ORDER BY sort_order, name"
)->fetchAll(PDO::FETCH_ASSOC);

$today_ts = mktime(0,0,0);
$page_title = 'Booked Requests';
include __DIR__ . '/includes/layout_header.php';
?>

<div class="container-fluid py-3">

  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">Booked Requests <span class="badge badge-secondary" id="rowCount"></span></h4>
    <div class="form-inline">
      <div class="custom-control custom-switch mr-3">
        <input type="checkbox" class="custom-control-input" id="showPast" checked>
        <label class="custom-control-label small" for="showPast">Show past</label>
      </div>
      <input type="text" id="searchBox" class="form-control form-control-sm" placeholder="Search…" style="width:180px">
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body p-0">
      <table class="table table-sm table-hover mb-0" id="bookedTable">
        <thead class="thead-light">
          <tr>
            <th>Arrival</th>
            <th>Departure</th>
            <th>Customer</th>
            <th>Email</th>
            <th>Agent</th>
            <th>Destination</th>
            <th class="text-center">Pax</th>
            <th class="text-center">Notes</th>
            <th style="width:80px"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $is_past = $r['start_ts'] !== PHP_INT_MAX && $r['start_ts'] < $today_ts;
        ?>
          <tr class="<?= $is_past ? 'past-row text-muted' : '' ?>"
              data-start="<?= $r['start_ts'] ?>"
              data-search="<?= htmlspecialchars(strtolower($r['customer_name'].' '.$r['agent_name'].' '.$r['destination'])) ?>">

            <td class="<?= !$is_past ? 'font-weight-bold' : '' ?>">
              <?= $r['start_date'] ? date('d M Y', strtotime($r['start_date'])) : '<span class="text-danger small">?</span>' ?>
            </td>
            <td><?= $r['end_date'] ? date('d M Y', strtotime($r['end_date'])) : '' ?></td>
            <td><?= htmlspecialchars($r['customer_name']) ?></td>
            <td class="small"><?= htmlspecialchars($r['email'] ?? '') ?></td>
            <td class="small"><?= htmlspecialchars($r['agent_name'] ?? '') ?></td>
            <td class="small"><?= htmlspecialchars($r['destination'] ?? '') ?></td>
            <td class="text-center"><?= $r['pax'] ?></td>
            <td class="text-center">
              <?php if ($r['note_count'] > 0): ?>
                <span class="badge badge-info cursor-pointer view-notes"
                      data-id="<?= $r['id'] ?>"
                      data-customer="<?= htmlspecialchars($r['customer_name']) ?>">
                  <?= $r['note_count'] ?>
                </span>
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td class="text-right">
              <?php if ($r['email']): ?>
                <button class="btn btn-xs btn-outline-primary send-btn"
                        data-id="<?= $r['id'] ?>"
                        data-customer="<?= htmlspecialchars($r['customer_name']) ?>"
                        data-to="<?= htmlspecialchars($r['email']) ?>">
                  <i class="fas fa-envelope"></i> Send
                </button>
              <?php else: ?>
                <span class="text-muted small">no email</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="9" class="text-center text-muted py-3">No booked requests found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── Send Email Modal ───────────────────────────────────────────────────── -->
<div class="modal fade" id="sendModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title mb-0">Send Email — <span id="sendModalCustomer"></span></h6>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="send_request_id">

        <div class="form-row mb-2">
          <div class="form-group col-md-5 mb-1">
            <label class="small font-weight-bold">To</label>
            <input type="email" id="send_to" class="form-control form-control-sm">
          </div>
          <div class="form-group col-md-5 mb-1">
            <label class="small font-weight-bold">Template</label>
            <select id="send_template" class="form-control form-control-sm">
              <option value="">— select template —</option>
              <?php
              $cat = '';
              foreach ($templates as $t):
                if ($t['category'] !== $cat) {
                    if ($cat !== '') echo '</optgroup>';
                    echo '<optgroup label="' . htmlspecialchars($t['category'] ?: 'General') . '">';
                    $cat = $t['category'];
                }
              ?>
                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
              <?php endforeach; if ($cat) echo '</optgroup>'; ?>
            </select>
          </div>
          <div class="form-group col-md-2 mb-1 d-flex align-items-end">
            <button class="btn btn-sm btn-outline-secondary w-100" id="btnLoadTpl">Load</button>
          </div>
        </div>

        <div class="form-group mb-2">
          <label class="small font-weight-bold">Subject</label>
          <input type="text" id="send_subject" class="form-control form-control-sm">
        </div>

        <ul class="nav nav-tabs nav-sm mb-1" id="bodyTabs">
          <li class="nav-item"><a class="nav-link active small py-1" data-toggle="tab" href="#tabEdit">Edit</a></li>
          <li class="nav-item"><a class="nav-link small py-1" data-toggle="tab" href="#tabPreview">Preview</a></li>
        </ul>
        <div class="tab-content border border-top-0 rounded-bottom">
          <div class="tab-pane active p-0" id="tabEdit">
            <textarea id="send_body" class="form-control border-0 font-monospace"
                      rows="18" style="font-size:12px;resize:vertical;border-radius:0"></textarea>
          </div>
          <div class="tab-pane p-3" id="tabPreview">
            <div id="send_preview" style="min-height:200px"></div>
          </div>
        </div>

        <div id="sendAlert" class="alert mt-2 d-none"></div>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-primary" id="btnSendEmail">
          <i class="fas fa-paper-plane"></i> Send Email
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── Notes Modal ────────────────────────────────────────────────────────── -->
<div class="modal fade" id="notesModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title mb-0">Notes — <span id="notesModalCustomer"></span></h6>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body" id="notesBody">
        <div class="text-center text-muted py-3">Loading…</div>
      </div>
    </div>
  </div>
</div>

<script>
// ── Row count + filter ───────────────────────────────────────────────────────
function updateTable() {
  const showPast  = document.getElementById('showPast').checked;
  const search    = document.getElementById('searchBox').value.toLowerCase().trim();
  const today_ts  = <?= $today_ts ?>;
  let visible = 0;

  document.querySelectorAll('#bookedTable tbody tr[data-start]').forEach(tr => {
    const ts = parseInt(tr.dataset.start);
    const isPast = ts !== <?= PHP_INT_MAX ?> && ts < today_ts;
    const matchSearch = !search || tr.dataset.search.includes(search);
    const show = matchSearch && (!isPast || showPast);
    tr.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  document.getElementById('rowCount').textContent = visible;
}

document.getElementById('showPast').addEventListener('change', updateTable);
document.getElementById('searchBox').addEventListener('input', updateTable);

// Sync preview tab
document.querySelector('a[href="#tabPreview"]').addEventListener('shown.bs.tab', () => {
  document.getElementById('send_preview').innerHTML = document.getElementById('send_body').value;
});

// ── Send modal ───────────────────────────────────────────────────────────────
document.querySelectorAll('.send-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('send_request_id').value     = btn.dataset.id;
    document.getElementById('sendModalCustomer').textContent = btn.dataset.customer;
    document.getElementById('send_to').value             = btn.dataset.to;
    document.getElementById('send_template').value       = '';
    document.getElementById('send_subject').value        = '';
    document.getElementById('send_body').value           = '';
    document.getElementById('send_preview').innerHTML    = '';
    document.getElementById('sendAlert').classList.add('d-none');
    $('#sendModal').modal('show');
  });
});

document.getElementById('btnLoadTpl').addEventListener('click', () => {
  const tpl_id = document.getElementById('send_template').value;
  const req_id = document.getElementById('send_request_id').value;
  if (!tpl_id) { alert('Select a template first.'); return; }

  fetch('booked.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:`action=preview_email&request_id=${req_id}&template_id=${tpl_id}`
  })
  .then(r=>r.json())
  .then(d => {
    if (!d.ok) { alert(d.msg); return; }
    document.getElementById('send_subject').value = d.subject;
    document.getElementById('send_body').value    = d.body;
    if (d.to && !document.getElementById('send_to').value) {
      document.getElementById('send_to').value = d.to;
    }
  });
});

document.getElementById('btnSendEmail').addEventListener('click', () => {
  const btn = document.getElementById('btnSendEmail');
  const alert_box = document.getElementById('sendAlert');

  const body = new URLSearchParams({
    action:     'send_email',
    request_id: document.getElementById('send_request_id').value,
    to:         document.getElementById('send_to').value,
    subject:    document.getElementById('send_subject').value,
    body:       document.getElementById('send_body').value,
  });

  btn.disabled = true;
  btn.textContent = 'Sending…';
  alert_box.classList.add('d-none');

  fetch('booked.php', {method:'POST', body})
    .then(r=>r.json())
    .then(d => {
      if (d.ok) {
        alert_box.className = 'alert alert-success mt-2';
        alert_box.textContent = 'Email sent and logged successfully.';
        alert_box.classList.remove('d-none');
        btn.textContent = '✓ Sent';
        setTimeout(() => { $('#sendModal').modal('hide'); location.reload(); }, 1500);
      } else {
        alert_box.className = 'alert alert-danger mt-2';
        alert_box.textContent = d.msg || 'Send failed.';
        alert_box.classList.remove('d-none');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Email';
      }
    });
});

// ── Notes modal ──────────────────────────────────────────────────────────────
document.querySelectorAll('.view-notes').forEach(el => {
  el.addEventListener('click', () => {
    document.getElementById('notesModalCustomer').textContent = el.dataset.customer;
    document.getElementById('notesBody').innerHTML = '<div class="text-center text-muted py-3">Loading…</div>';
    $('#notesModal').modal('show');

    fetch('booked.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:`action=get_notes&request_id=${el.dataset.id}`
    })
    .then(r=>r.json())
    .then(d => {
      if (!d.ok || !d.notes.length) {
        document.getElementById('notesBody').innerHTML = '<p class="text-muted text-center py-3">No notes.</p>';
        return;
      }
      let html = '';
      d.notes.forEach(n => {
        const icon  = n.note_type === 'email_sent' ? '📧' : '📝';
        const badge = n.note_type === 'email_sent'
          ? '<span class="badge badge-info">Email sent</span>'
          : '<span class="badge badge-secondary">Note</span>';
        html += `
          <div class="card mb-2 border-0 bg-light">
            <div class="card-body py-2 px-3">
              <div class="d-flex justify-content-between align-items-start mb-1">
                <div>${icon} ${badge}
                  ${n.subject ? `<span class="font-weight-bold ml-1">${escHtml(n.subject)}</span>` : ''}
                </div>
                <small class="text-muted">${n.created_at}${n.user_name ? ' — ' + escHtml(n.user_name) : ''}</small>
              </div>
              ${n.body ? `<div class="small border-top pt-1 mt-1" style="max-height:200px;overflow-y:auto">${n.body}</div>` : ''}
            </div>
          </div>`;
      });
      document.getElementById('notesBody').innerHTML = html;
    });
  });
});

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

updateTable();
</script>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
