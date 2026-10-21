<?php
/**
 * iti_guide_word.php — the guide sheet as an editable Word file (.docx), so the office can
 * correct it by hand before printing. Same content and order as iti_guide_pdf.php
 * (recap of transfers / flights and accommodation, then the day by day), black and white,
 * no photos. Data: iti_guide_data(iti_doc_data()).
 *
 * Keep PHP-7 style (no match / arrow functions / str_contains).
 */
require_once __DIR__ . '/iti_guide_pdf.php';   // labels, iti_guide_data(), iti_mag_* / iti_mw_paras()

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;

/** Lines into a cell / container: each [text, font] pair on its own line. */
function iti_gw_lines($c, array $lines): void {
    $run = $c->addTextRun('gwTight');
    foreach ($lines as $i => $l) {
        if ($i) $run->addTextBreak();
        foreach (preg_split('/\n/u', (string)$l[0]) as $k => $part) {
            if ($k) $run->addTextBreak();
            $run->addText(trim($part), $l[1]);
        }
    }
}

/** Build the guide Word file and return its bytes. */
function iti_guide_word_build(array $D): string {
    if (!class_exists('\PhpOffice\PhpWord\PhpWord')) throw new RuntimeException('PhpWord is not installed (vendor/autoload.php).');
    Settings::setOutputEscapingEnabled(true);
    $T = $D['T'];
    $M = iti_mag_labels($D['lang']);
    $G = iti_guide_labels($D['lang']);
    $p = $D['program'];
    $stays = iti_mag_stays($D['days']);
    $byN = [];
    foreach ($D['days'] as $d) $byN[$d['n']] = $d;
    $hasDates = !empty($D['days'][0]['date']);
    $dayDate = function ($d) use ($M) { return !empty($d['date']) ? iti_mag_date($d['date'], $M, false, true) : ''; };

    $w = new PhpWord();
    $w->getDocInfo()->setCreator('Savannah Explorers')->setTitle($D['title']);
    $langs = ['it' => 'it-IT', 'en' => 'en-GB', 'fr' => 'fr-FR', 'es' => 'es-ES', 'de' => 'de-DE'];
    $w->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language($langs[$D['lang']] ?? 'en-GB'));
    $w->setDefaultFontName('Calibri');
    $w->setDefaultFontSize(10);
    $w->addFontStyle('gwBody', ['name' => 'Calibri', 'size' => 10, 'color' => '000000']);
    $w->addFontStyle('gwBold', ['name' => 'Calibri', 'size' => 10, 'color' => '000000', 'bold' => true]);
    $w->addFontStyle('gwSmall', ['name' => 'Calibri', 'size' => 8.5, 'color' => '444444']);
    $w->addFontStyle('gwKick', ['name' => 'Calibri', 'size' => 8, 'bold' => true, 'allCaps' => true, 'spacing' => 30]);
    $w->addFontStyle('gwH1', ['name' => 'Calibri', 'size' => 18, 'bold' => true]);
    $w->addFontStyle('gwH2', ['name' => 'Calibri', 'size' => 12.5, 'bold' => true]);
    $w->addFontStyle('gwDay', ['name' => 'Calibri', 'size' => 11.5, 'bold' => true]);
    $w->addFontStyle('gwTh', ['name' => 'Calibri', 'size' => 7.5, 'bold' => true, 'color' => '444444', 'allCaps' => true]);
    $w->addFontStyle('gwLbl', ['name' => 'Calibri', 'size' => 7.5, 'bold' => true, 'color' => '444444', 'allCaps' => true]);
    $w->addParagraphStyle('gwP', ['spaceAfter' => 100, 'lineHeight' => 1.15]);
    $w->addParagraphStyle('gwTight', ['spaceAfter' => 0]);
    $w->addParagraphStyle('gwH2p', ['spaceBefore' => 240, 'spaceAfter' => 80, 'keepNext' => true]);
    $w->addParagraphStyle('gwDayP', ['spaceBefore' => 240, 'spaceAfter' => 60, 'keepNext' => true]);
    $grid = ['borderSize' => 4, 'borderColor' => '999999', 'cellMarginLeft' => 70, 'cellMarginRight' => 70,
             'cellMarginTop' => 30, 'cellMarginBottom' => 30];
    $meta = ['cellMarginLeft' => 0, 'cellMarginRight' => 80];   // no borders: label / value lines of a day
    $Wt = Converter::cmToTwip(17.6);   // content width (A4, 1.7 cm margins)

    $s = $w->addSection(['pageSizeW' => Converter::cmToTwip(21), 'pageSizeH' => Converter::cmToTwip(29.7),
                         'marginLeft' => Converter::cmToTwip(1.7), 'marginRight' => Converter::cmToTwip(1.7),
                         'marginTop' => Converter::cmToTwip(1.5), 'marginBottom' => Converter::cmToTwip(1.6)]);
    $foot = 'Savannah Explorers';
    foreach ($D['contacts'] as $c) $foot .= '  ·  ' . trim($c['name'] . ' ' . $c['phone']);
    $fr = $s->addFooter()->addTextRun(['alignment' => 'center']);
    $fr->addText($foot . '  ·  ', 'gwSmall');
    $fr->addField('PAGE', [], [], null, 'gwSmall');

    // ── Header ──
    $s->addText($G['sheet'], 'gwKick', 'gwTight');
    $s->addText($D['title'], 'gwH1', ['spaceAfter' => 60]);
    $facts = implode('   ·   ', array_filter([iti_mag_date_range($D, $M), $D['duration'], iti_mag_pax($D, $M)]));
    if ($facts !== '') $s->addText($facts, 'gwBody', 'gwTight');
    $client = trim((string)($p['client_name'] ?? ''));
    $ref = trim((string)($p['ref_number'] ?? ''));
    if ($client !== '' || $ref !== '') {
        $run = $s->addTextRun('gwTight');
        if ($client !== '') { $run->addText($G['client'] . ': ', 'gwSmall'); $run->addText($client . '     ', 'gwBody'); }
        if ($ref !== '') { $run->addText($G['ref'] . ': ', 'gwSmall'); $run->addText($ref, 'gwBody'); }
    }

    // ── Transfers and flights ──
    $s->addText($G['transfers'], 'gwH2', 'gwH2p');
    $rows = [];
    foreach ($D['days'] as $d) {
        $lines = [];
        if (!empty($d['arrival'])) $lines[] = [$G['arrival'] . ': ' . $d['arrival'], 'gwBold'];
        foreach ($d['flights'] ?? [] as $f) $lines[] = ['✈ ' . $f, 'gwBody'];
        foreach ($d['transfers'] as $t) $lines[] = ['⇢ ' . $t, 'gwBody'];
        if ($lines) $rows[] = [$d, $lines];
    }
    if ($rows) {
        $wDay = 600; $wDate = $hasDates ? 1900 : 0;
        $t = $s->addTable($grid);
        $t->addRow(null, ['tblHeader' => true]);
        $t->addCell($wDay)->addText($T['day'], 'gwTh', 'gwTight');
        if ($hasDates) $t->addCell($wDate)->addText($G['date'], 'gwTh', 'gwTight');
        $t->addCell($Wt - $wDay - $wDate)->addText($G['what'], 'gwTh', 'gwTight');
        foreach ($rows as $r) {
            $t->addRow(null, ['cantSplit' => true]);
            $t->addCell($wDay)->addText((string)$r[0]['n'], 'gwBold', 'gwTight');
            if ($hasDates) $t->addCell($wDate)->addText($dayDate($r[0]), 'gwBody', 'gwTight');
            iti_gw_lines($t->addCell($Wt - $wDay - $wDate), $r[1]);
        }
    } else {
        $s->addText($G['none'], 'gwSmall', 'gwTight');
    }

    // ── Accommodation ──
    if ($stays) {
        $s->addText($G['stays'], 'gwH2', 'gwH2p');
        $wDay = 750; $wIn = $hasDates ? 1700 : 0; $wN = 700; $wB = 1500;
        $wL = $Wt - $wDay - 2 * $wIn - $wN - $wB;
        $t = $s->addTable($grid);
        $t->addRow(null, ['tblHeader' => true]);
        $t->addCell($wDay)->addText($T['day'], 'gwTh', 'gwTight');
        if ($hasDates) { $t->addCell($wIn)->addText($G['in'], 'gwTh', 'gwTight'); $t->addCell($wIn)->addText($G['out'], 'gwTh', 'gwTight'); }
        $t->addCell($wL)->addText($G['lodge'], 'gwTh', 'gwTight');
        $t->addCell($wN)->addText(ucfirst($T['nights']), 'gwTh', 'gwTight');
        $t->addCell($wB)->addText($T['board'], 'gwTh', 'gwTight');
        foreach ($stays as $st) {
            $last = $st['day'] + $st['nights'] - 1;
            $t->addRow(null, ['cantSplit' => true]);
            $t->addCell($wDay)->addText($st['day'] . ($last > $st['day'] ? '–' . $last : ''), 'gwBold', 'gwTight');
            if ($hasDates) {
                $in = isset($byN[$st['day']]) ? $dayDate($byN[$st['day']]) : '';
                $out = $in !== '' ? iti_mag_date(date('Y-m-d', strtotime($byN[$st['day']]['date'] . ' +' . $st['nights'] . ' days')), $M, false, true) : '';
                $t->addCell($wIn)->addText($in, 'gwBody', 'gwTight');
                $t->addCell($wIn)->addText($out, 'gwBody', 'gwTight');
            }
            $lines = [[$st['lodge'] . ($st['room'] !== '' ? ' · ' . $st['room'] : ''), 'gwBold']];
            if ($st['dest'] !== '') $lines[] = [$st['dest'], 'gwSmall'];
            iti_gw_lines($t->addCell($wL), $lines);
            $t->addCell($wN)->addText((string)(int)$st['nights'], 'gwBody', 'gwTight');
            $t->addCell($wB)->addText(ucfirst($st['meals']), 'gwBody', 'gwTight');
        }
    }

    // ── Day by day ──
    $s->addPageBreak();
    $s->addText($T['program'], 'gwH2', ['spaceAfter' => 80]);
    $wLbl = 1700;
    foreach ($D['days'] as $d) {
        $head = $T['day'] . ' ' . (int)$d['n'] . (!empty($d['date']) ? ' · ' . $dayDate($d) : '') . ' — ' . $d['title'];
        $s->addText($head, 'gwDay', 'gwDayP');
        $info = [];
        if (!empty($d['arrival'])) $info[] = [$G['arrival'], [[$d['arrival'], 'gwBold']]];
        foreach ($d['flights'] ?? [] as $f) $info[] = ['✈', [[$f, 'gwBody']]];
        foreach ($d['transfers'] as $tr) $info[] = [$T['transfer'], [[$tr, 'gwBody']]];
        if ($d['activities']) $info[] = [$T['activities'], [[implode(' · ', $d['activities']), 'gwBody']]];
        $info[] = [$T['meals'], [[$d['meals'], 'gwBody']]];
        if ($d['lodge'] !== '') {
            $lodge = [[$d['lodge'] . ($d['room'] !== '' ? ' · ' . $d['room'] : ''), 'gwBold']];
            if (($d['lodge_area'] ?? '') !== '') $lodge[] = [$d['lodge_area'], 'gwSmall'];
            $info[] = [$T['overnight'], $lodge];
        }
        $t = $s->addTable($meta);
        foreach ($info as $row) {
            $t->addRow();
            $t->addCell($wLbl)->addText($row[0], 'gwLbl', 'gwTight');
            iti_gw_lines($t->addCell($Wt - $wLbl), $row[1]);
        }
        $s->addText('', 'gwBody', ['spaceAfter' => 40]);
        if (trim((string)$d['narrative']) !== '') iti_mw_paras($s, $d['narrative'], 'gwBody', 'gwP');
    }

    $out = tempnam(sys_get_temp_dir(), 'gwdoc');
    try {
        \PhpOffice\PhpWord\IOFactory::createWriter($w, 'Word2007')->save($out);
        return (string)file_get_contents($out);
    } finally {
        @unlink($out);
    }
}
