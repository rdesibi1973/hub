<?php
/**
 * followup_classify.php — reads one supplier document (PDF text) of a booking's invoices/
 * folder: status (confirmed / provisional / on request …), expiry, reference, service date,
 * who booked it. Pure functions, no DB / Dropbox: used by followup_service.php on the
 * server and by tools/followup_local_scan.php on the office PC (same rules both sides).
 *
 * Layouts seen in the Oct 2026 set (pdftotext with or without -layout, smalot/pdfparser):
 *   ResRequest  "Reservation Status: Provisional (Expires: 17 Mar 2026)" — without -layout the
 *               labels come first and the values after ("Reservation Status: Expires: …" /
 *               "Provisional (Expires: 08 Sep 2026)"); "CUS: Glady (Savannah …)" = who booked it.
 *   Orangi      service voucher "Status: Confirmed" + Reservation Notes "1 Double + 2 provisional".
 *   TNS / Sopa  "This provisional booking will expire in (14) days on 24/06/2026".
 *   Flights     table rows "YS106 ARUSHA 13:00 ZANZIBAR 14:15 $70.00 Confirmed | Request".
 *   Others      "Status: OPTION", "BOOKING STATUS CAMPSITE / CONFIRMED Ndutu 4".
 * Policy text ("All provisional bookings are strictly valid for 10 days …") is never a status.
 * No mbstring (the office PHP has none).
 */

const FU_STATUS_WORDS = 'Provisional|Confirmed|Cancell?ed|Option|On\s*Request|Request(?:ed)?|Wait\s*-?\s*list(?:ed)?|Tentative|Unconfirmed|Quotation';
const FU_MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
                   'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
/** A date as printed: "17 Mar 2026", "Fri 27 Nov 2026", "27th Nov 2026", "24/06/2026", "2026-06-24", "Mar 17, 2026". */
const FU_DATE_RE = '(?:\d{1,2}(?:st|nd|rd|th)?[ \-\.]+[A-Za-z]{3,9}\.?[ \-\.,]+\d{2,4}|\d{1,2}[\/\.\-]\d{1,2}[\/\.\-]\d{4}|\d{4}-\d{2}-\d{2}|[A-Za-z]{3,9}\.? \d{1,2},? \d{4})';

/** Files in invoices/ that are payments, not supplier bookings. */
function followup_skip_file(string $name): bool {
    if (!preg_match('/\.pdf$/i', $name)) return true;
    return (bool)preg_match('/swift|receipt|remittance|proof|\bpop\b|payment|bank|transfer|\btt\b|deposit slip|credit ?note|statement|'
                          . 'refund|\bSE-\d{4}|\bSH-\d{4}|\bINV-\d/i', $name);
}

/** "17 Mar 2026" / "24/06/2026" (day first) / "2026-06-24" → Y-m-d, or null. */
function followup_parse_date(string $s): ?string {
    $s = trim(preg_replace('/\s+/', ' ', $s));
    $ok = fn($y, $m, $d) => ($y >= 2020 && $y <= 2035 && checkdate($m, $d, $y)) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return $ok((int)$m[1], (int)$m[2], (int)$m[3]);
    if (preg_match('/^(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{4})$/', $s, $m)) return $ok((int)$m[3], (int)$m[2], (int)$m[1]);
    if (preg_match('/^(\d{1,2})(?:st|nd|rd|th)?[ \-\.]+([A-Za-z]{3,9})\.?[ \-\.,]+(\d{2,4})$/', $s, $m)) {
        $mon = FU_MONTHS[strtolower(substr($m[2], 0, 3))] ?? null;
        $y = (int)$m[3]; if ($y < 100) $y += 2000;
        return $mon ? $ok($y, $mon, (int)$m[1]) : null;
    }
    if (preg_match('/^([A-Za-z]{3,9})\.? (\d{1,2}),? (\d{4})$/', $s, $m)) {
        $mon = FU_MONTHS[strtolower(substr($m[1], 0, 3))] ?? null;
        return $mon ? $ok((int)$m[3], $mon, (int)$m[2]) : null;
    }
    return null;
}

/** Every date in a text, in order of appearance (Y-m-d). */
function followup_all_dates(string $text): array {
    preg_match_all('/(?<![\w\/\.\-])' . FU_DATE_RE . '(?![\w\/])/', $text, $m);
    return array_values(array_filter(array_map('followup_parse_date', $m[0])));
}

/** Supplier name from the file name: "LION'S PAW - INV.pdf" → "Lion's Paw", "The Orangi Collection  - sv 1.pdf" → "The Orangi Collection". */
function followup_supplier_from_file(string $name): string {
    $s = preg_replace('/\.[a-z0-9]{2,4}$/i', '', basename($name));
    $s = preg_replace('/\((?:\d+|copy)\)|\bcopy\b|[_]+|\bWB\s?\d{3,7}\b|\b\d{1,2}\s*pax\b/i', ' ', $s);   // the reference / pax count are not the supplier
    $s = preg_replace('/(?:[\s\-–]+|\b)(?:inv(?:oice)?s?|s\.?v\.?|vouchers?|proforma|pro-forma|confirmations?|conf|booking|reservation|res)\b\.?(?:[\s\-–]*\d+)?/i', ' ', $s);
    $s = trim(preg_replace('/\s+/', ' ', $s), " -–.,");
    if ($s === '') $s = preg_replace('/\.[a-z0-9]{2,4}$/i', '', basename($name));
    // ALL CAPS / all lower → Title Case (keeps "Lion's", "&").
    if (!preg_match('/[a-z]/', $s) || !preg_match('/[A-Z]/', $s)) {
        $s = preg_replace_callback("/[A-Za-z][A-Za-z']*/", fn($m) => ucfirst(strtolower($m[0])), $s);
    }
    return $s;
}

/** Lower-case alphanumerics, for matching the same supplier across documents. */
function followup_norm(string $s): string {
    return strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $s));
}

/** Policy / terms sentence that mentions "provisional" without being a status. */
function followup_is_policy_line(string $l): bool {
    return (bool)preg_match('/polic|strictly|release|held|subject to|are valid|is valid|bookings? (?:are|will|is)|provisional bookings|'
                          . 'provisional =|cancellation|expiry period|prior to|discretion|not applicable|change to provisional|'
                          . 'automatically|without (?:prior )?notice|notify you|terms|"provisional"|tentative|temporary|awaiting|definition|means|is applicable|per booking/i', $l);
}

/**
 * Status value printed after its label when the PDF text has the labels first
 * ("Reservation: Reservation #: Agent Status:" … "GRP2710 WB7712 Savannah Explorers Ltd Confirmed").
 * The first capitalised status word in the 600 characters after a "Status:" label, policy lines skipped.
 */
function followup_status_after_label(string $flat): ?string {
    if (!preg_match_all('/\bStatus\s*(?::|$)/im', $flat, $mm, PREG_OFFSET_CAPTURE)) return null;   // "Status:" or a table header ending in "Status"
    foreach ($mm[0] as $hit) {
        $after = substr($flat, $hit[1] + strlen($hit[0]), 600);
        foreach (preg_split('/\n/', $after) as $l) {
            if (followup_is_policy_line($l) || preg_match('/\bconfirmed booking\b/i', $l)) continue;
            if (preg_match_all('/\b(' . FU_STATUS_WORDS . ')\b/i', $l, $w)) {
                foreach ($w[1] as $word) if (ctype_upper($word[0])) return $word;
            }
        }
    }
    return null;
}

/**
 * What a group check needs from a document (collective GRP, Il Diamante SIC, private groups):
 *   block_text   "block 3 double rooms (6 adults)" / "8 rooms provisional" (voucher notes)
 *   names_tba    guest names still TBA
 *   flight_pax   seats on a flight invoice ("Travellers: (Count:Adult: 4)", "4XAdult", "… 4pax")
 *   flight_znz   the flight goes to / from Zanzibar
 *   flight_text  first flight row ("AL401 ARUSHA 14:30 ZANZIBAR 15:30"), ticket_limit (ticketing time limit)
 */
function followup_group_facts(array &$out, string $flat, string $one, bool $isFlight, string $file): void {
    $out['block_text'] = null; $out['names_tba'] = false;
    $out['flight_pax'] = null; $out['flight_znz'] = false; $out['flight_text'] = null; $out['ticket_limit'] = null;
    // "block 3 double rooms" anywhere; "2 Double rooms + 1 Single room" only in the notes (tables say "Room Charge", addresses "Block B").
    $notes = preg_match('/\b(?:Reservation Notes|Guest Information|Remarks|Special (?:Service )?Requests?)\b(.*?)(?:TERMS\s*&\s*CONDITIONS|Accommodation Rates|Provisional (?:Booking )?Policy|$)/is', $flat, $nm) ? $nm[1] : '';
    // The clause starts at a word: (?<!\S) keeps "5 adults + 1 Teenager…" whole instead of "eenager…".
    if (preg_match('/(?<![^\s\-–:(])[^\n.*]{0,60}?\bblock(?:ed)?\s+(?:of\s+)?\d+\s*[^\n]{0,70}/i', $flat, $m) && !followup_is_policy_line($m[0])) {
        $out['block_text'] = trim(preg_replace('/\s+/', ' ', $m[0]));
    } elseif ($notes !== '' && preg_match('/(?:^|(?<=\n))[^\n]*?\b\d+\s+(?:double|twin|single|triple|dbl|twn|sgl|tpl)?\s*rooms?\b[^\n]{0,60}/i', $notes, $m)
              && !followup_is_policy_line($m[0])) {
        $out['block_text'] = trim(preg_replace('/\s+/', ' ', $m[0]));
        if (strlen($out['block_text']) > 160) $out['block_text'] = '…' . substr($out['block_text'], -157);
    }
    $out['names_tba'] = (bool)preg_match('/\bnames?\s+(?:are\s+)?(?:TBA|TBC|to be (?:advised|confirmed))|\b(?:TBA|TBC)\b[^\n]{0,15}\bnames?\b/i', $one);
    $airline = '/auric|coastal|precision|flight ?link|salaam|regional air|air tanzania|zanair|tropical air|safari ?air|unity air|aerocrs/i';
    if ($isFlight || preg_match($airline, $file . ' ' . substr($one, 0, 600))) {
        $pax = 0;
        if (preg_match('/Count:\s*([^)]*)\)/i', $one, $m) && preg_match_all('/(?:Adult|Child|Infant|Teen)\w*\s*:\s*(\d+)/i', $m[1], $c)) {
            $pax = array_sum(array_map('intval', $c[1]));
        } elseif (preg_match_all('/\b(\d{1,2})\s*X\s*(?:Adult|Child|Teen)/i', $one, $c)) {
            $pax = max(array_map('intval', $c[1]));
        } elseif (preg_match('/\b(\d{1,2})\s*pax\b/i', $file . ' ' . $one, $m)) {
            $pax = (int)$m[1];
        }
        $out['flight_pax'] = $pax ?: null;
        $rows = preg_match_all('/\b[A-Z]{2}\s?\d{2,4}\b[^\n]{0,30}\b\d{1,2}:\d{2}\b[^\n]{0,40}/', $flat, $m) ? array_map(fn($x) => trim(preg_replace('/\s+/', ' ', $x)), $m[0]) : [];
        // Towards Zanzibar only (ARK-ZNZ, Seronera-ZNZ): "UI624 Arusha 13:45 Zanzibar 15:00", not the "YS105 ZANZIBAR 11:00 ARUSHA" return.
        $znz = array_values(array_filter($rows, fn($x) => preg_match('/zanzibar|\bZNZ\b/i', $x)
                                                        && !preg_match('/^[A-Z]{2}\s?\d{2,4}\s+(?:zanzibar|ZNZ)\b/i', $x)));
        $out['flight_znz'] = (bool)$znz;
        $out['flight_text'] = $znz[0] ?? $rows[0] ?? null;
        if (preg_match('/Ticketing Time Limit\s*:?\s*(' . FU_DATE_RE . ')/i', $one, $m)) $out['ticket_limit'] = followup_parse_date($m[1]);
    }
}

/**
 * Classify a supplier document.
 * $trip = ['start_date' => …, 'end_date' => …] of the booking (service-date fallback), $today = Y-m-d.
 * → ['status' => confirmed|provisional|on_request|cancelled|unknown|unreadable,
 *    'issue'  => null|provisional|provisional_expired|on_request|orangi_rooms_provisional|unreadable,
 *    'doc_status_text', 'expiry_date', 'supplier', 'supplier_ref', 'service_date', 'booked_by']
 */
function followup_classify(string $text, string $file, array $trip = [], ?string $today = null): array {
    $today = $today ?? date('Y-m-d');
    $out = ['status' => 'unknown', 'issue' => null, 'doc_status_text' => '', 'expiry_date' => null,
            'supplier' => followup_supplier_from_file($file), 'supplier_ref' => null, 'service_date' => null, 'booked_by' => null];
    $text = str_replace(["\xC2\xA0", "\r", "\f"], [' ', '', "\n"], $text);
    if (strlen(preg_replace('/\s+/', '', $text)) < 40) {
        return ['status' => 'unreadable', 'issue' => 'unreadable', 'doc_status_text' => 'no text in the PDF (scan / image?)'] + $out;
    }
    $flat = preg_replace('/[ \t|]+/', ' ', $text);              // columns → single spaces, lines kept
    $one  = preg_replace('/\s+/', ' ', $flat);                   // whole text on one line
    $D    = FU_DATE_RE;

    // File named after the reference or a scan date ("WB7712.pdf", "2026-09-30 12.51.20.pdf"): the property printed in the PDF.
    if (preg_match('/^(?:WB\s?\d+|[\d\s\.\-_]+|scan\w*|img\w*|doc\w*)$/i', $out['supplier'])) {
        if (preg_match('/Reservation Office:\s*([A-Z][A-Za-z\'&\.\- ]{3,60}?)(?:\s{2,}|\n|\s+(?:Web|Email|Tel|PO Box|P\.O)\b)/', $text, $m)) $out['supplier'] = trim($m[1]);
        elseif (preg_match('/\b([a-z]+)\.resrequest\.com/i', $one, $m)) $out['supplier'] = ucfirst(strtolower($m[1]));
    }

    // ── Reference ───────────────────────────────────────────────────────────
    if (preg_match('/\bWB\s?(\d{3,7})\b/', $one, $m)) {
        $out['supplier_ref'] = 'WB' . $m[1];
    } elseif (preg_match('/\b(?:Booking\s*(?:ID|Ref(?:erence)?|No\.?|Number|#)|Res(?:ervation)?\s*(?:No\.?|Number|#|Ref)|Confirmation\s*(?:No\.?|Number|#)|PNR)\s*[:#]?\s*([A-Z0-9][A-Z0-9\-\/]{3,24})\b/i', $one, $m)
              && preg_match('/\d/', $m[1])) {
        $out['supplier_ref'] = strtoupper($m[1]);
    } elseif (preg_match('/\bReference\s*:\s*([A-Z0-9][A-Z0-9\-\/]{3,20})\b/i', $one, $m) && preg_match('/\d/', $m[1])) {
        $out['supplier_ref'] = strtoupper($m[1]);
    }

    // ── Who booked it (CUS: Glady (Savannah …) / Contact Person / Booker) ───
    if (preg_match('/\bCUS:\s*([A-Za-z][A-Za-z \.]{1,30}?)\d*\.?\s*\(/', $one, $m)) {
        $out['booked_by'] = trim($m[1], ' .');
    } elseif (preg_match('/\b(?:Contact Person|Booked by|Booker)\s*:\s*([A-Z][A-Za-z]+(?: [A-Z][A-Za-z]+)?)/', $flat, $m)) {
        $out['booked_by'] = $m[1];
    }
    if ($out['booked_by'] !== null && preg_match('/savannah|explorers|^ltd$|^limited$|^(?:created|date|agent|telephone|email|reference|status)\b/i', $out['booked_by'])) {
        $out['booked_by'] = null;   // a label read as a name (labels-first PDF text)
    }
    if ($out['booked_by'] !== null && !preg_match('/[a-z]/', $out['booked_by'])) $out['booked_by'] = ucwords(strtolower($out['booked_by']));

    // ── Service date: labelled arrival, else the first date inside the trip ──
    foreach (['/\b(?:Travel Start Date|Arrival Date|Date of Arrival|Check[ \-]?In(?: Date)?|Arrival|Arrive|Start Date|Service Date)\s*:?\s*(?:[A-Za-z]{3},? )?(' . $D . ')/i'] as $re) {
        if (preg_match($re, $one, $m) && ($d = followup_parse_date($m[1]))) $out['service_date'] = $d;
    }
    $s0 = $trip['start_date'] ?? null; $s1 = $trip['end_date'] ?? null;
    if ($s0) {
        $lo = date('Y-m-d', strtotime($s0 . ' -1 day')); $hi = $s1 ?: date('Y-m-d', strtotime($s0 . ' +30 days'));
        if ($out['service_date'] && ($out['service_date'] < $lo || $out['service_date'] > $hi)) $out['service_date'] = null;
        if (!$out['service_date']) {
            $in = array_filter(followup_all_dates($flat), fn($d) => $d >= $lo && $d <= $hi);
            if ($in) $out['service_date'] = min($in);
        }
    }

    // ── Status ──────────────────────────────────────────────────────────────
    $status = null; $label = '';
    $set = function (string $word) use (&$status, &$label) {
        $w = strtolower(preg_replace('/[\s\-]+/', '', $word));
        $status = match (true) {
            $w === 'confirmed'                                   => 'confirmed',
            str_starts_with($w, 'cancel')                        => 'cancelled',
            in_array($w, ['onrequest', 'request', 'requested', 'waitlist', 'waitlisted'], true) => 'on_request',
            default                                              => 'provisional',   // provisional, option, tentative, unconfirmed, quotation
        };
        $label = ucfirst(strtolower(trim(preg_replace('/\s+/', ' ', $word))));
    };
    $W = FU_STATUS_WORDS;
    if (preg_match('/\b(Provisional|Option)\s*\(\s*Expir\w*\s*:?\s*(' . $D . ')\s*\)/i', $one, $m)) {         // "Provisional (Expires: 17 Mar 2026)" anywhere
        $set($m[1]); $out['expiry_date'] = followup_parse_date($m[2]);
    } elseif (preg_match('/\b(?:Reservation|Booking)?\s*Status\s*:?\s*(' . $W . ')\b/i', $one, $m)) {   // "Status: Confirmed", "Status: OPTION"
        $set($m[1]);
    } elseif (preg_match('/\bBOOKING STATUS\s+\w+\s+(' . $W . ')\b/i', $one, $m)                       // "BOOKING STATUS CAMPSITE / CONFIRMED Ndutu 4"
              || preg_match('/\bTelephone\s*:\s*(Provisional|Confirmed|Cancell?ed)\b/i', $one, $m)) {    // Heart & Soul: the value lands next to "Telephone:"
        $set($m[1]);
    } elseif (($v = followup_status_after_label($flat)) !== null) {                                     // labels first, values on a later line
        $set($v);
    }
    if (preg_match('/provisional booking will expire\b[^\n]{0,60}?\bon\s+(' . $D . ')/i', $one, $m)) {     // TNS / Sopa footer
        if ($status === null || $status === 'confirmed' && !preg_match('/Status\s*:?\s*Confirmed/i', $one)) $set('Provisional');
        $out['expiry_date'] = $out['expiry_date'] ?? followup_parse_date($m[1]);
    }

    // Flight tables: one row per flight with its status in the last columns.
    $isFlight = (bool)preg_match('/\bFlights?\s*:?\s*(?:#\s*Date\s*)?Flight\b|\bFlight\s+From\b|\bSTD\b.{0,20}\bSTA\b/i', $one);
    if ($isFlight) {
        $req = $conf = 0;
        foreach (preg_split('/\n/', $flat) as $l) {
            // The status can sit on the row or, in AeroCRS pro-formas, on the line under the header.
            $row = (bool)preg_match('/\b[A-Z0-9]{2}\s?\d{2,4}\b.*\b\d{1,2}:\d{2}\b/', $l);
            if (preg_match('/\b(?:On )?Request(?:ed)?\b|\bWait\s*-?\s*list(?:ed)?\b|\bRQ\b|\bHL\b/', $l)
                && !preg_match('/\b(?:we|kindly|please|special|upon|early|booking|reservation|your) requests?\b|\brequests? (?:your|that|all|for|to|a|the|should)\b/i', $l)
                && !followup_is_policy_line($l)) {
                $req++;
            } elseif ($row && preg_match('/\b(?:Confirmed|HK|OK)\b/i', $l)
                      || preg_match('/\bConfirmed\b/', $l) && !preg_match('/confirmed by|be confirmed/i', $l) && !followup_is_policy_line($l)) {
                $conf++;
            }
        }
        if ($req)      { $status = 'on_request'; $label = $req . ' flight' . ($req > 1 ? 's' : '') . ' on request' . ($conf ? ', ' . $conf . ' confirmed' : ''); }
        elseif ($conf && $status === null) { $status = 'confirmed'; $label = 'Confirmed'; }
    }

    // A word "provisional" outside policy text, when nothing else was printed ("Proforma … provisional").
    if ($status === null) {
        foreach (preg_split('/\n/', $flat) as $l) {
            if (preg_match('/\bprovisional\b/i', $l) && !followup_is_policy_line($l)) { $set('Provisional'); break; }
        }
    }

    // Expiry printed elsewhere ("valid until", "Option date", "Provisional until").
    if ($status === 'provisional' && !$out['expiry_date']
        && preg_match('/\b(?:Expir(?:es|y)(?: date)?|valid until|held until|Provisional (?:until|expiry)|Option (?:date|expiry|until)|Release date)\s*:?\s*(' . $D . ')/i', $one, $m)) {
        $out['expiry_date'] = followup_parse_date($m[1]);
    }

    // Confirmed voucher with rooms still provisional in its notes (Orangi Collection).
    $note = '';
    if ($status === 'confirmed' && preg_match('/\b(?:Reservation Notes|Remarks|Special (?:Service )?Requests?\s*\/?\s*Remarks?)\s*:?(.*?)(?:TERMS\s*&\s*CONDITIONS|Accommodation Rates|Provisional (?:Booking )?Policy|$)/is', $flat, $m)) {
        foreach (preg_split('/\n/', $m[1]) as $l) {
            // The clause up to "provisional" (+ "still provisional" words after it): the notes can be one long line.
            if (preg_match('/[^\n.*]{0,110}\bprovisional\b(?:\s+[a-z]+){0,2}/i', $l, $x, PREG_OFFSET_CAPTURE)
                && !followup_is_policy_line(substr($l, $x[0][1], strlen($x[0][0]) + 30))) {   // + what follows: '"Provisional" - temporary …'
                $x = $x[0];
                $note = trim(preg_replace('/\s+/', ' ', $x[0])); break;
            }
        }
    }

    followup_group_facts($out, $flat, $one, $isFlight, $file);

    $out['status'] = $status ?? 'unknown';
    if ($status === 'provisional') {
        $out['issue'] = ($out['expiry_date'] && $out['expiry_date'] < $today) ? 'provisional_expired' : 'provisional';
        $out['doc_status_text'] = $label . ($out['expiry_date'] ? ' (expires ' . date('d M Y', strtotime($out['expiry_date'])) . ')' : '');
    } elseif ($status === 'on_request') {
        $out['issue'] = 'on_request';
        $out['doc_status_text'] = $label === 'On request' || $label === 'Request' || $label === 'Requested' ? 'On request' : $label;
    } elseif ($status === 'confirmed' && $note !== '') {
        $out['issue'] = 'orangi_rooms_provisional';
        $out['doc_status_text'] = 'Confirmed – note: ' . (strlen($note) > 120 ? substr($note, 0, 117) . '…' : $note);
    } else {
        $out['doc_status_text'] = $status ? $label : '';
    }
    return $out;
}
