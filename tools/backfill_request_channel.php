<?php
/**
 * backfill_request_channel.php — one-off: fill requests.channel / agency_id (migration 067)
 * from the folder name, only where the folder says it for sure:
 *
 *   "(Agent-Drct…)"                          → direct
 *   "(Agent-SB)"                             → sb
 *   "(Capri-…)", "(RobertoCapri)" (Roberto Capri's direct clients) → direct
 *   any "EleonoraOngaro" (blog referral, e.g. "(Nuru-EleonoraOngaro)", "(Anderson_EleonoraOngaro)") → direct
 *   "(Nuru-Trekk)" / "(Roberto-Trek)" / "-Tekk" (trekking clients)  → direct
 *   "(AgencyShort-…-Agent)", first token = exactly one agency (short_name without its
 *   -PS / -LAM suffix, or name, compared without spaces/punctuation), or a token in
 *   $aliases (folder spellings checked by hand, Oct 2026)  → agency + agency_id
 *   "(Agent-…-Agency)" (agent first, e.g. "(Sultan-Yeadimtravel)", "(Roberto-LAM-CREO)"):
 *   the first later token naming one agency                → agency + agency_id
 *   one-token "(Agency)" / "(Agency_Agent)" (e.g. "(SouriTrip)", "(GoWorld_Alex)"): the
 *   first "_" part that names one agency and is not an agent name → agency + agency_id
 *
 * The LAST "(…)" block of the folder is read: a broken name like
 * "Claudiastefani(columbusvacanzeDaniela)(ColumbusVacanze-Daniela)" carries the real tag at the end.
 * Anything else (unknown agency, agent-only block, no block) stays NULL. Only rows with
 * channel IS NULL are touched. Dry run by default (prints counts); writes only with --confirm.
 * --list also prints each row that gets a channel.
 *
 *   php tools/backfill_request_channel.php [--confirm] [--list]
 *
 * CLI only (run over SSH on the server).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Africa/Dar_es_Salaam');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$confirm = in_array('--confirm', $argv, true);
$list    = in_array('--list', $argv, true);
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
    // spellings matched by hand, 9 Oct 2026
    'avit' => 9, 'adriana' => 72, 'avventure' => 129, 'areatour' => 227, 'souritip' => 75, 'federicakailas' => 48,
];
// First tokens (or a whole one-token block) that mean a direct client (not an agency).
$directTokens = ['capri', 'robertocapri'];
// Agents whose "(Agent-Trek)" folders are direct trekking clients.
$trekAgents = ['nuru', 'roberto'];

// Agency lookup (active agencies): normalised short_name (minus -PS / -LAM) / name → agency ids
$agencyIdx = [];
foreach ($pdo->query("SELECT id, nome, short_name FROM agencies WHERE attiva = 1") as $a) {
    foreach ([preg_replace('/-(PS|LAM)$/i', '', (string)$a['short_name']), $a['nome']] as $k) {
        if (trim((string)$k) !== '') $agencyIdx[$norm($k)][(int)$a['id']] = true;
    }
}
// One token → agency id: exactly one active agency, else an alias, else null.
$agencyOf = function ($tok) use ($norm, $agencyIdx, $aliases) {
    $t = $norm($tok);
    if ($t === '') return null;
    $hit = $agencyIdx[$t] ?? [];
    if (count($hit) === 1) return (int)key($hit);
    return isset($aliases[$t]) ? $aliases[$t] : null;
};
// Agent names (normalised): "(Agent-Agency)" folders put the agent first.
$agentIdx = [];
foreach ($pdo->query("SELECT name FROM agents") as $a) $agentIdx[$norm($a['name'])] = true;

$count = ['direct' => 0, 'sb' => 0, 'agency' => 0, 'unknown' => 0];
$unknownTokens = [];
$upd = $pdo->prepare("UPDATE requests SET channel = ?, agency_id = ? WHERE id = ? AND channel IS NULL");
$rows = $pdo->query("SELECT id, COALESCE(NULLIF(group_folder, ''), practice_code) AS folder FROM requests WHERE channel IS NULL")
            ->fetchAll(PDO::FETCH_ASSOC);

if ($confirm) $pdo->beginTransaction();
foreach ($rows as $r) {
    $channel = null; $agencyId = null;
    if (preg_match_all('/\(([^()]+)\)/', (string)$r['folder'], $mm)) {
        $block  = end($mm[1]);   // last "(…)" block
        $tokens = array_values(array_filter(array_map('trim', explode('-', $block)), 'strlen'));
        if (in_array('Drct', $tokens, true)) {
            $channel = 'direct';
        } elseif (strpos($norm($block), 'eleonoraongaro') !== false) {
            $channel = 'direct';
        } elseif (count($tokens) === 1 && in_array($norm($tokens[0]), $directTokens, true)) {
            $channel = 'direct';
        } elseif (count($tokens) === 2 && in_array($norm($tokens[0]), $trekAgents, true) && preg_match('/^t(r)?ek+$/i', $tokens[1])) {
            $channel = 'direct';
        } elseif (in_array('SB', $tokens, true)) {
            $channel = 'sb';
        } elseif (count($tokens) >= 2) {
            $tok = $norm($tokens[0]);
            if (in_array($tok, $directTokens, true)) $channel = 'direct';
            elseif (($id = $agencyOf($tokens[0])) !== null) { $channel = 'agency'; $agencyId = $id; }
            elseif (isset($agentIdx[$tok])) {   // "(Agent-…-Agency)"
                foreach (array_slice($tokens, 1) as $t) {
                    if (in_array(strtoupper($t), ['PS', 'LAM'], true)) continue;
                    if (($id = $agencyOf($t)) !== null) { $channel = 'agency'; $agencyId = $id; break; }
                }
            }
            if ($channel === null) $unknownTokens[$tokens[0]] = ($unknownTokens[$tokens[0]] ?? 0) + 1;
        } elseif (count($tokens) === 1) {       // "(Agency)" / "(Agency_Agent)"
            foreach (explode('_', $tokens[0]) as $t) {
                if (isset($agentIdx[$norm($t)])) continue;
                if (($id = $agencyOf($t)) !== null) { $channel = 'agency'; $agencyId = $id; break; }
            }
        }
    }
    if ($channel === null) { $count['unknown']++; continue; }
    $count[$channel]++;
    if ($list) echo '#' . $r['id'] . ' ' . $channel . ($agencyId ? ' ag=' . $agencyId : '') . '  ' . $r['folder'] . "\n";
    if ($confirm) $upd->execute([$channel, $agencyId, (int)$r['id']]);
}
if ($confirm) $pdo->commit();

echo ($confirm ? 'WRITTEN' : 'DRY RUN (add --confirm to write)') . ' — ' . count($rows) . " rows without channel\n";
foreach ($count as $k => $n) echo str_pad($k, 8) . " $n\n";
arsort($unknownTokens);
echo "Unmatched agency tokens (top 20):\n";
foreach (array_slice($unknownTokens, 0, 20, true) as $t => $n) echo "  $t: $n\n";
