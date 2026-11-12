/* booking_followup.js — Booking follow-up page (booking_followup.php). Data: window.FU_DATA,
 * refreshed from api/followup.php after each change. Filters / tabs run in the browser. */
(function () {
  'use strict';
  var D = window.FU_DATA;
  var API = 'api/followup.php';
  var ISSUE = {provisional: 'Provisional', provisional_expired: 'Provisional expired', on_request: 'On request',
               orangi_rooms_provisional: 'Rooms still provisional', ticket_missing: 'Ticket missing', unreadable: 'Unreadable',
               group_to_finalize: 'Group to finalize', group_znz_flight: 'Group ARK-ZNZ flight',
               transfer_missing: 'Transfer missing', booking_provisional: 'Booking provisional', flight_unpaid: 'Flight not paid / ticketed',
               client_flights_missing: 'Client flights missing', invoice_mismatch: 'Invoice mismatch', payment_mismatch: 'Payment mismatch', other: 'Other',
               sc_nights_differ: 'Nights differ', sc_checkin_differs: 'Check-in differs', sc_invoice_dates: 'Invoice dates differ', sc_rooms_differ: 'Rooms differ',
               sc_basis_differs: 'Basis differs', sc_grp_rooms_short: 'GRP rooms short', sc_grp_unsold: 'GRP unsold rooms', sc_transfer: 'Transfer',
               sc_flight: 'Flight', sc_dates: 'Dates', sc_pax: 'PAX', manual: 'Added by hand'};
  /** Issues meaning "still provisional" (FU_PROVISIONAL_ISSUES): Provisional tab, they block the _CK. */
  var PROV = ['provisional', 'provisional_expired', 'on_request', 'orangi_rooms_provisional', 'booking_provisional'];
  var SRC_ICON = {supplier_scan: '📄', ticket_scan: '🎫', group_scan: '👥', safaricheck: '✔', claude_check: '🤖', manual: '✋'};
  var AUD = {booking: 'Booking', sales: 'Sales', accounts: 'Accounts'};
  var STATUS = {open: 'Open', in_progress: 'In progress', problem: 'Problem', resolved: 'Resolved', not_relevant: 'Not relevant'};
  /**
   * Tabs (12 Nov 2026): Provisional first and the default — hotels, flights, balloons fill up; transfers always have
   * availability, in their own tab. Each open line is in one of provisional / transfers / tickets / groups / docs
   * (followup_tab_of(), sent as r.tab / r.tabs); a transfer ≤ 30 d before its date is in Provisional too.
   */
  var TABS = [
    {key: 'provisional', label: 'Provisional'},
    {key: 'transfers', label: 'Transfers'},
    {key: 'tickets', label: 'Tickets'},
    {key: 'groups', label: 'Groups'},
    {key: 'docs', label: 'Docs / Calc'},
    {key: 'problem', label: 'Problem'},
    {key: 'all', label: 'All open'},
    {key: 'closed', label: 'Closed'}
  ];
  var TAB_KEYS = TABS.map(function (t) { return t.key; });
  var params = new URLSearchParams(location.search);
  var urlTab = TAB_KEYS.indexOf(params.get('tab')) >= 0 ? params.get('tab') : null;   // kept only in the URL (links, reload)
  var state = {tab: urlTab || (params.get('type') === 'ck' ? 'all' : 'provisional'), owner: '', aud: '', prio: '',
               supplier: '', month: '', source: params.get('source') || (params.get('type') === 'ck' && !urlTab ? 'claude_check' : ''), q: params.get('q') || '',
               reason: params.get('close_reason') || '',
               request: params.get('request_id') ? parseInt(params.get('request_id'), 10) : null,
               group: params.get('folder') || '', open: {},   // GRP member: the group's lines belong to the group's first request
               exp: {}};   // "Set expiry" forms open: {id: {date, proof, name, file, files, err, busy}}
  var $ = function (id) { return document.getElementById(id); };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
    });
  }
  function dmy(iso) {
    if (!iso) return '';
    var d = new Date(iso.substr(0, 10) + 'T00:00:00');
    return d.toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'});
  }
  function dmyhm(iso) { return iso ? dmy(iso) + ' ' + iso.substr(11, 5) : ''; }
  function days(n) {
    if (n === null || n === undefined) return '';
    if (n === 0) return 'today';
    return n > 0 ? 'in ' + n + ' d' : Math.abs(n) + ' d ago';
  }

  // ── Filtering ───────────────────────────────────────────────────────────
  function srcs(r) { return (r.sources || []).map(function (s) { return s.source; }); }
  function isProv(r) {
    return PROV.indexOf(r.issue) >= 0 || (r.sources || []).some(function (s) { return PROV.indexOf(s.issue) >= 0; });
  }
  function inTab(r, tab) {
    if (tab === 'closed') return !!r.closed_at;
    if (r.closed_at) return false;
    if (tab === 'all') return true;
    if (tab === 'problem') return r.staff_status === 'problem';
    return (r.tabs || [r.tab || 'docs']).indexOf(tab) >= 0;
  }
  function passes(r) {
    if (state.request && r.request_id !== state.request && !(state.group && (r.folder || '').indexOf(state.group) >= 0)) return false;
    if (state.owner === 'none' && r.owner_user_id !== null) return false;
    if (state.owner && state.owner !== 'none' && String(r.owner_user_id) !== state.owner) return false;
    if (state.aud && (r.audience || 'booking') !== state.aud) return false;
    if (state.prio && r.priority !== state.prio) return false;
    if (state.supplier && (r.supplier || '') !== state.supplier) return false;
    if (state.month && (r.start_date || '').substr(0, 7) !== state.month) return false;
    if (state.source && srcs(r).indexOf(state.source) < 0) return false;
    if (state.reason && r.closed_at && r.close_reason !== state.reason) return false;   // "Closed as": closed lines only
    if (state.q) {
      var hay = [r.customer, r.folder, r.supplier, r.supplier_ref, r.service, r.notes, r.source_file, r.doc_status_text]
        .concat((r.sources || []).map(function (s) { return s.text; })).join(' ').toLowerCase();
      if (hay.indexOf(state.q.toLowerCase()) < 0) return false;
    }
    return true;
  }
  function all() { return D.rows.concat(D.closed); }
  /** Same order as followup_sort(): priority (from the deadline), nearest deadline, not taken in charge, problems, service date. Closed: newest first. */
  function sortRows(list) {
    var key = function (r) {
      return [r.priority, r.due_date || '9999', r.owner_user_id === null ? 0 : 1, r.staff_status === 'problem' ? 0 : 1, r.service_date || r.start_date || '9999', r.id];
    };
    return list.sort(function (a, b) {
      if (a.closed_at || b.closed_at) return (b.closed_at || '').localeCompare(a.closed_at || '');
      var x = key(a), y = key(b);
      for (var i = 0; i < x.length; i++) if (x[i] !== y[i]) return x[i] < y[i] ? -1 : 1;
      return 0;
    });
  }

  // ── Header, stats, tabs, filter options ─────────────────────────────────
  function renderRuns() {
    var parts = [];
    [['ticket', 'Tickets'], ['group', 'Groups'], ['supplier', 'Supplier documents']].forEach(function (t) {
      var r = D.runs[t[0]];
      parts.push(t[1] + ': ' + (r ? 'last check ' + dmyhm(r.at) + ' — ' + esc(r.by) + (r.partial ? ' (partial)' : '') : 'not checked yet'));
    });
    $('fu-runs').innerHTML = parts.join(' · ') + (D.me.can_edit ? '' : ' · <strong>view only</strong> (lines you own: editable)');
  }
  function renderStats() {
    var s = D.stats;
    var cards = [
      ['untaken', 'Not taken in charge', 'amber', function () { setTab('all'); state.owner = 'none'; }],
      ['urgent', 'Urgent', 'red', function () { setTab('all'); state.prio = '1-URGENT'; }],
      ['problem', 'Problem', 'red', function () { setTab('problem'); }],
      ['expiring', 'Expiring ≤ 7 days', 'amber', function () { setTab('provisional'); }],
      ['tickets', 'Tickets missing', 'navy', function () { setTab('tickets'); }],
      ['groups', 'Groups to finalize', 'navy', function () { setTab('groups'); }],
      ['ck', 'Check findings open', 'navy', function () { setTab('all'); state.source = 'claude_check'; }],
      ['closed7', 'Closed last 7 days', 'green', function () { setTab('closed'); }]
    ];
    var box = $('fu-stats');
    box.innerHTML = '';
    cards.forEach(function (c) {
      var el = document.createElement('div');
      el.className = 'stat-card ' + c[2];
      el.innerHTML = '<div class="v">' + (s[c[0]] || 0) + '</div><div class="l">' + c[1] + '</div>';
      el.addEventListener('click', function () { resetFilters(); c[3](); syncFilters(); render(); });
      box.appendChild(el);
    });
  }
  /** The tab lives in the URL only (?tab=, for links and reload); the plain page opens on Provisional. */
  function setTab(t) {
    state.tab = t;
    try {
      var u = new URL(location.href);
      if (t === 'provisional') u.searchParams.delete('tab'); else u.searchParams.set('tab', t);
      u.searchParams.delete('type');
      history.replaceState(null, '', u.pathname + u.search + u.hash);
    } catch (e) { /* old browser: the tab is just not in the URL */ }
  }
  function resetFilters() { state.owner = state.aud = state.prio = state.supplier = state.month = state.source = state.reason = ''; }
  function renderTabs() {
    var box = $('fu-tabs');
    box.innerHTML = '';
    TABS.forEach(function (t) {
      var list = all().filter(function (r) { return inTab(r, t.key) && passes(r); });
      var urgent = t.key === 'closed' ? 0 : list.filter(function (r) { return r.priority === '1-URGENT'; }).length;
      var b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('role', 'tab');
      b.setAttribute('aria-selected', state.tab === t.key ? 'true' : 'false');
      b.className = state.tab === t.key ? 'active' : '';
      b.innerHTML = esc(t.label) + '<span class="n">' + list.length + '</span>' +
        (urgent ? '<span class="fu-tab-urgent" title="' + urgent + ' urgent">' + urgent + '</span>' : '');
      b.addEventListener('click', function () { setTab(t.key); render(); });
      box.appendChild(b);
    });
  }
  function fillSelect(id, opts, cur) {
    var sel = $(id);
    sel.innerHTML = opts.map(function (o) {
      return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(cur) ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
    }).join('');
  }
  function syncFilters() {
    var rows = all();
    var owners = {};
    rows.forEach(function (r) { if (r.owner_user_id !== null) owners[r.owner_user_id] = r.owner_name; });
    fillSelect('fu-f-owner', [['', 'All'], ['none', 'Nobody']].concat(Object.keys(owners).map(function (k) { return [k, owners[k]]; })), state.owner);
    var sup = {};
    rows.forEach(function (r) { if (r.supplier) sup[r.supplier] = 1; });
    fillSelect('fu-f-supplier', [['', 'All']].concat(Object.keys(sup).sort().map(function (k) { return [k, k]; })), state.supplier);
    var mon = {};
    rows.forEach(function (r) { if (r.start_date) mon[r.start_date.substr(0, 7)] = 1; });
    fillSelect('fu-f-month', [['', 'All']].concat(Object.keys(mon).sort().map(function (k) {
      return [k, new Date(k + '-01T00:00:00').toLocaleDateString('en-GB', {month: 'short', year: 'numeric'})];
    })), state.month);
    var src = {};
    rows.forEach(function (r) { srcs(r).forEach(function (t) { src[t] = 1; }); });
    fillSelect('fu-f-src', [['', 'All']].concat(Object.keys(src).sort().map(function (k) { return [k, (D.source_labels || {})[k] || k]; })), state.source);
    var rs = {};
    rows.forEach(function (r) { if (r.closed_at && r.close_reason) rs[r.close_reason] = 1; });
    fillSelect('fu-f-reason', [['', 'All']].concat(Object.keys(rs).sort().map(function (k) { return [k, (D.reason_labels || {})[k] || k]; })), state.reason);
    $('fu-f-prio').value = state.prio;
    $('fu-f-aud').value = state.aud;
    $('fu-f-q').value = state.q;
    var chip = $('fu-f-req');
    if (state.request) {
      var hit = rows.filter(function (r) { return r.request_id === state.request; })[0];
      chip.hidden = false;
      chip.innerHTML = 'Request #' + state.request + (hit && hit.customer ? ' — ' + esc(hit.customer) : '') + ' <button type="button" aria-label="Clear">×</button>';
      chip.querySelector('button').addEventListener('click', function () { state.request = null; state.group = ''; syncFilters(); render(); });
    } else {
      chip.hidden = true;
    }
  }

  // ── Table ───────────────────────────────────────────────────────────────
  function prioBadge(p) {
    var n = p.charAt(0);
    return '<span class="badge badge-p' + n + '">' + esc(p.substr(2).toLowerCase()) + '</span>';
  }
  /** Priority + the deadline it comes from (followup_due()); a line added by hand: its date, editable. */
  function prioCell(r, edit) {
    var due = r.due_date ? '<span class="fu-due' + (r.due_days !== null && r.due_days <= 7 ? ' fu-late' : '') + '" title="Deadline: ' + esc(r.due_reason || '') + '">⏱ ' +
      dmy(r.due_date) + '<span class="fu-days">' + (r.due_days < 0 ? Math.abs(r.due_days) + ' d late' : days(r.due_days)) + '</span></span>' : '';
    if (r.type === 'manual' && edit && !r.closed_at) {
      due = '<input type="date" data-f="due_date" aria-label="Deadline" value="' + esc(r.due_date && r.due_reason === 'set by hand' ? r.due_date : '') + '" title="Deadline (set by hand)">' +
            (r.due_date && r.due_reason !== 'set by hand' ? '<span class="fu-days">⏱ ' + dmy(r.due_date) + '</span>' : '');
    }
    return prioBadge(r.priority) + due;
  }
  /** The checks reporting the line (one line per service): an icon each, what it says in the tooltip. */
  function sourceChips(r) {
    var list = r.sources || [];
    if (!list.length) return '';
    return '<span class="fu-srcs">' + list.map(function (s) {
      return '<span class="fu-src" title="' + esc(s.label + ': ' + (s.issue_label || '') + (s.text ? ' — ' + s.text : '') + (s.since ? ' (since ' + dmy(s.since) + ')' : '')) + '">' +
        (SRC_ICON[s.source] || '•') + ' ' + esc(s.label) + '</span>';
    }).join('') + '</span>';
  }
  /** Same rules as followup_update(): the booking team or the line's owner work it; reassign = admin, owner, or the team on a line nobody owns. */
  function canWork(r) { return D.me.can_edit || r.owner_user_id === D.me.id; }
  function canReassign(r) { return D.me.is_admin || r.owner_user_id === D.me.id || (r.owner_user_id === null && D.me.can_edit); }
  /** Owner: any active user (D.users); "Take" for the booking team on a line nobody owns. */
  function ownerCell(r) {
    var canTake = !r.closed_at && r.owner_user_id === null && D.me.can_edit;
    if (r.closed_at || !canReassign(r)) {
      return esc(r.owner_name || '—') + (canTake ? ' <button type="button" class="btn btn-success btn-sm" data-take="1">Take</button>' : '');
    }
    var known = r.owner_user_id === null || D.users.some(function (u) { return u.id === r.owner_user_id; });
    return '<select data-f="owner" aria-label="Owner"><option value="">— nobody —</option>' +
      (known ? '' : '<option value="' + r.owner_user_id + '" selected>' + esc(r.owner_name) + '</option>') +
      D.users.map(function (u) {
        return '<option value="' + u.id + '"' + (u.id === r.owner_user_id ? ' selected' : '') + '>' + esc(u.name) + '</option>';
      }).join('') + '</select>' + (canTake ? ' <button type="button" class="btn btn-success btn-sm" data-take="1">Take</button>' : '');
  }
  function statusCell(r) {
    if (!canWork(r)) return esc(STATUS[r.staff_status]);
    var nr = D.me.is_admin || r.owner_user_id === D.me.id;   // not relevant: admin or the current owner
    var opts = ['open', 'in_progress', 'problem', 'resolved'].concat(nr || r.staff_status === 'not_relevant' ? ['not_relevant'] : []);
    return '<select data-f="staff_status" aria-label="Status">' + opts.map(function (s) {
      return '<option value="' + s + '"' + (s === r.staff_status ? ' selected' : '') + (s === 'not_relevant' && !nr ? ' disabled' : '') + '>' + STATUS[s] + '</option>';
    }).join('') + '</select>';
  }
  // ── Expiry extended by mail (expiry_override + proof .eml in mails/) ────
  function dShort(iso) {
    return iso ? new Date(iso.substr(0, 10) + 'T00:00:00').toLocaleDateString('en-GB', {day: 'numeric', month: 'short'}) : '';
  }
  /** Same rule as followup_override_refusal(): the booking team / admin, open lines of a provisional booking. */
  function canSetExpiry(r) { return D.me.can_edit && !r.closed_at && (isProv(r) || !!r.expiry_date); }
  /**
   * The expiry: the effective one ("30 Nov 2026 (by mail)", who / when / proof on hover), the document's struck through
   * below ("pro-forma: 30 Oct"); "Set expiry" for the booking team.
   */
  function expiryCell(r) {
    var o = r.expiry_override, h = '';
    if (r.expiry_date) {
      var lbl = (r.expired ? 'expired ' : 'expires ') + dmy(r.expiry_date);
      if (!o) {
        h = '<span class="fu-days' + (r.expired ? ' fu-late' : '') + '" style="white-space:nowrap">' + lbl + '</span>';
      } else {
        var name = o.proof ? o.proof.replace(/^.*\//, '') : '';
        h = '<span class="fu-ovr" tabindex="0"><span class="fu-days' + (r.expired ? ' fu-late' : '') + '" style="white-space:nowrap">' + lbl + ' <b>(by mail)</b></span>' +
            '<span class="fu-pop" role="tooltip">Extended by mail — set by ' + esc(o.by || '?') + ', ' + esc(dmyhm(o.at)) +
            (o.proof ? '<br>Proof: <a href="' + esc(o.proof_url) + '" target="_blank" rel="noopener" title="Open in Dropbox (web)">☁</a> ' +
                       '<a href="' + esc(o.proof_open_url) + '" title="Open on this PC — ' + esc(o.proof) + '">✉ ' + esc(name) + '</a>' : '') + '</span></span>' +
            (r.doc_expiry_date ? '<span class="fu-days fu-struck" title="Expiry printed on the document">' +
              (/pro.?forma/i.test(r.source_file || '') ? 'pro-forma' : 'document') + ': ' + dShort(r.doc_expiry_date) + '</span>' : '');
      }
    }
    if (!canSetExpiry(r)) return h;
    var f = state.exp[r.id];
    if (!f) return h + '<button type="button" class="fu-hbtn fu-setexp" data-exp="1" title="The supplier extended the hold by mail: set the new expiry, with the mail as proof">📅 ' +
                       (o ? 'Change expiry' : 'Set expiry') + '</button>';
    var opts = f.files === undefined ? '<option value="">Loading mails/ …</option>'
             : '<option value="">' + (f.files.length ? '— choose a mail in mails/ —' : '— no .eml in mails/ yet —') + '</option>' +
               f.files.map(function (m) {
                 return '<option value="' + esc(m.proof) + '"' + (m.proof === f.proof ? ' selected' : '') + '>' + esc(m.name) + '</option>';
               }).join('');
    return h + '<div class="fu-exp" data-expform="1">' +
      '<label>New expiry (confirmed by mail)<input type="date" data-x="date" value="' + esc(f.date || '') + '"></label>' +
      '<label>Proof: a mail already in mails/<select data-x="proof"' + (f.file ? ' disabled' : '') + '>' + opts + '</select></label>' +
      '<label>… or upload the mail (.eml)<input type="file" data-x="file" accept=".eml,message/rfc822"></label>' +
      (f.file ? '<span class="fu-days">📎 ' + esc(f.file.name) + ' <button type="button" class="fu-hbtn" data-xfile-clear="1">×</button></span>' +
                '<label>Save as (mails/)<input type="text" data-x="name" value="' + esc(f.name || '') + '" placeholder="empty: ' + esc(suggestName(r, f)) + '"></label>' : '') +
      '<div class="fu-save"><button type="button" class="btn btn-primary btn-sm" data-xsave="1"' + (f.busy ? ' disabled' : '') + '>' + (f.busy ? 'Saving…' : 'Save expiry') + '</button>' +
      '<button type="button" class="btn btn-outline btn-sm" data-xcancel="1">Cancel</button></div>' +
      (o ? '<button type="button" class="fu-hbtn" data-xclear="1" title="Back to the expiry printed on the document">✕ Remove the mail expiry</button>' : '') +
      (f.err ? '<span class="fu-err">' + esc(f.err) + '</span>' : '') + '</div>';
  }
  /** Suggested name (the server builds it the same way from the mail's Date header): AAAA-MM-GG_<Supplier>_<Ref> extension to DD-MM.eml */
  function suggestName(r, f) {
    var ext = f.date ? ' extension to ' + f.date.substr(8, 2) + '-' + f.date.substr(5, 2) : ' mail';
    return ['YYYY-MM-DD', r.supplier || '', r.supplier_ref || ''].filter(Boolean).join('_').replace(/[\/\\:*?"<>|]/g, '_') + ext + '.eml';
  }
  function openExpiry(r) {
    var f = state.exp[r.id] = {date: r.expiry_override ? r.expiry_override.date : '', proof: '', name: '', file: null, files: undefined, err: ''};
    renderTable();
    fetch(API + '?action=mails&id=' + r.id, {credentials: 'same-origin'}).then(function (x) { return x.json(); }).then(function (d) {
      if (state.exp[r.id] !== f) return;
      f.files = d.ok ? d.files : [];
      if (!d.ok) f.err = d.error || 'mails/ not listed';
      renderTable();
    }).catch(function (e) { f.files = []; f.err = e.message; renderTable(); });
  }
  function readBase64(file) {
    return new Promise(function (ok, ko) {
      var rd = new FileReader();
      rd.onload = function () { ok(String(rd.result).replace(/^data:[^,]*,/, '')); };
      rd.onerror = function () { ko(new Error('The file could not be read')); };
      rd.readAsDataURL(file);
    });
  }
  function saveExpiry(r, clear) {
    var f = state.exp[r.id] || {};
    var body = {id: r.id, date: clear ? '' : (f.date || '')};
    if (!clear) {
      if (!body.date) { f.err = 'Choose the new expiry date'; renderTable(); return; }
      if (!f.file && !f.proof) { f.err = 'A proof is required: choose the supplier\'s mail in mails/ or upload it (.eml)'; renderTable(); return; }
      if (f.file && !/\.eml$/i.test(f.file.name)) { f.err = 'Upload the mail as .eml (in Outlook / Thunderbird: Save as → .eml)'; renderTable(); return; }
    }
    f.busy = true; f.err = ''; renderTable();
    var go = !clear && f.file ? readBase64(f.file).then(function (b64) { body.upload = {name: f.name || '', content_base64: b64}; })
                              : Promise.resolve(body.proof = clear ? '' : f.proof);
    go.then(function () { return post('set_expiry', body); }).then(function (d) {
      f.busy = false;
      if (!d.ok) { f.err = d.error || 'Not saved'; renderTable(); return; }
      delete state.exp[r.id];
      if (d.row) replaceRow(d.row);
      renderTabs(); renderTable();
    }).catch(function (e) { f.busy = false; f.err = e.message; renderTable(); });
  }

  /** Notes written so far, newest first, with date and author (a note older than the log: no date). */
  function notesLog(r) {
    var log = (r.notes_log || []).slice();
    if (!log.length && r.notes) log.push({at: null, by: '', text: r.notes});
    if (!log.length) return '';
    return '<ul class="fu-notes">' + log.map(function (n) {
      return '<li>' + (n.at ? '<span class="w">' + dmy(n.at) + (n.by ? ' · ' + esc(n.by) : '') + '</span>' : '') + esc(n.text) + '</li>';
    }).join('') + '</ul>';
  }
  function row(r) {
    var tr = document.createElement('tr');
    tr.dataset.id = r.id;
    if (r.staff_status === 'problem' && !r.closed_at) tr.className = 'problem';
    var svc = r.service_date || r.start_date;
    var issueCls = r.issue === 'provisional_expired' ? ' exp' : '';
    var edit = canWork(r);
    var ck = r.type === 'ck' || r.type === 'safaricheck' || r.type === 'manual';
    var cells = [
      prioCell(r, edit),
      '<span style="white-space:nowrap">' + dmy(svc) + '</span><span class="fu-days' + (r.days_left !== null && r.days_left <= 7 ? ' fu-late' : '') + '">' + days(r.days_left) + '</span>',
      '<span class="fu-client">' + esc(r.customer || r.folder_name) + '</span>' +
        (r.folder_open_url ? '<span class="fu-folder"><a href="' + esc(r.folder_url) + '" target="_blank" rel="noopener" title="Open in Dropbox (web)">☁</a> ' +
          '<a href="' + esc(r.folder_open_url) + '" title="Open in Windows Explorer — ' + esc(r.folder) + '">📂 ' + esc(r.folder_name) + '</a></span>' : '') +
        (r.ck_tracker_url ? '<span class="fu-folder"><a href="' + esc(r.ck_tracker_url) + '" target="_blank" title="The booking in the CK tracker (pipeline, SafariCheck, _CK)">✅ CK tracker</a></span>' : ''),
      esc(r.type === 'ticket' || (ck && !r.supplier) ? (r.service || r.supplier || '') : (r.supplier || '')) +
        ((r.type === 'group' || (ck && r.supplier)) && r.service ? '<span class="fu-days">' + esc(r.service) + '</span>' : '') +
        (r.supplier_ref ? '<span class="fu-ref">' + esc(r.supplier_ref) + '</span>' : ''),
      '<span class="badge badge-issue' + issueCls + '">' + esc(ISSUE[r.issue] || r.issue_label || r.issue) + '</span>' +
        (r.transfer_alert ? '<br><span class="badge badge-issue exp fu-tr30" title="Transfer not confirmed and the service is close: also listed in Provisional">Transfer ≤ ' +
                            (D.transfer_alert_days || 30) + ' d</span>' : '') +
        (r.audience && r.audience !== 'booking' || r.type === 'ck' ? '<br><span class="badge badge-aud ' + esc(r.audience) + '" title="Routed to">' + esc(AUD[r.audience] || r.audience) + '</span>' : '') +
        sourceChips(r),
      esc(r.doc_status_text || '') + (r.booked_by ? '<span class="fu-days">booked by ' + esc(r.booked_by) + '</span>' : '') + expiryCell(r),
      (r.file_open_url ? '<span class="fu-folder fu-file"><a href="' + esc(r.file_url) + '" target="_blank" rel="noopener" title="Open in Dropbox (web)">☁</a> ' +
        '<a href="' + esc(r.file_open_url) + '" title="Open on this PC — ' + esc(r.source_file) + '">📄 ' + esc(r.source_file.replace(/^invoices\//, '')) + '</a></span>'
      : r.file_url ? '<a href="' + esc(r.file_url) + '" target="_blank" rel="noopener" title="' + esc(r.source_file) + '">📄 ' + esc(r.source_file.replace(/^invoices\//, '')) + '</a>' : '') +
        (r.report_url ? '<span class="fu-folder fu-file"><a href="' + esc(r.report_url) + '" target="_blank" rel="noopener" title="Booking check report (Dropbox web)">☁</a> ' +
          '<a href="' + esc(r.report_open_url) + '" title="Open the booking check report on this PC">📋 booking-check</a></span>' : ''),
      ownerCell(r),
      statusCell(r),
      notesLog(r) + (edit ? '<textarea data-f="notes" aria-label="Add a note" placeholder="Add a note…"></textarea>' +
                            '<div class="fu-save"><button type="button" class="btn btn-primary btn-sm" data-save="1">Save</button></div>' : ''),
      (state.tab === 'closed'
        ? '<span style="white-space:nowrap">' + dmyhm(r.closed_at) + '</span><span class="fu-days">' + esc(r.close_reason_label || '') + ' — ' + esc(r.closed_by || '') + '</span>'
        : '') +
      '<button type="button" class="fu-hbtn" data-hist="1" title="History — last check ' + esc(dmy(r.last_seen_at)) + '">🕘 ' + r.events_count + '</button>' +
        (state.tab === 'closed' ? '' : '<span class="fu-days" style="white-space:nowrap" title="Last check">' + dmy(r.last_seen_at) + '</span>')
    ];
    tr.innerHTML = cells.map(function (c) { return '<td>' + c + '</td>'; }).join('');
    return tr;
  }
  // ── Edits are saved with the row's Save button ──────────────────────────
  function rowOf(tr) { var id = parseInt(tr.dataset.id, 10); return all().filter(function (x) { return x.id === id; })[0]; }
  function field(tr, f) { return tr.querySelector('[data-f="' + f + '"]'); }
  /** What the row's fields change on the line (owner, status, a new note). */
  function changesOf(tr) {
    var r = rowOf(tr), c = {}, el;
    if (!r) return c;
    if ((el = field(tr, 'owner')) && el.value !== String(r.owner_user_id === null ? '' : r.owner_user_id)) c.owner = el.value === '' ? null : parseInt(el.value, 10);
    if ((el = field(tr, 'staff_status')) && el.value !== r.staff_status) c.staff_status = el.value;
    if ((el = field(tr, 'notes')) && el.value.trim() !== '') c.notes = el.value;
    if ((el = field(tr, 'due_date')) && el.value !== (r.due_reason === 'set by hand' ? r.due_date || '' : '')) c.due_date = el.value;
    return c;
  }
  function markDirty(tr) {
    tr.classList.toggle('dirty', Object.keys(changesOf(tr)).length > 0);
    var b = tr.querySelector('[data-save]');
    if (b) b.disabled = false;
  }
  function renderTable() {
    var t = $('fu-table');
    // Rows being edited keep what was typed / chosen when the table is drawn again (another row saved, a filter).
    var pending = {};
    Array.prototype.forEach.call(t.querySelectorAll('tr.dirty[data-id]'), function (tr) {
      pending[tr.dataset.id] = ['owner', 'staff_status', 'notes', 'due_date'].map(function (f) { var el = field(tr, f); return el ? [f, el.value] : null; }).filter(Boolean);
    });
    var list = sortRows(all().filter(function (r) { return inTab(r, state.tab) && passes(r); }));
    var head = ['Priority / deadline', state.tab === 'groups' ? 'Arrival' : 'Service', 'Client / folder',
                state.tab === 'tickets' ? 'Flight' : state.tab === 'groups' ? 'Group' : state.tab === 'provisional' ? 'Supplier' : 'Supplier / service',
                'Issue / found by', state.tab === 'groups' ? 'What we found' : state.tab === 'docs' || state.tab === 'transfers' ? 'What to do' : 'Document status',
                'File', 'Owner', 'Status', 'Notes', state.tab === 'closed' ? 'Closed' : 'Checked'];
    t.innerHTML = '<thead><tr>' + head.map(function (h) { return '<th>' + h + '</th>'; }).join('') + '</tr></thead>';
    var body = document.createElement('tbody');
    if (!list.length) {
      body.innerHTML = '<tr><td colspan="' + head.length + '" class="fu-empty">' +
        (all().length ? 'Nothing here with these filters.' : 'No open items. The check fills this list (tickets every morning, supplier documents every Monday).') + '</td></tr>';
    }
    list.forEach(function (r) {
      body.appendChild(row(r));
      if (state.open[r.id]) body.appendChild(histRow(r, state.open[r.id], head.length));
    });
    t.appendChild(body);
    Object.keys(pending).forEach(function (id) {
      var tr = body.querySelector('tr[data-id="' + id + '"]');
      if (!tr) return;
      pending[id].forEach(function (p) { var el = field(tr, p[0]); if (el) el.value = p[1]; });
      markDirty(tr);
    });
  }
  /** Group lines: what the check collected (Excel, client folders, blocks, flights), above the history. */
  function detailsList(r) {
    var d = r.details || {};
    var rows = [];
    var add = function (label, list) { (list || []).forEach(function (x) { rows.push('<li><b>' + esc(label) + '</b>' + esc(x) + '</li>'); }); };
    // Every check reporting the line, with what it says (one line per service).
    (r.sources || []).forEach(function (s) {
      rows.push('<li><b>' + (SRC_ICON[s.source] || '•') + ' ' + esc(s.label) + '</b>' + esc(s.issue_label || '') + (s.text ? ' — ' + esc(s.text) : '') +
                (s.since ? ' <span class="w">since ' + dmy(s.since) + '</span>' : '') + '</li>');
    });
    if (r.due_date) rows.push('<li><b>Deadline</b>' + dmy(r.due_date) + ' — ' + esc(r.due_reason || '') + '</li>');
    if (d.payment_days) rows.push('<li><b>Terms</b>supplier payment ' + d.payment_days + ' days before check-in (read in the document)</li>');
    if (d.ticket_limit) rows.push('<li><b>Terms</b>ticketing time limit ' + dmy(d.ticket_limit) + '</li>');
    if (d.calc) rows.push('<li><b>Excel</b>' + esc(d.calc.file) + (d.calc.pax_total !== null ? ' — ' + d.calc.pax_total + ' pax' : '') + '</li>');
    add('Sheet', d.sold); add('Client folder', d.client_folders); add('Block', d.blocks); add('Provisional', d.provisional);
    add('Flight', d.flights); add('Flying ARK-ZNZ', d.clients_flying);
    if (d.calc && d.calc.warnings) add('Excel', d.calc.warnings);
    if (d.names_tba) rows.push('<li><b>Names</b>still TBA</li>');
    if (d.documents_not_read) rows.push('<li><b>PDF</b>' + d.documents_not_read + ' not read yet (run the office PC scan)</li>');
    return rows.length ? '<ul class="fu-det">' + rows.join('') + '</ul>' : '';
  }
  function histRow(r, events, span) {
    var id = r.id;
    var tr = document.createElement('tr');
    tr.className = 'hist';
    tr.dataset.hist = id;
    var items = events === 'loading' ? '<li>Loading…</li>' : events.map(function (e) {
      var what = e.field === 'created' ? 'created: ' + esc(e.new) : e.field === 'closed' ? 'closed: ' + esc(e.new) : e.field === 'reopened' ? 're-opened: ' + esc(e.new)
               : esc(e.field.replace('_', ' ')) + ': ' + esc(e.old || '—') + ' → ' + esc(e.new || '—');
      return '<li><span class="w">' + dmyhm(e.at) + ' · ' + esc(e.by) + '</span>' + what + '</li>';
    }).join('');
    tr.innerHTML = '<td colspan="' + span + '">' + detailsList(r) + '<ul class="fu-ev">' + items + '</ul></td>';
    return tr;
  }
  function render() { renderRuns(); renderStats(); renderTabs(); renderTable(); }

  // ── Server calls ────────────────────────────────────────────────────────
  function post(action, body) {
    return fetch(API + '?action=' + action, {method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)})
      .then(function (r) { return r.json().catch(function () { return {ok: false, error: 'HTTP ' + r.status}; }); });
  }
  function replaceRow(nr) {
    [D.rows, D.closed].forEach(function (list) {
      for (var i = list.length - 1; i >= 0; i--) if (list[i].id === nr.id) list.splice(i, 1);
    });
    (nr.closed_at ? D.closed : D.rows).push(nr);
    refreshStats();
  }
  function refreshStats() {
    fetch(API + '?action=list', {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (d) {
      if (d.ok) { D.stats = d.stats; D.runs = d.runs; renderStats(); renderRuns(); }
    }).catch(function () {});
  }
  function save(tr, change, cell) {
    var id = parseInt(tr.dataset.id, 10);
    var mark = function (html) {
      var old = cell.querySelector('.fu-saved,.fu-err'); if (old) old.remove();
      if (html) cell.insertAdjacentHTML('beforeend', html);
    };
    change.id = id;
    var btn = tr.querySelector('[data-save]');
    if (btn && change.fromSave) { btn.disabled = true; btn.textContent = 'Saving…'; }
    var fromSave = change.fromSave; delete change.fromSave;
    return post('update', change).then(function (d) {
      if (btn && fromSave) btn.textContent = 'Save';
      if (!d.ok) { mark('<span class="fu-err">' + esc(d.error || 'Not saved') + '</span>'); if (btn) markDirty(tr); return false; }
      if (d.row) {
        replaceRow(d.row);
        if (fromSave) tr.classList.remove('dirty');   // what was saved is now the line: nothing to keep
        var keep = state.tab;
        renderTabs();
        state.tab = keep;
        renderTable();
        var nt = $('fu-table').querySelector('tr[data-id="' + id + '"] .fu-save');
        if (nt && fromSave) nt.insertAdjacentHTML('beforeend', '<span class="fu-saved">✓ saved</span>');
      }
      return true;
    }).catch(function (e) { if (btn && fromSave) { btn.textContent = 'Save'; markDirty(tr); } mark('<span class="fu-err">' + esc(e.message) + '</span>'); return false; });
  }

  $('fu-table').addEventListener('click', function (ev) {
    var b = ev.target.closest('button');
    if (!b) return;
    var tr = b.closest('tr[data-id]');
    if (!tr) return;
    var id = parseInt(tr.dataset.id, 10);
    if (b.dataset.exp) { openExpiry(rowOf(tr)); return; }
    if (b.dataset.xcancel) { delete state.exp[id]; renderTable(); return; }
    if (b.dataset.xsave) { saveExpiry(rowOf(tr), false); return; }
    if (b.dataset.xclear) {
      if (window.confirm('Remove the expiry confirmed by mail? The expiry printed on the document counts again.')) saveExpiry(rowOf(tr), true);
      return;
    }
    if (b.dataset.xfileClear) { if (state.exp[id]) { state.exp[id].file = null; state.exp[id].name = ''; } renderTable(); return; }
    if (b.dataset.take) save(tr, {take: true}, b.parentNode);
    else if (b.dataset.release) save(tr, {owner: null}, b.parentNode);
    else if (b.dataset.save) {
      var change = changesOf(tr), r = rowOf(tr);
      var prev = b.parentNode.querySelector('.fu-err,.fu-saved'); if (prev) prev.remove();
      if (!Object.keys(change).length) {
        b.parentNode.insertAdjacentHTML('beforeend', '<span class="fu-err">Nothing changed: choose an owner / status or write a note</span>');
        return;
      }
      if (change.staff_status === 'problem' && !change.notes && !(r && r.notes)) {
        var old = b.parentNode.querySelector('.fu-err,.fu-saved'); if (old) old.remove();
        b.parentNode.insertAdjacentHTML('beforeend', '<span class="fu-err">Problem needs a note: write it above, then Save</span>');
        field(tr, 'notes').focus();
        return;
      }
      change.fromSave = true;
      save(tr, change, b.parentNode);
    }
    else if (b.dataset.hist) {
      if (state.open[id]) { delete state.open[id]; renderTable(); return; }
      state.open[id] = 'loading';
      renderTable();
      fetch(API + '?action=events&id=' + id, {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (d) {
        state.open[id] = d.ok ? d.events : [];
        renderTable();
      });
    }
  });
  // Owner, status and a new note are only marked as changed here; the row's Save button sends them together.
  // "Set expiry" form fields: kept in state.exp (the table is redrawn after each save of another row).
  ['change', 'input'].forEach(function (type) {
    $('fu-table').addEventListener(type, function (ev) {
      var x = ev.target.dataset.x, tr = x && ev.target.closest('tr[data-id]');
      var f = tr && state.exp[parseInt(tr.dataset.id, 10)];
      if (!f) return;
      if (x === 'file') {
        if (type !== 'change') return;
        f.file = ev.target.files && ev.target.files[0] || null;
        f.err = '';
        renderTable();
        return;
      }
      f[x] = ev.target.value;
      var nm = x === 'date' && tr.querySelector('input[data-x="name"]');   // suggested name follows the date (no redraw: focus kept)
      if (nm) nm.placeholder = 'empty: ' + suggestName(rowOf(tr), f);
    });
  });
  ['change', 'input'].forEach(function (type) {
    $('fu-table').addEventListener(type, function (ev) {
      var tr = ev.target.dataset.f && ev.target.closest('tr[data-id]');
      if (!tr) return;
      var msg = tr.querySelector('.fu-save .fu-saved,.fu-save .fu-err'); if (msg) msg.remove();
      markDirty(tr);
    });
  });
  window.addEventListener('beforeunload', function (ev) {
    if ($('fu-table').querySelector('tr.dirty')) { ev.preventDefault(); ev.returnValue = ''; }
  });

  // Filters
  [['fu-f-owner', 'owner'], ['fu-f-aud', 'aud'], ['fu-f-prio', 'prio'], ['fu-f-supplier', 'supplier'], ['fu-f-month', 'month'], ['fu-f-src', 'source'],
   ['fu-f-reason', 'reason']].forEach(function (p) {
    $(p[0]).addEventListener('change', function () { state[p[1]] = this.value; render(); });
  });
  $('fu-f-q').addEventListener('input', function () { state.q = this.value.trim(); renderTabs(); renderTable(); });

  // Run check now
  var runBtn = $('fu-run-btn');
  if (runBtn) runBtn.addEventListener('click', function () {
    var type = $('fu-run-type').value, msg = $('fu-msg');
    runBtn.disabled = true;
    msg.className = 'fu-msg'; msg.style.display = 'block';
    msg.textContent = '⏳ Checking ' + (type === 'ticket' ? 'tickets' : type === 'supplier' ? 'supplier documents' : type === 'group' ? 'groups arriving within 60 days' : 'tickets, groups and supplier documents') +
                      '… (supplier documents: up to a few minutes)';
    post('scan', {type: type}).then(function (d) {
      runBtn.disabled = false;
      if (!d.ok) { msg.className = 'fu-msg err'; msg.textContent = '⚠ ' + (d.error || 'Check failed'); return; }
      D.rows = d.rows; D.closed = d.closed; D.stats = d.stats; D.runs = d.runs;
      var lines = [];
      Object.keys(d.summary).forEach(function (t) {
        var s = d.summary[t];
        lines.push('<strong>' + (t === 'ticket' ? 'Tickets' : t === 'group' ? 'Groups' : 'Supplier documents') + ':</strong> ' + s.added + ' new, ' + s.updated + ' updated, ' +
                   s.closed + ' closed, ' + s.reopened + ' re-opened, ' + s.unreadable + ' unreadable' +
                   (s.merged ? ', ' + s.merged + ' added to another check\'s line' : '') + (s.cleared ? ', ' + s.cleared + ' still open (another check reports them)' : '') +
                   (s.units !== undefined ? ' · ' + s.units_complete + '/' + s.units + ' folders read' : '') +
                   (s.warning ? '<br>⚠ ' + esc(s.warning) : '') + (s.hint ? '<br>' + esc(s.hint) : '') +
                   (s.errors && s.errors.length ? '<br>⚠ ' + s.errors.length + ' folder(s) not read: ' + esc(s.errors.map(function (e) { return e.folder + ' (' + e.error + ')'; }).join('; ')) : ''));
      });
      msg.innerHTML = '✓ ' + lines.join('<br>');
      syncFilters(); render();
    }).catch(function (e) { runBtn.disabled = false; msg.className = 'fu-msg err'; msg.textContent = '⚠ ' + e.message; });
  });

  // Add a line by hand (a problem found by phone …): the booking team.
  var addBtn = $('fu-add-btn'), addBox = $('fu-add');
  if (addBtn && addBox) {
    var ownerSel = $('fu-add-owner');
    ownerSel.innerHTML = '<option value="">Default (booking owner / CK owner / Roberto)</option>' +
      D.users.map(function (u) { return '<option value="' + u.id + '">' + esc(u.name) + '</option>'; }).join('');
    addBtn.addEventListener('click', function () {
      addBox.hidden = !addBox.hidden;
      if (!addBox.hidden) {
        if (state.request && !$('fu-add-req').value) $('fu-add-req').value = state.request;
        $('fu-add-text').focus();
      }
    });
    $('fu-add-cancel').addEventListener('click', function () { addBox.hidden = true; });
    $('fu-add-save').addEventListener('click', function () {
      var msg = $('fu-add-msg'), btn = this;
      var body = {request_id: parseInt($('fu-add-req').value, 10) || 0, audience: $('fu-add-aud').value, text: $('fu-add-text').value.trim(),
                  service: $('fu-add-svc').value.trim(), service_date: $('fu-add-sdate').value, due_date: $('fu-add-due').value,
                  owner: ownerSel.value ? parseInt(ownerSel.value, 10) : null};
      if (!body.request_id || !body.text) { msg.className = 'fu-err'; msg.textContent = 'Request # and what to do are required'; return; }
      btn.disabled = true; msg.className = 'fu-days'; msg.textContent = 'Saving…';
      post('add', body).then(function (d) {
        btn.disabled = false;
        if (!d.ok) { msg.className = 'fu-err'; msg.textContent = d.error || 'Not saved'; return; }
        msg.className = 'fu-saved'; msg.textContent = '✓ added';
        if (d.row) replaceRow(d.row);
        ['fu-add-text', 'fu-add-svc', 'fu-add-sdate', 'fu-add-due'].forEach(function (id) { $(id).value = ''; });
        syncFilters(); render();
      }).catch(function (e) { btn.disabled = false; msg.className = 'fu-err'; msg.textContent = e.message; });
    });
  }

  // A link to one booking (request view, CK tracker) with nothing in Provisional: open the first tab that has its lines.
  if (state.request && !urlTab && !all().some(function (r) { return inTab(r, state.tab) && passes(r); })) {
    var hit = TAB_KEYS.filter(function (k) { return all().some(function (r) { return inTab(r, k) && passes(r); }); })[0];
    if (hit) state.tab = hit;
  }
  syncFilters();
  render();
})();
