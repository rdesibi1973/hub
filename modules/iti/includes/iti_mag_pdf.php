<?php
/**
 * iti_mag_pdf.php — the magazine programme as a PDF rendered on the server (Dompdf),
 * for the Agent API / Cowork and the "PDF" button: same sections and photos as the
 * Word file (iti_mag_word.php), laid out with tables because Dompdf has no grid / flex
 * and no CSS variables. Photos are the shrunk local JPEGs of iti_mw_img().
 *
 * Keep PHP-7 style (no match / arrow functions / str_contains).
 */
require_once __DIR__ . '/iti_mag_word.php';   // iti_mw_img() + iti_mag.php helpers

/** Plain text with blank-line paragraphs and "- " bullets → HTML (same rules as iti_mw_paras). */
function iti_mp_paras(string $txt, string $cls = ''): string {
    $out = ''; $list = [];
    $c = $cls !== '' ? ' class="' . $cls . '"' : '';
    foreach (preg_split('/\n\s*\n/u', trim($txt)) as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (preg_match('/^[-•]\s+(.*)$/su', $p, $m)) { $list[] = h(trim(preg_replace('/\s+/u', ' ', $m[1]))); continue; }
        if ($list) { $out .= '<ul' . $c . '><li>' . implode('</li><li>', $list) . '</li></ul>'; $list = []; }
        $out .= '<p' . $c . '>' . nl2br(h($p)) . '</p>';
    }
    if ($list) $out .= '<ul' . $c . '><li>' . implode('</li><li>', $list) . '</li></ul>';
    return $out;
}

/** <img> of a photo URL shrunk to $maxW px (local temp / cached file), or ''. */
function iti_mp_img(string $url, int $maxW, array &$tmp, string $style): string {
    $f = iti_mw_img($url, $maxW, $tmp);
    return $f ? '<img src="' . h($f) . '" style="' . $style . '">' : '';
}

/** Full HTML for Dompdf. $tmp collects temp files to delete after rendering. */
function iti_mag_pdf_html(array $D, array &$tmp): string {
    $T = $D['T'];
    $M = iti_mag_labels($D['lang']);
    $routeNames = iti_mag_route_names($D['days']);
    $dateRange = iti_mag_date_range($D, $M);
    $paxLabel = iti_mag_pax($D, $M);
    $stays = iti_mag_stays($D['days']);
    $foot = 'Savannah Explorers  ·  ' . ($D['contacts'][0]['phone'] ?? '') . '  ·  ' . ($D['contacts'][0]['email'] ?? '');
    $pub = isset($D['public_url']) ? (string)$D['public_url'] : '';   // digital itinerary (published personal programme), set by iti_export_file()

    ob_start(); ?>
<!DOCTYPE html>
<html lang="<?= h($D['lang']) ?>"><head><meta charset="utf-8"><title><?= h($D['title']) ?></title>
<style>
@page{size:A4;margin:16mm 16mm 18mm}
body{font-family:"DejaVu Sans",sans-serif;font-size:9.5pt;line-height:1.4;color:#231F1C;margin:0}
.serif{font-family:"DejaVu Serif",serif}
#foot{position:fixed;bottom:-11mm;left:0;right:0;text-align:center;font-size:7.5pt;color:#6F675F}
.kick{font-size:7pt;font-weight:bold;color:#B3241C;text-transform:uppercase;letter-spacing:1.5px;margin:0}
.kickO{color:#B5822F}
h1{font-family:"DejaVu Serif",serif;font-weight:normal;font-size:28pt;line-height:1.1;margin:4px 0 8px}
h2{font-family:"DejaVu Serif",serif;font-weight:normal;font-size:19pt;margin:4px 0 12px}
h3{font-family:"DejaVu Serif",serif;font-weight:normal;font-size:14pt;margin:0 0 6px}
h4{font-family:"DejaVu Serif",serif;font-weight:normal;font-size:11.5pt;margin:0 0 3px}
p{margin:0 0 7px}
ul{margin:0 0 7px 14px;padding:0}
li{margin:0 0 3px}
.small{font-size:8pt;color:#6F675F}
.brk{page-break-before:always}
.facts{color:#B3241C;font-weight:bold;font-size:9.5pt;margin:0 0 16px}
table{border-collapse:collapse;width:100%}
td{vertical-align:top}
.sand td{background:#F6F0E6;padding:6px 9px}
.legend td{padding:3px 4px;border-bottom:1px solid #E6DDD0;vertical-align:middle}
.num{width:22px;text-align:center;font-weight:bold;color:#B3241C}
.stays td{padding:5px 6px;border-bottom:1px solid #E6DDD0;vertical-align:middle}
.day{margin:0 0 10px}
.dayrule{border-top:1px solid #E6DDD0;margin:14px 0 14px}
.dayno{font-family:"DejaVu Serif",serif;font-size:24pt;color:#B3241C}
.daytitle{font-family:"DejaVu Serif",serif;font-size:14pt}
.chips{font-size:8pt;margin:4px 0 9px}
.chips b{color:#B3241C}
.th{font-size:7pt;font-weight:bold;color:#6F675F;text-transform:uppercase}
.dest{border-left:4px solid #B5822F;background:#FBF8F3;padding:7px 10px;margin:6px 0 10px}
.dest p,.dest li{font-size:8.5pt;color:#4A433D}
.lodge td{background:#F6F0E6;padding:8px}
.lodge p,.lodge li{font-size:8.5pt;color:#4A433D}
.prices td{padding:5px 4px;border-bottom:1px solid #E6DDD0}
.prices tr.hd td{border-bottom:2px solid #231F1C}
.price{font-family:"DejaVu Serif",serif;font-size:11.5pt;text-align:right}
.contacts td{padding:5px 4px;border-bottom:1px solid #E6DDD0}
.contacts tr.hd td{border-bottom:2px solid #231F1C}
.terms,.terms p,.terms li{font-size:7.5pt;color:#4A433D}
.terms h1,.terms h2,.terms h3{font-family:"DejaVu Sans",sans-serif;font-size:9pt;font-weight:bold;margin:8px 0 3px}
.nobrk{page-break-inside:avoid}
</style></head><body>
<div id="foot"><?= h($foot) ?></div>

<?php /* ── Cover ── */ ?>
<?php if ($D['logo']) echo iti_mp_img($D['logo'], 300, $tmp, 'width:60pt;margin:0 0 14pt'); ?>
<p class="kick"><?= h($T['kicker']) ?></p>
<h1><?= h($D['title']) ?></h1>
<?php if ($D['subtitle'] !== ''): ?><p style="font-size:12pt"><?= h($D['subtitle']) ?></p>
<?php elseif ($routeNames): ?><p class="serif" style="font-size:12pt;font-style:italic"><?= h(implode(' · ', $routeNames)) ?></p><?php endif; ?>
<p class="facts"><?= h(implode('   ·   ', array_filter([$dateRange, $D['duration'], $M['private'], $paxLabel]))) ?></p>
<?php if ($pub !== ''): ?><p class="small" style="margin:-8px 0 14px"><?= h($M['online']) ?>: <a href="<?= h($pub) ?>" style="color:#B3241C"><?= h($pub) ?></a></p><?php endif; ?>
<?php if (($c = iti_mag_cover($D)) !== '') echo iti_mp_img($c, 1400, $tmp, 'width:100%'); ?>

<?php /* ── The journey ── */ ?>
<div class="brk"></div>
<p class="kick"><?= h($T['brief']) ?></p>
<h2><?= h($M['overview']) ?></h2>
<?= iti_mp_paras($D['intro']) ?>
<?php
    $rows = [[ucfirst($T['days']), $D['duration']]];
    if ($dateRange !== '') $rows[] = [$M['dates'], $dateRange];
    if ($routeNames) $rows[] = [$M['destinations'], implode(', ', $routeNames)];
    if ($stays) $rows[] = [$M['lodges'], implode("\n", iti_mag_lodge_nights($stays, $T))];
    if ($paxLabel !== '') $rows[] = [ucfirst($T['pax']), $paxLabel];
?>
<table class="sand" style="margin-top:8px">
<?php foreach ($rows as $r): ?><tr><td class="small" style="width:27%"><?= h($r[0]) ?></td><td><b><?= nl2br(h($r[1])) ?></b></td></tr><?php endforeach; ?>
</table>

<?php /* ── Route: map + legend, stays ── */
    $map = isset($D['map']) ? $D['map'] : ['route' => [], 'legend' => []];
    $mapImg = '';
    if (count($map['route']) >= 2 && function_exists('imagecreatetruecolor')) {
        require_once __DIR__ . '/iti_map.php';
        $pts = [];
        foreach ($map['route'] as $rp) {
            $pts[] = ['lat' => $rp['lat'], 'lng' => $rp['lng'], 'name' => $rp['name'],
                      'label' => $rp['role'] === 'stop' ? (string)$rp['num'] : (string)($rp['code'] ?? ''), 'airport' => $rp['role'] !== 'stop'];
        }
        $mf = tempnam(sys_get_temp_dir(), 'mpmap') . '.png';
        if (iti_render_itinerary_map($pts, $mf)) { $tmp[] = $mf; $mapImg = '<img src="' . h($mf) . '" style="width:100%">'; }
    }
    if ($mapImg !== '' || $stays): ?>
<div class="brk"></div>
<?php if ($mapImg !== ''): ?>
<p class="kick"><?= h($D['duration']) ?></p>
<h2><?= h($M['route']) ?></h2>
<?= $mapImg ?>
<table class="legend" style="margin-top:6px">
<?php foreach ($map['legend'] as $l):
        $ap = $l['role'] !== 'stop';
        $sub = $ap ? ($l['role'] === 'start' ? $M['start'] : $M['end']) . ($l['code'] ? ' · ' . $l['code'] : '') : '';
        if ($l['dist'] !== null) $sub .= ($sub !== '' ? ' · ' : '') . '≈ ' . number_format($l['dist'], 0, ',', '.') . ' ' . $M['km']; ?>
<tr><td class="num"<?= $ap ? ' style="color:#231F1C"' : '' ?>><?= $ap ? '✈' : (int)$l['num'] ?></td>
<td><?= h($l['name']) ?><?= $sub !== '' ? '<br><span class="small">' . h($sub) . '</span>' : '' ?></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
<?php if ($stays): ?>
<?php if ($mapImg !== ''): ?><div class="brk"></div><?php endif; /* own page: the heading stayed alone under the map */ ?>
<h3><?= h($M['stays']) ?></h3>
<table class="stays">
<?php foreach ($stays as $st): $ph = $st['photo'] !== '' ? iti_mp_img($st['photo'], 700, $tmp, 'width:110pt') : ''; ?>
<tr class="nobrk"><td style="width:120pt"><?= $ph ?></td><td>
<p class="kick"><?= h($T['day'] . ' ' . $st['day'] . ($st['nights'] > 1 ? '–' . ($st['day'] + $st['nights'] - 1) : '')) ?></p>
<h4><?= h($st['lodge']) ?></h4>
<span class="small"><?= h(($st['dest'] !== '' ? $st['dest'] . ' · ' : '') . $st['nights'] . ' ' . ($st['nights'] === 1 ? $T['night1'] : $T['nights']) . ' · ' . $st['meals'] . ($st['room'] !== '' ? ' · ' . $st['room'] : '')) ?></span>
</td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
<?php endif; ?>

<?php /* ── Day by day ── */ ?>
<div class="brk"></div>
<p class="kick" style="margin-bottom:10px"><?= h($T['program']) ?></p>
<?php
    $seenLodge = []; $seenPhoto = []; $prevKey = '';
    foreach ($D['days'] as $i => $d):
        if ($i > 0) echo '<div class="dayrule"></div>';
        if ($d['dest_photo'] !== '' && empty($seenPhoto[$d['dest_photo']])) {
            $seenPhoto[$d['dest_photo']] = true;
            echo iti_mp_img($d['dest_photo'], 1400, $tmp, 'width:100%;margin:0 0 8px');
        } ?>
<div class="day">
<div class="nobrk">
<p class="kick"><?= h($T['day'] . (!empty($d['date']) ? ' · ' . iti_mag_date($d['date'], $M, false, true) : '')) ?></p>
<div><span class="dayno"><?= (int)$d['n'] ?></span>&nbsp;&nbsp;<span class="daytitle"><?= h($d['title']) ?></span></div>
<div class="chips">
<?php foreach ($d['flights'] ?? [] as $t) echo '<b>✈</b> ' . h($t) . ' &nbsp;&nbsp; ';
      foreach ($d['transfers'] as $t) echo '<b>⇢</b> ' . h($t) . ' &nbsp;&nbsp; '; ?>
<b><?= h($T['meals']) ?></b> <?= h($d['meals']) ?>
<?php if ($d['lodge'] !== ''): ?> &nbsp;&nbsp; <b><?= h($T['overnight']) ?></b> <?= h($d['lodge']) ?><?= $d['room'] !== '' ? ' · ' . h($d['room']) : '' ?><?php endif; ?>
</div>
</div>
<?= iti_mp_paras($d['narrative']) ?>
<?php if ($d['activities']): ?><p><span class="th"><?= h(mb_strtoupper($T['activities'])) ?></span>&nbsp;&nbsp; <?= h(implode(' · ', $d['activities'])) ?></p><?php endif; ?>
<?php if ($d['dest_desc'] !== ''): ?>
<div class="dest"><p class="kick kickO"><?= h($M['about_dest']) ?></p><h4><?= h($d['dest']) ?></h4><?= iti_mp_paras($d['dest_desc']) ?></div>
<?php endif; ?>
<?php
        $lk = $d['lodge_key'] !== '' ? $d['lodge_key'] : $d['lodge'];
        if ($d['lodge'] !== '' && !isset($seenLodge[$lk])):
            $seenLodge[$lk] = (int)$d['n'];
            $ph = $d['lodge_photos'] ? iti_mp_img($d['lodge_photos'][0], 700, $tmp, 'width:100%') : '';   // one photo, as in Word
            $meta = trim(($d['lodge_area'] ?? '') . ($d['lodge_url'] !== '' ? ((isset($d['lodge_area']) && $d['lodge_area'] !== '' ? ' · ' : '') . $d['lodge_url']) : '')); ?>
<table class="lodge nobrk" style="margin:6px 0 4px"><tr>
<?php if ($ph !== ''): ?><td style="width:44%"><?= $ph ?></td><?php endif; ?>
<td><p class="kick"><?= h($T['overnight']) ?></p><h3><?= h($d['lodge']) ?></h3><?= iti_mp_paras($d['lodge_desc']) ?>
<?php if ($meta !== ''): ?><span class="small"><?= h($meta) ?></span><?php endif; ?></td>
</tr></table>
<?php   elseif ($d['lodge'] !== '' && $lk !== $prevKey): ?>
<p><span class="th"><?= h(mb_strtoupper($T['overnight'])) ?></span>&nbsp;&nbsp; <span class="serif"><?= h($d['lodge']) ?></span> <span class="small">— <?= h($M['see_day'] . ' ' . $seenLodge[$lk]) ?></span></p>
<?php   endif;
        $prevKey = $d['lodge'] !== '' ? $lk : ''; ?>
</div>
<?php endforeach; ?>

<?php /* ── Prices, included / not included ── */ ?>
<?php if ($D['prices'] || $D['incl'] || $D['excl']): ?><div class="brk"></div><?php endif; ?>
<?php if ($D['prices']): ?>
<p class="kick"><?= h($T['pp']) ?></p>
<h2><?= h($T['prices']) ?></h2>
<table class="prices">
<tr class="hd"><td class="th"><?= h($T['group']) ?></td><td class="th" style="text-align:right"><?= h($T['pp']) ?></td></tr>
<?php foreach ($D['prices'] as $pr): ?>
<tr><td><?= h((string)($pr['label'] ?? '')) ?></td><td class="price"><?= isset($pr['price']) ? h(number_format((float)$pr['price'], 0, ',', '.') . ' ' . ($pr['currency'] ?? 'USD')) : '' ?></td></tr>
<?php endforeach; ?>
</table>
<?php if ($D['price_notes'] !== ''): ?><div style="margin-top:8px"><?= iti_mp_paras($D['price_notes'], 'small') ?></div><?php endif; ?>
<div style="height:14px"></div>
<?php endif; ?>
<?php if ($D['incl'] || $D['excl']): ?>
<table><tr>
<?php foreach ([[$T['incl'], $D['incl'], '✓', '#2F7A45'], [$T['excl'], $D['excl'], '✕', '#B3241C']] as $col): ?>
<td style="width:50%;padding-right:14px"><?php if ($col[1]): ?>
<h4 style="margin-bottom:6px"><?= h($col[0]) ?></h4>
<table><?php foreach ($col[1] as $x): ?><tr><td style="width:14px;color:<?= $col[3] ?>;font-weight:bold"><?= $col[2] ?></td><td style="padding-bottom:4px"><?= h($x) ?></td></tr><?php endforeach; ?></table>
<?php endif; ?></td>
<?php endforeach; ?>
</tr></table>
<?php endif; ?>

<?php /* ── Contacts, terms ── */ ?>
<div class="brk"></div>
<h2><?= h($T['contacts']) ?></h2>
<table class="contacts">
<tr class="hd"><td class="th"><?= h($T['ref']) ?></td><td class="th"><?= h($T['phone']) ?></td><td class="th"><?= h($T['email']) ?></td></tr>
<?php foreach ($D['contacts'] as $c): ?><tr><td><?= h((string)$c['name']) ?></td><td><?= h((string)$c['phone']) ?></td><td><?= h((string)$c['email']) ?></td></tr><?php endforeach; ?>
</table>
<?php if ($pub !== ''): ?><p style="margin-top:10px"><span class="th"><?= h(mb_strtoupper($M['online'])) ?></span><br><a href="<?= h($pub) ?>" class="small" style="color:#B3241C"><?= h($pub) ?></a></p><?php endif; ?>
<?php if ($D['terms'] !== ''): ?>
<h3 style="margin-top:18px"><?= h($T['terms']) ?></h3>
<div class="terms"><?= strpos($D['terms'], '<') !== false && function_exists('iti_sanitize_richtext') ? iti_sanitize_richtext($D['terms']) : iti_mp_paras(strip_tags($D['terms'])) ?></div>
<?php endif; ?>
</body></html>
<?php
    return (string)ob_get_clean();
}

/** Render the PDF and return its bytes (temp photos / map deleted). */
function iti_mag_pdf_build(array $D): string {
    if (!class_exists('\Dompdf\Dompdf')) throw new RuntimeException('PDF library not installed (vendor/autoload.php).');
    $tmp = [];
    try {
        $html = iti_mag_pdf_html($D, $tmp);
        $o = new \Dompdf\Options();
        $o->set('isHtml5ParserEnabled', true);
        $o->set('isRemoteEnabled', false);                      // photos are local files only
        $o->set('defaultFont', 'DejaVu Sans');
        $o->setChroot([dirname(__DIR__), sys_get_temp_dir()]);  // module uploads + temp JPEGs / map
        $pdf = new \Dompdf\Dompdf($o);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        return (string)$pdf->output();
    } finally {
        foreach ($tmp as $f) if (is_file($f)) @unlink($f);
    }
}
