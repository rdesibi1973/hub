<?php
/**
 * booking_followup.php — Operations → Booking follow-up. Supplier bookings not confirmed and
 * domestic tickets not saved, filled by the weekly / daily check (followup_cron.php) or
 * "Run check now"; the booking team takes lines in charge here.
 * Every logged-in Hub user can view; edits need module booking_followup_edit (admin: also
 * reassign + not relevant). Data / changes: api/followup.php → includes/followup_service.php.
 * ?request_id=N or ?q=… open the page filtered (link from the request view).
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/followup_service.php';
hub_db_timezone($pdo);

$fuUser = current_user();
$fuData = followup_page_data($pdo, $fuUser);
$page_title = 'Booking follow-up — Savannah Explorers Hub';

$extra_css = '
.fu-head{display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.fu-head .page-title{margin-bottom:4px}
.fu-sub{font-size:.78rem;color:var(--grey-mid)}
.fu-run{margin-left:auto;display:flex;gap:8px;align-items:center}
.fu-run select{padding:7px 12px;border:1.5px solid var(--grey-lt);border-radius:20px;font-family:inherit;font-size:.8rem;background:var(--white)}
.fu-msg{display:none;margin:0 0 16px;padding:12px 16px;border-radius:8px;font-size:.82rem;line-height:1.5;background:var(--navy-lt);color:#123a5c}
.fu-msg.err{background:var(--red-lt);color:var(--red-dk)}
.fu-stats{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin-bottom:18px}
.stat-card{background:var(--white);border-radius:10px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:14px 16px;border-top:3px solid var(--grey-lt);cursor:pointer}
.stat-card .v{font-family:Merriweather,serif;font-size:1.5rem;font-weight:700;color:var(--black);font-variant-numeric:tabular-nums}
.stat-card .l{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);margin-top:2px}
.stat-card.red{border-top-color:var(--red)} .stat-card.amber{border-top-color:var(--amber)} .stat-card.navy{border-top-color:var(--navy)} .stat-card.green{border-top-color:var(--green)}
.fu-tabs{display:flex;gap:4px;border-bottom:1px solid var(--grey-lt);margin-bottom:4px;overflow-x:auto}
.fu-tabs button{font-family:inherit;font-size:.78rem;font-weight:700;color:var(--grey-mid);background:none;border:none;border-bottom:3px solid transparent;padding:9px 14px;cursor:pointer;white-space:nowrap}
.fu-tabs button.active{color:var(--red);border-bottom-color:var(--red)}
.fu-tabs .n{font-weight:600;color:var(--grey-mid);margin-left:4px}
.fu-chip{display:inline-flex;align-items:center;gap:6px;background:var(--navy-lt);color:var(--navy);border-radius:20px;padding:6px 12px;font-size:.78rem;font-weight:600;align-self:flex-end}
.fu-chip[hidden]{display:none}
.fu-chip button{border:none;background:none;color:inherit;cursor:pointer;font-size:.9rem}
.table-wrap{overflow-x:auto}
table.fu-table{font-size:.78rem}
table.fu-table td,table.fu-table th{padding:8px 10px;vertical-align:top}
table.fu-table tr.problem td{background:#FDF1F0}
table.fu-table tr.problem td:first-child{box-shadow:inset 3px 0 0 var(--red)}
table.fu-table tr.hist td{background:var(--off-white);font-size:.74rem}
.fu-client{font-weight:700;color:var(--black)}
.fu-folder{display:block;font-size:.7rem;color:var(--grey-mid);text-decoration:none;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.fu-folder:hover{color:var(--red)}
.fu-days{display:block;font-size:.7rem;color:var(--grey-mid)}
.fu-late{color:var(--red-dk);font-weight:700}
.badge-p1{background:var(--red-lt);color:var(--red-dk)} .badge-p2{background:var(--amber-lt);color:#7A4F01} .badge-p3{background:var(--grey-lt);color:var(--grey-dk)}
.badge-issue{background:var(--navy-lt);color:var(--navy);text-transform:none;letter-spacing:0;font-size:.7rem}
.badge-issue.exp{background:var(--red-lt);color:var(--red-dk)}
.fu-table select,.fu-table input[type=date],.fu-table textarea{font-family:inherit;font-size:.75rem;border:1.5px solid var(--grey-lt);border-radius:6px;padding:4px 6px;background:var(--white);color:var(--black)}
.fu-table textarea{width:190px;min-height:34px;resize:vertical}
.fu-table select:focus,.fu-table input:focus,.fu-table textarea:focus{outline:none;border-color:var(--red)}
.fu-saved{font-size:.68rem;color:var(--green)} .fu-err{font-size:.68rem;color:var(--red-dk);display:block}
.fu-hbtn{border:none;background:none;color:var(--grey-mid);cursor:pointer;font-size:.72rem;padding:0}
.fu-hbtn:hover{color:var(--red)}
.fu-empty{padding:28px;text-align:center;color:var(--grey-mid);font-size:.85rem}
.fu-ev{list-style:none;margin:0;padding:0}
.fu-ev li{padding:2px 0;color:var(--grey-dk)}
.fu-ev .w{color:var(--grey-mid);margin-right:8px;font-variant-numeric:tabular-nums}
@media(max-width:1100px){.fu-stats{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:600px){.fu-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.fu-run{margin-left:0}}
';
$extra_js = '<script>window.FU_DATA = ' . json_encode($fuData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>'
          . '<script src="booking_followup.js?v=' . @filemtime(__DIR__ . '/booking_followup.js') . '"></script>';
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
  <div class="fu-head">
    <div>
      <div class="page-title">📌 Booking follow-up</div>
      <div class="fu-sub" id="fu-runs"></div>
    </div>
    <?php if ($fuData['me']['can_edit']): ?>
    <div class="fu-run">
      <select id="fu-run-type" aria-label="What to check">
        <option value="ticket">Tickets (next 14 days)</option>
        <option value="supplier">Supplier documents</option>
        <option value="all">Both</option>
      </select>
      <button type="button" class="btn btn-primary btn-sm" id="fu-run-btn">▶ Run check now</button>
    </div>
    <?php endif; ?>
  </div>
  <div class="fu-msg" id="fu-msg"></div>

  <div class="fu-stats" id="fu-stats"></div>

  <div class="fu-tabs" id="fu-tabs" role="tablist"></div>

  <div class="filters" id="fu-filters">
    <div><label for="fu-f-owner">Owner</label><select id="fu-f-owner"></select></div>
    <div><label for="fu-f-prio">Priority</label>
      <select id="fu-f-prio"><option value="">All</option><option value="1-URGENT">1 – Urgent</option><option value="2-HIGH">2 – High</option><option value="3-NORMAL">3 – Normal</option></select></div>
    <div><label for="fu-f-supplier">Supplier</label><select id="fu-f-supplier"></select></div>
    <div><label for="fu-f-month">Trip month</label><select id="fu-f-month"></select></div>
    <div><label for="fu-f-q">Search</label><input type="search" id="fu-f-q" placeholder="Client, folder, ref…"></div>
    <span class="fu-chip" id="fu-f-req" hidden></span>
  </div>

  <div class="card"><div class="table-wrap"><table class="data-table fu-table" id="fu-table"></table></div></div>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
