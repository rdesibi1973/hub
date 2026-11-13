/**
 * booking_gate.js — the "Booking check" panel (booking gate, includes/booking_gate_service.php).
 * Any element <div class="bg-panel" data-rid="N" data-endpoint="…/booking_gate.php" data-csrf="…"> becomes a panel:
 * state badge, blocking items with what to do, warnings, link to booking-check.html, "Bookings done — run check" /
 * "Re-run check", "Force unlock" (Roberto only). While a run is reading documents the panel asks the server to read
 * the next one (nudge), so nobody waits for the cron.
 */
(function () {
  'use strict';
  var STYLE = {
    none: ['#555', '#F0F0F0', '⚪ Not run'], queued: ['#1a3a5c', '#E3EEFB', '⏳ Queued'], running: ['#1a3a5c', '#E3EEFB', '⏳ Running'],
    passed: ['#1A6B3A', '#E6F4EA', '🟢 Passed'], stale: ['#8a6d00', '#FFF4D6', '🟡 Out of date'], blocked: ['#a33', '#F7DEDE', '🔴 Blocked'],
    error: ['#a33', '#F7DEDE', '⚠ Error'], overridden: ['#6B4FA0', '#EEE8F7', '🔓 Unlocked by Roberto']
  };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }
  function dt(s) { if (!s) return ''; var d = new Date(s.replace(' ', 'T')); return isNaN(d) ? s : d.toLocaleString('en-GB', {day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit'}); }

  function Panel(el) {
    this.el = el; this.rid = el.dataset.rid; this.url = el.dataset.endpoint || 'booking_gate.php'; this.csrf = el.dataset.csrf || '';
    this.busy = false; this.nudging = false;
    this.load(false);
  }
  Panel.prototype.post = function (action, extra) {
    var fd = new FormData();
    fd.append('action', action); fd.append('request_id', this.rid); fd.append('csrf', this.csrf);
    Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
    return fetch(this.url, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); });
  };
  Panel.prototype.load = function (fast) {
    var self = this;
    fetch(this.url + '?action=status&request_id=' + encodeURIComponent(this.rid) + (fast ? '&fast=1' : ''), {credentials: 'same-origin'})
      .then(function (r) { return r.json(); })
      .then(function (d) { self.render(d); })
      .catch(function (e) { self.el.innerHTML = '<span class="text-muted">— booking check not available (' + esc(e.message) + ')</span>'; });
  };
  Panel.prototype.render = function (d) {
    var self = this, el = this.el;
    if (!d || d.ok === false) { el.innerHTML = '<span style="color:#a33">⚠ ' + esc(d && d.error || 'error') + '</span>'; return; }
    if (d.group) { el.innerHTML = '<span class="text-muted">— group folder: not checked by the booking gate</span>'; return; }
    var st = STYLE[d.state] || STYLE.none, run = d.run || {};
    var h = '<span class="bg-badge" style="font-size:.72rem;font-weight:700;border-radius:6px;padding:2px 8px;color:' + st[0] + ';background:' + st[1] + '">' + st[2];
    if (d.state === 'running' || d.state === 'queued') h += ' (' + (run.docs_done || 0) + '/' + (run.docs_total || 0) + ')';
    if (d.state === 'blocked') h += ' (' + d.blocking.length + ')';
    if (d.state === 'passed' && d.warnings.length) h += ' · ' + d.warnings.length + ' warning' + (d.warnings.length > 1 ? 's' : '');
    h += '</span>';
    if (d.report_url && ['passed', 'blocked', 'stale', 'overridden'].indexOf(d.state) >= 0) {
      h += ' <a href="' + esc(d.report_url) + '" target="_blank" rel="noopener" style="margin-left:8px;font-size:.8rem;text-decoration:none">📄 booking-check.html</a>';
    }
    if (run.finished_at) h += '<span style="font-size:.75rem;color:var(--grey-mid,#888);margin-left:6px">' + esc(dt(run.finished_at)) + '</span>';
    if (d.can_run && d.state !== 'running' && d.state !== 'queued') {
      h += ' <button type="button" class="btn btn-outline btn-sm bg-run" style="margin-left:10px;padding:2px 9px;font-size:.75rem">'
         + (d.state === 'none' ? '▶ Bookings done — run check' : '↻ Re-run check') + '</button>';
    }
    if (d.can_override && ['passed', 'overridden', 'running', 'queued'].indexOf(d.state) < 0) {
      h += ' <button type="button" class="btn btn-outline btn-sm bg-ovr" style="margin-left:6px;padding:2px 9px;font-size:.75rem;color:#6B4FA0">🔓 Force unlock</button>';
    }
    var line = '';
    if (d.state === 'running' || d.state === 'queued') line = '⏳ reading documents ' + (run.docs_done || 0) + '/' + (run.docs_total || 0) + ' — the result appears here';
    else if (d.state === 'stale') line = '⚠ ' + d.reason;
    else if (d.state === 'error') line = '⚠ ' + d.reason;
    else if (d.state === 'blocked' && d.stale) line = '⚠ Files changed since this check (' + d.stale_reason + '): press Re-run check.';
    else if (d.state === 'none') line = d.tag === 'PROGRESS' ? 'Run it when the bookings are finished: the folder cannot leave PROGRESS before it passes'
                                                               + (d.enforced ? '.' : ' (shadow mode: today it only warns).') : '';
    else if (d.state === 'overridden') line = 'Reason: ' + (run.override_reason || '');
    h += '<div class="bg-line" style="font-size:.75rem;color:' + (d.state === 'stale' || d.state === 'error' || d.state === 'blocked' ? '#a33' : '#1a3a5c') + ';margin-top:3px">' + esc(line) + '</div>';
    if (d.blocking.length) {
      h += '<ul style="margin:4px 0 2px 18px;padding:0;font-size:.8rem;color:#a33">';
      d.blocking.forEach(function (b) { h += '<li><b>' + esc(b.label) + '</b> — <span style="color:#222">' + esc(b.text) + '</span></li>'; });
      h += '</ul>';
    }
    if (d.warnings.length && d.state !== 'none') {
      h += '<details style="font-size:.78rem;margin-top:2px"><summary style="cursor:pointer;color:#8a6d00">' + d.warnings.length + ' warning'
         + (d.warnings.length > 1 ? 's' : '') + ' (do not block)</summary><ul style="margin:2px 0 2px 18px;padding:0">';
      d.warnings.forEach(function (w) { h += '<li>' + esc(w.label) + ' — ' + esc(w.text) + (w.audience !== 'booking' ? ' <i>(' + esc(w.audience) + ')</i>' : '') + '</li>'; });
      h += '</ul></details>';
    }
    h += '<div class="bg-msg" style="font-size:.75rem;color:#a33;margin-top:2px"></div>';
    el.innerHTML = h;
    var run1 = el.querySelector('.bg-run'), ovr = el.querySelector('.bg-ovr');
    if (run1) run1.addEventListener('click', function () { self.run(run1); });
    if (ovr) ovr.addEventListener('click', function () { self.override(); });
    if ((d.state === 'running' || d.state === 'queued') && !this.nudging) this.nudge();
  };
  Panel.prototype.msg = function (t) { var m = this.el.querySelector('.bg-msg'); if (m) m.textContent = t ? '⚠ ' + t : ''; };
  Panel.prototype.run = function (btn) {
    var self = this;
    btn.disabled = true; btn.textContent = '⏳ collecting…';
    this.post('run').then(function (d) {
      if (d.ok === false) { self.msg(d.error); btn.disabled = false; btn.textContent = '↻ Try again'; return; }
      self.render(d);
    }).catch(function (e) { self.msg(e.message); btn.disabled = false; });
  };
  Panel.prototype.nudge = function () {
    var self = this;
    this.nudging = true;
    this.post('nudge').then(function (d) {
      self.nudging = false;
      self.render(d);
      if (d.state !== 'running' && d.state !== 'queued' && d.ok !== false) self.load(true);
    }).catch(function () { self.nudging = false; setTimeout(function () { self.load(true); }, 15000); });
  };
  Panel.prototype.override = function () {
    var reason = window.prompt('Force unlock: the folder may leave PROGRESS without a passed booking check.\nReason (logged in the timeline):', '');
    if (reason === null) return;
    var self = this;
    this.post('override', {reason: reason}).then(function (d) {
      if (d.ok === false) { self.msg(d.error); return; }
      self.render(d);
    }).catch(function (e) { self.msg(e.message); });
  };

  function init() { Array.prototype.forEach.call(document.querySelectorAll('.bg-panel'), function (el) { if (!el._bg) el._bg = new Panel(el); }); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  window.BookingGatePanel = {init: init};
})();
