<?php
require_once 'config.php';
$pageTitle = 'Edit Request';
$db = db();

$id = (int)($_GET['id'] ?? 0);

// Staff cannot edit — redirect to view
if (isLeadsRestricted()) { header('Location: request_view.php?id=' . $id); exit; }
$row = $db->prepare("SELECT * FROM requests WHERE id = ?");
$row->execute([$id]);
$req = $row->fetch();
if (!$req) { flash('Request not found.', 'error'); header('Location: requests.php'); exit; }

$agents = $db->query("SELECT * FROM agents WHERE active=1 ORDER BY name")->fetchAll();
$errors = [];

// Populate from DB on first load
$v = $req;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['practice_code','date_received','customer_name','email','source','agent_id',
               'destination','period','pax','status','value_usd','commission_pct','commission_usd',
               'date_paid','initial_request','dropbox_url','notes'];
    foreach ($fields as $f) $v[$f] = trim($_POST[$f] ?? '');

    // Auto-calculate commission
    if ($v['value_usd'] !== '' && $v['commission_pct'] !== '') {
        $v['commission_usd'] = round((float)$v['value_usd'] * (float)$v['commission_pct'] / 100, 2);
    }

    if (!$v['customer_name']) $errors[] = 'Customer name is required.';
    if (!$v['date_received']) $errors[] = 'Date received is required.';

    if (!$errors) {
        $db->prepare("
            UPDATE requests SET
              practice_code=?, date_received=?, customer_name=?, email=?, source=?, agent_id=?,
              destination=?, period=?, pax=?, status=?, value_usd=?, commission_pct=?, commission_usd=?,
              date_paid=?, initial_request=?, dropbox_url=?, notes=?
            WHERE id=?
        ")->execute([
            $v['practice_code']   ?: null,
            $v['date_received'],
            $v['customer_name'],
            $v['email']           ?: null,
            $v['source'],
            $v['agent_id']        ?: null,
            $v['destination']     ?: null,
            $v['period']          ?: null,
            $v['pax']             ?: null,
            $v['status'],
            $v['value_usd']       !== '' ? $v['value_usd']      : null,
            $v['commission_pct']  !== '' ? $v['commission_pct'] : null,
            $v['commission_usd']  !== '' ? $v['commission_usd'] : null,
            $v['date_paid']       ?: null,
            $v['initial_request'] ?: null,
            $v['dropbox_url']     ?: null,
            $v['notes']           ?: null,
            $id,
        ]);
        flash('Request updated successfully.');
        header("Location: requests.php");
        exit;
    }
}

include 'includes/header.php';
?>

<div class="page-header">
  <div>
    <h2>Edit Request</h2>
    <div class="sub"><a href="request_view.php?id=<?= $id ?>" class="text-muted" style="text-decoration:none">← <?= h($req['customer_name']) ?></a></div>
  </div>
</div>

<?php if ($errors): ?>
  <div class="flash flash-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<div class="form-card">
  <form method="POST">

    <div class="form-section-title" style="margin-top:0">Request Details</div>
    <div class="form-grid">

      <div class="form-group">
        <label>Date Received *</label>
        <input type="date" name="date_received" value="<?= h($v['date_received']) ?>" required>
      </div>

      <div class="form-group">
        <label>Customer Name *</label>
        <input type="text" id="customer_name" name="customer_name" value="<?= h($v['customer_name']) ?>" required autocomplete="off">
        <div id="dup-warning" style="display:none;margin-top:6px"></div>
      </div>

      <div class="form-group">
        <label>Source</label>
        <select name="source">
          <?php foreach (SOURCES as $s): ?>
            <option value="<?= h($s) ?>" <?= $v['source']===$s?'selected':'' ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Assigned Agent</label>
        <select name="agent_id">
          <option value="">— Unassigned —</option>
          <?php foreach ($agents as $ag): ?>
            <option value="<?= $ag['id'] ?>" <?= (string)$v['agent_id']===(string)$ag['id']?'selected':'' ?>><?= h($ag['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Request Type</label>
        <select name="destination">
          <option value="">— Select —</option>
          <?php foreach (['Safari','Kilimanjaro','Safari+Beach','Meru Trekking','Tailor-made','Other'] as $dt): ?>
            <option value="<?= h($dt) ?>" <?= $v['destination']===$dt?'selected':'' ?>><?= h($dt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Period</label>
        <input type="text" name="period" value="<?= h($v['period']) ?>"
               placeholder="e.g. jul-aug, Christmas/NY">
      </div>

      <div class="form-group">
        <label>Pax</label>
        <input type="number" name="pax" value="<?= h($v['pax']) ?>" min="1">
      </div>

      <div class="form-group">
        <label>Status</label>
        <select name="status">
          <?php foreach (STATUSES as $s => $_): ?>
            <option value="<?= h($s) ?>" <?= $v['status']===$s?'selected':'' ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Dropbox Folder</label>
        <input type="text" name="practice_code" value="<?= h($v['practice_code']) ?>"
               placeholder="e.g. JohnBrown(GoWorld-PS-Roberto)">
      </div>

      <div class="form-group full">
        <label>Dropbox Folder Link</label>
        <input type="url" name="dropbox_url" value="<?= h($v['dropbox_url']) ?>"
               placeholder="https://www.dropbox.com/home/…">
      </div>

    </div>

    <div class="form-section-title">Financials</div>
    <div class="form-grid">

      <div class="form-group">
        <label>Value (USD)</label>
        <input type="number" id="value_usd" name="value_usd" value="<?= h($v['value_usd']) ?>"
               step="0.01" min="0" oninput="calcComm()">
      </div>

      <div class="form-group">
        <label>Commission %</label>
        <input type="number" id="commission_pct" name="commission_pct" value="<?= h($v['commission_pct']) ?>"
               step="0.01" min="0" max="100" oninput="calcComm()">
      </div>

      <div class="form-group">
        <label>Commission (USD) — auto-calculated</label>
        <div class="calc-display" id="comm_display">
          <?= $v['commission_usd'] ? '$ '.number_format((float)$v['commission_usd'],2) : '$ —' ?>
        </div>
        <input type="hidden" id="commission_usd" name="commission_usd" value="<?= h($v['commission_usd']) ?>">
      </div>

      <div class="form-group">
        <label>Date Paid</label>
        <input type="date" name="date_paid" value="<?= h($v['date_paid']) ?>">
      </div>

    </div>

    <div class="form-section-title">Notes</div>
    <div class="form-grid">

      <div class="form-group full">
        <label>Initial Request</label>
        <textarea name="initial_request" class="tall"><?= h($v['initial_request']) ?></textarea>
      </div>

      <div class="form-group full">
        <label>Internal Notes</label>
        <textarea name="notes"><?= h($v['notes']) ?></textarea>
      </div>

    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-red">Save Changes</button>
      <a href="requests.php" class="btn btn-outline">Cancel</a>
      <a href="request_delete.php?id=<?= $id ?>" class="btn btn-danger"
         style="margin-left:auto"
         onclick="return confirm('Delete this request permanently?')">Delete</a>
    </div>

  </form>
</div>

<script>
function calcComm() {
  const val  = parseFloat(document.getElementById('value_usd').value)      || 0;
  const pct  = parseFloat(document.getElementById('commission_pct').value) || 0;
  const comm = val * pct / 100;
  const display = document.getElementById('comm_display');
  const hidden  = document.getElementById('commission_usd');
  if (val > 0 && pct > 0) {
    display.textContent = '$ ' + comm.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
    hidden.value = comm.toFixed(2);
  } else {
    display.textContent = '$ —';
    hidden.value = '';
  }
}

// ── Duplicate detection ──────────────────────────────────────────
(function(){
  const EXCLUDE_ID = <?= $id ?>;
  const COLORS = {
    high:   { bg:'#FEE2E2', border:'#C0211B', icon:'🔴', label:'Probabile duplicato' },
    medium: { bg:'#FEF9C3', border:'#CA8A04', icon:'🟡', label:'Nome molto simile' },
    low:    { bg:'#F0F9FF', border:'#0284C7', icon:'🔵', label:'Stesso nome/cognome' },
  };
  let debounce;
  const field   = document.getElementById('customer_name');
  const warning = document.getElementById('dup-warning');

  // Check on load (in case of POST error repopulation)
  if (field.value.trim().length >= 3) checkDuplicates(field.value.trim());

  field.addEventListener('input', function(){
    clearTimeout(debounce);
    const val = this.value.trim();
    if (val.length < 3) { warning.style.display='none'; return; }
    debounce = setTimeout(() => checkDuplicates(val), 400);
  });

  function checkDuplicates(name) {
    fetch('check_duplicate.php?name=' + encodeURIComponent(name) + '&exclude_id=' + EXCLUDE_ID)
      .then(r => r.json())
      .then(data => renderWarning(data))
      .catch(() => {});
  }

  function renderWarning(matches) {
    if (!matches.length) { warning.style.display='none'; return; }
    const top = matches[0];
    const c   = COLORS[top.level];
    let html  = `<div style="background:${c.bg};border:1px solid ${c.border};border-radius:6px;padding:8px 12px;font-size:.8rem;">`;
    html += `<strong>${c.icon} ${c.label}</strong><ul style="margin:4px 0 0 16px;padding:0">`;
    matches.forEach(m => {
      html += `<li style="margin:2px 0"><a href="request_view.php?id=${m.id}" target="_blank" style="color:inherit;font-weight:600">${escHtml(m.name)}</a> <span style="color:#6B7280">— ${escHtml(m.reason)}</span></li>`;
    });
    html += `</ul></div>`;
    warning.innerHTML = html;
    warning.style.display = '';
  }

  function escHtml(s){ const d=document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }
})();
</script>

<?php include 'includes/footer.php'; ?>
