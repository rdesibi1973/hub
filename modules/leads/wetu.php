<?php
require_once 'config.php';
requireLogin();

/* ═══════════════════════════════════════════════════════════════
   WETU SOAP HELPER
═══════════════════════════════════════════════════════════════ */
define('WETU_WSDL', 'https://wetu.com/api/itineraryservicev8.asmx?WSDL');

function wetu_client(): SoapClient {
    return new SoapClient(WETU_WSDL, [
        'exceptions' => true,
        'trace'      => false,
        'cache_wsdl' => WSDL_CACHE_DISK,
        'encoding'   => 'UTF-8',
    ]);
}

/* ═══════════════════════════════════════════════════════════════
   STATE
═══════════════════════════════════════════════════════════════ */
$wetu_error   = '';
$wetu_success = '';
$samples      = [];
$created      = null;
$token        = $_SESSION['wetu_token']    ?? null;
$wetu_user    = $_SESSION['wetu_user']     ?? '';
$wetu_op      = $_SESSION['wetu_operator'] ?? '';
$action       = $_POST['action'] ?? ($_GET['action'] ?? '');

/* Pre-fill from GET params (when arriving from request_view / quote_view) */
$prefill_name = trim($_GET['client_name'] ?? '');
$prefill_ref  = trim($_GET['ref_number']  ?? '');
$prefill_pax  = max(1, intval($_GET['pax'] ?? 2));
$prefill_days = max(0, intval($_GET['days'] ?? 0));
$prefill_date = trim($_GET['start_date']  ?? '');

/* ═══════════════════════════════════════════════════════════════
   ACTION: WETU LOGOUT
═══════════════════════════════════════════════════════════════ */
if ($action === 'wetu_logout') {
    unset($_SESSION['wetu_token'], $_SESSION['wetu_user'], $_SESSION['wetu_operator'], $_SESSION['wetu_pass']);
    header('Location: wetu.php');
    exit;
}

/* ═══════════════════════════════════════════════════════════════
   ACTION: WETU LOGIN
═══════════════════════════════════════════════════════════════ */
if ($action === 'wetu_login') {
    $u = trim($_POST['wetu_username'] ?? '');
    $p = trim($_POST['wetu_password'] ?? '');
    if (!$u || !$p) {
        $wetu_error = 'Please enter both username and password.';
    } else {
        try {
            $res  = wetu_client()->AuthenticateUser(['username' => $u, 'password' => $p]);
            $sess = $res->AuthenticateUserResult ?? null;
            if ($sess && !empty($sess->SessionToken)) {
                $_SESSION['wetu_token']    = $sess->SessionToken;
                $_SESSION['wetu_user']     = $u;
                $_SESSION['wetu_pass']     = $p;   // kept server-side for JSON REST API
                $_SESSION['wetu_operator'] = $sess->OperatorName ?? '';
                $token    = $sess->SessionToken;
                $wetu_user = $u;
                $wetu_op   = $sess->OperatorName ?? '';
            } else {
                $wetu_error = 'Authentication failed — please check your credentials.';
            }
        } catch (SoapFault $e) {
            $wetu_error = 'Connection error: ' . h($e->getMessage());
        }
    }
}

/* ═══════════════════════════════════════════════════════════════
   LOAD SAMPLE ITINERARIES via JSON REST (avoids SOAP encoding issues)
═══════════════════════════════════════════════════════════════ */
if ($token && !$created) {
    $wetu_u = $_SESSION['wetu_user'] ?? '';
    $wetu_p = $_SESSION['wetu_pass'] ?? '';
    $list_url = 'https://wetu.com/API/Itinerary/V8/List?'
        . http_build_query([
            'username' => $wetu_u,
            'password' => $wetu_p,
            'type'     => 'Sample',
            'results'  => 200,
            'sort'     => 'ItineraryNameAsc',
        ]);
    $ctx = stream_context_create(['http' => [
        'timeout'        => 15,
        'ignore_errors'  => true,
    ]]);
    $raw_json = @file_get_contents($list_url, false, $ctx);
    if ($raw_json === false) {
        $wetu_error = 'Could not connect to Wetu API. Check server network access.';
    } else {
        $decoded = json_decode($raw_json, true);
        if (is_array($decoded)) {
            $samples = $decoded;   // array of assoc arrays (snake_case keys)
        } else {
            $wetu_error = 'Unexpected response from Wetu: ' . h(substr($raw_json, 0, 200));
        }
    }
}

/* ═══════════════════════════════════════════════════════════════
   ACTION: CREATE PERSONAL ITINERARY
═══════════════════════════════════════════════════════════════ */
if ($action === 'create_personal' && $token) {
    $sample_id   = trim($_POST['sample_id']   ?? '');
    $client_name = trim($_POST['client_name'] ?? '');
    $ref_number  = trim($_POST['ref_number']  ?? '');
    $start_date  = trim($_POST['start_date']  ?? '');
    $days        = max(1, intval($_POST['days'] ?? 1));
    $pax         = max(1, intval($_POST['pax']  ?? 1));
    $language    = in_array($_POST['language'] ?? '', ['en','it']) ? $_POST['language'] : 'en';

    if (!$sample_id)   $wetu_error = 'Please select a Sample itinerary.';
    elseif (!$client_name) $wetu_error = 'Client Name is required.';
    elseif (!$ref_number)  $wetu_error = 'Reference Number is required.';
    else {
        try {
            $c = wetu_client();

            /* 1 — Load the full Sample */
            $loaded    = $c->LoadItinerary(['Identifier' => $sample_id, 'SessionToken' => $token]);
            $itinerary = $loaded->LoadItineraryResult;
            if (!$itinerary) throw new Exception('Sample itinerary could not be loaded from Wetu.');

            /* 2 — Rewrite for Personal */
            unset($itinerary->Identifier);
            $itinerary->Type            = 'Personal';
            $itinerary->Name            = $client_name;
            $itinerary->ReferenceNumber = $ref_number;
            $itinerary->Language        = $language;
            $itinerary->Days            = $days;
            if ($start_date) {
                $ts = strtotime($start_date);
                if ($ts) $itinerary->StartDate = date('Y-m-d\TH:i:s', $ts);
            }
            $prev = trim($itinerary->Summary ?? '');
            $itinerary->Summary = 'Pax: ' . $pax . ($prev ? "\n" . $prev : '');

            /* 3 — Save */
            $save_res = $c->SaveItinerary(['Itinerary' => $itinerary, 'SessionToken' => $token]);
            $summary  = $save_res->SaveItineraryResult;
            $new_id   = $summary->Identifier    ?? null;
            $short_id = $summary->IdentifierKey ?? null;
            $cons_key = $summary->ConsultantKey ?? null;

            if (!$new_id) throw new Exception('Itinerary saved but no identifier returned by Wetu.');

            $view_url = 'https://wetu.com/Itinerary/' . ($short_id ?: $new_id);
            $edit_url = $cons_key
                ? 'https://wetu.com/App?consultantKey=' . urlencode($cons_key) . '#/itinerary/' . $new_id
                : 'https://wetu.com/App/#/itinerary/' . $new_id;

            $created = [
                'name'       => $client_name,
                'ref'        => $ref_number,
                'pax'        => $pax,
                'days'       => $days,
                'language'   => strtoupper($language),
                'start_date' => $start_date,
                'view_url'   => $view_url,
                'edit_url'   => $edit_url,
                'short_id'   => $short_id,
            ];
            $wetu_success = 'Personal itinerary created successfully.';

        } catch (SoapFault $e) {
            $wetu_error = 'Wetu API error: ' . h($e->getMessage());
        } catch (Exception $e) {
            $wetu_error = h($e->getMessage());
        }
    }
}

/* ─── Build JS samples array (JSON REST returns snake_case) ─── */
$samples_js_arr = [];
foreach ($samples as $s) {
    $sid   = $s['identifier']   ?? ($s['itinerary_id'] ?? '');
    $sname = $s['name']         ?? ($s['itinerary_name'] ?? 'Unnamed');
    $sdays = intval($s['days']  ?? 0);
    $slang = strtolower(trim($s['language'] ?? ''));
    if ($sid) $samples_js_arr[] = [
        'id'   => $sid,
        'name' => $sname,
        'days' => $sdays,
        'lang' => $slang,
    ];
}
$samples_json = json_encode($samples_js_arr, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

/* ─── Page header ─── */
$pageTitle  = 'Wetu Itinerary Builder';
$extra_css  = '
/* ── WETU PAGE EXTRAS ── */
:root { --wetu: #1E4D7B; --wetu-lt: #E5EFF7; }

.wetu-session-bar {
  background: var(--wetu-lt); border: 1px solid #b8d0e8;
  border-radius: 8px; padding: 10px 16px;
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 22px; font-size: .8rem;
}
.wetu-session-bar .dot { width:8px;height:8px;border-radius:50%;background:#27ae60;flex-shrink:0; }
.wetu-session-bar strong { color: var(--wetu); }
.wetu-session-bar .sep  { color: var(--grey-lt); margin: 0 4px; }

.wetu-alert {
  padding: 11px 16px; border-radius: 7px;
  font-size: .82rem; font-weight: 600;
  margin-bottom: 20px;
  display: flex; align-items: flex-start; gap: 9px;
}
.wetu-alert-error   { background: var(--red-lt);  color: var(--red-dk); border-left: 4px solid var(--red); }
.wetu-alert-success { background: var(--green-lt); color: var(--green);  border-left: 4px solid var(--green); }

.wetu-login-wrap { max-width: 400px; }
.wetu-brand-badge {
  display: inline-flex; align-items: center; gap: 10px;
  background: var(--wetu-lt); border: 1px solid #b8d0e8;
  border-radius: 10px; padding: 10px 18px;
  margin-bottom: 20px;
}
.wetu-brand-badge span { font-family: "Merriweather",serif; font-size:1rem; font-weight:700; color:var(--wetu); }
.login-note { font-size:.72rem; color:var(--grey-mid); margin-top:14px; line-height:1.5; }

.lang-toggle {
  display: flex; border: 1.5px solid var(--grey-lt);
  border-radius: 6px; overflow: hidden; height:38px;
}
.lang-toggle input[type="radio"] { display: none; }
.lang-toggle label {
  flex:1; display:flex; align-items:center; justify-content:center;
  font-size:.82rem; font-weight:700; letter-spacing:.06em;
  text-transform:uppercase; cursor:pointer;
  background:var(--white); color:var(--grey-mid);
  transition:background .15s, color .15s; padding: 0 14px;
}
.lang-toggle label:first-of-type { border-right: 1.5px solid var(--grey-lt); }
.lang-toggle input:checked + label { background: var(--wetu); color: var(--white); }

.field-hint { font-size:.68rem; color:var(--grey-mid); margin-top:3px; }
.sample-count { font-size:.7rem; color:var(--grey-mid); font-weight:600; }

/* Result card */
.result-card { background:var(--white); border-radius:10px; border:2px solid #9dd4aa; box-shadow:0 2px 14px rgba(46,107,62,.1); overflow:hidden; margin-bottom:22px; }
.result-card-hd { background:var(--green-lt); padding:14px 22px; display:flex; align-items:center; gap:10px; }
.result-card-hd h3 { font-family:"Merriweather",serif; font-size:.95rem; font-weight:700; color:var(--green); }
.result-card-bd { padding: 22px; }
.result-meta { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:20px; }
.meta-lbl { font-size:.67rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:var(--grey-mid); margin-bottom:2px; }
.meta-val { font-size:.86rem; font-weight:600; color:var(--black); }
.result-link-row { display:flex; align-items:center; gap:10px; background:var(--off-white); border-radius:7px; padding:10px 14px; margin-bottom:8px; }
.result-link-row .lbl { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--grey-mid); min-width:90px; flex-shrink:0; }
.result-link-row a { font-size:.8rem; color:var(--wetu); text-decoration:none; font-weight:600; word-break:break-all; }
.result-link-row a:hover { text-decoration:underline; }
.copy-btn { margin-left:auto; flex-shrink:0; background:var(--white); border:1.5px solid var(--grey-lt); border-radius:5px; padding:3px 10px; font-size:.7rem; font-weight:600; color:var(--grey-dk); cursor:pointer; transition:all .15s; }
.copy-btn:hover { border-color:var(--wetu); color:var(--wetu); }
.copy-btn.copied { border-color:var(--green); color:var(--green); }

.btn-wetu { background:#1E4D7B !important; color:#fff !important; }
.btn-wetu:hover { background:#163d63 !important; }
';
include __DIR__ . '/includes/header.php';
?>

<div style="max-width:740px;">

<!-- Page title -->
<div class="page-title" style="margin-bottom:22px;">
  🗺️ Wetu Itinerary Builder
  <?php if ($token): ?>
    <span class="sample-count" style="margin-left:auto;font-size:.75rem;">
      <?= count($samples_js_arr) ?> sample<?= count($samples_js_arr) !== 1 ? 's' : '' ?> available
    </span>
  <?php endif; ?>
</div>

<?php if ($wetu_error): ?>
<div class="wetu-alert wetu-alert-error">⚠️ <?= $wetu_error ?></div>
<?php endif; ?>

<?php if ($wetu_success && !$created): ?>
<div class="wetu-alert wetu-alert-success">✅ <?= $wetu_success ?></div>
<?php endif; ?>


<?php /* ════ NOT LOGGED IN ════ */ if (!$token): ?>

<div class="wetu-login-wrap">
  <div class="wetu-brand-badge">
    <span style="font-size:1.3rem;">🌍</span>
    <span>Wetu</span>
  </div>

  <div class="card">
    <div class="card-header">
      <h2>🔐 Sign in to Wetu</h2>
      <span style="font-size:.75rem;color:var(--grey-mid);">Use your personal Wetu credentials</span>
    </div>
    <div class="card-body">
      <form method="POST" action="wetu.php" autocomplete="off">
        <input type="hidden" name="action" value="wetu_login">

        <div class="form-group">
          <label class="form-label" for="wetu_username">Username</label>
          <input class="form-control" type="text" id="wetu_username" name="wetu_username"
                 placeholder="Your Wetu username" autocomplete="username" required>
        </div>

        <div class="form-group">
          <label class="form-label" for="wetu_password">Password</label>
          <input class="form-control" type="password" id="wetu_password" name="wetu_password"
                 placeholder="Your Wetu password" autocomplete="current-password" required>
        </div>

        <div class="form-actions" style="border:none;padding-top:0;margin-top:0;">
          <button type="submit" class="btn btn-primary">
            🔓 Connect to Wetu
          </button>
        </div>
      </form>
      <p class="login-note">
        Your password is transmitted securely over HTTPS and is never stored.<br>
        Each team member uses their own Wetu account.
      </p>
    </div>
  </div>
</div>


<?php /* ════ RESULT ════ */ elseif ($created): ?>

<!-- Session bar -->
<div class="wetu-session-bar">
  <span class="dot"></span>
  Connected as <strong><?= h($wetu_user) ?></strong>
  <?php if ($wetu_op): ?><span class="sep">|</span><?= h($wetu_op) ?><?php endif; ?>
  <form method="POST" action="wetu.php" style="margin-left:auto;">
    <input type="hidden" name="action" value="wetu_logout">
    <button type="submit" class="btn btn-secondary btn-sm">Disconnect</button>
  </form>
</div>

<div class="result-card">
  <div class="result-card-hd">
    <span style="font-size:1.3rem;">✅</span>
    <h3>Personal Itinerary Created</h3>
  </div>
  <div class="result-card-bd">

    <div class="result-meta">
      <div><div class="meta-lbl">Client Name</div><div class="meta-val"><?= h($created['name']) ?></div></div>
      <div><div class="meta-lbl">Reference Number</div><div class="meta-val"><?= h($created['ref']) ?></div></div>
      <div><div class="meta-lbl">Duration</div><div class="meta-val"><?= $created['days'] ?> days</div></div>
      <div><div class="meta-lbl">Pax</div><div class="meta-val"><?= $created['pax'] ?></div></div>
      <?php if ($created['start_date']): ?>
      <div><div class="meta-lbl">Start Date</div><div class="meta-val"><?= h(date('d M Y', strtotime($created['start_date']))) ?></div></div>
      <?php endif; ?>
      <div><div class="meta-lbl">Language</div><div class="meta-val"><?= $created['language'] ?></div></div>
    </div>

    <div class="result-link-row">
      <span class="lbl">🖥️ Client View</span>
      <a href="<?= h($created['view_url']) ?>" target="_blank" id="view-link"><?= h($created['view_url']) ?></a>
      <button class="copy-btn" onclick="copyLink('view-link',this)">Copy</button>
    </div>

    <?php if ($created['edit_url']): ?>
    <div class="result-link-row">
      <span class="lbl">✏️ Edit in Wetu</span>
      <a href="<?= h($created['edit_url']) ?>" target="_blank" id="edit-link">Open Wetu Editor</a>
      <button class="copy-btn" onclick="copyLink('edit-link',this)">Copy</button>
    </div>
    <?php endif; ?>

    <?php if ($created['short_id']): ?>
    <div class="result-link-row">
      <span class="lbl">🔑 Identifier</span>
      <a href="#" id="id-val"><?= h($created['short_id']) ?></a>
      <button class="copy-btn" onclick="copyText('<?= h($created['short_id']) ?>',this)">Copy</button>
    </div>
    <?php endif; ?>

    <div class="form-actions" style="margin-top:16px;">
      <?php if ($created['edit_url']): ?>
      <a href="<?= h($created['edit_url']) ?>" target="_blank" class="btn btn-wetu">✏️ Continue in Wetu</a>
      <?php endif; ?>
      <a href="wetu.php" class="btn btn-secondary">＋ Create Another</a>
    </div>

  </div>
</div>


<?php /* ════ MAIN FORM ════ */ else: ?>

<!-- Session bar -->
<div class="wetu-session-bar">
  <span class="dot"></span>
  Connected as <strong><?= h($wetu_user) ?></strong>
  <?php if ($wetu_op): ?><span class="sep">|</span><?= h($wetu_op) ?><?php endif; ?>
  <form method="POST" action="wetu.php" style="margin-left:auto;">
    <input type="hidden" name="action" value="wetu_logout">
    <button type="submit" class="btn btn-secondary btn-sm">Disconnect</button>
  </form>
</div>

<div class="card">
  <div class="card-header">
    <h2>📋 Create Personal Itinerary</h2>
    <span style="font-size:.75rem;color:var(--grey-mid);">Copy a Sample as Personal and customise it for the client</span>
  </div>
  <div class="card-body">
    <form method="POST" action="wetu.php" id="create-form">
      <input type="hidden" name="action" value="create_personal">

      <!-- Sample filter + dropdown -->
      <div class="form-group">
        <label class="form-label">Base Sample Programme <span style="color:var(--red)">*</span></label>

        <?php if (empty($samples_js_arr)): ?>
          <select class="form-control" name="sample_id" disabled>
            <option>No Sample itineraries found</option>
          </select>
          <div class="field-hint">No samples returned from Wetu — check your account permissions.</div>
        <?php else: ?>

          <!-- Filter row -->
          <div style="display:flex;gap:10px;margin-bottom:8px;">
            <select id="filter_lang" class="form-control" style="max-width:110px;" onchange="filterSamples()">
              <option value="">All languages</option>
              <option value="en">EN</option>
              <option value="it">IT</option>
            </select>
            <input type="text" id="search_sample" class="form-control"
                   placeholder="Search by name…" oninput="filterSamples()"
                   autocomplete="off">
          </div>

          <!-- Main dropdown (populated by JS) -->
          <select class="form-control" id="sample_id" name="sample_id" required
                  onchange="onSampleChange(this)" size="1">
            <option value="">— Select a Sample —</option>
          </select>
          <div class="field-hint" id="sample_count_hint"></div>

        <?php endif; ?>
      </div>

      <!-- Client Name + Reference -->
      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="client_name">Client Name <span style="color:var(--red)">*</span></label>
          <input class="form-control" type="text" id="client_name" name="client_name"
                 placeholder="e.g. Smith Family"
                 value="<?= h($_POST['client_name'] ?? $prefill_name) ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="ref_number">Reference Number <span style="color:var(--red)">*</span></label>
          <input class="form-control" type="text" id="ref_number" name="ref_number"
                 placeholder="e.g. SmithFamily-2026-01"
                 value="<?= h($_POST['ref_number'] ?? $prefill_ref) ?>" required>
          <div class="field-hint">This is the Excel filename when downloaded from Wetu</div>
        </div>
      </div>

      <!-- Start Date + Days + Pax -->
      <div class="form-row" style="grid-template-columns:1fr 1fr 1fr;">
        <div class="form-group">
          <label class="form-label" for="start_date">Start Date</label>
          <input class="form-control" type="date" id="start_date" name="start_date"
                 value="<?= h($_POST['start_date'] ?? $prefill_date) ?>">
        </div>
        <div class="form-group">
          <label class="form-label" for="days">Duration (days) <span style="color:var(--red)">*</span></label>
          <input class="form-control" type="number" id="days" name="days"
                 min="1" max="60"
                 value="<?= intval($_POST['days'] ?? $prefill_days) ?: '' ?>"
                 placeholder="Auto from Sample" required>
          <div class="field-hint">Auto-filled when Sample is selected</div>
        </div>
        <div class="form-group">
          <label class="form-label" for="pax">Pax <span style="color:var(--red)">*</span></label>
          <input class="form-control" type="number" id="pax" name="pax"
                 min="1" max="99"
                 value="<?= intval($_POST['pax'] ?? $prefill_pax) ?>" required>
        </div>
      </div>

      <!-- Language -->
      <div class="form-group" style="max-width:200px;">
        <label class="form-label">Output Language <span style="color:var(--red)">*</span></label>
        <div class="lang-toggle">
          <input type="radio" id="lang_en" name="language" value="en"
                 <?= (($_POST['language'] ?? 'en') === 'en') ? 'checked' : '' ?>>
          <label for="lang_en">EN</label>
          <input type="radio" id="lang_it" name="language" value="it"
                 <?= (($_POST['language'] ?? '') === 'it') ? 'checked' : '' ?>>
          <label for="lang_it">IT</label>
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-wetu" id="submit-btn"
                <?= empty($samples_js_arr) ? 'disabled' : '' ?>>
          🗺️ Create Personal Itinerary
        </button>
        <a href="wetu.php" class="btn btn-secondary">Reset</a>
      </div>

    </form>
  </div>
</div>

<?php endif; ?>

</div><!-- /max-width -->

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
const allSamples = <?= $samples_json ?>;

/* ── Populate dropdown on load ── */
document.addEventListener('DOMContentLoaded', function() {
    filterSamples();
});

function filterSamples() {
    const lang   = (document.getElementById('filter_lang')   ?.value || '').toLowerCase();
    const search = (document.getElementById('search_sample') ?.value || '').toLowerCase().trim();
    const sel    = document.getElementById('sample_id');
    if (!sel) return;

    const prev = sel.value;
    sel.innerHTML = '<option value="">— Select a Sample —</option>';

    let count = 0;
    allSamples.forEach(s => {
        if (lang   && s.lang !== lang)                  return;
        if (search && !s.name.toLowerCase().includes(search)) return;
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.dataset.days = s.days;
        opt.textContent  = s.name + (s.days ? ` (${s.days}d)` : '');
        if (s.id === prev) opt.selected = true;
        sel.appendChild(opt);
        count++;
    });

    const hint = document.getElementById('sample_count_hint');
    if (hint) hint.textContent = count === allSamples.length
        ? `${count} samples available`
        : `${count} of ${allSamples.length} samples shown`;
}

function onSampleChange(sel) {
    const days = parseInt(sel.options[sel.selectedIndex]?.dataset.days || '0', 10);
    const d = document.getElementById('days');
    if (d && days > 0) d.value = days;
}

function copyLink(elId, btn) {
    const el = document.getElementById(elId);
    copyText(el.href || el.textContent.trim(), btn);
}
function copyText(txt, btn) {
    navigator.clipboard.writeText(txt).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✓ Copied';
        btn.classList.add('copied');
        setTimeout(() => { btn.textContent = orig; btn.classList.remove('copied'); }, 1800);
    });
}

const form = document.getElementById('create-form');
if (form) {
    form.addEventListener('submit', function () {
        const btn = document.getElementById('submit-btn');
        if (btn) { btn.disabled = true; btn.textContent = '⏳ Creating…'; }
    });
}
</script>
