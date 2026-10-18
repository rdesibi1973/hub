<?php
/**
 * procedures.php — "📘 Procedures": the office procedures, always the current version from Dropbox
 * /Operations/OfficeProcedures (procedures_service.php). Every logged-in user, read-only: PDFs only, a click on the
 * title opens the PDF in a new tab. Admins also get a link to the Dropbox folder (Word sources are edited there, in orig/).
 * Above the documents: how the office works, from the lead to the safari (Roberto, 18 Oct 2026; $flow below).
 * Meant to grow into the entry point of the Hub manual.
 */
ob_start();
require_once 'config.php';
requireLogin();
require_once __DIR__ . '/includes/procedures_service.php';
$pageTitle = 'Procedures';
$currentUser = current_user();
$admin = proc_is_admin();

$list = null;
$error = null;
try {
    $list = proc_list();
} catch (Throwable $e) {
    error_log('procedures: ' . $e->getMessage());
    $error = 'Dropbox is not reachable right now, try again in a minute.';
}
$fmtDate = function (string $iso) {
    $ts = strtotime($iso);
    return $ts ? date('d M Y', $ts) : '';
};
// How the office works: team, what happens, Hub pages / procedures that go with it ([label, url]).
$flow = [
    ['Sales', 'A lead arrives', 'A new request (email, web form, agency) reaches the sales team and is assigned to a seller.',
     [['Requests', 'requests.php'], ['Incoming', 'staging.php']]],
    ['Sales', 'Quotation and follow-up', 'The seller prepares the quotation and follows the client by email until the trip is confirmed '
     . '— every client email answered within 24 hours.', [['Procedure: Prepare customer quotation', proc_url('PREPARE CUSTOMER QUOTATION.pdf')],
     ['Sales Response', 'sales_response.php']]],
    ['Sales → Booking', 'Sent to confirmation', 'When the client confirms, the seller sends the trip to the booking team '
     . '(Confirm Safari in the BackOffice; the Hub prepares the booking email).',
     [['Procedure: Confirmed trips, section 3', proc_url('DROPBOX-REQUESTS-CONFIRMED TRIPS.pdf', false, 1)], ['BackOffice', 'backoffice.php']]],
    ['Booking', 'Bookings', 'The booking team books all the services. When the bookings are complete, it informs the seller.',
     [['Booked', 'booked.php']]],
    ['Booking + Sales', 'CK — check of the bookings', 'As soon as the bookings are complete — the sooner the better — booking and sales '
     . 'check that every booking made matches the trip (services, dates, guests); when everything is right the folder gets its CK.',
     [['CK tracker', 'ck_tracker.php'], ['Procedure: folder status, section 4', proc_url('DROPBOX-REQUESTS-CONFIRMED TRIPS.pdf', false, 2)]]],
    ['Sales + Accountant', 'Client confirmation and payments', 'The seller confirms the trip to the client and follows the client payments '
     . '(deposit, balance), checking with the accountant that every payment has been received.',
     [['Payments', 'payments.php'], ['Procedure: client payments, section 6', proc_url('DROPBOX-REQUESTS-CONFIRMED TRIPS.pdf', false, 3)]]],
    ['Booking + Accountant', 'Suppliers and flight tickets', 'Booking and accountant prepare the supplier payments, make sure no booked service '
     . 'is lost (deadlines, deposits), and receive and archive the domestic flight tickets in time.',
     [['Booking follow-up', '../operations/booking_followup.php']]],
    ['Operations', 'The safari', 'The operations team schedules the guides for the safari and hands off the file.',
     [['Operations', '../operations/index.php']]],
];
$fmtSize = function (int $b) { return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB'; };

include 'includes/header.php';
?>
<div class="page-header">
  <div>
    <h2>📘 Procedures</h2>
    <div class="sub">Office procedures — always the current version from Dropbox. Each document has the English text first and the Italian text after.</div>
  </div>
  <?php if ($admin): ?>
  <a class="btn btn-outline" href="https://www.dropbox.com/home<?= h(str_replace('%2F', '/', rawurlencode(PROC_DIR))) ?>" target="_blank" rel="noopener" style="font-size:.8rem">Open folder in Dropbox ↗</a>
  <?php endif; ?>
</div>

<style>
.pf-flow{list-style:none;margin:0;padding:0;counter-reset:pf}
.pf-flow li{position:relative;padding:0 0 16px 46px;counter-increment:pf}
.pf-flow li::before{content:counter(pf);position:absolute;left:0;top:0;width:30px;height:30px;border-radius:50%;background:#C0211B;color:#fff;
  font-weight:700;font-size:.85rem;display:flex;align-items:center;justify-content:center}
.pf-flow li:not(:last-child)::after{content:'';position:absolute;left:14px;top:32px;bottom:2px;width:2px;background:#eee}
.pf-team{display:inline-block;border-radius:10px;padding:1px 9px;font-size:.7rem;font-weight:700;background:#f3ece4;color:#7a4a1f;margin-right:6px;vertical-align:1px}
.pf-what{font-weight:700}
.pf-text{font-size:.86rem;color:var(--grey-dk,#444);margin:3px 0 4px;line-height:1.5}
.pf-links{font-size:.78rem}
.pf-links a{margin-right:12px}
</style>

<div class="table-wrap" style="margin-bottom:20px">
  <div style="padding:16px 20px 6px">
    <h3 style="font-size:1rem;margin:0 0 14px">How the office works — from the lead to the safari</h3>
    <ol class="pf-flow">
    <?php foreach ($flow as [$team, $what, $text, $links]): ?>
      <li>
        <div><span class="pf-team"><?= h($team) ?></span><span class="pf-what"><?= h($what) ?></span></div>
        <div class="pf-text"><?= h($text) ?></div>
        <div class="pf-links"><?php foreach ($links as [$label, $url]): ?><a href="<?= h($url) ?>"<?= strpos($url, 'procedure_file.php') === 0 ? ' target="_blank" rel="noopener"' : '' ?>><?= h($label) ?> →</a><?php endforeach; ?></div>
      </li>
    <?php endforeach; ?>
    </ol>
  </div>
</div>

<h3 style="font-size:1rem;margin:0 0 8px">Procedure documents</h3>
<?php if ($error): ?>
<div class="flash flash-error"><?= h($error) ?></div>
<?php else: ?>
<div class="table-wrap">
  <table>
    <thead><tr><th>Procedure</th><th>Last updated</th><th>Size</th></tr></thead>
    <tbody>
    <?php if (!$list['pdf']): ?>
      <tr><td colspan="3" style="color:#777">No procedure in the folder yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($list['pdf'] as $e): ?>
      <tr>
        <td><b><a href="<?= h(proc_url($e['key'])) ?>" target="_blank" rel="noopener"><?= h(proc_title($e['name'])) ?></a></b></td>
        <td><?= h($fmtDate($e['modified'])) ?></td>
        <td><?= h($fmtSize($e['size'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<?php include 'includes/footer.php'; ?>
