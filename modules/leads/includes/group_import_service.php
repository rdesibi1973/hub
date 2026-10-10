<?php
/**
 * group_import_service.php — import a confirmed group's Dropbox folder into `requests`,
 * shared by the Hub page "Import Group Folder" (import_folder.php + api_import_folder_parse.php)
 * and the Agent API (import_group_folder, move_group_folder).
 *
 *   Parse      : gi_parse()        folder name → fields + suggested agent
 *   Duplicates : gi_duplicates()   matches ranked exact → high → low
 *   Validate   : gi_validate()     the import form rules
 *   Import     : gi_import()       INSERT / UPDATE + "Group folder imported" timeline event
 *   API plan   : gi_values() / gi_plan()            defaults + overrides, duplicate rules, Dropbox check
 *   API move   : gi_move_plan() / gi_move_folder()  provisional Diamante folder → /001_Safari
 *
 * Requires the leads config.php (db(), STATUSES). Dropbox calls need dropbox_helper.php.
 * Keep PHP-7 compatible.
 */

require_once __DIR__ . '/folder_parser.php';
require_once __DIR__ . '/timeline_service.php';
require_once __DIR__ . '/folder_service.php';   // fs_name_warnings, bo_status_from_name, FS_BAD_CHARS_RE

/** Provisional Il Diamante set departures; a bare folder name is looked up in GI_DIAMANTE_DIR. */
const GI_DIAMANTE_BASE = '/itineraries/SafariClassic/it/Agenzia/Diamante';
const GI_DIAMANTE_DIR  = GI_DIAMANTE_BASE . '/2027-Groups';
const GI_SAFARI_DIR    = '/001_Safari';
const GI_PAYMENT       = ['', 'Deposit', 'Balance', 'Balance-Cash', 'Paid'];
const GI_MOVE_SUFFIXES = ['BALANCE', 'DEPOSIT', 'PAID'];

/** Statuses an import may set: the standard set plus "Provisional" (used by folder tags). */
function gi_statuses(): array {
    $s = array_keys(STATUSES);
    if (!in_array('Provisional', $s, true)) $s[] = 'Provisional';
    return $s;
}

/** parse_import_folder() + agent suggested by matching the handler against active agents. */
function gi_parse(PDO $db, string $folder): array {
    $parsed = parse_import_folder($folder);

    $agentSuggestId   = null;
    $agentSuggestName = '';
    if ($parsed['handler'] !== '') {
        $needle = strtolower(str_replace(' ', '', $parsed['handler']));
        $agents = $db->query("SELECT id, name FROM agents WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($agents as $a) {
            $cand = strtolower(str_replace(' ', '', $a['name']));
            if ($cand === $needle || strpos($cand, $needle) === 0) {
                $agentSuggestId   = (int)$a['id'];
                $agentSuggestName = $a['name'];
                break;
            }
        }
    }
    $parsed['agent_id_suggested']   = $agentSuggestId;
    $parsed['agent_name_suggested'] = $agentSuggestName;
    return $parsed;
}

/**
 * Requests that may already be this group. Match on: exact folder (group_folder/practice_code),
 * folder stem (status-agnostic), or same customer_name (+ start date when available).
 * Ranked exact → high → low.
 */
function gi_duplicates(PDO $db, array $parsed, string $folder): array {
    $stem      = $parsed['stem'];
    $name      = $parsed['customer_name'];
    $startDate = $parsed['start_date'];

    $sql = "SELECT id, customer_name, status, group_folder, practice_code, start_date,
                   period, confirmation_date
            FROM requests
            WHERE group_folder = ?
               OR practice_code = ?
               OR group_folder  LIKE ?
               OR practice_code LIKE ?
               OR (customer_name = ? AND ? <> '')";
    $stmt = $db->prepare($sql);
    $stmt->execute([$folder, $folder, $stem . '%', $stem . '%', $name, $name]);

    $seen    = [];
    $matches = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)$row['id'];
        if (isset($seen[$id])) continue;
        $seen[$id] = true;

        $level  = 'low';
        $reason = 'Same customer name';

        $rowFolder = (string)($row['group_folder'] ?: $row['practice_code']);
        if ($rowFolder === $folder) {
            $level  = 'exact';
            $reason = 'Identical folder already imported';
        } elseif ($stem !== '' && stripos($rowFolder, $stem) === 0) {
            $level  = 'high';
            $reason = 'Same group + dates (different status suffix)';
        } elseif ($name !== '' && strcasecmp((string)$row['customer_name'], $name) === 0
                  && $startDate && $row['start_date'] === $startDate) {
            $level  = 'high';
            $reason = 'Same group name and start date';
        }

        $matches[] = [
            'id'            => $id,
            'customer_name' => $row['customer_name'],
            'status'        => $row['status'],
            'folder'        => $rowFolder,
            'start_date'    => $row['start_date'],
            'period'        => $row['period'],
            'level'         => $level,
            'reason'        => $reason,
        ];
    }

    $rank = ['exact' => 0, 'high' => 1, 'low' => 2];
    usort($matches, function ($a, $b) use ($rank) {
        return $rank[$a['level']] <=> $rank[$b['level']];
    });
    return $matches;
}

/**
 * Import values (strings, as the form posts them): group_folder, mode, target_id, customer_name,
 * source, agent_id, destination, period, start_date, status, payment_status, pax, value_usd, notes.
 * Returns the list of errors (empty = OK).
 */
function gi_validate(PDO $db, array $v): array {
    $errors = [];
    if (trim((string)($v['group_folder'] ?? '')) === '')  $errors[] = 'Folder name is missing.';
    if (trim((string)($v['customer_name'] ?? '')) === '') $errors[] = 'Group / customer name is required.';
    if (!in_array((string)($v['status'] ?? ''), gi_statuses(), true)) $errors[] = 'Invalid status.';
    if (!in_array((string)($v['payment_status'] ?? ''), GI_PAYMENT, true)) $errors[] = 'Invalid payment status.';
    $startDate = (string)($v['start_date'] ?? '');
    if ($startDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        $errors[] = 'Invalid start date.';
    }
    $pax = (string)($v['pax'] ?? '');
    if ($pax !== '' && !ctype_digit($pax)) $errors[] = 'Pax must be a whole number.';
    $val = (string)($v['value_usd'] ?? '');
    if ($val !== '' && (!is_numeric($val) || (float)$val < 0)) $errors[] = 'Value (USD) must be a number ≥ 0.';
    $agentId = (int)($v['agent_id'] ?? 0);
    if ($agentId) {
        $chk = $db->prepare("SELECT id FROM agents WHERE id = ?");
        $chk->execute([$agentId]);
        if (!$chk->fetch()) $errors[] = 'Selected agent no longer exists.';
    }
    if (($v['mode'] ?? 'create') === 'update') {
        $chk = $db->prepare("SELECT id FROM requests WHERE id = ?");
        $chk->execute([(int)($v['target_id'] ?? 0)]);
        if (!$chk->fetch()) $errors[] = 'The request to update no longer exists.';
    }
    return $errors;
}

/**
 * Write the import (values already validated): a new request, or update request $targetId.
 * Logs a "Group folder imported" timeline event (author: the session user, or the API user).
 * Returns the request id.
 */
function gi_import(PDO $db, array $v, string $mode, int $targetId = 0): int {
    $folder        = trim((string)$v['group_folder']);
    $customerName  = trim((string)$v['customer_name']);
    $source        = trim((string)($v['source'] ?? ''));
    $agentId       = (int)($v['agent_id'] ?? 0);
    $destination   = trim((string)($v['destination'] ?? ''));
    $period        = trim((string)($v['period'] ?? ''));
    $status        = (string)$v['status'];
    $paymentStatus = (string)($v['payment_status'] ?? '');
    $pax           = trim((string)($v['pax'] ?? ''));
    $valueUsd      = trim((string)($v['value_usd'] ?? ''));
    $startDate     = trim((string)($v['start_date'] ?? ''));
    $notes         = trim((string)($v['notes'] ?? ''));

    $dropboxUrl = 'https://www.dropbox.com/home/001_Safari/' . rawurlencode($folder);
    $paxVal     = $pax      !== '' ? (int)$pax        : null;
    $valVal     = $valueUsd !== '' ? (float)$valueUsd : null;
    $startVal   = $startDate !== '' ? $startDate      : null;

    if ($mode === 'update') {
        $sql = "UPDATE requests SET
                    practice_code     = ?,
                    group_folder      = ?,
                    customer_name     = ?,
                    source            = ?,
                    agent_id          = ?,
                    destination       = ?,
                    period            = ?,
                    status            = ?,
                    payment_status    = ?,
                    start_date        = COALESCE(?, start_date),
                    pax               = COALESCE(?, pax),
                    value_usd         = COALESCE(?, value_usd),
                    confirmation_date = CURDATE(),
                    dropbox_url       = ?,
                    notes             = ?
                WHERE id = ?";
        $db->prepare($sql)->execute([
            $folder, $folder, $customerName, $source ?: null, $agentId ?: null,
            $destination ?: null, $period ?: null, $status, $paymentStatus ?: null,
            $startVal, $paxVal, $valVal, $dropboxUrl, $notes ?: null, $targetId,
        ]);
        $reqId = $targetId;
    } else {
        $sql = "INSERT INTO requests
                    (practice_code, group_folder, date_received, customer_name, source,
                     agent_id, destination, period, pax, status, payment_status, value_usd,
                     start_date, confirmation_date, notes, dropbox_url, created_at)
                VALUES (?,?,CURDATE(),?,?,?,?,?,?,?,?,?,?,CURDATE(),?,?,NOW())";
        $db->prepare($sql)->execute([
            $folder, $folder, $customerName, $source ?: null, $agentId ?: null,
            $destination ?: null, $period ?: null, $paxVal, $status, $paymentStatus ?: null,
            $valVal, $startVal, $notes ?: null, $dropboxUrl,
        ]);
        $reqId = (int)$db->lastInsertId();
    }

    $bits = array_filter([$status . ($paymentStatus !== '' ? ' / ' . $paymentStatus : ''),
                          $paxVal !== null ? $paxVal . ' pax' : '',
                          $valVal !== null ? 'USD ' . number_format($valVal, 2) : '']);
    timeline_log($reqId, 'confirmation', 'Group folder imported' . ($mode === 'update' ? ' (existing request updated)' : ''),
                 ['body' => $folder . "\n" . implode(' · ', $bits),
                  'refs' => ['dropbox_path' => GI_SAFARI_DIR . '/' . $folder]]);
    return $reqId;
}

/**
 * Import values for the API: what gi_parse() read from the folder name, overridden by $in
 * (customer_name, source, agent_id, destination, period, start_date, status, payment_status,
 * pax, value_usd, notes). Defaults: status Booked; payment status from the suffix, else Balance;
 * destination Tanzania; notes = handler / agency code / TO (as the Hub form pre-fills them).
 */
function gi_values(array $parsed, array $in): array {
    $notes = [];
    if ($parsed['handler'] !== '')       $notes[] = 'Handler: ' . $parsed['handler'];
    if ($parsed['agency_code'] !== '')   $notes[] = 'Agency code: ' . $parsed['agency_code'];
    if ($parsed['tour_operator'] !== '') $notes[] = 'TO: ' . $parsed['tour_operator'];

    $v = [
        'group_folder'   => $parsed['folder'],
        'customer_name'  => $parsed['customer_name'],
        'source'         => $parsed['tour_operator'],
        'agent_id'       => $parsed['agent_id_suggested'] ? (string)$parsed['agent_id_suggested'] : '',
        'destination'    => 'Tanzania',
        'period'         => $parsed['period'],
        'start_date'     => (string)($parsed['start_date'] ?? ''),
        'status'         => 'Booked',
        'payment_status' => $parsed['payment_status'] !== '' ? $parsed['payment_status'] : 'Balance',
        'pax'            => '',
        'value_usd'      => '',
        'notes'          => implode(' · ', $notes),
    ];
    foreach (array_keys($v) as $k) {
        if ($k === 'group_folder' || !array_key_exists($k, $in)) continue;
        $v[$k] = $in[$k] === null ? '' : trim((string)$in[$k]);
    }
    return $v;
}

/**
 * Plan an API import of folder $in['folder'] (a name in /001_Safari). Read-only.
 * $token: check that the folder exists in /001_Safari (null = skip, e.g. before a move).
 * Returns [folder, mode, target_id, parsed, values, duplicates, errors[] (→ 400),
 *          blocking[] {code: folder_missing|duplicate_exact|duplicate_high, message}, warnings[]].
 */
function gi_plan(PDO $db, array $in, ?string $token): array {
    $folder   = trim((string)($in['folder'] ?? ''));
    $mode     = ($in['mode'] ?? 'create') === 'update' ? 'update' : 'create';
    $targetId = (int)($in['target_id'] ?? 0);
    $out = ['folder' => $folder, 'mode' => $mode, 'target_id' => $targetId, 'parsed' => null, 'values' => null,
            'duplicates' => [], 'errors' => [], 'blocking' => [], 'warnings' => []];
    if ($folder === '') { $out['errors'][] = 'folder is required (the folder name in /001_Safari).'; return $out; }
    if (preg_match(FS_BAD_CHARS_RE, $folder)) {
        $out['errors'][] = 'folder must be the folder name only, without a path or \\ / : * ? " < > |.';
        return $out;
    }
    if (isset($in['mode']) && !in_array($in['mode'], ['create', 'update'], true)) $out['errors'][] = 'mode must be create or update.';
    if ($mode === 'update' && $targetId <= 0) $out['errors'][] = 'target_id is required with mode: update.';

    $parsed = gi_parse($db, $folder);
    $values = gi_values($parsed, $in);
    $out['parsed'] = $parsed;
    $out['values'] = $values;
    $out['errors'] = array_merge($out['errors'], gi_validate($db, $values + ['mode' => $mode, 'target_id' => $targetId]));
    $out['warnings'] = array_merge($parsed['errors'], $parsed['warnings']);
    if (!$parsed['agent_id_suggested'] && $values['agent_id'] === '') {
        $out['warnings'][] = 'Handler "' . $parsed['handler'] . '" not matched to an agent — pass agent_id.';
    }
    if ($values['pax'] === '')       $out['warnings'][] = 'pax not given.';
    if ($values['value_usd'] === '') $out['warnings'][] = 'value_usd not given.';

    if ($token !== null && !dropbox_path_exists($token, GI_SAFARI_DIR . '/' . $folder)) {
        $out['blocking'][] = ['code' => 'folder_missing', 'message' => 'Folder not found in Dropbox: ' . GI_SAFARI_DIR . '/' . $folder];
    }

    $dups = gi_duplicates($db, $parsed, $folder);
    $out['duplicates'] = $dups;
    $allow = !empty($in['allow_duplicate']);
    foreach ($dups as $d) {
        if ($mode === 'update' && $d['id'] === $targetId) continue;
        $what = 'Request #' . $d['id'] . ' (' . $d['customer_name'] . ', ' . $d['status'] . '): ' . $d['reason'];
        if ($mode === 'create' && $d['level'] === 'exact') {
            $out['blocking'][] = ['code' => 'duplicate_exact', 'message' => $what . ' — use mode: update with target_id ' . $d['id'] . '.'];
        } elseif ($mode === 'create' && $d['level'] === 'high' && !$allow) {
            $out['blocking'][] = ['code' => 'duplicate_high', 'message' => $what . ' — use mode: update, or allow_duplicate: true if it is really another group.'];
        } else {
            $out['warnings'][] = 'Possible duplicate: ' . $what;
        }
    }
    if ($mode === 'update' && $targetId > 0 && !in_array($targetId, array_column($dups, 'id'), true)) {
        $out['warnings'][] = 'Request #' . $targetId . ' does not look like this group (no folder / name match) — check target_id.';
    }
    return $out;
}

/**
 * Plan the move of a provisional Diamante group folder to /001_Safari. Read-only.
 * $in: source (name in GI_DIAMANTE_DIR, or a full path under GI_DIAMANTE_BASE), new_suffix
 * (BALANCE|DEPOSIT|PAID, default BALANCE), new_name (full name, overrides the suffix rule).
 * Returns [errors[] (→ 400), from, to, name, new_name,
 *          blocking[] {code: source_missing|destination_exists, message}, warnings[]].
 */
function gi_move_plan(PDO $db, array $in, string $token): array {
    $out = ['errors' => [], 'from' => null, 'to' => null, 'name' => null, 'new_name' => null, 'blocking' => [], 'warnings' => []];
    $src = trim((string)($in['source'] ?? ''));
    if ($src === '') { $out['errors'][] = 'source is required (folder name, or full path under ' . GI_DIAMANTE_BASE . '/).'; return $out; }
    if ($src[0] === '/') {
        $from = rtrim($src, '/');
        if (stripos($from, GI_DIAMANTE_BASE . '/') !== 0 || strpos($from, '/..') !== false) {
            $out['errors'][] = 'source path must be under ' . GI_DIAMANTE_BASE . '/.';
            return $out;
        }
        $name = basename($from);
    } else {
        if (preg_match(FS_BAD_CHARS_RE, $src)) { $out['errors'][] = 'source: invalid characters in the folder name.'; return $out; }
        $name = $src;
        $from = GI_DIAMANTE_DIR . '/' . $name;
    }
    if (!preg_match('/_PROVISIONAL$/i', $name)) $out['errors'][] = 'source must end with _PROVISIONAL.';

    $suffix  = strtoupper(trim((string)($in['new_suffix'] ?? 'BALANCE')));
    $newName = trim((string)($in['new_name'] ?? ''));
    if ($newName === '') {
        if (!in_array($suffix, GI_MOVE_SUFFIXES, true)) $out['errors'][] = 'new_suffix must be one of: ' . implode(', ', GI_MOVE_SUFFIXES) . '.';
        $newName = preg_replace('/_PROVISIONAL$/i', '_' . $suffix, $name);
    } else {
        if (preg_match(FS_BAD_CHARS_RE, $newName)) $out['errors'][] = 'new_name: invalid characters (\\ / : * ? " < > | are not allowed).';
        $st = bo_status_from_name($newName);
        if (!$st['matched'] || $st['status'] !== 'Booked') {
            $out['warnings'][] = 'new_name has no booking tag (_BALANCE, _DEPOSIT, _PAID …).';
        }
    }
    if (strcmp($newName, $name) === 0) $out['errors'][] = 'The new name is identical — nothing to change.';
    $out['from'] = $from; $out['name'] = $name; $out['new_name'] = $newName;
    $out['to'] = GI_SAFARI_DIR . '/' . $newName;
    if ($out['errors']) return $out;

    $out['warnings'] = array_merge($out['warnings'], fs_name_warnings($newName, $name));
    if (!dropbox_path_exists($token, $from)) {
        $out['blocking'][] = ['code' => 'source_missing', 'message' => 'Source folder not found in Dropbox: ' . $from];
    }
    if (dropbox_path_exists($token, $out['to'])) {
        $out['blocking'][] = ['code' => 'destination_exists', 'message' => 'A folder already exists at ' . $out['to'] . '.'];
    }
    $st = $db->prepare("SELECT id FROM requests WHERE group_folder = ? OR practice_code = ? LIMIT 3");
    $st->execute([$newName, $newName]);
    if ($ids = $st->fetchAll(PDO::FETCH_COLUMN)) {
        $out['warnings'][] = 'Request(s) #' . implode(', #', $ids) . ' already use this folder name in Hub.';
    }
    return $out;
}

/** Move (not copy) the folder; returns the new Dropbox path. Throws RuntimeException on a Dropbox error. */
function gi_move_folder(string $token, string $from, string $to): string {
    $meta = dropbox_move_folder($token, $from, $to);
    return (string)($meta['metadata']['path_display'] ?? $to);
}
