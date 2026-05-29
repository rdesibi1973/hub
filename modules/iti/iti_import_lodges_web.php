<?php
/**
 * iti_import_lodges_web.php
 * Import lodges from partner websites using AI extraction.
 * Fetches HTML, sends to Anthropic API, extracts structured lodge data,
 * shows preview with destination mapping, then imports to iti_lodges.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db       = db();
$_cu      = current_user();
$can_edit = in_array($_cu['role_name'], ['admin', 'manager']);

// ── Partner sources ──────────────────────────────────────────────────────────
$SOURCES = [
    'wellworth'  => ['label' => 'Wellworth Lodges',            'url' => 'https://wellworthcollection.co.tz/'],
    'karibu'     => ['label' => 'Karibu Camps',                'url' => 'https://karibucamps.com/'],
    'melia'      => ['label' => 'Meliá Serengeti Lodge',       'url' => 'https://www.melia.com/en/hotels/tanzania/serengeti-national-park/melia-serengeti-lodge'],
    'elewana'    => ['label' => 'Elewana Collection',          'url' => 'https://www.elewanacollection.com/'],
    'sopa'       => ['label' => 'Sopa Lodges',                 'url' => 'https://www.sopalodges.com/'],
    'fourseasons'=> ['label' => 'Four Seasons Serengeti',      'url' => 'https://www.fourseasons.com/serengeti/'],
    'sheraton'   => ['label' => 'Four Points by Sheraton Arusha', 'url' => 'https://www.marriott.com/en-us/hotels/jrofp-four-points-arusha-the-arusha-hotel/overview/'],
    'planet'     => ['label' => 'Planet Lodge',                'url' => 'https://planet-lodges.com/'],
    'asilia'     => ['label' => 'Asilia Africa Tanzania',      'url' => 'https://www.asiliaafrica.com/destinations/tanzania/'],
];

// ── Load destinations for mapping ────────────────────────────────────────────
$destinations_all = iti_get_destinations(false);
$dest_map_id      = [];  // id => name_en
$dest_map_name    = [];  // lowercase name_en => id  (for auto-matching)
foreach ($destinations_all as $d) {
    $dest_map_id[$d['id']]                  = $d['name_en'];
    $dest_map_name[strtolower($d['name_en'])] = $d['id'];
    // also index by code
    $dest_map_name[strtolower($d['code'])]  = $d['id'];
}

// ── Existing lodge names (to detect duplicates) ───────────────────────────────
$existing_names = [];
foreach ($db->query("SELECT LOWER(name) AS n FROM iti_lodges")->fetchAll() as $r)
    $existing_names[] = $r['n'];

// ── Anthropic config ─────────────────────────────────────────────────────────
// Key stored in config.php as ANTHROPIC_API_KEY (or we fall back to env)
$api_key = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : (getenv('ANTHROPIC_API_KEY') ?: '');

// ── Helpers ──────────────────────────────────────────────────────────────────
function fetch_url_text(string $url): string|false {
    $ctx = stream_context_create(['http' => [
        'timeout'     => 20,
        'user_agent'  => 'Mozilla/5.0 (compatible; SavannahHub/1.0)',
        'header'      => "Accept: text/html,application/xhtml+xml\r\n",
    ]]);
    $html = @file_get_contents($url, false, $ctx);
    if (!$html) return false;
    // Strip scripts, styles, head
    $html = preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', '', $html);
    $html = preg_replace('#<head[^>]*>.*?</head>#is', '', $html);
    // Strip tags, collapse whitespace
    $text = strip_tags($html);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    // Limit to 12000 chars to stay within token budget
    return mb_substr(trim($text), 0, 12000);
}

function call_anthropic(string $api_key, string $prompt): array|false {
    if (!$api_key) return false;
    $body = json_encode([
        'model'      => 'claude-sonnet-4-20250514',
        'max_tokens' => 2000,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'timeout' => 40,
        'header'  => implode("\r\n", [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
            'Content-Length: ' . strlen($body),
        ]),
        'content' => $body,
    ]]);
    $resp = @file_get_contents('https://api.anthropic.com/v1/messages', false, $ctx);
    if (!$resp) return false;
    $data = json_decode($resp, true);
    return $data['content'][0]['text'] ?? false;
}

function auto_match_destination(string $lodge_dest_hint, array $dest_map_name): int {
    $hint = strtolower($lodge_dest_hint);
    // Exact match
    if (isset($dest_map_name[$hint])) return $dest_map_name[$hint];
    // Partial match: dest name contains hint or hint contains dest name
    foreach ($dest_map_name as $name => $id) {
        if (str_contains($hint, $name) || str_contains($name, $hint)) return $id;
    }
    // Common aliases
    $aliases = [
        'serengeti' => 'SNP', 'ngorongoro' => 'NCA', 'nca' => 'NCA',
        'arusha'    => 'ARU', 'tarangire'  => 'TRP', 'zanzibar' => 'ZNZB',
        'ruaha'     => 'RNP', 'manyara'    => 'LMN', 'katavi'   => 'KNP',
        'mahale'    => 'MMP', 'moshi'      => 'MSH', 'kilimanjaro' => 'KILI',
        'nyerere'   => 'SGR', 'selous'     => 'SGR', 'karatu'   => 'KAR',
        'pemba'     => 'PMB', 'mafia'      => 'MFA', 'pangani'  => 'PGN',
    ];
    foreach ($aliases as $alias => $code) {
        if (str_contains($hint, $alias) && isset($dest_map_name[strtolower($code)]))
            return $dest_map_name[strtolower($code)];
    }
    return 0;
}

// ── State ────────────────────────────────────────────────────────────────────
$action      = $_POST['action'] ?? $_GET['action'] ?? '';
$source_key  = $_POST['source'] ?? $_GET['source'] ?? '';
$log         = [];
$fetched     = null;   // array of lodge rows after AI extraction
$fetch_error = '';

// ── STEP 1: Fetch & AI-extract ───────────────────────────────────────────────
if ($action === 'fetch' && isset($SOURCES[$source_key])) {
    $source = $SOURCES[$source_key];
    $text   = fetch_url_text($source['url']);
    if (!$text) {
        $fetch_error = "Could not fetch " . $source['url'] . ". The site may block automated requests.";
    } else {
        $dest_names_list = implode(', ', array_values($dest_map_id));
        $prompt = <<<PROMPT
You are a data extraction assistant. Below is the text content of a safari lodge website.
Extract ALL lodge/camp/hotel properties listed on this site.

For each property return a JSON object with these fields:
- "name": full property name (string)
- "destination": best matching Tanzania destination from this list: {$dest_names_list}
  If none match exactly, write the closest location name from the text.
- "category": one of: budget, mid, luxury, ultra_luxury
- "lodge_type": one of: lodge, tented_camp, hotel, mobile_camp, house
- "description_en": 2–4 sentence description in English (from website text, not invented)
- "website": the URL of this specific property if mentioned, else empty string

Return ONLY a JSON array of objects, no markdown, no preamble, no explanation.
If no properties are found, return [].

WEBSITE TEXT:
{$text}
PROMPT;

        $ai_raw = call_anthropic($api_key, $prompt);
        if (!$ai_raw) {
            $fetch_error = "AI extraction failed. Check ANTHROPIC_API_KEY in config.php.";
        } else {
            // Strip possible markdown fences
            $ai_raw = preg_replace('/^```json\s*/i', '', trim($ai_raw));
            $ai_raw = preg_replace('/```\s*$/i', '', $ai_raw);
            $rows   = json_decode(trim($ai_raw), true);
            if (!is_array($rows)) {
                $fetch_error = "AI returned invalid JSON. Raw output: " . htmlspecialchars(mb_substr($ai_raw, 0, 400));
            } else {
                // Enrich with auto-matched destination ids and duplicate check
                foreach ($rows as &$r) {
                    $r['dest_id_auto'] = auto_match_destination($r['destination'] ?? '', $dest_map_name);
                    $r['is_duplicate'] = in_array(strtolower(trim($r['name'] ?? '')), $existing_names);
                }
                unset($r);
                $fetched = $rows;
            }
        }
    }
}

// ── STEP 2: Import confirmed rows ────────────────────────────────────────────
$import_log  = [];
$import_done = false;
if ($action === 'import' && $can_edit) {
    $rows_json = $_POST['rows_json'] ?? '[]';
    $rows      = json_decode($rows_json, true);
    if (is_array($rows)) {
        $ok = $skip = $err = 0;
        $stmt = $db->prepare(
            'INSERT INTO iti_lodges
             (destination_id,name,category,lodge_type,website,
              description_en,is_active)
             VALUES (?,?,?,?,?,?,1)'
        );
        foreach ($rows as $r) {
            // Per-row overrides from POST (destination_id_{n}, skip_{n})
            $idx     = (int)($r['_idx'] ?? 0);
            $dest_id = (int)($_POST["dest_id_{$idx}"] ?? $r['dest_id_auto'] ?? 0);
            $do_skip = isset($_POST["skip_{$idx}"]);
            if ($do_skip) { $skip++; continue; }
            if (!$dest_id) { $import_log[] = "⚠️ Skipped — no destination: " . ($r['name'] ?? '?'); $skip++; continue; }
            $name = trim($r['name'] ?? '');
            if (!$name)   { $skip++; continue; }
            // Check duplicate again at import time
            if (in_array(strtolower($name), $existing_names)) {
                $import_log[] = "⏭ Already exists — skipped: {$name}";
                $skip++; continue;
            }
            try {
                $stmt->execute([
                    $dest_id,
                    $name,
                    $r['category']    ?? 'mid',
                    $r['lodge_type']  ?? 'lodge',
                    $r['website']     ?? '',
                    $r['description_en'] ?? '',
                ]);
                $existing_names[] = strtolower($name);
                $import_log[]     = "✅ Imported: {$name}";
                $ok++;
            } catch (Exception $e) {
                $import_log[] = "❌ Error ({$name}): " . $e->getMessage();
                $err++;
            }
        }
        $import_log[] = "─── Done: {$ok} imported, {$skip} skipped, {$err} errors ───";
        $import_done  = true;
    }
}

// ── Page ─────────────────────────────────────────────────────────────────────
$page_title = 'Import Lodges from Web — Itinerary Builder';
$extra_css  = iti_extra_css() . '
.src-card{background:#fff;border:1.5px solid var(--grey-lt);border-radius:10px;padding:16px 20px;margin-bottom:10px;display:flex;align-items:center;gap:16px;}
.src-card .src-label{flex:1;font-weight:600;font-size:.88rem;}
.src-card .src-url{font-size:.73rem;color:var(--grey-mid);word-break:break-all;}
.preview-table{width:100%;border-collapse:collapse;background:#fff;margin-top:12px;font-size:.78rem;}
.preview-table th{background:var(--green);color:#fff;padding:7px 10px;text-align:left;font-size:.72rem;white-space:nowrap;}
.preview-table td{padding:8px 10px;border-bottom:1px solid var(--grey-lt);vertical-align:top;}
.preview-table tr.dup{background:#fff8e1;}
.preview-table tr.dup td:first-child::after{content:" ⚠ exists";font-size:.65rem;color:#b45309;margin-left:6px;}
.log-box{background:#1a1a1a;color:#a8e6a3;font-family:monospace;font-size:.75rem;padding:12px 16px;border-radius:8px;max-height:200px;overflow-y:auto;margin:12px 0;white-space:pre-wrap;}
.badge-dup{display:inline-block;padding:1px 7px;border-radius:4px;background:#FEF3C7;color:#92400E;font-size:.65rem;font-weight:700;margin-left:6px;vertical-align:middle;}
';
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
<?php iti_nav('Import Lodges from Web'); ?>
<?php iti_flash_render(); ?>

<div class="page-header">
  <div>
    <h2>🌐 Import Lodges from Web</h2>
    <div class="sub">Master Data › Lodges › Web Import</div>
  </div>
  <a href="lodges.php" class="btn btn-outline btn-sm">← Back to Lodges</a>
</div>

<?php if ($import_done): ?>
<!-- ── IMPORT RESULT ── -->
<div class="form-card">
  <div class="form-section-title">Import Result</div>
  <div class="log-box"><?= implode("\n", array_map('htmlspecialchars', $import_log)) ?></div>
  <div style="margin-top:16px;display:flex;gap:10px;">
    <a href="lodges.php" class="btn btn-red">→ View Lodges</a>
    <a href="iti_import_lodges_web.php" class="btn btn-outline">Import more</a>
  </div>
</div>

<?php elseif ($fetched !== null): ?>
<!-- ── PREVIEW ── -->
<div class="form-card">
  <div class="form-section-title">
    Preview — <?= h($SOURCES[$source_key]['label']) ?>
    <span style="font-weight:400;font-size:.8rem;color:var(--grey-mid);margin-left:8px;"><?= count($fetched) ?> lodge<?= count($fetched)!==1?'s':'' ?> found</span>
  </div>

  <?php if (!$fetched): ?>
    <p style="color:var(--grey-mid);padding:20px 0;">No lodges could be extracted from this site.</p>
  <?php else: ?>

  <form method="POST" action="iti_import_lodges_web.php">
    <input type="hidden" name="action" value="import">
    <input type="hidden" name="source" value="<?= h($source_key) ?>">

    <p style="font-size:.8rem;color:var(--grey-mid);margin-bottom:12px;">
      Review each lodge below. Adjust the destination mapping if needed. Uncheck rows to skip them.
      Rows marked <span class="badge-dup">EXISTS</span> are already in the DB and will be skipped automatically.
    </p>

    <table class="preview-table">
      <thead>
        <tr>
          <th style="width:30px;"></th>
          <th>Lodge Name</th>
          <th>AI Destination hint</th>
          <th style="min-width:200px;">Map to Destination <span style="color:#fbbf24">*</span></th>
          <th>Category</th>
          <th>Type</th>
          <th>Description (EN)</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($fetched as $i => $r):
        $is_dup = $r['is_duplicate'] ?? false;
      ?>
        <tr class="<?= $is_dup ? 'dup' : '' ?>">
          <td style="text-align:center;">
            <input type="checkbox" name="skip_<?= $i ?>" value="1"
                   id="skip_<?= $i ?>" <?= $is_dup ? 'checked' : '' ?>>
          </td>
          <td>
            <strong><?= h($r['name']) ?></strong>
            <?php if ($is_dup): ?><span class="badge-dup">EXISTS</span><?php endif; ?>
            <!-- Pass all row data as JSON for the import step -->
            <input type="hidden" name="rows_json_row_<?= $i ?>" value="<?= h(json_encode(array_merge($r, ['_idx'=>$i]))) ?>">
          </td>
          <td style="color:var(--grey-mid);font-size:.73rem;"><?= h($r['destination'] ?? '') ?></td>
          <td>
            <select name="dest_id_<?= $i ?>" style="font-size:.78rem;">
              <option value="">— Select —</option>
              <?php foreach ($dest_map_id as $did => $dname):
                $sel = ($did == ($r['dest_id_auto'] ?? 0)) ? ' selected' : '';
              ?>
              <option value="<?= $did ?>"<?= $sel ?>><?= h($dname) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <select name="cat_<?= $i ?>" style="font-size:.78rem;">
              <?php foreach (['budget'=>'Budget','mid'=>'Mid-range','luxury'=>'Luxury','ultra_luxury'=>'Ultra Luxury'] as $cv=>$cl):
                $sel = (($r['category']??'mid')===$cv)?' selected':'';
              ?>
              <option value="<?= $cv ?>"<?= $sel ?>><?= $cl ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <select name="type_<?= $i ?>" style="font-size:.78rem;">
              <?php foreach (['lodge'=>'Lodge','tented_camp'=>'Tented Camp','hotel'=>'Hotel','mobile_camp'=>'Mobile Camp','house'=>'House'] as $tv=>$tl):
                $sel = (($r['lodge_type']??'lodge')===$tv)?' selected':'';
              ?>
              <option value="<?= $tv ?>"<?= $sel ?>><?= $tl ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td style="max-width:280px;font-size:.72rem;color:#555;"><?= h(mb_substr($r['description_en']??'',0,180)) ?><?= strlen($r['description_en']??'')>180?'…':'' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php
    // Pack all rows into a single hidden field for the import action
    $rows_for_import = [];
    foreach ($fetched as $i => $r) $rows_for_import[] = array_merge($r, ['_idx'=>$i]);
    ?>
    <input type="hidden" name="rows_json" value="<?= h(json_encode($rows_for_import)) ?>">

    <div style="margin-top:16px;display:flex;gap:10px;align-items:center;">
      <?php if ($can_edit): ?>
      <button type="submit" class="btn btn-red">⬆ Import checked rows</button>
      <?php endif; ?>
      <a href="iti_import_lodges_web.php" class="btn btn-outline">← Back</a>
      <span style="font-size:.75rem;color:var(--grey-mid);margin-left:auto;">Only unchecked (Skip) rows will be imported. Existing lodges are pre-checked for skip.</span>
    </div>
  </form>
  <?php endif; ?>
</div>

<?php else: ?>
<!-- ── SOURCE SELECTION ── -->

<?php if ($fetch_error): ?>
<div class="alert alert-danger" style="margin-bottom:16px;"><?= $fetch_error ?></div>
<?php endif; ?>

<?php if (!$api_key): ?>
<div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:.83rem;">
  ⚠️ <strong>ANTHROPIC_API_KEY</strong> not found in <code>includes/config.php</code>.
  Add: <code>define('ANTHROPIC_API_KEY', 'sk-ant-…');</code> to enable AI extraction.
</div>
<?php endif; ?>

<div class="form-card">
  <div class="form-section-title">Select a partner website to import from</div>
  <p style="font-size:.8rem;color:var(--grey-mid);margin-bottom:16px;">
    The script will fetch the website, use AI to extract lodge names and details,
    then show you a preview for review before importing.
  </p>

  <?php foreach ($SOURCES as $key => $src): ?>
  <div class="src-card">
    <div>
      <div class="src-label"><?= h($src['label']) ?></div>
      <div class="src-url"><?= h($src['url']) ?></div>
    </div>
    <form method="POST" action="iti_import_lodges_web.php" style="margin:0;">
      <input type="hidden" name="action" value="fetch">
      <input type="hidden" name="source" value="<?= h($key) ?>">
      <button class="btn btn-red btn-sm" <?= !$api_key ? 'disabled title="API key required"' : '' ?>>
        Fetch &amp; Extract →
      </button>
    </form>
  </div>
  <?php endforeach; ?>
</div>

<div class="form-card" style="margin-top:16px;">
  <div class="form-section-title">Notes</div>
  <ul style="font-size:.8rem;color:var(--grey-mid);line-height:2;padding-left:20px;margin:0;">
    <li>Some sites (Four Seasons, Marriott) may block automated fetching — use manual entry in those cases.</li>
    <li>AI extraction works best on sites with clear lodge listing pages.</li>
    <li>Descriptions are extracted in English only. Italian/French/Spanish/German can be added later via Edit.</li>
    <li>Duplicate detection is based on exact lodge name match — review "EXISTS" flagged rows carefully.</li>
    <li>Destination mapping is done automatically where possible — always verify before importing.</li>
  </ul>
</div>

<?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
