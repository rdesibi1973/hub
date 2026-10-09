<?php
/**
 * backfill_request_channel.php — one-off: fill requests.channel / agency_id (migration 067)
 * from the folder name, only where the folder says it for sure:
 *
 *   "(Agent-Drct…)"                          → direct
 *   "(Agent-SB)"                             → sb
 *   "(AgencyShort-…-Agent)", first token = exactly one agency (short_name or name,
 *   compared without spaces/punctuation)     → agency + agency_id
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

// Agency lookup: normalised short_name / name → agency ids
$agencyIdx = [];
foreach ($pdo->query("SELECT id, nome, short_name FROM agencies") as $a) {
    foreach ([$a['short_name'], $a['nome']] as $k) {
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
            $hit = $agencyIdx[$norm($tokens[0])] ?? [];
            if (count($hit) === 1) { $channel = 'agency'; $agencyId = (int)key($hit); }
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
