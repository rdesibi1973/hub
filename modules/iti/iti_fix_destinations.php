<?php
/**
 * iti_fix_destinations.php
 * Fix title case + traduzione FR/ES/DE via Anthropic API
 * Aggiungere a config.php: define('ANTHROPIC_API_KEY', 'sk-ant-...');
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db = db();

// ─── HELPER: Title Case smart (preserva acronimi tipo "NP", "GR", "CA") ─────
function iti_title_case(string $s): string {
    // Parole minuscole in mezzo: preposizioni EN/IT/FR/ES/DE
    $lower_words = ['a','an','the','and','but','or','for','nor','on','at','to','by',
                    'in','of','up','as','is','it','its',
                    'di','del','della','dei','degli','delle','da','dal','dalla',
                    'de','du','des','la','le','les','et','au','aux',
                    'el','los','las','en','con','por','para','del'];
    $words = explode(' ', strtolower($s));
    foreach ($words as $i => &$w) {
        // Acronimi (tutto uppercase nell'originale, max 4 char): tieni uppercase
        if (preg_match('/^[A-Z]{2,4}$/', $s !== $s ? '' : (explode(' ', $s)[$i] ?? ''))) {
            $w = strtoupper($w);
        // Prima parola sempre maiuscola
        } elseif ($i === 0) {
            $w = ucfirst($w);
        // Parola corta minuscola
        } elseif (in_array($w, $lower_words)) {
            $w = $w;
        } else {
            $w = ucfirst($w);
        }
    }
    return implode(' ', $words);
}

// Applica title case rispettando acronimi come NP, GR, CA, DR
function smart_title(string $s): string {
    $acronyms = ['NP','GR','CA','DR','AM','FM','UK','UN','EU','NGO','HQ','VIP','ID'];
    $result = iti_title_case($s);
    // Restore acronimi
    foreach ($acronyms as $ac) {
        $result = preg_replace('/\b' . ucfirst(strtolower($ac)) . '\b/', $ac, $result);
        $result = preg_replace('/\b' . strtolower($ac) . '\b/i', $ac, $result);
    }
    return $result;
}

// ─── HELPER: Chiama Anthropic API ────────────────────────────────────────────
function anthropic_translate(string $text, string $name_en, string $lang_code): string {
    if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) return '';
    if (!$text && !$name_en) return '';

    $lang_names = ['fr'=>'French','es'=>'Spanish','de'=>'German'];
    $lang_name  = $lang_names[$lang_code] ?? $lang_code;

    // Traduci sia il nome che la descrizione in un'unica chiamata
    $prompt = "You are a professional travel copywriter. Translate the following Tanzania destination information into {$lang_name}.\n\nReturn ONLY a JSON object with exactly these two keys:\n- \"name\": the translated destination name\n- \"description\": the translated description\n\nDo not add any other text, markdown, or explanation.\n\nDestination name (English): {$name_en}\n\nDescription (English):\n{$text}";

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-20250514',
        'max_tokens' => 1000,
        'messages'   => [['role'=>'user','content'=>$prompt]],
    ]);

    $opts = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => implode("\r\n", [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ]),
        'content' => $payload,
        'timeout' => 30,
        'ignore_errors' => true,
    ]]);

    $resp = @file_get_contents('https://api.anthropic.com/v1/messages', false, $opts);
    if (!$resp) return '';

    $data = json_decode($resp, true);
    $raw  = $data['content'][0]['text'] ?? '';

    // Strip possible markdown fences
    $raw = preg_replace('/^```json\s*/i', '', trim($raw));
    $raw = preg_replace('/```$/', '', $raw);

    $result = json_decode(trim($raw), true);
    return $result ?? [];  // ['name'=>..., 'description'=>...]
}

// ─── ACTIONS ─────────────────────────────────────────────────────────────────
$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$dry_run = ($_GET['dry'] ?? '0') === '1';

$log = [];

if ($action === 'fix_names' && !$dry_run) {
    // Fix title case for all name_* columns
    $rows = $db->query("SELECT id, name_en, name_it, name_fr, name_es, name_de FROM iti_destinations")->fetchAll();
    $n = 0;
    foreach ($rows as $r) {
        $updates = [];
        $vals    = [];
        foreach (['en','it','fr','es','de'] as $lang) {
            $orig = $r["name_{$lang}"];
            if (!$orig) continue;
            $fixed = smart_title($orig);
            if ($fixed !== $orig) {
                $updates[] = "name_{$lang}=?";
                $vals[]    = $fixed;
            }
        }
        if ($updates) {
            $vals[] = $r['id'];
            $db->prepare('UPDATE iti_destinations SET ' . implode(',', $updates) . ' WHERE id=?')->execute($vals);
            $log[] = "✅ #{$r['id']} name fixed: {$r['name_en']} → " . smart_title($r['name_en']);
            $n++;
        }
    }
    $log[] = "--- Title case: {$n} destinazioni aggiornate ---";
}

if ($action === 'translate' && !$dry_run) {
    if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) {
        $log[] = "❌ ANTHROPIC_API_KEY non definita in config.php";
    } else {
        $rows = $db->query("SELECT id, name_en, description_en FROM iti_destinations WHERE is_active=1 ORDER BY sort_order")->fetchAll();
        foreach ($rows as $r) {
            foreach (['fr','es','de'] as $lang) {
                // Skip if already translated (non-empty and different from EN)
                $existing_name = $db->prepare("SELECT name_{$lang}, description_{$lang} FROM iti_destinations WHERE id=?")->execute([$r['id']]) ? null : null;
                $chk = $db->prepare("SELECT name_{$lang}, description_{$lang} FROM iti_destinations WHERE id=?");
                $chk->execute([$r['id']]);
                $ex = $chk->fetch();
                if (!empty($ex["name_{$lang}"]) && $ex["name_{$lang}"] !== $r['name_en']) {
                    $log[] = "⏭ #{$r['id']} {$lang}: già tradotto, skip";
                    continue;
                }

                $result = anthropic_translate($r['description_en'] ?? '', $r['name_en'], $lang);
                if (empty($result) || !is_array($result)) {
                    $log[] = "⚠️ #{$r['id']} {$lang}: risposta API vuota o non valida";
                    continue;
                }

                $db->prepare("UPDATE iti_destinations SET name_{$lang}=?, description_{$lang}=? WHERE id=?")->execute([
                    $result['name']        ?? $r['name_en'],
                    $result['description'] ?? '',
                    $r['id'],
                ]);
                $log[] = "✅ #{$r['id']} {$lang}: {$r['name_en']} → " . ($result['name'] ?? '?');
            }
        }
        $log[] = "--- Traduzione completata ---";
    }
}

if ($action === 'fix_all' && !$dry_run) {
    // Prima fix names, poi translate
    $_POST['action'] = 'fix_names';
    // (handled above, just chain)
}

// ─── LOAD DATA PER PREVIEW ───────────────────────────────────────────────────
$destinations = $db->query(
    "SELECT id, code, name_en, name_it, name_fr, name_es, name_de,
            description_en, description_it, description_fr, description_es, description_de,
            region
     FROM iti_destinations WHERE is_active=1 ORDER BY sort_order, name_en"
)->fetchAll();

$has_api_key = defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY;

// ─── OUTPUT ──────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Fix & Translate Destinations</title>
<style>
body{font-family:sans-serif;padding:24px 32px;background:#f7f6f3;color:#1a1a1a;}
h1{color:#8b1010;margin-bottom:4px;}
.actions{display:flex;gap:10px;margin:16px 0;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:6px;font-size:.85rem;font-weight:600;text-decoration:none;border:none;cursor:pointer;}
.btn-red{background:#8b1010;color:#fff;}
.btn-blue{background:#1a4a8b;color:#fff;}
.btn-green{background:#0a6647;color:#fff;}
.btn-grey{background:#555;color:#fff;}
.log{background:#1a1a1a;color:#a8e6a3;font-family:monospace;font-size:.78rem;padding:12px 16px;border-radius:8px;max-height:200px;overflow-y:auto;margin-bottom:20px;white-space:pre-wrap;}
.warn{background:#fff3cd;padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:.85rem;}
table{width:100%;border-collapse:collapse;font-size:.78rem;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.06);}
th{background:#2c2c2a;color:#fff;padding:8px 10px;text-align:left;white-space:nowrap;}
td{padding:7px 10px;border-bottom:1px solid #eee;vertical-align:top;}
.code{font-family:monospace;font-weight:700;font-size:.8rem;}
.name-col{font-weight:600;}
.missing{color:#c0392b;font-style:italic;}
.ok{color:#0a6647;}
.region{font-size:.72rem;color:#888;}
.desc-preview{max-height:60px;overflow:hidden;color:#555;font-size:.75rem;line-height:1.4;}
.lang-cell{min-width:120px;}
</style>
</head>
<body>

<h1>🌍 Fix & Translate Destinations</h1>
<div style="color:#666;font-size:.85rem;margin-bottom:16px;">
  <?= count($destinations) ?> destinations in DB · 
  API Key: <?= $has_api_key ? '<span class="ok">✅ configured</span>' : '<span class="missing">❌ not configured — add define(\'ANTHROPIC_API_KEY\',\'sk-ant-...\') to config.php</span>' ?>
</div>

<?php if ($log): ?>
<div class="log"><?= implode("\n", array_map('htmlspecialchars', $log)) ?></div>
<?php endif; ?>

<?php if (!$has_api_key): ?>
<div class="warn">
  ⚠️ Per tradurre aggiungere a <code>includes/config.php</code>:<br>
  <code>define('ANTHROPIC_API_KEY', 'sk-ant-api03-...');</code>
</div>
<?php endif; ?>

<div class="actions">
  <form method="POST">
    <input type="hidden" name="action" value="fix_names">
    <button class="btn btn-red" onclick="return confirm('Fix title case su tutti i nomi?')">
      🔤 Fix Title Case
    </button>
  </form>
  <form method="POST">
    <input type="hidden" name="action" value="translate">
    <button class="btn btn-blue" <?= !$has_api_key?'disabled title="API key mancante"':'' ?>
            onclick="return confirm('Tradurre in FR, ES, DE via Anthropic? Potrebbe richiedere 1-2 minuti.')">
      🌐 Translate to FR / ES / DE
    </button>
  </form>
  <form method="POST">
    <input type="hidden" name="action" value="fix_names">
    <button class="btn btn-green" <?= !$has_api_key?'disabled':'' ?>
            onclick="this.form.action.value='fix_all';return confirm('Fix title case + traduci tutto?')">
      ✨ Fix all + Translate
    </button>
  </form>
  <a href="destinations.php" class="btn btn-grey">← Back to Destinations</a>
</div>

<!-- Preview tabella -->
<table>
<thead>
  <tr>
    <th>Code</th>
    <th>Region</th>
    <th>Name EN</th>
    <th class="lang-cell">Name IT</th>
    <th class="lang-cell">Name FR</th>
    <th class="lang-cell">Name ES</th>
    <th class="lang-cell">Name DE</th>
    <th>Description EN</th>
    <th>IT</th>
    <th>FR</th>
    <th>ES</th>
    <th>DE</th>
  </tr>
</thead>
<tbody>
<?php foreach ($destinations as $d): ?>
<tr>
  <td class="code"><?= htmlspecialchars($d['code']) ?></td>
  <td class="region"><?= htmlspecialchars($d['region'] ?? '') ?></td>
  <td class="name-col"><?= htmlspecialchars($d['name_en']) ?></td>
  <?php foreach (['it','fr','es','de'] as $lang): ?>
  <td class="lang-cell <?= empty($d["name_{$lang}"]) || $d["name_{$lang}"] === $d['name_en'] ? 'missing' : '' ?>">
    <?= htmlspecialchars($d["name_{$lang}"] ?: '—') ?>
  </td>
  <?php endforeach; ?>
  <td><div class="desc-preview"><?= htmlspecialchars(mb_substr($d['description_en'] ?? '', 0, 120)) ?><?= strlen($d['description_en']??'')>120?'…':'' ?></div></td>
  <?php foreach (['it','fr','es','de'] as $lang): ?>
  <td style="text-align:center;"><?= !empty($d["description_{$lang}"]) ? '<span class="ok">✅</span>' : '<span class="missing">—</span>' ?></td>
  <?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>

</body>
</html>
