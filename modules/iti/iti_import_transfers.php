<?php
/**
 * iti_import_transfers.php
 * Import transfer routes (bidirectional) into iti_transfer_routes
 * Eseguire una sola volta dopo aver importato le destinazioni
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db = db();

// ─── ROUTES ──────────────────────────────────────────────────────────────────
// [ from_code, to_code, km, min, road_type, note_en ]
// road_type: tarmac | gravel | mixed
// Bidirectional: script inserts A→B and B→A automatically

$ROUTES = [
    // ── JRO / AIRPORT CONNECTIONS ──────────────────────────────────────────
    ['JRO', 'ARU',  50,  90, 'tarmac', 'Good tarmac road through Arusha town'],
    ['JRO', 'MSH',  40,  90, 'tarmac', 'Good tarmac road via Moshi town'],
    ['ARK', 'ARU',   5,  15, 'tarmac', 'Short transfer within Arusha'],
    ['DAR', 'DRSM', 15,  30, 'tarmac', 'Airport to Dar es Salaam city centre'],
    // ── NORTHERN CIRCUIT ───────────────────────────────────────────────────
    ['ARU', 'MSH',  80, 120, 'tarmac', 'Good tarmac road via A23 highway'],
    ['ARU', 'MWB', 120, 120, 'mixed',  'Tarmac to Makuyuni then dirt road'],
    ['ARU', 'MKY', 100,  90, 'mixed',  'Tarmac to Makuyuni junction'],
    ['ARU', 'TRP', 130, 120, 'mixed',  'Tarmac to Makuyuni then murram road into park'],
    ['ARU', 'LMN', 120, 120, 'mixed',  'Tarmac to Makuyuni then gravel to Lake Manyara'],
    ['ARU', 'KAR', 160, 150, 'mixed',  'Tarmac to Makuyuni then good gravel road to Karatu'],
    ['ARU', 'NCA', 190, 180, 'mixed',  'Tarmac to Makuyuni then gravel through Karatu to Ngorongoro gate'],
    ['ARU', 'SNP', 335, 360, 'mixed',  'Long drive via Makuyuni, Karatu, Ngorongoro and Serengeti gate'],
    ['ARU', 'MCH', 120, 150, 'mixed',  'Tarmac towards Moshi then dirt road to Machame gate'],
    ['ARU', 'MRG',  80,  90, 'tarmac', 'Tarmac road towards Moshi, turnoff to Marangu gate'],
    ['ARU', 'LEM', 195, 210, 'mixed',  'Via Londorossi gate, partly murram road'],
    ['ARU', 'RNG', 200, 240, 'mixed',  'Long route to Rongai gate on the northern slopes'],
    ['MSH', 'MCH',  30,  45, 'tarmac', 'Short tarmac road to Machame gate'],
    ['MSH', 'MRG',  55,  60, 'tarmac', 'Tarmac road to Marangu gate'],
    ['MSH', 'LEM', 150, 180, 'mixed',  'Via Londorossi gate, partly murram road'],
    ['MSH', 'RNG', 100, 120, 'mixed',  'To Rongai gate on the northern slopes'],
    ['MSH', 'TRP', 170, 210, 'mixed',  'Via Arusha and Makuyuni to Tarangire'],
    ['MSH', 'LMN', 165, 210, 'mixed',  'Via Arusha and Makuyuni to Lake Manyara'],
    ['MSH', 'KAR', 220, 240, 'mixed',  'Via Arusha, Makuyuni and gravel road to Karatu'],
    ['TRP', 'MWB',  40,  45, 'mixed',  'Short murram road between Tarangire and Mto wa Mbu'],
    ['TRP', 'LMN',  60,  60, 'mixed',  'Short gravel road between the two parks'],
    ['TRP', 'KAR',  90,  90, 'mixed',  'Via Mto wa Mbu, partly gravel road'],
    ['LMN', 'MWB',  30,  30, 'mixed',  'Very short road along the lake escarpment'],
    ['LMN', 'KAR',  75,  90, 'mixed',  'Gravel road through Mto wa Mbu to Karatu'],
    ['LMN', 'NCA',  80,  90, 'mixed',  'Via Karatu up to the crater rim'],
    ['KAR', 'NCA',  15,  30, 'mixed',  'Short gravel climb to Ngorongoro gate'],
    ['KAR', 'SNP', 200, 240, 'mixed',  'Through Ngorongoro Conservation Area to Serengeti gate'],
    ['NCA', 'SNP', 220, 270, 'mixed',  'Through Ngorongoro crater area and Olduvai to Serengeti'],
    ['MWB', 'MKY',  20,  20, 'mixed',  'Short stretch along the main Arusha road'],
    // ── BORDER CROSSINGS ───────────────────────────────────────────────────
    ['ARU', 'NAM', 100,  90, 'tarmac', 'Tarmac road to Namanga Tanzania/Kenya border post'],
    ['MSH', 'TVT', 100, 120, 'mixed',  'Road to Taveta Tanzania/Kenya border via Himo'],
    ['ARU', 'ISB', 380, 480, 'mixed',  'Long drive to Isebania border crossing near Lake Victoria'],
    // ── SOUTHERN CIRCUIT ───────────────────────────────────────────────────
    ['DRSM','IRG', 500, 600, 'tarmac', 'Good tarmac via TANZAM highway'],
    ['DRSM','MKM', 300, 360, 'tarmac', 'Tarmac via TANZAM highway through Mikumi NP area'],
    ['DRSM','RNP', 625, 720, 'mixed',  'Long drive via TANZAM and then gravel to Ruaha'],
    ['DRSM','ARU', 635, 720, 'mixed',  'Very long drive, usually done overnight or by flight'],
    ['IRG', 'KSZ', 100,  90, 'mixed',  'Gravel road from Iringa to Kisolanza farm area'],
    ['IRG', 'RNP', 130, 120, 'mixed',  'Gravel road through bush to Ruaha National Park gate'],
    ['KSZ', 'RNP',  55,  60, 'mixed',  'Short transfer from Kisolanza to Ruaha park gate'],
];

// ─── MULTILINGUAL NOTE GENERATOR ─────────────────────────────────────────────
function transfer_notes(string $from_name, string $to_name, int $km, int $min, string $note_en): array {
    $h = $min >= 60 ? floor($min/60) : 0;
    $m = $min % 60;
    $time_en = $h > 0 ? ($h . 'h' . ($m > 0 ? $m.'min' : '')) : $m.'min';
    $time_it = $h > 0 ? ($h . ' or' . ($h > 1 ? 'e' : 'a') . ($m > 0 ? ' e '.$m.' min' : '')) : $m.' min';
    $time_fr = $h > 0 ? ($h . 'h' . ($m > 0 ? $m : '')) : $m.'min';
    $time_es = $h > 0 ? ($h . 'h' . ($m > 0 ? $m.'min' : '')) : $m.'min';
    $time_de = $h > 0 ? ($h . ' Std.' . ($m > 0 ? ' '.$m.' Min.' : '')) : $m.' Min.';

    // Translate note_en road condition to other languages
    $road_map = [
        'tarmac' => ['it'=>'Strada asfaltata','fr'=>'Route asphaltée','es'=>'Carretera asfaltada','de'=>'Asphaltierte Straße'],
        'gravel' => ['it'=>'Strada sterrata','fr'=>'Piste en gravier','es'=>'Carretera de grava','de'=>'Schotterstraße'],
        'mixed'  => ['it'=>'Strada mista asfalto e sterrato','fr'=>'Route mixte asphalte et piste','es'=>'Carretera mixta asfalto y grava','de'=>'Gemischte Straße'],
    ];

    return [
        'en' => "Transfer from {$from_name} to {$to_name} — {$km} km, approx. {$time_en}. {$note_en}.",
        'it' => "Transfer da {$from_name} a {$to_name} — {$km} km, circa {$time_it}.",
        'fr' => "Transfert de {$from_name} à {$to_name} — {$km} km, environ {$time_fr}.",
        'es' => "Traslado de {$from_name} a {$to_name} — {$km} km, aproximadamente {$time_es}.",
        'de' => "Transfer von {$from_name} nach {$to_name} — {$km} km, ca. {$time_de}.",
    ];
}

// ─── MAIN ─────────────────────────────────────────────────────────────────────
$dry_run = isset($_GET['dry']);
$do_run  = isset($_POST['run']);
$log     = [];
$rows    = [];
$ok = $err = $skip = 0;

// Load all destination codes → id + name
$dest_map = [];
foreach ($db->query("SELECT id, code, name_en FROM iti_destinations")->fetchAll() as $d) {
    $dest_map[$d['code']] = ['id' => $d['id'], 'name' => $d['name_en']];
}

if ($do_run || $dry_run) {

    if ($do_run && !$dry_run) {
        $db->exec("SET FOREIGN_KEY_CHECKS=0");
        $db->exec("TRUNCATE TABLE iti_transfer_routes");
        $db->exec("SET FOREIGN_KEY_CHECKS=1");
        $log[] = "🗑 TRUNCATE iti_transfer_routes — done";
    }

    foreach ($ROUTES as [$from_code, $to_code, $km, $min, $road_type, $note_en]) {

        // Validate codes
        if (!isset($dest_map[$from_code])) {
            $log[] = "⚠️ Code not found: {$from_code}";
            $skip++; continue;
        }
        if (!isset($dest_map[$to_code])) {
            $log[] = "⚠️ Code not found: {$to_code}";
            $skip++; continue;
        }

        $from_id   = $dest_map[$from_code]['id'];
        $to_id     = $dest_map[$to_code]['id'];
        $from_name = $dest_map[$from_code]['name'];
        $to_name   = $dest_map[$to_code]['name'];

        // Build both directions
        $directions = [
            [$from_id, $to_id, $from_name, $to_name],
            [$to_id, $from_id, $to_name, $from_name],
        ];

        foreach ($directions as [$fid, $tid, $fn, $tn]) {
            $notes = transfer_notes($fn, $tn, $km, $min, $note_en);
            $rows[] = [
                'from_code' => $fid === $from_id ? $from_code : $to_code,
                'to_code'   => $fid === $from_id ? $to_code   : $from_code,
                'from_name' => $fn,
                'to_name'   => $tn,
                'km'        => $km,
                'min'       => $min,
                'road_type' => $road_type,
                'note_en'   => $notes['en'],
            ];

            if ($do_run && !$dry_run) {
                try {
                    $db->prepare(
                        'INSERT INTO iti_transfer_routes
                         (from_destination,to_destination,duration_min,distance_km,road_type,
                          notes_en,notes_it,notes_fr,notes_es,notes_de,is_active)
                         VALUES (?,?,?,?,?,?,?,?,?,?,1)'
                    )->execute([
                        $fid, $tid, $min, $km, $road_type,
                        $notes['en'], $notes['it'], $notes['fr'], $notes['es'], $notes['de'],
                    ]);
                    $ok++;
                } catch (Exception $e) {
                    $log[] = "❌ {$fn} → {$tn}: " . $e->getMessage();
                    $err++;
                }
            }
        }
    }

    if ($do_run && !$dry_run)
        $log[] = "Import completato: {$ok} rotte inserite, {$skip} codici mancanti, {$err} errori DB.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Import Transfer Routes</title>
<style>
body{font-family:sans-serif;padding:24px 32px;background:#f7f6f3;color:#1a1a1a;font-size:.83rem;}
h1{color:#8b1010;}
.actions{display:flex;gap:10px;margin:16px 0;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:6px;font-size:.84rem;font-weight:600;text-decoration:none;border:none;cursor:pointer;color:#fff;}
.btn-red{background:#8b1010;}.btn-grey{background:#444;}
.log{background:#1a1a1a;color:#a8e6a3;font-family:monospace;font-size:.75rem;padding:12px 16px;border-radius:8px;max-height:180px;overflow-y:auto;margin:12px 0;white-space:pre-wrap;}
.ok-box{background:#d4edda;padding:10px 14px;border-radius:6px;margin-bottom:12px;}
.warn-box{background:#fff3cd;padding:10px 14px;border-radius:6px;margin-bottom:12px;}
table{width:100%;border-collapse:collapse;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-top:12px;}
th{background:#2c2c2a;color:#fff;padding:7px 10px;text-align:left;font-size:.73rem;white-space:nowrap;}
td{padding:6px 10px;border-bottom:1px solid #eee;vertical-align:top;font-size:.73rem;}
.code{font-family:monospace;font-weight:700;font-size:.72rem;}
.tarmac{color:#0a6647;}.mixed{color:#b87c00;}.gravel{color:#c0392b;}
.time{white-space:nowrap;color:#555;}
</style>
</head>
<body>
<h1>🚗 Import Transfer Routes</h1>
<p style="color:#666;margin-bottom:4px;">
  <?= count($ROUTES) ?> unique routes × 2 directions = <strong><?= count($ROUTES)*2 ?> rows</strong> total.
  Requires destinations to be imported first.
</p>

<?php if ($log): ?>
<div class="log"><?= implode("\n", array_map('htmlspecialchars', $log)) ?></div>
<?php endif; ?>
<?php if ($do_run && !$dry_run && $ok > 0): ?>
<div class="ok-box">✅ <?= $ok ?> transfer routes inserted. <a href="transfers.php">→ Vai ai Transfer</a></div>
<?php endif; ?>
<?php if ($skip > 0): ?>
<div class="warn-box">⚠️ <?= $skip ?> destinazioni non trovate nel DB — verifica che l'import destinazioni sia completato.</div>
<?php endif; ?>

<div class="actions">
  <a href="?dry" class="btn btn-grey">🔍 Dry Run</a>
  <form method="POST" action="iti_import_transfers.php" style="margin:0;"
        onsubmit="return confirm('TRUNCATE iti_transfer_routes e reimporta tutto?')">
    <input type="hidden" name="run" value="1">
    <button class="btn btn-red">🚀 Truncate + Import</button>
  </form>
  <a href="transfers.php" class="btn btn-grey" style="background:#555;">← Back</a>
</div>

<?php if ($rows): ?>
<p style="color:#888;margin-bottom:6px;"><?= count($rows) ?> routes (including both directions):</p>
<table>
<thead><tr>
  <th>From</th><th>To</th><th>Km</th><th>Time</th><th>Road</th><th>Note EN</th>
</tr></thead>
<tbody>
<?php foreach ($rows as $r):
    $h = intdiv($r['min'],60); $m = $r['min']%60;
    $time = ($h?$h.'h':'').($m?$m.'min':'');
?>
<tr>
  <td class="code"><?= h($r['from_name']) ?></td>
  <td class="code"><?= h($r['to_name']) ?></td>
  <td style="text-align:right;"><?= $r['km'] ?></td>
  <td class="time"><?= $time ?></td>
  <td class="<?= $r['road_type'] ?>"><?= $r['road_type'] ?></td>
  <td style="color:#666;font-size:.71rem;"><?= h(mb_substr($r['note_en'],0,80)) ?>…</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php elseif (!$do_run && !$dry_run): ?>
<p style="color:#888;margin-top:12px;">Clicca "Dry Run" per anteprima, oppure "Truncate + Import" per procedere.</p>
<?php endif; ?>
</body>
</html>
