<?php
/**
 * iti_import_destinations.php
 * Script one-shot per importare le destinazioni da savannahexplorers.net/.com
 * Eseguire una sola volta via browser: hub/modules/iti/iti_import_destinations.php
 */

require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db = db();

// ─── MAPPA DESTINAZIONI ───────────────────────────────────────────────────────
// [ code, name_en (fallback), region, slug_en, slug_it, sort ]
$DESTINATIONS = [
    // ── NATIONAL PARKS ──────────────────────────────────────────────────────
    ['ANP', 'Arusha National Park',            'National Parks', 'arusha-national-park',                   'arusha-national-park',                0],
    ['GNP', 'Gombe National Park',             'National Parks', 'gombe-stream-national-park',             'gombe-stream-national-park',          1],
    ['KNP', 'Katavi National Park',            'National Parks', 'katavi-national-park',                   'katavi',                              2],
    ['KTP', 'Kitulo National Park',            'National Parks', 'kitulo-national-park',                   'Kitulo',                              3],
    ['MNP', 'Mahale Mountains National Park',  'National Parks', 'mahale-mountains-national-park',         'mahale-mountains-national-park',      4],
    ['LMN', 'Lake Manyara National Park',      'National Parks', 'lake-manyara-national-park',             'lake-manyara-national-park',          5],
    ['MKZ', 'Mkomazi National Park',           'National Parks', 'mkomazi-national-park',                  'mkomazi-national-park',               6],
    ['MKM', 'Mikumi National Park',            'National Parks', 'mikumi-national-park',                   'mikumi-national-park',                7],
    ['NCA', 'Ngorongoro Conservation Area',    'National Parks', 'ngorongoro-conservation-national-park',  'ngorongoro-conservation-area',        8],
    ['RNP', 'Ruaha National Park',             'National Parks', 'ruaha-national-park',                    'ruaha-national-park',                 9],
    ['RBN', 'Rubondo National Park',           'National Parks', 'rubondo-national-park',                  'rubondo',                            10],
    ['SND', 'Saadani National Park',           'National Parks', 'saadani-national-park',                  'saadani',                            11],
    ['SGR', 'Selous Game Reserve',             'National Parks', 'selous-game-reserve',                    'selous-game-reserve',                12],
    ['SNP', 'Serengeti National Park',         'National Parks', 'serengeti-national-park',                'serengeti-national-park',            13],
    ['TRP', 'Tarangire National Park',         'National Parks', 'tarangire-national-park',                'tarangire-national-park',            14],
    ['UNP', 'Udzungwa Mountains National Park','National Parks', 'udzungwa-mountains-national-park',       'udzungwa-mountains-national-park',   15],
    // ── TOWNS & OTHER DESTINATIONS ──────────────────────────────────────────
    ['ARU', 'Arusha',                          'Northern Circuit', 'arusha',                               'arusha',                             20],
    ['EYS', 'Lake Eyasi',                      'Northern Circuit', 'lake-eyasi',                           'lake-eyasi',                         21],
    ['NAT', 'Lake Natron',                     'Northern Circuit', 'lake-natron',                          'lake-natron',                        22],
    ['OLD', 'Olduvai Gorge',                   'Northern Circuit', 'olduvai-gorge',                        'olduvai-gorge',                      23],
    ['KLM', 'Mount Kilimanjaro',               'Northern Circuit', 'mount-kilimanjaro',                    'kilimanjaro',                        24],
    ['MRU', 'Mount Meru',                      'Northern Circuit', 'mount-meru',                           'mount-meru',                         25],
    ['ODL', 'Ol Doinyo Lengai',                'Northern Circuit', 'ol-doinyo-lengai',                     'ol-doinyo-lengai',                   26],
    ['ZNZ', 'Zanzibar',                        'Zanzibar',         'zanzibar/zanzibar',                    'zanzibar/zanzibar',                  30],
    ['PMB', 'Pemba Island',                    'Zanzibar',         'pemba-island',                         'pemba-island',                       31],
    ['MFA', 'Mafia Island',                    'Zanzibar',         'mafia-island',                         'mafia-island',                       32],
    ['PGN', 'Pangani',                         'Zanzibar',         'pangani',                              'pangani',                            33],
    ['FJV', 'Fanjove Island',                  'Zanzibar',         'fanjove-island',                       'fanjove-island',                     34],
    ['DAR', 'Dar es Salaam',                   'Other',            'dar-es-salaam',                        'dar-es-salaam',                      35],
];

$BASE_EN = 'https://www.savannahexplorers.net/tanzania/';
$BASE_IT = 'https://www.savannahexplorers.com/tanzania/';

// ─── HELPERS ─────────────────────────────────────────────────────────────────
function fetch_url(string $url): string {
    $ctx = stream_context_create(['http' => [
        'timeout'     => 15,
        'user_agent'  => 'Mozilla/5.0 (compatible; SavannahBot/1.0)',
        'ignore_errors' => true,
    ]]);
    $html = @file_get_contents($url, false, $ctx);
    return $html ?: '';
}

function parse_page(string $html, string $lang): array {
    if (!$html) return ['name' => '', 'subtitle' => '', 'description' => ''];

    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xp = new DOMXPath($dom);

    // Name from h1
    $h1 = $xp->query('//h1');
    $name = $h1->length ? trim($h1->item(0)->textContent) : '';

    // Subtitle: small text after h1 (the italic/small under the brand name)
    // On these pages it's a <p> directly under a specific div after h1
    $subtitle = '';
    $sub_nodes = $xp->query('//h1/following-sibling::p[1]');
    if ($sub_nodes->length) {
        $txt = trim($sub_nodes->item(0)->textContent);
        if (strlen($txt) < 150 && strpos($txt,'@') === false) $subtitle = $txt;
    }

    // Description: text under h2 "Description" (EN) or "Il Parco"/"Descrizione" (IT)
    $h2_targets = $lang === 'it'
        ? ['il parco', 'descrizione', 'description', 'il parco nazionale']
        : ['description', 'il parco'];

    $description = '';
    $h2nodes = $xp->query('//h2');
    foreach ($h2nodes as $h2) {
        $h2text = strtolower(trim($h2->textContent));
        foreach ($h2_targets as $target) {
            if (strpos($h2text, $target) === 0) {
                // Collect all sibling p/ul/li until next h2
                $sibling = $h2->nextSibling;
                $parts = [];
                while ($sibling) {
                    if ($sibling->nodeType === XML_ELEMENT_NODE && $sibling->nodeName === 'h2') break;
                    if ($sibling->nodeType === XML_ELEMENT_NODE) {
                        $t = trim($sibling->textContent);
                        if ($t) $parts[] = $t;
                    }
                    $sibling = $sibling->nextSibling;
                }
                $description = implode("\n\n", $parts);
                break 2;
            }
        }
    }

    // Fallback: first long paragraph if no h2 description found
    if (!$description) {
        $paras = $xp->query('//p');
        foreach ($paras as $p) {
            $txt = trim($p->textContent);
            if (strlen($txt) > 100) { $description = $txt; break; }
        }
    }

    return [
        'name'        => $name,
        'subtitle'    => $subtitle,
        'description' => $description,
    ];
}

// ─── RUN ─────────────────────────────────────────────────────────────────────
$dry_run = isset($_GET['dry']) && $_GET['dry'] === '1';
$results = [];
$ok = 0; $skip = 0; $err = 0;

foreach ($DESTINATIONS as $row) {
    [$code, $name_fb, $region, $slug_en, $slug_it, $sort] = $row;

    $url_en = $BASE_EN . $slug_en . '.html';
    $url_it = $BASE_IT . $slug_it . '.html';

    // Fetch EN
    $html_en = fetch_url($url_en);
    $en = parse_page($html_en, 'en');

    // Fetch IT
    $html_it = fetch_url($url_it);
    $it = parse_page($html_it, 'it');

    $name_en = $en['name'] ?: $name_fb;
    $name_it = $it['name'] ?: $name_en;
    $desc_en = $en['description'];
    $desc_it = $it['description'];
    $status  = $desc_en ? '✅' : '⚠️ no EN desc';

    $results[] = compact('code','name_en','name_it','region','desc_en','desc_it','url_en','url_it','status','sort');

    if (!$dry_run) {
        try {
            // Check if code already exists
            $chk = $db->prepare("SELECT id FROM iti_destinations WHERE code=?");
            $chk->execute([$code]);
            $existing_id = $chk->fetchColumn();

            if ($existing_id) {
                $db->prepare("UPDATE iti_destinations SET
                    name_en=?,name_it=?,
                    description_en=?,description_it=?,
                    region=?,sort_order=?
                    WHERE code=?")->execute([
                    $name_en, $name_it,
                    $desc_en, $desc_it,
                    $region, $sort, $code
                ]);
                $status .= ' (updated)';
            } else {
                $db->prepare("INSERT INTO iti_destinations
                    (code,name_en,name_it,name_fr,name_es,name_de,
                     description_en,description_it,
                     region,country,sort_order,is_active)
                    VALUES (?,?,?,?,?,?,?,?,?,'Tanzania',?,1)")->execute([
                    $code, $name_en, $name_it, $name_en, $name_en, $name_en,
                    $desc_en, $desc_it,
                    $region, $sort
                ]);
                $status .= ' (inserted)';
            }
            $ok++;
        } catch (Exception $e) {
            $status .= ' ❌ DB error: ' . $e->getMessage();
            $err++;
        }
    }
}

// ─── OUTPUT ──────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Import Destinations</title>
<style>
body{font-family:sans-serif;padding:24px;background:#f7f6f3;}
h1{color:#8b1010;}
table{width:100%;border-collapse:collapse;font-size:.82rem;background:#fff;}
th{background:#2c2c2a;color:#fff;padding:8px 10px;text-align:left;}
td{padding:8px 10px;border-bottom:1px solid #e8e6e0;vertical-align:top;}
tr:hover td{background:#fdf0f0;}
.desc{max-height:80px;overflow:hidden;color:#555;}
.ok{color:green;}
.warn{color:orange;}
.code{font-family:monospace;font-weight:700;}
.btn{display:inline-block;padding:8px 20px;background:#8b1010;color:#fff;border-radius:6px;text-decoration:none;margin:8px 4px;}
.btn-dry{background:#888;}
</style>
</head>
<body>
<h1>🗺️ Import Destinations — Savannah Explorers</h1>

<?php if ($dry_run): ?>
<p style="background:#fff3cd;padding:10px;border-radius:6px;">
  <strong>DRY RUN</strong> — nessun dato scritto nel DB. Controlla i risultati poi clicca Import per confermare.
</p>
<?php else: ?>
<p style="background:#d4edda;padding:10px;border-radius:6px;">
  <strong>IMPORT COMPLETATO</strong> — <?= $ok ?> destinazioni processate, <?= $err ?> errori.
</p>
<?php endif; ?>

<p>
  <a href="?dry=1" class="btn btn-dry">🔍 Dry run (preview)</a>
  <a href="?" class="btn" onclick="return confirm('Importare tutte le destinazioni nel DB?')">🚀 Import now</a>
  <a href="destinations.php" class="btn" style="background:#2c2c2a;">← Destinations</a>
</p>

<table>
<thead>
  <tr>
    <th>Code</th>
    <th>Name EN</th>
    <th>Name IT</th>
    <th>Region</th>
    <th>Description EN (preview)</th>
    <th>Status</th>
  </tr>
</thead>
<tbody>
<?php foreach ($results as $r): ?>
<tr>
  <td class="code"><?= htmlspecialchars($r['code']) ?></td>
  <td><a href="<?= htmlspecialchars($r['url_en']) ?>" target="_blank"><?= htmlspecialchars($r['name_en']) ?></a></td>
  <td><a href="<?= htmlspecialchars($r['url_it']) ?>" target="_blank"><?= htmlspecialchars($r['name_it']) ?></a></td>
  <td><?= htmlspecialchars($r['region']) ?></td>
  <td class="desc"><?= htmlspecialchars(mb_substr($r['desc_en'], 0, 200)) ?>…</td>
  <td class="<?= str_contains($r['status'],'✅')?'ok':'warn' ?>"><?= htmlspecialchars($r['status']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

</body>
</html>
