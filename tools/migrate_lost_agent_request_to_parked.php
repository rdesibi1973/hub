<?php
/**
 * migrate_lost_agent_request_to_parked.php — one-off (Roberto, 20 Nov 2026): requests marked Lost with reason
 * agent_request ("Agency request – no follow-up") were not lost, only waiting for the agency → status Parked.
 *
 *   Parked  when the trip is not over (parked_service.php pk_trip_over(): start_date, else the folder dates, else
 *           Lost less than PARKED_STALE_DAYS (180) days ago). parked_at = the Lost date (reporting / auto-close),
 *           parked_wake_after = the migration time (set by bs_set_request_status(): only mails after it wake the
 *           request up — fix of 20 Nov 2026, old mails from October woke 4 requests), parked_from = Quoted.
 *           lost_reason / lost_note are cleared as on any reopen; the "Marked Lost" note and the old timeline events
 *           stay; timeline event "Migrated from Lost (agent_request) to Parked".
 *   leave   trip over, or no Lost date and no trip date: untouched (reason kept — Roberto decides).
 *
 * Dry run by default: prints the table (and --csv=<file>). Writes only with --confirm; --id=N (repeatable) limits
 * the run to those requests (confirm one first).
 *
 *   php tools/migrate_lost_agent_request_to_parked.php [--csv=/tmp/parked.csv] [--id=2451] [--confirm]
 *
 * CLI only (over SSH on the server). Needs 'Parked' in STATUSES (modules/leads/config.php).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../modules/leads');
require_once __DIR__ . '/../modules/leads/config.php';
require_once __DIR__ . '/../modules/leads/includes/booking_service.php';

$confirm = in_array('--confirm', $argv, true);
$csv = ''; $only = [];
foreach ($argv as $a) {
    if (preg_match('/^--csv=(.+)$/', $a, $m)) $csv = $m[1];
    if (preg_match('/^--id=(\d+)$/', $a, $m)) $only[] = (int)$m[1];
}
if (!array_key_exists(PK_STATUS, STATUSES)) { fwrite(STDERR, "STATUSES has no 'Parked' yet (modules/leads/config.php) — stop.\n"); exit(1); }

$db = db();
pk_schema($db);
tl_schema($db);
$sql = "SELECT r.id, r.customer_name, r.practice_code, r.group_folder, r.start_date, r.status, r.lost_reason,
               a.name AS agent, COALESCE(NULLIF(ag.short_name, ''), ag.nome) AS agency
        FROM requests r LEFT JOIN agents a ON a.id = r.agent_id LEFT JOIN agencies ag ON ag.id = r.agency_id
        WHERE r.status = 'Lost' AND r.lost_reason = 'agent_request'" . ($only ? " AND r.id IN (" . implode(',', $only) . ")" : "") . "
        ORDER BY r.id";
$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Lost date: the last "… → Lost" timeline event, else the "Marked Lost" request note.
$tl = $db->prepare("SELECT MAX(event_at) FROM request_timeline WHERE request_id = ? AND event_type = 'status_change' AND title LIKE '% → Lost' AND hidden = 0");
$nt = $db->prepare("SELECT MAX(created_at) FROM request_notes WHERE request_id = ? AND note LIKE 'Marked Lost%'");

$out = [];
foreach ($rows as $r) {
    $tl->execute([(int)$r['id']]); $lost = $tl->fetchColumn() ?: null;
    if (!$lost) { $nt->execute([(int)$r['id']]); $lost = $nt->fetchColumn() ?: null; }
    $t = pk_trip_over($r + ['parked_at' => $lost]);
    $action = ($t['basis'] === 'none' || $t['over']) ? 'leave' : 'park';
    $why = $t['basis'] === 'none' ? 'no Lost date, no trip date' : ($t['over'] ? 'trip over (' . $t['basis'] . ')' : 'trip ahead (' . $t['basis'] . ')');
    $item = ['id' => (int)$r['id'], 'folder' => $r['practice_code'], 'agent' => $r['agent'], 'agency' => $r['agency'],
             'lost_date' => $lost ? substr((string)$lost, 0, 10) : '', 'trip_date' => $t['trip_date'] ?: '', 'action' => $action,
             'why' => $why, 'result' => ''];
    if ($confirm && $action === 'park') {
        try {
            $db->beginTransaction();
            $res = bs_set_request_status($db, (int)$r['id'], PK_STATUS, '', '', 0,
                                         ['tl_title' => 'Migrated from Lost (agent_request) to Parked',
                                          'tl_body'  => 'Lost on ' . ($lost ? date('d M Y', strtotime((string)$lost)) : '?') . ' — ' . $why]);
            if (empty($res['ok'])) throw new RuntimeException($res['error'] ?? 'refused');
            $db->prepare("UPDATE requests SET parked_at = ?, parked_from = 'Quoted', parked_by = NULL WHERE id = ?")
               ->execute([$lost, (int)$r['id']]);
            $db->commit();
            $item['result'] = 'parked';
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $item['result'] = 'ERROR: ' . $e->getMessage();
        }
    }
    $out[] = $item;
}

$cols = ['id', 'folder', 'agent', 'agency', 'lost_date', 'trip_date', 'action', 'why', 'result'];
if ($csv !== '') {
    $fh = fopen($csv, 'w');
    fputcsv($fh, $cols);
    foreach ($out as $o) fputcsv($fh, array_map(function ($c) use ($o) { return (string)$o[$c]; }, $cols));
    fclose($fh);
}
foreach ($out as $o) {
    printf("%-5d %-48s %-10s %-14s lost %-10s trip %-10s %-5s %s %s\n", $o['id'], mb_substr((string)$o['folder'], 0, 48), mb_substr((string)$o['agent'], 0, 10),
           mb_substr((string)$o['agency'], 0, 14), $o['lost_date'], $o['trip_date'], $o['action'], $o['why'], $o['result']);
}
$nPark = count(array_filter($out, function ($o) { return $o['action'] === 'park'; }));
echo "\n" . count($out) . " Lost/agent_request — park: $nPark, leave: " . (count($out) - $nPark)
   . ($confirm ? " — WRITTEN" : " — dry run, nothing written (add --confirm, first with one --id=N)") . "\n";
