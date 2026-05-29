<?php
/**
 * iti_import_lodges_web.php
 * Static import of partner lodges — data hardcoded from official websites.
 * Sources: Wellworth, Karibu Camps, Elewana, Sopa, Planet Lodges, Asilia Africa
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db       = db();
$_cu      = current_user();
$can_edit = in_array($_cu['role_name'], ['admin', 'manager']);

// ── Destination id map (code => id) populated at runtime ─────────────────────
$dest_rows = iti_get_destinations(false);
$dest_by_code = [];
foreach ($dest_rows as $d) $dest_by_code[$d['code']] = $d['id'];
$dest_by_id   = [];
foreach ($dest_rows as $d) $dest_by_id[$d['id']] = $d['name_en'];
function did(string $code) { global $dest_by_code; return $dest_by_code[$code] ?? 0; }

// ── Existing lodge names (lowercase) for duplicate detection ─────────────────
$existing = [];
foreach ($db->query("SELECT LOWER(name) AS n FROM iti_lodges")->fetchAll() as $r)
    $existing[] = $r['n'];

// ── Lodge data ───────────────────────────────────────────────────────────────
// Each entry: dest_code, name, category, lodge_type, website, description_en
$LODGES = [

    // ── WELLWORTH COLLECTION ─────────────────────────────────────────────────
    [
        'group'       => 'Wellworth Collection',
        'dest_code'   => 'NCA',
        'name'        => 'Ngorongoro Oldeani Mountain Lodge',
        'category'    => 'luxury',
        'lodge_type'  => 'lodge',
        'website'     => 'https://wellworthcollection.co.tz/ngorongoro-oldeani/',
        'description_en' => 'A 5-star colonial lodge perched on a hilltop with 360-degree views of Oldeani Mountain, the Ngorongoro Crater Rim, Lake Eyasi, and Lake Manyara. Set on 40 acres of pristine gardens with over 130 bird species, the lodge features 50 deluxe rooms including a Livingstone Suite, a rim-flow pool, spa, fine dining, and conference facilities. Located 9 km from Ngorongoro Gate.',
    ],
    [
        'group'       => 'Wellworth Collection',
        'dest_code'   => 'TRP',
        'name'        => 'Tarangire Kuro Treetops Lodge',
        'category'    => 'luxury',
        'lodge_type'  => 'tented_camp',
        'website'     => 'https://wellworthcollection.co.tz/tarangire-kuro/',
        'description_en' => 'An eco-friendly luxury tented lodge with 25 tents perched along an ancient baobab tree line inside Tarangire National Park. Each tent features luxury bedding, en-suite bathroom with indoor/outdoor shower, and a private veranda. Solar-powered, with fine dining, a fully equipped bar, and day/night game drives. Located 8 km from Kuro Airstrip.',
    ],
    [
        'group'       => 'Wellworth Collection',
        'dest_code'   => 'LMN',
        'name'        => 'Lake Manyara Kilimamoja Lodge',
        'category'    => 'luxury',
        'lodge_type'  => 'lodge',
        'website'     => 'https://wellworthcollection.co.tz/lake-manyara-kilimamoja/',
        'description_en' => 'A luxury lodge set on the Great Rift Valley escarpment overlooking Lake Manyara National Park. Offers sweeping panoramic views of the lake and surrounding landscape, with comfortable rooms, fine dining, a pool, and easy access to game drives in one of Tanzania\'s most scenic parks.',
    ],
    [
        'group'       => 'Wellworth Collection',
        'dest_code'   => 'SNP',
        'name'        => 'Serengeti Lake Magadi Lodge',
        'category'    => 'luxury',
        'lodge_type'  => 'lodge',
        'website'     => 'https://wellworthcollection.co.tz/serengeti-lake-magadi/',
        'description_en' => 'A 5-star eco-friendly lodge in prime hilltop position inside Serengeti National Park, overlooking the game-filled plains towards Moru Kopjes and the alkaline Lake Magadi. Features 60 handcrafted suites with stone showers open to the sky, 24-hour solar power, spa, in-house gym, fine dining, and conference facilities.',
    ],
    [
        'group'       => 'Wellworth Collection',
        'dest_code'   => 'MKM',
        'name'        => 'Mikumi Wildlife Lodge',
        'category'    => 'mid',
        'lodge_type'  => 'lodge',
        'website'     => 'https://wellworthcollection.co.tz/mikumi-wildlife-lodge/',
        'description_en' => 'A comfortable lodge located inside Mikumi National Park offering direct access to one of Tanzania\'s most accessible wildlife areas. Features comfortable rooms, restaurant and bar, swimming pool, and guided game drives through the park\'s open floodplains teeming with elephant, buffalo, lion, and giraffe.',
    ],
    [
        'group'       => 'Wellworth Collection',
        'dest_code'   => 'ZNZB',
        'name'        => 'Wellworth Zanzibar Beach Resort',
        'category'    => 'luxury',
        'lodge_type'  => 'hotel',
        'website'     => 'https://wellworthcollection.co.tz/zanzibar-beach/',
        'description_en' => 'A luxury beach resort on the shores of Zanzibar featuring white sand beaches, tropical gardens, and elegant accommodation. Offers water sports, spa, multiple dining options, and easy access to Stone Town and the island\'s spice plantations.',
    ],
    [
        'group'       => 'Wellworth Collection',
        'dest_code'   => 'KGT',
        'name'        => 'Ole Serai Kogatende',
        'category'    => 'luxury',
        'lodge_type'  => 'tented_camp',
        'website'     => 'https://wellworthcollection.co.tz/ole-serai-kogatende/',
        'description_en' => 'A luxury tented camp in the remote northern Serengeti near Kogatende, positioned in prime territory for witnessing the dramatic river crossings of the Great Wildebeest Migration. Offers spacious luxury tents, fine dining, hot air balloon safaris, and expert-guided game drives.',
    ],
    [
        'group'       => 'Wellworth Collection',
        'dest_code'   => 'SNP',
        'name'        => 'Ole Serai Seronera',
        'category'    => 'luxury',
        'lodge_type'  => 'tented_camp',
        'website'     => 'https://wellworthcollection.co.tz/ole-serai-seronera/',
        'description_en' => 'A luxury tented camp in the heart of Serengeti National Park near Seronera, centrally located for year-round wildlife viewing. The Seronera area is famous for its resident leopards, lions, and cheetahs. Offers fine dining, a full bar, and expert-guided game drives departing directly from camp.',
    ],
    [
        'group'       => 'Wellworth Collection',
        'dest_code'   => 'SNP',
        'name'        => 'Ole Serai Moru Kopjes',
        'category'    => 'luxury',
        'lodge_type'  => 'tented_camp',
        'website'     => 'https://wellworthcollection.co.tz/ole-serai-moru-kopjes/',
        'description_en' => 'A luxury tented camp set among the ancient granite kopjes of the southern Serengeti. The Moru Kopjes are home to black rhino, lion prides, and rock paintings. Features spacious tents, fine dining, guided walking safaris, and excellent big cat sightings in a remote, atmospheric setting.',
    ],
    [
        'group'       => 'Wellworth Collection',
        'dest_code'   => 'SNP',
        'name'        => 'Ole Serai Turner Springs',
        'category'    => 'luxury',
        'lodge_type'  => 'tented_camp',
        'website'     => 'https://wellworthcollection.co.tz/ole-serai-turner-springs/',
        'description_en' => 'A luxury tented camp in the western Serengeti near Turner Springs, offering exclusive access to a remote area of the park with exceptional predator sightings and diverse birdlife. Features luxury tents, gourmet dining, guided game drives, and sundowner experiences on the open plains.',
    ],

    // ── KARIBU CAMPS & LODGES ────────────────────────────────────────────────
    [
        'group'       => 'Karibu Camps & Lodges',
        'dest_code'   => 'NCA',
        'name'        => "Ngorongoro Lion's Paw",
        'category'    => 'luxury',
        'lodge_type'  => 'lodge',
        'website'     => 'https://karibucamps.com/lions-paw/',
        'description_en' => 'A luxury lodge on the eastern rim of the Ngorongoro Crater with explicit views of Lake Magadi and the caldera below. Located 10 minutes from the crater entrance, guests can spot tusked elephants and black rhinos from the bar and lounge. Offers bush dinners, guided crater descents, and an intimate atmosphere in one of Africa\'s most iconic landscapes.',
    ],
    [
        'group'       => 'Karibu Camps & Lodges',
        'dest_code'   => 'SNP',
        'name'        => 'Serengeti Woodlands Camp',
        'category'    => 'luxury',
        'lodge_type'  => 'tented_camp',
        'website'     => 'https://karibucamps.com/woodlands/',
        'description_en' => 'A luxury permanent tented camp nestled in the woodlands of the Serengeti, offering an intimate and immersive safari experience. The camp is positioned for excellent wildlife viewing throughout the year, with expert guides, gourmet cuisine, and a warm, personal atmosphere that captures the essence of classic East African safari.',
    ],
    [
        'group'       => 'Karibu Camps & Lodges',
        'dest_code'   => 'SNP',
        'name'        => 'Serengeti Sametu Camp',
        'category'    => 'luxury',
        'lodge_type'  => 'tented_camp',
        'website'     => 'https://karibucamps.com/sametu-camp/',
        'description_en' => 'A luxury tented camp in the central Serengeti offering undisturbed serenity and prime wildlife viewing. Sametu Camp combines spacious luxury tents with expert-led game drives, bush walks, and sundowner experiences. Positioned for year-round big cat sightings in one of the Serengeti\'s most productive game zones.',
    ],
    [
        'group'       => 'Karibu Camps & Lodges',
        'dest_code'   => 'SNP',
        'name'        => 'Serengeti Mara River Camp',
        'category'    => 'luxury',
        'lodge_type'  => 'tented_camp',
        'website'     => 'https://karibucamps.com/river-camp/',
        'description_en' => 'A luxury tented camp on the banks of the Mara River in the northern Serengeti, strategically positioned to witness the dramatic wildebeest river crossings during the Great Migration. Features spacious tents with river views, fine dining, and expert guides who know the best crossing points.',
    ],
    [
        'group'       => 'Karibu Camps & Lodges',
        'dest_code'   => 'TRP',
        'name'        => 'Tarangire Elephant Springs',
        'category'    => 'luxury',
        'lodge_type'  => 'tented_camp',
        'website'     => 'https://karibucamps.com/elephant-springs/',
        'description_en' => 'A new luxury camp nestled in the heart of Tarangire where towering baobabs line the banks of the Tarangire River. Suites are designed to blend into the natural setting with stone architecture under open skies. Elephants regularly stroll past the camp, and the river and savannah bustle with wildlife. Offers an understated, elegant retreat connected deeply to the wild.',
    ],

    // ── ELEWANA COLLECTION (Tanzania only) ──────────────────────────────────
    [
        'group'       => 'Elewana Collection',
        'dest_code'   => 'ARU',
        'name'        => 'Elewana Arusha Coffee Lodge',
        'category'    => 'luxury',
        'lodge_type'  => 'lodge',
        'website'     => 'https://www.elewanacollection.com/arusha-coffee-lodge/at-a-glance',
        'description_en' => 'A boutique luxury lodge set on a working coffee estate on the slopes of Mount Meru, minutes from Arusha town. The lodge features elegant cottages surrounded by coffee trees, an award-winning farm-to-table restaurant, a spa, and beautifully manicured gardens. The ideal start or end point for a Northern Circuit safari.',
    ],
    [
        'group'       => 'Elewana Collection',
        'dest_code'   => 'TRP',
        'name'        => 'Elewana Tarangire Treetops',
        'category'    => 'ultra_luxury',
        'lodge_type'  => 'tented_camp',
        'website'     => 'https://www.elewanacollection.com/tarangire-treetops/at-a-glance',
        'description_en' => 'An iconic ultra-luxury camp in Tarangire National Park where 20 enormous treehouses are built among giant baobab and marula trees. Each treehouse blends seamlessly into the forest canopy with open-air baths and sweeping savannah views. The camp offers the highest level of personalised service, guided walks, and extraordinary night-sky stargazing.',
    ],
    [
        'group'       => 'Elewana Collection',
        'dest_code'   => 'NCA',
        'name'        => 'Elewana The Manor at Ngorongoro',
        'category'    => 'ultra_luxury',
        'lodge_type'  => 'house',
        'website'     => 'https://www.elewanacollection.com/the-manor-at-ngorongoro/at-a-glance',
        'description_en' => 'A gracious colonial manor house on a coffee and wheat farm on the slopes above the Ngorongoro Conservation Area. The Manor evokes the atmosphere of old East Africa with antique furnishings, log fires, and warm hospitality. Features 18 suites, fine dining, a billiard room, and guided excursions into the Crater and surrounding wilderness.',
    ],
    [
        'group'       => 'Elewana Collection',
        'dest_code'   => 'SNP',
        'name'        => 'Elewana Serengeti Pioneer Camp',
        'category'    => 'ultra_luxury',
        'lodge_type'  => 'tented_camp',
        'website'     => 'https://www.elewanacollection.com/serengeti-pioneer-camp/at-a-glance',
        'description_en' => 'An intimate ultra-luxury camp in the central Serengeti evoking the golden era of East African exploration. Just 9 classic canvas tents under thatch, all with four-poster beds, copper baths, and en-suite bathrooms. Offers exceptional personalised guiding, Maasai village walks, and bush breakfasts in one of the Serengeti\'s most wildlife-rich zones.',
    ],
    [
        'group'       => 'Elewana Collection',
        'dest_code'   => 'SNP',
        'name'        => 'Elewana Serengeti Migration Camp',
        'category'    => 'ultra_luxury',
        'lodge_type'  => 'tented_camp',
        'website'     => 'https://www.elewanacollection.com/serengeti-migration-camp/at-a-glance',
        'description_en' => 'A luxury semi-permanent camp in the northern Serengeti following the Great Migration, positioned on a ridge above the Grumeti River valley. Features 20 spacious tents on raised wooden platforms with panoramic views, a pool, and expert guides specialising in tracking the wildebeest migration. Partially mobile, moving to follow the herds.',
    ],
    [
        'group'       => 'Elewana Collection',
        'dest_code'   => 'ZNZB',
        'name'        => 'Elewana Kilindi Zanzibar',
        'category'    => 'ultra_luxury',
        'lodge_type'  => 'hotel',
        'website'     => 'https://www.kilindizanzibar.com/',
        'description_en' => 'An ultra-luxury boutique retreat on the unspoiled northwest coast of Zanzibar, set in 50 acres of tropical gardens fronting a pristine private beach. Features 15 spacious pavilions with private plunge pools, an award-winning restaurant, a spa, and a range of water sports and island excursions. One of Zanzibar\'s most exclusive addresses.',
    ],

    // ── SOPA LODGES (Tanzania only) ──────────────────────────────────────────
    [
        'group'       => 'Sopa Lodges',
        'dest_code'   => 'TRP',
        'name'        => 'Tarangire Sopa Lodge',
        'category'    => 'mid',
        'lodge_type'  => 'lodge',
        'website'     => 'https://www.sopalodges.com/tarangire-sopa-lodge/the-lodge',
        'description_en' => 'A well-established lodge hidden among ancient baobab trees and kopjes inside Tarangire National Park, home to the greatest concentration of elephants in Africa. Features comfortable rooms with private verandas, a swimming pool, restaurant, bar, and guided game drives. Located 129 km from Arusha, with a 20-minute flight connection from Arusha Airport.',
    ],

    // ── PLANET LODGES & LAIRS CAMPS ──────────────────────────────────────────
    [
        'group'       => 'Planet Lodges',
        'dest_code'   => 'JRO',
        'name'        => 'Airport Planet Lodge',
        'category'    => 'mid',
        'lodge_type'  => 'hotel',
        'website'     => 'https://planet-lodges.com/lodges/airport-planet-lodge/',
        'description_en' => 'A 3-star lodge ideally situated between Moshi and Arusha, just 12 minutes from Kilimanjaro International Airport. Features African-style chalets in tropical gardens rich with birdlife, a restaurant, bar, swimming pool, and spa. The perfect overnight stop before or after a safari or Kilimanjaro climb.',
    ],
    [
        'group'       => 'Planet Lodges',
        'dest_code'   => 'ARU',
        'name'        => 'Arusha Planet Lodge',
        'category'    => 'mid',
        'lodge_type'  => 'lodge',
        'website'     => 'https://planet-lodges.com/lodges/arusha-planet-lodge/',
        'description_en' => 'A comfortable 3-4 star lodge in Arusha offering a relaxed base for safari departures and returns. Set in lush gardens with a swimming pool, restaurant, bar, and spa. Well-located for exploring the Northern Circuit parks and for acclimatisation before a Kilimanjaro expedition.',
    ],
    [
        'group'       => 'Planet Lodges',
        'dest_code'   => 'SNP',
        'name'        => 'Elephants Lair Camp',
        'category'    => 'mid',
        'lodge_type'  => 'tented_camp',
        'website'     => 'https://planet-lodges.com/elephants-lair-camp/',
        'description_en' => 'A tented camp in the Serengeti offering an authentic safari experience at accessible rates. Positioned for quality game viewing, the camp features comfortable furnished tents, meals, and guided game drives through the Serengeti ecosystem. An affordable alternative for travellers seeking the classic Serengeti experience.',
    ],
    [
        'group'       => 'Planet Lodges',
        'dest_code'   => 'SNP',
        'name'        => 'Gnus Lair Camp',
        'category'    => 'mid',
        'lodge_type'  => 'tented_camp',
        'website'     => 'https://planet-lodges.com/gnus-lair/',
        'description_en' => 'A tented camp in the Serengeti ideally positioned for witnessing the Great Wildebeest Migration. Features comfortable tents, guided game drives, and a relaxed atmosphere perfect for following the gnu herds across the Serengeti plains.',
    ],
    [
        'group'       => 'Planet Lodges',
        'dest_code'   => 'SNP',
        'name'        => "Jackals Lair Camp",
        'category'    => 'mid',
        'lodge_type'  => 'tented_camp',
        'website'     => 'https://planet-lodges.com/lodges/jackals-lair-camp/',
        'description_en' => 'A tented camp in the Serengeti offering comfortable accommodation and guided game drives at competitive rates. Set in a good location for wildlife viewing, with particular opportunities for spotting the smaller predators and resident wildlife of the Serengeti ecosystem.',
    ],

    // ── ASILIA AFRICA (Tanzania) ─────────────────────────────────────────────
    [
        'group'       => 'Asilia Africa',
        'dest_code'   => 'SNP',
        'name'        => 'Dunia Camp',
        'category'    => 'luxury',
        'lodge_type'  => 'tented_camp',
        'website'     => 'https://www.asiliaafrica.com/our-camps-lodges/dunia-camp/',
        'description_en' => 'An intimate luxury tented camp in the heart of the Serengeti, with just 8 spacious tents offering panoramic views over the plains. Dunia is celebrated for outstanding year-round wildlife — resident lions, leopards, cheetahs, and elephants — and for its exceptional personalised guiding. The camp offers fly-camping extensions and authentic bush immersion.',
    ],
    [
        'group'       => 'Asilia Africa',
        'dest_code'   => 'KGT',
        'name'        => 'Sayari Camp',
        'category'    => 'luxury',
        'lodge_type'  => 'tented_camp',
        'website'     => 'https://www.asiliaafrica.com/our-camps-lodges/sayari-camp/',
        'description_en' => 'A flagship luxury camp in the far north of the Serengeti near Kogatende, renowned as one of the best positions for witnessing the Great Migration river crossings. Features 15 spacious tents on raised platforms with sweeping views, a pool, top-rated cuisine, and guides with deep knowledge of the northern ecosystem.',
    ],
    [
        'group'       => 'Asilia Africa',
        'dest_code'   => 'RNP',
        'name'        => 'Jongomero Camp',
        'category'    => 'ultra_luxury',
        'lodge_type'  => 'tented_camp',
        'website'     => 'https://www.asiliaafrica.com/our-camps-lodges/jongomero/',
        'description_en' => 'An ultra-exclusive remote camp in the southern Ruaha National Park, one of Africa\'s largest and least-visited wilderness areas. Jongomero offers just 8 spacious tents beside the seasonal Jongomero River, with outstanding predator and elephant sightings. Features a pool, gourmet bush dining, fly-camping, and walking safaris in genuine off-the-beaten-track Africa.',
    ],
    [
        'group'       => 'Asilia Africa',
        'dest_code'   => 'SNP',
        'name'        => 'Ubuntu Migration Camp',
        'category'    => 'luxury',
        'lodge_type'  => 'mobile_camp',
        'website'     => 'https://www.asiliaafrica.com/our-camps-lodges/ubuntu-migration-camp/',
        'description_en' => 'A semi-permanent luxury mobile camp that follows the Great Migration across the Serengeti throughout the year, ensuring guests are always in the heart of the action. Features comfortable canvas tents with proper beds and en-suite bathrooms, expert migration guides, and an adventurous atmosphere that recalls the pioneering days of East African safari.',
    ],
    [
        'group'       => 'Asilia Africa',
        'dest_code'   => 'NDU',
        'name'        => 'Namiri Plains Camp',
        'category'    => 'luxury',
        'lodge_type'  => 'tented_camp',
        'website'     => 'https://www.asiliaafrica.com/our-camps-lodges/namiri-plains/',
        'description_en' => 'A luxury camp in the exclusive Namiri Plains concession in the eastern Serengeti, an area famous for the highest density of cheetahs in East Africa. Features 8 tents positioned on a rocky ridge with sweeping grassland views, exceptional big cat guiding, and access to a private concession with no day-trippers.',
    ],
];

// ── Add duplicate flag ────────────────────────────────────────────────────────
foreach ($LODGES as &$l) {
    $l['dest_id']      = did($l['dest_code']);
    $l['is_duplicate'] = in_array(strtolower($l['name']), $existing);
}
unset($l);

// ── Group by partner ─────────────────────────────────────────────────────────
$by_group = [];
foreach ($LODGES as $i => $l) {
    $l['_idx'] = $i;
    $by_group[$l['group']][] = $l;
}

// ── POST: import ─────────────────────────────────────────────────────────────
$import_log  = [];
$import_done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $stmt = $db->prepare(
        'INSERT INTO iti_lodges
         (destination_id,name,category,lodge_type,website,description_en,is_active)
         VALUES (?,?,?,?,?,?,1)'
    );
    $ok = $skip = $err = 0;
    foreach ($LODGES as $i => $l) {
        if (isset($_POST["skip_{$i}"])) { $skip++; continue; }
        $dest_id = (int)($_POST["dest_{$i}"] ?? $l['dest_id']);
        if (!$dest_id) {
            $import_log[] = "⚠ No destination — skipped: {$l['name']}";
            $skip++; continue;
        }
        if (in_array(strtolower($l['name']), $existing)) {
            $import_log[] = "⏭ Already exists — skipped: {$l['name']}";
            $skip++; continue;
        }
        try {
            $cat  = $_POST["cat_{$i}"]  ?? $l['category'];
            $type = $_POST["type_{$i}"] ?? $l['lodge_type'];
            $stmt->execute([$dest_id, $l['name'], $cat, $type, $l['website'], $l['description_en']]);
            $existing[]   = strtolower($l['name']);
            $import_log[] = "✅ Imported: {$l['name']} ({$dest_by_id[$dest_id]})";
            $ok++;
        } catch (Exception $e) {
            $import_log[] = "❌ Error — {$l['name']}: " . $e->getMessage();
            $err++;
        }
    }
    $import_log[] = "─── Done: {$ok} imported, {$skip} skipped, {$err} errors ───";
    $import_done  = true;
}

// ── Page ─────────────────────────────────────────────────────────────────────
$page_title = 'Import Partner Lodges';
$extra_css  = iti_extra_css() . '
.group-header{background:var(--green);color:#fff;padding:8px 14px;font-size:.75rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;border-radius:6px 6px 0 0;margin-top:20px;}
.import-table{width:100%;border-collapse:collapse;background:#fff;font-size:.78rem;border:1px solid var(--grey-lt);}
.import-table th{background:#f0f0ef;padding:7px 10px;text-align:left;font-size:.71rem;white-space:nowrap;border-bottom:1.5px solid var(--grey-lt);}
.import-table td{padding:8px 10px;border-bottom:1px solid #f0f0ef;vertical-align:middle;}
.import-table tr.dup td{background:#fffbeb;}
.badge-dup{display:inline-block;padding:1px 7px;border-radius:4px;background:#FEF3C7;color:#92400E;font-size:.65rem;font-weight:700;margin-left:6px;vertical-align:middle;}
.badge-nodest{background:#fee2e2;color:#991b1b;}
.log-box{background:#1a1a1a;color:#a8e6a3;font-family:monospace;font-size:.75rem;padding:14px 16px;border-radius:8px;max-height:220px;overflow-y:auto;white-space:pre-wrap;margin:12px 0;}
';
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
<?php iti_nav('Import Partner Lodges'); ?>
<?php iti_flash_render(); ?>

<div class="page-header">
  <div>
    <h2>🏕️ Import Partner Lodges</h2>
    <div class="sub">Master Data › Lodges › Partner Import — <?= count($LODGES) ?> lodges from <?= count($by_group) ?> partners</div>
  </div>
  <a href="lodges.php" class="btn btn-outline btn-sm">← Back to Lodges</a>
</div>

<?php if ($import_done): ?>
<div class="form-card">
  <div class="form-section-title">Import Result</div>
  <div class="log-box"><?= implode("\n", array_map('htmlspecialchars', $import_log)) ?></div>
  <div style="margin-top:16px;display:flex;gap:10px;">
    <a href="lodges.php" class="btn btn-red">→ View All Lodges</a>
    <a href="iti_import_lodges_web.php" class="btn btn-outline">↩ Import again</a>
  </div>
</div>

<?php else: ?>

<form method="POST" action="iti_import_lodges_web.php">

<div class="form-card">
  <div class="form-section-title">Review &amp; Import</div>
  <p style="font-size:.8rem;color:var(--grey-mid);margin-bottom:4px;">
    Review each lodge below. Adjust destination, category, or type if needed.
    Check <strong>Skip</strong> to exclude a lodge. Rows marked <span class="badge-dup">EXISTS</span> are already in the database and are pre-checked for skip.
    Rows marked <span class="badge-dup badge-nodest">NO DEST</span> have no destination mapped — assign one or they will be skipped.
  </p>
  <p style="font-size:.78rem;color:var(--grey-mid);margin-bottom:16px;">
    <strong><?= count(array_filter($LODGES, fn($l) => !$l['is_duplicate'])) ?></strong> new lodges ready to import
    &nbsp;·&nbsp; <strong><?= count(array_filter($LODGES, fn($l) => $l['is_duplicate'])) ?></strong> already in database
  </p>

  <?php foreach ($by_group as $group => $lodges): ?>
  <div class="group-header"><?= h($group) ?> &nbsp;<span style="font-weight:400;opacity:.8"><?= count($lodges) ?> lodges</span></div>
  <table class="import-table" style="margin-bottom:0;border-radius:0 0 6px 6px;">
    <thead>
      <tr>
        <th style="width:36px;text-align:center;">Skip</th>
        <th>Lodge Name</th>
        <th style="min-width:180px;">Destination</th>
        <th>Category</th>
        <th>Type</th>
        <th style="max-width:320px;">Description</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($lodges as $l):
      $i      = $l['_idx'];
      $is_dup = $l['is_duplicate'];
      $no_dst = !$l['dest_id'];
    ?>
    <tr class="<?= $is_dup ? 'dup' : '' ?>">
      <td style="text-align:center;">
        <input type="checkbox" name="skip_<?= $i ?>" value="1" <?= $is_dup ? 'checked' : '' ?>>
      </td>
      <td>
        <strong><?= h($l['name']) ?></strong>
        <?php if ($is_dup): ?><span class="badge-dup">EXISTS</span><?php endif; ?>
        <?php if ($no_dst && !$is_dup): ?><span class="badge-dup badge-nodest">NO DEST</span><?php endif; ?>
        <div style="font-size:.68rem;color:var(--grey-mid);margin-top:2px;">
          <a href="<?= h($l['website']) ?>" target="_blank" rel="noopener"><?= h($l['website']) ?></a>
        </div>
      </td>
      <td>
        <select name="dest_<?= $i ?>" style="font-size:.78rem;">
          <option value="">— Select —</option>
          <?php foreach ($dest_by_id as $did => $dname):
            $sel = ($did == $l['dest_id']) ? ' selected' : '';
          ?>
          <option value="<?= $did ?>"<?= $sel ?>><?= h($dname) ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td>
        <select name="cat_<?= $i ?>" style="font-size:.78rem;">
          <?php foreach (['budget'=>'Budget','mid'=>'Mid-range','luxury'=>'Luxury','ultra_luxury'=>'Ultra Luxury'] as $cv=>$cl): ?>
          <option value="<?= $cv ?>" <?= $l['category']===$cv?'selected':'' ?>><?= $cl ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td>
        <select name="type_<?= $i ?>" style="font-size:.78rem;">
          <?php foreach (['lodge'=>'Lodge','tented_camp'=>'Tented Camp','hotel'=>'Hotel','mobile_camp'=>'Mobile Camp','house'=>'House'] as $tv=>$tl): ?>
          <option value="<?= $tv ?>" <?= $l['lodge_type']===$tv?'selected':'' ?>><?= $tl ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td style="font-size:.71rem;color:#555;max-width:320px;">
        <?= h(mb_substr($l['description_en'], 0, 160)) ?>…
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endforeach; ?>

  <?php if ($can_edit): ?>
  <div style="margin-top:20px;display:flex;gap:10px;align-items:center;">
    <button type="submit" class="btn btn-red">⬆ Import Selected Lodges</button>
    <a href="lodges.php" class="btn btn-outline">Cancel</a>
    <span style="margin-left:auto;font-size:.75rem;color:var(--grey-mid);">
      Unchecked rows will be imported. Rows marked EXISTS are pre-checked and will be skipped.
    </span>
  </div>
  <?php else: ?>
  <p style="color:var(--grey-mid);margin-top:16px;">You need admin or manager role to import lodges.</p>
  <?php endif; ?>
</div>

</form>
<?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
