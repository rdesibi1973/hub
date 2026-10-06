<?php
/**
 * iti_guide_pdf.php — the programme for the safari guide (PDF, Dompdf): no photos,
 * no introduction, no prices, no lodge / destination descriptions. Page 1 recaps the
 * transfers / flights and the accommodation (check-in / check-out), then the day by day.
 * Same data as the client documents (iti_doc_data()).
 *
 * Keep PHP-7 style (no match / arrow functions / str_contains).
 */
require_once __DIR__ . '/iti_mag_pdf.php';   // iti_mp_paras() + iti_mag.php helpers

/** Strings of the guide sheet only (the rest comes from iti_doc_labels / iti_mag_labels). */
function iti_guide_labels(string $lang): array {
    $L = [
        'it' => ['sheet' => 'Programma per la guida', 'transfers' => 'Trasferimenti e voli', 'stays' => 'Alloggi',
                 'date' => 'Data', 'what' => 'Trasferimento / volo', 'in' => 'Arrivo', 'out' => 'Partenza', 'lodge' => 'Alloggio',
                 'client' => 'Cliente', 'ref' => 'Rif.', 'none' => 'Nessun trasferimento indicato.'],
        'en' => ['sheet' => 'Guide sheet', 'transfers' => 'Transfers and flights', 'stays' => 'Accommodation',
                 'date' => 'Date', 'what' => 'Transfer / flight', 'in' => 'Check-in', 'out' => 'Check-out', 'lodge' => 'Lodge / hotel',
                 'client' => 'Client', 'ref' => 'Ref.', 'none' => 'No transfers entered.'],
        'fr' => ['sheet' => 'Fiche guide', 'transfers' => 'Transferts et vols', 'stays' => 'Hébergements',
                 'date' => 'Date', 'what' => 'Transfert / vol', 'in' => 'Arrivée', 'out' => 'Départ', 'lodge' => 'Hébergement',
                 'client' => 'Client', 'ref' => 'Réf.', 'none' => 'Aucun transfert indiqué.'],
        'es' => ['sheet' => 'Hoja del guía', 'transfers' => 'Traslados y vuelos', 'stays' => 'Alojamientos',
                 'date' => 'Fecha', 'what' => 'Traslado / vuelo', 'in' => 'Entrada', 'out' => 'Salida', 'lodge' => 'Alojamiento',
                 'client' => 'Cliente', 'ref' => 'Ref.', 'none' => 'Ningún traslado indicado.'],
        'de' => ['sheet' => 'Programm für den Guide', 'transfers' => 'Transfers und Flüge', 'stays' => 'Unterkünfte',
                 'date' => 'Datum', 'what' => 'Transfer / Flug', 'in' => 'Anreise', 'out' => 'Abreise', 'lodge' => 'Unterkunft',
                 'client' => 'Kunde', 'ref' => 'Ref.', 'none' => 'Keine Transfers angegeben.'],
    ];
    return isset($L[$lang]) ? $L[$lang] : $L['en'];
}

/** Full HTML for Dompdf (no images, so nothing to clean up). */
function iti_guide_pdf_html(array $D): string {
    $T = $D['T'];
    $M = iti_mag_labels($D['lang']);
    $G = iti_guide_labels($D['lang']);
    $p = $D['program'];
    $stays = iti_mag_stays($D['days']);
    $dateRange = iti_mag_date_range($D, $M);
    $paxLabel = iti_mag_pax($D, $M);
    $client = trim((string)($p['client_name'] ?? ''));
    $ref = trim((string)($p['ref_number'] ?? ''));
    $foot = 'Savannah Explorers';
    foreach ($D['contacts'] as $c) $foot .= '  ·  ' . trim($c['name'] . ' ' . $c['phone']);
    $dayDate = function ($d, $wday) use ($M) { return !empty($d['date']) ? iti_mag_date($d['date'], $M, false, $wday) : ''; };
    $byN = [];
    foreach ($D['days'] as $d) $byN[$d['n']] = $d;
    $hasDates = !empty($D['days'][0]['date']);

    ob_start(); ?>
<!DOCTYPE html>
<html lang="<?= h($D['lang']) ?>"><head><meta charset="utf-8"><title><?= h($D['title']) ?></title>
<style>
@page{size:A4;margin:14mm 14mm 16mm}
body{font-family:"DejaVu Sans",sans-serif;font-size:9.5pt;line-height:1.4;color:#231F1C;margin:0}
#foot{position:fixed;bottom:-10mm;left:0;right:0;text-align:center;font-size:7.5pt;color:#6F675F}
.kick{font-size:7pt;font-weight:bold;color:#B3241C;text-transform:uppercase;letter-spacing:1.5px;margin:0}
h1{font-size:17pt;line-height:1.15;margin:3px 0 4px}
h2{font-size:11.5pt;margin:16px 0 6px;color:#B3241C}
p{margin:0 0 6px}
ul{margin:0 0 6px 14px;padding:0}
li{margin:0 0 2px}
.facts{font-size:9pt;margin:0 0 4px}
.facts b{color:#6F675F;font-weight:normal}
table{border-collapse:collapse;width:100%}
td{vertical-align:top}
.grid td{padding:4px 6px;border-bottom:1px solid #E6DDD0}
.grid tr.hd td{font-size:7pt;font-weight:bold;color:#6F675F;text-transform:uppercase;border-bottom:2px solid #231F1C}
.n{width:26px;font-weight:bold;color:#B3241C}
.nw{white-space:nowrap}
.brk{page-break-before:always}
.day{border-top:1px solid #E6DDD0;padding:8px 0 4px}
.dhead{font-size:11pt;font-weight:bold;margin:0 0 3px}
.dhead span{color:#B3241C}
.meta{font-size:8.5pt;margin:0 0 5px}
.meta td{padding:1px 8px 1px 0}
.lbl{width:90px;font-size:7pt;font-weight:bold;color:#6F675F;text-transform:uppercase}
.small{font-size:8pt;color:#6F675F}
</style></head><body>
<div id="foot"><?= h($foot) ?></div>

<p class="kick"><?= h($G['sheet']) ?></p>
<h1><?= h($D['title']) ?></h1>
<p class="facts"><?= h(implode('   ·   ', array_filter([$dateRange, $D['duration'], $paxLabel]))) ?></p>
<?php if ($client !== '' || $ref !== ''): ?>
<p class="facts"><?php if ($client !== ''): ?><b><?= h($G['client']) ?>:</b> <?= h($client) ?>&nbsp;&nbsp;&nbsp;<?php endif; ?>
<?php if ($ref !== ''): ?><b><?= h($G['ref']) ?>:</b> <?= h($ref) ?><?php endif; ?></p>
<?php endif; ?>

<?php /* ── Transfers and flights ── */ ?>
<h2><?= h($G['transfers']) ?></h2>
<?php $any = false; foreach ($D['days'] as $d) if ($d['transfers'] || !empty($d['flights'])) { $any = true; break; } ?>
<?php if ($any): ?>
<table class="grid">
<tr class="hd"><td><?= h($T['day']) ?></td><?php if ($hasDates): ?><td><?= h($G['date']) ?></td><?php endif; ?><td><?= h($G['what']) ?></td></tr>
<?php foreach ($D['days'] as $d):
        $lines = [];
        foreach ($d['flights'] ?? [] as $f) $lines[] = '✈ ' . h($f);
        foreach ($d['transfers'] as $t) $lines[] = '⇢ ' . h($t);
        if (!$lines) continue; ?>
<tr><td class="n"><?= (int)$d['n'] ?></td><?php if ($hasDates): ?><td class="nw"><?= h($dayDate($d, true)) ?></td><?php endif; ?><td><?= implode('<br>', $lines) ?></td></tr>
<?php endforeach; ?>
</table>
<?php else: ?><p class="small"><?= h($G['none']) ?></p><?php endif; ?>

<?php /* ── Accommodation ── */ ?>
<?php if ($stays): ?>
<h2><?= h($G['stays']) ?></h2>
<table class="grid">
<tr class="hd"><td><?= h($T['day']) ?></td><?php if ($hasDates): ?><td><?= h($G['in']) ?></td><td><?= h($G['out']) ?></td><?php endif; ?>
<td><?= h($G['lodge']) ?></td><td><?= h(ucfirst($T['nights'])) ?></td><td><?= h($T['board']) ?></td></tr>
<?php foreach ($stays as $st):
        $last = $st['day'] + $st['nights'] - 1;
        $in = isset($byN[$st['day']]) ? $dayDate($byN[$st['day']], true) : '';
        $out = $in !== '' ? iti_mag_date(date('Y-m-d', strtotime($byN[$st['day']]['date'] . ' +' . $st['nights'] . ' days')), $M, false, true) : ''; ?>
<tr><td class="n nw"><?= h($st['day'] . ($last > $st['day'] ? '–' . $last : '')) ?></td>
<?php if ($hasDates): ?><td class="nw"><?= h($in) ?></td><td class="nw"><?= h($out) ?></td><?php endif; ?>
<td><b><?= h($st['lodge']) ?></b><?= $st['room'] !== '' ? ' · ' . h($st['room']) : '' ?><?= $st['dest'] !== '' ? '<br><span class="small">' . h($st['dest']) . '</span>' : '' ?></td>
<td><?= (int)$st['nights'] ?></td><td><?= h(ucfirst($st['meals'])) ?></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php /* ── Day by day ── */ ?>
<div class="brk"></div>
<h2 style="margin-top:0"><?= h($T['program']) ?></h2>
<?php foreach ($D['days'] as $d): ?>
<div class="day">
<p class="dhead"><span><?= h($T['day'] . ' ' . (int)$d['n']) ?></span><?= !empty($d['date']) ? ' · ' . h($dayDate($d, true)) : '' ?> — <?= h($d['title']) ?></p>
<table class="meta">
<?php foreach ($d['flights'] ?? [] as $f): ?><tr><td class="lbl">✈</td><td><?= h($f) ?></td></tr><?php endforeach; ?>
<?php foreach ($d['transfers'] as $t): ?><tr><td class="lbl"><?= h($T['transfer']) ?></td><td><?= h($t) ?></td></tr><?php endforeach; ?>
<?php if ($d['activities']): ?><tr><td class="lbl"><?= h($T['activities']) ?></td><td><?= h(implode(' · ', $d['activities'])) ?></td></tr><?php endif; ?>
<tr><td class="lbl"><?= h($T['meals']) ?></td><td><?= h($d['meals']) ?></td></tr>
<?php if ($d['lodge'] !== ''): ?><tr><td class="lbl"><?= h($T['overnight']) ?></td><td><b><?= h($d['lodge']) ?></b><?= $d['room'] !== '' ? ' · ' . h($d['room']) : '' ?><?= ($d['lodge_area'] ?? '') !== '' ? ' <span class="small">· ' . h($d['lodge_area']) . '</span>' : '' ?></td></tr><?php endif; ?>
</table>
<?= iti_mp_paras($d['narrative']) ?>
</div>
<?php endforeach; ?>
</body></html>
<?php
    return (string)ob_get_clean();
}

/** Render the guide PDF and return its bytes. */
function iti_guide_pdf_build(array $D): string {
    if (!class_exists('\Dompdf\Dompdf')) throw new RuntimeException('PDF library not installed (vendor/autoload.php).');
    $o = new \Dompdf\Options();
    $o->set('isHtml5ParserEnabled', true);
    $o->set('isRemoteEnabled', false);
    $o->set('defaultFont', 'DejaVu Sans');
    $pdf = new \Dompdf\Dompdf($o);
    $pdf->loadHtml(iti_guide_pdf_html($D), 'UTF-8');
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();
    return (string)$pdf->output();
}
