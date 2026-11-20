<?php
/**
 * grp_program_service.php — the group programme (Duma / Simba / Nyumbu) in GRP folder names.
 *
 * Rule (Roberto, 20 Nov 2026): a group parent folder is named
 *   …_GRP{DDMM}{Programme}(Agency-Agent)_START…
 * with the programme written exactly once, right after the code. The member sub-folder keeps
 * Client(Agency-Agent); the Calc name (…_NyumbuGRP_Calc.xlsx) is never touched.
 * DumaShort is a private safari, never the Duma group programme.
 *
 * Used by Confirm Safari (CREATE / ADD, booking_service.php), rename_folder on a group
 * (folder_service.php), the Java confirm endpoint (warning) and the grp_names report.
 * Pure string functions (no DB / Dropbox) except bo_grp_names_report().
 *
 * Keep PHP-7 compatible (no match / arrow functions / str_contains).
 */

const BO_GRP_PROGRAMS = ['Duma', 'Simba', 'Nyumbu'];

/**
 * A programme word as a whole word: Title-case ("Nyumbu") wherever it starts (also glued
 * to the client name, "AdolfoPiazzaNyumbu"), or any case after a non-letter or "GRP"
 * ("GRP0209 NYUMBU", "GRP-nyumbu"); never followed by a lowercase letter, and never
 * "DumaShort" (a private safari). Group 1 = the word.
 */
function bo_grp_prog_re(): string {
    return '/(?|(?:(?<![A-Za-z])|(?<=[Gg][Rr][Pp]))((?i:duma(?![\s_\-]?short)|simba|nyumbu))'
         . '|(Duma(?![\s_\-]?(?i:short))|Simba|Nyumbu))(?![a-z])/';
}

/** 'nyumbu' / 'NYUMBU' → 'Nyumbu'; null when not a group programme. */
function bo_grp_prog_canon(?string $w): ?string {
    $w = strtolower(trim((string)$w));
    foreach (BO_GRP_PROGRAMS as $p) { if (strtolower($p) === $w) return $p; }
    return null;
}

/** Hide the "(…)" groups (agency-agent, destination) behind tokens so they are never scanned. */
function bo_grp_mask(string $name, array &$saved): string {
    $saved = [];
    return preg_replace_callback('/\([^()]*\)/', function ($m) use (&$saved) {
        $saved[] = $m[0];
        return "\x02" . (count($saved) - 1) . "\x03";
    }, $name);
}

function bo_grp_unmask(string $s, array $saved): string {
    return preg_replace_callback('/\x02(\d+)\x03/', function ($m) use ($saved) {
        return $saved[(int)$m[1]] ?? '';
    }, $s);
}

/** Distinct programmes (canonical, in name order) written in a name, outside "(…)". */
function bo_grp_programs_in(string $name): array {
    $saved = [];
    $s = bo_grp_mask($name, $saved);
    $out = [];
    if (preg_match_all(bo_grp_prog_re(), $s, $m)) {
        foreach ($m[1] as $w) { $c = bo_grp_prog_canon($w); if ($c && !in_array($c, $out, true)) $out[] = $c; }
    }
    return $out;
}

/**
 * Remove every programme word from a masked name, with one separator before it, and a
 * "GRP" right after it that has no code of its own ("_NyumbuGRP" left over from a Calc-style
 * name). Separators left doubled at a junction are merged.
 */
function bo_grp_strip(string $s): string {
    if (!preg_match_all(bo_grp_prog_re(), $s, $m, PREG_OFFSET_CAPTURE)) return $s;
    $isSep = function ($c) { return $c === '_' || $c === '-' || $c === ' '; };
    for ($i = count($m[1]) - 1; $i >= 0; $i--) {
        $start = $m[1][$i][1];
        $end   = $start + strlen($m[1][$i][0]);
        if (preg_match('/\G[Gg][Rr][Pp](?![\s_\-]*\d{4})/', $s, $g, 0, $end)) $end += 3;   // orphan "GRP"
        // The separator before goes too, unless a word follows right away ("_NyumbuGRP0209" keeps "_").
        if ($start > 0 && $isSep($s[$start - 1]) && ($end >= strlen($s) || !ctype_alnum($s[$end]))) $start--;
        $s = substr($s, 0, $start) . substr($s, $end);
        // Junction: "a__b" → "a_b"; a leading separator goes.
        if ($start > 0 && $start < strlen($s) && $isSep($s[$start - 1]) && $isSep($s[$start])) {
            $s = substr($s, 0, $start) . substr($s, $start + 1);
        } elseif ($start === 0 && $s !== '' && $isSep($s[0])) {
            $s = substr($s, 1);
        }
    }
    return $s;
}

/**
 * Normalise a group folder name: the programme written once, right after GRP{code}.
 *
 * $code      'DDMM' to look for ('' = the first GRP code in the name).
 * $programme the programme to write (null = the one in the name). With $force, words of
 *            another programme in the name are replaced by it (the seller chose it);
 *            without, they are a conflict and nothing is guessed.
 *
 * Returns ['name' => normalised name (the input unchanged on conflict / no code),
 *          'program' => ?string, 'programs' => [found in the name], 'code' => found code or '',
 *          'conflict' => '' | message, 'changed' => bool,
 *          'normal' => bool (code + programme, already in the normalised form)].
 */
function bo_grp_normalise(string $name, string $code = '', ?string $programme = null, bool $force = false): array {
    $programme = bo_grp_prog_canon($programme);
    $found = bo_grp_programs_in($name);
    $out = ['name' => $name, 'program' => null, 'programs' => $found, 'code' => '', 'conflict' => '',
            'changed' => false, 'normal' => false];

    $others = $programme ? array_values(array_diff($found, [$programme])) : [];
    if ($programme && $others && !$force) {
        $out['conflict'] = 'Programme unclear: name says ' . implode(' / ', $others) . ', chosen ' . $programme . ' — choose one';
        return $out;
    }
    if (!$programme && count($found) > 1) {
        $out['conflict'] = 'Programme unclear: name says ' . implode(' and ', $found) . ' — choose one';
        return $out;
    }
    $prog = $programme ?: ($found[0] ?? null);
    $out['program'] = $prog;

    $saved = [];
    $s = bo_grp_strip(bo_grp_mask($name, $saved));
    $re = $code !== '' ? '/GRP[\s_\-]*(' . preg_quote($code, '/') . ')(?!\d)/i' : '/GRP[\s_\-]*(\d{4})(?!\d)/i';
    if (!preg_match($re, $s, $cm, PREG_OFFSET_CAPTURE)) return $out;   // no code: nothing to place
    $out['code'] = $cm[1][0];
    $s = substr($s, 0, $cm[0][1]) . 'GRP' . $cm[1][0] . (string)$prog . substr($s, $cm[0][1] + strlen($cm[0][0]));
    $new = bo_grp_unmask($s, $saved);

    $out['name']    = $new;
    $out['changed'] = $new !== $name;
    $out['normal']  = $prog !== null && !$out['changed'];
    return $out;
}

/**
 * Insert the group code (and programme) into a customer folder for a CREATE:
 * "X(Agency-Agent)" → "X_GRP0209Nyumbu(Agency-Agent)". A folder that already holds the
 * code or the programme (anywhere, any spelling) is cleaned, never doubled.
 * $programme is the resolved programme (field / folder / Calc): it wins over the name.
 */
function bo_grp_insert(string $cust, string $code, ?string $programme = null): string {
    $n = bo_grp_normalise($cust, $code, $programme, true);
    if ($n['code'] !== '') return $n['name'];
    // No code yet: drop the programme words, put "_GRP{code}" before the first '(' and normalise.
    $saved = [];
    $s = bo_grp_unmask(bo_grp_strip(bo_grp_mask($cust, $saved)), $saved);
    if (preg_match('/GRP[\s_\-]*' . preg_quote($code, '/') . '(?!\d)/i', $s)) return $s;   // two programmes (blocked upstream): code already there
    $paren = strpos($s, '(');
    $s = $paren !== false ? rtrim(substr($s, 0, $paren), '_- ') . '_GRP' . $code . substr($s, $paren)
                          : rtrim($s, '_- ') . '_GRP' . $code;
    return bo_grp_normalise($s, $code, $programme, true)['name'];
}

/** Programme(s) of the group Calc(s) among a folder's files ("…_NyumbuGRP_Calc.xlsx"); DumaShort is not a group. */
function bo_grp_calc_programs(array $files): array {
    $out = []; $file = null;
    foreach ($files as $f) {
        if (!preg_match('/\.xlsx?$/i', $f) || strpos($f, '~$') === 0) continue;
        if (!preg_match_all(bo_grp_prog_re(), $f, $m, PREG_OFFSET_CAPTURE)) continue;
        foreach ($m[1] as $hit) {
            if (strcasecmp(substr($f, $hit[1] + strlen($hit[0]), 3), 'GRP') !== 0) continue;   // "{Prog}GRP" only
            $c = bo_grp_prog_canon($hit[0]);
            if ($c && !in_array($c, $out, true)) { $out[] = $c; if ($file === null) $file = $f; }
        }
    }
    return ['programs' => $out, 'file' => $file];
}

/**
 * Programme for a Confirm Safari, from (in this order) the field, the request folder name,
 * the group Calc at the top of the request folder.
 * Returns ['program' => ?string, 'source' => 'field'|'folder'|'calc'|'', 'conflict' => '' | message,
 *          'note' => '' | warning (field chosen against the folder / Calc)].
 */
function bo_grp_resolve_program(?string $field, array $folderProgs, array $calcProgs): array {
    $out = ['program' => null, 'source' => '', 'conflict' => '', 'note' => ''];
    if ($field) {
        $out['program'] = $field; $out['source'] = 'field';
        $diff = [];
        if ($d = array_diff($folderProgs, [$field])) $diff[] = 'folder says ' . implode(' / ', $d);
        if ($d = array_diff($calcProgs, [$field]))   $diff[] = 'Calc says ' . implode(' / ', $d);
        if ($diff) $out['note'] = 'Programme ' . $field . ' chosen, but the ' . implode(' and the ', $diff)
                                . ' — the group is named ' . $field . '. Check it is right.';
        return $out;
    }
    if (count($folderProgs) > 1) {
        $out['conflict'] = 'Programme unclear: name says ' . implode(' and ', $folderProgs) . ' — choose one';
        return $out;
    }
    if (count($folderProgs) === 1) {
        $p = $folderProgs[0];
        if ($d = array_values(array_diff($calcProgs, [$p]))) {
            $out['conflict'] = 'Programme unclear: name says ' . $p . ', Calc says ' . implode(' / ', $d) . ' — choose one';
            return $out;
        }
        $out['program'] = $p; $out['source'] = 'folder';
        return $out;
    }
    if (count($calcProgs) > 1) {
        $out['conflict'] = 'Programme unclear: Calc files say ' . implode(' and ', $calcProgs) . ' — choose one';
        return $out;
    }
    if (count($calcProgs) === 1) { $out['program'] = $calcProgs[0]; $out['source'] = 'calc'; }
    return $out;
}

/**
 * Split the /001_Safari folder names holding GRP{code} by programme:
 * 'match' = same programme or none written (legacy / unclear — could be the same group),
 * 'same' = same programme only, 'other' = another programme, 'info' = name => bo_grp_normalise().
 * With $prog null every group of the code is in 'match'.
 */
function bo_grp_split(array $names, string $code, ?string $prog): array {
    $out = ['match' => [], 'same' => [], 'other' => [], 'info' => []];
    foreach ($names as $n) {
        if (stripos($n, 'GRP') === false) continue;
        $info = bo_grp_normalise($n, $code);
        if ($info['code'] === '' && $info['conflict'] === '') continue;
        if ($info['conflict'] !== '' && !preg_match('/GRP[\s_\-]*' . preg_quote($code, '/') . '(?!\d)/i', bo_grp_strip($n))) continue;
        $out['info'][$n] = $info;
        $p = $info['conflict'] !== '' ? null : $info['program'];
        if ($prog === null || $p === null) { $out['match'][] = $n; continue; }
        if ($p === $prog) { $out['match'][] = $n; $out['same'][] = $n; }
        else $out['other'][] = $n;
    }
    foreach (['match', 'same', 'other'] as $k) sort($out[$k], SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * Warning for a group rename whose new name is not in the normalised form (not blocking).
 * '' when fine or when the name has no GRP code.
 */
function bo_grp_rename_warning(string $newName): string {
    if (!preg_match('/GRP/i', $newName)) return '';
    $n = bo_grp_normalise($newName);
    if ($n['conflict'] !== '') return 'Group name: ' . $n['conflict'] . ' (only one of Duma / Simba / Nyumbu, right after GRP{DDMM}).';
    if ($n['code'] === '') return '';
    if ($n['program'] === null) return 'Group name has no programme after GRP' . $n['code'] . ' — add Duma / Simba / Nyumbu right after the code (e.g. GRP' . $n['code'] . 'Nyumbu).';
    if ($n['changed']) return 'Group name not in the normalised form — suggested: ' . $n['name'];
    return '';
}

/**
 * grp_names_report: every group folder at the top of /001_Safari whose name is not
 * normalised (no programme, wrong place / spelling, separator, duplicate, two programmes),
 * with the programme found (name, else the group Calc at the folder top) and the suggested
 * name. Read-only: nothing is renamed. Requires dropbox_helper.php.
 *
 * Returns [['folder', 'code', 'program', 'program_source' => name|calc|'', 'issue',
 *           'suggested' => ?string, 'request_id' => ?int (a member, for rename_folder scope group)], …]
 */
function bo_grp_names_report(PDO $db, string $token, int $calcLookups = 80): array {
    $rows = [];
    foreach (dropbox_list_folder($token, '/001_Safari') as $name) {
        if (stripos($name, 'GRP') === false) continue;
        $n = bo_grp_normalise($name);
        if ($n['code'] === '' && $n['conflict'] === '') continue;    // not a GRP{DDMM} group
        if ($n['normal']) continue;
        $row = ['folder' => $name, 'code' => $n['code'], 'program' => $n['program'], 'program_source' => $n['program'] ? 'name' : '',
                'issue' => '', 'suggested' => null, 'request_id' => null];
        if ($n['conflict'] !== '') {
            $row['issue'] = $n['conflict'];
        } else {
            if ($n['program'] === null && $calcLookups-- > 0) {
                try {
                    $c = bo_grp_calc_programs(dropbox_list_files($token, '/001_Safari/' . $name));
                    if (count($c['programs']) === 1) { $row['program'] = $c['programs'][0]; $row['program_source'] = 'calc'; }
                    elseif (count($c['programs']) > 1) $row['issue'] = 'Calc files say ' . implode(' and ', $c['programs']) . ' — choose one';
                } catch (Throwable $e) { /* listing failed: no programme */ }
            }
            if ($row['issue'] === '') {
                if ($row['program'] === null) {
                    $row['issue'] = 'No programme in the name or the group Calc';
                } else {
                    $fix = bo_grp_normalise($name, $n['code'], $row['program'], true);
                    $row['suggested'] = $fix['name'];
                    $row['issue'] = $n['program'] === null ? 'No programme in the name' : 'Programme not right after the code / spelling';
                }
            }
        }
        try {
            $st = $db->prepare("SELECT id FROM requests WHERE group_folder = ? ORDER BY id LIMIT 1");
            $st->execute([$name]);
            $id = $st->fetchColumn();
            $row['request_id'] = $id ? (int)$id : null;
        } catch (Throwable $e) { /* no DB: no member id */ }
        $rows[] = $row;
    }
    usort($rows, function ($a, $b) { return strnatcasecmp($a['folder'], $b['folder']); });
    return $rows;
}
