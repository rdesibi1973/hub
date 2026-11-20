<?php
/**
 * reassign_service.php — change a request's seller (agent_id), shared by the request edit
 * page (request_edit.php) and the Agent API (reassign_request). Roberto, 20 Nov 2026: requests of
 * a seller who left (Alessia-Drct) must move to another seller, also from the API.
 *
 *   ra_agent_resolve()    agent id / Hub username / agent name → one agents row (ambiguous name refused)
 *   ra_channel()          channel of a request (column, else read from the folder tag)
 *   bs_reassign_request() checks → agent_id + assign_touch() + timeline → optional mail, optional folder rename
 *
 * Requires the leads config.php (db(), STATUSES) and booking_service.php. Keep PHP-7 compatible.
 */

/**
 * One agent from an id, a Hub username (rdesibi) or an agent name (exact, case-insensitive; else a
 * unique substring). → ['ok' => true, 'agent' => {id, name, active}] or
 * ['ok' => false, 'code' => 400|404, 'error', 'candidates' => [{id, name, active, username}]].
 */
function ra_agent_resolve(PDO $db, $val): array {
    $val = trim((string)$val);
    if ($val === '') return ['ok' => false, 'code' => 400, 'error' => 'agent_id or agent is required', 'candidates' => []];
    $sel = "SELECT a.id, a.name, a.active,
                   (SELECT GROUP_CONCAT(u.username) FROM users u WHERE u.agent_id = a.id) AS username
            FROM agents a";
    $pick = function (array $rows) {
        return ['ok' => true, 'agent' => ['id' => (int)$rows[0]['id'], 'name' => (string)$rows[0]['name'],
                                          'active' => (bool)$rows[0]['active']]];
    };
    $cands = function (array $rows) {
        return array_map(function ($a) {
            return ['id' => (int)$a['id'], 'name' => $a['name'], 'active' => (bool)$a['active'], 'username' => $a['username']];
        }, $rows);
    };
    if (ctype_digit($val)) {
        $st = $db->prepare("$sel WHERE a.id = ?");
        $st->execute([(int)$val]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return $rows ? $pick($rows) : ['ok' => false, 'code' => 404, 'error' => 'Agent ' . $val . ' not found', 'candidates' => []];
    }
    $st = $db->prepare("$sel WHERE a.id IN (SELECT u.agent_id FROM users u WHERE LOWER(u.username) = ? AND u.agent_id IS NOT NULL)");
    $st->execute([strtolower($val)]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) === 1) return $pick($rows);

    $st = $db->prepare("$sel WHERE LOWER(a.name) = ? ORDER BY a.active DESC, a.id");
    $st->execute([mb_strtolower($val)]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        $st = $db->prepare("$sel WHERE a.name LIKE ? ORDER BY a.active DESC, a.id");
        $st->execute(['%' . $val . '%']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    if (count($rows) === 1) return $pick($rows);
    if (!$rows) return ['ok' => false, 'code' => 404, 'error' => 'No agent matches "' . $val . '"', 'candidates' => []];
    return ['ok' => false, 'code' => 400, 'candidates' => $cands($rows),
            'error' => 'Agent "' . $val . '" is ambiguous (' . count($rows) . ' agents) — use agent_id or the Hub username'];
}

require_once __DIR__ . '/booking_service.php';   // bs_folder_retag(), timeline_log(), assign_touch()

/** Channel of a request: requests.channel, else read from the folder tag (old rows have NULL). '' = unknown. */
function ra_channel(array $r, string $folder): string {
    $ch = strtolower(trim((string)($r['channel'] ?? '')));
    if (in_array($ch, ['agency', 'direct', 'sb', 'other'], true)) return $ch;
    if (!preg_match_all('/\(([^()]*)\)/', $folder, $m)) return '';
    $tag = end($m[1]);
    if (preg_match('/-SB$/i', $tag))   return 'sb';
    if (preg_match('/-Drct$/i', $tag)) return 'direct';
    if (!empty($r['agency_id']))       return 'agency';
    if (strpos($tag, '-') === false)   return 'other';
    return '';
}

/**
 * Change the seller of a request.
 *
 * $opt: dry_run (bool), notify (bool: notify_agent_reassigned() mail to the new seller), retag_folder (bool:
 *       rename the Dropbox folder tag with folder_rename()), allow_booked (bool), note (string, timeline body),
 *       editor_user_id (int: who does it — Hub user or the API user), from_agent_id (int: the seller before the
 *       change when the caller already wrote agent_id, as request_edit.php does in its full UPDATE).
 *
 * Refused: request not found (404), agent not found (404) / inactive (400), Booked without allow_booked (409).
 * Same agent → unchanged, nothing written. Lost / Cancelled are allowed (warning).
 * Group members (group_folder set) are never retagged: warning + suggested_folder only. A failed folder rename after
 * the DB write keeps the new seller: ok stays true, folder_rename = {from, to, error}, warning added.
 *
 * → ['ok', 'code' (on failure), 'error', 'id', 'customer_name', 'folder', 'status', 'agent_from' {id, name},
 *    'agent_to' {id, name}, 'unchanged', 'effects' [] (dry run) | 'done' [], 'suggested_folder', 'warnings' [],
 *    'folder_rename' ({from, to} | {from, to, error} | null), 'notify' ({sent, error} | null)]
 */
function bs_reassign_request(PDO $db, int $reqId, int $newAgentId, array $opt = []): array {
    $dry    = !empty($opt['dry_run']);
    $editor = (int)($opt['editor_user_id'] ?? 0);
    $note   = trim((string)($opt['note'] ?? ''));
    $out = ['ok' => false, 'id' => $reqId, 'customer_name' => null, 'folder' => null, 'status' => null,
            'agent_from' => null, 'agent_to' => null, 'unchanged' => false, 'suggested_folder' => null,
            'warnings' => [], 'folder_rename' => null, 'notify' => null];
    $fail = function (int $code, string $msg) use (&$out) {
        return array_merge($out, ['ok' => false, 'code' => $code, 'error' => $msg]);
    };

    $st = $db->prepare("SELECT r.*, a.name AS agent_name, ag.nome AS agency_nome, ag.short_name AS agency_short
                        FROM requests r LEFT JOIN agents a ON a.id = r.agent_id LEFT JOIN agencies ag ON ag.id = r.agency_id
                        WHERE r.id = ?");
    $st->execute([$reqId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return $fail(404, 'Request ' . $reqId . ' not found');
    $out['customer_name'] = $r['customer_name'];
    $out['folder']        = $r['group_folder'] ? $r['group_folder'] . '/' . $r['practice_code'] : $r['practice_code'];
    $out['status']        = $r['status'];

    $fromId = array_key_exists('from_agent_id', $opt) ? (int)$opt['from_agent_id'] : (int)($r['agent_id'] ?? 0);
    $fromName = '';
    if ($fromId > 0) {
        $st = $db->prepare("SELECT name FROM agents WHERE id = ?");
        $st->execute([$fromId]);
        $fromName = (string)($st->fetchColumn() ?: '');
    }
    $out['agent_from'] = $fromId > 0 ? ['id' => $fromId, 'name' => $fromName] : null;

    $st = $db->prepare("SELECT id, name, active FROM agents WHERE id = ?");
    $st->execute([$newAgentId]);
    $to = $st->fetch(PDO::FETCH_ASSOC);
    if (!$to) return $fail(404, 'Agent ' . $newAgentId . ' not found');
    $out['agent_to'] = ['id' => (int)$to['id'], 'name' => (string)$to['name']];
    if ($fromId === $newAgentId) return array_merge($out, ['ok' => true, 'unchanged' => true]);
    if (empty($to['active'])) return $fail(400, 'Agent ' . $to['name'] . ' (' . $to['id'] . ') is inactive — inactive agents only stay on their old requests');
    if ($r['status'] === 'Booked' && empty($opt['allow_booked'])) {
        return $fail(409, 'Request ' . $reqId . ' is Booked — changing the seller changes commissions: pass "allow_booked": true only when Roberto decided it');
    }

    $warnings = [];
    if (in_array($r['status'], ['Lost', 'Cancelled'], true)) $warnings[] = 'Request is ' . $r['status'] . ' — the seller changes on a closed request (history).';

    // ── Folder tag ──
    $pc       = trim((string)($r['practice_code'] ?? ''));
    $isGroup  = trim((string)($r['group_folder'] ?? '')) !== '';
    $suggest  = null;
    if ($pc !== '') {
        $channel = ra_channel($r, $pc);
        $short   = $r['agency_short'] ?: $r['agency_nome'];
        $suggest = $channel !== '' ? bs_folder_retag($pc, $channel, $short, (string)$to['name'], $fromName) : null;
        if ($suggest === $pc) $suggest = null;   // tag already right (e.g. renamed with the form)
        if ($channel === '') $warnings[] = 'Folder tag of "' . $pc . '" not rebuilt: channel unknown — rename it with rename_folder.';
    }
    $out['suggested_folder'] = $suggest;
    $retag = false;
    if ($suggest !== null) {
        if ($isGroup) {
            $warnings[] = 'Group member: the folder is never retagged automatically — rename "' . $pc . '" → "' . $suggest . '" by hand if needed (rename_folder scope member).';
        } elseif (empty($opt['retag_folder'])) {
            $warnings[] = 'The folder still names the old seller: "' . $pc . '" → suggested "' . $suggest . '" (BackOffice "Rename…", or API retag_folder: true / rename_folder).';
        } else {
            $retag = true;
            if ($dry) {
                require_once __DIR__ . '/folder_service.php';
                $v = fs_rename_validate($db, $reqId, $suggest);
                if (!$v['ok']) { $retag = false; $warnings[] = 'Folder rename would be refused: ' . $v['error']; }
            }
        }
    }

    // ── Open sales follow-up lines (not moved in this version) ──
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM booking_followups WHERE request_id = ? AND closed_at IS NULL AND audience = 'sales'");
        $st->execute([$reqId]);
        $n = (int)$st->fetchColumn();
        if ($n > 0) $warnings[] = $n . ' open Booking follow-up line(s) for sales stay with their current owner — reassign them in Booking follow-up if needed (followup_list request_id=' . $reqId . ').';
    } catch (Throwable $e) { /* no follow-up table yet */ }

    $title   = 'Reassigned: ' . ($fromName !== '' ? $fromName : '(none)') . ' → ' . $to['name'];
    $effects = ['requests.agent_id: ' . ($fromId > 0 ? $fromName . ' (' . $fromId . ')' : '(none)') . ' → ' . $to['name'] . ' (' . $to['id'] . ')',
                'requests.assigned_at = now (sales responsiveness KPIs)',
                'timeline event status_change "' . $title . '"' . ($note !== '' ? ' with the note' : '')];
    $effects[] = !empty($opt['notify']) ? 'mail to ' . $to['name'] . ' (request reassigned to you)' : 'no mail (notify false)';
    if ($retag) $effects[] = 'Dropbox folder renamed: ' . $pc . ' → ' . $suggest . ' (folder_rename: booking gate, verify-after-write, CK tracker)';

    if ($dry) return array_merge($out, ['ok' => true, 'effects' => $effects, 'warnings' => $warnings]);

    // ── Write ──
    if ((int)($r['agent_id'] ?? 0) !== $newAgentId) {
        $db->prepare("UPDATE requests SET agent_id = ? WHERE id = ?")->execute([$newAgentId, $reqId]);
    }
    assign_touch($db, $reqId);
    timeline_log($reqId, 'status_change', $title, ['body' => $note !== '' ? $note : null,
                                                   'refs' => ['agent_from' => $fromId ?: null, 'agent_to' => $newAgentId]]);
    $done = array_slice($effects, 0, 3);

    if (!empty($opt['notify'])) {
        require_once __DIR__ . '/../notifications.php';
        $out['notify'] = notify_agent_reassigned($db, $newAgentId, $editor, $reqId, (string)$r['customer_name']);
        $done[] = $out['notify']['sent'] ? 'mail sent to ' . $to['name'] : 'no mail sent' . ($out['notify']['error'] ? ': ' . $out['notify']['error'] : ' (own agent identity)');
        if ($out['notify']['error']) $warnings[] = $out['notify']['error'];
    }

    if ($retag) {
        require_once __DIR__ . '/folder_service.php';
        try {
            $rn = folder_rename($db, $reqId, $suggest, $editor ?: null);
        } catch (Throwable $e) {
            $rn = ['ok' => false, 'msg' => $e->getMessage()];
        }
        if (!empty($rn['ok'])) {
            $out['folder_rename'] = ['from' => $rn['dropbox_path_old'] ?? $pc, 'to' => $rn['dropbox_path_new'] ?? $suggest];
            $out['suggested_folder'] = null;
            $done[] = 'Dropbox folder renamed: ' . $out['folder_rename']['from'] . ' → ' . $out['folder_rename']['to'];
        } else {
            $out['folder_rename'] = ['from' => $pc, 'to' => $suggest, 'error' => (string)($rn['msg'] ?? 'rename failed')];
            $warnings[] = 'Seller changed, but the folder was not renamed: ' . $out['folder_rename']['error'] . ' — retry with rename_folder.';
        }
    }
    return array_merge($out, ['ok' => true, 'done' => $done, 'warnings' => $warnings]);
}
