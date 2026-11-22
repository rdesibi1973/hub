<?php
/**
 * jeep_demand.php — Operations → Jeep demand (phase 1, 22 Nov 2026): how many jeeps the confirmed safaris need
 * per day / week / month, read from their Calc (JEEP column / Cost per Jeep). Read-only for the Operations module;
 * "Refresh now" re-reads the Calcs that changed, "Rebuild all" (admin) reads every archive again.
 * Data: api/jeep_demand.php → includes/jeep_service.php (shared with the Agent API jeep_demand / jeep_demand_day and
 * the cron step in leads/ck_cron.php). Charts: plain SVG (jeep_demand.js).
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_permission('operations');
$page_title = 'Jeep demand — Savannah Explorers Hub';

$extra_css = '
.jd-main{padding:24px 40px 48px;max-width:1400px;margin:0 auto}
.jd-head{display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:14px}
.jd-head h2{font-family:Merriweather,serif;font-size:1.35rem;color:var(--black)}
.jd-sub{font-size:.78rem;color:var(--grey-mid);margin-top:4px;line-height:1.5}
.jd-actions{margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.jd-actions label{font-size:.78rem;color:var(--grey-dk);display:flex;gap:6px;align-items:center;cursor:pointer}
.jd-msg{display:none;margin:0 0 14px;padding:10px 14px;border-radius:8px;font-size:.8rem;line-height:1.5;background:var(--navy-lt);color:#123a5c}
.jd-msg.err{background:var(--red-lt);color:var(--red-dk)}
.jd-tabs{display:flex;gap:4px;border-bottom:1px solid var(--grey-lt);margin-bottom:16px;overflow-x:auto}
.jd-tabs button{font-family:inherit;font-size:.78rem;font-weight:700;color:var(--grey-mid);background:none;border:none;border-bottom:3px solid transparent;padding:9px 14px;cursor:pointer;white-space:nowrap}
.jd-tabs button.active{color:var(--red);border-bottom-color:var(--red)}
.jd-tabs .n{font-weight:600;margin-left:4px}
.jd-panel{display:none}.jd-panel.active{display:block}
.jd-card{background:var(--white);border-radius:10px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:16px 18px;margin-bottom:16px}
.jd-card h3{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-dk);margin-bottom:4px}
.jd-card .hint{font-size:.74rem;color:var(--grey-mid);margin-bottom:10px;line-height:1.45}
.jd-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.jd-grid .jd-card{margin-bottom:0}
.jd-legend{display:flex;gap:14px;flex-wrap:wrap;font-size:.74rem;color:var(--grey-dk);margin-bottom:6px}
.jd-legend span{display:inline-flex;align-items:center;gap:6px}
.jd-legend i{display:inline-block;width:12px;height:12px;border-radius:3px}
.jd-legend i.line{height:3px;border-radius:2px;width:16px}
.jd-chart{position:relative;width:100%}
.jd-chart svg{display:block;width:100%;height:auto;overflow:visible}
.jd-chart .axis text{font-size:10px;fill:#666;font-family:"Open Sans",sans-serif}
.jd-chart .grid line{stroke:#ececec}
.jd-chart .base{stroke:#bbb}
.jd-tip{position:absolute;pointer-events:none;background:#1A1A1A;color:#fff;font-size:.72rem;line-height:1.45;padding:7px 10px;border-radius:6px;white-space:nowrap;z-index:5;display:none;box-shadow:0 2px 8px rgba(0,0,0,.25)}
.jd-tip b{font-weight:700}
table.jd-table{width:100%;border-collapse:collapse;font-size:.8rem}
table.jd-table th{text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--grey-mid);border-bottom:2px solid var(--grey-lt);padding:7px 8px;white-space:nowrap}
table.jd-table td{border-bottom:1px solid #f0f0f0;padding:7px 8px;vertical-align:top}
table.jd-table td.num,table.jd-table th.num{text-align:right;font-variant-numeric:tabular-nums}
table.jd-table tr.prov td{color:var(--grey-mid)}
table.jd-table .sw{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:6px;vertical-align:-1px}
.jd-filters{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px}
.jd-filters label{display:block;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--grey-mid);margin-bottom:3px}
.jd-filters select,.jd-filters input{padding:6px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:inherit;font-size:.8rem;background:var(--white)}
.jd-badge{display:inline-block;font-size:.64rem;font-weight:700;padding:2px 7px;border-radius:10px;background:var(--off-white);color:var(--grey-dk);text-transform:uppercase;letter-spacing:.04em}
.jd-badge.error,.jd-badge.no_values{background:var(--red-lt);color:var(--red-dk)}
.jd-badge.no_calc,.jd-badge.old_xls{background:var(--amber-lt);color:#8a4410}
.jd-badge.ambiguous_sheet,.jd-badge.multi_calc{background:var(--navy-lt);color:var(--navy)}
.jd-badge.prov{background:var(--amber-lt);color:#8a4410}
.jd-note{font-size:.76rem;color:var(--grey-dk);line-height:1.55;background:var(--off-white);border-left:3px solid var(--grey-lt);padding:10px 14px;border-radius:4px}
.jd-empty{font-size:.8rem;color:var(--grey-mid);padding:14px 0}
.jd-folder{font-size:.76rem;word-break:break-all;min-width:300px}
.jd-scroll{overflow-x:auto}
.jd-presets{display:flex;gap:6px;flex-wrap:wrap;margin-left:auto}
table.jd-table tr.sel td{background:#FBF8F2;font-weight:600}
.jd-p-grid{display:grid;grid-template-columns:3fr 2fr;gap:16px;align-items:start;margin-top:6px}
@media(max-width:900px){.jd-p-grid{grid-template-columns:1fr}.jd-presets{margin-left:0}}
@media(max-width:900px){.jd-grid{grid-template-columns:1fr}.jd-main{padding:16px}}
';
include __DIR__ . '/../../includes/layout_header.php';
?>
<main class="jd-main">
  <div class="jd-head">
    <div>
      <h2>Jeep demand</h2>
      <div class="jd-sub">Jeeps the confirmed safaris need each day, read from each booking's Calc
        (JEEP column ÷ Cost per Jeep: doubled day = 2, half arrival day = 0.5, beach day = 0).<br>
        <span id="jd-state">Loading…</span></div>
    </div>
    <div class="jd-actions">
      <label><input type="checkbox" id="jd-prov" checked> Include provisional (_PROVISIONAL / _PROGRESS)</label>
      <button type="button" class="btn btn-outline btn-sm" id="jd-refresh" title="Re-reads the Calcs that changed (the /001_Safari folders at once)">↻ Refresh now</button>
      <button type="button" class="btn btn-outline btn-sm" id="jd-rebuild" hidden title="Re-reads every Calc of every archive, in batches">Rebuild all</button>
    </div>
  </div>
  <div class="jd-msg" id="jd-msg"></div>

  <div class="jd-tabs" role="tablist">
    <button type="button" class="active" data-tab="summary">Summary</button>
    <button type="button" data-tab="daily">Daily</button>
    <button type="button" data-tab="guides">Guides</button>
    <button type="button" data-tab="quality">Data quality <span class="n" id="jd-q-n"></span></button>
  </div>

  <!-- Summary -->
  <section class="jd-panel active" id="tab-summary">
    <div class="jd-card">
      <h3>Custom period</h3>
      <div class="hint">Pick any period: the same figures, on those dates only, next to the same dates of the other years.</div>
      <div class="jd-filters">
        <div><label for="jd-p-from">From</label><input type="date" id="jd-p-from"></div>
        <div><label for="jd-p-to">To</label><input type="date" id="jd-p-to"></div>
        <div><button type="button" class="btn btn-primary btn-sm" id="jd-p-go">Calculate</button></div>
        <div class="jd-presets" id="jd-p-presets">
          <button type="button" class="btn btn-outline btn-sm" data-p="last30">Last 30 days</button>
          <button type="button" class="btn btn-outline btn-sm" data-p="next90">Next 90 days</button>
          <button type="button" class="btn btn-outline btn-sm" data-p="high">High season Jul–Oct</button>
          <button type="button" class="btn btn-outline btn-sm" data-p="xmas">Christmas 20 Dec – 6 Jan</button>
        </div>
      </div>
      <div id="jd-p-out"></div>
    </div>
    <div class="jd-card">
      <h3>Summary by year</h3>
      <div class="hint">Current year: days up to yesterday only; what is booked from today to 31 Dec is shown apart. Future years: booked so far.</div>
      <div class="jd-scroll"><table class="jd-table" id="jd-years"></table></div>
    </div>
    <div class="jd-card">
      <h3>Average jeeps per day, by month</h3>
      <div class="hint">Future months = booked so far (lighter colour). Hover a bar for median and maximum.</div>
      <div class="jd-legend" id="jd-month-legend"></div>
      <div class="jd-chart" id="jd-month"></div>
    </div>
    <div class="jd-grid">
      <div class="jd-card">
        <h3>By week</h3>
        <div class="hint">Average jeeps per day, weeks 1–52 (week 1 = 1–7 January).</div>
        <div class="jd-legend" id="jd-week-legend"></div>
        <div class="jd-chart" id="jd-week"></div>
      </div>
      <div class="jd-card">
        <h3>Days with at least N jeeps</h3>
        <div class="hint">With N jeeps in the fleet, on how many days we would have had to hire more (current year: up to yesterday).</div>
        <div class="jd-legend" id="jd-curve-legend"></div>
        <div class="jd-chart" id="jd-curve"></div>
      </div>
    </div>
    <div class="jd-card" style="margin-top:16px">
      <h3>Not included</h3>
      <div class="jd-note">Real demand is a little higher: airport / other transfers not written in the Calc, work for other operators without a Calc
        (UniqueFootprint, Verticalife…), old .xls Calcs (see Data quality) and cancelled bookings are not counted. Kenya / Namibia bookings
        have no JEEP values and count 0 (not our jeeps). A group counts once (the group folder).</div>
      <div style="margin-top:10px;font-size:.78rem">Export CSV: <a href="#" id="jd-csv-daily">daily</a> · <a href="#" id="jd-csv-folders">per booking</a></div>
    </div>
  </section>

  <!-- Daily -->
  <section class="jd-panel" id="tab-daily">
    <div class="jd-card">
      <div class="jd-filters">
        <div><label for="jd-d-year">Year</label><select id="jd-d-year"></select></div>
        <div><label for="jd-d-from">From</label><input type="date" id="jd-d-from"></div>
        <div><label for="jd-d-to">To</label><input type="date" id="jd-d-to"></div>
      </div>
      <h3>Jeeps per day</h3>
      <div class="hint">Click a day to see the safaris on the road that day.</div>
      <div class="jd-legend" id="jd-daily-legend"></div>
      <div class="jd-chart" id="jd-daily"></div>
    </div>
    <div class="jd-card" id="jd-day-card" hidden>
      <h3 id="jd-day-title"></h3>
      <div class="jd-scroll"><table class="jd-table" id="jd-day"></table></div>
    </div>
  </section>

  <!-- Guides -->
  <section class="jd-panel" id="tab-guides">
    <div class="jd-card">
      <div class="jd-filters"><div><label for="jd-g-year">Year</label><select id="jd-g-year"></select></div></div>
      <h3>Guides</h3>
      <div class="hint">Name as written in the Calc (only case and trailing punctuation merged: "EVAREST." = "Evarest"); mapping to staff comes in phase 2.</div>
      <div class="jd-scroll"><table class="jd-table" id="jd-guides"></table></div>
    </div>
  </section>

  <!-- Data quality -->
  <section class="jd-panel" id="tab-quality">
    <div class="jd-card">
      <div class="jd-filters"><div><label for="jd-q-status">Status</label><select id="jd-q-status"></select></div></div>
      <h3>Data quality</h3>
      <div class="hint">Bookings whose Calc cannot be read, or is read with doubts: fix the Calc in Dropbox and the next read updates them by itself.
        "Ambiguous sheets" and "Two Calcs" are still counted (the sheet with most days / the latest Calc).</div>
      <div class="jd-scroll"><table class="jd-table" id="jd-quality"></table></div>
    </div>
  </section>
</main>
<script src="jeep_demand.js?v=3"></script>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
