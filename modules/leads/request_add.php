<?php
require_once 'config.php';
require_once 'dropbox_helper.php';
require_once 'notifications.php';
$pageTitle = 'New Request';
$db = db();

$isRestricted = isLeadsRestricted();   // true = staff, false = admin/manager

$agents   = $db->query("SELECT * FROM agents WHERE active=1 ORDER BY name")->fetchAll();
$agencies = $db->query("SELECT * FROM agencies ORDER BY nome")->fetchAll();

// Staff (restricted) users may only assign requests to themselves; managers/admins
// see the full agent list. getStaffAgentId() maps the logged-in user → their agent.
$staffAgentId = 0;
if ($isRestricted && function_exists('getStaffAgentId')) {
    $staffAgentId = (int) getStaffAgentId();
}
// Only lock to the staff agent if it actually exists in the active agent list.
$staffAgent = null;
if ($staffAgentId) {
    foreach ($agents as $a) { if ((int)$a['id'] === $staffAgentId) { $staffAgent = $a; break; } }
}
$lockAgent = ($isRestricted && $staffAgent !== null);   // restrict + default the Agent field

// Agency list for the searchable picker (id / display name / short code).
$agencyJs = array_map(fn($a) => [
    'id'    => (int)$a['id'],
    'nome'  => $a['nome'],
    'short' => $a['short_name'] ?: $a['nome'],
], $agencies);

$errors = [];
$dupCandidates = [];   // duplicate matches found before insert (name/email/phone)
$v = [
    'date_received'   => date('Y-m-d'),
    'customer_name'   => '',
    'email'           => '',
    'whatsapp'        => '',
    'source'          => 'Email',
    'channel'         => 'agency',
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

// Staff: default (and lock) the Assigned Agent to their own agent.
if ($lockAgent) $v['agent_id'] = (string)$staffAgentId;

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
    // Staff can only assign to themselves — ignore any posted agent_id.
    if ($lockAgent) $v['agent_id'] = (string)$staffAgentId;

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

    // ── Duplicate check BEFORE inserting (same checks as Incoming) ──────────────
    // Name + email + phone against requests and lead_staging. Strong matches
    // (definite/possible) block until the user ticks "Create anyway".
    if (!$errors && empty($_POST['dup_override'])) {
        require_once 'includes/dup_check.php';
        $dupCandidates = array_values(array_filter(
            find_duplicate_candidates($db, $v['customer_name'], $v['email'], $v['whatsapp']),
            fn($c) => $c['severity'] !== 'weak'
        ));
        if ($dupCandidates) {
            $errors[] = 'Possible duplicate found — review the matches below, then tick "Create anyway" if this really is a new booking.';
        }
    }

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

// Currently-selected agency (repopulate the search box after a POST error).
$selAgencyNome = $selAgencyShort = '';
if (($v['agency_id'] ?? '') !== '') {
    foreach ($agencies as $a) {
        if ((string)$a['id'] === (string)$v['agency_id']) {
            $selAgencyNome  = $a['nome'];
            $selAgencyShort = $a['short_name'] ?: $a['nome'];
            break;
        }
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
        <label>Channel *</label>
        <div style="display:flex;gap:20px;align-items:center;padding-top:4px">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:400">
            <input type="radio" name="channel" value="agency" <?= $v['channel']!=='direct'?'checked':'' ?> onchange="updateChannel()" style="accent-color:#C0211B"> Agency
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:400">
            <input type="radio" name="channel" value="direct" <?= $v['channel']==='direct'?'checked':'' ?> onchange="updateChannel()" style="accent-color:#C0211B"> Direct
          </label>
        </div>
      </div>

      <div class="form-group" id="agencyRow"
           style="display:<?= $v['channel']!=='direct'?'block':'none' ?>">
        <label for="agency_search">Agency *</label>
        <div style="position:relative">
          <div style="display:flex;gap:6px;align-items:stretch">
            <input type="text" id="agency_search" autocomplete="off" placeholder="Type to filter agencies…"
                   value="<?= h($selAgencyNome) ?>"
                   oninput="filterAgencies()" onfocus="filterAgencies()" onkeydown="agencyKeydown(event)"
                   style="flex:1">
            <button type="button" class="btn btn-outline" onclick="openAddAgency()" title="Add a new agency" style="white-space:nowrap">➕ Add</button>
          </div>
          <input type="hidden" id="agency_id" name="agency_id" value="<?= h($v['agency_id']) ?>"
                 data-short="<?= h($selAgencyShort) ?>">
          <div id="agency_list" role="listbox"
               style="position:absolute;z-index:30;left:0;right:0;top:100%;margin-top:2px;max-height:230px;overflow:auto;background:#fff;border:1px solid var(--grey-lt);border-radius:6px;box-shadow:0 6px 18px rgba(0,0,0,.12);display:none"></div>
        </div>
      </div>

      <div class="form-group">
        <label for="agent_id">Assigned Agent *</label>
        <?php if ($lockAgent): ?>
          <!-- Staff: locked to their own agent (single option). -->
          <select id="agent_id" name="agent_id" onchange="updateFolderPreview()" required>
            <option value="<?= (int)$staffAgent['id'] ?>"
                    data-name="<?= h(str_replace(' ', '', $staffAgent['name'])) ?>" selected>
              <?= h($staffAgent['name']) ?>
            </option>
          </select>
        <?php else: ?>
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
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="destination">Request Type</label>
        <select id="destination" name="destination">
          <option value="">— Select —</option>
          <?php foreach (['Safari','Kilimanjaro','Safari+Beach','Meru Trekking','Trekking+Safari','Tailor-made','Other'] as $dt): ?>
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

      <?php if (defined('SHOW_COMMISSIONS') && SHOW_COMMISSIONS): ?>
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
      <?php else: ?>
      <input type="hidden" name="commission_pct" value="<?= h($v['commission_pct']) ?>">
      <input type="hidden" name="commission_usd" value="<?= h($v['commission_usd']) ?>">
      <?php endif; ?>

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

    <?php if ($dupCandidates): ?>
    <div style="border:2px solid #C0211B;background:#FEE2E2;border-radius:8px;padding:14px 16px;margin-bottom:16px">
      <div style="font-weight:700;color:#C0211B;margin-bottom:8px">⚠ Possible duplicate — don't create a second record for the same customer</div>
      <ul style="margin:0 0 10px 18px;padding:0;font-size:.85rem">
        <?php foreach ($dupCandidates as $c): ?>
          <li style="margin-bottom:3px">
            <?php if ($c['source_table'] === 'requests'): ?>
              <a href="request_view.php?id=<?= (int)$c['id'] ?>" target="_blank" rel="noopener" style="font-weight:600"><?= h($c['name']) ?></a>
              <span style="color:#7a1c17">— <?= h($c['reason']) ?> (request #<?= (int)$c['id'] ?>)</span>
            <?php else: ?>
              <span style="font-weight:600"><?= h($c['name']) ?></span>
              <span style="color:#7a1c17">— <?= h($c['reason']) ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <label style="display:flex;align-items:center;gap:8px;font-weight:700;cursor:pointer;color:#7a1c17">
        <input type="checkbox" name="dup_override" value="1" style="width:16px;height:16px;accent-color:#C0211B">
        Create anyway — I checked, this is not a duplicate
      </label>
    </div>
    <?php endif; ?>

    <div class="form-actions">
      <button type="submit" class="btn btn-red">Save Request</button>
      <a href="requests.php" class="btn btn-outline">Cancel</a>
    </div>
  </form>
</div>

<!-- Add Agency modal -->
<div id="addAgencyOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:10px;max-width:430px;width:92%;padding:20px;box-shadow:0 12px 40px rgba(0,0,0,.3)">
    <div style="font-size:1.05rem;font-weight:700;margin-bottom:12px">Add Agency</div>
    <div id="addAgencyError" style="display:none;background:#FEE2E2;border:1px solid #C0211B;color:#991B1B;border-radius:6px;padding:8px 12px;font-size:.82rem;margin-bottom:10px"></div>
    <div class="form-group">
      <label for="aa_nome">Agency name *</label>
      <input type="text" id="aa_nome" autocomplete="off" placeholder="e.g. Go World Travel">
    </div>
    <div class="form-group">
      <label for="aa_short">Short code (optional)</label>
      <input type="text" id="aa_short" autocomplete="off" placeholder="auto-generated from name if blank">
    </div>
    <div class="form-group">
      <label>Type</label>
      <div style="display:flex;gap:16px;padding-top:4px;flex-wrap:wrap">
        <label style="font-weight:400;display:flex;align-items:center;gap:5px;cursor:pointer"><input type="radio" name="aa_type" value="savannah" checked> Savannah</label>
        <label style="font-weight:400;display:flex;align-items:center;gap:5px;cursor:pointer"><input type="radio" name="aa_type" value="promoservice"> Promoservice&nbsp;(-PS)</label>
        <label style="font-weight:400;display:flex;align-items:center;gap:5px;cursor:pointer"><input type="radio" name="aa_type" value="lamprati"> Lamprati&nbsp;(-LAM)</label>
      </div>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
      <button type="button" class="btn btn-outline" onclick="closeAddAgency()">Cancel</button>
      <button type="button" class="btn btn-red" id="aa_submit" onclick="submitAddAgency()">Create agency</button>
    </div>
  </div>
</div>

<script>
// ── Channel / agency toggle ───────────────────────────────────────────────────
function channelValue() {
  const r = document.querySelector('input[name="channel"]:checked');
  return r ? r.value : 'agency';
}
function updateChannel() {
  const ch = channelValue();
  document.getElementById('agencyRow').style.display = (ch === 'agency') ? 'block' : 'none';
  if (ch !== 'agency') clearAgency();   // leaving Agency channel — drop any selection
  updateFolderPreview();
}

// ── Live folder preview ───────────────────────────────────────────────────────
function updateFolderPreview() {
  const name     = document.getElementById('customer_name').value.trim();
  const agentSel = document.getElementById('agent_id');
  const agentOpt = agentSel.options[agentSel.selectedIndex];
  const agentName = (agentOpt && agentOpt.value) ? (agentOpt.dataset.name || '') : '';
  const channel  = channelValue();
  const row      = document.getElementById('folderPreviewRow');
  const box      = document.getElementById('folderPreviewBox');

  if (!name || !agentName) { row.style.display = 'none'; return; }

  const camel = toCamelCase(name);
  let suffix;
  if (channel === 'agency') {
    const agHidden = document.getElementById('agency_id');
    const agShort  = (agHidden && agHidden.value) ? (agHidden.dataset.short || '?') : '?';
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
  const pctEl   = document.getElementById('commission_pct');
  const display = document.getElementById('comm_display');
  const hidden  = document.getElementById('commission_usd');
  if (!pctEl || !display || !hidden) return; // commissions hidden — nothing to calc
  const val  = parseFloat(document.getElementById('value_usd').value) || 0;
  const pct  = parseFloat(pctEl.value) || 0;
  const comm = val * pct / 100;
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
    // If "Create anyway" is ticked, the server-side guard is overriding — don't
    // also prompt here.
    if (document.querySelector('input[name="dup_override"]:checked')) return;
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

// ── Agency searchable picker ──────────────────────────────────────────────────
window.AGENCIES = <?= json_encode($agencyJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function agencyEls() {
  return {
    search: document.getElementById('agency_search'),
    hidden: document.getElementById('agency_id'),
    list:   document.getElementById('agency_list'),
  };
}

function filterAgencies() {
  const { search, list } = agencyEls();
  if (!search || !list) return;
  const q = search.value.trim().toLowerCase();
  const matches = window.AGENCIES.filter(a =>
    !q || a.nome.toLowerCase().includes(q) || (a.short || '').toLowerCase().includes(q)
  ).slice(0, 60);
  if (!matches.length) {
    list.innerHTML = '<div style="padding:8px 12px;color:var(--grey-mid);font-size:.82rem">No match — use ➕ Add to create it</div>';
    list.style.display = 'block';
    return;
  }
  list.innerHTML = matches.map(a =>
    '<div class="agency-opt" data-id="' + a.id + '" style="padding:7px 12px;cursor:pointer;font-size:.85rem;border-bottom:1px solid #f1f1f1">' +
      escAg(a.nome) + ' <span style="color:var(--grey-mid);font-size:.75rem">' + escAg(a.short) + '</span></div>'
  ).join('');
  list.style.display = 'block';
  list.querySelectorAll('.agency-opt').forEach(el => {
    el.addEventListener('mousedown', function (e) { e.preventDefault(); selectAgency(parseInt(this.dataset.id, 10)); });
    el.addEventListener('mouseenter', function () { this.style.background = '#F0FDF4'; });
    el.addEventListener('mouseleave', function () { this.style.background = ''; });
  });
}

function selectAgency(id) {
  const { search, hidden, list } = agencyEls();
  const a = window.AGENCIES.find(x => x.id === id);
  if (!a) return;
  hidden.value = a.id;
  hidden.dataset.short = a.short || '';
  if (search) search.value = a.nome;
  if (list) list.style.display = 'none';
  updateFolderPreview();
}

function clearAgency() {
  const { search, hidden, list } = agencyEls();
  if (hidden) { hidden.value = ''; hidden.dataset.short = ''; }
  if (search) search.value = '';
  if (list) list.style.display = 'none';
}

function agencyKeydown(e) {
  if (e.key === 'Escape') { const { list } = agencyEls(); if (list) list.style.display = 'none'; }
}

function escAg(s){ const d=document.createElement('div'); d.appendChild(document.createTextNode(s||'')); return d.innerHTML; }

// Close the dropdown on an outside click.
document.addEventListener('click', function (e) {
  const { search, list } = agencyEls();
  if (!list) return;
  if (e.target === search || list.contains(e.target)) return;
  list.style.display = 'none';
});

// ── Add Agency modal ──────────────────────────────────────────────────────────
function openAddAgency() {
  const ov  = document.getElementById('addAgencyOverlay');
  const pre = document.getElementById('agency_search');
  document.getElementById('aa_nome').value  = (pre && pre.value.trim()) || '';
  document.getElementById('aa_short').value = '';
  document.getElementById('addAgencyError').style.display = 'none';
  ov.style.display = 'flex';
  document.getElementById('aa_nome').focus();
}
function closeAddAgency() { document.getElementById('addAgencyOverlay').style.display = 'none'; }

function submitAddAgency() {
  const nome   = document.getElementById('aa_nome').value.trim();
  const short  = document.getElementById('aa_short').value.trim();
  const type   = (document.querySelector('input[name="aa_type"]:checked') || {}).value || 'savannah';
  const errBox = document.getElementById('addAgencyError');
  const btn    = document.getElementById('aa_submit');
  if (!nome) { errBox.textContent = 'Agency name is required.'; errBox.style.display = 'block'; return; }
  btn.disabled = true; btn.textContent = 'Saving…';
  const body = new URLSearchParams({ nome: nome, short_name: short, type: type });
  fetch('ajax_create_agency.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
    .then(r => r.json().then(j => ({ ok: r.ok, j: j })))
    .then(({ ok, j }) => {
      // Success, or a duplicate that still hands back the existing id → select it.
      if (!j || (!j.success && !j.id)) throw new Error((j && j.message) || 'Could not create the agency.');
      if (!window.AGENCIES.some(a => a.id === j.id)) {
        window.AGENCIES.push({ id: j.id, nome: j.nome, short: j.short_name });
        window.AGENCIES.sort((a, b) => a.nome.localeCompare(b.nome));
      }
      selectAgency(j.id);
      closeAddAgency();
    })
    .catch(err => { errBox.textContent = err.message; errBox.style.display = 'block'; })
    .finally(() => { btn.disabled = false; btn.textContent = 'Create agency'; });
}

// Require an agency (via the picker) when the channel is Agency. Capture phase so
// this runs before the email-duplicate submit handler.
document.getElementById('request-form').addEventListener('submit', function (e) {
  if (channelValue() !== 'agency') return;
  const hidden = document.getElementById('agency_id');
  const search = document.getElementById('agency_search');
  // Auto-select when the typed text exactly matches one agency name.
  if (!hidden.value && search) {
    const typed = search.value.trim().toLowerCase();
    const m = typed ? window.AGENCIES.find(a => a.nome.toLowerCase() === typed) : null;
    if (m) selectAgency(m.id);
  }
  if (!hidden.value) {
    e.preventDefault();
    e.stopImmediatePropagation();
    alert('Please select an agency (type to filter, or use ➕ Add).');
    if (search) search.focus();
  }
}, true);

// Init on load (after a POST error, restore skip state)
updateChannel();
onDropboxSkipChange();
</script>

<?php include 'includes/footer.php'; ?>
