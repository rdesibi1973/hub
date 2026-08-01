<?php
/**
 * import_folder.php
 *
 * Import a confirmed group's Dropbox folder into `requests`.
 *
 * Flow:
 *   1. Operator picks the confirmed folder (moved into 001_Safari).
 *      The browser reports every file's webkitRelativePath; JS takes the top
 *      segment = the folder name (nothing is uploaded — only the name is sent).
 *   2. JS calls api_import_folder_parse.php → parsed fields + duplicate check.
 *   3. Operator reviews / edits the fields, resolves any duplicate, confirms.
 *   4. This page (POST) inserts a new request, or updates the chosen existing one.
 */

require_once 'config.php';
require_once 'includes/folder_parser.php';
requireLogin();

if (isLeadsRestricted()) {
    flash('You are not authorised to import group folders.', 'error');
    header('Location: requests.php');
    exit;
}

$pageTitle = 'Import Group Folder';
$db        = db();
$errors    = [];

// Allowed statuses: the standard set plus "Provisional" (used by folder tags).
$allowedStatuses = array_keys(STATUSES);
if (!in_array('Provisional', $allowedStatuses, true)) $allowedStatuses[] = 'Provisional';

// ── POST: confirm + insert/update ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $folder        = trim($_POST['group_folder']  ?? '');
    $mode          = ($_POST['mode'] ?? 'create') === 'update' ? 'update' : 'create';
    $targetId      = (int)($_POST['target_id']    ?? 0);
    $customerName  = trim($_POST['customer_name'] ?? '');
    $source        = trim($_POST['source']        ?? '');
    $agentId       = (int)($_POST['agent_id']     ?? 0);
    $destination   = trim($_POST['destination']   ?? '');
    $period        = trim($_POST['period']        ?? '');
    $startDate     = trim($_POST['start_date']    ?? '');
    $status        = trim($_POST['status']        ?? 'Booked');
    $paymentStatus = trim($_POST['payment_status'] ?? '');
    $pax           = trim($_POST['pax']           ?? '');
    $valueUsd      = trim($_POST['value_usd']     ?? '');
    $notes         = trim($_POST['notes']         ?? '');

    // ── Validate ──────────────────────────────────────────────────────────────
    if ($folder === '')        $errors[] = 'Folder name is missing.';
    if ($customerName === '')  $errors[] = 'Group / customer name is required.';
    if (!in_array($status, $allowedStatuses, true)) $errors[] = 'Invalid status.';
    $allowedPayment = ['', 'Deposit', 'Balance', 'Balance-Cash', 'Paid'];
    if (!in_array($paymentStatus, $allowedPayment, true)) $errors[] = 'Invalid payment status.';
    if ($startDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        $errors[] = 'Invalid start date.';
    }
    if ($agentId) {
        $chk = $db->prepare("SELECT id FROM agents WHERE id = ?");
        $chk->execute([$agentId]);
        if (!$chk->fetch()) $errors[] = 'Selected agent no longer exists.';
    }
    if ($mode === 'update') {
        $chk = $db->prepare("SELECT id FROM requests WHERE id = ?");
        $chk->execute([$targetId]);
        if (!$chk->fetch()) $errors[] = 'The request to update no longer exists.';
    }

    if (!$errors) {
        $dropboxUrl = 'https://www.dropbox.com/home/001_Safari/' . rawurlencode($folder);
        $paxVal     = $pax      !== '' ? (int)$pax        : null;
        $valVal     = $valueUsd !== '' ? (float)$valueUsd : null;
        $startVal   = $startDate !== '' ? $startDate      : null;

        if ($mode === 'update') {
            $sql = "UPDATE requests SET
                        practice_code     = ?,
                        group_folder      = ?,
                        customer_name     = ?,
                        source            = ?,
                        agent_id          = ?,
                        destination       = ?,
                        period            = ?,
                        status            = ?,
                        payment_status    = ?,
                        start_date        = COALESCE(?, start_date),
                        pax               = COALESCE(?, pax),
                        value_usd         = COALESCE(?, value_usd),
                        confirmation_date = CURDATE(),
                        dropbox_url       = ?,
                        notes             = ?
                    WHERE id = ?";
            $db->prepare($sql)->execute([
                $folder, $folder, $customerName, $source ?: null, $agentId ?: null,
                $destination ?: null, $period ?: null, $status, $paymentStatus ?: null,
                $startVal, $paxVal, $valVal, $dropboxUrl, $notes ?: null, $targetId,
            ]);
            $reqId = $targetId;
            flash("Group folder imported — updated request #{$reqId}. 📁 {$folder}");
        } else {
            $sql = "INSERT INTO requests
                        (practice_code, group_folder, date_received, customer_name, source,
                         agent_id, destination, period, pax, status, payment_status, value_usd,
                         start_date, confirmation_date, notes, dropbox_url, created_at)
                    VALUES (?,?,CURDATE(),?,?,?,?,?,?,?,?,?,?,CURDATE(),?,?,NOW())";
            $db->prepare($sql)->execute([
                $folder, $folder, $customerName, $source ?: null, $agentId ?: null,
                $destination ?: null, $period ?: null, $paxVal, $status, $paymentStatus ?: null,
                $valVal, $startVal, $notes ?: null, $dropboxUrl,
            ]);
            $reqId = (int)$db->lastInsertId();
            flash("Group folder imported — created request #{$reqId}. 📁 {$folder}");
        }

        header('Location: request_view.php?id=' . $reqId);
        exit;
    }
}

$agents = $db->query("SELECT id, name FROM agents WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<div class="page-header">
  <div>
    <h2>Import Confirmed Group Folder</h2>
    <div class="sub"><a href="requests.php" class="text-muted" style="text-decoration:none">← Requests</a></div>
  </div>
</div>

<?php if ($errors): ?>
  <div class="flash flash-error"><?= implode('<br>', array_map('h', $errors)) ?></div>
<?php endif; ?>

<div class="form-card" style="max-width:860px">

  <div class="form-section-title" style="margin-top:0">1 · Select the confirmed folder</div>
  <p class="text-muted" style="margin-top:-4px">
    Pick the group's folder (the one you moved into <strong>001_Safari</strong>).
    Only the folder <em>name</em> is read — no files are uploaded.
  </p>

  <div class="form-grid">
    <div class="form-group">
      <label for="folderPicker">Choose folder</label>
      <input type="file" id="folderPicker" webkitdirectory directory multiple>
    </div>
    <div class="form-group">
      <label for="folderNameManual">…or paste the folder name</label>
      <input type="text" id="folderNameManual" autocomplete="off"
             placeholder="03_02MAR_Panorama05_(Diamante-PS-Roberto)_START02MAR_END09MAR2027_CONFIRMED">
    </div>
  </div>

  <div style="margin-top:6px">
    <button type="button" class="btn" id="parseBtn">Parse folder →</button>
    <span id="detectedName" class="text-muted" style="margin-left:10px;font-size:.85rem;word-break:break-all"></span>
  </div>

  <div id="parseMsg" style="display:none;margin-top:10px"></div>
</div>

<form method="POST" id="importForm" class="form-card" style="display:none;max-width:860px">
  <input type="hidden" name="group_folder" id="f_group_folder">
  <input type="hidden" name="mode"      id="f_mode"      value="create">
  <input type="hidden" name="target_id" id="f_target_id" value="0">

  <div class="form-section-title" style="margin-top:0">2 · Review &amp; confirm</div>

  <div id="dupPanel" style="display:none;margin-bottom:14px"></div>

  <div class="form-grid">
    <div class="form-group">
      <label for="f_customer_name">Group / Customer Name *</label>
      <input type="text" id="f_customer_name" name="customer_name" required autocomplete="off">
    </div>

    <div class="form-group">
      <label for="f_source">Source (Tour Operator)</label>
      <input type="text" id="f_source" name="source" autocomplete="off">
    </div>

    <div class="form-group">
      <label for="f_agent_id">Agent</label>
      <select id="f_agent_id" name="agent_id">
        <option value="">— none —</option>
        <?php foreach ($agents as $a): ?>
          <option value="<?= (int)$a['id'] ?>"><?= h($a['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <div id="agentHint" class="text-muted" style="font-size:.78rem;margin-top:3px"></div>
    </div>

    <div class="form-group">
      <label for="f_destination">Destination</label>
      <input type="text" id="f_destination" name="destination" value="Tanzania" autocomplete="off">
    </div>

    <div class="form-group">
      <label for="f_status">Status</label>
      <select id="f_status" name="status">
        <?php foreach ($allowedStatuses as $s): ?>
          <option value="<?= h($s) ?>" <?= $s === 'Booked' ? 'selected' : '' ?>><?= h($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label for="f_payment_status">Payment Status</label>
      <select id="f_payment_status" name="payment_status">
        <option value="">— None —</option>
        <?php foreach (['Deposit', 'Balance', 'Balance-Cash', 'Paid'] as $ps): ?>
          <option value="<?= h($ps) ?>"><?= h($ps) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label for="f_period">Period</label>
      <input type="text" id="f_period" name="period" autocomplete="off">
    </div>

    <div class="form-group">
      <label for="f_start_date">Start date</label>
      <input type="date" id="f_start_date" name="start_date">
    </div>

    <div class="form-group">
      <label for="f_pax">Pax</label>
      <input type="number" id="f_pax" name="pax" min="0" step="1" placeholder="optional">
    </div>

    <div class="form-group">
      <label for="f_value_usd">Value (USD)</label>
      <input type="number" id="f_value_usd" name="value_usd" min="0" step="0.01" placeholder="optional">
    </div>
  </div>

  <div class="form-group" style="margin-top:8px">
    <label for="f_notes">Notes</label>
    <textarea id="f_notes" name="notes" rows="3"></textarea>
  </div>

  <div style="margin-top:14px;display:flex;gap:10px;align-items:center">
    <button type="submit" class="btn btn-primary" id="submitBtn">Import to HUB</button>
    <span class="text-muted" style="font-size:.82rem">Folder: <code id="folderEcho"></code></span>
  </div>
</form>

<script>
(function () {
  'use strict';

  var picker      = document.getElementById('folderPicker');
  var manual      = document.getElementById('folderNameManual');
  var parseBtn    = document.getElementById('parseBtn');
  var detected    = document.getElementById('detectedName');
  var parseMsg    = document.getElementById('parseMsg');
  var form        = document.getElementById('importForm');
  var dupPanel    = document.getElementById('dupPanel');

  var currentFolder = '';

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function showMsg(html, kind) {
    parseMsg.style.display = 'block';
    parseMsg.className = 'flash flash-' + (kind || 'info');
    parseMsg.innerHTML = html;
  }

  // When a folder is picked, extract the top path segment as the folder name.
  picker.addEventListener('change', function () {
    if (!picker.files || !picker.files.length) return;
    var rel = picker.files[0].webkitRelativePath || picker.files[0].name;
    var top = rel.split('/')[0];
    manual.value = top;
    detected.textContent = 'Detected: ' + top;
  });

  parseBtn.addEventListener('click', function () {
    var folder = (manual.value || '').trim();
    if (!folder) { showMsg('Pick a folder or paste a folder name first.', 'error'); return; }
    parseBtn.disabled = true;
    showMsg('Parsing…', 'info');

    fetch('api_import_folder_parse.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ folder: folder })
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      parseBtn.disabled = false;
      if (d.error) { showMsg('Error: ' + esc(d.error), 'error'); return; }
      fillForm(d);
    })
    .catch(function (e) {
      parseBtn.disabled = false;
      showMsg('Request failed: ' + esc(e.message), 'error');
    });
  });

  function fillForm(d) {
    currentFolder = d.folder || '';

    var msgs = [];
    if (d.errors && d.errors.length)   msgs.push('<strong>Problems:</strong> ' + d.errors.map(esc).join('; '));
    if (d.warnings && d.warnings.length) msgs.push('<strong>Notes:</strong> ' + d.warnings.map(esc).join('; '));
    if (msgs.length) showMsg(msgs.join('<br>'), (d.errors && d.errors.length) ? 'error' : 'info');
    else { parseMsg.style.display = 'none'; }

    document.getElementById('f_group_folder').value = currentFolder;
    document.getElementById('f_customer_name').value = d.customer_name || '';
    document.getElementById('f_source').value        = d.tour_operator || '';
    document.getElementById('f_period').value        = d.period || '';
    document.getElementById('f_start_date').value    = d.start_date || '';
    document.getElementById('folderEcho').textContent = currentFolder;

    var statusSel = document.getElementById('f_status');
    if (d.status) {
      for (var i = 0; i < statusSel.options.length; i++) {
        if (statusSel.options[i].value === d.status) { statusSel.selectedIndex = i; break; }
      }
    }

    var paySel = document.getElementById('f_payment_status');
    paySel.value = d.payment_status || '';

    var agentSel  = document.getElementById('f_agent_id');
    var agentHint = document.getElementById('agentHint');
    if (d.agent_id_suggested) {
      agentSel.value = String(d.agent_id_suggested);
      agentHint.textContent = 'Matched handler "' + (d.handler || '') + '" → ' + (d.agent_name_suggested || '');
    } else if (d.handler) {
      agentSel.value = '';
      agentHint.textContent = 'Handler "' + d.handler + '" not matched to an agent — pick one manually.';
    } else {
      agentHint.textContent = '';
    }

    // Pre-fill notes with parsing context (handler / agency code).
    var noteBits = [];
    if (d.handler)     noteBits.push('Handler: ' + d.handler);
    if (d.agency_code) noteBits.push('Agency code: ' + d.agency_code);
    if (d.tour_operator) noteBits.push('TO: ' + d.tour_operator);
    document.getElementById('f_notes').value = noteBits.join(' · ');

    buildDupPanel(d.duplicates || []);
    form.style.display = 'block';
    document.getElementById('f_customer_name').focus();
  }

  function buildDupPanel(dups) {
    document.getElementById('f_mode').value      = 'create';
    document.getElementById('f_target_id').value = '0';

    if (!dups.length) {
      dupPanel.style.display = 'none';
      dupPanel.innerHTML = '';
      return;
    }

    var top = dups[0];
    var strong = (top.level === 'exact' || top.level === 'high');

    var html = '';
    html += '<div class="flash flash-' + (strong ? 'error' : 'info') + '" style="margin:0 0 8px">';
    html += '<strong>' + dups.length + ' possible duplicate' + (dups.length > 1 ? 's' : '') + ' found.</strong> ';
    html += 'Choose whether to update an existing request or create a new one.';
    html += '</div>';

    html += '<table style="width:100%;border-collapse:collapse;font-size:.85rem">';
    html += '<tr style="text-align:left;color:var(--grey-mid)">'
          + '<th style="padding:4px 6px"></th><th style="padding:4px 6px">#</th>'
          + '<th style="padding:4px 6px">Customer</th><th style="padding:4px 6px">Status</th>'
          + '<th style="padding:4px 6px">Why</th></tr>';

    // "Create new" option
    html += '<tr>'
          + '<td style="padding:4px 6px"><input type="radio" name="dupChoice" value="create" id="dup_create"' + (strong ? '' : ' checked') + '></td>'
          + '<td style="padding:4px 6px" colspan="4"><label for="dup_create"><strong>Create a new request</strong></label></td>'
          + '</tr>';

    for (var i = 0; i < dups.length; i++) {
      var m = dups[i];
      var checked = (strong && i === 0) ? ' checked' : '';
      html += '<tr style="border-top:1px solid #eee">'
        + '<td style="padding:4px 6px"><input type="radio" name="dupChoice" value="update:' + m.id + '" id="dup_' + m.id + '"' + checked + '></td>'
        + '<td style="padding:4px 6px"><label for="dup_' + m.id + '">' + m.id + '</label></td>'
        + '<td style="padding:4px 6px">' + esc(m.customer_name) + '</td>'
        + '<td style="padding:4px 6px">' + esc(m.status) + '</td>'
        + '<td style="padding:4px 6px">' + esc(m.reason) + (m.period ? ' <span style="color:var(--grey-mid)">(' + esc(m.period) + ')</span>' : '') + '</td>'
        + '</tr>';
    }
    html += '</table>';

    dupPanel.innerHTML = html;
    dupPanel.style.display = 'block';

    var radios = dupPanel.querySelectorAll('input[name="dupChoice"]');
    for (var j = 0; j < radios.length; j++) {
      radios[j].addEventListener('change', applyDupChoice);
    }
    applyDupChoice();
  }

  function applyDupChoice() {
    var sel = dupPanel.querySelector('input[name="dupChoice"]:checked');
    var modeEl   = document.getElementById('f_mode');
    var targetEl = document.getElementById('f_target_id');
    var btn      = document.getElementById('submitBtn');
    if (!sel || sel.value === 'create') {
      modeEl.value = 'create';
      targetEl.value = '0';
      btn.textContent = 'Import as new request';
    } else {
      var id = sel.value.split(':')[1];
      modeEl.value = 'update';
      targetEl.value = id;
      btn.textContent = 'Update request #' + id;
    }
  }

  form.addEventListener('submit', function (e) {
    var mode = document.getElementById('f_mode').value;
    var id   = document.getElementById('f_target_id').value;
    if (mode === 'update' && !confirm('Update existing request #' + id + ' with this confirmed folder?')) {
      e.preventDefault();
    }
  });
})();
</script>

<?php include 'includes/footer.php'; ?>
