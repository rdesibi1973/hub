/* booking_mail.js — Operations → Booking mail. Mail text and document data only ever go in through textContent / value. */
(function () {
  'use strict';
  var API = 'api/booking_mail.php';
  var $ = function (id) { return document.getElementById(id); };

  function el(tag, props, kids) {
    var e = document.createElement(tag);
    Object.keys(props || {}).forEach(function (k) {
      if (k === 'text') e.textContent = props[k];
      else if (k === 'cls') e.className = props[k];
      else if (k === 'on') Object.keys(props.on).forEach(function (ev) { e.addEventListener(ev, props.on[ev]); });
      else e[k] = props[k];
    });
    (kids || []).forEach(function (c) { if (c) e.appendChild(c); });
    return e;
  }

  function note(text, err) {
    var m = $('bm-msg');
    m.hidden = !text;
    m.textContent = text || '';
    m.className = 'bm-note' + (err ? ' err' : '');
  }

  function call(action, body, query) {
    var opts = body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {};
    return fetch(API + '?action=' + action + (query || ''), opts).then(function (r) {
      return r.json().catch(function () { throw new Error('Server error (' + r.status + ')'); });
    }).then(function (d) {
      if (!d.ok) throw new Error(d.error || 'Error');
      return d;
    });
  }

  function fail(e) { note(e.message, true); }

  // ── Tabs ────────────────────────────────────────────────────────────────────
  var tabs = document.querySelectorAll('.bm-tabs button');
  function show(name) {
    tabs.forEach(function (b) { b.classList.toggle('active', b.dataset.tab === name); });
    ['check', 'passports'].forEach(function (t) { var s = $('tab-' + t); if (s) s.hidden = t !== name; });
    if (name === 'check' && BM.live) loadCheck();
  }
  tabs.forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.tab); }); });

  // ── Mail check ──────────────────────────────────────────────────────────────
  var current = null;

  function loadCheck() {
    call('check').then(function (d) {
      var run = d.last_run;
      $('bm-run').textContent = run ? 'Last read: ' + run.ran_at + (Number(run.ok) ? '' : ' — FAILED: ' + run.error) + '.' : 'Never read yet.';
      var t = $('bm-table');
      t.textContent = '';
      t.appendChild(el('thead', {}, [el('tr', {}, ['Status', 'Client', 'Supplier', 'Service', 'Issue', 'Owner', 'Contacted', 'Mails', ''].map(function (h) { return el('th', { text: h }); }))]));
      var body = el('tbody');
      d.lines.forEach(function (l) {
        body.appendChild(el('tr', {}, [
          el('td', {}, [el('span', { cls: 'bm-sig ' + l.signal, text: l.signal_label })]),
          el('td', { text: l.client || '' }), el('td', { text: (l.supplier || '') + (l.ref ? ' · ' + l.ref : '') }),
          el('td', { text: l.service_date || '' }), el('td', { text: l.issue || '' }), el('td', { text: l.owner || '' }),
          el('td', { text: l.contacted_at || '' }), el('td', { text: String(l.mails) }),
          el('td', {}, [l.last_key ? el('button', { type: 'button', cls: 'btn btn-outline btn-sm', text: 'Thread', on: { click: function () { pick(l); } } }) : null])
        ]));
      });
      t.appendChild(body);
    }).catch(fail);
  }

  function pick(line) {
    current = line;
    $('bm-thread').hidden = false;
    $('bm-reason-box').hidden = false;
    $('bm-reply-box').hidden = true;
    $('bm-messages').textContent = '';
    $('bm-reason').value = '';
    $('bm-thread-title').textContent = line.last_subject || '(no subject)';
    $('bm-thread').scrollIntoView({ behavior: 'smooth' });
  }

  function openThread() {
    var reason = $('bm-reason').value;
    call('thread', { key: current.last_key, reason: reason }).then(function (d) {
      note('');
      var box = $('bm-messages');
      box.textContent = '';
      d.messages.forEach(function (m) {
        var atts = (m.attachments || []).map(function (a) {
          return el('a', { text: a.name + ' (' + Math.round(a.size / 1024) + ' KB)  ', href: API + '?action=attachment&key=' + encodeURIComponent(m.msg_key) + '&part=' + encodeURIComponent(a.part) + '&reason=' + encodeURIComponent(reason) });
        });
        box.appendChild(el('div', { cls: 'bm-msg' }, [
          el('div', { cls: 'h', text: m.date_utc + ' UTC · ' + m.from + ' → ' + m.to + ' · in ' + m.mailbox }),
          el('pre', { text: m.readable ? m.body : '(this mail can no longer be read)' }),
          atts.length ? el('div', { cls: 'h', style: 'margin:8px 0 0' }, atts) : null,
          m.readable && m.direction === 'client' ? proofBox(m, reason) : null   // proof = a mail received from the supplier
        ]));
      });
      $('bm-reason-box').hidden = true;
      $('bm-reply-box').hidden = false;
      $('bm-send').disabled = true;
      $('bm-preview-out').textContent = '';
      current.reason = reason;
    }).catch(fail);
  }

  /**
   * "Save as proof & set expiry": the supplier confirmed a longer hold in this mail → it is saved as .eml in the booking's
   * mails/ and the follow-up line's expiry is set to that date (api proof → followup_set_expiry(), same rules as the
   * follow-up page). Check first (dry run: name, passport attachments removed), then Save.
   */
  function proofBox(m, reason) {
    var line = current;   // the line whose thread was opened (another Thread click changes current)
    var date = el('input', { type: 'date' });
    var name = el('input', { type: 'text', maxLength: 150, placeholder: 'empty: AAAA-MM-GG_' + (line.supplier || 'Supplier') + '_' + (line.ref || 'Ref') + ' extension to DD-MM' });
    var out = el('div', { cls: 'bm-sub', style: 'margin:8px 0 0;white-space:pre-wrap' });
    var check = el('button', { type: 'button', cls: 'btn btn-outline btn-sm', text: 'Check' });
    var save = el('button', { type: 'button', cls: 'btn btn-primary btn-sm', text: 'Save proof & set expiry', disabled: true });
    var form = el('div', { cls: 'bm-form bm-proof', hidden: true }, [
      el('div', { cls: 'bm-sub', style: 'margin:0', text: 'Line: ' + (line.supplier || '') + (line.ref ? ' · ' + line.ref : '') + ' — ' + (line.client || '') +
                                                        '. The mail goes into the booking folder mails/ (passport attachments removed).' }),
      el('label', { text: 'New expiry confirmed in this mail' }), date,
      el('label', { text: 'Save as (mails/, .eml added)' }), name,
      el('div', { cls: 'bm-actions' }, [check, save]), out
    ]);
    var open = el('button', { type: 'button', cls: 'btn btn-outline btn-sm', text: '📅 Save as proof & set expiry',
                              title: 'The supplier extended the hold in this mail: keep it as proof and set the new expiry on the follow-up line',
                              on: { click: function () { form.hidden = !form.hidden; } } });
    var body = function (confirm) {
      return { key: m.msg_key, line_id: line.id, date: date.value, save_as: name.value, reason: reason, confirm: confirm };
    };
    [date, name].forEach(function (i) { i.addEventListener('input', function () { save.disabled = true; out.textContent = ''; }); });
    check.addEventListener('click', function () {
      if (!date.value) return note('Choose the new expiry date the supplier confirmed.', true);
      call('proof', body(false)).then(function (d) {
        note('');
        var s = d.saved || {};
        out.textContent = 'Will save: ' + (s.path || '') + (s.removed_attachments && s.removed_attachments.length ? '\nPassport attachments removed: ' + s.removed_attachments.join(', ') : '') +
                          '\nExpiry of the line: ' + date.value + ' (by mail).';
        save.disabled = false;
      }).catch(fail);
    });
    save.addEventListener('click', function () {
      save.disabled = true;
      call('proof', body(true)).then(function (d) {
        var s = d.saved || {};
        note('Saved ' + (s.proof || '') + ' and set the expiry of ' + (line.supplier || 'the line') + ' to ' + date.value + ' (by mail).');
        out.textContent = '✓ Saved: ' + (s.saved_to || s.path || '');
        open.disabled = true;
      }).catch(function (e) { save.disabled = false; fail(e); });
    });
    return el('div', { style: 'margin-top:10px' }, [open, form]);
  }

  function reply(confirm) {
    return call('reply', { key: current.last_key, body: $('bm-reply').value, reason: current.reason, confirm: confirm });
  }

  if ($('bm-open')) {
    $('bm-open').addEventListener('click', openThread);
    $('bm-close').addEventListener('click', function () { $('bm-thread').hidden = true; });
    $('bm-reply').addEventListener('input', function () { $('bm-send').disabled = true; });
    $('bm-preview').addEventListener('click', function () {
      reply(false).then(function (d) {
        $('bm-preview-out').textContent = 'From: ' + d.from + '\nTo: ' + d.to.join(', ') + '\nSubject: ' + d.subject + '\n\n' + d.body;
        $('bm-send').disabled = false;
      }).catch(fail);
    });
    $('bm-send').addEventListener('click', function () {
      $('bm-send').disabled = true;
      reply(true).then(function (d) {
        note('Sent from ' + d.from + ' to ' + d.to.join(', ') + (d.warning ? '. ' + d.warning : ''));
        $('bm-reply').value = '';
        $('bm-preview-out').textContent = '';
      }).catch(fail);
    });
    $('bm-scan').addEventListener('click', function () {
      call('scan', {}).then(function (d) {
        note(d.run.ok ? d.run.added + ' new mails read.' : 'Not read: ' + d.run.error, !d.run.ok);
        loadCheck();
      }).catch(fail);
    });
  }

  // ── Passports ───────────────────────────────────────────────────────────────
  var guests = [], cards = [], requestId = 0;

  function loadPassports() {
    requestId = parseInt($('bm-req').value, 10) || 0;
    if (!requestId) return note('Write the request number.', true);
    return call('passports', null, '&request_id=' + requestId).then(function (d) {
      note('');
      guests = d.guests;
      cards = [];
      $('bm-rows').textContent = '';
      $('bm-apply-box').hidden = true;
      $('bm-pp-head').textContent = (d.customer || '') + ' · ' + d.calc + ' · sheet ' + d.sheet + ' · trip starts ' + d.start;
      var box = $('bm-sources');
      box.textContent = '';
      if (!d.sources.length) box.appendChild(el('div', { cls: 'bm-sub', text: 'No passports found in the folder or in the mails linked to this request.' }));
      d.sources.forEach(function (s) {
        var btn = el('button', { type: 'button', cls: 'btn btn-outline btn-sm', text: 'Read' });
        btn.addEventListener('click', function () { read(s, btn); });
        box.appendChild(el('div', { cls: 'bm-src' }, [el('span', { text: s.label + ' — ' + s.where }), btn]));
      });
    }).catch(fail);
  }

  function read(source, btn) {
    btn.disabled = true;
    btn.textContent = 'Reading…';
    call('passport_read', { request_id: requestId, src: source.src }).then(function (d) {
      btn.textContent = 'Read';
      btn.disabled = false;
      note('');
      if (!d.is_passport) return note(source.label + ' does not look like a passport data page.', true);
      addCard(d);
    }).catch(function (e) { btn.textContent = 'Read'; btn.disabled = false; fail(e); });
  }

  function field(label, value) {
    var input = el('input', { type: 'text', value: value || '' });
    return { input: input, node: el('div', {}, [el('label', { text: label }), input]) };
  }

  function bestRow(surname) {
    var s = (surname || '').toUpperCase();
    var taken = cards.map(function (c) { return String(c.row.value); });
    var free = guests.filter(function (g) { return taken.indexOf(String(g.row)) < 0; });
    var byName = free.filter(function (g) { return s && g.name.toUpperCase().indexOf(s) >= 0; })[0];
    var blank = free.filter(function (g) { return !g.name || /^(tba|tbc|tbd)/i.test(g.name.trim()); })[0];
    return (byName || blank || free[0] || guests[0]).row;
  }

  function addCard(d) {
    var r = d.row;
    var f = { name: field('Name (first name + surname)', r.name), title: field('MR / MRS / MS', r.title), dob: field('Date of birth', r.dob),
              age: field('Age (13 TEEN, 9 CHILD)', r.age_cell), passport: field('Passport number', r.passport), country: field('Country', r.country) };
    var row = el('select', {}, guests.map(function (g) {
      return el('option', { value: g.row, text: 'Row ' + g.row + ': ' + (g.name || '(empty)') + (g.has_passport ? ' — has a number' : '') });
    }));
    row.value = String(bestRow(r.surname));
    var replace = el('input', { type: 'checkbox', style: 'width:auto' });
    var ok = el('input', { type: 'checkbox', style: 'width:auto' });
    var preview = d.mime === 'application/pdf' ? el('iframe', { src: d.preview, title: 'Passport' }) : el('img', { src: d.preview, alt: 'Passport' });
    var card = { row: row, f: f, replace: replace, ok: ok, src: d.src, category: r.category };
    var remove = el('button', { type: 'button', cls: 'btn btn-outline btn-sm', text: 'Discard' });
    var node = el('div', { cls: 'bm-pp' }, [
      el('div', {}, [preview]),
      el('div', { cls: 'bm-form' }, [
        r.verified ? el('div', { cls: 'bm-sub', text: 'Check digits agree.' }) : null
      ].filter(Boolean).concat(r.warnings.map(function (w) { return el('div', { cls: 'bm-note err', text: w }); }))
       .concat(Object.keys(f).map(function (k) { return f[k].node; }))
       .concat([el('label', { text: 'Calc row' }), row,
                el('label', {}, [replace, document.createTextNode(' Replace the number already in this row')]),
                el('label', {}, [ok, document.createTextNode(' I compared every field with the document')]),
                el('div', { cls: 'bm-actions' }, [remove])]))
    ]);
    remove.addEventListener('click', function () { cards.splice(cards.indexOf(card), 1); node.remove(); $('bm-apply-box').hidden = !cards.length; });
    cards.push(card);
    $('bm-rows').appendChild(node);
    $('bm-apply-box').hidden = false;
  }

  function category(age) {
    var a = String(age).toUpperCase();
    return a.indexOf('TEEN') >= 0 ? 'teen' : a.indexOf('CHILD') >= 0 ? 'child' : 'adult';
  }

  function apply() {
    if (cards.some(function (c) { return !c.ok.checked; })) return note('Tick "I compared every field" on every passport first.', true);
    var rows = cards.map(function (c) {
      return { row: parseInt(c.row.value, 10), name: c.f.name.input.value, title: c.f.title.input.value, dob: c.f.dob.input.value, age_cell: c.f.age.input.value,
               passport: c.f.passport.input.value, country: c.f.country.input.value, replace: c.replace.checked, src: c.src, category: category(c.f.age.input.value) };
    });
    $('bm-apply').disabled = true;
    call('passport_apply', { request_id: requestId, rows: rows }).then(function (d) {
      $('bm-apply').disabled = false;
      var done = d.written + ' guest(s) written in ' + d.calc + '. Files saved in passports/: ' + (d.saved.join(', ') || 'none new') + '.' + (d.pax_updated ? '' : ' ADULTS/TEEN/CHD not rewritten: not every guest was in this batch.');
      return loadPassports().then(function () { note(done); });
    }).catch(function (e) { $('bm-apply').disabled = false; fail(e); });
  }

  $('bm-load').addEventListener('click', function () { if (BM.live) loadPassports(); });
  $('bm-apply').addEventListener('click', apply);

  show(BM.admin ? 'check' : 'passports');
  if (BM.request_id && BM.live) { show('passports'); loadPassports(); }
})();
