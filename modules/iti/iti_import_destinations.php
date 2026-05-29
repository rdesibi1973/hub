<?php
/**
 * iti_import_destinations.php
 * Scrape EN from savannahexplorers.net, IT from savannahexplorers.com
 * Translate to FR/ES/DE via Anthropic API
 * Eseguire una sola volta: hub/modules/iti/iti_import_destinations.php
 *
 * Richiede in includes/config.php:
 *   define('ANTHROPIC_API_KEY', 'sk-ant-...');
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db = db();

// ─── MAPPA DESTINAZIONI ───────────────────────────────────────────────────────
// [ code, name_en_fallback, region, slug_en, slug_it, sort_order ]
$DESTINATIONS = [
    // NATIONAL PARKS
    ['ANP', 'Arusha National Park',             'National Parks', 'arusha-national-park',                  'arusha-national-park',               0],
    ['GNP', 'Gombe National Park',              'National Parks', 'gombe-stream-national-park',            'gombe-stream-national-park',          1],
    ['KNP', 'Katavi National Park',             'National Parks', 'katavi-national-park',                  'katavi',                              2],
    ['KTP', 'Kitulo National Park',             'National Parks', 'kitulo-national-park',                  'Kitulo',                              3],
    ['MMP', 'Mahale Mountains National Park',   'National Parks', 'mahale-mountains-national-park',        'mahale-mountains-national-park',      4],
    ['LMN', 'Lake Manyara National Park',       'National Parks', 'lake-manyara-national-park',            'lake-manyara-national-park',          5],
    ['MKZ', 'Mkomazi National Park',            'National Parks', 'mkomazi-national-park',                 'mkomazi-national-park',               6],
    ['MKM', 'Mikumi National Park',             'National Parks', 'mikumi-national-park',                  'mikumi-national-park',                7],
    ['NCA', 'Ngorongoro Conservation Area',     'National Parks', 'ngorongoro-conservation-national-park', 'ngorongoro-conservation-area',        8],
    ['RNP', 'Ruaha National Park',              'National Parks', 'ruaha-national-park',                   'ruaha-national-park',                 9],
    ['RBN', 'Rubondo National Park',            'National Parks', 'rubondo-national-park',                 'rubondo',                            10],
    ['SDN', 'Saadani National Park',            'National Parks', 'saadani-national-park',                 'saadani',                            11],
    ['SGR', 'Selous Game Reserve',              'National Parks', 'selous-game-reserve',                   'selous-game-reserve',                12],
    ['SNP', 'Serengeti National Park',          'National Parks', 'serengeti-national-park',               'serengeti-national-park',            13],
    ['TRP', 'Tarangire National Park',          'National Parks', 'tarangire-national-park',               'tarangire-national-park',            14],
    ['UNP', 'Udzungwa Mountains National Park', 'National Parks', 'udzungwa-mountains-national-park',      'udzungwa-mountains-national-park',   15],
    // TOWNS & OTHER
    ['ARU', 'Arusha',                           'Northern Circuit', 'arusha',             'arusha',         20],
    ['EYS', 'Lake Eyasi',                       'Northern Circuit', 'lake-eyasi',         'lake-eyasi',     21],
    ['NAT', 'Lake Natron',                      'Northern Circuit', 'lake-natron',        'lake-natron',    22],
    ['OLD', 'Olduvai Gorge',                    'Northern Circuit', 'olduvai-gorge',      'olduvai-gorge',  23],
    ['KLM', 'Mount Kilimanjaro',                'Northern Circuit', 'mount-kilimanjaro',  'kilimanjaro',    24],
    ['MRU', 'Mount Meru',                       'Northern Circuit', 'mount-meru',         'mount-meru',     25],
    ['ODL', 'Ol Doinyo Lengai',                 'Northern Circuit', 'ol-doinyo-lengai',   'ol-doinyo-lengai',26],
    ['ZNZ', 'Zanzibar',                         'Zanzibar',         'zanzibar/zanzibar',  'zanzibar/zanzibar',30],
    ['PMB', 'Pemba Island',                     'Zanzibar',         'pemba-island',       'pemba-island',   31],
    ['MFA', 'Mafia Island',                     'Zanzibar',         'mafia-island',       'mafia-island',   32],
    ['PGN', 'Pangani',                          'Zanzibar',         'pangani',            'pangani',        33],
    ['FJV', 'Fanjove Island',                   'Zanzibar',         'fanjove-island',     'fanjove-island', 34],
    ['DAR', 'Dar es Salaam',                    'Other',            'dar-es-salaam',      'dar-es-salaam',  35],
];

$BASE_EN = 'https://www.savannahexplorers.net/tanzania/';
$BASE_IT = 'https://www.savannahexplorers.com/tanzania/';

// ─── HELPER: title case ───────────────────────────────────────────────────────
function smart_title(string $s): string {
    $lower = ['a','an','the','and','but','or','for','of','in','on','at','to','by',
              'di','del','della','da','in','con','su','per','tra','fra',
              'de','du','des','la','le','les','et','au',
              'el','los','las','en','por','para'];
    $words = explode(' ', $s);
    foreach ($words as $i => &$w) {
        if (preg_match('/^[A-Z]{2,5}$/', $w)) continue; // acronimo, lascia
        $w = ($i === 0 || !in_array(strtolower($w), $lower))
            ? ucfirst(strtolower($w))
            : strtolower($w);
    }
    return implode(' ', $words);
}

// ─── HELPER: fetch URL ───────────────────────────────────────────────────────
function fetch_url(string $url): string {
    $ctx = stream_context_create(['http' => [
        'timeout'       => 15,
        'user_agent'    => 'Mozilla/5.0 (compatible; SavannahBot/1.0)',
        'ignore_errors' => true,
    ]]);
    return @file_get_contents($url, false, $ctx) ?: '';
}

// ─── HELPER: parse page ───────────────────────────────────────────────────────
function parse_page(string $html, string $lang): array {
    if (!$html) return ['name'=>'','description'=>''];
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xp = new DOMXPath($dom);

    $h1 = $xp->query('//h1');
    $name = $h1->length ? trim($h1->item(0)->textContent) : '';

    $h2_targets = $lang === 'it'
        ? ['il parco','descrizione','il sito','storia','location']
        : ['description','about','history','location'];

    $description = '';
    foreach ($xp->query('//h2') as $h2) {
        $h2text = strtolower(trim($h2->textContent));
        foreach ($h2_targets as $t) {
            if (str_starts_with($h2text, $t)) {
                $sibling = $h2->nextSibling;
                $parts = [];
                while ($sibling) {
                    if ($sibling->nodeType === XML_ELEMENT_NODE && $sibling->nodeName === 'h2') break;
                    if ($sibling->nodeType === XML_ELEMENT_NODE) {
                        $t2 = trim($sibling->textContent);
                        if ($t2) $parts[] = $t2;
                    }
                    $sibling = $sibling->nextSibling;
                }
                $description = implode("\n\n", $parts);
                break 2;
            }
        }
    }
    // Fallback: first long paragraph
    if (!$description) {
        foreach ($xp->query('//p') as $p) {
            $t = trim($p->textContent);
            if (strlen($t) > 100) { $description = $t; break; }
        }
    }
    return ['name' => $name, 'description' => $description];
}

// ─── HELPER: Anthropic translate ─────────────────────────────────────────────
function anthropic_translate(string $name_en, string $desc_en, string $lang_code): array {
    if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) return [];
    $lang_names = ['fr'=>'French','es'=>'Spanish','de'=>'German'];
    $lang_name  = $lang_names[$lang_code] ?? $lang_code;

    $prompt = "You are a professional travel copywriter specializing in African safari destinations.\n"
        . "Translate the following Tanzania destination information into {$lang_name}.\n\n"
        . "Return ONLY a valid JSON object with exactly two keys:\n"
        . "- \"name\": translated destination name (proper nouns like national park names should follow {$lang_name} conventions)\n"
        . "- \"description\": translated description, keeping the same style and length\n\n"
        . "Do NOT include markdown, backticks, or any other text.\n\n"
        . "Name (English): {$name_en}\n\n"
        . "Description (English):\n{$desc_en}";

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-20250514',
        'max_tokens' => 1200,
        'messages'   => [['role'=>'user','content'=>$prompt]],
    ]);

    $ctx = stream_context_create(['http'=>[
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n"
                   . "x-api-key: " . ANTHROPIC_API_KEY . "\r\n"
                   . "anthropic-version: 2023-06-01",
        'content' => $payload,
        'timeout' => 30,
        'ignore_errors' => true,
    ]]);

    $resp = @file_get_contents('https://api.anthropic.com/v1/messages', false, $ctx);
    if (!$resp) return [];

    $data = json_decode($resp, true);
    $raw  = trim($data['content'][0]['text'] ?? '');
    $raw  = preg_replace('/^```json\s*/i', '', $raw);
    $raw  = preg_replace('/```$/', '', trim($raw));
    $result = json_decode($raw, true);
    return is_array($result) ? $result : [];
}

// ─── MAIN RUN ────────────────────────────────────────────────────────────────
$dry_run = isset($_GET['dry']) && $_GET['dry'] === '1';
$do_run  = isset($_POST['run']) || (isset($_GET['run']) && $_GET['run'] === '1');
$has_api = defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY;

$rows    = [];   // collected data for preview + import
$log     = [];
$ok = $err = 0;

if ($do_run || $dry_run) {
    foreach ($DESTINATIONS as [$code, $name_fb, $region, $slug_en, $slug_it, $sort]) {

        $url_en = $BASE_EN . $slug_en . '.html';
        $url_it = $BASE_IT . $slug_it . '.html';

        $en = parse_page(fetch_url($url_en), 'en');
        $it = parse_page(fetch_url($url_it), 'it');

        $name_en = smart_title($en['name'] ?: $name_fb);
        $name_it = smart_title($it['name'] ?: $name_en);
        $desc_en = $en['description'];
        $desc_it = $it['description'];

        // Translate to FR, ES, DE
        $tr = ['fr'=>[],'es'=>[],'de'=>[]];
        if ($has_api && $desc_en) {
            foreach (['fr','es','de'] as $lang) {
                $tr[$lang] = anthropic_translate($name_en, $desc_en, $lang);
            }
        }

        $name_fr = smart_title($tr['fr']['name'] ?? $name_en);
        $name_es = smart_title($tr['es']['name'] ?? $name_en);
        $name_de = smart_title($tr['de']['name'] ?? $name_en);
        $desc_fr = $tr['fr']['description'] ?? '';
        $desc_es = $tr['es']['description'] ?? '';
        $desc_de = $tr['de']['description'] ?? '';

        $rows[] = compact(
            'code','region','sort',
            'name_en','name_it','name_fr','name_es','name_de',
            'desc_en','desc_it','desc_fr','desc_es','desc_de',
            'url_en','url_it'
        );

        if (!$dry_run) {
            try {
                $db->prepare(
                    'INSERT INTO iti_destinations
                     (code,name_en,name_it,name_fr,name_es,name_de,
                      description_en,description_it,description_fr,description_es,description_de,
                      region,country,sort_order,is_active)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,\'Tanzania\',?,1)'
                )->execute([
                    $code,
                    $name_en,$name_it,$name_fr,$name_es,$name_de,
                    $desc_en,$desc_it,$desc_fr,$desc_es,$desc_de,
                    $region,$sort,
                ]);
                $log[] = "✅ {$code} — {$name_en}";
                $ok++;
            } catch (Exception $e) {
                $log[] = "❌ {$code} — " . $e->getMessage();
                $err++;
            }
        }
    }

    if (!$dry_run && $ok > 0) {
        $log[] = "";
        $log[] = "✅ Import completato: {$ok} inserite, {$err} errori.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Import Destinations</title>
<style>
body{font-family:sans-serif;padding:24px 32px;background:#f7f6f3;color:#1a1a1a;font-size:.85rem;}
h1{color:#8b1010;margin-bottom:4px;}
.actions{display:flex;gap:10px;margin:16px 0;flex-wrap:wrap;align-items:center;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:6px;font-size:.85rem;font-weight:600;text-decoration:none;border:none;cursor:pointer;color:#fff;}
.btn-red{background:#8b1010;}.btn-grey{background:#333;}.btn-blue{background:#1a4a8b;}
.btn:disabled{opacity:.4;cursor:default;}
.warn{background:#fff3cd;padding:10px 14px;border-radius:6px;margin-bottom:16px;border:1px solid #ffc107;}
.success{background:#d4edda;padding:10px 14px;border-radius:6px;margin-bottom:16px;border:1px solid #28a745;}
.log{background:#1a1a1a;color:#a8e6a3;font-family:monospace;font-size:.75rem;padding:12px 16px;border-radius:8px;max-height:180px;overflow-y:auto;margin-bottom:16px;white-space:pre-wrap;}
table{width:100%;border-collapse:collapse;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.06);}
th{background:#2c2c2a;color:#fff;padding:7px 10px;text-align:left;white-space:nowrap;font-size:.75rem;}
td{padding:6px 10px;border-bottom:1px solid #eee;vertical-align:top;font-size:.75rem;}
.code{font-family:monospace;font-weight:700;}
.miss{color:#c0392b;font-style:italic;}
.ok{color:#0a6647;}
.dp{max-height:55px;overflow:hidden;color:#666;line-height:1.4;}
.tcheck{text-align:center;font-size:1rem;}
</style>
</head>
<body>
<h1>🌍 Import Destinations</h1>
<p style="color:#666;margin-bottom:12px;">
  Scrape EN from <strong>savannahexplorers.net</strong> · IT from <strong>savannahexplorers.com</strong> · FR/ES/DE via Anthropic API
  <br>API Key: <?= $has_api ? '<span class="ok">✅ configured</span>' : '<span class="miss">❌ missing — add define(\'ANTHROPIC_API_KEY\',\'sk-ant-...\') to config.php</span>' ?>
</p>

<?php if (!$has_api): ?>
<div class="warn">⚠️ Senza API key le traduzioni FR/ES/DE non verranno create (i campi rimarranno uguali all'EN).</div>
<?php endif; ?>

<?php if ($log): ?>
<div class="log"><?= implode("\n", array_map('htmlspecialchars', $log)) ?></div>
<?php endif; ?>

<?php if ($do_run && !$dry_run && $ok > 0): ?>
<div class="success">✅ Import completato — <?= $ok ?> destinazioni inserite. <a href="destinations.php">→ Vai alle destinazioni</a></div>
<?php endif; ?>

<div class="actions">
  <form method="GET">
    <input type="hidden" name="dry" value="1">
    <button class="btn btn-grey">🔍 Dry Run (preview senza salvare)</button>
  </form>
  <form method="POST" onsubmit="return confirm('TRUNCATE iti_destinations e reimporta tutto? Questa operazione non può essere annullata.')">
    <input type="hidden" name="run" value="1">
    <button class="btn btn-red">🚀 Truncate + Import</button>
  </form>
  <a href="destinations.php" class="btn btn-grey" style="background:#555;">← Destinations</a>
</div>

<?php if ($rows): ?>
<table>
<thead><tr>
  <th>Code</th><th>Region</th>
  <th>Name EN</th><th>Name IT</th><th>Name FR</th><th>Name ES</th><th>Name DE</th>
  <th>Desc EN</th><th>IT</th><th>FR</th><th>ES</th><th>DE</th>
</tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
  <td class="code"><?= h($r['code']) ?></td>
  <td style="color:#888;"><?= h($r['region']) ?></td>
  <td><a href="<?= h($r['url_en']) ?>" target="_blank"><?= h($r['name_en']) ?></a></td>
  <td class="<?= $r['name_it']===$r['name_en']?'miss':'' ?>"><?= h($r['name_it']) ?></td>
  <td class="<?= $r['name_fr']===$r['name_en']?'miss':'' ?>"><?= h($r['name_fr']) ?></td>
  <td class="<?= $r['name_es']===$r['name_en']?'miss':'' ?>"><?= h($r['name_es']) ?></td>
  <td class="<?= $r['name_de']===$r['name_en']?'miss':'' ?>"><?= h($r['name_de']) ?></td>
  <td><div class="dp"><?= h(mb_substr($r['desc_en'],0,120)) ?><?= strlen($r['desc_en'])>120?'…':'' ?></div></td>
  <?php foreach (['desc_it','desc_fr','desc_es','desc_de'] as $dk): ?>
  <td class="tcheck"><?= !empty($r[$dk]) ? '<span class="ok">✅</span>' : '<span class="miss">—</span>' ?></td>
  <?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php elseif (!$do_run && !$dry_run): ?>
<p style="color:#888;">Clicca "Dry Run" per vedere l'anteprima senza salvare, oppure "Truncate + Import" per procedere.</p>
<?php endif; ?>

</body>
</html>
