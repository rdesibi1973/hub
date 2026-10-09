<?php
/**
 * backfill_request_channel.php — one-off: fill requests.channel / agency_id (migration 067)
 * from the folder name, only where the folder says it for sure:
 *
 *   "(Agent-Drct…)"                          → direct
 *   "(Agent-SB)"                             → sb
 *   "(Capri-…)" (Roberto Capri's direct clients) → direct
 *   "(AgencyShort-…-Agent)", first token = exactly one agency (short_name without its
 *   -PS / -LAM suffix, or name, compared without spaces/punctuation), or a token in
 *   $aliases (folder spellings checked by hand, Oct 2026)  → agency + agency_id
 *
 * Anything else (unknown agency, one-token block, no block) stays NULL. Only rows with
 * channel IS NULL are touched. Dry run by default (prints counts); writes only with --confirm.
 *
 *   php tools/backfill_request_channel.php [--confirm]
 *
 * CLI only (run over SSH on the server).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Africa/Dar_es_Salaam');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$confirm = in_array('--confirm', $argv, true);
$norm = function ($s) { return strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$s)); };

// Folder spellings that differ from the agency record (normalised token → agency id).
$aliases = [
    'woa' => 86, 'euphemia' => 202, 'lamiafrica' => 52, 'viagginuovaera' => 154, 'teloni' => 77, 'amisano' => 5,
    'souri' => 75, 'souritripalessia' => 75, 'asti' => 126, 'creotravel' => 106, 'creoviaggi' => 106,
    'sagaexpedition' => 73, 'ventotesotravel' => 168, 'encounterafrica' => 30, 'encounterafricasafari' => 30,
    'lagiostradeiviaggi' => 205, 'daver' => 132, 'luxurynumber5' => 58, 'odp' => 119, 'odp1pax' => 119,
    'demidoff' => 20, 'bramardi' => 12, 'commendatours' => 244, 'verticallife' => 82, 'mondeltravel' => 206,
    'gruppoauroraviaggi' => 102, 'newcoagency' => 153, 'trafficadidiviaggio' => 162, 'atlante' => 127,
    'checkintrave' => 15, 'iantrasrleventiecongressi' => 226, 'maxitravel' => 59, 'africalands' => 2, 'africaland' => 2,
    'eko' => 27, 'gaia' => 36, 'tvtravel' => 164, 'inforgroovetrave' => 39, 'focus' => 34, 'matthew' => 274,
    'lastminutetour' => 247,
    // duplicates merged Oct 2026 (kept the record with more data / the oldest; the others are attiva = 0)
    'sonotravel' => 74, 'libetiter' => 209, 'libetitertravel' => 209, 'mywayholiday' => 92,
    'mywayholidaytouroperator' => 92, 'kendirita' => 249, 'kendiritatours' => 249,
];
// First tokens that mean a direct client (not an agency).
$directTokens = ['capri'];

// Agency lookup (active agencies): normalised short_name (minus -PS / -LAM) / name → agency ids
$agencyIdx = [];
foreach ($pdo->query("SELECT id, nome, short_name FROM agencies WHERE attiva = 1") as $a) {
    foreach ([preg_replace('/-(PS|LAM)$/i', '', (string)$a['short_name']), $a['nome']] as $k) {
        if (trim((string)$k) !== '') $agencyIdx[$norm($k)][(int)$a['id']] = true;
    }
}

$count = ['direct' => 0, 'sb' => 0, 'agency' => 0, 'unknown' => 0];
$unknownTokens = [];
$upd = $pdo->prepare("UPDATE requests SET channel = ?, agency_id = ? WHERE id = ? AND channel IS NULL");
$rows = $pdo->query("SELECT id, COALESCE(NULLIF(group_folder, ''), practice_code) AS folder FROM requests WHERE channel IS NULL")
            ->fetchAll(PDO::FETCH_ASSOC);

if ($confirm) $pdo->beginTransaction();
foreach ($rows as $r) {
    $channel = null; $agencyId = null;
    if (preg_match('/\(([^)]+)\)/', (string)$r['folder'], $m)) {
        $tokens = array_values(array_filter(array_map('trim', explode('-', $m[1])), 'strlen'));
        if (in_array('Drct', $tokens, true)) {
            $channel = 'direct';
        } elseif (in_array('SB', $tokens, true)) {
            $channel = 'sb';
        } elseif (count($tokens) >= 2) {
            $tok = $norm($tokens[0]);
            $hit = $agencyIdx[$tok] ?? [];
            if (in_array($tok, $directTokens, true)) $channel = 'direct';
            elseif (count($hit) === 1)          { $channel = 'agency'; $agencyId = (int)key($hit); }
            elseif (isset($aliases[$tok]))  { $channel = 'agency'; $agencyId = $aliases[$tok]; }
            else $unknownTokens[$tokens[0]] = ($unknownTokens[$tokens[0]] ?? 0) + 1;
        }
    }
    if ($channel === null) { $count['unknown']++; continue; }
    $count[$channel]++;
    if ($confirm) $upd->execute([$channel, $agencyId, (int)$r['id']]);
}
if ($confirm) $pdo->commit();

echo ($confirm ? 'WRITTEN' : 'DRY RUN (add --confirm to write)') . ' — ' . count($rows) . " rows without channel\n";
foreach ($count as $k => $n) echo str_pad($k, 8) . " $n\n";
arsort($unknownTokens);
echo "Unmatched agency tokens (top 20):\n";
foreach (array_slice($unknownTokens, 0, 20, true) as $t => $n) echo "  $t: $n\n";
