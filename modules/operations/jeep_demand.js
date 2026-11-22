/* jeep_demand.js — Operations → Jeep demand. Data from api/jeep_demand.php; charts in plain SVG. */
(function () {
  'use strict';
  var API = 'api/jeep_demand.php';
  // Fixed colour per year (the colour follows the year, never its position).
  var YEAR_COLORS = { 2024: '#eda100', 2025: '#2a78d6', 2026: '#eb6834', 2027: '#1baf7a', 2028: '#e87ba4', 2029: '#6250d6' };
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  var STATUS_LABEL = { error: 'Error', no_values: 'No values', no_calc: 'No Calc', old_xls: '.xls Calc', multi_calc: 'Two Calcs',
                       ambiguous_sheet: 'Ambiguous sheets', ok: 'Warning' };
  var D = null, guideYear = null, dayRange = null;

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function color(y) { return YEAR_COLORS[y] || '#888'; }
  function fmt(n, d) { if (n == null) return '—'; d = d == null ? 1 : d; return Number(n).toLocaleString('en-GB', { minimumFractionDigits: 0, maximumFractionDigits: d }); }
  function fdate(iso) { if (!iso) return '—'; var p = iso.split('-'); var dt = new Date(+p[0], +p[1] - 1, +p[2]); return DOW[dt.getDay()] + ' ' + (+p[2]) + ' ' + MONTHS[+p[1] - 1] + ' ' + p[0]; }
  function sdate(iso) { return iso ? (+iso.slice(8, 10)) + ' ' + MONTHS[+iso.slice(5, 7) - 1] : '—'; }
  function addDays(iso, n) { var p = iso.split('-'); var dt = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2] + n)); return dt.toISOString().slice(0, 10); }
  function dayIndex(iso) { var a = D.daily.from.split('-'), b = iso.split('-'); return Math.round((Date.UTC(+b[0], +b[1] - 1, +b[2]) - Date.UTC(+a[0], +a[1] - 1, +a[2])) / 86400000); }
  function prov() { return $('jd-prov').checked ? '1' : '0'; }

  function msg(text, err) { var m = $('jd-msg'); m.textContent = text || ''; m.className = 'jd-msg' + (err ? ' err' : ''); m.style.display = text ? 'block' : 'none'; }

  function get(params) {
    var q = Object.keys(params).map(function (k) { return k + '=' + encodeURIComponent(params[k]); }).join('&');
    return fetch(API + '?' + q, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function post(action, body) {
    return fetch(API + '?action=' + action, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); });
  }

  // ── Tooltip ────────────────────────────────────────────────────────────
  function tipFor(box) {
    var t = box.querySelector('.jd-tip');
    if (!t) { t = document.createElement('div'); t.className = 'jd-tip'; box.appendChild(t); }
    return t;
  }
  function showTip(box, html, evt) {
    var t = tipFor(box), r = box.getBoundingClientRect();
    t.innerHTML = html; t.style.display = 'block';
    var x = evt.clientX - r.left + 14, y = evt.clientY - r.top + 14;
    if (x + t.offsetWidth > r.width) x = evt.clientX - r.left - t.offsetWidth - 14;
    t.style.left = Math.max(0, x) + 'px'; t.style.top = y + 'px';
  }
  function hideTip(box) { var t = box.querySelector('.jd-tip'); if (t) t.style.display = 'none'; }

  // ── SVG helpers ────────────────────────────────────────────────────────
  var NS = 'http://www.w3.org/2000/svg';
  function el(name, attrs, parent) {
    var e = document.createElementNS(NS, name);
    for (var k in attrs) e.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(e);
    return e;
  }
  /** Axis top = 4 equal steps of a round size (1, 1.2, 1.5, 2, 2.5 … × 10ⁿ), close above the data. */
  function niceMax(v) {
    var raw = Math.max(1, v / 4), p = Math.pow(10, Math.floor(Math.log10(raw))), s = [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10];
    for (var i = 0; i < s.length; i++) if (s[i] * p >= raw) return s[i] * p * 4;
    return 40 * p;
  }
  /** Frame: svg with a y axis (gridlines, labels) — returns {svg, x0, x1, y(v), plot}. */
  function frame(box, W, H, maxV) {
    box.innerHTML = '';
    var m = { l: 38, r: 10, t: 10, b: 26 };
    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img' }, box);
    var top = niceMax(maxV || 1), y = function (v) { return m.t + (H - m.t - m.b) * (1 - v / top); };
    var g = el('g', { 'class': 'grid' }, svg), ax = el('g', { 'class': 'axis' }, svg);
    for (var i = 0; i <= 4; i++) {
      var v = top * i / 4;
      el('line', { x1: m.l, x2: W - m.r, y1: y(v), y2: y(v) }, g);
      var t = el('text', { x: m.l - 6, y: y(v) + 3, 'text-anchor': 'end' }, ax); t.textContent = fmt(v, 1);
    }
    el('line', { 'class': 'base', x1: m.l, x2: W - m.r, y1: y(0), y2: y(0) }, svg);
    return { svg: svg, ax: ax, x0: m.l, x1: W - m.r, y: y, H: H, base: y(0) };
  }
  /** Bar with 2px rounded top anchored on the baseline. */
  function bar(parent, x, w, yTop, yBase, fill, opacity) {
    var h = Math.max(0, yBase - yTop); if (h <= 0) return null;
    var r = Math.min(2, w / 2, h);
    var d = 'M' + x + ',' + yBase + 'V' + (yTop + r) + 'Q' + x + ',' + yTop + ' ' + (x + r) + ',' + yTop + 'H' + (x + w - r) + 'Q' + (x + w) + ',' + yTop + ' ' + (x + w) + ',' + (yTop + r) + 'V' + yBase + 'Z';
    return el('path', { d: d, fill: fill, 'fill-opacity': opacity == null ? 1 : opacity }, parent);
  }
  function legend(id, years, kind, extra) {
    $(id).innerHTML = years.map(function (y) { return '<span><i class="' + (kind === 'line' ? 'line' : '') + '" style="background:' + color(y) + '"></i>' + y + '</span>'; }).join('') + (extra || '');
  }

  // ── Summary table ──────────────────────────────────────────────────────
  function renderYears() {
    var h = '<thead><tr><th>Year</th><th>Period</th><th class="num">Average / day</th><th class="num">Typical day (median)</th>'
          + '<th class="num">At least, 1 day in 10 (90th)</th><th class="num">Maximum</th><th class="num">Jeep-days</th><th class="num">Days ≥ 30</th>'
          + '<th class="num">Booked (from today)</th></tr></thead><tbody>';
    D.years.forEach(function (y) {
      var s = y.summary, b = y.booked;
      var per = y.window === 'full' ? 'whole year' : y.window === 'to_yesterday' ? 'up to yesterday' : 'booked so far';
      h += '<tr' + (y.window === 'booked' ? ' class="prov"' : '') + '><td><span class="sw" style="background:' + color(y.year) + '"></span><b>' + y.year + '</b></td>'
         + '<td>' + per + '</td><td class="num">' + fmt(s.avg) + '</td><td class="num">' + fmt(s.median) + '</td><td class="num">' + fmt(s.p90) + '</td>'
         + '<td class="num">' + fmt(s.max) + (s.max_day ? ' <span style="color:var(--grey-mid)">(' + fdate(s.max_day) + ')</span>' : '') + '</td>'
         + '<td class="num">' + fmt(s.jeep_days, 0) + '</td><td class="num">' + s.days_ge30 + '</td>'
         + '<td class="num">' + (b ? fmt(b.jeep_days, 0) + ' jeep-days · avg ' + fmt(b.avg) + ' · max ' + fmt(b.max) : '') + '</td></tr>';
    });
    $('jd-years').innerHTML = h + '</tbody>';
  }

  // ── Monthly grouped bars ───────────────────────────────────────────────
  function renderMonths() {
    var box = $('jd-month'), years = D.years, W = 980, H = 260;
    var max = 0; years.forEach(function (y) { y.months.forEach(function (m) { max = Math.max(max, m.avg); }); });
    var f = frame(box, W, H, max), slot = (f.x1 - f.x0) / 12, n = years.length;
    var bw = Math.max(3, Math.min(18, (slot - 10) / n - 2));
    var hit = el('g', {}, f.svg);
    for (var mi = 0; mi < 12; mi++) {
      var gx = f.x0 + slot * mi + (slot - (bw + 2) * n + 2) / 2;
      years.forEach(function (y, yi) {
        var m = y.months[mi], x = gx + yi * (bw + 2);
        bar(f.svg, x, bw, f.y(m.avg), f.base, color(y.year), m.booked ? 0.45 : 1);
        var r = el('rect', { x: x - 1, y: 10, width: bw + 2, height: f.base - 10, fill: 'transparent' }, hit);
        r.addEventListener('mousemove', function (e) {
          showTip(box, '<b>' + MONTHS[mi] + ' ' + y.year + '</b>' + (m.booked ? ' (booked so far)' : '') + '<br>Average ' + fmt(m.avg) + ' jeeps/day<br>Median ' + fmt(m.median)
            + ' · Max ' + fmt(m.max) + (m.max_day ? ' (' + (+m.max_day.slice(8)) + ' ' + MONTHS[mi] + ')' : '') + '<br>' + fmt(m.jeep_days, 0) + ' jeep-days', e);
        });
        r.addEventListener('mouseleave', function () { hideTip(box); });
      });
      var t = el('text', { x: f.x0 + slot * mi + slot / 2, y: H - 8, 'text-anchor': 'middle' }, f.ax); t.textContent = MONTHS[mi];
    }
    legend('jd-month-legend', years.map(function (y) { return y.year; }), 'bar',
           '<span><i style="background:#888;opacity:.45"></i>booked so far</span>');
  }

  // ── Line chart (weeks, curve) with crosshair ───────────────────────────
  function lineChart(box, series, nX, xLabel, tipFn, ticks) {
    var W = 480, H = 240, max = 0;
    series.forEach(function (s) { s.values.forEach(function (v) { max = Math.max(max, v); }); });
    var f = frame(box, W, H, max), step = (f.x1 - f.x0) / (nX - 1);
    var X = function (i) { return f.x0 + step * i; };
    series.forEach(function (s) {
      var d = s.values.map(function (v, i) { return (i ? 'L' : 'M') + X(i).toFixed(1) + ',' + f.y(v).toFixed(1); }).join('');
      el('path', { d: d, fill: 'none', stroke: s.color, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-dasharray': s.dashed ? '4 3' : '' }, f.svg);
    });
    ticks.forEach(function (i) { var t = el('text', { x: X(i), y: H - 8, 'text-anchor': 'middle' }, f.ax); t.textContent = xLabel(i); });
    var cross = el('line', { y1: 10, y2: f.base, stroke: '#999', 'stroke-width': 1, visibility: 'hidden' }, f.svg);
    var dots = series.map(function (s) { return el('circle', { r: 4, fill: s.color, stroke: '#fff', 'stroke-width': 2, visibility: 'hidden' }, f.svg); });
    var hit = el('rect', { x: f.x0, y: 0, width: f.x1 - f.x0, height: f.base, fill: 'transparent' }, f.svg);
    hit.addEventListener('mousemove', function (e) {
      var pt = f.svg.createSVGPoint(); pt.x = e.clientX; pt.y = e.clientY;
      var p = pt.matrixTransform(f.svg.getScreenCTM().inverse());
      var i = Math.max(0, Math.min(nX - 1, Math.round((p.x - f.x0) / step)));
      cross.setAttribute('x1', X(i)); cross.setAttribute('x2', X(i)); cross.setAttribute('visibility', 'visible');
      series.forEach(function (s, k) { dots[k].setAttribute('cx', X(i)); dots[k].setAttribute('cy', f.y(s.values[i])); dots[k].setAttribute('visibility', 'visible'); });
      showTip(box, tipFn(i), e);
    });
    hit.addEventListener('mouseleave', function () { hideTip(box); cross.setAttribute('visibility', 'hidden'); dots.forEach(function (d) { d.setAttribute('visibility', 'hidden'); }); });
  }

  function renderWeeks() {
    var years = D.years;
    lineChart($('jd-week'), years.map(function (y) { return { color: color(y.year), dashed: y.window === 'booked', values: y.weeks.map(function (w) { return w.avg; }) }; }),
      52, function (i) { return 'W' + (i + 1); },
      function (i) {
        return '<b>Week ' + (i + 1) + '</b>' + years.map(function (y) {
          var w = y.weeks[i];
          return '<br><span style="color:' + color(y.year) + '">■</span> ' + y.year + ': avg ' + fmt(w.avg) + ' · peak ' + fmt(w.max) + (w.booked ? ' (booked)' : '');
        }).join('');
      }, [0, 8, 17, 25, 34, 42, 51]);
    legend('jd-week-legend', years.map(function (y) { return y.year; }), 'line');
  }

  function renderCurve() {
    var years = D.years;
    lineChart($('jd-curve'), years.map(function (y) { return { color: color(y.year), dashed: y.window === 'booked', values: y.curve }; }),
      45, function (i) { return String(i + 1); },
      function (i) {
        return '<b>At least ' + (i + 1) + ' jeeps</b>' + years.map(function (y) {
          return '<br><span style="color:' + color(y.year) + '">■</span> ' + y.year + ': ' + y.curve[i] + ' days';
        }).join('');
      }, [0, 9, 19, 29, 39, 44]);
    legend('jd-curve-legend', years.map(function (y) { return y.year; }), 'line');
  }

  // ── Daily bars + drill-down ────────────────────────────────────────────
  function initDaily() {
    var sel = $('jd-d-year'), cur = D.today.slice(0, 4);
    sel.innerHTML = D.years.map(function (y) { return '<option' + (String(y.year) === cur ? ' selected' : '') + '>' + y.year + '</option>'; }).join('');
    if (!dayRange) setYearRange(sel.value);
    else { $('jd-d-from').value = dayRange[0]; $('jd-d-to').value = dayRange[1]; }
  }
  function setYearRange(y) { dayRange = [y + '-01-01', y + '-12-31']; $('jd-d-from').value = dayRange[0]; $('jd-d-to').value = dayRange[1]; }

  function renderDaily() {
    var box = $('jd-daily'), from = $('jd-d-from').value, to = $('jd-d-to').value;
    if (!from || !to || to < from) { box.innerHTML = '<div class="jd-empty">Choose a valid period.</div>'; return; }
    var i0 = Math.max(0, dayIndex(from)), i1 = Math.min(D.daily.j.length - 1, dayIndex(to));
    if (i1 < i0) { box.innerHTML = '<div class="jd-empty">No data in this period.</div>'; return; }
    var n = i1 - i0 + 1, W = 980, H = 280, max = 0;
    for (var i = i0; i <= i1; i++) max = Math.max(max, D.daily.j[i]);
    var f = frame(box, W, H, max), slot = (f.x1 - f.x0) / n, gap = slot > 4 ? Math.min(2, slot * 0.2) : 0, bw = Math.max(0.5, slot - gap);
    var yr = +from.slice(0, 4), c = color(yr), hit = el('g', {}, f.svg);
    for (var k = 0; k < n; k++) (function (k) {
      var idx = i0 + k, iso = addDays(D.daily.from, idx), j = D.daily.j[idx], p = D.daily.p[idx], x = f.x0 + slot * k;
      var future = iso >= D.today;
      if (j - p > 0) bar(f.svg, x, bw, f.y(j - p), f.base, c, future ? 0.6 : 1);
      if (p > 0) bar(f.svg, x, bw, f.y(j), f.y(j - p) - (j - p > 0 && gap ? 1 : 0), c, 0.3);
      var r = el('rect', { x: x, y: 10, width: Math.max(slot, 1), height: f.base - 10, fill: 'transparent', style: 'cursor:pointer' }, hit);
      r.addEventListener('mousemove', function (e) {
        showTip(box, '<b>' + fdate(iso) + '</b><br>' + fmt(j, 2) + ' jeeps · ' + D.daily.n[idx] + ' safaris' + (p > 0 ? '<br>of which provisional ' + fmt(p, 2) : '') + (future ? '<br>(booked)' : '') + '<br><i>click for the safaris</i>', e);
      });
      r.addEventListener('mouseleave', function () { hideTip(box); });
      r.addEventListener('click', function () { loadDay(iso); });
      var dt = iso.slice(8);
      if ((n <= 62 && (k === 0 || dt === '01' || dt === '15' || n <= 31)) || (n > 62 && dt === '01')) {
        var t = el('text', { x: x + slot / 2, y: H - 8, 'text-anchor': 'middle' }, f.ax);
        t.textContent = n > 62 ? MONTHS[+iso.slice(5, 7) - 1] : (+dt) + (dt === '01' || k === 0 ? ' ' + MONTHS[+iso.slice(5, 7) - 1] : '');
      }
    })(k);
    $('jd-daily-legend').innerHTML = '<span><i style="background:' + c + '"></i>confirmed</span>'
      + (prov() === '1' ? '<span><i style="background:' + c + ';opacity:.3"></i>provisional</span>' : '')
      + '<span><i style="background:' + c + ';opacity:.6"></i>future days (booked)</span>';
  }

  function loadDay(iso) {
    $('jd-day-card').hidden = false;
    $('jd-day-title').textContent = 'Safaris on the road — ' + fdate(iso);
    $('jd-day').innerHTML = '<tr><td class="jd-empty">Loading…</td></tr>';
    get({ action: 'day', date: iso, prov: prov() }).then(function (r) {
      if (!r.ok) { $('jd-day').innerHTML = '<tr><td>' + esc(r.error) + '</td></tr>'; return; }
      if (!r.rows.length) { $('jd-day').innerHTML = '<tr><td class="jd-empty">No safari with jeeps on this day.</td></tr>'; return; }
      var tot = 0, h = '<thead><tr><th>Booking</th><th class="num">Jeeps that day</th><th class="num">Jeeps (Calc)</th><th class="num">Pax</th><th>Guide</th><th>Dates</th><th>Status</th></tr></thead><tbody>';
      r.rows.forEach(function (x) {
        tot += x.jeeps;
        h += '<tr' + (x.provisional ? ' class="prov"' : '') + '><td class="jd-folder"><a href="' + esc(x.url) + '" target="_blank" rel="noopener">' + esc(x.folder) + '</a>'
           + (x.request_id ? ' · <a href="../leads/request_view.php?id=' + x.request_id + '">request</a>' : '') + (x.kind === 'GRP' ? ' <span class="jd-badge">GRP</span>' : '') + '</td>'
           + '<td class="num"><b>' + fmt(x.jeeps, 2) + '</b></td><td class="num">' + fmt(x.jeeps_header) + '</td><td class="num">' + (x.pax == null ? '—' : x.pax) + '</td>'
           + '<td>' + esc(x.guide || '—') + '</td><td style="white-space:nowrap">' + sdate(x.start_day) + ' → ' + sdate(x.end_day) + '</td>'
           + '<td>' + (x.provisional ? '<span class="jd-badge prov">' + esc(x.stage) + '</span>' : esc(x.stage || '')) + '</td></tr>';
      });
      h += '</tbody><tfoot><tr><td><b>' + r.rows.length + ' safaris</b></td><td class="num"><b>' + fmt(tot, 2) + '</b></td><td colspan="5"></td></tr></tfoot>';
      $('jd-day').innerHTML = h;
      $('jd-day-card').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
  }

  // ── Guides, quality ────────────────────────────────────────────────────
  function renderGuides() {
    var sel = $('jd-g-year');
    sel.innerHTML = D.years.map(function (y) { return '<option' + (y.year === D.guides.year ? ' selected' : '') + '>' + y.year + '</option>'; }).join('');
    var rows = D.guides.rows;
    if (!rows.length) { $('jd-guides').innerHTML = '<tr><td class="jd-empty">No data for ' + D.guides.year + '.</td></tr>'; return; }
    var h = '<thead><tr><th>Guide</th><th class="num">Safaris</th><th class="num">Jeep-days</th><th>Written in the Calcs as</th></tr></thead><tbody>';
    rows.forEach(function (g) {
      h += '<tr><td><b>' + esc(g.guide || '(no guide in the Calc)') + '</b></td><td class="num">' + g.safaris + '</td><td class="num">' + fmt(g.jeep_days) + '</td>'
         + '<td style="font-size:.74rem;color:var(--grey-mid)">' + esc(g.variants.slice(0, 6).join(' · ')) + (g.variants.length > 6 ? ' …' : '') + '</td></tr>';
    });
    $('jd-guides').innerHTML = h + '</tbody>';
  }

  function renderQuality() {
    var q = D.quality, sel = $('jd-q-status'), keep = sel.value;
    $('jd-q-n').textContent = q.length ? '(' + q.length + ')' : '';
    var counts = {};
    q.forEach(function (r) { counts[r.status] = (counts[r.status] || 0) + 1; });
    sel.innerHTML = '<option value="">All (' + q.length + ')</option>' + Object.keys(counts).map(function (s) {
      return '<option value="' + s + '"' + (s === keep ? ' selected' : '') + '>' + esc(STATUS_LABEL[s] || s) + ' (' + counts[s] + ')</option>';
    }).join('');
    var rows = q.filter(function (r) { return !sel.value || r.status === sel.value; });
    if (!rows.length) { $('jd-quality').innerHTML = '<tr><td class="jd-empty">Nothing to fix.</td></tr>'; return; }
    var h = '<thead><tr><th>Status</th><th>Booking</th><th>Problem</th><th>Calc / sheet</th></tr></thead><tbody>';
    rows.forEach(function (r) {
      h += '<tr><td><span class="jd-badge ' + esc(r.status) + '">' + esc(STATUS_LABEL[r.status] || r.status) + '</span></td>'
         + '<td class="jd-folder"><a href="' + esc(r.url) + '" target="_blank" rel="noopener">' + esc(r.folder_name) + '</a>'
         + (r.request_id ? ' · <a href="../leads/request_view.php?id=' + r.request_id + '">request</a>' : '') + '</td>'
         + '<td style="font-size:.76rem">' + esc(r.message || '') + '</td><td style="font-size:.74rem;color:var(--grey-mid)">' + esc([r.calc_file, r.sheet].filter(Boolean).join(' · ')) + '</td></tr>';
    });
    $('jd-quality').innerHTML = h + '</tbody>';
  }

  function renderState() {
    var s = D.scan;
    var t = s.folders + ' bookings read' + (s.last_scanned_at ? ' · last Calc read ' + s.last_scanned_at.slice(0, 16) : '')
          + (s.queued ? ' · ' + s.queued + ' queued' : '') + (s.last_run ? ' · last run: ' + s.last_run : '');
    $('jd-state').textContent = s.folders ? t : 'No data yet: an admin should start "Rebuild all" (the full read takes a few minutes).';
    $('jd-rebuild').hidden = !D.is_admin;
  }

  function renderAll() {
    renderState();
    if (!D.years.length) return;
    renderYears(); renderMonths(); renderWeeks(); renderCurve();
    initDaily(); renderDaily(); renderGuides(); renderQuality();
  }

  function load() {
    return get({ action: 'data', prov: prov(), guide_year: guideYear || '' }).then(function (r) {
      if (!r.ok) { msg(r.error || 'Error', true); return; }
      D = r; renderAll();
    }).catch(function (e) { msg('Network error: ' + e.message, true); });
  }

  // ── Scan buttons ───────────────────────────────────────────────────────
  function runScan(mode, btn, total) {
    btn.disabled = true;
    post('scan', { mode: mode }).then(function (r) {
      if (!r.ok) { msg(r.error || 'Error', true); btn.disabled = false; return; }
      var more = (mode === 'full' || mode === 'continue') && r.remaining > 0 && (r.read > 0 || r.unchanged > 0);
      msg((more ? 'Rebuilding… ' : 'Done: ') + r.summary + (more ? ' — it goes on by itself, keep the page open.' : ''));
      if (more) return runScan('continue', btn, total);
      btn.disabled = false;
      return load();
    }).catch(function (e) { msg('Network error: ' + e.message + ' — try again (it resumes where it stopped).', true); btn.disabled = false; });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.jd-tabs button').forEach(function (b) {
      b.addEventListener('click', function () {
        document.querySelectorAll('.jd-tabs button').forEach(function (x) { x.classList.toggle('active', x === b); });
        document.querySelectorAll('.jd-panel').forEach(function (p) { p.classList.toggle('active', p.id === 'tab-' + b.dataset.tab); });
        if (b.dataset.tab === 'daily' && D) renderDaily();
      });
    });
    $('jd-prov').addEventListener('change', load);
    $('jd-d-year').addEventListener('change', function () { setYearRange(this.value); renderDaily(); });
    ['jd-d-from', 'jd-d-to'].forEach(function (id) { $(id).addEventListener('change', function () { dayRange = [$('jd-d-from').value, $('jd-d-to').value]; renderDaily(); }); });
    $('jd-g-year').addEventListener('change', function () { guideYear = this.value; load(); });
    $('jd-q-status').addEventListener('change', renderQuality);
    $('jd-refresh').addEventListener('click', function () { msg('Reading the Calcs that changed…'); runScan('incremental', this); });
    $('jd-rebuild').addEventListener('click', function () {
      if (!confirm('Re-read every Calc of every archive (2024 →)? It takes a few minutes; the page works in batches.')) return;
      msg('Rebuild started…'); runScan('full', this);
    });
    $('jd-csv-daily').addEventListener('click', function (e) { e.preventDefault(); location.href = API + '?action=csv&what=daily&prov=' + prov(); });
    $('jd-csv-folders').addEventListener('click', function (e) { e.preventDefault(); location.href = API + '?action=csv&what=folders&prov=' + prov(); });
    load();
  });
})();
