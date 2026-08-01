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

    // ── Flights from "Voli" ───────────────────────────────────────────────────
    $flights = [];
    if ($t = voucher_find_table($doc['tables'], ['Voli', 'Compagnia aerea'])) {
        for ($i = 1; $i < count($t); $i++) {
            $row = $t[$i];
            $date = voucher_parse_it_date($row[0] ?? '', $startYear, $startMonth);
            $no   = trim(preg_replace('/\(.*?\)/', '', $row[1] ?? '')); // strip "(Programmati)"
            if (!$date || $no === '') continue;
            $flights[] = [
                'date'      => $date,
                'no'        => $no,
                'dep_code'  => voucher_airport_code($row[3] ?? ''),
                'dep_time'  => trim($row[4] ?? ''),
                'arr_code'  => voucher_airport_code($row[5] ?? ''),
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

    // ── Transfers from "Trasferimenti" ────────────────────────────────────────
    $transfers = [];
    if ($t = voucher_find_table($doc['tables'], ['Presa', 'Rilascio'])) {
        for ($i = 1; $i < count($t); $i++) {
            $row  = $t[$i];
            $date = voucher_parse_it_date($row[0] ?? '', $startYear, $startMonth);
            $from = trim($row[2] ?? '');
            $to   = trim($row[3] ?? '');
            if ($from === '' && $to === '') continue;
            $fl = $flightDeparting($date, voucher_airport_code($to));
            $transfers[] = [
                'date'         => $date,
                'from'         => $from,
                'to'           => $to,
                'flight_no'    => $fl['no'] ?? '',
                'flight_time'  => $fl['dep_time'] ?? '',
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

    return [
        'ref'            => $ref ?: pathinfo($origDocxName !== '' ? $origDocxName : $docxPath, PATHINFO_FILENAME),
        'consultant'     => $consultant,
        'travellers'     => $xl['travellers'],
        'adults'         => $adults,
        'dietary'        => $xl['dietary'],
        'accommodations' => $accommodations,
        'transfers'      => $transfers,
    ];
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

    $blocks = [];

    $headBlock = function (string $title) use ($h, $c, $ref): string {
        return
            '<div class="v-title">' . $h($title) . '</div>'
          . '<div class="v-meta">'
          . 'Consultant: ' . $h($c['name']) . ', ' . $h($c['phone']) . '<br>'
          . 'Email: ' . $h($c['email']) . '<br>'
          . 'Our Ref. No.: ' . $h($ref)
          . '</div>';
    };

    foreach ($model['accommodations'] as $a) {
        $prov = $h($a['provider_name']);
        if ($a['provider_phone'] !== '') $prov .= ', ' . $h($a['provider_phone']);
        $addr = $a['provider_address'] !== '' ? '<div>' . $h($a['provider_address']) . '</div>' : '';
        $gps  = $a['gps'] !== '' ? '<div>GPS: ' . $h($a['gps']) . '</div>' : '';
        $nights = $a['nights'] === 1 ? '1 Night' : $a['nights'] . ' Nights';
        $blocks[] =
            '<div class="voucher">'
          . $headBlock('ACCOMMODATION VOUCHER - OVERNIGHT')
          . '<div class="v-row">Provider: ' . $prov . '</div>'
          . '<div class="v-row">Travellers: ' . $h($travellers) . '</div>'
          . '<div class="v-row v-sub">(Adults ' . $adults . ')&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Your Ref no.:</div>'
          . $addr . $gps
          . '<div class="v-row">Check In: ' . $h(voucher_fmt_date($a['checkin']))
          . '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Check Out: ' . $h(voucher_fmt_date($a['checkout']))
          . ' (' . $nights . ')</div>'
          . '<div class="v-label">INCLUDED SERVICES:</div>'
          . '<div class="v-row">' . $h($a['room']) . '</div>'
          . '<div class="v-row">Meal Basis: ' . $h($a['meal']) . '</div>'
          . '<div class="v-row">Dietary Requirements: ' . $h($model['dietary']) . '</div>'
          . '<div class="v-foot">All additional services are for guest\'s own account</div>'
          . '<div class="v-row">Notes:</div>'
          . '</div>';
    }

    foreach ($model['transfers'] as $t) {
        $flight = '';
        if ($t['flight_no'] !== '') {
            $flight = '<div class="v-row">Flight Departs: ' . $h($t['flight_no'])
                    . ($t['flight_time'] !== '' ? ', ' . $h($t['flight_time']) : '') . '</div>';
        }
        $blocks[] =
            '<div class="voucher">'
          . $headBlock('TRANSPORT VOUCHER - TRANSFER')
          . '<div class="v-row">Provider:</div>'
          . '<div class="v-row">Travellers: ' . $h($travellers) . '</div>'
          . '<div class="v-row v-sub">(Adults ' . $adults . ')&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Your Ref no.:</div>'
          . '<div class="v-label">INCLUDED SERVICES:</div>'
          . '<div class="v-row">Pick Up: ' . $h(voucher_fmt_date($t['date'])) . ', ' . $h($t['from']) . '</div>'
          . '<div class="v-row">Drop Off: ' . $h(voucher_fmt_date($t['date'])) . ', ' . $h($t['to']) . '</div>'
          . $flight
          . '<div class="v-foot">All additional services are for guest\'s own account</div>'
          . '<div class="v-row">Notes:</div>'
          . '</div>';
    }

    $body = implode("\n", $blocks);
    if ($body === '') $body = '<div class="voucher"><div class="v-title">No vouchers</div>'
        . '<div class="v-row">No booked accommodation or transfers were found in the programme.</div></div>';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><style>
      * { box-sizing: border-box; }
      body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #1a1a1a; }
      .voucher { page-break-after: always; padding: 4px 2px; }
      .voucher:last-child { page-break-after: auto; }
      .v-title { font-size: 15px; font-weight: bold; letter-spacing: .5px;
                 border-bottom: 2px solid #C0211B; padding-bottom: 6px; margin-bottom: 10px; color: #C0211B; }
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
    $phpWord->addFontStyle('vTitle', ['name' => 'Calibri', 'size' => 14, 'bold' => true, 'color' => $RED]);
    $phpWord->addFontStyle('vBase',  ['name' => 'Calibri', 'size' => 10]);
    $phpWord->addFontStyle('vLabel', ['name' => 'Calibri', 'size' => 10, 'bold' => true]);
    $phpWord->addFontStyle('vSub',   ['name' => 'Calibri', 'size' => 10, 'color' => $GREY]);
    $phpWord->addFontStyle('vFoot',  ['name' => 'Calibri', 'size' => 10, 'italic' => true, 'color' => $GREY]);

    $c = $model['consultant'];
    $travellers = voucher_travellers_line($model);
    $adults = (int)$model['adults'];
    $ref = $model['ref'];

    $addHead = function ($section, string $title) use ($c, $ref) {
        $section->addText($title, 'vTitle', ['spaceAfter' => 120]);
        $section->addText('Consultant: ' . $c['name'] . ', ' . $c['phone'], 'vBase');
        $section->addText('Email: ' . $c['email'], 'vBase');
        $section->addText('Our Ref. No.: ' . $ref, 'vBase', ['spaceAfter' => 120]);
    };

    $first = true;
    $newSection = function () use ($phpWord, &$first) {
        $s = $phpWord->addSection(['marginTop' => 900, 'marginBottom' => 900, 'marginLeft' => 1000, 'marginRight' => 1000]);
        $first = false;
        return $s;
    };

    foreach ($model['accommodations'] as $a) {
        $s = $newSection();
        $addHead($s, 'ACCOMMODATION VOUCHER - OVERNIGHT');
        $prov = $a['provider_name'] . ($a['provider_phone'] !== '' ? ', ' . $a['provider_phone'] : '');
        $s->addText('Provider: ' . $prov, 'vBase');
        $s->addText('Travellers: ' . $travellers, 'vBase');
        $s->addText('(Adults ' . $adults . ')     Your Ref no.:', 'vSub', ['spaceAfter' => 60]);
        if ($a['provider_address'] !== '') $s->addText($a['provider_address'], 'vBase');
        if ($a['gps'] !== '') $s->addText('GPS: ' . $a['gps'], 'vBase');
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

    foreach ($model['transfers'] as $t) {
        $s = $newSection();
        $addHead($s, 'TRANSPORT VOUCHER - TRANSFER');
        $s->addText('Provider:', 'vBase');
        $s->addText('Travellers: ' . $travellers, 'vBase');
        $s->addText('(Adults ' . $adults . ')     Your Ref no.:', 'vSub', ['spaceAfter' => 60]);
        $s->addText('INCLUDED SERVICES:', 'vLabel');
        $s->addText('Pick Up: ' . voucher_fmt_date($t['date']) . ', ' . $t['from'], 'vBase');
        $s->addText('Drop Off: ' . voucher_fmt_date($t['date']) . ', ' . $t['to'], 'vBase');
        if ($t['flight_no'] !== '') {
            $s->addText('Flight Departs: ' . $t['flight_no'] . ($t['flight_time'] !== '' ? ', ' . $t['flight_time'] : ''), 'vBase');
        }
        $s->addText('All additional services are for guest\'s own account', 'vFoot', ['spaceBefore' => 120]);
        $s->addText('Notes:', 'vBase');
    }

    if ($first) { // nothing added
        $s = $newSection();
        $s->addText('No vouchers', 'vTitle');
        $s->addText('No booked accommodation or transfers were found in the programme.', 'vBase');
    }

    return $phpWord;
}
