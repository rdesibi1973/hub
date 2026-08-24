<?php
/**
 * modules/iti/includes/voucher_lib.php
 *
 * Voucher generation from WeTu exports (Strada A).
 *
 * Reads a WeTu Word programme (.docx) + the internal Excel calc (.xlsx),
 * builds a unified voucher model, fills in per-lodge GPS/contacts from the
 * iti_voucher_lodges directory, and renders the result as PDF (Dompdf) or
 * Word (PhpWord). All input parsing is dependency-free (ZipArchive + DOM), so
 * nothing new needs installing in vendor/ on the server.
 *
 * Nothing here echoes output; callers own the HTTP response.
 */

// ─────────────────────────────────────────────────────────────────────────────
//  Normalization & date helpers
// ─────────────────────────────────────────────────────────────────────────────

/** Lower-case, strip accents/punctuation, collapse spaces — for fuzzy matching. */
function voucher_norm(string $s): string
{
    $s = trim($s);
    if ($s === '') return '';
    // Best-effort accent fold (à→a). iconv may be missing some translit; fall back.
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t !== false && $t !== '') $s = $t;
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** Italian month name/abbrev → 1..12 (null if unknown). */
function voucher_month_num(string $m): ?int
{
    $m = strtolower(trim($m));
    $m = preg_replace('/[^a-z]/', '', $m);
    static $map = [
        'gen' => 1, 'gennaio' => 1, 'feb' => 2, 'febbraio' => 2,
        'mar' => 3, 'marzo' => 3, 'apr' => 4, 'aprile' => 4,
        'mag' => 5, 'maggio' => 5, 'giu' => 6, 'giugno' => 6,
        'lug' => 7, 'luglio' => 7, 'ago' => 8, 'agosto' => 8,
        'set' => 9, 'settembre' => 9, 'sett' => 9,
        'ott' => 10, 'ottobre' => 10, 'nov' => 11, 'novembre' => 11,
        'dic' => 12, 'dicembre' => 12,
    ];
    if (isset($map[$m])) return $map[$m];
    // try 3-letter prefix
    $p = substr($m, 0, 3);
    return $map[$p] ?? null;
}

/**
 * Parse an Italian short date like "14 ago" into ISO "YYYY-MM-DD".
 * $startYear/$startMonth anchor the year (bump +1 if the trip crosses new year).
 */
function voucher_parse_it_date(string $s, int $startYear, int $startMonth): ?string
{
    if (!preg_match('/(\d{1,2})\s*([A-Za-zàèéìòù]+)/u', trim($s), $m)) return null;
    $day = (int)$m[1];
    $mon = voucher_month_num($m[2]);
    if (!$mon || $day < 1 || $day > 31) return null;
    $year = $startYear;
    if ($mon < $startMonth) $year++; // wrapped into next calendar year
    return sprintf('%04d-%02d-%02d', $year, $mon, $day);
}

/** Voucher-style English date: "Fri, August 14, 2026". */
function voucher_fmt_date(?string $iso): string
{
    if (!$iso) return '';
    $ts = strtotime($iso);
    return $ts ? date('D, F d, Y', $ts) : $iso;
}

/** Whole nights between two ISO dates. */
function voucher_nights(?string $ci, ?string $co): int
{
    if (!$ci || !$co) return 0;
    $a = strtotime($ci); $b = strtotime($co);
    if (!$a || !$b || $b <= $a) return 0;
    return (int)round(($b - $a) / 86400);
}

/** Extract an IATA-ish code in brackets, e.g. "... [ZNZ]" → "ZNZ". */
function voucher_airport_code(string $s): ?string
{
    return preg_match('/\[([A-Z]{3,4})\]/', $s, $m) ? $m[1] : null;
}

/**
 * Standard note for a transfer whose drop-off is Zanzibar airport: pick-up is
 * 4.5 h before an international flight, or 3.5 h before an internal one.
 * $hasInternalFlight = an internal (Voli-table) flight departs on this transfer.
 */
function voucher_zanzibar_note(string $dropoff, bool $hasInternalFlight): string
{
    $isZnzAirport = strpos($dropoff, '[ZNZ]') !== false
        || stripos($dropoff, 'Abeid Amani Karume') !== false
        || (stripos($dropoff, 'Zanzibar') !== false && stripos($dropoff, 'airport') !== false);
    if (!$isZnzAirport) return '';
    return $hasInternalFlight
        ? 'Please be ready for pick-up 3.5 hours before your internal flight departure.'
        : 'Please be ready for pick-up 4.5 hours before your international flight departure.';
}

// ─────────────────────────────────────────────────────────────────────────────
//  Meal basis
// ─────────────────────────────────────────────────────────────────────────────

function voucher_meal_label(string $code): string
{
    $c = strtoupper(trim($code));
    $c = str_replace([' ', '.'], '', $c);
    switch ($c) {
        case 'FB':  return 'Full Board - Dinner, Bed, Breakfast and Lunch';
        case 'HB':  return 'Half Board - Dinner, Bed and Breakfast';
        case 'BB':
        case 'B&B': return 'Bed and Breakfast';
        case 'RO':
        case 'ROOMONLY': return 'Room Only';
        case 'AI':  return 'All Inclusive';
    }
    // Already a full phrase — pass through.
    return trim($code);
}

/** Light IT→EN room-type helper (WeTu programmes mix languages). Editable on review. */
function voucher_room_label(string $room): string
{
    $room = trim($room);
    if ($room === '') return '';
    static $map = [
        'camera matrimoniale' => 'Double Room',
        'matrimoniale'        => 'Double Room',
        'camera doppia'       => 'Twin Room',
        'camera singola'      => 'Single Room',
        'camera tripla'       => 'Triple Room',
        'camera familiare'    => 'Family Room',
        'camera famiglia'     => 'Family Room',
    ];
    foreach ($map as $it => $en) {
        $room = preg_replace('/' . preg_quote($it, '/') . '/i', $en, $room);
    }
    return $room;
}

// ─────────────────────────────────────────────────────────────────────────────
//  DOCX reader (paragraphs + tables)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @return array{paragraphs: string[], tables: array<int, array<int, string[]>>}
 * Each table is a list of rows; each row a list of cell strings.
 */
function voucher_docx_read(string $path): array
{
    $out = ['paragraphs' => [], 'tables' => []];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Cannot open Word file.');
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) throw new RuntimeException('Word file has no document.xml.');

    $ns  = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadXML($xml);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', $ns);

    // Text of a node, in document order, with line breaks (<w:br/>, <w:cr/>)
    // preserved as "\n" so multi-line paragraphs can be split into logical lines.
    $rawText = function (DOMNode $node) use ($xp): string {
        $s = '';
        // Run-level content only, so paragraph tab-stop definitions (w:pPr/w:tabs/w:tab)
        // are not mistaken for text tabs. Union results come in document order.
        foreach ($xp->query('.//w:r/w:t | .//w:r/w:br | .//w:r/w:cr | .//w:r/w:tab', $node) as $n) {
            $ln = $n->localName;
            if ($ln === 't')        $s .= $n->textContent;
            elseif ($ln === 'tab')  $s .= ' ';
            else                    $s .= "\n"; // br / cr
        }
        return $s;
    };
    $cellText = function (DOMNode $node) use ($rawText): string {
        return trim(preg_replace('/[ \t]+/', ' ', str_replace("\n", ' ', $rawText($node))));
    };

    // Paragraphs at body level, split on line breaks into logical lines.
    foreach ($xp->query('//w:body/w:p') as $p) {
        foreach (explode("\n", $rawText($p)) as $line) {
            $line = trim(preg_replace('/[ \t]+/', ' ', $line));
            if ($line !== '') $out['paragraphs'][] = $line;
        }
    }

    // Tables (top-level; these programmes have no nested tables).
    foreach ($xp->query('//w:tbl') as $tbl) {
        $rows = [];
        foreach ($xp->query('./w:tr', $tbl) as $tr) {
            $cells = [];
            foreach ($xp->query('./w:tc', $tr) as $tc) {
                $cells[] = $cellText($tc);
            }
            if ($cells) $rows[] = $cells;
        }
        if ($rows) $out['tables'][] = $rows;
    }
    return $out;
}

/** Find the first table whose header row contains ALL given needles (case-insensitive). */
function voucher_find_table(array $tables, array $needles): ?array
{
    foreach ($tables as $rows) {
        $header = strtolower(implode(' | ', $rows[0] ?? []));
        $ok = true;
        foreach ($needles as $n) {
            if (strpos($header, strtolower($n)) === false) { $ok = false; break; }
        }
        if ($ok) return $rows;
    }
    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
//  XLSX reader (dependency-free)
// ─────────────────────────────────────────────────────────────────────────────

/** @return string[] sheet names, in workbook order. */
function voucher_xlsx_sheets(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Cannot open Excel file.');
    $wb = $zip->getFromName('xl/workbook.xml');
    $zip->close();
    if ($wb === false) return [];
    $names = [];
    if (preg_match_all('/<sheet\b[^>]*\/?>/', $wb, $m)) {
        foreach ($m[0] as $tag) {
            if (preg_match('/name="([^"]*)"/', $tag, $mm)) {
                $names[] = html_entity_decode($mm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
    }
    return $names;
}

/**
 * Read one worksheet into a coordinate→value map, e.g. ['A37' => '1 double'].
 * $sheetName null → first sheet.
 * @return array<string,string>
 */
function voucher_xlsx_cells(string $path, ?string $sheetName = null): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Cannot open Excel file.');

    $read = function (string $name) use ($zip) { return $zip->getFromName($name); };

    // Shared strings.
    $shared = [];
    $ssXml = $read('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($ssXml);
        libxml_clear_errors();
        foreach ($dom->getElementsByTagName('si') as $si) {
            $buf = '';
            foreach ($si->getElementsByTagName('t') as $t) $buf .= $t->textContent;
            $shared[] = $buf;
        }
    }

    // Resolve sheet name → worksheet path.
    $wbXml   = $read('xl/workbook.xml');
    $relsXml = $read('xl/_rels/workbook.xml.rels');
    $target  = 'xl/worksheets/sheet1.xml';
    if ($wbXml !== false && $relsXml !== false) {
        // name → rId
        $nameToRid = [];
        if (preg_match_all('/<sheet\b[^>]*\/?>/', $wbXml, $m)) {
            foreach ($m[0] as $tag) {
                $nm  = preg_match('/name="([^"]*)"/', $tag, $a) ? html_entity_decode($a[1], ENT_QUOTES | ENT_XML1, 'UTF-8') : '';
                $rid = preg_match('/r:id="([^"]*)"/', $tag, $b) ? $b[1] : '';
                if ($nm !== '' && $rid !== '') $nameToRid[$nm] = $rid;
            }
        }
        // rId → target
        $ridToTarget = [];
        if (preg_match_all('/<Relationship\b[^>]*\/?>/', $relsXml, $m)) {
            foreach ($m[0] as $tag) {
                $id  = preg_match('/Id="([^"]*)"/', $tag, $a) ? $a[1] : '';
                $tgt = preg_match('/Target="([^"]*)"/', $tag, $b) ? $b[1] : '';
                if ($id !== '' && $tgt !== '') $ridToTarget[$id] = $tgt;
            }
        }
        $pick = null;
        if ($sheetName !== null && isset($nameToRid[$sheetName])) {
            $pick = $nameToRid[$sheetName];
        } elseif ($sheetName === null) {
            // first sheet listed
            $pick = $nameToRid ? reset($nameToRid) : null;
        }
        if ($pick && isset($ridToTarget[$pick])) {
            $tgt = ltrim($ridToTarget[$pick], '/');
            $target = (strpos($tgt, 'xl/') === 0) ? $tgt : 'xl/' . $tgt;
        }
    }

    $sheetXml = $read($target);
    $zip->close();
    if ($sheetXml === false) return [];

    $cells = [];
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadXML($sheetXml);
    libxml_clear_errors();
    foreach ($dom->getElementsByTagName('c') as $c) {
        $ref = $c->getAttribute('r');
        if ($ref === '') continue;
        $type = $c->getAttribute('t');
        $val  = '';
        if ($type === 'inlineStr') {
            foreach ($c->getElementsByTagName('t') as $t) $val .= $t->textContent;
        } else {
            $vNode = null;
            foreach ($c->childNodes as $ch) {
                if ($ch->nodeType === XML_ELEMENT_NODE && $ch->localName === 'v') { $vNode = $ch; break; }
            }
            $raw = $vNode ? $vNode->textContent : '';
            if ($type === 's') {
                $idx = (int)$raw;
                $val = $shared[$idx] ?? '';
            } else {
                $val = $raw; // number or cached formula string
            }
        }
        $val = trim($val);
        if ($val !== '') $cells[$ref] = $val;
    }
    return $cells;
}

/** Split a cell ref "AB12" → ['AB', 12]. */
function voucher_ref_split(string $ref): array
{
    preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
    return [$m[1] ?? '', (int)($m[2] ?? 0)];
}

/** Value at column letter + row from a cells map, '' if absent. */
function voucher_cell(array $cells, string $col, int $row): string
{
    return $cells[$col . $row] ?? '';
}

// ─────────────────────────────────────────────────────────────────────────────
//  Excel field extraction (travellers, room, dietary)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @return array{travellers: array<int, array{title:string,name:string,country:string}>,
 *               room: string, dietary: string}
 */
function voucher_xlsx_extract(array $cells): array
{
    // Locate anchor labels in column A (and its neighbours).
    $labelRow = function (string $needle) use ($cells): ?int {
        $needle = strtolower($needle);
        foreach ($cells as $ref => $val) {
            [$col, $row] = voucher_ref_split($ref);
            if ($col !== 'A') continue;
            if (strpos(strtolower($val), $needle) === 0) return $row;
        }
        return null;
    };

    // Room type — value directly under the ROOMS TYPE label.
    $room = '';
    if ($r = $labelRow('rooms type')) {
        for ($i = $r + 1; $i <= $r + 3; $i++) {
            $v = voucher_cell($cells, 'A', $i);
            if ($v !== '') { $room = $v; break; }
        }
    }

    // Dietary / extra details — value(s) under the EXTRA DETAILS label,
    // up to the NAME header. Best-effort; the operator edits it on review.
    $dietary = '';
    if ($r = $labelRow('extra details')) {
        $lines = [];
        for ($i = $r + 1; $i <= $r + 4; $i++) {
            $v = voucher_cell($cells, 'A', $i);
            if ($v === '') continue;
            if (stripos($v, 'name (') === 0) break;         // hit next section
            if (stripos($v, 'honeymooners') === 0 && $v === strtoupper($v)) continue; // template tag
            $lines[] = $v;
        }
        $dietary = trim(implode(' · ', $lines));
    }

    // Travellers — rows below the NAME header until a blank name or ARRIVAL label.
    $travellers = [];
    if ($r = $labelRow('name (')) {
        for ($i = $r + 1; $i <= $r + 20; $i++) {
            $name = voucher_cell($cells, 'A', $i);
            if ($name === '') break;
            if (stripos($name, 'arrival') === 0 || stripos($name, 'departure') === 0) break;
            $title   = voucher_cell($cells, 'B', $i);
            $country = voucher_cell($cells, 'F', $i);
            $travellers[] = [
                'title'   => voucher_title_case($title),
                'name'    => $name,
                'country' => $country,
            ];
        }
    }

    return ['travellers' => $travellers, 'room' => $room, 'dietary' => $dietary];
}

/** "MR"/"mrs" → "Mr"/"Mrs". */
function voucher_title_case(string $t): string
{
    $t = trim($t);
    if ($t === '') return '';
    return ucfirst(strtolower($t));
}

// ─────────────────────────────────────────────────────────────────────────────
//  Excel ↔ Word accommodation cross-check
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Parse a calc-sheet date cell to ISO "YYYY-MM-DD". Handles both a text date
 * ("1-Jul-2026", "01/07/2026") and an Excel serial number (days since the
 * 1899-12-30 epoch). Returns null when it can't be read as a plausible date.
 */
function voucher_parse_xlsx_date(string $v): ?string
{
    $v = trim($v);
    if ($v === '') return null;

    // Excel serial: a bare number in the modern-date range (~1954..2100).
    if (preg_match('/^\d+(?:\.\d+)?$/', $v)) {
        $n = (int)floor((float)$v);
        if ($n >= 20000 && $n <= 80000) {
            $d = new DateTime('1899-12-30');
            $d->modify('+' . $n . ' days');
            return $d->format('Y-m-d');
        }
        return null;
    }

    // Text date. strtotime reads "1-Jul-2026"; normalise "/" so "01/07/2026" too.
    $ts = strtotime($v);
    if ($ts === false) $ts = strtotime(str_replace('/', '-', $v));
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

/**
 * Per-stay accommodations from the calc sheet. The sheet lists one row per night
 * (a DATE column + the HOTEL/LODGE/CAMPSITE column); consecutive nights at the
 * same lodge are grouped into one stay. A night dated D means check-in D,
 * check-out D+1, so a run of rows becomes checkin = first date, checkout = last
 * date + 1 day, nights = row count. Split stays (same lodge, non-consecutive)
 * come back as separate entries.
 * @return array<int, array{name:string, checkin:?string, checkout:?string, nights:int}>
 */
function voucher_xlsx_stays(array $cells): array
{
    // Locate the lodge column and the table header row.
    $lodgeCol = null; $headerRow = 0;
    foreach ($cells as $ref => $val) {
        $v = strtolower($val);
        if (strpos($v, 'lodge') !== false && (strpos($v, 'hotel') !== false || strpos($v, 'camp') !== false)) {
            [$c, $r] = voucher_ref_split($ref);
            if ($c !== '') { $lodgeCol = $c; $headerRow = $r; break; }
        }
    }
    if ($lodgeCol === null) return [];

    // Date column: a "DATE" header on the same row (fallback: column A).
    $dateCol = 'A';
    foreach ($cells as $ref => $val) {
        [$c, $r] = voucher_ref_split($ref);
        if ($r !== $headerRow) continue;
        if (strpos(strtolower(trim($val)), 'date') === 0) { $dateCol = $c; break; }
    }

    // Gather night rows (lodge + parsed date) in row order.
    $rows = [];
    foreach ($cells as $ref => $val) {
        [$c, $r] = voucher_ref_split($ref);
        if ($c !== $lodgeCol || $r <= $headerRow) continue;
        $name = trim($val);
        if ($name === '' || stripos($name, 'total') === 0) continue;
        $rows[$r] = ['name' => $name, 'date' => voucher_parse_xlsx_date(voucher_cell($cells, $dateCol, $r))];
    }
    ksort($rows);

    // Group consecutive same-lodge rows into stays.
    $stays = []; $cur = null;
    foreach ($rows as $row) {
        $key = voucher_norm($row['name']);
        if ($cur !== null && $cur['key'] === $key) {
            $cur['nights']++;
            if ($cur['first'] === null) $cur['first'] = $row['date'];
            if ($row['date'] !== null)  $cur['last']  = $row['date'];
        } else {
            if ($cur !== null) $stays[] = $cur;
            $cur = ['key' => $key, 'name' => $row['name'], 'first' => $row['date'], 'last' => $row['date'], 'nights' => 1];
        }
    }
    if ($cur !== null) $stays[] = $cur;

    // Finalize: checkout = last night's date + 1 day.
    $out = [];
    foreach ($stays as $s) {
        $checkout = null;
        if ($s['last'] !== null) {
            $d = new DateTime($s['last']); $d->modify('+1 day');
            $checkout = $d->format('Y-m-d');
        }
        $out[] = ['name' => $s['name'], 'checkin' => $s['first'], 'checkout' => $checkout, 'nights' => $s['nights']];
    }
    return $out;
}

/**
 * Meaningful lower-case tokens of a lodge name, for fuzzy set-matching. Drops
 * meal-basis codes and generic hospitality words so two spellings of the same
 * place still overlap ("Marera View Lodge" ↔ "Marera View Lodge HB" → {marera}).
 * @return string[]
 */
function voucher_lodge_tokens(string $s): array
{
    static $stop = [
        'lodge' => 1, 'camp' => 1, 'tented' => 1, 'hotel' => 1, 'resort' => 1,
        'room' => 1, 'rooms' => 1, 'deluxe' => 1, 'standard' => 1, 'luxury' => 1,
        'view' => 1, 'safari' => 1, 'the' => 1, 'and' => 1, 'wing' => 1,
        'suite' => 1, 'villa' => 1, 'house' => 1, 'campsite' => 1, 'mobile' => 1,
        'special' => 1, 'night' => 1, 'nights' => 1, 'in' => 1, 'of' => 1,
        'fb' => 1, 'hb' => 1, 'bb' => 1, 'ro' => 1, 'ai' => 1,
    ];
    $tokens = [];
    foreach (explode(' ', voucher_norm($s)) as $w) {
        if (strlen($w) < 3 || isset($stop[$w])) continue;
        $tokens[$w] = true;
    }
    return array_keys($tokens);
}

/**
 * Cross-check the accommodations parsed from the Word programme against the
 * calc-sheet stays — names, nights AND check-in/out dates. Advisory: it reports
 * discrepancies; the caller decides whether to soft-block. Two names "match"
 * when they share a meaningful token (accents/case/meal-basis ignored), so
 * legitimate spelling differences line up while a mismatched file (wrong pair
 * uploaded) stands out.
 *
 * Names + nights are compared aggregated per lodge (so split stays add up).
 * Dates are compared per stay: each programme stay is paired to the calc stay
 * that shares a token and has the nearest check-in, then the two dates are
 * compared.
 *
 * @param array<int, array{name:string, nights:int, checkin:?string, checkout:?string}> $wordStays
 * @param array<int, array{name:string, nights:int, checkin:?string, checkout:?string}> $excelStays
 * @return array{applicable:bool, ok:bool, word_only:string[], excel_only:string[],
 *               nights_mismatch:array<int, array{name:string, word:int, excel:int}>,
 *               date_mismatch:array<int, array{name:string, field:string, word:string, excel:string}>,
 *               excel_lodges:string[]}
 */
function voucher_lodge_crosscheck(array $wordStays, array $excelStays): array
{
    // Distinct calc lodge names (for display), first-seen order.
    $excelNames = []; $seen = [];
    foreach ($excelStays as $s) {
        $k = voucher_norm($s['name']);
        if ($k !== '' && !isset($seen[$k])) { $seen[$k] = true; $excelNames[] = $s['name']; }
    }

    $result = [
        'applicable'      => ($wordStays !== [] && $excelStays !== []),
        'ok'              => true,
        'word_only'       => [],
        'excel_only'      => [],
        'nights_mismatch' => [],
        'date_mismatch'   => [],
        'excel_lodges'    => $excelNames,
    ];
    if (!$result['applicable']) return $result;

    $shares = function (array $a, array $b): bool {
        foreach ($a as $t) if (in_array($t, $b, true)) return true;
        return false;
    };
    // Aggregate stays into one entry per lodge (by normalized name).
    $group = function (array $stays): array {
        $g = [];
        foreach ($stays as $s) {
            $key = voucher_norm($s['name']);
            if ($key === '') continue;
            if (!isset($g[$key])) $g[$key] = ['name' => $s['name'], 'nights' => 0, 'tokens' => voucher_lodge_tokens($s['name'])];
            $g[$key]['nights'] += (int)($s['nights'] ?? 0);
        }
        return $g;
    };

    $wG = $group($wordStays);
    $xG = $group($excelStays);

    // Programme lodges with no match in the calc.
    foreach ($wG as $w) {
        if ($w['tokens'] === []) continue;
        $hit = false;
        foreach ($xG as $x) { if ($shares($w['tokens'], $x['tokens'])) { $hit = true; break; } }
        if (!$hit) $result['word_only'][] = $w['name'];
    }

    // Calc lodges: unmatched → excel_only; matched → compare aggregated nights.
    foreach ($xG as $x) {
        if ($x['tokens'] === []) continue;
        $matched = false; $wordNights = 0;
        foreach ($wG as $w) {
            if ($w['tokens'] !== [] && $shares($x['tokens'], $w['tokens'])) { $matched = true; $wordNights += $w['nights']; }
        }
        if (!$matched) { $result['excel_only'][] = $x['name']; continue; }
        if ($x['nights'] > 0 && $wordNights > 0 && $wordNights !== $x['nights']) {
            $result['nights_mismatch'][] = ['name' => $x['name'], 'word' => $wordNights, 'excel' => $x['nights']];
        }
    }

    // Per-stay date comparison: pair by token overlap + nearest check-in.
    $wTok = []; foreach ($wordStays as $i => $w)  $wTok[$i] = voucher_lodge_tokens($w['name']);
    $xTok = []; foreach ($excelStays as $j => $x) $xTok[$j] = voucher_lodge_tokens($x['name']);
    $used = [];
    foreach ($wordStays as $i => $w) {
        if ($wTok[$i] === [] || (empty($w['checkin']) && empty($w['checkout']))) continue;
        $best = null; $bestDiff = null;
        foreach ($excelStays as $j => $x) {
            if (isset($used[$j]) || $xTok[$j] === [] || !$shares($wTok[$i], $xTok[$j])) continue;
            $diff = PHP_INT_MAX;
            if (!empty($w['checkin']) && !empty($x['checkin'])) $diff = abs(strtotime($w['checkin']) - strtotime($x['checkin']));
            if ($best === null || $diff < $bestDiff) { $best = $j; $bestDiff = $diff; }
        }
        if ($best === null) continue;
        $used[$best] = true;
        $x = $excelStays[$best];
        if (!empty($w['checkin']) && !empty($x['checkin']) && $w['checkin'] !== $x['checkin']) {
            $result['date_mismatch'][] = ['name' => $w['name'], 'field' => 'Check-in', 'word' => $w['checkin'], 'excel' => $x['checkin']];
        }
        if (!empty($w['checkout']) && !empty($x['checkout']) && $w['checkout'] !== $x['checkout']) {
            $result['date_mismatch'][] = ['name' => $w['name'], 'field' => 'Check-out', 'word' => $w['checkout'], 'excel' => $x['checkout']];
        }
    }

    $result['word_only']  = array_values(array_unique($result['word_only']));
    $result['excel_only'] = array_values(array_unique($result['excel_only']));
    $result['ok'] = ($result['word_only'] === [] && $result['excel_only'] === []
                     && $result['nights_mismatch'] === [] && $result['date_mismatch'] === []);
    return $result;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Model builder
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Build the full voucher model from the two source files (files only — no DB).
 * Call voucher_apply_directory() afterwards to fill GPS from iti_voucher_lodges.
 */
function voucher_build_model(string $docxPath, string $xlsxPath, ?string $sheet = null, string $origDocxName = ''): array
{
    $doc   = voucher_docx_read($docxPath);
    $cells = voucher_xlsx_cells($xlsxPath, $sheet);
    $xl    = voucher_xlsx_extract($cells);

    // ── Header: ref no, consultant, trip year ────────────────────────────────
    $ref = '';
    foreach ($doc['paragraphs'] as $p) {
        if (preg_match('/^Referenze:\s*(.+)$/i', $p, $m)) { $ref = trim($m[1]); break; }
    }

    // Trip start = FROM date of the range line "DD month YYYY - DD month YYYY".
    // Prefer a hyphenated range; ignore "Data di Rilascio" (issue date) lines.
    $startYear = (int)date('Y'); $startMonth = 1; $gotStart = false;
    foreach ($doc['paragraphs'] as $p) {
        if (stripos($p, 'rilascio') !== false) continue;
        if (preg_match('/(\d{1,2})\s+([A-Za-zàèéìòù]+)\s+(\d{4})\s*[-–]\s*\d/u', $p, $m)) {
            $mon = voucher_month_num($m[2]);
            if ($mon) { $startYear = (int)$m[3]; $startMonth = $mon; $gotStart = true; break; }
        }
    }
    if (!$gotStart) { // fallback: first plausible "DD month YYYY" that isn't the issue date
        foreach ($doc['paragraphs'] as $p) {
            if (stripos($p, 'rilascio') !== false) continue;
            if (preg_match('/(\d{1,2})\s+([A-Za-zàèéìòù]+)\s+(\d{4})/u', $p, $m)) {
                $mon = voucher_month_num($m[2]);
                if ($mon) { $startYear = (int)$m[3]; $startMonth = $mon; break; }
            }
        }
    }

    // Consultant from "Numeri Utili" table (header has "Contatto").
    $consultant = ['name' => 'Roberto', 'phone' => '+255 768 900 199', 'email' => 'info@savannahexplorers.com'];
    if ($t = voucher_find_table($doc['tables'], ['Telefono', 'Contatto'])) {
        for ($i = 1; $i < count($t); $i++) {
            $row = $t[$i];
            $name = trim($row[3] ?? '');
            if ($name === '') continue;
            $consultant = [
                'name'  => $name,
                'phone' => trim($row[1] ?? '') ?: $consultant['phone'],
                'email' => trim($row[2] ?? '') ?: $consultant['email'],
            ];
            break;
        }
    }

    // ── Providers (phone/address) from "Elenco Fornitori" ─────────────────────
    $providers = []; // norm(name) => ['name'=>..,'phone'=>..,'address'=>..]
    if ($t = voucher_find_table($doc['tables'], ['Servizi Forniti'])) {
        for ($i = 1; $i < count($t); $i++) {
            $row = $t[$i];
            $nm = trim($row[0] ?? '');
            if ($nm === '') continue;
            $providers[voucher_norm($nm)] = [
                'name'    => $nm,
                'phone'   => trim($row[2] ?? ''),
                'address' => trim($row[3] ?? ''),
            ];
        }
    }
    $providerFor = function (string $lodge) use ($providers): array {
        $key = voucher_norm($lodge);
        if (isset($providers[$key])) return $providers[$key];
        foreach ($providers as $k => $p) {
            if ($k !== '' && (strpos($k, $key) !== false || strpos($key, $k) !== false)) return $p;
        }
        return ['name' => $lodge, 'phone' => '', 'address' => ''];
    };

    // ── Accommodations from "Sistemazioni" (skip own-arrangement) ─────────────
    $accommodations = [];
    if ($t = voucher_find_table($doc['tables'], ['Sistemazioni', 'Destinazione'])) {
        for ($i = 1; $i < count($t); $i++) {
            $row   = $t[$i];
            $lodge = trim($row[0] ?? '');
            if ($lodge === '') continue;
            if (stripos($lodge, 'organizzazione personale') !== false) continue; // own arrangement
            if (stripos($lodge, 'own arrangement') !== false) continue;
            $ci = voucher_parse_it_date($row[2] ?? '', $startYear, $startMonth);
            $co = voucher_parse_it_date($row[3] ?? '', $startYear, $startMonth);
            $prov = $providerFor($lodge);
            $accommodations[] = [
                'lodge'            => $lodge,
                'dest'             => trim($row[1] ?? ''),
                'checkin'          => $ci,
                'checkout'         => $co,
                'nights'           => voucher_nights($ci, $co),
                'meal'             => voucher_meal_label(trim($row[4] ?? '')),
                'room'             => voucher_room_label(trim($row[5] ?? '') ?: $xl['room']),
                'provider_name'    => $prov['name'],
                'provider_phone'   => $prov['phone'],
                'provider_address' => $prov['address'],
                'gps'              => '',       // filled by directory
                'gps_missing'      => false,
            ];
        }
    }

    // ── Flights from "Voli" (each becomes its own flight voucher) ─────────────
    $flights = [];
    if ($t = voucher_find_table($doc['tables'], ['Voli', 'Compagnia aerea'])) {
        for ($i = 1; $i < count($t); $i++) {
            $row = $t[$i];
            $date = voucher_parse_it_date($row[0] ?? '', $startYear, $startMonth);
            $no   = trim(preg_replace('/\(.*?\)/', '', $row[1] ?? '')); // strip "(Programmati)"
            if (!$date || $no === '') continue;
            $flights[] = [
                'date'        => $date,
                'no'          => $no,
                'airline'     => trim($row[2] ?? ''),
                'dep_airport' => trim($row[3] ?? ''),
                'dep_code'    => voucher_airport_code($row[3] ?? ''),
                'dep_time'    => trim($row[4] ?? ''),
                'arr_airport' => trim($row[5] ?? ''),
                'arr_code'    => voucher_airport_code($row[5] ?? ''),
                'arr_time'    => trim($row[6] ?? ''),
            ];
        }
    }
    $flightDeparting = function (?string $date, ?string $airportCode) use ($flights): ?array {
        if (!$date || !$airportCode) return null;
        foreach ($flights as $f) {
            if ($f['date'] === $date && $f['dep_code'] === $airportCode) return $f;
        }
        return null;
    };
    $flightArriving = function (?string $date, ?string $airportCode) use ($flights): ?array {
        if (!$date || !$airportCode) return null;
        foreach ($flights as $f) {
            if ($f['date'] === $date && $f['arr_code'] === $airportCode) return $f;
        }
        return null;
    };

    // ── Transfers from "Trasferimenti" ────────────────────────────────────────
    // Flights get their own vouchers (above), so transfers no longer carry flight
    // numbers automatically — a transfer is a pure ground leg. WeTu sometimes
    // encodes a hotel↔airport ground transfer as airport→same-airport (when the
    // stay is "organizzazione personale", so the hotel name isn't in the files):
    // we swap the hotel side for a placeholder the operator fills on review, and
    // decide arrival vs departure from whether a flight arrives that day.
    $transfers = [];
    if ($t = voucher_find_table($doc['tables'], ['Presa', 'Rilascio'])) {
        for ($i = 1; $i < count($t); $i++) {
            $row  = $t[$i];
            $date = voucher_parse_it_date($row[0] ?? '', $startYear, $startMonth);
            $from = trim($row[2] ?? '');
            $to   = trim($row[3] ?? '');
            if ($from === '' && $to === '') continue;

            $fromCode = voucher_airport_code($from);
            $toCode   = voucher_airport_code($to);
            $hotelMissing = false;

            // Same airport both ends → hotel placeholder on the non-airport side.
            if ($fromCode && $toCode && $fromCode === $toCode) {
                $hotel = voucher_hotel_placeholder($from);
                if ($flightArriving($date, $fromCode)) { // arrival: airport → hotel
                    $to = $hotel;
                } else {                                 // departure: hotel → airport
                    $from = $hotel;
                }
                $hotelMissing = true;
            }

            // Timing note only on departures to the airport (drop-off = airport,
            // pick-up = not an airport). Internal flight → 3.5 h, else 4.5 h.
            $notes = '';
            if (voucher_airport_code($to) && !voucher_airport_code($from)) {
                $notes = voucher_zanzibar_note($to, $flightDeparting($date, voucher_airport_code($to)) !== null);
            }

            $transfers[] = [
                'date'          => $date,
                'from'          => $from,
                'to'            => $to,
                'flight_no'     => '',
                'flight_time'   => '',
                'notes'         => $notes,
                'hotel_missing' => $hotelMissing,
            ];
        }
    }

    // Adults = traveller count (fallback to "N Persone" in the programme).
    $adults = count($xl['travellers']);
    if ($adults === 0) {
        foreach ($doc['paragraphs'] as $p) {
            if (preg_match('/(\d+)\s+Persone/i', $p, $m)) { $adults = (int)$m[1]; break; }
        }
        $adults = max(1, $adults);
    }

    // Advisory cross-check: do the calc-sheet lodges match the programme's?
    $lodgeCheck = voucher_lodge_crosscheck(
        array_map(fn($a) => [
            'name'     => $a['lodge'],
            'nights'   => $a['nights'],
            'checkin'  => $a['checkin'],
            'checkout' => $a['checkout'],
        ], $accommodations),
        voucher_xlsx_stays($cells)
    );

    return [
        'ref'            => $ref ?: pathinfo($origDocxName !== '' ? $origDocxName : $docxPath, PATHINFO_FILENAME),
        'consultant'     => $consultant,
        'travellers'     => $xl['travellers'],
        'adults'         => $adults,
        'dietary'        => $xl['dietary'],
        'accommodations' => $accommodations,
        'flights'        => $flights,
        'transfers'      => $transfers,
        'lodge_check'    => $lodgeCheck,
    ];
}

/** Placeholder name for a hotel WeTu didn't export (own-arrangement stay). */
function voucher_hotel_placeholder(string $airport): string
{
    if (stripos($airport, 'zanzibar') !== false
        || stripos($airport, '[ZNZ]') !== false
        || stripos($airport, 'Abeid Amani') !== false) {
        return 'Zanzibar hotel';
    }
    return 'Hotel';
}

// ─────────────────────────────────────────────────────────────────────────────
//  Lodge directory (GPS / contacts)
// ─────────────────────────────────────────────────────────────────────────────

/** @return array<int, array{name_key:string,display_name:string,phone:string,address:string,gps:string}> */
function voucher_lodge_dir(PDO $db): array
{
    try {
        return $db->query(
            'SELECT name_key, display_name, phone, address, gps
               FROM iti_voucher_lodges WHERE is_active=1'
        )->fetchAll() ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

function voucher_lodge_match(array $dir, string $lodgeName): ?array
{
    $key = voucher_norm($lodgeName);
    if ($key === '') return null;
    foreach ($dir as $row) {
        $nk = $row['name_key'];
        if ($nk !== '' && (strpos($key, $nk) !== false || strpos($nk, $key) !== false)) {
            return $row;
        }
    }
    return null;
}

/** Fill GPS (and phone/address when the Word table left them blank) from the directory. */
function voucher_apply_directory(PDO $db, array &$model): void
{
    $dir = voucher_lodge_dir($db);
    foreach ($model['accommodations'] as &$a) {
        $hit = voucher_lodge_match($dir, $a['lodge']);
        if ($hit) {
            $a['gps'] = $hit['gps'] ?? '';
            if ($a['provider_phone'] === '')   $a['provider_phone']   = $hit['phone']   ?? '';
            if ($a['provider_address'] === '') $a['provider_address'] = $hit['address'] ?? '';
            if (!empty($hit['display_name']))  $a['provider_name']    = $hit['display_name'];
        }
        $a['gps_missing'] = ($a['gps'] === '');
    }
    unset($a);
}

// ─────────────────────────────────────────────────────────────────────────────
//  Rendering — shared bits
// ─────────────────────────────────────────────────────────────────────────────

function voucher_travellers_line(array $model): string
{
    $parts = [];
    foreach ($model['travellers'] as $t) {
        $parts[] = trim(($t['title'] ? $t['title'] . ' ' : '') . $t['name']);
    }
    return implode(', ', array_filter($parts));
}

/**
 * Flight line label for a transfer: "Flight Departs" when it drops at an
 * airport, "Flight Arrives" when it picks up at one, else "Flight". Empty when
 * the transfer carries no flight number.
 */
function voucher_flight_label(array $t): string
{
    if (($t['flight_no'] ?? '') === '') return '';
    if (voucher_airport_code($t['to'] ?? ''))   return 'Flight Departs';
    if (voucher_airport_code($t['from'] ?? '')) return 'Flight Arrives';
    return 'Flight';
}

/** Prominent "service" line for a flight voucher: "DEP → ARR" or "Flight NO". */
function voucher_flight_headline(array $f): string
{
    $dep = $f['dep_airport'] ?? '';
    $arr = $f['arr_airport'] ?? '';
    if ($dep !== '' && $arr !== '') return $dep . ' → ' . $arr;
    return trim('Flight ' . ($f['no'] ?? ''));
}

/** Prominent "service" line for a transfer voucher: the route, or "Transfer". */
function voucher_transfer_headline(array $t): string
{
    $from = $t['from'] ?? '';
    $to   = $t['to'] ?? '';
    if ($from !== '' && $to !== '') return $from . ' → ' . $to;
    if ($from !== '') return $from;
    if ($to !== '')   return $to;
    return 'Transfer';
}

/** Path to the Savannah Explorers logo (reused from the invoices module). */
function voucher_logo_path(): string
{
    return __DIR__ . '/../../invoices/assets/logo_se.png';
}

function voucher_company_name(): string
{
    return 'Savannah Explorers Ltd';
}

/** Standard company phone references printed on every voucher. */
function voucher_company_contacts(): array
{
    return [
        'Office: +255 768 900 199',
        'Emergency Mobile (Tanzania): +255 768 900 199 and +255 747 777 315',
        'Zanzibar transfers: +255 773 053 725',
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
//  Rendering — PDF (Dompdf-compatible HTML)
// ─────────────────────────────────────────────────────────────────────────────

function voucher_render_html(array $model): string
{
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $c = $model['consultant'];
    $travellers = voucher_travellers_line($model);
    $adults = (int)$model['adults'];
    $ref = $model['ref'];

    // Branded header (logo + company contacts), embedded once as base64.
    $logoSrc = '';
    $lp = voucher_logo_path();
    if (is_file($lp)) $logoSrc = 'data:image/png;base64,' . base64_encode((string)file_get_contents($lp));
    $contactLines = voucher_company_contacts();
    if (!empty($c['email'])) $contactLines[] = 'Email: ' . $c['email'];
    $contacts = implode('<br>', array_map($h, $contactLines));
    $brand =
        '<table class="v-brand"><tr>'
      . ($logoSrc ? '<td class="v-brand-logo"><img src="' . $logoSrc . '" style="height:62px;width:62px;"></td>' : '')
      . '<td class="v-brand-info"><div class="v-brand-name">' . $h(strtoupper(voucher_company_name())) . '</div>' . $contacts . '</td>'
      . '</tr></table>';

    // One voucher block: brand → title → highlighted service band → meta → body.
    $voucher = function (string $title, string $headline, string $bodyHtml) use ($brand, $h, $c, $ref): string {
        return
            '<div class="voucher">'
          . $brand
          . '<div class="v-title">' . $h($title) . '</div>'
          . '<div class="v-headline">' . $h($headline) . '</div>'
          . '<div class="v-meta">'
          . 'Our Ref. No.: ' . $h($ref)
          . '</div>'
          . $bodyHtml
          . '</div>';
    };

    $blocks = [];

    foreach ($model['accommodations'] as $a) {
        $tel  = $a['provider_phone']   !== '' ? '<div class="v-row">Lodge Phone: ' . $h($a['provider_phone']) . '</div>' : '';
        $addr = $a['provider_address'] !== '' ? '<div class="v-row">' . $h($a['provider_address']) . '</div>' : '';
        $gps  = $a['gps'] !== '' ? '<div class="v-row">GPS: ' . $h($a['gps']) . '</div>' : '';
        $nights = $a['nights'] === 1 ? '1 Night' : $a['nights'] . ' Nights';
        $body =
            $tel . $addr . $gps
          . '<div class="v-row">Travellers: ' . $h($travellers) . '</div>'
          . '<div class="v-row v-sub">(Adults ' . $adults . ')&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Your Ref no.:</div>'
          . '<div class="v-row">Check In: ' . $h(voucher_fmt_date($a['checkin']))
          . '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Check Out: ' . $h(voucher_fmt_date($a['checkout']))
          . ' (' . $nights . ')</div>'
          . '<div class="v-label">INCLUDED SERVICES:</div>'
          . '<div class="v-row">' . $h($a['room']) . '</div>'
          . '<div class="v-row">Meal Basis: ' . $h($a['meal']) . '</div>'
          . '<div class="v-row">Dietary Requirements: ' . $h($model['dietary']) . '</div>'
          . '<div class="v-foot">All additional services are for guest\'s own account</div>'
          . '<div class="v-row">Notes:</div>';
        $blocks[] = $voucher('ACCOMMODATION VOUCHER - OVERNIGHT', $a['provider_name'], $body);
    }

    foreach ($model['flights'] ?? [] as $f) {
        $dep = trim(($f['dep_airport'] ?? '') . ($f['dep_time'] !== '' ? ', ' . $f['dep_time'] : ''));
        $arr = trim(($f['arr_airport'] ?? '') . ($f['arr_time'] !== '' ? ', ' . $f['arr_time'] : ''));
        $body =
            '<div class="v-row">Travellers: ' . $h($travellers) . '</div>'
          . '<div class="v-row v-sub">(Adults ' . $adults . ')&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Your Ref no.:</div>'
          . '<div class="v-label">FLIGHT DETAILS:</div>'
          . '<div class="v-row">Date: ' . $h(voucher_fmt_date($f['date'])) . '</div>'
          . ($f['airline'] !== '' ? '<div class="v-row">Airline: ' . $h($f['airline']) . '</div>' : '')
          . '<div class="v-row">Flight No.: ' . $h($f['no']) . '</div>'
          . '<div class="v-row">Departure: ' . $h($dep) . '</div>'
          . '<div class="v-row">Arrival: ' . $h($arr) . '</div>'
          . '<div class="v-foot">All additional services are for guest\'s own account</div>'
          . '<div class="v-row">Notes:</div>';
        $blocks[] = $voucher('FLIGHT VOUCHER', voucher_flight_headline($f), $body);
    }

    foreach ($model['transfers'] as $t) {
        $flight = '';
        $flabel = voucher_flight_label($t);
        if ($flabel !== '') {
            $flight = '<div class="v-row">' . $flabel . ': ' . $h($t['flight_no'])
                    . ($t['flight_time'] !== '' ? ', ' . $h($t['flight_time']) : '') . '</div>';
        }
        $body =
            '<div class="v-row">Travellers: ' . $h($travellers) . '</div>'
          . '<div class="v-row v-sub">(Adults ' . $adults . ')&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Your Ref no.:</div>'
          . '<div class="v-label">INCLUDED SERVICES:</div>'
          . '<div class="v-row">Pick Up: ' . $h(voucher_fmt_date($t['date'])) . ', ' . $h($t['from']) . '</div>'
          . '<div class="v-row">Drop Off: ' . $h(voucher_fmt_date($t['date'])) . ', ' . $h($t['to']) . '</div>'
          . $flight
          . '<div class="v-foot">All additional services are for guest\'s own account</div>'
          . '<div class="v-row">Notes: ' . $h($t['notes'] ?? '') . '</div>';
        $blocks[] = $voucher('TRANSPORT VOUCHER - TRANSFER', voucher_transfer_headline($t), $body);
    }

    $body = implode("\n", $blocks);
    if ($body === '') $body = '<div class="voucher"><div class="v-title">No vouchers</div>'
        . '<div class="v-row">No booked accommodation or transfers were found in the programme.</div></div>';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><style>
      * { box-sizing: border-box; }
      body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #1a1a1a; }
      .voucher { page-break-after: always; padding: 4px 2px; }
      .voucher:last-child { page-break-after: auto; }
      .v-brand { width: 100%; margin-bottom: 10px; border-collapse: collapse; }
      .v-brand-logo { width: 72px; vertical-align: top; }
      .v-brand-info { text-align: right; font-size: 9px; line-height: 1.5; color: #333; vertical-align: top; }
      .v-brand-name { font-weight: bold; font-size: 12px; color: #C0211B; margin-bottom: 2px; }
      .v-title { font-size: 15px; font-weight: bold; letter-spacing: .5px;
                 border-bottom: 2px solid #C0211B; padding-bottom: 6px; margin-bottom: 8px; color: #C0211B; }
      .v-headline { font-size: 17px; font-weight: bold; color: #111;
                    border-left: 5px solid #C0211B; padding: 6px 0 6px 12px; margin: 0 0 12px; }
      .v-meta { margin-bottom: 12px; line-height: 1.6; }
      .v-row { margin: 3px 0; line-height: 1.5; }
      .v-sub { color: #555; }
      .v-label { font-weight: bold; margin: 12px 0 4px; }
      .v-foot { font-style: italic; color: #444; margin: 12px 0 6px; }
    </style></head><body>' . $body . '</body></html>';
}

// ─────────────────────────────────────────────────────────────────────────────
//  Rendering — Word (PhpWord)
// ─────────────────────────────────────────────────────────────────────────────

/** Requires PhpWord (already used by export_word.php). Returns a PhpWord object. */
function voucher_render_word(array $model)
{
    $phpWord = new \PhpOffice\PhpWord\PhpWord();
    $RED = 'C0211B'; $GREY = '555555';
    $phpWord->addFontStyle('vTitle',     ['name' => 'Calibri', 'size' => 14, 'bold' => true, 'color' => $RED]);
    $phpWord->addFontStyle('vHeadline',  ['name' => 'Calibri', 'size' => 15, 'bold' => true, 'color' => '111111']);
    $phpWord->addFontStyle('vBase',      ['name' => 'Calibri', 'size' => 10]);
    $phpWord->addFontStyle('vLabel',     ['name' => 'Calibri', 'size' => 10, 'bold' => true]);
    $phpWord->addFontStyle('vSub',       ['name' => 'Calibri', 'size' => 10, 'color' => $GREY]);
    $phpWord->addFontStyle('vFoot',      ['name' => 'Calibri', 'size' => 10, 'italic' => true, 'color' => $GREY]);
    $phpWord->addFontStyle('vBrandName', ['name' => 'Calibri', 'size' => 11, 'bold' => true, 'color' => $RED]);
    $phpWord->addFontStyle('vBrand',     ['name' => 'Calibri', 'size' => 8, 'color' => $GREY]);

    $c = $model['consultant'];
    $travellers = voucher_travellers_line($model);
    $adults = (int)$model['adults'];
    $ref = $model['ref'];
    $logo = voucher_logo_path();
    $contacts = voucher_company_contacts();

    // Branded header + title + highlighted service band + booking meta.
    $addHead = function ($section, string $title, string $headline)
        use ($c, $ref, $logo, $contacts) {
        if (is_file($logo)) {
            $section->addImage($logo, ['height' => 52, 'width' => 52]);
        }
        $section->addText(strtoupper(voucher_company_name()), 'vBrandName');
        foreach ($contacts as $line) $section->addText($line, 'vBrand');
        if (!empty($c['email'])) $section->addText('Email: ' . $c['email'], 'vBrand');
        $section->addTextBreak(1, 'vBrand');
        $section->addText($title, 'vTitle', ['spaceAfter' => 80]);
        $section->addText($headline, 'vHeadline', ['spaceBefore' => 40, 'spaceAfter' => 160]);
        $section->addText('Our Ref. No.: ' . $ref, 'vBase', ['spaceAfter' => 120]);
    };

    $first = true;
    $newSection = function () use ($phpWord, &$first) {
        $s = $phpWord->addSection(['marginTop' => 800, 'marginBottom' => 800, 'marginLeft' => 1000, 'marginRight' => 1000]);
        $first = false;
        return $s;
    };

    foreach ($model['accommodations'] as $a) {
        $s = $newSection();
        $addHead($s, 'ACCOMMODATION VOUCHER - OVERNIGHT', $a['provider_name']);
        if ($a['provider_phone']   !== '') $s->addText('Lodge Phone: ' . $a['provider_phone'], 'vBase');
        if ($a['provider_address'] !== '') $s->addText($a['provider_address'], 'vBase');
        if ($a['gps'] !== '') $s->addText('GPS: ' . $a['gps'], 'vBase');
        $s->addText('Travellers: ' . $travellers, 'vBase');
        $s->addText('(Adults ' . $adults . ')     Your Ref no.:', 'vSub', ['spaceAfter' => 60]);
        $nights = $a['nights'] === 1 ? '1 Night' : $a['nights'] . ' Nights';
        $s->addText('Check In: ' . voucher_fmt_date($a['checkin'])
                  . '     Check Out: ' . voucher_fmt_date($a['checkout']) . ' (' . $nights . ')', 'vBase', ['spaceAfter' => 120]);
        $s->addText('INCLUDED SERVICES:', 'vLabel');
        $s->addText($a['room'], 'vBase');
        $s->addText('Meal Basis: ' . $a['meal'], 'vBase');
        $s->addText('Dietary Requirements: ' . $model['dietary'], 'vBase', ['spaceAfter' => 120]);
        $s->addText('All additional services are for guest\'s own account', 'vFoot');
        $s->addText('Notes:', 'vBase');
    }

    foreach ($model['flights'] ?? [] as $f) {
        $s = $newSection();
        $addHead($s, 'FLIGHT VOUCHER', voucher_flight_headline($f));
        $s->addText('Travellers: ' . $travellers, 'vBase');
        $s->addText('(Adults ' . $adults . ')     Your Ref no.:', 'vSub', ['spaceAfter' => 60]);
        $s->addText('FLIGHT DETAILS:', 'vLabel');
        $s->addText('Date: ' . voucher_fmt_date($f['date']), 'vBase');
        if ($f['airline'] !== '') $s->addText('Airline: ' . $f['airline'], 'vBase');
        $s->addText('Flight No.: ' . $f['no'], 'vBase');
        $s->addText('Departure: ' . trim(($f['dep_airport'] ?? '') . ($f['dep_time'] !== '' ? ', ' . $f['dep_time'] : '')), 'vBase');
        $s->addText('Arrival: ' . trim(($f['arr_airport'] ?? '') . ($f['arr_time'] !== '' ? ', ' . $f['arr_time'] : '')), 'vBase');
        $s->addText('All additional services are for guest\'s own account', 'vFoot', ['spaceBefore' => 120]);
        $s->addText('Notes:', 'vBase');
    }

    foreach ($model['transfers'] as $t) {
        $s = $newSection();
        $addHead($s, 'TRANSPORT VOUCHER - TRANSFER', voucher_transfer_headline($t));
        $s->addText('Travellers: ' . $travellers, 'vBase');
        $s->addText('(Adults ' . $adults . ')     Your Ref no.:', 'vSub', ['spaceAfter' => 60]);
        $s->addText('INCLUDED SERVICES:', 'vLabel');
        $s->addText('Pick Up: ' . voucher_fmt_date($t['date']) . ', ' . $t['from'], 'vBase');
        $s->addText('Drop Off: ' . voucher_fmt_date($t['date']) . ', ' . $t['to'], 'vBase');
        $flabel = voucher_flight_label($t);
        if ($flabel !== '') {
            $s->addText($flabel . ': ' . $t['flight_no'] . ($t['flight_time'] !== '' ? ', ' . $t['flight_time'] : ''), 'vBase');
        }
        $s->addText('All additional services are for guest\'s own account', 'vFoot', ['spaceBefore' => 120]);
        $s->addText('Notes: ' . ($t['notes'] ?? ''), 'vBase');
    }

    if ($first) { // nothing added
        $s = $newSection();
        $s->addText('No vouchers', 'vTitle');
        $s->addText('No booked accommodation or transfers were found in the programme.', 'vBase');
    }

    return $phpWord;
}
