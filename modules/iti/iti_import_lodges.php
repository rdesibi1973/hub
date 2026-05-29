<?php
/**
 * iti_import_lodges.php
 * Import The Orangi Collection — 4 lodges
 * Data scraped from theorangicollection.com
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db = db();

// ─── LODGE DATA ───────────────────────────────────────────────────────────────
// destination_code, name, category, lodge_type, website, desc_en, desc_it, desc_fr, desc_es, desc_de
$LODGES = [

    [
        'dest_code'  => 'SNP',
        'name'       => 'Serengeti Orangi River Luxury Lodge',
        'category'   => 'luxury',
        'lodge_type' => 'lodge',
        'website'    => 'https://orangiluxurylodge.com',
        'desc_en'    => 'Located inside Serengeti National Park atop a hill overlooking the Orangi River, this luxury lodge offers 15 spacious safari rooms with private verandas and panoramic savannah views. Each room features an en-suite bathroom with indoor and outdoor shower. Facilities include a lounge, restaurant and bar, swimming pool and spa. Approximately 45 minutes drive from Seronera airstrip.',
        'desc_it'    => 'Situato all\'interno del Parco Nazionale del Serengeti, in cima a una collina affacciata sul fiume Orangi, questo lodge di lusso offre 15 spaziose camere safari con veranda privata e vista panoramica sulla savana. Ogni camera dispone di bagno privato con doccia interna ed esterna. Le strutture includono lounge, ristorante e bar, piscina e spa. A circa 45 minuti di guida dalla pista di atterraggio di Seronera.',
        'desc_fr'    => 'Situé à l\'intérieur du Parc National du Serengeti, au sommet d\'une colline surplombant la rivière Orangi, ce lodge de luxe propose 15 chambres safari spacieuses avec vérandas privées et vues panoramiques sur la savane. Chaque chambre dispose d\'une salle de bains privative avec douche intérieure et extérieure. Les installations comprennent un salon, un restaurant et un bar, une piscine et un spa. À environ 45 minutes en voiture de la piste de Seronera.',
        'desc_es'    => 'Ubicado dentro del Parque Nacional del Serengeti, en lo alto de una colina con vistas al río Orangi, este lujoso lodge ofrece 15 amplias habitaciones safari con terrazas privadas y vistas panorámicas a la sabana. Cada habitación cuenta con baño privado con ducha interior y exterior. Las instalaciones incluyen salón, restaurante y bar, piscina y spa. A aproximadamente 45 minutos en coche de la pista de aterrizaje de Seronera.',
        'desc_de'    => 'Innerhalb des Serengeti-Nationalparks auf einem Hügel mit Blick auf den Orangi-Fluss gelegen, bietet diese Luxuslodge 15 geräumige Safarizimmer mit privaten Veranden und Panoramablick auf die Savanne. Jedes Zimmer verfügt über ein eigenes Bad mit Innen- und Außendusche. Zu den Einrichtungen gehören Lounge, Restaurant und Bar, Schwimmbad und Spa. Ca. 45 Fahrminuten vom Seronera Airstrip entfernt.',
    ],

    [
        'dest_code'  => 'KAR',
        'name'       => 'Ngorongoro Marera Mountain View Lodge',
        'category'   => 'luxury',
        'lodge_type' => 'lodge',
        'website'    => 'https://mareraviewlodge.com',
        'desc_en'    => 'Located next to Ngorongoro Conservation Area in the lush Rhotia Valley near Karatu, this lodge offers 24 luxury rooms including double and family rooms arranged in separate cottages with veranda, fireplace, double wash basin, shower and bathtub. The restaurant and bar enjoy breathtaking views of Rhotia Valley, serving local, Italian and international cuisine. Facilities include swimming pool and spa. Approximately 30 minutes drive from Ngorongoro crater gate.',
        'desc_it'    => 'Situato accanto all\'Area di Conservazione del Ngorongoro nella lussureggiante Valle Rhotia vicino a Karatu, questo lodge offre 24 camere di lusso tra doppie e familiari, distribuite in cottage separati con veranda, camino, doppio lavabo, doccia e vasca da bagno. Il ristorante e il bar godono di una vista mozzafiato sulla Valle Rhotia e servono cucina locale, italiana e internazionale. Le strutture includono piscina e spa. A circa 30 minuti di guida dal cancello del cratere del Ngorongoro.',
        'desc_fr'    => 'Situé à côté de la Zone de Conservation du Ngorongoro dans la luxuriante Vallée de Rhotia près de Karatu, ce lodge propose 24 chambres de luxe dont des chambres doubles et familiales réparties dans des cottages séparés avec véranda, cheminée, double lavabo, douche et baignoire. Le restaurant et le bar bénéficient d\'une vue imprenable sur la Vallée de Rhotia et servent une cuisine locale, italienne et internationale. Les installations comprennent une piscine et un spa. À environ 30 minutes en voiture du portail du cratère du Ngorongoro.',
        'desc_es'    => 'Ubicado junto al Área de Conservación del Ngorongoro en el exuberante Valle Rhotia cerca de Karatu, este lodge ofrece 24 habitaciones de lujo entre dobles y familiares distribuidas en cottages separados con terraza, chimenea, lavabo doble, ducha y bañera. El restaurante y el bar disfrutan de impresionantes vistas al Valle Rhotia y sirven cocina local, italiana e internacional. Las instalaciones incluyen piscina y spa. A aproximadamente 30 minutos en coche de la puerta del cráter del Ngorongoro.',
        'desc_de'    => 'Neben dem Ngorongoro-Schutzgebiet im üppigen Rhotia-Tal in der Nähe von Karatu gelegen, bietet diese Lodge 24 Luxuszimmer darunter Doppel- und Familienzimmer in separaten Cottages mit Veranda, Kamin, Doppelwaschbecken, Dusche und Badewanne. Restaurant und Bar genießen einen atemberaubenden Blick auf das Rhotia-Tal und servieren lokale, italienische und internationale Küche. Zu den Einrichtungen gehören Schwimmbad und Spa. Ca. 30 Fahrminuten vom Eingang des Ngorongoro-Kraters.',
    ],

    [
        'dest_code'  => 'SNP',
        'name'       => 'Serengeti Kifaru Tented Lodge',
        'category'   => 'luxury',
        'lodge_type' => 'tented_camp',
        'website'    => 'https://kifarutentedlodge.com',
        'desc_en'    => 'Located inside Serengeti National Park with spectacular views of the surrounding savannah, Kifaru Tented Lodge offers 13 luxury safari tents plus 2 family tents, all set on raised platforms with private verandas. Each tent features spacious interiors with en-suite bathrooms, 24/7 power and stunning Serengeti views. The restaurant blends local flavours with international refinement. Approximately 45 minutes drive from Seronera airstrip.',
        'desc_it'    => 'Situato all\'interno del Parco Nazionale del Serengeti con una vista spettacolare sulla savana circostante, Kifaru Tented Lodge offre 13 tende safari di lusso più 2 tende familiari, tutte su piattaforme rialzate con veranda privata. Ogni tenda dispone di interni spaziosi con bagno privato, corrente 24 ore su 24 e una splendida vista sul Serengeti. Il ristorante unisce i sapori locali alla raffinatezza internazionale. A circa 45 minuti di guida dalla pista di atterraggio di Seronera.',
        'desc_fr'    => 'Situé à l\'intérieur du Parc National du Serengeti avec des vues spectaculaires sur la savane environnante, Kifaru Tented Lodge propose 13 tentes safari de luxe plus 2 tentes familiales, toutes sur des plateformes surélevées avec vérandas privées. Chaque tente dispose d\'intérieurs spacieux avec salle de bains privative, électricité 24h/24 et de magnifiques vues sur le Serengeti. Le restaurant allie les saveurs locales à la finesse internationale. À environ 45 minutes en voiture de la piste de Seronera.',
        'desc_es'    => 'Ubicado dentro del Parque Nacional del Serengeti con espectaculares vistas a la sabana circundante, Kifaru Tented Lodge ofrece 13 tiendas safari de lujo más 2 tiendas familiares, todas sobre plataformas elevadas con terrazas privadas. Cada tienda cuenta con amplios interiores con baño privado, electricidad 24 horas y magníficas vistas al Serengeti. El restaurante combina los sabores locales con la refinada cocina internacional. A aproximadamente 45 minutos en coche de la pista de Seronera.',
        'desc_de'    => 'Innerhalb des Serengeti-Nationalparks mit spektakulärem Blick auf die umliegende Savanne gelegen, bietet Kifaru Tented Lodge 13 Luxus-Safarizelte und 2 Familienzelte, alle auf erhöhten Plattformen mit privaten Veranden. Jedes Zelt verfügt über geräumige Innenräume mit eigenem Bad, 24/7-Strom und atemberaubenden Serengeti-Ausblicken. Das Restaurant verbindet lokale Aromen mit internationaler Raffinesse. Ca. 45 Fahrminuten vom Seronera Airstrip entfernt.',
    ],

    [
        'dest_code'  => 'ARU',
        'name'       => 'Arusha Explorers Lodge',
        'category'   => 'luxury',
        'lodge_type' => 'lodge',
        'website'    => 'https://arushaexplorerslodge.com',
        'desc_en'    => 'Located on the slopes of Mount Meru outside Arusha town, Arusha Explorers Lodge offers 8 luxury rooms with private verandas overlooking lush banana plantations. Each room features a spacious interior with private en-suite bathroom. Facilities include a lounge, restaurant and bar, and a swimming pool. Approximately 1 hour drive from Kilimanjaro International Airport. The perfect base for exploring the Northern Circuit national parks.',
        'desc_it'    => 'Situato sulle pendici del Monte Meru fuori dalla città di Arusha, l\'Arusha Explorers Lodge offre 8 camere di lusso con veranda privata affacciata su lussureggianti piantagioni di banane. Ogni camera è dotata di interni spaziosi con bagno privato. Le strutture includono lounge, ristorante e bar e una piscina. A circa 1 ora di guida dall\'aeroporto internazionale del Kilimanjaro. La base perfetta per esplorare i parchi nazionali del Circuito Settentrionale.',
        'desc_fr'    => 'Situé sur les pentes du Mont Méru en dehors de la ville d\'Arusha, l\'Arusha Explorers Lodge propose 8 chambres de luxe avec vérandas privées surplombant de luxuriantes plantations de bananiers. Chaque chambre dispose d\'un intérieur spacieux avec salle de bains privative. Les installations comprennent un salon, un restaurant et un bar, ainsi qu\'une piscine. À environ 1 heure en voiture de l\'aéroport international du Kilimandjaro. La base idéale pour explorer les parcs nationaux du Circuit Nord.',
        'desc_es'    => 'Ubicado en las laderas del Monte Meru a las afueras de la ciudad de Arusha, el Arusha Explorers Lodge ofrece 8 habitaciones de lujo con terrazas privadas con vistas a exuberantes platanales. Cada habitación cuenta con amplios interiores con baño privado. Las instalaciones incluyen salón, restaurante y bar y una piscina. A aproximadamente 1 hora en coche del aeropuerto internacional del Kilimanjaro. La base perfecta para explorar los parques nacionales del Circuito Norte.',
        'desc_de'    => 'Am Hang des Mount Meru außerhalb der Stadt Arusha gelegen, bietet die Arusha Explorers Lodge 8 Luxuszimmer mit privaten Veranden mit Blick auf üppige Bananenplantagen. Jedes Zimmer verfügt über geräumige Innenräume mit eigenem Bad. Zu den Einrichtungen gehören Lounge, Restaurant und Bar sowie ein Schwimmbad. Ca. 1 Stunde Fahrt vom Kilimandscharo Internationalen Flughafen entfernt. Die perfekte Basis zur Erkundung der Nationalparks des nördlichen Kreises.',
    ],
];

// ─── MAIN ─────────────────────────────────────────────────────────────────────
$dry_run = isset($_GET['dry']);
$do_run  = isset($_POST['run']);
$log     = [];
$rows    = [];
$ok = $err = $skip = 0;

// Load destination codes → ids
$dest_map = [];
foreach ($db->query("SELECT id, code, name_en FROM iti_destinations")->fetchAll() as $d) {
    $dest_map[$d['code']] = ['id' => $d['id'], 'name' => $d['name_en']];
}

if ($do_run || $dry_run) {

    if ($do_run && !$dry_run) {
        // Only delete Orangi Collection lodges if re-running
        $db->exec("DELETE FROM iti_lodges WHERE name IN (
            'Serengeti Orangi River Luxury Lodge',
            'Ngorongoro Marera Mountain View Lodge',
            'Serengeti Kifaru Tented Lodge',
            'Arusha Explorers Lodge'
        )");
        $log[] = "🗑 Existing Orangi Collection lodges removed";
    }

    foreach ($LODGES as $lodge) {
        $code = $lodge['dest_code'];
        if (!isset($dest_map[$code])) {
            $log[] = "⚠️ Destination code not found: {$code} — skipping {$lodge['name']}";
            $skip++;
            continue;
        }
        $dest_id   = $dest_map[$code]['id'];
        $dest_name = $dest_map[$code]['name'];

        $rows[] = [
            'dest_code' => $code,
            'dest_name' => $dest_name,
            'name'      => $lodge['name'],
            'category'  => $lodge['category'],
            'type'      => $lodge['lodge_type'],
            'website'   => $lodge['website'],
            'desc_en'   => $lodge['desc_en'],
        ];

        if ($do_run && !$dry_run) {
            try {
                $db->prepare(
                    'INSERT INTO iti_lodges
                     (destination_id, name, category, lodge_type, website,
                      description_en, description_it, description_fr, description_es, description_de,
                      is_active)
                     VALUES (?,?,?,?,?,?,?,?,?,?,1)'
                )->execute([
                    $dest_id,
                    $lodge['name'],
                    $lodge['category'],
                    $lodge['lodge_type'],
                    $lodge['website'],
                    $lodge['desc_en'],
                    $lodge['desc_it'],
                    $lodge['desc_fr'],
                    $lodge['desc_es'],
                    $lodge['desc_de'],
                ]);
                $log[] = "✅ {$lodge['name']} ({$dest_name})";
                $ok++;
            } catch (Exception $e) {
                $log[] = "❌ {$lodge['name']}: " . $e->getMessage();
                $err++;
            }
        }
    }

    if ($do_run && !$dry_run)
        $log[] = "Import completato: {$ok} lodge inseriti, {$skip} skip, {$err} errori.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Import Lodges — Orangi Collection</title>
<style>
body{font-family:sans-serif;padding:24px 32px;background:#f7f6f3;color:#1a1a1a;font-size:.83rem;}
h1{color:#0a6647;}
.actions{display:flex;gap:10px;margin:16px 0;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:6px;font-size:.84rem;font-weight:600;text-decoration:none;border:none;cursor:pointer;color:#fff;}
.btn-green{background:#0a6647;}.btn-grey{background:#444;}
.log{background:#1a1a1a;color:#a8e6a3;font-family:monospace;font-size:.75rem;padding:12px 16px;border-radius:8px;max-height:180px;overflow-y:auto;margin:12px 0;white-space:pre-wrap;}
.ok-box{background:#d4edda;padding:10px 14px;border-radius:6px;margin-bottom:12px;}
.warn-box{background:#fff3cd;padding:10px 14px;border-radius:6px;margin-bottom:12px;}
table{width:100%;border-collapse:collapse;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-top:12px;}
th{background:#0a6647;color:#fff;padding:7px 10px;text-align:left;font-size:.73rem;white-space:nowrap;}
td{padding:8px 10px;border-bottom:1px solid #eee;vertical-align:top;font-size:.78rem;}
.name{font-weight:700;}
.tag{display:inline-block;padding:2px 8px;border-radius:4px;font-size:.7rem;font-weight:600;}
.luxury{background:#FAEEDA;color:#854F0B;}
.lodge{background:#e8f4f0;color:#0a6647;}
.tented{background:#eef2ff;color:#3730a3;}
.desc{max-height:60px;overflow:hidden;color:#666;line-height:1.5;font-size:.73rem;}
</style>
</head>
<body>
<h1>🏕 Import Lodges — The Orangi Collection</h1>
<p style="color:#666;margin-bottom:4px;">4 lodges from theorangicollection.com — descriptions in EN/IT/FR/ES/DE</p>

<?php if ($log): ?>
<div class="log"><?= implode("\n", array_map('htmlspecialchars', $log)) ?></div>
<?php endif; ?>
<?php if ($do_run && !$dry_run && $ok > 0): ?>
<div class="ok-box">✅ <?= $ok ?> lodge inseriti. <a href="lodges.php">→ Vai ai Lodge</a></div>
<?php endif; ?>
<?php if ($skip > 0): ?>
<div class="warn-box">⚠️ <?= $skip ?> destinazioni non trovate — assicurati che l'import destinazioni sia completato.</div>
<?php endif; ?>

<div class="actions">
  <a href="?dry" class="btn btn-grey">🔍 Dry Run</a>
  <form method="POST" action="iti_import_lodges.php" style="margin:0;"
        onsubmit="return confirm('Rimuovi i lodge Orangi Collection esistenti e reimporta?')">
    <input type="hidden" name="run" value="1">
    <button class="btn btn-green">🚀 Import Lodges</button>
  </form>
  <a href="lodges.php" class="btn btn-grey" style="background:#555;">← Back</a>
</div>

<?php if ($rows): ?>
<table>
<thead><tr>
  <th>Lodge</th><th>Destination</th><th>Category</th><th>Type</th><th>Website</th><th>Description EN</th>
</tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
  <td class="name"><?= h($r['name']) ?></td>
  <td><?= h($r['dest_name']) ?> <span style="font-family:monospace;font-size:.7rem;color:#888;">(<?= h($r['dest_code']) ?>)</span></td>
  <td><span class="tag luxury"><?= h($r['category']) ?></span></td>
  <td><span class="tag <?= $r['type']==='tented_camp'?'tented':'lodge' ?>"><?= h($r['type']) ?></span></td>
  <td style="font-size:.7rem;"><a href="<?= h($r['website']) ?>" target="_blank"><?= h($r['website']) ?></a></td>
  <td><div class="desc"><?= h(mb_substr($r['desc_en'],0,150)) ?>…</div></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php elseif (!$do_run && !$dry_run): ?>
<p style="color:#888;margin-top:12px;">Clicca "Dry Run" per anteprima o "Import Lodges" per procedere.</p>
<?php endif; ?>
</body>
</html>
