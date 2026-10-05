<?php
/**
 * iti_mag_word.php — the magazine programme as an editable Word file (.docx),
 * for agencies: cover photo, the journey, route map, stays, day by day with
 * destination and lodge photos, prices, included / not included, contacts, terms.
 *
 * Same data as the web / PDF layout (iti_doc_data() + iti_mag.php helpers).
 * Photos are shrunk into temp JPEGs (full width ≤ 1400 px, thumbnails ≤ 700 px)
 * so the file stays light; all text is plain Word text the agency can edit.
 *
 * Keep PHP-7 style (no match / arrow functions / str_contains).
 */
require_once __DIR__ . '/iti_mag.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;

/** A photo URL → local temp JPEG ≤ $maxW px wide (our uploads read from disk, others downloaded), or null. */
function iti_mw_img(string $url, int $maxW, array &$tmp): ?string {
    if ($url === '') return null;
    $src = null;
    $up = ITI_MODULE_URL . '/uploads/';
    if (strpos($url, $up) === 0) {
        $rel = substr($url, strlen($up));
        if (preg_match('~^[a-z]+/[A-Za-z0-9][A-Za-z0-9._-]*$~', $rel)) {
            $f = dirname(__DIR__) . '/uploads/' . $rel;
            if (is_file($f)) $src = $f;
        }
    } elseif (preg_match('~^[^:]+\.(jpe?g|png|gif|webp)$~i', $url) && is_file($url)) {
        $src = $url;                                   // local path (harness / tests)
    }
    if ($src === null) {
        if (!function_exists('iti_photo_download')) return null;
        $t = tempnam(sys_get_temp_dir(), 'mwdl');
        $err = null;
        if (!iti_photo_download($url, $t, $err)) { @unlink($t); return null; }
        $tmp[] = $t; $src = $t;
    }
    // Our own photos: the shrunk copy is kept in uploads/_word/<width>/ and reused next time.
    $cache = null;
    if (strpos($src, dirname(__DIR__) . '/uploads/') === 0) {
        $cache = dirname(__DIR__) . '/uploads/_word/' . $maxW . '/' . str_replace('/', '__', substr($src, strlen(dirname(__DIR__) . '/uploads/')));
        $cache = preg_replace('/\.\w+$/', '', $cache) . '.jpg';
        if (is_file($cache) && filemtime($cache) >= filemtime($src)) return $cache;
    }
    $info = @getimagesize($src);
    if (!$info) return null;
    if (!function_exists('imagecreatetruecolor') || $info[0] <= $maxW) {
        if ($info[2] === IMAGETYPE_JPEG || $info[2] === IMAGETYPE_PNG) return $src;
        if (!function_exists('imagecreatetruecolor')) return null;
    }
    $img = function_exists('iti_photo_load') && iti_photo_mem_ok((int)$info[0], (int)$info[1]) ? iti_photo_load($src, $info[2]) : null;
    if (!$img) return in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) ? $src : null;
    $img = iti_photo_fit($img, $maxW);
    if ($cache !== null && (is_dir(dirname($cache)) || @mkdir(dirname($cache), 0755, true)) && imagejpeg($img, $cache, 80)) {
        imagedestroy($img);
        return $cache;
    }
    $out = tempnam(sys_get_temp_dir(), 'mwim') . '.jpg';
    imagejpeg($img, $out, 80);
    imagedestroy($img);
    $tmp[] = $out;
    return $out;
}

/** Plain text with blank-line paragraphs and "- " bullets → Word paragraphs / list items. */
function iti_mw_paras($c, string $txt, string $font = 'mwBody', string $para = 'mwP'): void {
    foreach (preg_split('/\n\s*\n/u', trim($txt)) as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (preg_match('/^[-•]\s+(.*)$/su', $p, $m)) { $c->addListItem(trim(preg_replace('/\s+/u', ' ', $m[1])), 0, $font, null, $para); continue; }
        $lines = preg_split('/\n/u', $p);
        $run = $c->addTextRun($para);
        foreach ($lines as $i => $l) { if ($i) $run->addTextBreak(); $run->addText(trim($l), $font); }
    }
}

/**
 * Build the Word document from iti_doc_data(). Returns [PhpWord, temp files to delete].
 */
function iti_mag_word_build(array $D): array {
    Settings::setOutputEscapingEnabled(true);
    $T = $D['T'];
    $M = iti_mag_labels($D['lang']);
    $tmp = [];
    $RED = 'B3241C'; $INK = '231F1C'; $MUTE = '6F675F'; $SAND = 'F6F0E6'; $OCHRE = 'B5822F';
    $W = 490;   // content width in pt (A4, 1.8 cm margins)

    $w = new PhpWord();
    $w->getDocInfo()->setCreator('Savannah Explorers')->setTitle($D['title']);
    $w->setDefaultFontName('Calibri');
    $w->setDefaultFontSize(10.5);
    $w->addFontStyle('mwBody', ['name' => 'Calibri', 'size' => 10.5, 'color' => $INK]);
    $w->addFontStyle('mwSmall', ['name' => 'Calibri', 'size' => 9, 'color' => $MUTE]);
    $w->addFontStyle('mwKicker', ['name' => 'Calibri', 'size' => 8, 'bold' => true, 'color' => $RED, 'allCaps' => true, 'spacing' => 40]);
    $w->addFontStyle('mwKickerO', ['name' => 'Calibri', 'size' => 8, 'bold' => true, 'color' => $OCHRE, 'allCaps' => true, 'spacing' => 40]);
    $w->addFontStyle('mwH1', ['name' => 'Georgia', 'size' => 34, 'color' => $INK]);
    $w->addFontStyle('mwH2', ['name' => 'Georgia', 'size' => 24, 'color' => $INK]);
    $w->addFontStyle('mwH3', ['name' => 'Georgia', 'size' => 18, 'color' => $INK]);
    $w->addFontStyle('mwH4', ['name' => 'Georgia', 'size' => 13, 'color' => $INK]);
    $w->addFontStyle('mwDayNo', ['name' => 'Georgia', 'size' => 30, 'color' => $RED]);
    $w->addFontStyle('mwRoute', ['name' => 'Georgia', 'size' => 13, 'italic' => true, 'color' => $INK]);
    $w->addFontStyle('mwChip', ['name' => 'Calibri', 'size' => 9, 'color' => $INK]);
    $w->addFontStyle('mwChipB', ['name' => 'Calibri', 'size' => 9, 'bold' => true, 'color' => $RED]);
    $w->addFontStyle('mwPrice', ['name' => 'Georgia', 'size' => 13, 'color' => $INK]);
    $w->addFontStyle('mwTh', ['name' => 'Calibri', 'size' => 8, 'bold' => true, 'color' => $MUTE, 'allCaps' => true]);
    $w->addParagraphStyle('mwP', ['spaceAfter' => 120, 'lineHeight' => 1.25]);
    $w->addParagraphStyle('mwTight', ['spaceAfter' => 0]);
    $w->addParagraphStyle('mwCenter', ['alignment' => 'center', 'spaceAfter' => 0]);
    $w->addTitleStyle(1, ['name' => 'Georgia', 'size' => 24, 'color' => $INK], ['spaceBefore' => 120, 'spaceAfter' => 160]);
    $w->addTitleStyle(2, ['name' => 'Georgia', 'size' => 18, 'color' => $INK], ['spaceBefore' => 60, 'spaceAfter' => 100]);

    $sec = ['pageSizeW' => Converter::cmToTwip(21), 'pageSizeH' => Converter::cmToTwip(29.7),
            'marginLeft' => Converter::cmToTwip(1.8), 'marginRight' => Converter::cmToTwip(1.8),
            'marginTop' => Converter::cmToTwip(1.6), 'marginBottom' => Converter::cmToTwip(1.6)];
    $s = $w->addSection($sec);
    $foot = $s->addFooter();
    $fr = $foot->addTextRun(['alignment' => 'center']);
    $fr->addText('Savannah Explorers  ·  ' . ($D['contacts'][0]['phone'] ?? '') . '  ·  ' . ($D['contacts'][0]['email'] ?? '') . '  ·  ', 'mwSmall');
    $fr->addField('PAGE', [], [], null, 'mwSmall');

    // ── Cover ─────────────────────────────────────────────
    $routeNames = iti_mag_route_names($D['days']);
    $dateRange = iti_mag_date_range($D, $M);
    $paxLabel = iti_mag_pax($D, $M);
    if ($D['logo'] && ($lg = iti_mw_img($D['logo'], 300, $tmp))) $s->addImage($lg, ['width' => 60, 'alignment' => 'left']);
    $s->addTextBreak(1);
    $s->addText($T['kicker'], 'mwKicker', 'mwTight');
    $s->addText($D['title'], 'mwH1', ['spaceAfter' => 80]);
    if ($D['subtitle'] !== '') $s->addText($D['subtitle'], ['name' => 'Calibri', 'size' => 13, 'color' => $INK], ['spaceAfter' => 100]);
    if ($routeNames && $D['subtitle'] === '') $s->addText(implode(' · ', $routeNames), 'mwRoute', ['spaceAfter' => 100]);
    $facts = array_filter([$dateRange, $D['duration'], $M['private'], $paxLabel]);
    $s->addText(implode('   ·   ', $facts), ['name' => 'Calibri', 'size' => 10, 'bold' => true, 'color' => $RED], ['spaceAfter' => 240]);
    if (($c = iti_mag_cover($D)) !== '' && ($ci = iti_mw_img($c, 1400, $tmp))) $s->addImage($ci, ['width' => $W, 'alignment' => 'center']);
    $s->addPageBreak();

    // ── The journey ───────────────────────────────────────
    $s->addText($T['brief'], 'mwKicker', 'mwTight');
    $s->addTitle($M['overview'], 1);
    iti_mw_paras($s, $D['intro']);
    $stays = iti_mag_stays($D['days']);
    $nNights = 0; $lodges = [];
    foreach ($stays as $st) { $nNights += $st['nights']; $lodges[$st['lodge']] = true; }
    $tb = $s->addTable(['cellMargin' => 90, 'bgColor' => $SAND]);
    $rows = [[ucfirst($T['days']), $D['duration']]];
    if ($dateRange !== '') $rows[] = [$M['dates'], $dateRange];
    if ($routeNames) $rows[] = [$M['destinations'], implode(', ', $routeNames)];
    if ($lodges) $rows[] = [$M['lodges'], implode("
", iti_mag_lodge_nights($stays, $T))];
    if ($paxLabel !== '') $rows[] = [ucfirst($T['pax']), $paxLabel];
    foreach ($rows as $r) {
        $tb->addRow();
        $tb->addCell(2600, ['bgColor' => $SAND])->addText($r[0], 'mwSmall', 'mwTight');
        $run = $tb->addCell(7100, ['bgColor' => $SAND])->addTextRun('mwTight');
        foreach (explode("
", $r[1]) as $k => $line) { if ($k) $run->addTextBreak(); $run->addText($line, ['name' => 'Calibri', 'size' => 10.5, 'bold' => true]); }
    }

    // ── Route: map + legend, stays ───────────────────────
    $map = isset($D['map']) ? $D['map'] : ['route' => [], 'legend' => []];
    if (count($map['route']) >= 2 || $stays) $s->addPageBreak();
    if (count($map['route']) >= 2 && function_exists('imagecreatetruecolor')) {
        $s->addText($D['duration'], 'mwKicker', 'mwTight');
        $s->addTitle($M['route'], 1);
        require_once __DIR__ . '/iti_map.php';
        $pts = [];
        foreach ($map['route'] as $rp) {
            $pts[] = ['lat' => $rp['lat'], 'lng' => $rp['lng'], 'name' => $rp['name'],
                      'label' => $rp['role'] === 'stop' ? (string)$rp['num'] : (string)($rp['code'] ?? ''), 'airport' => $rp['role'] !== 'stop'];
        }
        $mf = tempnam(sys_get_temp_dir(), 'mwmap') . '.png';
        if (iti_render_itinerary_map($pts, $mf)) { $tmp[] = $mf; $s->addImage($mf, ['width' => $W, 'alignment' => 'center']); }
        $lt = $s->addTable(['cellMargin' => 50]);
        foreach ($map['legend'] as $l) {
            $ap = $l['role'] !== 'stop';
            $lt->addRow();
            $lt->addCell(700)->addText($ap ? '✈' : (string)$l['num'], ['name' => 'Calibri', 'size' => 10, 'bold' => true, 'color' => $ap ? $INK : $RED], 'mwCenter');
            $cell = $lt->addCell(9000);
            $cell->addText($l['name'], 'mwBody', 'mwTight');
            $sub = $ap ? ($l['role'] === 'start' ? $M['start'] : $M['end']) . ($l['code'] ? ' · ' . $l['code'] : '') : '';
            if ($l['dist'] !== null) $sub .= ($sub !== '' ? ' · ' : '') . '≈ ' . number_format($l['dist'], 0, ',', '.') . ' ' . $M['km'];
            if ($sub !== '') $cell->addText($sub, 'mwSmall', 'mwTight');
        }
    }
    if ($stays) {
        $s->addTextBreak(1);
        $s->addTitle($M['stays'], 2);
        $tb = $s->addTable(['cellMargin' => 70, 'borderBottomSize' => 0]);
        foreach ($stays as $st) {
            $tb->addRow();
            $pc = $tb->addCell(2600, ['valign' => 'center']);
            if ($st['photo'] !== '' && ($pi = iti_mw_img($st['photo'], 700, $tmp))) $pc->addImage($pi, ['width' => 120]);
            $cc = $tb->addCell(7100, ['valign' => 'center']);
            $cc->addText($T['day'] . ' ' . $st['day'] . ($st['nights'] > 1 ? '–' . ($st['day'] + $st['nights'] - 1) : ''), 'mwKicker', 'mwTight');
            $cc->addText($st['lodge'], 'mwH4', 'mwTight');
            $cc->addText(($st['dest'] !== '' ? $st['dest'] . ' · ' : '') . $st['nights'] . ' ' . ($st['nights'] === 1 ? $T['night1'] : $T['nights']) . ' · ' . $st['meals'], 'mwSmall', 'mwTight');
        }
    }

    // ── Day by day ────────────────────────────────────────
    $s->addPageBreak();
    $s->addText($T['program'], 'mwKicker', ['spaceAfter' => 120]);
    $seenLodge = []; $seenPhoto = []; $prevKey = '';
    foreach ($D['days'] as $i => $d) {
        if ($i > 0) $s->addText('', null, ['borderBottomSize' => 6, 'borderBottomColor' => 'E6DDD0', 'spaceAfter' => 200]);
        if ($d['dest_photo'] !== '' && empty($seenPhoto[$d['dest_photo']])) {
            $seenPhoto[$d['dest_photo']] = true;
            if ($pi = iti_mw_img($d['dest_photo'], 1400, $tmp)) $s->addImage($pi, ['width' => $W, 'alignment' => 'center']);
        }
        $run = $s->addTextRun(['spaceAfter' => 0, 'keepNext' => true]);
        $run->addText($T['day'] . (!empty($d['date']) ? ' · ' . iti_mag_date($d['date'], $M, false, true) : ''), 'mwKicker');
        $hr = $s->addTextRun(['spaceAfter' => 100, 'keepNext' => true]);
        $hr->addText((string)$d['n'] . '  ', 'mwDayNo');
        $hr->addText($d['title'], 'mwH3');
        $chips = $s->addTextRun(['spaceAfter' => 140]);
        foreach ($d['flights'] ?? [] as $t) { $chips->addText('✈ ', 'mwChipB'); $chips->addText($t . '     ', 'mwChip'); }
        foreach ($d['transfers'] as $t) { $chips->addText('⇢ ', 'mwChipB'); $chips->addText($t . '     ', 'mwChip'); }
        $chips->addText($T['meals'] . ' ', 'mwChipB'); $chips->addText($d['meals'] . '     ', 'mwChip');
        if ($d['lodge'] !== '') { $chips->addText($T['overnight'] . ' ', 'mwChipB'); $chips->addText($d['lodge'], 'mwChip'); }
        iti_mw_paras($s, $d['narrative']);
        if ($d['activities']) {
            $ar = $s->addTextRun('mwP');
            $ar->addText(mb_strtoupper($T['activities']) . '   ', 'mwTh');
            $ar->addText(implode(' · ', $d['activities']), 'mwBody');
        }
        if ($d['dest_desc'] !== '') {
            $bx = $s->addTable(['cellMargin' => 120, 'borderLeftSize' => 18, 'borderLeftColor' => $OCHRE]);
            $bx->addRow();
            $cell = $bx->addCell(9700, ['borderLeftSize' => 18, 'borderLeftColor' => $OCHRE]);
            $cell->addText($M['about_dest'], 'mwKickerO', 'mwTight');
            $cell->addText($d['dest'], 'mwH4', ['spaceAfter' => 60]);
            iti_mw_paras($cell, $d['dest_desc'], 'mwSmall', 'mwP');
            $s->addTextBreak(1);
        }
        $lk = $d['lodge_key'] !== '' ? $d['lodge_key'] : $d['lodge'];
        if ($d['lodge'] !== '' && !isset($seenLodge[$lk])) {
            $seenLodge[$lk] = (int)$d['n'];
            $card = $s->addTable(['cellMargin' => 110]);
            $card->addRow(null, ['cantSplit' => true]);   // photo and text stay together on one page
            // One photo beside the text: a second one stacked below left an empty sand box.
            $pi = $d['lodge_photos'] ? iti_mw_img($d['lodge_photos'][0], 700, $tmp) : null;
            if ($pi) $card->addCell(4300, ['bgColor' => $SAND, 'valign' => 'top'])->addImage($pi, ['width' => 200]);
            $cc = $card->addCell($pi ? 5400 : 9700, ['bgColor' => $SAND, 'valign' => 'top']);
            $cc->addText($T['overnight'], 'mwKicker', 'mwTight');
            $cc->addText($d['lodge'], 'mwH3', ['spaceAfter' => 80]);
            iti_mw_paras($cc, $d['lodge_desc'], 'mwSmall', 'mwP');
            $meta = trim(($d['lodge_area'] ?? '') . ($d['lodge_url'] !== '' ? ((isset($d['lodge_area']) && $d['lodge_area'] !== '' ? ' · ' : '') . $d['lodge_url']) : ''));
            if ($meta !== '') $cc->addText($meta, 'mwSmall', 'mwTight');
            $s->addTextBreak(1);
        } elseif ($d['lodge'] !== '' && $lk !== $prevKey) {
            $r = $s->addTextRun('mwP');
            $r->addText(mb_strtoupper($T['overnight']) . '   ', 'mwTh');
            $r->addText($d['lodge'], ['name' => 'Georgia', 'size' => 11.5]);
            $r->addText(' — ' . $M['see_day'] . ' ' . $seenLodge[$lk], 'mwSmall');
        }
        $prevKey = $d['lodge'] !== '' ? $lk : '';
    }

    // ── Prices, included / not included ──────────────────
    if ($D['prices'] || $D['incl'] || $D['excl']) $s->addPageBreak();
    if ($D['prices']) {
        $s->addText($T['pp'], 'mwKicker', 'mwTight');
        $s->addTitle($T['prices'], 1);
        $pt = $s->addTable(['cellMargin' => 80, 'borderBottomSize' => 4, 'borderBottomColor' => 'E6DDD0']);
        $pt->addRow();
        $pt->addCell(6000, ['borderBottomSize' => 12, 'borderBottomColor' => $INK])->addText($T['group'], 'mwTh', 'mwTight');
        $pt->addCell(3700, ['borderBottomSize' => 12, 'borderBottomColor' => $INK])->addText($T['pp'], 'mwTh', ['alignment' => 'right', 'spaceAfter' => 0]);
        foreach ($D['prices'] as $pr) {
            $pt->addRow();
            $pt->addCell(6000, ['borderBottomSize' => 4, 'borderBottomColor' => 'E6DDD0'])->addText((string)($pr['label'] ?? ''), 'mwBody', 'mwTight');
            $pt->addCell(3700, ['borderBottomSize' => 4, 'borderBottomColor' => 'E6DDD0'])->addText(isset($pr['price']) ? number_format((float)$pr['price'], 0, ',', '.') . ' ' . ($pr['currency'] ?? 'USD') : '', 'mwPrice', ['alignment' => 'right', 'spaceAfter' => 0]);
        }
        if ($D['price_notes'] !== '') { $s->addTextBreak(1); iti_mw_paras($s, $D['price_notes'], 'mwSmall'); }
        $s->addTextBreak(1);
    }
    if ($D['incl'] || $D['excl']) {
        $lt = $s->addTable(['cellMargin' => 80]);
        $lt->addRow();
        foreach ([[$T['incl'], $D['incl'], '✓', '2F7A45'], [$T['excl'], $D['excl'], '✕', $RED]] as $col) {
            $cell = $lt->addCell(4850, ['valign' => 'top']);
            if (!$col[1]) continue;
            $cell->addText($col[0], 'mwH4', ['spaceAfter' => 80]);
            foreach ($col[1] as $x) {
                $r = $cell->addTextRun(['spaceAfter' => 60, 'indentation' => ['left' => 280, 'hanging' => 280]]);
                $r->addText($col[2] . '  ', ['name' => 'Calibri', 'size' => 10, 'bold' => true, 'color' => $col[3]]);
                $r->addText($x, 'mwBody');
            }
        }
    }

    // ── Contacts, terms ───────────────────────────────────
    $s->addPageBreak();
    $s->addTitle($T['contacts'], 1);
    $ct = $s->addTable(['cellMargin' => 80]);
    $ct->addRow();
    foreach ([$T['ref'], $T['phone'], $T['email']] as $h) $ct->addCell(3230, ['borderBottomSize' => 12, 'borderBottomColor' => $INK])->addText($h, 'mwTh', 'mwTight');
    foreach ($D['contacts'] as $c) {
        $ct->addRow();
        foreach ([$c['name'], $c['phone'], $c['email']] as $v) $ct->addCell(3230, ['borderBottomSize' => 4, 'borderBottomColor' => 'E6DDD0'])->addText((string)$v, 'mwBody', 'mwTight');
    }
    if ($D['terms'] !== '') {
        $s->addTextBreak(1);
        $s->addTitle($T['terms'], 2);
        if (strpos($D['terms'], '<') !== false && function_exists('iti_richtext_to_phpword')) {
            $w->addFontStyle('mwTerms', ['name' => 'Calibri', 'size' => 9, 'color' => '4A433D']);
            iti_richtext_to_phpword($s, $D['terms'], 'mwTerms', ['spaceAfter' => 60, 'lineHeight' => 1.2]);
        } else {
            iti_mw_paras($s, strip_tags($D['terms']), 'mwSmall');
        }
    }
    return [$w, $tmp];
}
