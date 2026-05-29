<?php
/**
 * iti_import_destinations.php  — v3
 * Scrape EN da savannahexplorers.net, IT da savannahexplorers.com
 * Nomi FR/ES/DE hardcoded per le destinazioni principali
 * Descrizioni FR/ES/DE = stessa descrizione EN (da tradurre manualmente dopo)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db = db();

// ─── NOMI NELLE 5 LINGUE ─────────────────────────────────────────────────────
// Formato: code => [name_en, name_it, name_fr, name_es, name_de]
$NAMES = [
    'ANP' => ['Arusha National Park',             'Parco Nazionale di Arusha',            'Parc National d\'Arusha',              'Parque Nacional de Arusha',               'Arusha-Nationalpark'],
    'GNP' => ['Gombe National Park',              'Parco Nazionale di Gombe',             'Parc National de Gombe',               'Parque Nacional de Gombe',                'Gombe-Nationalpark'],
    'KNP' => ['Katavi National Park',             'Parco Nazionale di Katavi',            'Parc National de Katavi',              'Parque Nacional de Katavi',               'Katavi-Nationalpark'],
    'KTP' => ['Kitulo National Park',             'Parco Nazionale di Kitulo',            'Parc National de Kitulo',              'Parque Nacional de Kitulo',               'Kitulo-Nationalpark'],
    'MMP' => ['Mahale Mountains National Park',   'Parco Nazionale dei Monti Mahale',     'Parc National des Monts Mahale',       'Parque Nacional de los Montes Mahale',    'Mahale-Berge-Nationalpark'],
    'LMN' => ['Lake Manyara National Park',       'Parco Nazionale del Lago Manyara',     'Parc National du Lac Manyara',         'Parque Nacional del Lago Manyara',        'Lake-Manyara-Nationalpark'],
    'MKZ' => ['Mkomazi National Park',            'Parco Nazionale di Mkomazi',           'Parc National de Mkomazi',             'Parque Nacional de Mkomazi',              'Mkomazi-Nationalpark'],
    'MKM' => ['Mikumi National Park',             'Parco Nazionale di Mikumi',            'Parc National de Mikumi',              'Parque Nacional de Mikumi',               'Mikumi-Nationalpark'],
    'NCA' => ['Ngorongoro Conservation Area',     'Area di Conservazione del Ngorongoro', 'Zone de Conservation du Ngorongoro',   'Área de Conservación del Ngorongoro',     'Ngorongoro-Schutzgebiet'],
    'RNP' => ['Ruaha National Park',              'Parco Nazionale di Ruaha',             'Parc National de Ruaha',               'Parque Nacional de Ruaha',                'Ruaha-Nationalpark'],
    'RBN' => ['Rubondo National Park',            'Parco Nazionale di Rubondo',           'Parc National de Rubondo',             'Parque Nacional de Rubondo',              'Rubondo-Nationalpark'],
    'SDN' => ['Saadani National Park',            'Parco Nazionale di Saadani',           'Parc National de Saadani',             'Parque Nacional de Saadani',              'Saadani-Nationalpark'],
    'SGR' => ['Selous Game Reserve',              'Riserva di Caccia del Selous',         'Réserve de Gibier de Selous',          'Reserva de Caza del Selous',              'Selous-Wildreservat'],
    'SNP' => ['Serengeti National Park',          'Parco Nazionale del Serengeti',        'Parc National du Serengeti',           'Parque Nacional del Serengeti',           'Serengeti-Nationalpark'],
    'TRP' => ['Tarangire National Park',          'Parco Nazionale di Tarangire',         'Parc National de Tarangire',           'Parque Nacional de Tarangire',            'Tarangire-Nationalpark'],
    'UNP' => ['Udzungwa Mountains National Park', 'Parco Nazionale dei Monti Udzungwa',   'Parc National des Monts Udzungwa',     'Parque Nacional de los Montes Udzungwa',  'Udzungwa-Berge-Nationalpark'],
    'ARU' => ['Arusha',                           'Arusha',                               'Arusha',                               'Arusha',                                  'Arusha'],
    'EYS' => ['Lake Eyasi',                       'Lago Eyasi',                           'Lac Eyasi',                            'Lago Eyasi',                              'Eyasi-See'],
    'NAT' => ['Lake Natron',                      'Lago Natron',                          'Lac Natron',                           'Lago Natron',                             'Natron-See'],
    'OLD' => ['Olduvai Gorge',                    'Gola di Olduvai',                      'Gorge d\'Olduvai',                     'Garganta de Olduvai',                     'Olduvai-Schlucht'],
    'KLM' => ['Mount Kilimanjaro',                'Monte Kilimanjaro',                    'Mont Kilimandjaro',                    'Monte Kilimanjaro',                       'Kilimandscharo'],
    'MRU' => ['Mount Meru',                       'Monte Meru',                           'Mont Méru',                            'Monte Meru',                              'Mount Meru'],
    'ODL' => ['Ol Doinyo Lengai',                 'Ol Doinyo Lengai',                     'Ol Doinyo Lengai',                     'Ol Doinyo Lengai',                        'Ol Doinyo Lengai'],
    'ZNZ' => ['Zanzibar',                         'Zanzibar',                             'Zanzibar',                             'Zanzíbar',                                'Sansibar'],
    'PMB' => ['Pemba Island',                     'Isola di Pemba',                       'Île de Pemba',                         'Isla de Pemba',                           'Pemba-Insel'],
    'MFA' => ['Mafia Island',                     'Isola di Mafia',                       'Île de Mafia',                         'Isla de Mafia',                           'Mafia-Insel'],
    'PGN' => ['Pangani',                          'Pangani',                              'Pangani',                              'Pangani',                                 'Pangani'],
    'FJV' => ['Fanjove Island',                   'Isola di Fanjove',                     'Île de Fanjove',                       'Isla de Fanjove',                         'Fanjove-Insel'],
    'DAR' => ['Dar es Salaam',                    'Dar es Salaam',                        'Dar es Salaam',                        'Dar es Salaam',                           'Daressalam'],
];

// ─── MAPPA SLUG + REGION + SORT ──────────────────────────────────────────────
$DESTINATIONS = [
    ['ANP', 'National Parks',   'arusha-national-park',                 'arusha-national-park',          0],
    ['GNP', 'National Parks',   'gombe-stream-national-park',           'gombe-stream-national-park',    1],
    ['KNP', 'National Parks',   'katavi-national-park',                 'katavi',                        2],
    ['KTP', 'National Parks',   'kitulo-national-park',                 'Kitulo',                        3],
    ['MMP', 'National Parks',   'mahale-mountains-national-park',       'mahale-mountains-national-park',4],
    ['LMN', 'National Parks',   'lake-manyara-national-park',           'lake-manyara-national-park',    5],
    ['MKZ', 'National Parks',   'mkomazi-national-park',                'mkomazi-national-park',         6],
    ['MKM', 'National Parks',   'mikumi-national-park',                 'mikumi-national-park',          7],
    ['NCA', 'National Parks',   'ngorongoro-conservation-national-park','ngorongoro-conservation-area',  8],
    ['RNP', 'National Parks',   'ruaha-national-park',                  'ruaha-national-park',           9],
    ['RBN', 'National Parks',   'rubondo-national-park',                'rubondo',                      10],
    ['SDN', 'National Parks',   'saadani-national-park',                'saadani',                      11],
    ['SGR', 'National Parks',   'selous-game-reserve',                  'selous-game-reserve',          12],
    ['SNP', 'National Parks',   'serengeti-national-park',              'serengeti-national-park',      13],
    ['TRP', 'National Parks',   'tarangire-national-park',              'tarangire-national-park',      14],
    ['UNP', 'National Parks',   'udzungwa-mountains-national-park',     'udzungwa-mountains-national-park',15],
    ['ARU', 'Northern Circuit', 'arusha',                               'arusha',                       20],
    ['EYS', 'Northern Circuit', 'lake-eyasi',                           'lake-eyasi',                   21],
    ['NAT', 'Northern Circuit', 'lake-natron',                          'lake-natron',                  22],
    ['OLD', 'Northern Circuit', 'olduvai-gorge',                        'olduvai-gorge',                23],
    ['KLM', 'Northern Circuit', 'mount-kilimanjaro',                    'kilimanjaro',                  24],
    ['MRU', 'Northern Circuit', 'mount-meru',                           'mount-meru',                   25],
    ['ODL', 'Northern Circuit', 'ol-doinyo-lengai',                     'ol-doinyo-lengai',             26],
    ['ZNZ', 'Zanzibar',         'zanzibar/zanzibar',                    'zanzibar/zanzibar',            30],
    ['PMB', 'Zanzibar',         'pemba-island',                         'pemba-island',                 31],
    ['MFA', 'Zanzibar',         'mafia-island',                         'mafia-island',                 32],
    ['PGN', 'Zanzibar',         'pangani',                              'pangani',                      33],
    ['FJV', 'Zanzibar',         'fanjove-island',                       'fanjove-island',               34],
    ['DAR', 'Other',            'dar-es-salaam',                        'dar-es-salaam',                35],
];

$BASE_EN = 'https://www.savannahexplorers.net/tanzania/';
$BASE_IT = 'https://www.savannahexplorers.com/tanzania/';

// ─── HELPERS ─────────────────────────────────────────────────────────────────
function fetch_page(string $url): string {
    $ctx = stream_context_create(['http' => [
        'timeout'       => 15,
        'user_agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'ignore_errors' => true,
        'header'        => "Accept-Language: en-US,en;q=0.9\r\nAccept: text/html\r\n",
    ]]);
    return @file_get_contents($url, false, $ctx) ?: '';
}

function extract_description(string $html, string $lang): string {
    if (!$html) return '';
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xp = new DOMXPath($dom);

    $targets = $lang === 'it'
        ? ['il parco', 'descrizione', 'il sito', 'storia', 'location']
        : ['description', 'about', 'serengeti national park', 'history', 'location'];

    foreach ($xp->query('//h2') as $h2) {
        $h2t = strtolower(trim($h2->textContent));
        foreach ($targets as $t) {
            if (strpos($h2t, $t) === 0) {
                $parts = []; $s = $h2->nextSibling;
                while ($s) {
                    if ($s->nodeType === XML_ELEMENT_NODE && $s->nodeName === 'h2') break;
                    if ($s->nodeType === XML_ELEMENT_NODE) { $p = trim($s->textContent); if ($p) $parts[] = $p; }
                    $s = $s->nextSibling;
                }
                $d = implode(' ', $parts);
                if (strlen($d) > 80) return $d;
            }
        }
    }
    // Fallback: longest intro paragraph
    foreach ($xp->query('//p') as $p) {
        $t = trim($p->textContent);
        if (strlen($t) > 120 && strpos($t,'©') === false && strpos($t,'info@') === false) return $t;
    }
    return '';
}

// ─── MAIN ────────────────────────────────────────────────────────────────────
$dry_run = isset($_GET['dry']);
$do_run  = isset($_POST['run']);
$rows    = [];
$log     = [];
$ok = $err = 0;

if ($do_run || $dry_run) {

    if ($do_run && !$dry_run) {
        // TRUNCATE
        $db->exec("SET FOREIGN_KEY_CHECKS=0");
        $db->exec("TRUNCATE TABLE iti_destinations");
        $db->exec("SET FOREIGN_KEY_CHECKS=1");
        $log[] = "🗑 TRUNCATE iti_destinations — done";
    }

    foreach ($DESTINATIONS as [$code, $region, $slug_en, $slug_it, $sort]) {
        $names = $NAMES[$code];

        // Fetch descriptions
        $html_en = fetch_page($BASE_EN . $slug_en . '.html');
        $html_it = fetch_page($BASE_IT . $slug_it . '.html');

        $desc_en = extract_description($html_en, 'en');
        $desc_it = extract_description($html_it, 'it');

        // FR/ES/DE: use EN description (same content, different language names)
        // These can be translated later via the edit form
        $desc_fr = $desc_en;
        $desc_es = $desc_en;
        $desc_de = $desc_en;

        $rows[] = [
            'code'    => $code,
            'region'  => $region,
            'sort'    => $sort,
            'name_en' => $names[0],
            'name_it' => $names[1],
            'name_fr' => $names[2],
            'name_es' => $names[3],
            'name_de' => $names[4],
            'desc_en' => $desc_en,
            'desc_it' => $desc_it,
            'desc_fr' => $desc_fr,
            'desc_es' => $desc_es,
            'desc_de' => $desc_de,
        ];

        if ($do_run && !$dry_run) {
            try {
                $db->prepare(
                    'INSERT INTO iti_destinations
                     (code,name_en,name_it,name_fr,name_es,name_de,
                      description_en,description_it,description_fr,description_es,description_de,
                      region,country,sort_order,is_active)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,\'Tanzania\',?,1)'
                )->execute([
                    $code,
                    $names[0],$names[1],$names[2],$names[3],$names[4],
                    $desc_en,$desc_it,$desc_fr,$desc_es,$desc_de,
                    $region,$sort,
                ]);
                $log[] = "✅ {$code} — {$names[0]}";
                $ok++;
            } catch (Exception $e) {
                $log[] = "❌ {$code} — " . $e->getMessage();
                $err++;
            }
        }
    }

    if ($do_run && !$dry_run) {
        $log[] = "";
        $log[] = "Import completato: {$ok} inserite, {$err} errori.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Import Destinations</title>
<style>
body{font-family:sans-serif;padding:24px 32px;background:#f7f6f3;color:#1a1a1a;font-size:.83rem;}
h1{color:#8b1010;}
.actions{display:flex;gap:10px;margin:16px 0;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:6px;font-size:.84rem;font-weight:600;text-decoration:none;border:none;cursor:pointer;color:#fff;}
.btn-red{background:#8b1010;}.btn-grey{background:#444;}.btn-blue{background:#1a4a8b;}
.log{background:#1a1a1a;color:#a8e6a3;font-family:monospace;font-size:.75rem;padding:12px 16px;border-radius:8px;max-height:180px;overflow-y:auto;margin:12px 0;white-space:pre-wrap;}
.ok-box{background:#d4edda;padding:10px 14px;border-radius:6px;margin-bottom:12px;}
table{width:100%;border-collapse:collapse;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-top:12px;}
th{background:#2c2c2a;color:#fff;padding:7px 10px;text-align:left;font-size:.73rem;white-space:nowrap;}
td{padding:6px 10px;border-bottom:1px solid #eee;vertical-align:top;font-size:.73rem;}
.code{font-family:monospace;font-weight:700;}
.miss{color:#c0392b;font-style:italic;}
.ok{color:#0a6647;}
.dp{max-height:50px;overflow:hidden;color:#666;line-height:1.4;}
.tc{text-align:center;}
</style>
</head>
<body>
<h1>🌍 Import Destinations</h1>
<p style="color:#666;margin-bottom:4px;">
  Scrape descrizioni EN da <strong>savannahexplorers.net</strong> e IT da <strong>savannahexplorers.com</strong>.<br>
  Nomi FR/ES/DE già inclusi nello script. Descrizioni FR/ES/DE = testo EN (da tradurre manualmente se necessario).
</p>

<?php if ($log): ?>
<div class="log"><?= implode("\n", array_map('htmlspecialchars', $log)) ?></div>
<?php endif; ?>
<?php if ($do_run && !$dry_run && $ok > 0): ?>
<div class="ok-box">✅ Import completato — <?= $ok ?> destinazioni inserite, <?= $err ?> errori. <a href="destinations.php">→ Vai alle destinazioni</a></div>
<?php endif; ?>

<div class="actions">
  <a href="?dry" class="btn btn-grey">🔍 Dry Run (preview)</a>
  <form method="POST" style="margin:0;" onsubmit="return confirm('TRUNCATE iti_destinations e reimporta tutto?')">
    <input type="hidden" name="run" value="1">
    <button class="btn btn-red">🚀 Truncate + Import</button>
  </form>
  <a href="destinations.php" class="btn btn-grey" style="background:#555;">← Back</a>
</div>

<?php if ($rows): ?>
<table>
<thead><tr>
  <th>Code</th><th>Region</th>
  <th>EN</th><th>IT</th><th>FR</th><th>ES</th><th>DE</th>
  <th>Desc EN</th><th>IT</th><th>FR/ES/DE</th>
</tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
  <td class="code"><?= h($r['code']) ?></td>
  <td style="color:#888;font-size:.7rem;"><?= h($r['region']) ?></td>
  <td style="font-weight:600;"><?= h($r['name_en']) ?></td>
  <td><?= h($r['name_it']) ?></td>
  <td><?= h($r['name_fr']) ?></td>
  <td><?= h($r['name_es']) ?></td>
  <td><?= h($r['name_de']) ?></td>
  <td><div class="dp"><?= h(mb_substr($r['desc_en'],0,100)) ?><?= strlen($r['desc_en'])>100?'…':'' ?></div></td>
  <td class="tc"><?= $r['desc_it'] ? '<span class="ok">✅</span>' : '<span class="miss">—</span>' ?></td>
  <td class="tc" style="color:#888;font-size:.7rem;"><?= $r['desc_en'] ? '= EN' : '—' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php elseif (!$do_run && !$dry_run): ?>
<p style="color:#888;margin-top:12px;">Clicca "Dry Run" per anteprima, oppure "Truncate + Import" per procedere direttamente.</p>
<?php endif; ?>
</body>
</html>
