<?php
require_once 'config.php';

requireLogin();
$isStaff      = isLeadsRestricted();
$staffAgentId = $isStaff ? getStaffAgentId() : 0;

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); die('Missing id.'); }

$db = db();

// Load quote
$q = $db->prepare("SELECT * FROM quotes WHERE id = ?");
$q->execute([$id]);
$quote = $q->fetch();
if (!$quote) { http_response_code(404); die('Quote not found.'); }

// Staff access check
if ($isStaff && (int)$quote['agent_id'] !== $staffAgentId) {
    http_response_code(403); die('Access denied.');
}

// Load days + items
$dStmt = $db->prepare("SELECT * FROM quote_days WHERE quote_id = ? ORDER BY day_number");
$dStmt->execute([$id]);
$days = $dStmt->fetchAll();

$iStmt = $db->prepare("SELECT * FROM quote_day_items WHERE quote_day_id IN (SELECT id FROM quote_days WHERE quote_id = ?) ORDER BY quote_day_id, id");
$iStmt->execute([$id]);
$allItems = $iStmt->fetchAll();
$itemsByDay = [];
foreach ($allItems as $item) $itemsByDay[$item['quote_day_id']][] = $item;

$pax   = (int)$quote['adults'] + (int)$quote['teens'] + (int)$quote['children'];
$mk    = (float)$quote['markup_pct'] / 100;
$ppp   = $pax > 0 ? (float)$quote['total_price'] / $pax : 0;
$pppTo = $pax > 0 ? (float)$quote['total_costs'] * 1.18 / $pax : 0;
$dep   = (float)$quote['total_price'] * 0.3;
$single = ($quote['program'] === 'beachkiboko') ? 650 : 250;

$cleanName = preg_replace('/\s+/', '', $quote['customer_name']);
$filename  = $quote['quote_number'] . '_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $cleanName) . '.xlsx';

// ── Build XLSX ────────────────────────────────────────────────────────────────
// Minimal XLSX using ZipArchive + OpenXML

function xlsx_esc(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function col_letter(int $n): string { // 0-based
    $letters = '';
    $n++;
    while ($n > 0) {
        $n--;
        $letters = chr(65 + ($n % 26)) . $letters;
        $n = intdiv($n, 26);
    }
    return $letters;
}

// Shared strings collector
$sst = [];
$sstMap = [];
function ss(string $s): int {
    global $sst, $sstMap;
    if (!isset($sstMap[$s])) {
        $sstMap[$s] = count($sst);
        $sst[] = $s;
    }
    return $sstMap[$s];
}

// Cell builders: returns [type, value, styleId]
// Styles: 0=normal, 1=bold, 2=green-header(white bold on green), 3=right-num, 4=bold-right, 5=title
function cStr(string $v, int $style = 0): array { return ['s', ss($v), $style]; }
function cNum(float $v, int $style = 3): array  { return ['n', $v, $style]; }
function cBlank(): array { return ['b', '', 0]; }

// ── Build rows ────────────────────────────────────────────────────────────────
$rows = [];

// Title
$rows[] = [cStr('SAVANNAH EXPLORERS — Safari Quote', 5)];
$rows[] = [cStr('Quote #' . $quote['quote_number'] . '  —  ' . $quote['customer_name'], 1)];
$rows[] = [cBlank()];

// Info block
$rows[] = [cStr('Program', 1), cStr($quote['program'] ?? '—')];
$rows[] = [cStr('Agent',   1), cStr($quote['agent_name'] ?? '—')];
$rows[] = [cStr('Agency',  1), cStr($quote['agency_name'] ?? '—')];
$rows[] = [cStr('Start Date', 1), cStr($quote['start_date'] ? date('d M Y', strtotime($quote['start_date'])) : '—')];
$rows[] = [cStr('Participants', 1),
           cStr($quote['adults'].' Adults, '.$quote['teens'].' Teenagers, '.$quote['children'].' Children = '.$pax.' PAX')];
$rows[] = [cBlank()];

// Day table header
$rows[] = array_map(function($h){ return cStr($h, 2); },
    ['Day', 'Date', 'Location', 'Lodge', 'Jeep', 'Park Fees', 'Flight', 'Drinks', 'Activities / Extras', 'Day Total']);

// Day rows
foreach ($days as $d) {
    $dayItems = $itemsByDay[$d['id']] ?? [];
    $dateStr = '';
    if ($quote['start_date']) {
        $dt = new DateTime($quote['start_date']);
        $dt->modify('+' . (((int)$d['day_number'] - 1)) . ' days');
        $dateStr = $dt->format('d M Y');
    }
    $itemsStr = implode('; ', array_map(function($it) {
        return $it['description'] . ' ($' . number_format((float)$it['amount'], 0) . ($it['item_type']==='pax'?'/pax':' fixed') . ')';
    }, $dayItems));

    $rows[] = [
        cStr((string)$d['day_number']),
        cStr($dateStr),
        cStr($d['route'] ?? $d['location'] ?? ''),
        cStr($d['lodge']    ?? ''),
        cStr(ucfirst($d['jeep'])),
        cStr($d['park'] !== 'none' ? ucfirst($d['park']) : '—'),
        cStr($d['flight'] !== 'none' ? ucfirst($d['flight']) : '—'),
        cStr($d['drinks'] ? 'Yes' : 'No'),
        cStr($itemsStr),
        cNum((float)$d['day_total']),
    ];
}

// Spacer + totals
$rows[] = [cBlank()];
$rows[] = [cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(),
           cStr('Bank Commission', 1),
           cNum((float)$quote['bank_commission'])];
$rows[] = [cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(),
           cStr('Net Total Costs', 1),
           cNum((float)$quote['total_costs'])];
$rows[] = [cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(),
           cStr('Markup (' . number_format($quote['markup_pct'], 0) . '%)', 1),
           cNum((float)$quote['total_costs'] * $mk)];

// Grand total row — style 2 (green bg)
$rows[] = array_merge(
    array_fill(0, 8, cStr('', 2)),
    [cStr('TOTAL PRICE', 2), cNum((float)$quote['total_price'], 4)]
);

$rows[] = [cBlank()];
$rows[] = [cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(),
           cStr('Price per person (rack)', 1), cNum($ppp)];
$rows[] = [cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(),
           cStr('Price per person (T.O.)', 1), cNum($pppTo)];
$rows[] = [cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(),
           cStr('Single supplement', 1), cNum($single)];
$rows[] = [cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(), cBlank(),
           cStr('Deposit (30%)', 1), cNum($dep)];

// ── Generate XML files ────────────────────────────────────────────────────────

// Worksheet XML
$sheetXml = '<?xml version="1.0" encoding="UTF-8"?>'
. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
. '<sheetData>';
foreach ($rows as $ri => $row) {
    $rowNum = $ri + 1;
    $sheetXml .= '<row r="' . $rowNum . '">';
    foreach ($row as $ci => $cell) {
        [$type, $val, $style] = $cell;
        $ref = col_letter($ci) . $rowNum;
        if ($type === 'b' || $val === '') { $sheetXml .= ''; continue; }
        if ($type === 's') {
            $sheetXml .= '<c r="'.$ref.'" t="s" s="'.$style.'"><v>'.(int)$val.'</v></c>';
        } else {
            $sheetXml .= '<c r="'.$ref.'" s="'.$style.'"><v>'.xlsx_esc((string)$val).'</v></c>';
        }
    }
    $sheetXml .= '</row>';
}
$sheetXml .= '</sheetData></worksheet>';

// Shared strings XML
$sstXml = '<?xml version="1.0" encoding="UTF-8"?>'
. '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($sst).'" uniqueCount="'.count($sst).'">';
foreach ($sst as $s) {
    $sstXml .= '<si><t xml:space="preserve">'.xlsx_esc($s).'</t></si>';
}
$sstXml .= '</sst>';

// Styles XML (6 styles: 0=normal, 1=bold, 2=green-bg white-bold, 3=right-num, 4=bold-right-num, 5=big-title)
$stylesXml = '<?xml version="1.0" encoding="UTF-8"?>'
.'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
.'<fonts count="4">'
  .'<font><sz val="11"/><name val="Calibri"/></font>'                              // 0 normal
  .'<font><sz val="11"/><b/><name val="Calibri"/></font>'                          // 1 bold
  .'<font><sz val="11"/><b/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'   // 2 white bold
  .'<font><sz val="14"/><b/><name val="Calibri"/></font>'                          // 3 title
.'</fonts>'
.'<fills count="4">'
  .'<fill><patternFill patternType="none"/></fill>'
  .'<fill><patternFill patternType="gray125"/></fill>'
  .'<fill><patternFill patternType="solid"><fgColor rgb="FFC0211B"/></patternFill></fill>'  // green
  .'<fill><patternFill patternType="solid"><fgColor rgb="FFFEF2F2"/></patternFill></fill>'  // lt green
.'</fills>'
.'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
.'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
.'<cellXfs count="6">'
  .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                           // 0 normal
  .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>'                           // 1 bold
  .'<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0"/>'                           // 2 green bg white bold
  .'<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0"><alignment horizontal="right"/></xf>' // 3 right num (#,##0.00)
  .'<xf numFmtId="4" fontId="1" fillId="2" borderId="0" xfId="0"><alignment horizontal="right"/></xf>' // 4 bold right green bg
  .'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0"/>'                           // 5 big title
.'</cellXfs>'
.'</styleSheet>';

$workbookXml = '<?xml version="1.0" encoding="UTF-8"?>'
.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
.'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
.'<sheets><sheet name="Quote" sheetId="1" r:id="rId1"/></sheets>'
.'</workbook>';

$workbookRels = '<?xml version="1.0" encoding="UTF-8"?>'
.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
.'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
.'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
.'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
.'</Relationships>';

$rootRels = '<?xml version="1.0" encoding="UTF-8"?>'
.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
.'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
.'</Relationships>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8"?>'
.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
.'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
.'<Default Extension="xml"  ContentType="application/xml"/>'
.'<Override PartName="/xl/workbook.xml"           ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
.'<Override PartName="/xl/worksheets/sheet1.xml"  ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
.'<Override PartName="/xl/sharedStrings.xml"      ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
.'<Override PartName="/xl/styles.xml"             ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
.'</Types>';

// ── ZIP assembly ──────────────────────────────────────────────────────────────
$tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500); die('Cannot create ZIP archive.');
}
$zip->addFromString('[Content_Types].xml',         $contentTypes);
$zip->addFromString('_rels/.rels',                  $rootRels);
$zip->addFromString('xl/workbook.xml',              $workbookXml);
$zip->addFromString('xl/_rels/workbook.xml.rels',   $workbookRels);
$zip->addFromString('xl/worksheets/sheet1.xml',     $sheetXml);
$zip->addFromString('xl/sharedStrings.xml',         $sstXml);
$zip->addFromString('xl/styles.xml',                $stylesXml);
$zip->close();

// ── Stream response ───────────────────────────────────────────────────────────
$safeFilename = rawurlencode($filename);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . $safeFilename);
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: no-cache, no-store');
readfile($tmpFile);
unlink($tmpFile);
