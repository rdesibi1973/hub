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
    unset($_SESSION['wetu_token'], $_SESSION['wetu_user'], $_SESSION['wetu_operator'], $_SESSION['wetu_pass'], $_SESSION['wetu_samples']);
    header('Location: wetu.php');
    exit;
}

/* ═══════════════════════════════════════════════════════════════
   ACTION: WETU LOGIN
═══════════════════════════════════════════════════════════════ */
/* ═══════════════════════════════════════════════════════════════
   HELPER: fetch ALL Sample itineraries via JSON REST (paginated)
═══════════════════════════════════════════════════════════════ */
function wetu_json_get(string $url): ?array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $err || $code !== 200) return null;
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return null;
    // Direct array
    if (empty($decoded) || isset($decoded[0])) return $decoded;
    // Wrapper object — try common keys
    foreach (['Itineraries','itineraries','Items','items','Results','results'] as $k) {
        if (isset($decoded[$k]) && is_array($decoded[$k])) return $decoded[$k];
    }
    return $decoded;
}

function wetu_fetch_samples(string $u, string $p): array {
    $all      = [];
    $pageSize = 200;
    $start    = 0;
    $maxPages = 10;   // safety cap: max 2000 samples

    for ($page = 0; $page < $maxPages; $page++) {
        $url = 'https://wetu.com/API/Itinerary/V8/List?' . http_build_query([
            'username' => $u,
            'password' => $p,
            'type'     => 'Sample',
            'results'  => $pageSize,
            'start'    => $start,
            'sort'     => 'ItineraryNameAsc',
        ]);
        $batch = wetu_json_get($url);
        if ($batch === null || empty($batch)) break;
        $all   = array_merge($all, $batch);
        $start += $pageSize;
        if (count($batch) < $pageSize) break;   // last page — done
    }

    return $all;
}

/* ═══════════════════════════════════════════════════════════════
   ACTION: WETU LOGIN  — fetch samples immediately, cache in session
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
                $fetched = wetu_fetch_samples($u, $p);
                $_SESSION['wetu_token']    = $sess->SessionToken;
                $_SESSION['wetu_user']     = $u;
                $_SESSION['wetu_operator'] = $sess->OperatorName ?? '';
                $_SESSION['wetu_samples']  = $fetched;
                $token    = $sess->SessionToken;
                $wetu_user = $u;
                $wetu_op   = $sess->OperatorName ?? '';
                $samples   = $fetched;
                if (empty($fetched)) {
                    $wetu_error = 'Logged in, but no Sample itineraries were returned by Wetu.';
                }
            } else {
                $wetu_error = 'Authentication failed — please check your credentials.';
            }
        } catch (SoapFault $e) {
            $wetu_error = 'Connection error: ' . h($e->getMessage());
        }
    }
}

/* ═══════════════════════════════════════════════════════════════
   LOAD SAMPLES FROM SESSION CACHE
═══════════════════════════════════════════════════════════════ */
$wetu_debug = '';
if ($token && !$created && $action !== 'wetu_login') {
    $samples = $_SESSION['wetu_samples'] ?? [];
    if (empty($samples) && $token) {
        $wetu_error = 'No samples in session — please disconnect and sign in again.';
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
    $language    = trim($_POST['language'] ?? 'en');
    if (!preg_match('/^[a-z]{2}$/', $language)) $language = 'en';

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

/* ─── Build JS samples array + language list ─── */
$samples_js_arr = [];
$lang_set = [];

// Debug: capture first sample raw keys to show in UI
$first_sample_debug = !empty($samples) ? json_encode(array_keys($samples[0]), JSON_PRETTY_PRINT) : '(no samples)';
$first_sample_raw   = !empty($samples) ? json_encode($samples[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '';

foreach ($samples as $s) {
    $sid   = $s['identifier']    ?? ($s['itinerary_id']   ?? ($s['Identifier'] ?? ''));
    $sname = $s['name']          ?? ($s['itinerary_name'] ?? ($s['Name'] ?? ($s['ItineraryName'] ?? 'Unnamed')));
    $sdays = intval($s['days']   ?? ($s['Days'] ?? 0));
    // Try every possible field name Wetu might use
    $slang = trim(
        $s['language']      ??
        $s['Language']      ??
        $s['lang']          ??
        $s['language_code'] ??
        $s['LanguageCode']  ??
        ''
    );
    if ($sid) {
        $samples_js_arr[] = ['id' => $sid, 'name' => $sname, 'days' => $sdays, 'lang' => $slang];
        if ($slang) $lang_set[$slang] = true;
    }
}
ksort($lang_set);
$languages    = array_keys($lang_set);
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

/* Ensure anchor .btn elements look like buttons, not links */
a.btn { text-decoration: none !important; }
a.btn-secondary { color: var(--grey-dk) !important; }
a.btn-wetu      { color: #fff !important; }

/* Disconnect button */
.btn-disconnect {
  font-family: inherit; font-size: .75rem; font-weight: 700;
  letter-spacing: .04em; text-transform: uppercase;
  padding: 5px 14px; border-radius: 5px; cursor: pointer;
  background: #fff; color: #C0211B;
  border: 1.5px solid #C0211B;
  transition: background .15s;
}
.btn-disconnect:hover { background: #FAE8E7; }
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
<div class="wetu-alert wetu-alert-error">⚠️ <?= $wetu_error ?>
<?php if ($wetu_debug): ?><br><small style="font-family:monospace;font-weight:400;"><?= $wetu_debug ?></small><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($token && !empty($first_sample_raw)): ?>
<details style="margin-bottom:16px;font-size:.75rem;">
  <summary style="cursor:pointer;color:#888;font-weight:600;">🔍 Debug: first sample fields (<?= count($samples) ?> total, <?= count($languages) ?> languages found)</summary>
  <pre style="background:#f5f5f5;padding:10px;border-radius:6px;margin-top:6px;white-space:pre-wrap;word-break:break-all;font-size:.7rem;max-height:200px;overflow:auto;"><?= htmlspecialchars($first_sample_raw) ?></pre>
</details>
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
          <button type="submit" style="font-family:inherit;font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:10px 22px;border:none;border-radius:6px;cursor:pointer;background:#C0211B;color:#fff;transition:background .15s;">
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
    <button type="submit" class="btn-disconnect">⏏ Disconnect</button>
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
      <a href="<?= h($created['edit_url']) ?>" target="_blank" style="font-family:inherit;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:9px 18px;border-radius:6px;background:#C0211B;color:#fff;text-decoration:none;display:inline-block;">✏️ Continue in Wetu</a>
      <?php endif; ?>
      <a href="wetu.php" style="font-family:inherit;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:9px 18px;border-radius:6px;background:#E8E8E8;color:#444;text-decoration:none;display:inline-block;">＋ Create Another</a>
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
    <button type="submit" class="btn-disconnect">⏏ Disconnect</button>
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
        <label class="form-label">Base Sample Programme <span style="color:#C0211B">*</span></label>

        <!-- Filter row — always visible -->
        <div style="display:flex;gap:10px;margin-bottom:8px;">
          <select id="filter_lang" class="form-control" style="max-width:150px;flex-shrink:0;" onchange="filterSamples()">
            <option value="">All languages</option>
            <option value="English">English</option>
            <option value="Italian">Italian</option>
            <option value="German">German</option>
            <option value="Spanish">Spanish</option>
            <option value="French">French</option>
          </select>
          <input type="text" id="search_sample" class="form-control"
                 placeholder="Search by name…" oninput="filterSamples()"
                 autocomplete="off">
        </div>

        <!-- Main dropdown (populated by JS) -->
        <select class="form-control" id="sample_id" name="sample_id"
                <?= empty($samples_js_arr) ? 'disabled' : 'required' ?>
                onchange="onSampleChange(this)">
          <option value=""><?= empty($samples_js_arr) ? '— No samples loaded —' : '— Select a Sample —' ?></option>
        </select>
        <div class="field-hint" id="sample_count_hint">
          <?php if (empty($samples_js_arr) && $wetu_debug): ?>
            <details style="margin-top:6px;">
              <summary style="cursor:pointer;color:#C0211B;font-weight:700;">▶ Show Wetu API response (debug)</summary>
              <pre style="font-size:.68rem;background:#f5f5f5;padding:8px;border-radius:4px;margin-top:4px;white-space:pre-wrap;word-break:break-all;"><?= $wetu_debug ?></pre>
            </details>
          <?php elseif (empty($samples_js_arr)): ?>
            No samples returned — try disconnecting and reconnecting.
          <?php endif; ?>
        </div>
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

      <!-- Language: auto-set from selected Sample -->
      <input type="hidden" name="language" id="hidden_language" value="en">

      <div class="form-actions">
        <button type="submit" id="submit-btn"
                <?= empty($samples_js_arr) ? 'disabled' : '' ?>
                style="font-family:inherit;font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:10px 22px;border:none;border-radius:6px;cursor:pointer;background:#C0211B;color:#fff;transition:background .15s;">
          🗺️ Create Personal Itinerary
        </button>
        <a href="wetu.php" style="font-family:inherit;font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:10px 20px;border-radius:6px;background:#E8E8E8;color:#444;text-decoration:none;display:inline-block;">Reset</a>
      </div>

    </form>
  </div>
</div>

<?php endif; ?>

</div><!-- /max-width -->

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
const allSamples = <?= $samples_json ?>;

/* Language display names for the filter LOV */
const langNames = {
    en:'English', it:'Italian', de:'German', es:'Spanish', fr:'French',
    english:'English', italian:'Italian', german:'German', spanish:'Spanish', french:'French'
};

/* ── Build language LOV dynamically from actual sample data ── */
function buildLangLOV() {
    const sel = document.getElementById('filter_lang');
    if (!sel || allSamples.length === 0) return;

    // Collect unique raw lang values
    const seen = {};
    allSamples.forEach(s => { if (s.lang) seen[s.lang] = true; });

    // Sort by display name
    const langs = Object.keys(seen).sort((a, b) => {
        return (langNames[a.toLowerCase()] || a).localeCompare(langNames[b.toLowerCase()] || b);
    });

    // Rebuild options (keep current selection)
    const prev = sel.value;
    sel.innerHTML = '<option value="">All languages</option>';
    langs.forEach(raw => {
        const opt = document.createElement('option');
        opt.value = raw;                                      // exact value from API
        opt.textContent = langNames[raw.toLowerCase()] || raw;
        if (raw === prev) opt.selected = true;
        sel.appendChild(opt);
    });
}

/* ── Populate dropdown on load ── */
document.addEventListener('DOMContentLoaded', function() {
    buildLangLOV();
    filterSamples();
});

function filterSamples() {
    const langRaw = document.getElementById('filter_lang')?.value || '';
    const search  = (document.getElementById('search_sample')?.value || '').toLowerCase().trim();
    const sel     = document.getElementById('sample_id');
    if (!sel) return;

    const prev = sel.value;
    sel.innerHTML = '<option value="">— Select a Sample —</option>';

    let count = 0;
    allSamples.forEach(s => {
        // Language filter: exact match on raw value from API
        if (langRaw && s.lang !== langRaw) return;
        // Name filter: case-insensitive contains
        if (search && !s.name.toLowerCase().includes(search)) return;

        const opt = document.createElement('option');
        opt.value = s.id;
        opt.dataset.days = s.days;
        opt.dataset.lang = s.lang;
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
    const opt  = sel.options[sel.selectedIndex];
    const days = parseInt(opt?.dataset.days || '0', 10);
    const d    = document.getElementById('days');
    if (d && days > 0) d.value = days;

    // Auto-set hidden language from sample
    const raw  = (opt?.dataset.lang || 'en').toLowerCase();
    const langMap = {english:'en', italian:'it', german:'de', spanish:'es', french:'fr'};
    const lang = langMap[raw] || (raw.length === 2 ? raw : raw.substring(0,2)) || 'en';
    const hl   = document.getElementById('hidden_language');
    if (hl) hl.value = lang;
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
