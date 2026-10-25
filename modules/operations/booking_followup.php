<?php
/**
 * booking_followup.php — Operations → Booking follow-up. Supplier bookings not confirmed and
 * domestic tickets not saved, filled by the weekly / daily check (followup_cron.php) or
 * "Run check now"; the booking team takes lines in charge here.
 * Also the CK findings of the booking check (type ck, audience booking / sales / accounts), the SafariCheck booking
 * points (type safaricheck) and lines added by hand (type manual): one list — a line found by several checks shows
 * each of them ("found by"), its priority comes from its deadline (due_date).
 * Every logged-in Hub user can view; edits need module booking_followup_edit, or being the line's owner
 * (owners: any active user; reassign / not relevant: admin or the current owner).
 * Data / changes: api/followup.php → includes/followup_service.php.
 * ?request_id=N, ?q=…, ?type=ck open the page filtered (links from the request view / CK tracker).
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/followup_service.php';
hub_db_timezone($pdo);

$fuUser = current_user();
$fuData = followup_page_data($pdo, $fuUser);
$fuReader = followup_pdf_reader() !== null;   // BlueHost today: none → supplier PDFs are read by the office PC scan
$page_title = 'Booking follow-up — Savannah Explorers Hub';

$extra_css = '
.fu-head{display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.fu-head .page-title{margin-bottom:4px}
.fu-sub{font-size:.78rem;color:var(--grey-mid)}
.fu-run{margin-left:auto;display:flex;gap:8px;align-items:center}
.fu-run select{padding:7px 12px;border:1.5px solid var(--grey-lt);border-radius:20px;font-family:inherit;font-size:.8rem;background:var(--white)}
.fu-msg{display:none;margin:0 0 16px;padding:12px 16px;border-radius:8px;font-size:.82rem;line-height:1.5;background:var(--navy-lt);color:#123a5c}
.fu-msg.err{background:var(--red-lt);color:var(--red-dk)}
.fu-stats{display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:12px;margin-bottom:18px}
.badge-aud{background:var(--off-white);color:var(--grey-dk);text-transform:none;letter-spacing:0;font-size:.66rem;margin-top:3px;display:inline-block}
.badge-aud.sales{background:#EEF3F9;color:#1a3a5c} .badge-aud.accounts{background:#F3ECF8;color:#5B2C83}
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
/* The list scrolls in its own frame: the horizontal scrollbar stays on screen, the header stays on top. */
.table-wrap{overflow:auto;max-height:calc(100vh - 150px)}
table.fu-table{font-size:.78rem}
table.fu-table td,table.fu-table th{padding:8px 8px;vertical-align:top}
table.fu-table thead th{position:sticky;top:0;z-index:2}
.fu-ref{display:block;font-size:.7rem;color:var(--grey-dk);font-weight:600}
table.fu-table tr.problem td{background:#FDF1F0}
table.fu-table tr.problem td:first-child{box-shadow:inset 3px 0 0 var(--red)}
table.fu-table tr.hist td{background:var(--off-white);font-size:.74rem}
.fu-client{font-weight:700;color:var(--black)}
.fu-client{display:block;max-width:200px;overflow-wrap:anywhere}
.fu-folder{display:block;font-size:.7rem;color:var(--grey-mid);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.fu-folder.fu-file{max-width:150px}
.fu-folder a{color:inherit;text-decoration:none}
.fu-folder a:hover{color:var(--red)}
.fu-days{display:block;font-size:.7rem;color:var(--grey-mid)}
.fu-late{color:var(--red-dk);font-weight:700}
.badge-p1{background:var(--red-lt);color:var(--red-dk)} .badge-p2{background:var(--amber-lt);color:#7A4F01} .badge-p3{background:var(--grey-lt);color:var(--grey-dk)}
.badge-issue{background:var(--navy-lt);color:var(--navy);text-transform:none;letter-spacing:0;font-size:.7rem}
.badge-issue.exp{background:var(--red-lt);color:var(--red-dk)}
.fu-table select,.fu-table input[type=date],.fu-table textarea{font-family:inherit;font-size:.75rem;border:1.5px solid var(--grey-lt);border-radius:6px;padding:4px 6px;background:var(--white);color:var(--black)}
.fu-table textarea{width:190px;min-height:34px;resize:vertical}
.fu-notes{list-style:none;margin:0 0 4px;padding:0;width:190px;max-height:120px;overflow-y:auto;font-size:.72rem;line-height:1.35}
.fu-notes li{padding:2px 0;border-bottom:1px dashed var(--grey-lt);color:var(--black);white-space:pre-wrap;overflow-wrap:anywhere}
.fu-notes li:last-child{border-bottom:0}
.fu-notes .w{display:block;color:var(--grey-mid);font-size:.68rem;white-space:nowrap}
.fu-save{margin-top:4px;display:flex;align-items:center;gap:8px}
.fu-save .btn:disabled{opacity:.35;cursor:default}
table.fu-table tr.dirty td{background:#FFF8E1}
.fu-table select:focus,.fu-table input:focus,.fu-table textarea:focus{outline:none;border-color:var(--red)}
.fu-saved{font-size:.68rem;color:var(--green)} .fu-err{font-size:.68rem;color:var(--red-dk);display:block}
.fu-hbtn{border:none;background:none;color:var(--grey-mid);cursor:pointer;font-size:.72rem;padding:0}
.fu-hbtn:hover{color:var(--red)}
.fu-empty{padding:28px;text-align:center;color:var(--grey-mid);font-size:.85rem}
.fu-ev{list-style:none;margin:0;padding:0}
.fu-ev li{padding:2px 0;color:var(--grey-dk)}
.fu-det{margin:0 0 8px;padding:0 0 8px;border-bottom:1px solid var(--grey-lt);list-style:none}
.fu-det li{padding:1px 0;color:var(--grey-dk)} .fu-det b{color:var(--black);font-weight:600;margin-right:6px}
.fu-ev .w{color:var(--grey-mid);margin-right:8px;font-variant-numeric:tabular-nums}
.fu-det .w{color:var(--grey-mid);font-size:.68rem}
.fu-due{display:block;font-size:.7rem;color:var(--grey-dk);white-space:nowrap;margin-top:3px}
.fu-due.fu-late{color:var(--red-dk);font-weight:700}
.fu-srcs{display:flex;flex-wrap:wrap;gap:3px;margin-top:4px}
.fu-src{font-size:.64rem;background:var(--off-white);color:var(--grey-dk);border-radius:8px;padding:1px 6px;white-space:nowrap;cursor:help}
.fu-add{background:var(--white);border-radius:10px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:14px 16px;margin-bottom:16px;display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;align-items:end}
.fu-add[hidden]{display:none}
.fu-add label{display:block;font-size:.68rem;font-weight:700;color:var(--grey-dk);margin-bottom:3px}
.fu-add input,.fu-add select,.fu-add textarea{width:100%;box-sizing:border-box;font-family:inherit;font-size:.78rem;border:1.5px solid var(--grey-lt);border-radius:6px;padding:5px 7px}
.fu-add .wide{grid-column:span 3}
.fu-add .acts{grid-column:span 6;display:flex;gap:8px;align-items:center}
@media(max-width:900px){.fu-add{grid-template-columns:1fr 1fr}.fu-add .wide,.fu-add .acts{grid-column:span 2}}
@media(max-width:1200px){.fu-stats{grid-template-columns:repeat(4,minmax(0,1fr))}}
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
        <option value="group">Groups (arrival ≤ 60 days)</option>
        <?php if ($fuReader): ?>
        <option value="supplier">Supplier documents</option>
        <?php else: ?>
        <option value="supplier" disabled>Supplier documents — office PC scan (followup_local_scan.bat)</option>
        <?php endif; ?>
        <option value="all">All</option>
      </select>
      <button type="button" class="btn btn-primary btn-sm" id="fu-run-btn">▶ Run check now</button>
      <button type="button" class="btn btn-outline btn-sm" id="fu-add-btn" title="A problem found outside the checks (by phone, by mail…)">＋ Add line</button>
    </div>
    <?php endif; ?>
  </div>
  <div class="fu-msg" id="fu-msg"></div>
  <?php if ($fuData['me']['can_edit']): ?>
  <div class="fu-add" id="fu-add" hidden>
    <div><label for="fu-add-req">Request #</label><input type="number" id="fu-add-req" min="1" placeholder="2073"></div>
    <div><label for="fu-add-aud">For</label>
      <select id="fu-add-aud"><option value="booking">Booking</option><option value="sales">Sales</option><option value="accounts">Accounts</option></select></div>
    <div class="wide"><label for="fu-add-text">What is wrong / what to do</label><input type="text" id="fu-add-text" maxlength="500" placeholder="Lodge called: 2 rooms instead of 3, rebook"></div>
    <div><label for="fu-add-owner">Owner</label><select id="fu-add-owner"></select></div>
    <div><label for="fu-add-svc">Supplier / service</label><input type="text" id="fu-add-svc" maxlength="255" placeholder="Serengeti Classic View"></div>
    <div><label for="fu-add-sdate">Service date</label><input type="date" id="fu-add-sdate"></div>
    <div><label for="fu-add-due">Deadline</label><input type="date" id="fu-add-due" title="Empty: 14 days before the service"></div>
    <div class="acts"><button type="button" class="btn btn-primary btn-sm" id="fu-add-save">Add</button>
      <button type="button" class="btn btn-outline btn-sm" id="fu-add-cancel">Cancel</button><span id="fu-add-msg"></span></div>
  </div>
  <?php endif; ?>

  <div class="fu-stats" id="fu-stats"></div>

  <div class="fu-tabs" id="fu-tabs" role="tablist"></div>

  <div class="filters" id="fu-filters">
    <div><label for="fu-f-owner">Owner</label><select id="fu-f-owner"></select></div>
    <div><label for="fu-f-aud">For</label>
      <select id="fu-f-aud"><option value="">All</option><option value="booking">Booking</option><option value="sales">Sales</option><option value="accounts">Accounts</option></select></div>
    <div><label for="fu-f-prio">Priority</label>
      <select id="fu-f-prio"><option value="">All</option><option value="1-URGENT">1 – Urgent</option><option value="2-HIGH">2 – High</option><option value="3-NORMAL">3 – Normal</option></select></div>
    <div><label for="fu-f-supplier">Supplier</label><select id="fu-f-supplier"></select></div>
    <div><label for="fu-f-src">Found by</label><select id="fu-f-src"></select></div>
    <div><label for="fu-f-month">Trip month</label><select id="fu-f-month"></select></div>
    <div><label for="fu-f-q">Search</label><input type="search" id="fu-f-q" placeholder="Client, folder, ref…"></div>
    <span class="fu-chip" id="fu-f-req" hidden></span>
  </div>

  <div class="card"><div class="table-wrap"><table class="data-table fu-table" id="fu-table"></table></div></div>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
