<?php
/**
 * iti_guide_pdf.php — the programme for the safari guide (PDF, Dompdf): no photos,
 * no introduction, no prices, no lodge / destination descriptions. Page 1 recaps the
 * transfers / flights and the accommodation (check-in / check-out), then the day by day.
 * Same data as the client documents (iti_doc_data()), cut to the safari by iti_guide_data():
 * no Zanzibar / beach days, the arrival flight on day 1. The Word copy: iti_guide_word.php.
 *
 * Black and white only (printed on a B/W printer): black text, grey rules, no colours.
 *
 * Keep PHP-7 style (no match / arrow functions / str_contains).
 */
require_once __DIR__ . '/iti_mag_pdf.php';   // iti_mp_paras() + iti_mag.php helpers

/** Strings of the guide sheet only (the rest comes from iti_doc_labels / iti_mag_labels). */
function iti_guide_labels(string $lang): array {
    $L = [
        'it' => ['sheet' => 'Programma per la guida', 'transfers' => 'Trasferimenti e voli', 'stays' => 'Alloggi',
                 'date' => 'Data', 'what' => 'Trasferimento / volo', 'in' => 'Arrivo', 'out' => 'Partenza', 'lodge' => 'Alloggio',
                 'client' => 'Cliente', 'ref' => 'Rif.', 'none' => 'Nessun trasferimento indicato.', 'arrival' => 'Volo di arrivo'],
        'en' => ['sheet' => 'Guide sheet', 'transfers' => 'Transfers and flights', 'stays' => 'Accommodation',
                 'date' => 'Date', 'what' => 'Transfer / flight', 'in' => 'Check-in', 'out' => 'Check-out', 'lodge' => 'Lodge / hotel',
                 'client' => 'Client', 'ref' => 'Ref.', 'none' => 'No transfers entered.', 'arrival' => 'Arrival flight'],
        'fr' => ['sheet' => 'Fiche guide', 'transfers' => 'Transferts et vols', 'stays' => 'Hébergements',
                 'date' => 'Date', 'what' => 'Transfert / vol', 'in' => 'Arrivée', 'out' => 'Départ', 'lodge' => 'Hébergement',
                 'client' => 'Client', 'ref' => 'Réf.', 'none' => 'Aucun transfert indiqué.', 'arrival' => 'Vol d\'arrivée'],
        'es' => ['sheet' => 'Hoja del guía', 'transfers' => 'Traslados y vuelos', 'stays' => 'Alojamientos',
                 'date' => 'Fecha', 'what' => 'Traslado / vuelo', 'in' => 'Entrada', 'out' => 'Salida', 'lodge' => 'Alojamiento',
                 'client' => 'Cliente', 'ref' => 'Ref.', 'none' => 'Ningún traslado indicado.', 'arrival' => 'Vuelo de llegada'],
        'de' => ['sheet' => 'Programm für den Guide', 'transfers' => 'Transfers und Flüge', 'stays' => 'Unterkünfte',
                 'date' => 'Datum', 'what' => 'Transfer / Flug', 'in' => 'Anreise', 'out' => 'Abreise', 'lodge' => 'Unterkunft',
                 'client' => 'Kunde', 'ref' => 'Ref.', 'none' => 'Keine Transfers angegeben.', 'arrival' => 'Ankunftsflug'],
    ];
    return isset($L[$lang]) ? $L[$lang] : $L['en'];
}

/** A night by the sea: a beach / relax stay (is_beach_stay), or anywhere on Zanzibar (Stone Town too). */
function iti_guide_sea_night(array $d): bool {
    if ($d['lodge'] === '') return false;
    if (!empty($d['beach'])) return true;
    $area = ($d['lodge_area'] ?? '') !== '' ? $d['lodge_area'] : $d['dest'];
    return (bool)preg_match('/zanzibar|sansibar|stone town|pemba|mafia/iu', (string)$area);
}

/**
 * iti_doc_data() cut to the guide's part of the trip:
 * - days at the sea are left out (Zanzibar / beach nights and the departure day after them); the day
 *   the clients fly to the sea stays (the guide takes them to the airstrip), without the beach hotel;
 * - 'arrival' on day 1 = the arrival flight of the booking (iti_program_booking.arrival_details,
 *   from the Calc), unless the trip starts at the sea;
 * - duration recounted on the days kept (day numbers and dates stay those of the client's programme).
 */
function iti_guide_data(array $D): array {
    $arrival = '';
    try {
        $st = db()->prepare('SELECT arrival_details FROM iti_program_booking WHERE program_id = ?');
        $st->execute([(int)$D['program']['id']]);
        $arrival = trim((string)$st->fetchColumn());
    } catch (PDOException $e) {
        $arrival = '';   // table not created yet (no final programme from a Calc)
    }
    $keep = []; $prevSea = false; $first = true;
    foreach ($D['days'] as $d) {
        $sea = iti_guide_sea_night($d);
        $drop = $first ? $sea : ($prevSea && ($sea || $d['lodge'] === ''));
        $prevSea = $sea; $first = false;
        if ($drop) continue;
        if ($sea) { $d['lodge'] = ''; $d['room'] = ''; $d['lodge_area'] = ''; $d['lodge_key'] = ''; }   // flies to the sea
        $d['arrival'] = '';
        $keep[] = $d;
    }
    if ($keep && $arrival !== '' && $keep[0]['n'] === $D['days'][0]['n']) $keep[0]['arrival'] = $arrival;
    $T = $D['T'];
    $n = count($keep); $nights = 0;
    foreach ($keep as $d) if ($d['lodge'] !== '') $nights++;
    $D['days'] = $keep;
    $D['duration'] = $n . ' ' . ($n === 1 ? $T['day1'] : $T['days']) . ' / ' . $nights . ' ' . ($nights === 1 ? $T['night1'] : $T['nights']);
    return $D;
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
body{font-family:"DejaVu Sans",sans-serif;font-size:9.5pt;line-height:1.4;color:#000;margin:0}
#foot{position:fixed;bottom:-10mm;left:0;right:0;text-align:center;font-size:7.5pt;color:#444}
.kick{font-size:7pt;font-weight:bold;color:#000;text-transform:uppercase;letter-spacing:1.5px;margin:0}
h1{font-size:17pt;line-height:1.15;margin:3px 0 4px}
h2{font-size:11.5pt;margin:16px 0 6px;color:#000}
p{margin:0 0 6px}
ul{margin:0 0 6px 14px;padding:0}
li{margin:0 0 2px}
.facts{font-size:9pt;margin:0 0 4px}
.facts b{color:#444;font-weight:normal}
table{border-collapse:collapse;width:100%}
td{vertical-align:top}
.grid td{padding:4px 6px;border-bottom:1px solid #999}
.grid tr.hd td{font-size:7pt;font-weight:bold;color:#444;text-transform:uppercase;border-bottom:2px solid #000}
.n{width:26px;font-weight:bold;color:#000}
.nw{white-space:nowrap}
.brk{page-break-before:always}
.day{border-top:1px solid #999;padding:8px 0 4px}
.dhead{font-size:11pt;font-weight:bold;margin:0 0 3px}
.dhead span{color:#000}
.meta{font-size:8.5pt;margin:0 0 5px}
.meta td{padding:1px 8px 1px 0}
.lbl{width:90px;font-size:7pt;font-weight:bold;color:#444;text-transform:uppercase}
.small{font-size:8pt;color:#444}
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
<?php $any = false; foreach ($D['days'] as $d) if ($d['transfers'] || !empty($d['flights']) || !empty($d['arrival'])) { $any = true; break; } ?>
<?php if ($any): ?>
<table class="grid">
<tr class="hd"><td><?= h($T['day']) ?></td><?php if ($hasDates): ?><td><?= h($G['date']) ?></td><?php endif; ?><td><?= h($G['what']) ?></td></tr>
<?php foreach ($D['days'] as $d):
        $lines = [];
        if (!empty($d['arrival'])) $lines[] = '<b>' . h($G['arrival']) . ':</b> ' . nl2br(h($d['arrival']));
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
<?php if (!empty($d['arrival'])): ?><tr><td class="lbl"><?= h($G['arrival']) ?></td><td><b><?= nl2br(h($d['arrival'])) ?></b></td></tr><?php endif; ?>
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
