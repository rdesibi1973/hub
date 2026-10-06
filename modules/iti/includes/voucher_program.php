<?php
/**
 * modules/iti/includes/voucher_program.php
 *
 * Vouchers of an ITI program (personal / built from the Calc), from the Hub data instead of
 * the WeTu files: one accommodation voucher per stay (consecutive nights in one lodge, own
 * arrangements left out), one per flight and one per transfer. Same model and renderers as
 * vouchers.php (voucher_lib.php). Used by the "Vouchers" button of the program editor and by
 * the Agent API (iti_vouchers).
 *
 * Vouchers are in English, like the WeTu ones.
 */
require_once __DIR__ . '/iti_functions.php';
require_once __DIR__ . '/voucher_lib.php';

/** Meal basis code of the night after day $i: AI / FB / HB / BB / RO, from the day flags. */
function voucher_prog_meal(array $days, int $i): string
{
    $d = $days[$i];
    $next = $days[$i + 1] ?? $d;   // breakfast (and lunch) of the next morning
    if (!empty($d['meal_all_inclusive'])) return 'AI';
    $dinner = !empty($d['meal_dinner']);
    $bfast = !empty($next['meal_breakfast']);
    $lunch = !empty($next['meal_lunch']) || !empty($d['meal_lunch']);
    if ($dinner && $bfast && $lunch) return 'FB';
    if ($dinner && $bfast) return 'HB';
    if ($bfast) return 'BB';
    return 'RO';
}

/** "Arusha Airport [ARK]", for the transfer notes and the flight headline. */
function voucher_prog_airport(?string $name, ?string $code): string
{
    $name = trim((string)$name); $code = strtoupper(trim((string)$code));
    return $code !== '' && strpos($name, '[') === false ? trim($name . ' [' . $code . ']') : $name;
}

/** "Kilimanjaro Airport – Arusha Explorers Lodge, about 1 h" → [from, to] (to = '' when not split). */
function voucher_prog_split_transfer(string $txt): array
{
    $t = trim(preg_replace('/,\s*(about|approx\.?|circa|ca\.?|environ|aprox\.?)\b.*$/iu', '', $txt));
    $parts = preg_split('/\s+(?:–|—|-|→|->|to)\s+/u', $t, 2);
    return count($parts) === 2 ? [trim($parts[0]), trim($parts[1])] : [$t, ''];
}

/**
 * The voucher model of program $pid (same shape as voucher_build_model()), plus 'warnings'
 * (what to check before sending: no phone / GPS, transfer text not split, no guests…).
 * InvalidArgumentException: program not found / no start date.
 */
function voucher_model_from_program(PDO $db, int $pid): array
{
    if (function_exists('iti_ensure_doc_columns')) iti_ensure_doc_columns();   // room_type, pax_teens
    $p = iti_get_program($pid);
    if (!$p) throw new InvalidArgumentException('Program ' . $pid . ' not found');
    if (empty($p['start_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$p['start_date'])) {
        throw new InvalidArgumentException('Program ' . $pid . ' has no start date — set it first (the vouchers need the dates)');
    }
    $date = function (int $dayNo) use ($p): string { return date('Y-m-d', strtotime($p['start_date'] . ' +' . ($dayNo - 1) . ' days')); };
    $warn = [];

    $booking = [];
    try {
        $st = $db->prepare('SELECT * FROM iti_program_booking WHERE program_id = ?');
        $st->execute([$pid]);
        $booking = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) { /* no booking table: a proposal */ }

    // Travellers: the guests (Calc), else the client name of the Hub request.
    $travellers = [];
    try {
        $st = $db->prepare('SELECT title, full_name, country FROM iti_program_guests WHERE program_id = ? ORDER BY sort_order, id');
        $st->execute([$pid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
            if (trim((string)$g['full_name']) === '') continue;
            $travellers[] = ['title' => voucher_title_case((string)$g['title']), 'name' => trim((string)$g['full_name']), 'country' => (string)$g['country']];
        }
    } catch (PDOException $e) { /* no guests table */ }
    if (!$travellers && !empty($p['lead_request_id'])) {
        $st = $db->prepare('SELECT customer_name FROM requests WHERE id = ?');
        $st->execute([(int)$p['lead_request_id']]);
        if (($n = trim((string)$st->fetchColumn())) !== '') $travellers[] = ['title' => '', 'name' => $n, 'country' => ''];
    }
    if (!$travellers) $warn[] = 'No guest names: the "Travellers" line is empty.';

    $a = (int)$p['pax_adults']; $t = (int)($p['pax_teens'] ?? 0); $c = (int)$p['pax_children'];
    $pax = 'Adults ' . $a . ($t ? ', Teenagers ' . $t . ' (under 16)' : '') . ($c ? ', Children ' . $c . ' (under 12)' : '');

    $days = array_values(iti_get_days($pid));
    $lodgeIds = [];
    foreach ($days as $d) if (!empty($d['end_lodge_id'])) $lodgeIds[(int)$d['end_lodge_id']] = true;
    $lodges = [];
    if ($lodgeIds) {
        $in = implode(',', array_map('intval', array_keys($lodgeIds)));
        foreach ($db->query("SELECT l.*, d.name_en AS dest_name FROM iti_lodges l LEFT JOIN iti_destinations d ON d.id = l.destination_id WHERE l.id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) $lodges[(int)$r['id']] = $r;
    }

    // ── Stays: consecutive nights in the same lodge (own arrangements skipped) ──
    $dir = voucher_lodge_dir($db);
    $accommodations = []; $prevKey = null; $mealCount = [];
    foreach ($days as $i => $d) {
        $custom = trim((string)($d['end_lodge_custom'] ?? ''));
        $own = !empty($d['own_arrangement']) || strcasecmp($custom, 'Own Arrangement') === 0;
        $key = !empty($d['end_lodge_id']) ? 'L' . (int)$d['end_lodge_id'] : ($custom !== '' && !$own ? 'C' . strtolower($custom) : null);
        $room = voucher_room_label(trim((string)($d['room_type'] ?? '')) ?: trim((string)($booking['room_config'] ?? '')));
        $n = count($accommodations) - 1;
        if ($key === null || $own) { $prevKey = null; continue; }
        if ($key === $prevKey && $accommodations[$n]['room'] === $room) {
            $accommodations[$n]['checkout'] = $date((int)$d['day_number'] + 1);
            $accommodations[$n]['nights']++;
            $mealCount[$n][voucher_prog_meal($days, $i)] = ($mealCount[$n][voucher_prog_meal($days, $i)] ?? 0) + 1;
            continue;
        }
        $l = !empty($d['end_lodge_id']) ? ($lodges[(int)$d['end_lodge_id']] ?? []) : [];
        $name = $l['name'] ?? $custom;
        $gps = (!empty($l['latitude']) && !empty($l['longitude'])) ? round((float)$l['latitude'], 6) . ', ' . round((float)$l['longitude'], 6) : '';
        $acc = [
            'lodge' => $name, 'dest' => (string)($l['dest_name'] ?? ''),
            'checkin' => $date((int)$d['day_number']), 'checkout' => $date((int)$d['day_number'] + 1), 'nights' => 1,
            'meal' => '', 'room' => $room,
            'provider_name' => $name,
            'provider_phone' => trim((string)($l['phone'] ?? '')) ?: trim((string)($l['emergency_phone'] ?? '')),
            'provider_address' => trim((string)($l['address'] ?? '')),
            'gps' => $gps, 'gps_missing' => false,
        ];
        // Missing phone / address / GPS: the voucher lodge directory (as in vouchers.php).
        if (($hit = voucher_lodge_match($dir, $name)) !== null) {
            if ($acc['gps'] === '')              $acc['gps'] = (string)($hit['gps'] ?? '');
            if ($acc['provider_phone'] === '')   $acc['provider_phone'] = (string)($hit['phone'] ?? '');
            if ($acc['provider_address'] === '') $acc['provider_address'] = (string)($hit['address'] ?? '');
        }
        $accommodations[] = $acc;
        $mealCount[$n + 1] = [voucher_prog_meal($days, $i) => 1];
        $prevKey = $key;
    }
    foreach ($accommodations as $k => &$acc) {
        arsort($mealCount[$k]);
        $acc['meal'] = voucher_meal_label((string)key($mealCount[$k]));
        $acc['gps_missing'] = $acc['gps'] === '';
        $miss = array_keys(array_filter(['phone' => $acc['provider_phone'] === '', 'GPS' => $acc['gps'] === '', 'room' => $acc['room'] === '']));
        if ($miss) $warn[] = $acc['lodge'] . ': no ' . implode(', ', $miss) . ' (ITI → Lodges, or the room on the day).';
    }
    unset($acc);

    // ── Flights and transfers, day by day ──
    $flights = []; $transfers = [];
    $fltSt = $db->prepare('SELECT f.*, r.from_airport, r.from_code, r.to_airport, r.to_code, r.operator
                             FROM iti_day_flights f LEFT JOIN iti_flight_routes r ON r.id = f.flight_route_id
                            WHERE f.program_day_id = ? ORDER BY f.sort_order, f.id');
    foreach ($days as $d) {
        $day = $date((int)$d['day_number']);
        $fltSt->execute([(int)$d['id']]);
        foreach ($fltSt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $dep = voucher_prog_airport($f['from_airport'] ?? '', $f['from_code'] ?? '');
            $arr = voucher_prog_airport($f['to_airport'] ?? '', $f['to_code'] ?? '');
            if (!$f['flight_route_id']) { list($dep, $arr) = voucher_prog_split_transfer((string)$f['flight_custom']); }
            $flights[] = [
                'date' => $day, 'no' => '', 'airline' => trim((string)($f['airline_company'] ?: ($f['operator'] ?? ''))),
                'dep_airport' => $dep, 'dep_code' => voucher_airport_code($dep), 'dep_time' => substr((string)$f['departure_time'], 0, 5),
                'arr_airport' => $arr, 'arr_code' => voucher_airport_code($arr), 'arr_time' => substr((string)$f['arrival_time'], 0, 5),
            ];
        }
        $hasFlight = !empty($flights) && end($flights)['date'] === $day;
        foreach (iti_get_day_transfers((int)$d['id']) as $tr) {
            $txt = trim((string)($tr['description_en'] ?? '')) ?: trim((string)$tr['description']);
            if ($txt === '') continue;
            list($from, $to) = voucher_prog_split_transfer($txt);
            if ($to === '') $warn[] = voucher_fmt_date($day) . ': transfer "' . $txt . '" — pick-up and drop-off not separated (written "A – B").';
            $transfers[] = ['date' => $day, 'from' => $from, 'to' => $to, 'flight_no' => '', 'flight_time' => '',
                            'notes' => $to !== '' ? voucher_zanzibar_note($to, $hasFlight) : '', 'hotel_missing' => false];
        }
    }

    return [
        'ref'            => trim((string)($p['ref_number'] ?? '')) ?: trim((string)($p['title_en'] ?: $p['title_it'])),
        'consultant'     => ['name' => 'Roberto', 'phone' => '+255 768 900 199', 'email' => 'info@savannahexplorers.com'],
        'travellers'     => $travellers,
        'adults'         => $a,
        'pax_line'       => $pax,
        'dietary'        => trim((string)($booking['extra_details'] ?? '')),
        'accommodations' => $accommodations,
        'flights'        => $flights,
        'transfers'      => $transfers,
        'lodge_check'    => ['applicable' => false, 'ok' => true],
        'warnings'       => $warn,
    ];
}
