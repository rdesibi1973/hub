/* booking_followup.js — Booking follow-up page (booking_followup.php). Data: window.FU_DATA,
 * refreshed from api/followup.php after each change. Filters / tabs run in the browser. */
(function () {
  'use strict';
  var D = window.FU_DATA;
  var API = 'api/followup.php';
  var ISSUE = {provisional: 'Provisional', provisional_expired: 'Provisional expired', on_request: 'On request',
               orangi_rooms_provisional: 'Rooms still provisional', ticket_missing: 'Ticket missing', unreadable: 'Unreadable',
               group_to_finalize: 'Group to finalize', group_znz_flight: 'Group ARK-ZNZ flight'};
  var STATUS = {open: 'Open', in_progress: 'In progress', problem: 'Problem', resolved: 'Resolved', not_relevant: 'Not relevant'};
  var TABS = [
    {key: 'open', label: 'All open'},
    {key: 'provisional', label: 'Provisional'},
    {key: 'tickets', label: 'Tickets'},
    {key: 'groups', label: 'Groups'},
    {key: 'problem', label: 'Problem'},
    {key: 'closed', label: 'Closed'}
  ];
  var params = new URLSearchParams(location.search);
  var state = {tab: 'open', owner: '', prio: '', supplier: '', month: '', q: params.get('q') || '',
               request: params.get('request_id') ? parseInt(params.get('request_id'), 10) : null,
               group: params.get('folder') || '', open: {}};   // GRP member: the group's lines belong to the group's first request
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
  function inTab(r, tab) {
    if (tab === 'closed') return !!r.closed_at;
    if (r.closed_at) return false;
    if (tab === 'provisional') return r.type === 'supplier';
    if (tab === 'tickets') return r.type === 'ticket';
    if (tab === 'groups') return r.type === 'group';
    if (tab === 'problem') return r.staff_status === 'problem';
    return true;
  }
  function passes(r) {
    if (state.request && r.request_id !== state.request && !(state.group && (r.folder || '').indexOf(state.group) >= 0)) return false;
    if (state.owner === 'none' && r.owner_user_id !== null) return false;
    if (state.owner && state.owner !== 'none' && String(r.owner_user_id) !== state.owner) return false;
    if (state.prio && r.priority !== state.prio) return false;
    if (state.supplier && (r.supplier || '') !== state.supplier) return false;
    if (state.month && (r.start_date || '').substr(0, 7) !== state.month) return false;
    if (state.q) {
      var hay = [r.customer, r.folder, r.supplier, r.supplier_ref, r.service, r.notes, r.source_file, r.doc_status_text].join(' ').toLowerCase();
      if (hay.indexOf(state.q.toLowerCase()) < 0) return false;
    }
    return true;
  }
  function all() { return D.rows.concat(D.closed); }
  /** Same order as followup_sort(): not taken in charge, problems, priority, service date. Closed: newest first. */
  function sortRows(list) {
    var key = function (r) {
      return [r.owner_user_id === null ? 0 : 1, r.staff_status === 'problem' ? 0 : 1, r.priority, r.service_date || r.start_date || '9999', r.id];
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
    $('fu-runs').innerHTML = parts.join(' · ') + (D.me.can_edit ? '' : ' · <strong>view only</strong>');
  }
  function renderStats() {
    var s = D.stats;
    var cards = [
      ['untaken', 'Not taken in charge', 'amber', function () { setTab('open'); state.owner = 'none'; }],
      ['urgent', 'Urgent', 'red', function () { setTab('open'); state.prio = '1-URGENT'; }],
      ['problem', 'Problem', 'red', function () { setTab('problem'); }],
      ['expiring', 'Expiring ≤ 7 days', 'amber', function () { setTab('provisional'); }],
      ['tickets', 'Tickets missing', 'navy', function () { setTab('tickets'); }],
      ['groups', 'Groups to finalize', 'navy', function () { setTab('groups'); }],
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
  function setTab(t) { state.tab = t; }
  function resetFilters() { state.owner = state.prio = state.supplier = state.month = ''; }
  function renderTabs() {
    var box = $('fu-tabs');
    box.innerHTML = '';
    TABS.forEach(function (t) {
      var n = all().filter(function (r) { return inTab(r, t.key) && passes(r); }).length;
      var b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('role', 'tab');
      b.className = state.tab === t.key ? 'active' : '';
      b.innerHTML = esc(t.label) + '<span class="n">' + n + '</span>';
      b.addEventListener('click', function () { state.tab = t.key; render(); });
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
    $('fu-f-prio').value = state.prio;
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
  /** Owner: one of the booking team (D.users = Glady / Lydia); "Take" only for them. */
  function ownerCell(r) {
    if (r.closed_at || !D.me.can_edit) return esc(r.owner_name || '—');
    var known = r.owner_user_id === null || D.users.some(function (u) { return u.id === r.owner_user_id; });
    var canTake = r.owner_user_id === null && D.users.some(function (u) { return u.id === D.me.id; });
    return '<select data-f="owner" aria-label="Owner"><option value="">— nobody —</option>' +
      (known ? '' : '<option value="' + r.owner_user_id + '" selected>' + esc(r.owner_name) + '</option>') +
      D.users.map(function (u) {
        return '<option value="' + u.id + '"' + (u.id === r.owner_user_id ? ' selected' : '') + '>' + esc(u.name) + '</option>';
      }).join('') + '</select>' + (canTake ? ' <button type="button" class="btn btn-success btn-sm" data-take="1">Take</button>' : '');
  }
  function statusCell(r) {
    if (!D.me.can_edit) return esc(STATUS[r.staff_status]);
    var opts = ['open', 'in_progress', 'problem', 'resolved'].concat(D.me.is_admin || r.staff_status === 'not_relevant' ? ['not_relevant'] : []);
    return '<select data-f="staff_status" aria-label="Status">' + opts.map(function (s) {
      return '<option value="' + s + '"' + (s === r.staff_status ? ' selected' : '') + (s === 'not_relevant' && !D.me.is_admin ? ' disabled' : '') + '>' + STATUS[s] + '</option>';
    }).join('') + '</select>';
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
    var edit = D.me.can_edit;
    var cells = [
      prioBadge(r.priority),
      '<span style="white-space:nowrap">' + dmy(svc) + '</span><span class="fu-days' + (r.days_left !== null && r.days_left <= 7 ? ' fu-late' : '') + '">' + days(r.days_left) + '</span>',
      '<span class="fu-client">' + esc(r.customer || r.folder_name) + '</span>' +
        (r.folder_open_url ? '<span class="fu-folder"><a href="' + esc(r.folder_url) + '" target="_blank" rel="noopener" title="Open in Dropbox (web)">☁</a> ' +
          '<a href="' + esc(r.folder_open_url) + '" title="Open in Windows Explorer — ' + esc(r.folder) + '">📂 ' + esc(r.folder_name) + '</a></span>' : ''),
      esc(r.type === 'ticket' ? (r.service || r.supplier || '') : (r.supplier || '')) + (r.type === 'group' && r.service ? '<span class="fu-days">' + esc(r.service) + '</span>' : '') +
        (r.supplier_ref ? '<span class="fu-ref">' + esc(r.supplier_ref) + '</span>' : ''),
      '<span class="badge badge-issue' + issueCls + '">' + esc(ISSUE[r.issue] || r.issue) + '</span>',
      esc(r.doc_status_text || '') + (r.booked_by ? '<span class="fu-days">booked by ' + esc(r.booked_by) + '</span>' : '') +
        (r.expiry_date ? '<span class="fu-days' + (r.expired ? ' fu-late' : '') + '" style="white-space:nowrap">' + (r.expired ? 'expired ' : 'expires ') + dmy(r.expiry_date) + '</span>' : ''),
      r.file_open_url ? '<span class="fu-folder fu-file"><a href="' + esc(r.file_url) + '" target="_blank" rel="noopener" title="Open in Dropbox (web)">☁</a> ' +
        '<a href="' + esc(r.file_open_url) + '" title="Open on this PC — ' + esc(r.source_file) + '">📄 ' + esc(r.source_file.replace(/^invoices\//, '')) + '</a></span>'
      : r.file_url ? '<a href="' + esc(r.file_url) + '" target="_blank" rel="noopener" title="' + esc(r.source_file) + '">📄 ' + esc(r.source_file.replace(/^invoices\//, '')) + '</a>' : '',
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
      pending[tr.dataset.id] = ['owner', 'staff_status', 'notes'].map(function (f) { var el = field(tr, f); return el ? [f, el.value] : null; }).filter(Boolean);
    });
    var list = sortRows(all().filter(function (r) { return inTab(r, state.tab) && passes(r); }));
    var head = ['Priority', state.tab === 'groups' ? 'Arrival' : 'Service', 'Client / folder',
                state.tab === 'tickets' ? 'Flight' : state.tab === 'groups' ? 'Group' : 'Supplier',
                'Issue', state.tab === 'groups' ? 'What we found' : 'Document status',
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
    var d = r.details;
    if (!d) return '';
    var rows = [];
    var add = function (label, list) { (list || []).forEach(function (x) { rows.push('<li><b>' + esc(label) + '</b>' + esc(x) + '</li>'); }); };
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
  [['fu-f-owner', 'owner'], ['fu-f-prio', 'prio'], ['fu-f-supplier', 'supplier'], ['fu-f-month', 'month']].forEach(function (p) {
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
                   (s.units !== undefined ? ' · ' + s.units_complete + '/' + s.units + ' folders read' : '') +
                   (s.warning ? '<br>⚠ ' + esc(s.warning) : '') + (s.hint ? '<br>' + esc(s.hint) : '') +
                   (s.errors && s.errors.length ? '<br>⚠ ' + s.errors.length + ' folder(s) not read: ' + esc(s.errors.map(function (e) { return e.folder + ' (' + e.error + ')'; }).join('; ')) : ''));
      });
      msg.innerHTML = '✓ ' + lines.join('<br>');
      syncFilters(); render();
    }).catch(function (e) { runBtn.disabled = false; msg.className = 'fu-msg err'; msg.textContent = '⚠ ' + e.message; });
  });

  if (state.request && !D.rows.some(function (r) { return r.request_id === state.request; })) {
    if (D.closed.some(function (r) { return r.request_id === state.request; })) state.tab = 'closed';
  }
  syncFilters();
  render();
})();
