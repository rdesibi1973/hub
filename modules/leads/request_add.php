<?php
require_once 'config.php';
require_once 'dropbox_helper.php';
require_once 'notifications.php';
$pageTitle = 'New Request';
$db = db();

$isRestricted = isLeadsRestricted();   // true = staff, false = admin/manager

$agents   = $db->query("SELECT * FROM agents WHERE active=1 ORDER BY name")->fetchAll();
$agencies = $db->query("SELECT * FROM agencies ORDER BY nome")->fetchAll();

$errors = [];
$v = [
    'date_received'   => date('Y-m-d'),
    'customer_name'   => '',
    'email'           => '',
    'whatsapp'        => '',
    'source'          => 'Email',
    'channel'         => 'direct',
    'agency_id'       => '',
    'agent_id'        => '',
    'destination'     => '',
    'period'          => '',
    'pax'             => '',
    'status'          => 'Inquiry',
    'value_usd'       => '',
    'commission_pct'  => '',
    'commission_usd'  => '',
    'date_paid'       => '',
    'initial_request' => '',
    'notes'           => '',
];

function toCamelCaseRa(string $name): string {
    $name = trim($name);
    if (strpos($name, ' ') === false && strpos($name, '-') === false) return $name;
    return implode('', array_map('ucfirst', array_map('mb_strtolower', preg_split('/[\s\-]+/', $name))));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $dropboxSkip = !empty($_POST['dropbox_skip']);

    foreach ($v as $k => $_) {
        $v[$k] = trim($_POST[$k] ?? '');
    }

    // Staff cannot set status — always force Inquiry
    if ($isRestricted) {
        $v['status']         = 'Inquiry';
        $v['value_usd']      = '';
        $v['commission_pct'] = '';
        $v['commission_usd'] = '';
        $v['date_paid']      = '';
    }

    if ($v['value_usd'] !== '' && $v['commission_pct'] !== '') {
        $v['commission_usd'] = round((float)$v['value_usd'] * (float)$v['commission_pct'] / 100, 2);
    }

    // ── Validate ──────────────────────────────────────────────────────────────
    if (!$v['customer_name'])   $errors[] = 'Customer name is required.';
    if (!$v['date_received'])   $errors[] = 'Date received is required.';
    if (!$dropboxSkip && !$v['initial_request']) $errors[] = 'Initial Request is required.';
    if (!$v['agent_id'])        $errors[] = 'Please select an agent.';
    if (!array_key_exists($v['status'], STATUSES)) $errors[] = 'Invalid status.';
    if ($v['channel'] === 'agency' && !$v['agency_id']) $errors[] = 'Please select an agency.';

    if (!$errors) {

        // ── Build folder name ─────────────────────────────────────────────────
        $agStmt = $db->prepare("SELECT name FROM agents WHERE id = ? LIMIT 1");
        $agStmt->execute([$v['agent_id']]);
        $agRow     = $agStmt->fetch();
        $agentName = $agRow ? str_replace(' ', '', $agRow['name']) : 'Unknown';

        $agencyNome = '';
        if ($v['channel'] === 'agency' && $v['agency_id']) {
            $agencyStmt = $db->prepare("SELECT nome, short_name FROM agencies WHERE id = ? LIMIT 1");
            $agencyStmt->execute([$v['agency_id']]);
            $agencyRow = $agencyStmt->fetch();
            if ($agencyRow) {
                $raw        = $agencyRow['short_name'] ?: $agencyRow['nome'];
                $agencyNome = preg_replace('/[^\w\-]/', '', $raw);
            }
        }

        $namePart = toCamelCaseRa($v['customer_name']);
        switch ($v['channel']) {
            case 'agency': $suffix = "({$agencyNome}-{$agentName})"; break;
            case 'sb':     $suffix = "({$agentName}-SB)";            break;
            case 'other':  $suffix = "({$agentName})";               break;
            default:       $suffix = "({$agentName}-Drct)";          break;
        }
        $folderName    = $namePart . $suffix;
        $dropboxPath   = DROPBOX_BASE_PATH . '/' . $folderName;
        $dropboxWebUrl = 'https://www.dropbox.com/home' . $dropboxPath;

        // ── Create Dropbox folder (unless "already exists" flag is set) ─────
        if (!$dropboxSkip) {
        try {
            $token = dropbox_get_access_token();
            dropbox_create_folder($token, $dropboxPath, true); // throwOnConflict=true

            foreach (['bookings','complain','flights','guestcomments','insurance',
                      'IntFlights','invoices','mails','old','passports','vouchers'] as $sub) {
                try { dropbox_create_folder($token, $dropboxPath . '/' . $sub); }
                catch (RuntimeException $e) { /* non-blocking */ }
            }

            $txtContent =
                "REQUEST DETAILS:\r\n\r\n"
              . $v['initial_request'] . "\r\n\r\n\r\n"
              . "WHATSAPP link\r\n"
              . "Add phone number with international code without + or spaces and use the following link to chat with customer on whatsapp web\r\n"
              . "https://web.whatsapp.com/send?phone=\r\n\r\n"
              . "CUSTOMERS FULL NAMES:\r\n\r\n\r\n\r\n"
              . "ARRIVAL/DEPARTURE DETAILS - FLIGHTS:\r\n\r\n\r\n\r\n\r\n\r\n"
              . "DIETARY RESTRICTIONS:\r\n\r\n\r\n\r\n"
              . "NOTES:\r\n\r\n";
            dropbox_upload_text($token, $dropboxPath . '/CustomerInfo.txt', $txtContent);

        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'already exists') !== false) {
                $errors[] = "Dropbox folder already exists: <strong>{$folderName}</strong> — check for duplicates before proceeding.";
            } else {
                $errors[] = "Dropbox error: {$msg}";
            }
        }
        } // end !$dropboxSkip
    }

    if (!$errors) {

        // ── INSERT ────────────────────────────────────────────────────────────
        $db->prepare("
            INSERT INTO requests
              (practice_code, date_received, customer_name, email, whatsapp, source, agent_id,
               destination, period, pax, status, value_usd, commission_pct, commission_usd,
               date_paid, initial_request, dropbox_url, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $folderName,
            $v['date_received'],
            $v['customer_name'],
            $v['email']           ?: null,
            $v['whatsapp']        ?: null,
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
            $dropboxSkip ? null : ($v['initial_request'] ?: null),
            $dropboxWebUrl,
            $v['notes']           ?: null,
        ]);
        $newReqId = (int)$db->lastInsertId();

        // ── Notify agent ──────────────────────────────────────────────────────
        $doNotify = !empty($_POST['notify_agent']);
        $cu       = current_user();
        $notif    = notify_agent_new_request(
            $db, (int)$v['agent_id'], (int)($cu['id'] ?? 0),
            $newReqId, $v['customer_name'], $folderName, $doNotify
        );

        $flashMsg = "Request created. 📁 Folder: {$folderName}"
                  . ($dropboxSkip ? " — Dropbox folder skipped (already exists)." : '')
                  . ($notif['sent'] ? " — ✉ Notification sent to agent." : '');
        flash($flashMsg);
        if ($notif['error']) flash('⚠ ' . htmlspecialchars($notif['error']), 'error');

        header('Location: request_view.php?id=' . $newReqId);
        exit;
    }
}

include 'includes/header.php';
?>

<div class="page-header">
  <div>
    <h2>New Request</h2>
    <div class="sub"><a href="requests.php" class="text-muted" style="text-decoration:none">← Requests</a></div>
  </div>
</div>

<?php if ($errors): ?>
  <div class="flash flash-error"><?= implode('<br>', $errors) ?></div>
<?php endif; ?>

<div class="form-card">
  <form method="POST" id="request-form">

    <div class="form-section-title" style="margin-top:0">Request Details</div>
    <div class="form-grid">

      <div class="form-group">
        <label for="date_received">Date Received *</label>
        <input type="date" id="date_received" name="date_received"
               value="<?= h($v['date_received']) ?>" required>
      </div>

      <div class="form-group">
        <label for="customer_name">Customer Name *</label>
        <input type="text" id="customer_name" name="customer_name"
               value="<?= h($v['customer_name']) ?>"
               placeholder="e.g. John Brown" required autocomplete="off">
        <div id="dup-warning" style="display:none;margin-top:6px"></div>
      </div>

      <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= h($v['email']) ?>"
               placeholder="e.g. john@example.com" autocomplete="off">
        <div id="email-dup-warning" style="display:none;margin-top:6px"></div>
      </div>

      <div class="form-group">
        <label for="whatsapp">WhatsApp / Phone</label>
        <input type="text" id="whatsapp" name="whatsapp" value="<?= h($v['whatsapp']) ?>"
               placeholder="e.g. +39 333 1234567" autocomplete="off">
      </div>

      <div class="form-group">
        <label for="source">Source</label>
        <select id="source" name="source">
          <?php foreach (SOURCES as $s): ?>
            <option value="<?= h($s) ?>" <?= $v['source']===$s?'selected':'' ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="channel">Channel *</label>
        <select id="channel" name="channel" onchange="updateChannel()">
          <option value="direct" <?= $v['channel']==='direct'?'selected':'' ?>>Direct (Drct)</option>
          <option value="agency" <?= $v['channel']==='agency'?'selected':'' ?>>Agency</option>
          <option value="sb"     <?= $v['channel']==='sb'    ?'selected':'' ?>>Safari Bookings (SB)</option>
          <option value="other"  <?= $v['channel']==='other' ?'selected':'' ?>>Other</option>
        </select>
      </div>

      <div class="form-group" id="agencyRow"
           style="display:<?= $v['channel']==='agency'?'block':'none' ?>">
        <label for="agency_id">Agency *</label>
        <select id="agency_id" name="agency_id" onchange="updateFolderPreview()">
          <option value="">— Select Agency —</option>
          <?php foreach ($agencies as $ag): ?>
            <option value="<?= $ag['id'] ?>"
                    data-short="<?= h($ag['short_name'] ?: $ag['nome']) ?>"
                    <?= $v['agency_id']==(string)$ag['id']?'selected':'' ?>>
              <?= h($ag['nome']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="agent_id">Assigned Agent *</label>
        <select id="agent_id" name="agent_id" onchange="updateFolderPreview()" required>
          <option value="">— Select Agent —</option>
          <?php foreach ($agents as $ag): ?>
            <option value="<?= $ag['id'] ?>"
                    data-name="<?= h(str_replace(' ', '', $ag['name'])) ?>"
                    <?= $v['agent_id']==(string)$ag['id']?'selected':'' ?>>
              <?= h($ag['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="destination">Request Type</label>
        <select id="destination" name="destination">
          <option value="">— Select —</option>
          <?php foreach (['Safari','Kilimanjaro','Safari+Beach','Meru Trekking','Tailor-made','Other'] as $dt): ?>
            <option value="<?= h($dt) ?>" <?= $v['destination']===$dt?'selected':'' ?>><?= h($dt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="period">Period</label>
        <input type="text" id="period" name="period" value="<?= h($v['period']) ?>"
               placeholder="e.g. jul-aug, Christmas/NY">
      </div>

      <div class="form-group">
        <label for="pax">Pax</label>
        <input type="number" id="pax" name="pax" value="<?= h($v['pax']) ?>"
               min="1" placeholder="2">
      </div>

      <div class="form-group">
        <label for="status">Status</label>
        <?php if ($isRestricted): ?>
          <input type="hidden" name="status" value="Inquiry">
          <input type="text" value="Inquiry" disabled style="background:var(--grey-lt);color:var(--grey-mid)">
        <?php else: ?>
        <select id="status" name="status">
          <?php foreach (STATUSES as $s => $_): ?>
            <option value="<?= h($s) ?>" <?= $v['status']===$s?'selected':'' ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </div>

      <!-- Folder preview — auto-generated, read-only -->
      <div class="form-group full" id="folderPreviewRow" style="display:none">
        <label>📁 Dropbox Folder (auto-generated)</label>
        <div id="folderPreviewBox"
             style="background:#F0FDF4;border:1px solid #86EFAC;border-radius:6px;
                    padding:8px 12px;font-family:monospace;font-size:.85rem;
                    color:#166534;word-break:break-all;"></div>
        <div style="margin-top:8px;">
          <label style="display:flex;align-items:center;gap:8px;font-weight:500;cursor:pointer;font-size:.85rem;color:var(--grey-dk);">
            <input type="checkbox" id="dropbox_skip" name="dropbox_skip" value="1"
                   onchange="onDropboxSkipChange()"
                   style="width:14px;height:14px;cursor:pointer;"
                   <?= !empty($_POST['dropbox_skip']) ? 'checked' : '' ?>>
            Dropbox folder already exists — skip creation
          </label>
        </div>
      </div>

    </div>

    <?php if (!$isRestricted): ?>
    <div class="form-section-title">Financials</div>
    <div class="form-grid">

      <div class="form-group">
        <label for="value_usd">Value (USD)</label>
        <input type="number" id="value_usd" name="value_usd"
               value="<?= h($v['value_usd']) ?>"
               step="0.01" min="0" placeholder="0.00" oninput="calcComm()">
      </div>

      <div class="form-group">
        <label for="commission_pct">Commission %</label>
        <input type="number" id="commission_pct" name="commission_pct"
               value="<?= h($v['commission_pct']) ?>"
               step="0.01" min="0" max="100" placeholder="2.00" oninput="calcComm()">
      </div>

      <div class="form-group">
        <label>Commission (USD) — auto-calculated</label>
        <div class="calc-display" id="comm_display">
          <?= $v['commission_usd'] !== '' ? '$ '.number_format((float)$v['commission_usd'], 2) : '$ —' ?>
        </div>
        <input type="hidden" id="commission_usd" name="commission_usd"
               value="<?= h($v['commission_usd']) ?>">
      </div>

      <div class="form-group">
        <label for="date_paid">Date Paid</label>
        <input type="date" id="date_paid" name="date_paid" value="<?= h($v['date_paid']) ?>">
      </div>

    </div>
    <?php endif; ?>

    <div class="form-section-title">Notes</div>
    <div class="form-grid">

      <div class="form-group full">
        <label for="initial_request" id="initial_request_label">Initial Request *</label>
        <textarea id="initial_request" name="initial_request" class="tall"
                  placeholder="Paste the original email, form submission, or WhatsApp message here…"><?= h($v['initial_request']) ?></textarea>
      </div>

      <div class="form-group full">
        <label for="notes">Internal Notes</label>
        <textarea id="notes" name="notes"
                  placeholder="Any internal notes…"><?= h($v['notes']) ?></textarea>
      </div>

    </div>

    <!-- Notify checkbox -->
    <div style="margin:4px 0 18px;">
      <label style="display:flex;align-items:center;gap:8px;font-weight:600;cursor:pointer;">
        <input type="checkbox" name="notify_agent" value="1" checked
               style="width:15px;height:15px;cursor:pointer;">
        Send email notification to assigned agent
      </label>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-red">Save Request</button>
      <a href="requests.php" class="btn btn-outline">Cancel</a>
    </div>
  </form>
</div>

<script>
// ── Channel / agency toggle ───────────────────────────────────────────────────
function updateChannel() {
  const ch = document.getElementById('channel').value;
  document.getElementById('agencyRow').style.display = (ch === 'agency') ? 'block' : 'none';
  if (ch !== 'agency') document.getElementById('agency_id').value = '';
  updateFolderPreview();
}

// ── Live folder preview ───────────────────────────────────────────────────────
function updateFolderPreview() {
  const name     = document.getElementById('customer_name').value.trim();
  const agentSel = document.getElementById('agent_id');
  const agentOpt = agentSel.options[agentSel.selectedIndex];
  const agentName = (agentOpt && agentOpt.value) ? (agentOpt.dataset.name || '') : '';
  const channel  = document.getElementById('channel').value;
  const row      = document.getElementById('folderPreviewRow');
  const box      = document.getElementById('folderPreviewBox');

  if (!name || !agentName) { row.style.display = 'none'; return; }

  const camel = toCamelCase(name);
  let suffix;
  if (channel === 'agency') {
    const agSel   = document.getElementById('agency_id');
    const agOpt   = agSel.options[agSel.selectedIndex];
    const agShort = (agOpt && agOpt.value) ? (agOpt.dataset.short || toCamelCase(agOpt.text)) : '?';
    suffix = `(${agShort}-${agentName})`;
  } else if (channel === 'sb') {
    suffix = `(${agentName}-SB)`;
  } else if (channel === 'other') {
    suffix = `(${agentName})`;
  } else {
    suffix = `(${agentName}-Drct)`;
  }

  box.textContent = camel + suffix;
  row.style.display = 'block';
}

function toCamelCase(name) {
  name = name.trim();
  if (!name.includes(' ') && !name.includes('-')) return name;
  return name.split(/[\s\-]+/).filter(Boolean)
    .map(w => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase())
    .join('');
}

// ── Commission ────────────────────────────────────────────────────────────────
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

// ── Duplicate detection ───────────────────────────────────────────────────────
(function(){
  const COLORS = {
    high:   { bg:'#FEE2E2', border:'#C0211B', icon:'🔴', label:'Probable duplicate'  },
    medium: { bg:'#FEF9C3', border:'#CA8A04', icon:'🟡', label:'Very similar name'   },
    low:    { bg:'#F0F9FF', border:'#0284C7', icon:'🔵', label:'Same first/last name' },
  };
  let debounce;
  const field   = document.getElementById('customer_name');
  const warning = document.getElementById('dup-warning');

  field.addEventListener('input', function(){
    clearTimeout(debounce);
    updateFolderPreview();
    const val = this.value.trim();
    if (val.length < 3) { warning.style.display='none'; return; }
    debounce = setTimeout(() => checkDuplicates(val), 400);
  });

  function checkDuplicates(name) {
    fetch('check_duplicate.php?name=' + encodeURIComponent(name))
      .then(r => r.json()).then(renderWarning).catch(() => {});
  }

  function renderWarning(matches) {
    if (!matches.length) { warning.style.display='none'; return; }
    const top = matches[0], c = COLORS[top.level];
    let html = `<div style="background:${c.bg};border:1px solid ${c.border};border-radius:6px;padding:8px 12px;font-size:.8rem;">`;
    html += `<strong>${c.icon} ${c.label}</strong><ul style="margin:4px 0 0 16px;padding:0">`;
    matches.forEach(m => {
      html += `<li style="margin:2px 0"><a href="request_view.php?id=${m.id}" target="_blank" style="color:inherit;font-weight:600">${esc(m.name)}</a> <span style="color:#6B7280">— ${esc(m.reason)}</span></li>`;
    });
    html += '</ul></div>';
    warning.innerHTML = html;
    warning.style.display = '';
  }

  function esc(s){ const d=document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }
})();

// ── Email duplicate detection + submit guard ──────────────────────────────────
(function(){
  const emailField = document.getElementById('email');
  const emailWarn  = document.getElementById('email-dup-warning');
  const form       = document.getElementById('request-form');
  if (!emailField) return;

  // Cache of duplicate matches found for the current email value
  let emailDupMatches = [];
  let lastCheckedEmail = '';

  // Check on blur (visual feedback while filling form)
  emailField.addEventListener('blur', function(){
    const val = this.value.trim();
    if (!val || !val.includes('@')) {
      emailDupMatches = [];
      lastCheckedEmail = '';
      emailWarn.style.display = 'none';
      return;
    }
    if (val === lastCheckedEmail) return; // already checked
    fetchEmailDups(val);
  });

  function fetchEmailDups(email) {
    const excludeId = <?= json_encode((int)($v['id'] ?? 0)) ?>;
    const url = 'check_duplicate.php?email=' + encodeURIComponent(email)
              + (excludeId ? '&exclude_id=' + excludeId : '');
    return fetch(url).then(r => r.json()).then(matches => {
      emailDupMatches  = matches;
      lastCheckedEmail = email;
      renderEmailWarning(matches);
      return matches;
    }).catch(() => []);
  }

  function renderEmailWarning(matches) {
    if (!matches.length) { emailWarn.style.display='none'; return; }
    let html = '<div style="background:#FEE2E2;border:1px solid #C0211B;border-radius:6px;padding:8px 12px;font-size:.8rem;">';
    html += '<strong>🔴 Same email already on file</strong><ul style="margin:4px 0 0 16px;padding:0">';
    matches.forEach(m => {
      html += `<li style="margin:2px 0"><a href="request_view.php?id=${m.id}" target="_blank" style="color:#991B1B;font-weight:600">${esc(m.name)}</a> <span style="color:#6B7280">— Request #${m.id}</span></li>`;
    });
    html += '</ul></div>';
    emailWarn.innerHTML = html;
    emailWarn.style.display = '';
  }

  // Intercept submit: if email has duplicates, ask for confirmation
  form.addEventListener('submit', function(e) {
    const currentEmail = emailField.value.trim();
    if (!currentEmail || !currentEmail.includes('@')) return; // no email, proceed

    // If we haven't checked this email yet (user never left the field), check now
    if (currentEmail !== lastCheckedEmail) {
      e.preventDefault();
      fetchEmailDups(currentEmail).then(matches => {
        if (!matches.length) {
          form.submit(); // clean — submit normally
          return;
        }
        const names = matches.map(m => `"${m.name}" (Request #${m.id})`).join(', ');
        const ok = confirm(
          '⚠ WARNING — Email already on file!\n\n' +
          'This email address is associated with:\n' + names + '\n\n' +
          'Do you want to create a NEW request anyway?'
        );
        if (ok) form.submit();
      });
      return;
    }

    // Email was already checked and duplicates found — ask confirmation
    if (emailDupMatches.length) {
      e.preventDefault();
      const names = emailDupMatches.map(m => `"${m.name}" (Request #${m.id})`).join(', ');
      const ok = confirm(
        '⚠ WARNING — Email already on file!\n\n' +
        'This email address is associated with:\n' + names + '\n\n' +
        'Do you want to create a NEW request anyway?'
      );
      if (ok) form.submit();
    }
  });

  function esc(s){ const d=document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }
})();

// ── Dropbox skip toggle ───────────────────────────────────────────────────────
function onDropboxSkipChange() {
  const skip = document.getElementById('dropbox_skip').checked;
  const ta   = document.getElementById('initial_request');
  const lbl  = document.getElementById('initial_request_label');
  if (skip) {
    ta.removeAttribute('required');
    ta.style.opacity = '0.45';
    ta.style.background = '#F3F4F6';
    lbl.textContent = 'Initial Request (not saved when skipping Dropbox)';
  } else {
    ta.setAttribute('required', '');
    ta.style.opacity = '';
    ta.style.background = '';
    lbl.textContent = 'Initial Request *';
  }
}

// Init on load (after a POST error, restore skip state)
updateChannel();
onDropboxSkipChange();
</script>

<?php include 'includes/footer.php'; ?>
