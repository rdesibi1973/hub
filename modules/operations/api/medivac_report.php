<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_permission('operations');

$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';

if (!$from || !$to ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    http_response_code(400); die('Invalid date range.');
}

// ── Fetch data ────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT * FROM medivac_travelers
     WHERE coverage_start >= ? AND coverage_start <= ?
     ORDER BY coverage_start ASC, group_name ASC, full_name ASC'
);
$stmt->execute([$from, $to]);
$rows = $stmt->fetchAll();

if (empty($rows)) {
    http_response_code(204); die('No data for this date range.');
}

// ── XLSX helpers (same pattern as api_export_quote.php) ───────────────────────
function xlsx_esc(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
function col_letter(int $n): string {
    $letters = ''; $n++;
    while ($n > 0) { $n--; $letters = chr(65 + ($n % 26)) . $letters; $n = intdiv($n, 26); }
    return $letters;
}
$sst = []; $sstMap = [];
function ss(string $s): int {
    global $sst, $sstMap;
    if (!isset($sstMap[$s])) { $sstMap[$s] = count($sst); $sst[] = $s; }
    return $sstMap[$s];
}
// Styles: 0=normal 1=bold 2=dark-header(white bold) 3=subheader 4=data-row 5=title 6=label-bold
function cS(string $v, int $st=0): array { return ['s', ss($v), $st]; }
function cN(float  $v, int $st=0): array { return ['n', $v, $st]; }
function cB(): array { return ['b','',0]; }

function fmtDate(?string $d): string {
    if (!$d) return '';
    try { return (new DateTime($d))->format('d/m/Y'); } catch(Exception $e) { return $d; }
}

// ── Build rows ────────────────────────────────────────────────────────────────
$xlRows = [];

// Title
$xlRows[] = [cS('Arusha Medivac Monthly Corporate Tourist Tracker', 5)];
$xlRows[] = [cB()];
$xlRows[] = [cS('Tour Company:', 6), cS('Savannah Explorers Limited', 1)];
$xlRows[] = [cB()];
$xlRows[] = [cB()];

// Sub-header (date format hints)
$xlRows[] = [cB(), cB(), cB(), cB(), cB(), cS('(dd/mm/yyyy)', 3), cS('(dd/mm/yyyy)', 3)];

// Column headers
$xlRows[] = array_map(fn($h) => cS($h, 2), [
    'Name', 'DOB (dd/mm/yyyy)', 'Nationality', 'Passport #',
    'Tour Agent', 'Coverage Start Date', 'Coverage End Date',
    'Group Name', 'Insurance Name', 'Policy #'
]);

// Data rows — alternate fill per group
$lastGroup   = null;
$groupToggle = false;  // false=white(style 0), true=light-blue(style 7)

foreach ($rows as $r) {
    $grp = $r['group_name'] ?? '';
    if ($grp !== $lastGroup) {
        $groupToggle = !$groupToggle;
        $lastGroup   = $grp;
    }
    $st = $groupToggle ? 7 : 0;   // 7=light-blue fill, 0=white
    $xlRows[] = [
        cS($r['full_name']       ?? '', $st),
        cS(fmtDate($r['dob']),          $st),
        cS($r['country']         ?? '', $st),
        cS('', $st),
        cS($r['tour_agent']      ?? '', $st),
        cS(fmtDate($r['coverage_start']), $st),
        cS(fmtDate($r['coverage_end']),   $st),
        cS($r['group_name']      ?? '', $st),
        cS($r['insurance_name']  ?? '', $st),
        cS($r['policy_number']   ?? '', $st),
    ];
}

// ── Column widths ─────────────────────────────────────────────────────────────
$colWidths = [30, 18, 18, 18, 18, 22, 22, 28, 22, 16];

// ── Generate XML ──────────────────────────────────────────────────────────────
$colsXml = '';
foreach ($colWidths as $i => $w) {
    $colsXml .= '<col min="'.($i+1).'" max="'.($i+1).'" width="'.$w.'" customWidth="1"/>';
}

$sheetXml = '<?xml version="1.0" encoding="UTF-8"?>'
. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
. '<cols>' . $colsXml . '</cols>'
. '<sheetData>';

foreach ($xlRows as $ri => $row) {
    $rowNum = $ri + 1;
    $sheetXml .= '<row r="'.$rowNum.'">';
    foreach ($row as $ci => $cell) {
        [$type, $val, $style] = $cell;
        $ref = col_letter($ci) . $rowNum;
        if ($type === 'b' || $val === '') continue;
        if ($type === 's') {
            $sheetXml .= '<c r="'.$ref.'" t="s" s="'.$style.'"><v>'.(int)$val.'</v></c>';
        } else {
            $sheetXml .= '<c r="'.$ref.'" s="'.$style.'"><v>'.xlsx_esc((string)$val).'</v></c>';
        }
    }
    $sheetXml .= '</row>';
}
$sheetXml .= '</sheetData></worksheet>';

// Shared strings
$sstXml = '<?xml version="1.0" encoding="UTF-8"?>'
. '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($sst).'" uniqueCount="'.count($sst).'">';
foreach ($sst as $s) $sstXml .= '<si><t xml:space="preserve">'.xlsx_esc($s).'</t></si>';
$sstXml .= '</sst>';

// Styles
// Fonts: 0=normal 1=bold 2=white-bold 3=title-bold 4=grey-italic
// Fills: 0=none 1=gray125 2=dark-navy(header) 3=light-grey(subheader)
$stylesXml = '<?xml version="1.0" encoding="UTF-8"?>'
.'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
.'<fonts count="5">'
  .'<font><sz val="11"/><name val="Calibri"/></font>'
  .'<font><sz val="11"/><b/><name val="Calibri"/></font>'
  .'<font><sz val="11"/><b/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
  .'<font><sz val="14"/><b/><name val="Calibri"/></font>'
  .'<font><sz val="10"/><color rgb="FF888888"/><name val="Calibri"/></font>'
.'</fonts>'
.'<fills count="5">'
  .'<fill><patternFill patternType="none"/></fill>'
  .'<fill><patternFill patternType="gray125"/></fill>'
  .'<fill><patternFill patternType="solid"><fgColor rgb="FF1A1A2E"/></patternFill></fill>'
  .'<fill><patternFill patternType="solid"><fgColor rgb="FFF0F0F0"/></patternFill></fill>'
  .'<fill><patternFill patternType="solid"><fgColor rgb="FFD6E8F7"/></patternFill></fill>'  // light blue group alt
.'</fills>'
.'<borders count="2">'
  .'<border><left/><right/><top/><bottom/><diagonal/></border>'
  .'<border>'
    .'<left style="thin"><color rgb="FFCCCCCC"/></left>'
    .'<right style="thin"><color rgb="FFCCCCCC"/></right>'
    .'<top style="thin"><color rgb="FFCCCCCC"/></top>'
    .'<bottom style="thin"><color rgb="FFCCCCCC"/></bottom>'
  .'</border>'
.'</borders>'
.'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
.'<cellXfs count="8">'
  .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/>'                          // 0 normal+border (white)
  .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>'                          // 1 bold
  .'<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0"><alignment horizontal="center"/></xf>' // 2 dark-header white bold
  .'<xf numFmtId="0" fontId="4" fillId="3" borderId="0" xfId="0"><alignment horizontal="center"/></xf>' // 3 subheader grey italic
  .'<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0"/>'                          // 4 data-alt (unused)
  .'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0"/>'                          // 5 title
  .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>'                          // 6 label-bold
  .'<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0"/>'                          // 7 light-blue group alt
.'</cellXfs>'
.'</styleSheet>';

$workbookXml = '<?xml version="1.0" encoding="UTF-8"?>'
.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
.'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
.'<sheets><sheet name="Medivac Tracker" sheetId="1" r:id="rId1"/></sheets>'
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
.'<Override PartName="/xl/workbook.xml"          ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
.'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
.'<Override PartName="/xl/sharedStrings.xml"     ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
.'<Override PartName="/xl/styles.xml"            ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
.'</Types>';

// ── Assemble ZIP ──────────────────────────────────────────────────────────────
$tmpFile = tempnam(sys_get_temp_dir(), 'medivac_');
$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500); die('Cannot create archive.');
}
$zip->addFromString('[Content_Types].xml',        $contentTypes);
$zip->addFromString('_rels/.rels',                 $rootRels);
$zip->addFromString('xl/workbook.xml',             $workbookXml);
$zip->addFromString('xl/_rels/workbook.xml.rels',  $workbookRels);
$zip->addFromString('xl/worksheets/sheet1.xml',    $sheetXml);
$zip->addFromString('xl/sharedStrings.xml',        $sstXml);
$zip->addFromString('xl/styles.xml',               $stylesXml);
$zip->close();

$filename = 'Medivac_Tracker_' . str_replace('-', '', $from) . '_' . str_replace('-', '', $to) . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: no-cache, no-store');
readfile($tmpFile);
unlink($tmpFile);
