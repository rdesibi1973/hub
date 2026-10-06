<?php
/**
 * iti_final.php — ITI final programme built from a confirmed booking's Calc.
 * See ITI_FINAL_PROGRAMME_HANDOFF.md §5.
 *
 *   iti_final_plan()      Calc + sample → per-night plan: lodges / activities /
 *                         routes resolved through the Calc aliases, nights aligned
 *                         with the sample's days, blocking items and flags.
 *   iti_final_generate()  duplicate the sample into a PERSONAL programme with
 *                         stage='final' and overlay the Calc (dates, nights,
 *                         lodges, meals, activities, booking data, guests).
 *
 * Rule: never guess. A Calc text without an alias blocks the generation until it
 * is mapped (ITI → Aliases, or inline on program_final.php). Days of the sample
 * that match the Calc keep their Wetu text; added / changed days get master text
 * and needs_review = 1.
 *
 * Needs iti_functions.php and, for calc_read_request(), the leads helpers
 * (dropbox_helper.php, includes/booking_service.php, includes/calc_service.php).
 */
require_once __DIR__ . '/iti_functions.php';

/** "01_TouristTrophy(GoWorld-Roberto)_GranSafariTanzania_Calc.xlsx" → "GranSafariTanzania". */
function iti_final_code_from_calc(string $file): string {
    $base = preg_replace('/_calc\.xlsx$/i', '', basename($file));
    $p = strrpos($base, ')');
    if ($p !== false) return trim(substr($base, $p + 1), '_ ');
    $parts = explode('_', $base, 3);          // no "(agency)": NN_Name_Code
    return count($parts) === 3 ? $parts[2] : $base;
}

/** The sample tagged with this Calc code (prefer the display language), or null. */
function iti_final_find_sample(PDO $db, string $code, string $lang) {
    if ($code === '') return null;
    $st = $db->prepare("SELECT id, title_en, title_it, display_language, duration_days FROM iti_programs
                         WHERE program_type = 'sample' AND status <> 'cancelled' AND hub_program_code = ?
                         ORDER BY (display_language = ?) DESC, id DESC LIMIT 1");
    $st->execute(array($code, $lang));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? $r : null;
}

/** Meal flags [breakfast, lunch, dinner, all_inclusive] for a meal basis code. */
function iti_final_meals(?string $basis): ?array {
    $m = array('BB' => array(1, 0, 0, 0), 'HB' => array(1, 0, 1, 0), 'FB' => array(1, 1, 1, 0), 'AI' => array(1, 1, 1, 1));
    return ($basis !== null && isset($m[$basis])) ? $m[$basis] : null;
}

/**
 * LCS alignment of two key lists. Returns [calcIndex => sampleIndex] for the
 * matched pairs (keys compared with ===; unique keys never match).
 */
function iti_final_align(array $a, array $b): array {
    $n = count($a); $m = count($b);
    $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            $L[$i][$j] = ($a[$i] === $b[$j]) ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
        }
    }
    $map = array();
    for ($i = 0, $j = 0; $i < $n && $j < $m; ) {
        if ($a[$i] === $b[$j]) { $map[$i] = $j; $i++; $j++; }
        elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) $i++;
        else $j++;
    }
    return $map;
}

/**
 * The plan: one entry per Calc day row, with its resolved lodge / activities /
 * route, the matching sample day (or null = new day) and flags.
 * $calc = calc_read_file() / calc_read_request() output. $sampleId 0 = no sample yet.
 * @return array{days:array, blocking:array, unmapped:array, flags:string[], sample_days:int}
 */
function iti_final_plan(PDO $db, array $calc, int $sampleId): array {
    iti_ensure_final_schema();
    $days = array(); $unmapped = array('lodge' => array(), 'activity' => array(), 'route' => array());
    $n = count($calc['days']);
    $lodgeInfo = function ($id) use ($db) {
        static $cache = array();
        if (!isset($cache[$id])) {
            $st = $db->prepare('SELECT l.id, l.name, l.destination_id, d.name_en AS dest_name FROM iti_lodges l
                                 LEFT JOIN iti_destinations d ON d.id = l.destination_id WHERE l.id = ?');
            $st->execute(array($id));
            $cache[$id] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return $cache[$id];
    };

    foreach ($calc['days'] as $i => $cd) {
        $last = ($i === $n - 1);
        $d = array('i' => $i, 'date' => $cd['date'], 'label' => $cd['label'], 'hotel_text' => $cd['hotel_text'],
                   'invoice' => $cd['invoice'], 'checked' => $cd['checked'], 'flight_cost' => $cd['flight_cost'],
                   'last' => $last, 'lodge_id' => null, 'lodge_name' => '', 'lodge_custom' => null, 'dest_id' => null,
                   'meal' => null, 'lodge_state' => 'none', 'acts' => array(), 'route' => null,
                   'sample_index' => null, 'flags' => array());

        // Lodge (every night; the departure day normally has none).
        if ($cd['hotel_text'] !== '') {
            $a = iti_alias_lookup('lodge', $cd['hotel_text']);
            if ($a === null) {
                $d['lodge_state'] = 'unmapped';
                $unmapped['lodge'][iti_alias_norm($cd['hotel_text'])] = $cd['hotel_text'];
            } elseif (!$a['lodge_id']) {
                $d['lodge_state'] = 'nothing';
                $d['lodge_custom'] = $cd['hotel_text'];        // e.g. "Riu Palace Nungwi - own arrangement"
            } else {
                $li = $lodgeInfo((int)$a['lodge_id']);
                $d['lodge_state'] = $li ? 'ok' : 'unmapped';
                if ($li) {
                    $d['lodge_id'] = (int)$li['id']; $d['lodge_name'] = $li['name'];
                    $d['dest_id'] = $li['destination_id'] ? (int)$li['destination_id'] : null;
                }
                $d['meal'] = $a['meal_basis'] ?: null;
            }
        } elseif (!$last) {
            $d['flags'][] = 'Night without hotel in the Calc (col K).';
        }

        // Activities: ACTIVITY DESC + FEES DESC, split on '+'.
        foreach (array_merge(iti_alias_parts($cd['activity_desc']), iti_alias_parts($cd['park_fees_desc'])) as $part) {
            // Picnic lunch cost line, never an activity: not asked, no alias needed.
            if (preg_match('/^lunch ?box(es)?$/', iti_alias_norm($part))) {
                $d['acts'][] = array('text' => $part, 'state' => 'nothing', 'activity_id' => null);
                continue;
            }
            $a = iti_alias_lookup('activity', $part);
            if ($a === null) {
                $unmapped['activity'][$part] = $part;
                $d['acts'][] = array('text' => $part, 'state' => 'unmapped', 'activity_id' => null);
            } else {
                $d['acts'][] = array('text' => $part, 'state' => $a['activity_id'] ? 'ok' : 'nothing',
                                     'activity_id' => $a['activity_id'] ? (int)$a['activity_id'] : null);
            }
        }

        // Route (PARK/OVERNIGHT label) — required only for days the sample does not cover (below).
        if ($cd['label'] !== '') {
            $a = iti_alias_lookup('route', $cd['label']);
            $d['route'] = array('text' => $cd['label'], 'state' => $a === null ? 'unmapped' : 'ok',
                                'transfer_route_id' => $a && $a['transfer_route_id'] ? (int)$a['transfer_route_id'] : null,
                                'flight_route_id' => $a && $a['flight_route_id'] ? (int)$a['flight_route_id'] : null);
        }
        $days[] = $d;
    }

    // Align with the sample's days on the lodge sequence.
    $sampleDays = array();
    if ($sampleId) {
        $st = $db->prepare('SELECT id, day_number, end_lodge_id FROM iti_program_days WHERE program_id = ? ORDER BY day_number');
        $st->execute(array($sampleId));
        $sampleDays = $st->fetchAll(PDO::FETCH_ASSOC);
        $ka = array(); $kb = array();
        foreach ($days as $i => $d) {
            $ka[] = $d['lodge_id'] ? 'L' . $d['lodge_id'] : (($d['last'] && $d['hotel_text'] === '') ? 'END' : 'c' . $i);
        }
        $m = count($sampleDays);
        foreach ($sampleDays as $j => $s) {
            $kb[] = $s['end_lodge_id'] ? 'L' . $s['end_lodge_id'] : ($j === $m - 1 ? 'END' : 's' . $j);
        }
        foreach (iti_final_align($ka, $kb) as $i => $j) $days[$i]['sample_index'] = $j;
    }

    $blocking = array();
    foreach ($unmapped['lodge'] as $t) $blocking[] = 'Hotel "' . $t . '" has no lodge alias.';
    foreach ($unmapped['activity'] as $t) $blocking[] = 'Activity "' . $t . '" has no activity alias.';
    foreach ($days as $i => $d) {
        if ($sampleId && $d['sample_index'] === null) {
            $days[$i]['flags'][] = 'Not in the sample: added from the Calc (text to review).';
            if ($d['route'] && $d['route']['state'] === 'unmapped') {
                $unmapped['route'][iti_alias_norm($d['route']['text'])] = $d['route']['text'];
            }
        }
        if ($d['flight_cost'] !== null && $d['flight_cost'] > 0 && !($d['route'] && $d['route']['flight_route_id']) && ($d['sample_index'] === null)) {
            $days[$i]['flags'][] = 'The Calc has a flight cost this day — check the flight in the program.';
        }
    }
    foreach ($unmapped['route'] as $t) $blocking[] = 'Route "' . $t . '" (a day not in the sample) has no route alias.';

    $flags = array();
    if (!empty($calc['guests_tba'])) $flags[] = 'Guests not known yet (TBA) — the program will say "to be defined".';
    if ($sampleId && count($sampleDays) !== count($days)) {
        $flags[] = 'The sample has ' . count($sampleDays) . ' days, the Calc ' . count($days) . ' — days are added / removed to match the Calc.';
    }
    return array('days' => $days, 'blocking' => $blocking, 'unmapped' => $unmapped, 'flags' => $flags,
                 'sample_days' => count($sampleDays));
}

/**
 * Build the final programme. $calc must come from calc_read_request() (calc_path,
 * calc_rev). Throws RuntimeException when the plan still has blocking items.
 * $who = ['id' => users.id, 'username' => ...]. Returns ['program_id', 'superseded' => int[], 'plan'].
 */
function iti_final_generate(PDO $db, int $requestId, array $calc, int $sampleId, string $lang, array $who): array {
    iti_ensure_final_schema();
    if (!in_array($lang, ITI_LANGUAGES, true)) $lang = 'it';
    $plan = iti_final_plan($db, $calc, $sampleId);
    if (!$sampleId) throw new RuntimeException('Choose the sample program first.');
    if ($plan['blocking']) throw new RuntimeException('Map these Calc texts first: ' . implode(' ', $plan['blocking']));
    $sample = iti_get_program($sampleId);
    if (!$sample || $sample['program_type'] !== 'sample') throw new RuntimeException('Sample #' . $sampleId . ' not found.');

    $pax = $calc['pax'];
    $newId = iti_duplicate_program($sampleId, 'personal', (string)($who['username'] ?? 'system'), array(
        'title_en'         => $sample['title_en'],
        'lead_request_id'  => $requestId,
        'ref_number'       => iti_lead_ref_number($requestId, (string)($calc['calc_path'] ?? '')),
        'stage'            => 'final',
        'start_date'       => $calc['start_date'],
        'duration_days'    => count($calc['days']),
        'pax_adults'       => max(1, (int)$pax['adults']),
        'pax_teens'        => (int)$pax['teen'],      // under 16 (column from iti_ensure_doc_columns())
        'pax_children'     => (int)$pax['child'],     // under 12
        'display_language' => $lang,
        'hub_program_code' => null,
        'source_calc_path' => $calc['calc_path'] ?? null,
        'source_calc_rev'  => $calc['calc_rev'] ?? null,
        'generated_at'     => date('Y-m-d H:i:s'),
        'generated_by'     => !empty($who['id']) ? (int)$who['id'] : null,
        'superseded_by'    => null,
        'superseded_at'    => null,
    ));

    iti_transfers_schema($db);   // DDL: before the transaction (transfer texts in every language below)
    try {
        $db->beginTransaction();
        $st = $db->prepare('SELECT * FROM iti_program_days WHERE program_id = ? ORDER BY day_number');
        $st->execute(array($newId));
        $copied = $st->fetchAll(PDO::FETCH_ASSOC);          // same order as the sample's days
        // Free the day numbers, then drop the sample days the Calc does not use.
        $db->prepare('UPDATE iti_program_days SET day_number = -day_number WHERE program_id = ?')->execute(array($newId));
        $used = array();
        foreach ($plan['days'] as $d) if ($d['sample_index'] !== null) $used[$d['sample_index']] = true;
        foreach ($copied as $j => $row) {
            if (isset($used[$j])) continue;
            foreach (array('iti_day_activities', 'iti_day_flights', 'iti_day_transfers') as $t) {
                $db->prepare('DELETE FROM ' . $t . ' WHERE program_day_id = ?')->execute(array((int)$row['id']));
            }
            $db->prepare('DELETE FROM iti_program_days WHERE id = ?')->execute(array((int)$row['id']));
        }

        $dayCols = iti_table_columns('iti_program_days');
        $destDesc = function ($destId) use ($db, $lang) {
            if (!$destId) return '';
            $st = $db->prepare('SELECT description_' . $lang . ' FROM iti_destinations WHERE id = ?');
            $st->execute(array($destId));
            return trim((string)$st->fetchColumn());
        };
        $routeName = function ($trId) use ($db) {
            $st = $db->prepare('SELECT fd.name_en AS f, td.name_en AS t, tr.duration_min, tr.distance_km FROM iti_transfer_routes tr
                                  JOIN iti_destinations fd ON fd.id = tr.from_destination
                                  JOIN iti_destinations td ON td.id = tr.to_destination WHERE tr.id = ?');
            $st->execute(array($trId));
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        };
        $update = function (int $dayId, array $vals) use ($db, $dayCols) {
            $vals = array_intersect_key($vals, array_flip($dayCols));
            if (!$vals) return;
            $set = array();
            foreach (array_keys($vals) as $c) $set[] = '`' . $c . '` = ?';
            $db->prepare('UPDATE iti_program_days SET ' . implode(', ', $set) . ' WHERE id = ?')
               ->execute(array_merge(array_values($vals), array($dayId)));
        };

        $prevLodge = null; $prevCustom = null;
        $nDays = count($plan['days']);
        foreach ($plan['days'] as $i => $d) {
            $isNew = $d['sample_index'] === null;
            $vals = array(
                'day_number'     => $i + 1,
                'start_lodge_id' => $prevLodge,
                'start_custom'   => $prevLodge ? null : $prevCustom,
                'end_lodge_id'   => $d['lodge_id'],
                'end_lodge_custom' => $d['lodge_id'] ? null : $d['lodge_custom'],
                'room_type'      => ($d['lodge_id'] || $d['lodge_custom']) && $calc['room_config'] !== '' ? mb_substr($calc['room_config'], 0, 100) : null,
                'booked_status'  => $d['invoice'] !== '' ? mb_substr($d['invoice'], 0, 40) : null,
                'booked_by'      => $d['checked'] !== '' ? mb_substr($d['checked'], 0, 60) : null,
                'needs_review'   => $isNew ? 1 : 0,
                'review_note'    => $isNew ? 'Added from the Calc (not in the sample): check title, text, transfer and meals.' : null,
            );
            $meals = iti_final_meals($d['meal']);
            if ($isNew && $meals === null) $meals = array(1, 1, 1, 0);   // default full board, flagged for review
            if ($meals !== null) {
                $vals['meal_breakfast']     = $i === 0 ? 0 : $meals[0];
                $vals['meal_lunch']         = $meals[1];
                $vals['meal_dinner']        = $i === $nDays - 1 ? 0 : $meals[2];
                $vals['meal_all_inclusive'] = $meals[3];
            }
            if ($isNew) {
                if ($d['dest_id']) $vals['destination_id'] = $d['dest_id'];
                foreach (ITI_LANGUAGES as $l) { $vals['day_title_' . $l] = ''; $vals['narrative_' . $l] = ''; }
                $vals['day_title_' . $lang] = mb_substr($d['label'], 0, 200);
                $vals['narrative_' . $lang] = $destDesc($d['dest_id']);
                $vals['program_id'] = $newId;
                $vals = array_intersect_key($vals, array_flip($dayCols));
                $db->prepare('INSERT INTO iti_program_days (`' . implode('`,`', array_keys($vals)) . '`) VALUES ('
                             . implode(',', array_fill(0, count($vals), '?')) . ')')->execute(array_values($vals));
                $dayId = (int)$db->lastInsertId();
                // Transfer / flight of an added day, from its route alias.
                if ($d['route'] && $d['route']['transfer_route_id'] && ($r = $routeName($d['route']['transfer_route_id']))) {
                    $notes = iti_build_transfer_notes($r['f'], $r['t'], (int)$r['duration_min'], $r['distance_km'] !== null ? (int)$r['distance_km'] : 0);
                    $tr = array('description' => $notes[$lang] ?? $notes['en'], 'tr' => $notes);   // all languages at once
                    iti_transfers_replace($db, $dayId, array($tr));
                }
                if ($d['route'] && $d['route']['flight_route_id']) {
                    $db->prepare('INSERT INTO iti_day_flights (program_day_id, flight_route_id, sort_order) VALUES (?,?,1)')
                       ->execute(array($dayId, $d['route']['flight_route_id']));
                }
            } else {
                $dayId = (int)$copied[$d['sample_index']]['id'];
                $update($dayId, $vals);
            }

            // Calc activities not already on the day (same activity, or its name in a custom text).
            $st = $db->prepare('SELECT activity_id, activity_custom FROM iti_day_activities WHERE program_day_id = ?');
            $st->execute(array($dayId));
            $have = $st->fetchAll(PDO::FETCH_ASSOC);
            $sort = count($have);
            foreach ($d['acts'] as $a) {
                if (!$a['activity_id']) continue;
                $an = $db->prepare('SELECT name_en, name_it FROM iti_activities WHERE id = ?');
                $an->execute(array($a['activity_id']));
                $names = array_filter(array_map('mb_strtolower', array_values($an->fetch(PDO::FETCH_ASSOC) ?: array())));
                $names[] = $a['text'];
                $dup = false;
                foreach ($have as $h) {
                    if ((int)$h['activity_id'] === $a['activity_id']) { $dup = true; break; }
                    $txt = mb_strtolower((string)$h['activity_custom']);
                    foreach ($names as $nm) { if ($nm !== '' && $txt !== '' && strpos($txt, $nm) !== false) { $dup = true; break 2; } }
                }
                if ($dup) continue;
                $db->prepare('INSERT INTO iti_day_activities (program_day_id, activity_id, sort_order) VALUES (?,?,?)')
                   ->execute(array($dayId, $a['activity_id'], ++$sort));
                $have[] = array('activity_id' => $a['activity_id'], 'activity_custom' => null);
            }

            $prevLodge = $d['lodge_id'];
            $prevCustom = $d['lodge_id'] ? null : $d['lodge_custom'];
        }

        // Booking data + guests (no passport numbers).
        $db->prepare('INSERT INTO iti_program_booking (program_id, room_config, pax_adults, pax_teen, pax_child,
                        arrival_details, departure_details, extra_details) VALUES (?,?,?,?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE room_config = VALUES(room_config), pax_adults = VALUES(pax_adults),
                        pax_teen = VALUES(pax_teen), pax_child = VALUES(pax_child), arrival_details = VALUES(arrival_details),
                        departure_details = VALUES(departure_details), extra_details = VALUES(extra_details)')
           ->execute(array($newId, $calc['room_config'] !== '' ? $calc['room_config'] : null, (int)$pax['adults'], (int)$pax['teen'],
                           (int)$pax['child'], $calc['arrival'] ?: null, $calc['departure'] ?: null, $calc['extra_details'] ?: null));
        $gi = 0;
        foreach ($calc['guests'] as $g) {
            $db->prepare('INSERT INTO iti_program_guests (program_id, sort_order, full_name, title, dob, country) VALUES (?,?,?,?,?,?)')
               ->execute(array($newId, ++$gi, mb_substr($g['name'], 0, 160), $g['title'] !== '' ? mb_substr($g['title'], 0, 10) : null,
                               $g['dob'], $g['country'] !== '' ? mb_substr($g['country'], 0, 80) : null));
        }

        // One active final per request: older ones are kept, marked superseded.
        $st = $db->prepare("SELECT id FROM iti_programs WHERE lead_request_id = ? AND stage = 'final' AND superseded_by IS NULL AND id <> ?");
        $st->execute(array($requestId, $newId));
        $old = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($old) {
            $db->prepare("UPDATE iti_programs SET superseded_by = ?, superseded_at = ? WHERE id IN (" . implode(',', $old) . ")")
               ->execute(array($newId, date('Y-m-d H:i:s')));
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        // Remove the half-built copy (days, children and booking cascade or are cleaned here).
        try {
            foreach (array('iti_day_activities', 'iti_day_flights', 'iti_day_transfers') as $t) {
                $db->prepare('DELETE c FROM ' . $t . ' c JOIN iti_program_days d ON d.id = c.program_day_id WHERE d.program_id = ?')->execute(array($newId));
            }
            $db->prepare('DELETE FROM iti_program_days WHERE program_id = ?')->execute(array($newId));
            $db->prepare('DELETE FROM iti_programs WHERE id = ?')->execute(array($newId));
        } catch (Throwable $e2) {
            error_log('iti_final_generate cleanup of #' . $newId . ': ' . $e2->getMessage());
        }
        throw $e;
    }
    return array('program_id' => $newId, 'superseded' => $old, 'plan' => $plan);
}

/** Final programmes of a request, newest first (active = not superseded). */
function iti_final_list(PDO $db, int $requestId): array {
    iti_ensure_final_schema();
    $st = $db->prepare("SELECT id, title_en, title_it, start_date, generated_at, source_calc_rev, superseded_by, superseded_at, status
                          FROM iti_programs WHERE lead_request_id = ? AND stage = 'final' ORDER BY id DESC");
    $st->execute(array($requestId));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
