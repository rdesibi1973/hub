<?php
require_once 'config.php';
$pageTitle = 'Edit Request';
$db = db();

$id = (int)($_GET['id'] ?? 0);

// ── Access control ─────────────────────────────────────────────────────────
// Admin/manager: full edit.
// Staff: can edit only their own requests (limited fields).
// Others: redirect to view.
$isRestricted = isLeadsRestricted();
$staffAgentId = $isRestricted ? getStaffAgentId() : 0;

$row = $db->prepare("SELECT * FROM requests WHERE id = ?");
$row->execute([$id]);
$req = $row->fetch();
if (!$req) { flash('Request not found.', 'error'); header('Location: requests.php'); exit; }

if ($isRestricted) {
    // Staff may only edit their own requests
    if ((int)$req['agent_id'] !== $staffAgentId) {
        header('Location: request_view.php?id=' . $id);
        exit;
    }
}

$agents = $db->query("SELECT * FROM agents WHERE active=1 ORDER BY name")->fetchAll();
$errors = [];

// Fields editable by admin/manager (full set)
$fullFields = ['practice_code','date_received','customer_name','email','whatsapp','source','agent_id',
               'destination','period','pax','status','value_usd','commission_pct','commission_usd',
               'date_paid','initial_request','dropbox_url','notes'];

// Fields editable by staff (restricted set — no financials, no status, no agent reassignment)
$staffFields = ['customer_name','email','whatsapp','source','destination','period','pax',
                'initial_request','dropbox_url','notes'];

$editableFields = $isRestricted ? $staffFields : $fullFields;

// Populate $v from DB on first load
$v = $req;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($editableFields as $f) {
        $v[$f] = trim($_POST[$f] ?? '');
    }

    // Auto-calculate commission (admin/manager only)
    if (!$isRestricted && $v['value_usd'] !== '' && $v['commission_pct'] !== '') {
        $v['commission_usd'] = round((float)$v['value_usd'] * (float)$v['commission_pct'] / 100, 2);
    }

    if (!$v['customer_name']) $errors[] = 'Customer name is required.';
    if (!$isRestricted && !$v['date_received']) $errors[] = 'Date received is required.';

    // ── Dropbox folder rename when practice_code changes (admin/manager only) ──
    // Triggered by ANY change to the Dropbox Folder field (including when driven by
    // a customer-name edit via JS). Uses old DB value → new POST value as from/to.
    $dropboxRenamed = false;
    if (!$errors && !$isRestricted) {
        $oldFolder = trim($req['practice_code'] ?? '');
        $newFolder = trim($v['practice_code']  ?? '');

        if ($oldFolder !== '' && $newFolder !== '' && $oldFolder !== $newFolder) {
            // Derive the full Dropbox API paths from the stored dropbox_url.
            // URL format: https://www.dropbox.com/home/2026/FolderName
            //              → API path: /2026/FolderName
            $oldUrl        = trim($req['dropbox_url'] ?? '');
            $dropboxPrefix = 'https://www.dropbox.com/home';
            $fromPath      = '';
            $toPath        = '';

            if (str_starts_with($oldUrl, $dropboxPrefix)) {
                $apiPath   = urldecode(substr($oldUrl, strlen($dropboxPrefix)));
                $lastSlash = strrpos($apiPath, '/');
                if ($lastSlash !== false) {
                    $parentPath = substr($apiPath, 0, $lastSlash);
                    $fromPath   = $apiPath;
                    $toPath     = $parentPath . '/' . $newFolder;
                }
            }

            // Fallback: guess parent from status when dropbox_url is missing
            if ($fromPath === '') {
                $parentPath = ($req['status'] === 'Booked') ? '/001_Safari' : '/2026';
                $fromPath   = $parentPath . '/' . $oldFolder;
                $toPath     = $parentPath . '/' . $newFolder;
            }

            require_once 'dropbox_helper.php';
            try {
                $token = dropbox_get_access_token();
                dropbox_move_folder($token, $fromPath, $toPath);

                // Success — rebuild dropbox_url to match the renamed folder
                if ($oldUrl !== '') {
                    $lastSlash        = strrpos($oldUrl, '/');
                    $v['dropbox_url'] = substr($oldUrl, 0, $lastSlash + 1) . rawurlencode($newFolder);
                }
                $dropboxRenamed = true;
            } catch (RuntimeException $e) {
                $msg = $e->getMessage();
                error_log("[request_edit] Dropbox rename failed: from=$fromPath to=$toPath — $msg");
                if (str_contains($msg, 'not_found')) {
                    $errors[] = "Dropbox folder not found: \"$oldFolder\" does not exist in Dropbox."
                              . " Update the Dropbox Folder field to match the actual folder name, then save again.";
                } else {
                    $errors[] = "Dropbox rename failed — the request has not been saved."
                              . " Error: " . htmlspecialchars($msg);
                }
            }
        }
    }

    if (!$errors) {
        if ($isRestricted) {
            // Staff: update only their allowed fields
            $db->prepare("
                UPDATE requests SET
                  customer_name=?, email=?, whatsapp=?, source=?, destination=?, period=?,
                  pax=?, initial_request=?, dropbox_url=?, notes=?
                WHERE id=? AND agent_id=?
            ")->execute([
                $v['customer_name'],
                $v['email']           ?: null,
                $v['whatsapp']        ?: null,
                $v['source'],
                $v['destination']     ?: null,
                $v['period']          ?: null,
                $v['pax']             ?: null,
                $v['initial_request'] ?: null,
                $v['dropbox_url']     ?: null,
                $v['notes']           ?: null,
                $id,
                $staffAgentId,
            ]);
        } else {
            // Admin/manager: full update
            $db->prepare("
                UPDATE requests SET
                  practice_code=?, date_received=?, customer_name=?, email=?, whatsapp=?, source=?, agent_id=?,
                  destination=?, period=?, pax=?, status=?, value_usd=?, commission_pct=?, commission_usd=?,
                  date_paid=?, initial_request=?, dropbox_url=?, notes=?
                WHERE id=?
            ")->execute([
                $v['practice_code']   ?: null,
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
                $v['initial_request'] ?: null,
                $v['dropbox_url']     ?: null,
                $v['notes']           ?: null,
                $id,
            ]);
        }
        $flashMsg = $dropboxRenamed ? 'Request updated and Dropbox folder renamed successfully.' : 'Request updated successfully.';
        flash($flashMsg);
        header("Location: request_view.php?id=" . $id);
        exit;
    }
}

include 'includes/header.php';
?>

<div class="page-header">
  <div>
    <h2>Edit Request</h2>
    <div class="sub">
      <a href="request_view.php?id=<?= $id ?>" class="text-muted" style="text-decoration:none">← <?= h($req['customer_name']) ?></a>
      <?php if ($isRestricted): ?>
        &nbsp;·&nbsp; <span style="font-size:.75rem;color:var(--grey-mid)">⚠️ Some fields are managed by the office</span>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($errors): ?>
  <div class="flash flash-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<div class="form-card">
  <form method="POST">

    <div class="form-section-title" style="margin-top:0">Request Details</div>
    <div class="form-grid">

      <?php if (!$isRestricted): ?>
      <div class="form-group">
        <label>Date Received *</label>
        <input type="date" name="date_received" value="<?= h($v['date_received']) ?>" required>
      </div>
      <?php endif; ?>

      <div class="form-group">
        <label>Customer Name *</label>
        <input type="text" id="customer_name" name="customer_name" value="<?= h($v['customer_name']) ?>" required autocomplete="off">
        <div id="dup-warning" style="display:none;margin-top:6px"></div>
      </div>

      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" value="<?= h($v['email']) ?>">
      </div>

      <div class="form-group">
        <label>WhatsApp / Phone</label>
        <input type="text" name="whatsapp" value="<?= h($v['whatsapp'] ?? '') ?>"
               placeholder="e.g. +39 333 1234567">
      </div>

      <div class="form-group">
        <label>Source</label>
        <select name="source">
          <?php foreach (SOURCES as $s): ?>
            <option value="<?= h($s) ?>" <?= $v['source']===$s?'selected':'' ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if (!$isRestricted): ?>
      <div class="form-group">
        <label>Assigned Agent</label>
        <select name="agent_id">
          <option value="">— Unassigned —</option>
          <?php foreach ($agents as $ag): ?>
            <option value="<?= $ag['id'] ?>" <?= (string)$v['agent_id']===(string)$ag['id']?'selected':'' ?>><?= h($ag['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

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

      <?php if (!$isRestricted): ?>
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
      <?php endif; ?>

      <div class="form-group full">
        <label>Dropbox Folder Link</label>
        <input type="url" name="dropbox_url" value="<?= h($v['dropbox_url']) ?>"
               placeholder="https://www.dropbox.com/home/…">
      </div>

    </div>

    <?php if (!$isRestricted): ?>
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
    <?php endif; ?>

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
      <a href="request_view.php?id=<?= $id ?>" class="btn btn-outline">Cancel</a>
      <?php if (!$isRestricted): ?>
        <button type="button"
                onclick="deleteRequest(<?= $req['id'] ?>, '<?= addslashes(h($req['customer_name'])) ?>', '<?= addslashes(h($req['practice_code'] ?? '')) ?>', '<?= addslashes(h($req['status'] ?? '')) ?>', 'requests.php')"
                class="btn btn-danger"
                style="margin-left:auto">Delete</button>
      <?php endif; ?>
    </div>

  </form>
</div>

<script>
<?php if (!$isRestricted): ?>
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
<?php endif; ?>


<?php if (!$isRestricted): ?>
// ── Auto-update Dropbox Folder when customer name changes ────────────────
(function(){
  const nameField   = document.getElementById('customer_name');
  const folderField = document.querySelector('input[name="practice_code"]');
  if (!nameField || !folderField) return;

  nameField.addEventListener('input', function(){
    const folder   = folderField.value;
    const parenIdx = folder.indexOf('(');
    const suffix   = parenIdx >= 0 ? folder.substring(parenIdx) : '';
    folderField.value = this.value.trimStart() + suffix;
  });
})();
<?php endif; ?>

// ── Duplicate detection ──────────────────────────────────────────
(function(){
  const EXCLUDE_ID = <?= $id ?>;
  const COLORS = {
    high:   { bg:'#FEE2E2', border:'#C0211B', icon:'🔴', label:'Possible duplicate' },
    medium: { bg:'#FEF9C3', border:'#CA8A04', icon:'🟡', label:'Very similar name' },
    low:    { bg:'#F0F9FF', border:'#0284C7', icon:'🔵', label:'Same first/last name' },
  };
  let debounce;
  const field   = document.getElementById('customer_name');
  const warning = document.getElementById('dup-warning');

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
