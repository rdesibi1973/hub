<?php
/**
 * Booking gate (handoff 12–13 Nov 2026; modules/leads/includes/booking_gate_service.php): the deterministic parts —
 * transitions, airports / route rule, rooms, Calc annotation rows, the evaluation against the handoff's acceptance
 * cases (Cavalli included) and the passport / DOB scrub. No Dropbox, no AI, no DB.
 *   C:/Utility/PHP/php.exe -d extension_dir=C:/Utility/PHP/ext -d extension=mbstring tools/tests/booking_gate_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../modules/leads/includes/booking_gate_service.php';

$fail = 0;
$t = function (string $name, bool $ok, $got = null) use (&$fail) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $got === null ? '' : '  got: ' . json_encode($got, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
    if (!$ok) $fail++;
};
$issues = fn(array $res) => array_map(fn($f) => $f['issue'] . ($f['blocking'] ? '!' : ''), $res['findings']);

// ── Transitions ──
$p = '01_15JAN_ArturoCavalli(VolaEVa-Roberto)_START15JAN_END18JAN2027_';
$t('PROGRESS → DEPOSIT gated', bg_transition_gated($p . 'PROGRESS', $p . 'DEPOSIT'));
$t('PROGRESS → BALANCE-CASH gated', bg_transition_gated($p . 'PROGRESS', $p . 'BALANCE-CASH'));
$t('PROGRESS → PAID gated', bg_transition_gated($p . 'PROGRESS', $p . 'PAID'));
$t('DEPOSIT → BALANCE not gated', !bg_transition_gated($p . 'DEPOSIT', $p . 'BALANCE'));
$t('PROGRESS → CANCELLED not gated', !bg_transition_gated($p . 'PROGRESS', $p . 'CANCELLED'));
$t('PROGRESS → PROVISIONAL not gated', !bg_transition_gated($p . 'PROGRESS', $p . 'PROVISIONAL'));
$t('no way round: PROVISIONAL / no tag → DEPOSIT gated', bg_transition_gated($p . 'PROVISIONAL', $p . 'DEPOSIT') && bg_transition_gated(rtrim($p, '_'), $p . 'DEPOSIT'));
$t('BALANCE → PAID, DEPOSIT_CK → BALANCE_CK not gated', !bg_transition_gated($p . 'BALANCE', $p . 'PAID') && !bg_transition_gated($p . 'DEPOSIT_CK', $p . 'BALANCE_CK'));
$t('enforced by default (no constant)', bg_enforce());
// Leaving PROVISIONAL (Roberto, 13 Nov 2026; Rivolta, request 3091)
$rv = '08_18AUG_AlessandroRivolta(Nuru-Drct)_START18AUG_END29AUG2027_';
$t('PROVISIONAL / CANCELLED are tags', bg_tag($rv . 'PROVISIONAL') === 'PROVISIONAL' && bg_tag($rv . 'CANCELLED') === 'CANCELLED' && bg_tag($rv . 'DEPOSIT_CANCELLED') === 'CANCELLED');
$t('toward PROGRESS always free (from PROVISIONAL, CANCELLED, DEPOSIT)', !bg_transition_gated($rv . 'PROVISIONAL', $rv . 'PROGRESS')
   && !bg_transition_gated($rv . 'CANCELLED', $rv . 'PROGRESS') && !bg_transition_gated($rv . 'DEPOSIT', $rv . 'PROGRESS'));
$t('CANCELLED → DEPOSIT gated, CANCELLED → PROVISIONAL free', bg_transition_gated($rv . 'CANCELLED', $rv . 'DEPOSIT') && !bg_transition_gated($rv . 'CANCELLED', $rv . 'PROVISIONAL'));
$t('PROVISIONAL → DEPOSIT gated', bg_transition_gated($rv . 'PROVISIONAL', $rv . 'DEPOSIT'));
$t('PROVISIONAL → _CK added gated', bg_transition_gated($rv . 'PROVISIONAL', $rv . 'PROVISIONAL_CK'));
$t('PROVISIONAL → tag removed gated', bg_transition_gated($rv . 'PROVISIONAL', rtrim($rv, '_')));
$t('PROVISIONAL → CANCELLED free', !bg_transition_gated($rv . 'PROVISIONAL', $rv . 'CANCELLED'));
$t('PROVISIONAL rename keeping the tag free', !bg_transition_gated($rv . 'PROVISIONAL', str_replace('29AUG', '30AUG', $rv) . 'PROVISIONAL'));
$t('PROGRESS → CANCELLED free, PROGRESS → DEPOSIT still gated', !bg_transition_gated($p . 'PROGRESS', $p . 'CANCELLED') && bg_transition_gated($p . 'PROGRESS', $p . 'DEPOSIT'));
$t('Rivolta is a private folder (in scope)', !bg_is_group(['practice_code' => $rv . 'PROVISIONAL', 'group_folder' => '']));
// Groups only when named (Roberto, 13 Nov 2026): _PROVISIONAL alone is a private safari for the CK tracker and the group scan too.
$t('CK tracker: Rivolta _PROVISIONAL not a group', !ck_is_group_folder($rv . 'PROVISIONAL') && !ck_is_group_folder($rv . 'PROVISIONAL_CK'));
$t('CK tracker: GRP / Diamante / GRUPPO / group requests are groups', ck_is_group_folder('05_10MAY_GRP1005_PROVISIONAL') && ck_is_group_folder('x(Diamante-Roberto)')
   && ck_is_group_folder('05_GRUPPO Rossi_PROVISIONAL') && ck_is_group_folder('anything', true));
$t('follow-up group kind: _PROVISIONAL alone null, GRUPPO private', followup_group_kind($rv . 'PROVISIONAL') === null
   && followup_group_kind('05_GRUPPO Rossi_PROVISIONAL') === 'private' && followup_group_kind('GRP1005') === 'grp');
$t('group member not gated', bg_is_group(['group_folder' => 'GRP0101_x', 'practice_code' => 'a']) && bg_is_group(['practice_code' => '05_GRUPPO Diamante_PROGRESS'])
                             && !bg_is_group(['practice_code' => $p . 'PROGRESS']));

// ── Airports and the route rule ──
$t('airport codes', bg_airport_code('Arusha') === 'ARK' && bg_airport_code('ZNZ') === 'ZNZ' && bg_airport_code('Dar es Salaam (DAR)') === 'DAR'
                    && bg_airport_code('Zanzibar Abeid Amani Karume Intl') === 'ZNZ' && bg_airport_code('Karatu') === null
                    && bg_airport_code('Kilimanjaro International Airport') === 'JRO', [bg_airport_code('Dar es Salaam (DAR)')]);
$r = bg_calc_route('Karatu-Manyara-Arusha-ZNZ');
$t('Cavalli route: dest ZNZ, Arusha a possible origin', $r['dest'] === 'ZNZ' && in_array('ARK', $r['origins'], true), $r);
$r = bg_calc_route('Karatu-Manyara-Arusha-ZNZ', "Flight Arusha - Zanzibar 18JAN\nTK 1322 BLQIST");
$t('details fix the origin', $r['origin'] === 'ARK', $r);
$r = bg_calc_route('Kogatende - Arusha - Zanzibar');
$t('multi-leg: dest ZNZ', $r['dest'] === 'ZNZ' && $r['origins'] === ['KGT', 'ARK'], $r);

// ── Rooms / meals ──
$t('rooms parse', bg_rooms_parse('1 DBL + 2 TWN')['total'] === 3 && bg_rooms_parse('DBL')['types'] === ['dbl' => 1] && bg_rooms_parse('2 double, 1 single')['total'] === 3);
$t('rooms types', bg_rooms_same_types(['dbl' => 1], ['dbl_twn' => 1]) && !bg_rooms_same_types(['dbl' => 1], ['twn' => 1]));
$t('meal ranks', bg_calc_meal('Orangi -FB') === 3 && bg_meal_rank('Half Board') === 2 && bg_meal_rank('Bed & Breakfast') === 1);

// ── Scrub ──
$s = bg_scrub(['a' => 'Guest Mario Rossi passport no. YA1234567 DOB: 12/03/1980', 'b' => ['ref WB10062 PNR 27609551 YS113']]);
$t('passport and DOB removed, refs kept', strpos($s['a'], 'YA1234567') === false && strpos($s['a'], '1980') === false
                                         && $s['b'][0] === 'ref WB10062 PNR 27609551 YS113', $s);

// ── Calc annotation rows (Cavalli row 22) ──
$day = fn($row, $date, $label, $hotel, $extra = []) => $extra + ['row' => $row, 'date' => $date, 'date_derived' => false, 'label' => $label, 'flight_cost' => null,
        'park_fees_desc' => '', 'activity_desc' => '', 'hotel_text' => $hotel, 'invoice' => '', 'checked' => '', 'stay_cost' => null, 'act_cost' => null];
$calc = ['days' => [
    $day(17, '2027-01-15', 'Arusha', 'Arusha Explorers', ['activity_desc' => 'Transfer JRO-Arusha']),
    $day(18, '2027-01-16', 'Tarangire-Karatu', 'Marera -FB'),
    $day(19, '2027-01-17', 'Ngorongoro-Karatu', 'Marera -FB'),
    $day(20, '2027-01-18', 'Karatu-Manyara-Arusha-ZNZ', '', ['flight_cost' => 470.0]),
], 'skipped_rows' => [['row' => 22, 'text' => 'hotel "Flight link"', 'hotel' => 'Flight link', 'invoice' => 'Invoice$290', 'checked' => 'Glady', 'dated' => false, 'has_cost' => false]]];
$svcs = bc_services($calc);
$fl = array_values(array_filter($svcs, fn($s) => $s['type'] === 'flight'))[0];
$t('annotation attached to the row 20 flight, no extra service', count($svcs) === 4 && ($fl['annotations'][0]['row'] ?? 0) === 22
                                                                && $fl['calc_invoice'] === ['Invoice$290'] && $fl['calc_checked'] === ['Glady'], $svcs);
$calc2 = $calc;
$calc2['skipped_rows'][0]['has_cost'] = true;
$t('a row with a cost is not an annotation', empty(array_values(array_filter(bc_services($calc2), fn($s) => $s['type'] === 'flight'))[0]['annotations']));

// ── Evaluation ──
$snap = fn(array $services, array $calcOver = []) => ['customer' => 'ArturoCavalli(VolaEVa-Roberto)', 'folder' => '/001_Safari/x',
    'calc' => $calcOver + ['file' => '01_Calc.xlsx', 'sheet' => '2PAX', 'pax' => ['adults' => 2, 'teen' => 0, 'child' => 0], 'room_config' => '1 DBL',
                           'arrival' => 'KL569 15JAN AMSJRO 1000 2010', 'departure' => 'own arrangement', 'start_date' => '2027-01-15', 'end_date' => '2027-01-18',
                           'single_supp' => null, 'warnings' => []],
    'services' => $services, 'evidence' => []];
$stay = fn($name, $in, $out, $n, $cost = null, $own = false) => ['type' => 'accommodation', 'name' => $name, 'check_in' => $in, 'check_out' => $out, 'nights' => $n,
    'own' => $own, 'calc_invoice' => [], 'calc_checked' => [], 'rows' => [18], 'cost' => $cost];
$dsStay = fn($prop, $in, $out, $over = []) => $over + ['type' => 'stay', 'supplier' => $prop, 'property' => $prop, 'status' => 'confirmed', 'check_in' => $in, 'check_out' => $out,
    'nights_header' => null, 'nights_billed' => null, 'rooms' => [['type' => 'Double', 'count' => 1]], 'meal_plan' => null, 'adults' => 2, 'children' => 0, 'child_ages' => [],
    'service_date' => null, 'legs' => [], 'transfer_from' => null, 'transfer_to' => null, 'transfer_flight' => null, 'description' => null, 'amount_total' => null,
    'commission' => null, 'net_amount' => null, 'currency' => 'USD', 'concession_fee_on_invoice' => null, 'reference' => null, 'expiry_date' => null,
    'payment_terms' => null, 'payment_time_limit' => null];
$doc = fn($file, array $services, $clients = ['Cavalli']) => ['rel_path' => $file, 'status' => 'done', 'error' => null,
    'data' => ['document_kind' => 'invoice', 'issuer' => null, 'client_names' => $clients, 'services' => $services]];
$flightSvc = ['type' => 'flight', 'date' => '2027-01-18', 'route' => 'Karatu-Manyara-Arusha-ZNZ', 'cost' => 470.0, 'rows' => [20]];
$leg = fn($from, $to) => ['flight_no' => 'YS113', 'date' => '2027-01-18', 'from_airport' => $from, 'to_airport' => $to, 'std' => '18:30', 'sta' => '20:35'];
$dsFlight = fn($from, $to, $net, $status = 'provisional') => $dsStay(null, null, null, ['type' => 'flight', 'supplier' => 'Flightlink', 'property' => null, 'rooms' => [],
    'status' => $status, 'legs' => [$leg($from, $to)], 'net_amount' => $net, 'reference' => '27609551']);
$sv = $doc('invoices/SV WB10062.pdf', [
    $dsStay('Arusha Explorers', '2027-01-15', '2027-01-16', ['supplier' => 'The Orangi Collection']),
    $dsStay('Marera Valley Lodge', '2027-01-16', '2027-01-18', ['supplier' => 'The Orangi Collection']),
]);
$cavServices = [$stay('Arusha Explorers', '2027-01-15', '2027-01-16', 1, null, true), $stay('Marera -FB', '2027-01-16', '2027-01-18', 2, null, true), $flightSvc];

// Cavalli as found: ARK → DAR at 290.40 (below the Calc 470) → blocked on the route, amount not used.
$res = bg_evaluate($snap($cavServices), [$sv, $doc('invoices/Flightlink proforma.pdf', [$dsFlight('Arusha', 'Dar es Salaam', 290.40)])]);
$t('Cavalli: stays OK, flight route blocked', !$res['passed'] && $issues($res) === ['flight_route!', 'booking_provisional']
                                             && $res['services'][0]['status'] === 'OK' && $res['services'][1]['status'] === 'OK', [$issues($res), array_column($res['services'], 'status')]);
$t('Cavalli: the route text says ZNZ ≠ DAR', strpos($res['findings'][0]['text'], 'ZNZ ≠ DAR') !== false, $res['findings'][0]['text']);
// After the pro-forma ARK → ZNZ for 2 adults (provisional: a warning).
$res = bg_evaluate($snap($cavServices), [$sv, $doc('invoices/Flightlink proforma.pdf', [$dsFlight('ARK', 'ZNZ', 450)])]);
$t('Cavalli fixed: passed (provisional warning)', $res['passed'] && $issues($res) === ['booking_provisional'], $issues($res));

// Plain booking: stays + flight right, one Zanzibar transfer missing → passed with 1 warning.
$base = [$stay('Tarangire Treetops', '2027-01-15', '2027-01-17', 2, 1000.0), $flightSvc,
         ['type' => 'transfer', 'date' => '2027-01-18', 'text' => 'Transfer ZNZ airport - Nungwi', 'route' => 'ZNZ', 'rows' => [20]]];
$tt = fn($over = []) => $doc('invoices/Treetops.pdf', [$dsStay('Tarangire Treetops', '2027-01-15', '2027-01-17', $over + ['net_amount' => 1000])]);
$okFlight = $doc('invoices/flight.pdf', [$dsFlight('ARK', 'ZNZ', 470, 'confirmed')]);
$res = bg_evaluate($snap($base), [$tt(), $okFlight]);
$t('transfer missing only → passed, 1 warning', $res['passed'] && $issues($res) === ['transfer_missing'] && $res['warnings'] === 1, $issues($res));
// 2 nights on the invoice, 3 in the Calc → blocked.
$b3 = $base; $b3[0] = $stay('Tarangire Treetops', '2027-01-15', '2027-01-18', 3, 1000.0);
$res = bg_evaluate($snap($b3), [$tt(), $okFlight]);
$t('2 nights vs Calc 3 → stay_dates blocking', !$res['passed'] && in_array('stay_dates!', $issues($res), true), $issues($res));
// Another lodge booked (alias: different) → blocked "update the Calc"; same → passes.
$other = $doc('invoices/Kirurumu.pdf', [$dsStay('Kirurumu Tarangire Lodge', '2027-01-15', '2027-01-17', ['net_amount' => 900])]);
$res = bg_evaluate($snap($base), [$other, $okFlight], fn($p) => array_fill(0, count($p), 'different'));
$t('booked lodge ≠ Calc → booked_differs blocking', !$res['passed'] && in_array('booked_differs!', $issues($res), true)
                                                   && strpos(implode(' ', array_column($res['findings'], 'text')), 'update the Calc') !== false, $issues($res));
$res = bg_evaluate($snap($base), [$other, $okFlight], fn($p) => array_fill(0, count($p), 'same'));
$t('alias same → no booked_differs', $res['passed'], $issues($res));
// Amount: +4 OK, +30 blocked.
$res = bg_evaluate($snap($base), [$tt(['net_amount' => 1004]), $okFlight]);
$t('invoice = Calc + 4 → OK', $res['passed'], $issues($res));
$res = bg_evaluate($snap($base), [$tt(['net_amount' => 1030]), $okFlight]);
$t('invoice = Calc + 30 → invoice_mismatch blocking', !$res['passed'] && in_array('invoice_mismatch!', $issues($res), true), $issues($res));
// Commission line: net after commission.
$res = bg_evaluate($snap($base), [$tt(['net_amount' => null, 'amount_total' => 1250, 'commission' => 250]), $okFlight]);
$t('commission deducted → OK', $res['passed'], $issues($res));
// Correct but provisional → passed with a warning.
$res = bg_evaluate($snap($base), [$tt(['status' => 'provisional', 'expiry_date' => '2026-12-01']), $okFlight]);
$t('provisional → passed + booking_provisional', $res['passed'] && in_array('booking_provisional', $issues($res), true), $issues($res));
// Pax, rooms, children billed as adults, doc inconsistent, meal plan.
$res = bg_evaluate($snap($base), [$tt(['adults' => 3]), $okFlight]);
$t('3 pax vs 2 → stay_pax', in_array('stay_pax!', $issues($res), true), $issues($res));
$res = bg_evaluate($snap($base), [$tt(['rooms' => [['type' => 'Twin', 'count' => 1]]]), $okFlight]);
$t('Twin vs DBL → warning only (final room list)', $res['passed'] && in_array('rooms_note', $issues($res), true), $issues($res));
$res = bg_evaluate($snap($base, ['pax' => ['adults' => 2, 'teen' => 0, 'child' => 1]]), [$tt(['adults' => 3]), $okFlight]);
$t('child billed as adult', in_array('child_as_adult!', $issues($res), true), $issues($res));
$res = bg_evaluate($snap($base), [$tt(['nights_header' => 2, 'nights_billed' => 1]), $okFlight]);
$t('header 2 / billed 1 → doc_inconsistent', in_array('doc_inconsistent!', $issues($res), true), $issues($res));
$bm = $base; $bm[0]['name'] = 'Tarangire Treetops FB';
$res = bg_evaluate($snap($bm), [$tt(['meal_plan' => 'Half Board']), $okFlight]);
$t('FB Calc, HB booked → note only', $res['passed'] && in_array('meal_plan_note', $issues($res), true), $issues($res));
$res = bg_evaluate($snap($bm), [$tt(['meal_plan' => 'Bed & Breakfast']), $okFlight]);
$t('FB Calc, BB booked → blocking', in_array('stay_meal_plan!', $issues($res), true), $issues($res));
// Missing stay, missing flight, wrong document, unreadable, client flights, Calc error.
$res = bg_evaluate($snap($base), [$okFlight]);
$t('no stay document → stay_missing', in_array('stay_missing!', $issues($res), true) && $res['services'][0]['status'] === 'MISSING', $issues($res));
$res = bg_evaluate($snap($base), [$tt()]);
$t('no flight document → flight_missing', in_array('flight_missing!', $issues($res), true), $issues($res));
$res = bg_evaluate($snap($base), [$tt(), $okFlight, $doc('invoices/old client.pdf', [$dsStay('Gibbs Farm', '2026-08-02', '2026-08-04')], ['Bianchi'])]);
$t('document of another date → wrong_document', in_array('wrong_document!', $issues($res), true), $issues($res));
$res = bg_evaluate($snap($base), [$tt(), $okFlight, ['rel_path' => 'invoices/x.msg', 'status' => 'error', 'error' => 'Outlook .msg could not be read', 'data' => []]]);
$t('unreadable → warning only', $res['passed'] && in_array('doc_unreadable', $issues($res), true), $issues($res));
$res = bg_evaluate($snap($base, ['arrival' => '']), [$tt(), $okFlight]);
$t('client flights missing → sales warning', $res['passed'] && in_array('client_flights_missing', $issues($res), true)
                                            && array_values(array_filter($res['findings'], fn($f) => $f['issue'] === 'client_flights_missing'))[0]['audience'] === 'sales');
$res = bg_evaluate(['customer' => 'X', 'folder' => '/001_Safari/x', 'calc_error' => 'The Calc has 3 pax sheets and 2 marked CONF.'], []);
$t('Calc not clean → blocking, sales', !$res['passed'] && $res['findings'][0]['issue'] === 'calc_not_clean' && $res['findings'][0]['audience'] === 'sales');
// Own airport transfer: in-house, not a finding.
$bo = $base; $bo[2] = ['type' => 'transfer', 'date' => '2027-01-15', 'text' => 'Transfer JRO-Arusha', 'route' => 'Arusha', 'rows' => [17]];
$res = bg_evaluate($snap($bo), [$tt(), $okFlight]);
$t('own JRO transfer → IN-HOUSE, nothing reported', $res['passed'] && !$res['findings'] && $res['services'][2]['status'] === 'IN-HOUSE', $issues($res));
// Report renders, no passport.
$html = bg_report_html(['customer_name' => 'Cavalli'], $snap($cavServices), bg_evaluate($snap($cavServices), [$sv]), ['finished_at' => '2026-11-13 10:00:00']);
$t('report renders', strpos($html, '<title>Booking check') !== false && strpos($html, 'MISSING') !== false);

// ── Rivolta (request 3091, run 8): rooms from room names, family equivalents, pax per room, adults vs children ──
$t('room names: bed layout = one family room', bg_room_type('Jambo (1Dbl & 2Sngl)') === 'fam' && bg_room_type('Bwana 1Dbl & 2Sngl)') === 'fam'
   && bg_room_type('Tusker Villa 1') === 'fam' && bg_room_type('Executive Family Room') === 'fam' && bg_room_type('Swala (1Dbl)') === 'dbl'
   && bg_room_type('Fisi (Dbl)') === 'dbl' && bg_room_type('Executive Room') === '');
$rvSnap = $snap([], ['pax' => ['adults' => 4, 'teen' => 0, 'child' => 0], 'room_config' => '1 FAM + 1 TWN', 'start_date' => '2027-08-18', 'end_date' => '2027-08-29']);
$rvSvc = fn($name, $in, $out, $n, $own = false) => $stay($name, $in, $out, $n, null, $own);
$rvSnap['services'] = [
    $rvSvc('Arusha Explorers', '2027-08-18', '2027-08-19', 1, true),
    $rvSvc('Marera', '2027-08-20', '2027-08-21', 1, true),
    $rvSvc('Orangi River', '2027-08-22', '2027-08-24', 2, true),
    $rvSvc('Elephant Springs', '2027-08-19', '2027-08-20', 1),
    $rvSvc('Lions Paw', '2027-08-21', '2027-08-22', 1),
    $rvSvc('River Camp', '2027-08-24', '2027-08-25', 1),
];
$rm = fn($type, $n = 1) => ['type' => $type, 'count' => $n];
$orangi = $doc('invoices/The Orangi Collection -sv.pdf', [
    $dsStay('Arusha Explorers', '2027-08-18', '2027-08-19', ['adults' => 2, 'children' => 2, 'rooms' => [$rm('Jambo (1Dbl & 2Sngl)')], 'status' => 'provisional']),
    $dsStay('Marera', '2027-08-20', '2027-08-21', ['adults' => 2, 'children' => 2, 'rooms' => [$rm('Kilimanjaro (1Dbl & 2Sngl)')], 'status' => 'provisional']),
    $dsStay('Orangi River Luxury', '2027-08-22', '2027-08-24', ['adults' => 2, 'children' => 0, 'rooms' => [$rm('Swala (1Dbl)')], 'status' => 'provisional']),
    $dsStay('Orangi River Luxury', '2027-08-22', '2027-08-24', ['adults' => 2, 'children' => 0, 'rooms' => [$rm('Fisi (Dbl)')], 'status' => 'provisional']),
]);
$karibu = $doc('invoices/karibu camp -inv.pdf', [
    $dsStay('Elephant Springs', '2027-08-19', '2027-08-20', ['adults' => 4, 'rooms' => [$rm('Tusker Villa 1')], 'status' => 'provisional']),
    $dsStay('Lions Paw', '2027-08-21', '2027-08-22', ['adults' => 2, 'rooms' => [$rm('Executive Family Room')], 'status' => 'provisional']),
    $dsStay('Lions Paw', '2027-08-21', '2027-08-22', ['adults' => 2, 'rooms' => [$rm('Executive Family Room')], 'status' => 'provisional']),
    $dsStay('River Camp', '2027-08-24', '2027-08-25', ['adults' => 2, 'rooms' => [$rm('Executive Room', 2)], 'amount_total' => 1980, 'status' => 'provisional']),
]);
$res = bg_evaluate($rvSnap, [$orangi, $karibu]);
$blk = array_values(array_filter($res['findings'], fn($f) => $f['blocking']));
$t('Rivolta: only blocking = 2A/2C vs 4 adults on Arusha Explorers + Marera, audience sales',
   count($blk) === 2 && $blk[0]['issue'] === 'stay_pax' && $blk[0]['audience'] === 'sales' && strpos($blk[0]['text'], '2 adults + 2 children') !== false
   && $blk[1]['service'] === 'Marera', array_map(fn($f) => [$f['issue'], $f['service'], $f['audience'], $f['text']], $blk));
$t('Rivolta: no room block, Orangi 2 × Double = warning only, no duplicate line per service',
   !array_filter($res['findings'], fn($f) => $f['issue'] === 'stay_rooms')
   && count(array_filter($res['findings'], fn($f) => $f['issue'] === 'rooms_note' && $f['service'] === 'Orangi River')) === 1
   && count(array_filter($res['findings'], fn($f) => $f['service'] === 'River Camp' && $f['issue'] !== 'booking_provisional')) === 0, $issues($res));
// CANCELLED: only back to PROGRESS (rule, refused even with a passed run)
$t('CANCELLED → PROVISIONAL / DEPOSIT / PAID refused by rule; → PROGRESS allowed', bg_cancelled_move_refused($rv . 'CANCELLED', $rv . 'PROVISIONAL')
   && bg_cancelled_move_refused($rv . 'CANCELLED', $rv . 'DEPOSIT') && bg_cancelled_move_refused($rv . 'CANCELLED', $rv . 'PAID')
   && !bg_cancelled_move_refused($rv . 'CANCELLED', $rv . 'PROGRESS') && !bg_cancelled_move_refused($rv . 'PROVISIONAL', $rv . 'DEPOSIT'));

// ── Undated Calc rows: one rule (calc_skipped_kind) for booking_check, the gate and the ITI final program ──
$row21 = ['row' => 21, 'text' => 'activity "MEDIVAC"', 'hotel' => '', 'invoice' => '', 'checked' => '', 'dated' => false, 'has_cost' => true, 'stay_cost' => null];
$row22 = ['row' => 22, 'text' => 'hotel "Flight link"', 'hotel' => 'Flight link', 'invoice' => 'Invoice$290', 'checked' => 'Glady', 'dated' => false, 'has_cost' => false, 'stay_cost' => null];
$lost  = ['row' => 23, 'text' => 'hotel "Gibbs Farm"', 'hotel' => 'Gibbs Farm', 'invoice' => '', 'checked' => '', 'dated' => false, 'has_cost' => true, 'stay_cost' => 420.0];
$t('MEDIVAC row 21 → trip item', calc_skipped_kind($row21) === 'trip_item');
$t('Flight link row 22 → annotation', calc_skipped_kind($row22) === 'annotation');
$t('lodge + cost in J, no date → lost day', calc_skipped_kind($lost) === 'lost_day');
$cav = $calc; $cav['skipped_rows'] = [$row21, $row22];
$svCav = bc_services($cav);
$t('booking_check: MEDIVAC no service, marks on the flight', count($svCav) === 4
   && (array_values(array_filter($svCav, fn($s) => $s['type'] === 'flight'))[0]['annotations'][0]['row'] ?? 0) === 22);
// ITI review (iti_final.php needs the server config only to connect: DB_HOST set, nothing is queried here).
if (!defined('DB_HOST')) define('DB_HOST', 'test');
if (!defined('BASE_URL')) define('BASE_URL', 'https://test');
require_once __DIR__ . '/../../modules/iti/includes/iti_final.php';
$rv = iti_final_calc_review(['pax' => ['adults' => 2, 'teen' => 0, 'child' => 0], 'room_config' => '1 DBL', 'request_status' => 'Booked', 'sheet' => '2PAX',
                             'start_date' => '2027-01-15', 'days' => [['date' => '2027-01-15']], 'skipped_rows' => [$row21, $row22]]);
$rowErr = array_filter($rv['errors'], fn($e) => preg_match('/^Row 2[12]\b/', $e));
$t('ITI review: no errors for rows 21 / 22, a warning for the annotation', !$rowErr
   && count(array_filter($rv['warnings'], fn($w) => strpos($w, 'Row 22') === 0)) === 1, $rv['errors']);
$rv = iti_final_calc_review(['pax' => ['adults' => 2, 'teen' => 0, 'child' => 0], 'room_config' => '1 DBL', 'request_status' => 'Booked', 'sheet' => '2PAX',
                             'start_date' => '2027-01-15', 'days' => [['date' => '2027-01-15']], 'skipped_rows' => [$lost]]);
$t('ITI review: lodge + J cost with no date → still an error', (bool)array_filter($rv['errors'], fn($e) => strpos($e, 'Row 23') === 0), $rv['errors']);

// ── Extraction schema: the API accepts at most 16 union-typed (nullable) parameters ──
$unions = 0;
$walk = function ($n) use (&$walk, &$unions) {
    if (!is_array($n)) return;
    if (isset($n['type']) && is_array($n['type'])) $unions++;
    if (isset($n['anyOf'])) $unions++;
    foreach ($n as $x) if (is_array($x)) $walk($x);
};
$walk(bg_extract_schema());
$t('schema: ≤ 16 union-typed parameters', $unions <= 16, $unions);
$b = bg_blank_to_null(['services' => [['supplier' => '', 'legs' => [['from_airport' => 'ARK', 'flight_no' => '']], 'adults' => 2]], 'client_names' => ['', 'Cavalli']]);
$t('"" → null, empty list items dropped', $b['services'][0]['supplier'] === null && $b['services'][0]['legs'][0]['flight_no'] === null
                                         && $b['services'][0]['adults'] === 2 && $b['client_names'] === ['Cavalli'], $b);
$res = bg_evaluate($snap($base), [['rel_path' => 'invoices/Treetops.pdf', 'status' => 'error', 'error' => 'AI API error (HTTP 400): x', 'data' => []], $okFlight]);
$miss = array_values(array_filter($res['findings'], fn($f) => $f['issue'] === 'stay_missing'))[0]['text'] ?? '';
$unr = array_values(array_filter($res['findings'], fn($f) => $f['issue'] === 'doc_unreadable'))[0]['text'] ?? '';
$t('missing says a document could not be read; Hub error says re-run', strpos($miss, 'could not be read') !== false && strpos($unr, 're-run the check') !== false, [$miss, $unr]);

echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
