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
  function ownerCell(r) {
    if (r.closed_at || !D.me.can_edit) return esc(r.owner_name || '—');
    if (D.me.is_admin) {
      return '<select data-f="owner" aria-label="Owner"><option value="">— nobody —</option>' + D.users.map(function (u) {
        return '<option value="' + u.id + '"' + (u.id === r.owner_user_id ? ' selected' : '') + '>' + esc(u.name) + '</option>';
      }).join('') + '</select>' + (r.owner_user_id === null ? ' <button type="button" class="btn btn-success btn-sm" data-take="1">Take</button>' : '');
    }
    if (r.owner_user_id === null) return '<button type="button" class="btn btn-success btn-sm" data-take="1">Take in charge</button>';
    return esc(r.owner_name) + (r.owner_user_id === D.me.id ? ' <button type="button" class="fu-hbtn" data-release="1" title="Release this line">✕</button>' : '');
  }
  function statusCell(r) {
    if (!D.me.can_edit) return esc(STATUS[r.staff_status]);
    var opts = ['open', 'in_progress', 'problem', 'resolved'].concat(D.me.is_admin || r.staff_status === 'not_relevant' ? ['not_relevant'] : []);
    return '<select data-f="staff_status" aria-label="Status">' + opts.map(function (s) {
      return '<option value="' + s + '"' + (s === r.staff_status ? ' selected' : '') + (s === 'not_relevant' && !D.me.is_admin ? ' disabled' : '') + '>' + STATUS[s] + '</option>';
    }).join('') + '</select>';
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
        (r.folder_url ? '<a class="fu-folder" href="' + esc(r.folder_url) + '" target="_blank" rel="noopener" title="' + esc(r.folder) + '">📁 ' + esc(r.folder_name) + '</a>' : ''),
      esc(r.type === 'ticket' ? (r.service || r.supplier || '') : (r.supplier || '')) + (r.type === 'group' && r.service ? '<span class="fu-days">' + esc(r.service) + '</span>' : ''),
      esc(r.supplier_ref || ''),
      '<span class="badge badge-issue' + issueCls + '">' + esc(ISSUE[r.issue] || r.issue) + '</span>',
      esc(r.doc_status_text || '') + (r.booked_by ? '<span class="fu-days">booked by ' + esc(r.booked_by) + '</span>' : ''),
      r.expiry_date ? '<span class="' + (r.expired ? 'fu-late' : '') + '" style="white-space:nowrap">' + dmy(r.expiry_date) + '</span>' : '',
      r.file_url ? '<a href="' + esc(r.file_url) + '" target="_blank" rel="noopener" title="' + esc(r.source_file) + '">📄 ' + esc(r.source_file.replace(/^invoices\//, '')) + '</a>' : '',
      ownerCell(r),
      edit ? '<input type="date" data-f="contacted_at" value="' + esc(r.contacted_at || '') + '" aria-label="Contacted">' : esc(dmy(r.contacted_at)),
      statusCell(r),
      edit ? '<textarea data-f="notes" aria-label="Notes" placeholder="Notes…">' + esc(r.notes || '') + '</textarea>' : esc(r.notes || ''),
      state.tab === 'closed'
        ? '<span style="white-space:nowrap">' + dmyhm(r.closed_at) + '</span><span class="fu-days">' + esc(r.close_reason_label || '') + ' — ' + esc(r.closed_by || '') + '</span>'
        : '<span style="white-space:nowrap">' + dmy(r.last_seen_at) + '</span>',
      '<button type="button" class="fu-hbtn" data-hist="1" title="History">🕘 ' + r.events_count + '</button>'
    ];
    tr.innerHTML = cells.map(function (c) { return '<td>' + c + '</td>'; }).join('');
    return tr;
  }
  function renderTable() {
    var t = $('fu-table');
    var list = sortRows(all().filter(function (r) { return inTab(r, state.tab) && passes(r); }));
    var head = ['Priority', state.tab === 'groups' ? 'Arrival' : 'Service', 'Client / folder',
                state.tab === 'tickets' ? 'Flight' : state.tab === 'groups' ? 'Group' : 'Supplier',
                'Ref', 'Issue', state.tab === 'groups' ? 'What we found' : 'Document status',
                'Expiry', 'File', 'Owner', 'Contacted', 'Status', 'Notes', state.tab === 'closed' ? 'Closed' : 'Last check', ''];
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
    return post('update', change).then(function (d) {
      if (!d.ok) { mark('<span class="fu-err">' + esc(d.error || 'Not saved') + '</span>'); return false; }
      if (d.row) {
        replaceRow(d.row);
        var keep = state.tab;
        renderTabs();
        if (d.closed || d.reopened || change.take || 'owner' in change || 'staff_status' in change) { state.tab = keep; renderTable(); }
        else mark('<span class="fu-saved">✓ saved</span>');
      }
      return true;
    }).catch(function (e) { mark('<span class="fu-err">' + esc(e.message) + '</span>'); return false; });
  }

  $('fu-table').addEventListener('click', function (ev) {
    var b = ev.target.closest('button');
    if (!b) return;
    var tr = b.closest('tr[data-id]');
    if (!tr) return;
    var id = parseInt(tr.dataset.id, 10);
    if (b.dataset.take) save(tr, {take: true}, b.parentNode);
    else if (b.dataset.release) save(tr, {owner: null}, b.parentNode);
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
  $('fu-table').addEventListener('change', function (ev) {
    var el = ev.target, f = el.dataset.f;
    if (!f || f === 'notes') return;
    var tr = el.closest('tr[data-id]');
    var change = {};
    if (f === 'owner') change.owner = el.value === '' ? null : parseInt(el.value, 10);
    else change[f] = el.value;
    if (f === 'staff_status' && el.value === 'problem') {
      var notes = tr.querySelector('textarea[data-f=notes]');
      if (notes && notes.value.trim() === '') {
        var cell = el.parentNode, old = cell.querySelector('.fu-err'); if (old) old.remove();
        cell.insertAdjacentHTML('beforeend', '<span class="fu-err">Write a note first, then choose Problem</span>');
        var r = all().filter(function (x) { return x.id === parseInt(tr.dataset.id, 10); })[0];
        el.value = r ? r.staff_status : 'open';
        notes.focus();
        return;
      }
      change.notes = notes ? notes.value : undefined;
    }
    save(tr, change, el.parentNode);
  });
  $('fu-table').addEventListener('focusout', function (ev) {
    var el = ev.target;
    if (el.dataset.f !== 'notes') return;
    var tr = el.closest('tr[data-id]');
    var r = all().filter(function (x) { return x.id === parseInt(tr.dataset.id, 10); })[0];
    if (r && (r.notes || '') !== el.value.trim()) save(tr, {notes: el.value}, el.parentNode);
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
