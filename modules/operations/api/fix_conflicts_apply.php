<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_permission('operations');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); die('Method Not Allowed');
}
if (empty($_FILES['file']['tmp_name'])) {
    http_response_code(400); die('No file uploaded.');
}

$sheetName = $_POST['sheet']    ?? '';
$changes   = json_decode($_POST['changes'] ?? '[]', true);
$origName  = $_POST['filename'] ?? 'fixed.xlsx';

if (!is_array($changes)) {
    http_response_code(400); die('Invalid changes payload.');
}

// ── Copy to temp working file ─────────────────────────────────────────────────
$workFile = tempnam(sys_get_temp_dir(), 'fixcf_') . '.xlsx';
if (!move_uploaded_file($_FILES['file']['tmp_name'], $workFile)) {
    http_response_code(500); die('Cannot save uploaded file.');
}

$zip = new ZipArchive();
if ($zip->open($workFile) !== true) {
    unlink($workFile);
    http_response_code(500); die('Cannot open xlsx as zip.');
}

// ── Resolve target sheet filename via workbook.xml.rels ───────────────────────
$sheetFile = null;

$wbXml = $zip->getFromName('xl/workbook.xml');
$relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

if ($wbXml && $relsXml && $sheetName !== '') {
    $wbDom = new DOMDocument();
    @$wbDom->loadXML($wbXml);
    $xpath = new DOMXPath($wbDom);
    $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

    $rId = null;
    foreach ($wbDom->getElementsByTagName('sheet') as $s) {
        if ($s->getAttribute('name') === $sheetName) {
            $rId = $s->getAttribute('r:id');
            break;
        }
    }

    if ($rId) {
        $relsDom = new DOMDocument();
        @$relsDom->loadXML($relsXml);
        foreach ($relsDom->getElementsByTagName('Relationship') as $rel) {
            if ($rel->getAttribute('Id') === $rId) {
                $target = $rel->getAttribute('Target');
                // Target may be relative like "worksheets/sheet1.xml"
                $sheetFile = 'xl/' . ltrim($target, '/');
                break;
            }
        }
    }
}

// Fallback to first sheet
if (!$sheetFile) {
    $sheetFile = 'xl/worksheets/sheet1.xml';
}

// ── Load sheet XML ────────────────────────────────────────────────────────────
$sheetXml = $zip->getFromName($sheetFile);
if ($sheetXml === false) {
    $zip->close(); unlink($workFile);
    http_response_code(500); die('Sheet XML not found: ' . $sheetFile);
}

$dom = new DOMDocument('1.0', 'UTF-8');
$dom->preserveWhiteSpace = false;
if (!@$dom->loadXML($sheetXml)) {
    $zip->close(); unlink($workFile);
    http_response_code(500); die('Cannot parse sheet XML.');
}

$xp  = new DOMXPath($dom);
$ns  = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
$xp->registerNamespace('x', $ns);

// ── Apply each change ─────────────────────────────────────────────────────────
foreach ($changes as $change) {
    $addr   = preg_replace('/[^A-Z0-9]/i', '', $change['addr'] ?? '');
    $newVal = (string)($change['value'] ?? '');
    if ($addr === '') continue;

    // Find cell node
    $cellNodes = $xp->query("//x:c[@r='{$addr}']");
    if ($cellNodes->length === 0) continue;
    $cell = $cellNodes->item(0);

    // Remove formula (f), cached value (v), inline string (is)
    foreach (['f', 'v', 'is'] as $tag) {
        $kids = $xp->query("x:{$tag}", $cell);
        foreach ($kids as $kid) $cell->removeChild($kid);
    }

    if ($newVal === '') {
        // Leave cell empty (keep style attributes)
        $cell->removeAttribute('t');
    } elseif (is_numeric($newVal) && !preg_match('/^0\d/', $newVal)) {
        // Numeric — remove type attr (defaults to number)
        $cell->removeAttribute('t');
        $vNode = $dom->createElement('v');
        $vNode->appendChild($dom->createTextNode($newVal));
        $cell->appendChild($vNode);
    } else {
        // String — use inline string to avoid touching sharedStrings.xml
        $cell->setAttribute('t', 'inlineStr');
        $isNode = $dom->createElement('is');
        $tNode  = $dom->createElement('t');
        $tNode->appendChild($dom->createTextNode($newVal));
        $isNode->appendChild($tNode);
        $cell->appendChild($isNode);
    }
}

// ── Write modified sheet back into zip ───────────────────────────────────────
$zip->addFromString($sheetFile, $dom->saveXML());
$zip->close();

// ── Stream to client ──────────────────────────────────────────────────────────
$stem    = preg_replace('/\s*\([^)]*conflicted copy[^)]*\)/i', '', $origName);
$stem    = preg_replace('/\.xlsx$/i', '', $stem);
$outName = trim($stem) . '_FIXED.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . rawurlencode($outName) . '"');
header('Content-Length: ' . filesize($workFile));
header('Cache-Control: no-store');
readfile($workFile);
unlink($workFile);
