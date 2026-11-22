<?php
/**
 * reports_pdf.php — Sales Report / Travel Period as a PDF to share (Dompdf, A4 portrait).
 * Called by reports.php (?pdf=1) with the data it already built for the page:
 *   type     'sales' | 'travel'
 *   period   title of the period ("November 2026", "01 Jan 2026 → 31 Mar 2026")
 *   agent    agent name when the report is filtered on one seller, else ''
 *   summary  buildSummary() result (sales)       travel  $travel_summary (travel)
 *   no_date  Booked requests without start date (travel warning)
 *   channels buildChannelBreakdown() result or null
 * Tables only (Dompdf has no flexbox); commissions never printed — the PDF is meant to be shared.
 */

function rpdf_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function rpdf_usd($v): string { return (float)$v > 0 ? '$' . number_format((float)$v, 0) : '—'; }

/** Share bar: grey track with a coloured fill, inside a table cell. */
function rpdf_bar(float $pct, string $color, bool $sub = false): string {
    $w = max(0, min(100, $pct));
    return '<table class="bar"><tr><td class="track"><div style="width:' . number_format($w, 1, '.', '') . '%;background:' . $color
         . ';height:7px' . ($sub ? ';opacity:.5' : '') . '"></div></td><td class="pc' . ($sub ? '' : ' b') . '">'
         . number_format($w, 1, '.', '') . '%</td></tr></table>';
}

function reports_pdf_html(array $D): string {
    $isTravel = $D['type'] === 'travel';
    $title    = $isTravel ? 'Travel Period Report' : 'Sales Report';
    $basis    = $isTravel ? 'Booked trips starting in the period (by start date)'
                          : 'Received = requests received in the period · Confirmed and Sales = deals closed in the period (by confirmation date)';
    $C = $D['channels'];
    ob_start();
?><!DOCTYPE html>
<html><head><meta charset="UTF-8"><style>
  @page { margin: 16mm 14mm 16mm 14mm; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #333; }
  .head { border-bottom: 2px solid #C0211B; padding-bottom: 6px; margin-bottom: 12px; }
  .head .brand { font-size: 8pt; color: #888; letter-spacing: .08em; text-transform: uppercase; }
  .head h1 { font-size: 16pt; color: #A01A14; margin: 2px 0 0; }
  .head .per { font-size: 11pt; font-weight: bold; margin-top: 2px; }
  .head .note { font-size: 7.5pt; color: #888; margin-top: 3px; }
  table.kpi { width: 100%; border-collapse: separate; border-spacing: 4px 0; margin: 0 -4px 14px; }
  table.kpi td { background: #f6f6f6; border-top: 3px solid #1A6B3A; padding: 6px 8px; width: 20%; }
  table.kpi .l { font-size: 6.5pt; color: #888; text-transform: uppercase; letter-spacing: .05em; font-weight: bold; }
  table.kpi .v { font-size: 13pt; font-weight: bold; color: #222; margin-top: 2px; }
  h2 { font-size: 10.5pt; color: #222; margin: 16px 0 2px; }
  p.sub { font-size: 7.5pt; color: #888; margin: 0 0 6px; }
  table.t { width: 100%; border-collapse: collapse; }
  table.t th { background: #1A6B3A; color: #fff; font-size: 7.5pt; padding: 5px 6px; text-align: left; }
  table.t td { padding: 4px 6px; border-bottom: 1px solid #E8E8E8; vertical-align: middle; }
  table.t .r { text-align: right; }
  table.t tr.tot td { font-weight: bold; background: #f6f6f6; border-top: 1.5px solid #ccc; }
  table.t tr.sub td { color: #888; font-size: 8pt; }
  .g { color: #1A6B3A; } .m { color: #888; } .b { font-weight: bold; }
  table.bar { width: 100%; border-collapse: collapse; }
  table.bar td { border: 0; padding: 0; }
  table.bar td.track { background: #E8E8E8; height: 7px; }
  table.bar td.pc { width: 38px; text-align: right; font-size: 8pt; }
  .warn { background: #fff8e1; border: 1px solid #ffe082; padding: 5px 8px; font-size: 8pt; color: #6d4c00; margin-bottom: 10px; }
  .foot { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7pt; color: #aaa; text-align: right; }
</style></head><body>
<div class="foot">Savannah Explorers · <?= rpdf_e($title) ?> · <?= rpdf_e($D['period']) ?> · generated <?= date('d M Y H:i') ?> EAT</div>
<div class="head">
  <div class="brand">Savannah Explorers</div>
  <h1><?= rpdf_e($title) ?></h1>
  <div class="per"><?= rpdf_e($D['period']) ?><?= $D['agent'] !== '' ? ' · Agent: ' . rpdf_e($D['agent']) : '' ?></div>
  <div class="note"><?= rpdf_e($basis) ?></div>
</div>

<?php if ($isTravel):
    $T = $D['travel']['totals']; ?>
<?php if ((int)$D['no_date'] > 0): ?>
<div class="warn"><?= (int)$D['no_date'] ?> Booked request<?= $D['no_date'] > 1 ? 's have' : ' has' ?> no start date and <?= $D['no_date'] > 1 ? 'are' : 'is' ?> not in this report.</div>
<?php endif; ?>
<table class="kpi"><tr>
  <td><div class="l">Trips</div><div class="v"><?= (int)$T['trips'] ?></div></td>
  <td><div class="l">PAX</div><div class="v"><?= (int)$T['pax'] ?></div></td>
  <td><div class="l">Sales Amount</div><div class="v"><?= rpdf_usd($T['sales']) ?></div></td>
  <td><div class="l">$ / PAX</div><div class="v"><?= $T['pax'] > 0 ? rpdf_usd($T['sales'] / $T['pax']) : '—' ?></div></td>
</tr></table>

<h2>By seller</h2>
<table class="t">
  <tr><th>Agent</th><th class="r">Trips</th><th class="r">PAX</th><th class="r">Sales Amount</th></tr>
  <?php foreach ($D['travel']['rows'] as $r): if ((int)$r['trips'] === 0) continue; ?>
  <tr><td class="b"><?= rpdf_e($r['name']) ?></td><td class="r"><?= (int)$r['trips'] ?></td>
      <td class="r"><?= (int)$r['pax'] ?: '—' ?></td><td class="r g b"><?= rpdf_usd($r['sales']) ?></td></tr>
  <?php endforeach; ?>
  <tr class="tot"><td>TOTAL</td><td class="r"><?= (int)$T['trips'] ?></td><td class="r"><?= (int)$T['pax'] ?></td>
      <td class="r g"><?= rpdf_usd($T['sales']) ?></td></tr>
</table>

<?php else:
    $T = $D['summary']['totals']; ?>
<table class="kpi"><tr>
  <td><div class="l">Received</div><div class="v"><?= (int)$T['total'] ?></div></td>
  <td><div class="l">Confirmed</div><div class="v"><?= (int)$T['confirmed'] ?></div></td>
  <td><div class="l">Sales Rate</div><div class="v"><?= rpdf_e($T['rate']) ?>%</div></td>
  <td><div class="l">Sales Amount</div><div class="v"><?= rpdf_usd($T['sales_amount']) ?></div></td>
  <td><div class="l">PAX (Booked)</div><div class="v"><?= (int)$T['booked_pax'] ?></div></td>
</tr></table>

<h2>By seller</h2>
<table class="t">
  <tr><th>Agent</th><th class="r">Received</th><th class="r">Confirmed</th><th class="r">Sales Rate</th><th class="r">Sales Amount</th><th class="r">PAX</th></tr>
  <?php foreach ($D['summary']['rows'] as $r): if ((int)$r['total'] === 0 && (int)$r['confirmed'] === 0) continue; ?>
  <tr><td class="b"><?= rpdf_e($r['agent']) ?></td><td class="r"><?= (int)$r['total'] ?></td>
      <td class="r"><?= (int)$r['confirmed'] ?: '—' ?></td><td class="r b"><?= rpdf_e($r['rate']) ?>%</td>
      <td class="r g"><?= rpdf_usd($r['sales_amount']) ?></td><td class="r m"><?= (int)$r['booked_pax'] ?: '—' ?></td></tr>
  <?php endforeach; ?>
  <tr class="tot"><td>TOTAL</td><td class="r"><?= (int)$T['total'] ?></td><td class="r"><?= (int)$T['confirmed'] ?></td>
      <td class="r"><?= rpdf_e($T['rate']) ?>%</td><td class="r g"><?= rpdf_usd($T['sales_amount']) ?></td><td class="r"><?= (int)$T['booked_pax'] ?></td></tr>
</table>
<?php endif; ?>

<?php if ($C !== null && $C['total'] > 0):
    $ct  = (float)$C['total'];
    $pct = fn($v) => $ct > 0 ? (float)$v / $ct * 100 : 0.0;
    $ppx = fn($c) => $c['pax'] > 0 ? rpdf_usd($c['sales'] / $c['pax']) : '—';
    $nw  = $C['network'];
    $tables = [
        ['Sales by network', 'Network', 'Promoservice / Lamprati = the agency\'s type in Agencies (-PS / -LAM); everything else, direct clients included, is Savannah.', [
            ['Savannah', $nw['savannah'], '#1A6B3A', false],
            ['direct clients (incl. SB)', $nw['sav_direct'], '#1A6B3A', true],
            ['Savannah agencies', $nw['sav_agency'], '#1A6B3A', true],
            ['Promoservice', $nw['promoservice'], '#e05c1a', false],
            ['Lamprati', $nw['lamprati'], '#1a6bb3', false],
        ]],
        ['Sales by channel', 'Channel', 'SB bookings count as direct clients.', array_merge([
            ['Agencies', $C['agency'], '#1a6bb3', false],
            ['Direct clients', $C['direct'], '#1A6B3A', false],
            ['of which SB', $C['sb'], '#1A6B3A', true],
        ], $C['unknown']['n'] > 0 ? [['Not classified', $C['unknown'], '#888888', false]] : [])],
    ];
    foreach ($tables as [$h, $first, $note, $rows]): ?>
<h2><?= rpdf_e($h) ?></h2>
<p class="sub"><?= rpdf_e($note) ?></p>
<table class="t">
  <tr><th><?= rpdf_e($first) ?></th><th class="r">Bookings</th><th class="r">Sales Amount</th><th style="width:150px">Share</th><th class="r">PAX</th><th class="r">$ / PAX</th></tr>
  <?php foreach ($rows as [$label, $c, $color, $sub]): ?>
  <tr<?= $sub ? ' class="sub"' : '' ?>>
    <td<?= $sub ? ' style="padding-left:16px"' : ' class="b"' ?>><?= $sub ? '↳ ' : '' ?><?= rpdf_e($label) ?></td>
    <td class="r"><?= (int)$c['n'] ?: '—' ?></td>
    <td class="r<?= $sub ? '' : ' g' ?>"><?= rpdf_usd($c['sales']) ?></td>
    <td><?= rpdf_bar($pct($c['sales']), $color, $sub) ?></td>
    <td class="r m"><?= (int)$c['pax'] ?: '—' ?></td>
    <td class="r m"><?= $ppx($c) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endforeach; ?>

<?php $ag = $C['agencies']; if ($ag): ?>
<h2>Sales by agency</h2>
<p class="sub"><?= count($ag) ?> agencies with <?= $isTravel ? 'trips starting' : 'bookings' ?> in the period<?php
  if (count($ag) > 10): ?> · top 10 = <?= number_format($pct(array_sum(array_column(array_slice($ag, 0, 10), 'sales'))), 1) ?>% of all sales<?php endif; ?>.</p>
<table class="t">
  <tr><th class="r" style="width:20px">#</th><th>Agency</th><th class="r">Bookings</th><th class="r">Sales Amount</th><th class="r">% of all</th><th class="r">Cumulative</th><th class="r">PAX</th><th class="r">$ / PAX</th></tr>
  <?php $cum = 0; foreach ($ag as $i => $a): $cum += (float)$a['sales']; ?>
  <tr>
    <td class="r m"><?= $i + 1 ?></td>
    <td class="b"><?= $a['agency_id'] ? rpdf_e($a['nome'] ?? ('#' . $a['agency_id'])) : '<span class="m">Agency not in the list</span>' ?></td>
    <td class="r"><?= (int)$a['n'] ?></td>
    <td class="r g"><?= rpdf_usd($a['sales']) ?></td>
    <td class="r b"><?= number_format($pct($a['sales']), 1) ?>%</td>
    <td class="r m"><?= number_format($pct($cum), 1) ?>%</td>
    <td class="r m"><?= (int)$a['pax'] ?: '—' ?></td>
    <td class="r m"><?= $ppx(['sales' => (float)$a['sales'], 'pax' => (int)$a['pax']]) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>
<?php endif; ?>
</body></html>
<?php
    return (string)ob_get_clean();
}

/** Render the report PDF and return its bytes. */
function reports_pdf_build(array $D): string {
    $auto = __DIR__ . '/../../../vendor/autoload.php';
    if (!class_exists('\Dompdf\Dompdf') && is_file($auto)) require_once $auto;
    if (!class_exists('\Dompdf\Dompdf')) throw new RuntimeException('PDF library not installed (vendor/autoload.php).');
    $o = new \Dompdf\Options();
    $o->set('isHtml5ParserEnabled', true);
    $o->set('isRemoteEnabled', false);
    $o->set('defaultFont', 'DejaVu Sans');
    $pdf = new \Dompdf\Dompdf($o);
    $pdf->loadHtml(reports_pdf_html($D), 'UTF-8');
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();
    return (string)$pdf->output();
}
