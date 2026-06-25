// modules/memo/memo.js
// Conventions: var + string concatenation, no const/let at top scope.

(function () {
  'use strict';

  var AJAX    = 'ajax.php';
  var board   = document.getElementById('memoBoard');
  var modal   = document.getElementById('memoModal');
  var dragId  = null;
  var memoQuill = null;

  // ---------- Quill ----------
  function initQuill() {
    memoQuill = new Quill('#m_body_editor', {
      theme: 'snow',
      placeholder: 'Notes (optional)…',
      modules: { toolbar: [
        ['bold','italic','underline'],
        [{'list':'ordered'},{'list':'bullet'}],
        ['link','clean'],
        [{'color':[]},{'background':[]}]
      ]}
    });
    memoQuill.on('text-change', function () {
      document.getElementById('m_body').value = memoQuill.root.innerHTML;
    });
  }

  // "send mail" checkbox shows/hides reminder fields
  document.getElementById('m_send_mail').addEventListener('change', function () {
    var fields = document.getElementById('m_reminder_fields');
    fields.classList.toggle('visible', this.checked);
    if (this.checked && !document.getElementById('m_reminder_date').value) {
      var due = document.getElementById('m_due_date').value;
      document.getElementById('m_reminder_date').value = due || todayStr();
    }
  });

  function todayStr() {
    var d = new Date();
    return d.getFullYear() + '-' +
      pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }
  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  // ---------- AJAX ----------
  function post(action, data, cb) {
    var fd = new FormData();
    fd.append('action', action);
    for (var k in data) {
      if (!data.hasOwnProperty(k)) { continue; }
      if (Object.prototype.toString.call(data[k]) === '[object Array]') {
        for (var i = 0; i < data[k].length; i++) { fd.append(k + '[]', data[k][i]); }
      } else { fd.append(k, data[k]); }
    }
    var xhr = new XMLHttpRequest();
    xhr.open('POST', AJAX, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState === 4) {
        var res = null;
        try { res = JSON.parse(xhr.responseText); } catch (e) {}
        if (cb) { cb(res); }
      }
    };
    xhr.send(fd);
  }

  function get(action, params, cb) {
    var url = AJAX + '?action=' + encodeURIComponent(action);
    for (var k in params) {
      if (params.hasOwnProperty(k)) { url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }
    }
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState === 4) {
        var res = null;
        try { res = JSON.parse(xhr.responseText); } catch (e) {}
        if (cb) { cb(res); }
      }
    };
    xhr.send();
  }

  function esc(s) {
    if (s === null || s === undefined) { return ''; }
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  // ---------- load & render ----------
  function load() {
    post('list', {}, function (res) {
      if (!res || !res.ok) {
        board.innerHTML = '<p class="memo-empty">Could not load memos.</p>'; return;
      }
      render(res.memos || []);
    });
  }

  function todayDate() {
    var d = new Date(); d.setHours(0,0,0,0); return d;
  }

  function render(memos) {
    board.innerHTML = '';
    if (memos.length === 0) {
      board.innerHTML = '<p class="memo-empty">No memos yet. Click &ldquo;+ New memo&rdquo; to start.</p>';
      return;
    }

    var todaySt = todayStr();

    var overdue = [], todayArr = [], upcoming = [], nodate = [];
    for (var i = 0; i < memos.length; i++) {
      var m = memos[i];
      if (!m.due_date) { nodate.push(m); continue; }
      if (m.due_date < todaySt && m.status !== 'done') { overdue.push(m); }
      else if (m.due_date === todaySt) { todayArr.push(m); }
      else { upcoming.push(m); }
    }

    renderGroup('Forever',   nodate,   'nodate');
    renderGroup('Expired',  overdue,  'overdue');
    renderGroup('Today',    todayArr, 'today');
    renderGroup('Next days',upcoming, 'upcoming');
  }

  function renderGroup(label, memos, cls) {
    if (memos.length === 0) { return; }
    var wrap = document.createElement('div');
    wrap.className = 'memo-group-' + cls;
    if (label) {
      var hdr = document.createElement('div');
      hdr.className = 'memo-group-label';
      hdr.textContent = label;
      wrap.appendChild(hdr);
    }
    var row = document.createElement('div');
    row.className = 'memo-group-row';
    for (var i = 0; i < memos.length; i++) { row.appendChild(buildCard(memos[i])); }
    wrap.appendChild(row);
    board.appendChild(wrap);
  }

  function buildCard(m) {
    var isOwner  = Number(m.is_owner)  === 1;
    var canEdit  = Number(m.can_edit)  === 1;
    var isShared = Number(m.is_shared) === 1;
    var isOverdue = m.due_date && m.due_date < todayStr() && m.status !== 'done';

    var card = document.createElement('div');
    card.className = 'memo-card prio-' + esc(m.priority) +
      (m.status === 'done'       ? ' is-done'    : '') +
      (Number(m.pinned) === 1    ? ' is-pinned'  : '') +
      (isOverdue                 ? ' is-overdue' : '');
    card.setAttribute('draggable', isOwner ? 'true' : 'false');
    card.setAttribute('data-id', m.id);
    if (m.color) { card.style.background = m.color; }

    var pinTxt = Number(m.pinned) === 1 ? '★' : '☆';
    var html = '';
    html += '<div class="memo-card-head">';
    if (isOwner) {
      html += '<button class="memo-pin" data-act="pin">' + pinTxt + '</button>';
      html += '<button class="memo-share-btn" data-act="share">Share</button>';
    } else {
      html += '<span style="font-size:.68rem;color:#888;">di ' + esc(m.owner_name || '') + '</span>';
    }
    if (isShared && isOwner) {
      html += '<span class="memo-share-badge">Condivisa</span>';
    }
    html += '</div>';

    html += '<div class="memo-card-title">' + esc(m.title) + '</div>';
    if (m.body) { html += '<div class="memo-card-body">' + m.body + '</div>'; }

    var meta = '';
    if (m.due_date) {
      var dueCls = isOverdue ? ' memo-meta-overdue' : '';
      meta += '<span class="memo-meta-item' + dueCls + '">' +
        (isOverdue ? '⚠ ' : '') + 'Due ' + esc(m.due_date) + '</span>';
    }
    if (m.reminder_at) {
      var rec = (m.recur_rule && m.recur_rule !== 'none') ? ' (' + esc(m.recur_rule) + ')' : '';
      meta += '<span class="memo-meta-item">✉ ' + esc(m.reminder_at.substring(0,16)) + rec + '</span>';
    }
    if (meta) { html += '<div class="memo-card-meta">' + meta + '</div>'; }

    html += '<div class="memo-card-actions">';
    if (canEdit) {
      if (m.status !== 'done') {
        html += '<button class="memo-mini" data-act="done">✓ Done</button>';
      } else {
        html += '<button class="memo-mini" data-act="reopen">Reopen</button>';
      }
      html += '<button class="memo-mini" data-act="edit">Edit</button>';
    }
    if (isOwner) {
      html += '<button class="memo-mini memo-mini-del" data-act="delcard">Delete</button>';
    }
    html += '</div>';

    card.innerHTML = html;

    // wire up pin
    var pinBtn = card.querySelector('[data-act="pin"]');
    if (pinBtn) { pinBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      post('toggle_pin', { id: m.id }, function () { load(); });
    }); }

    // wire up share
    var shareBtn = card.querySelector('[data-act="share"]');
    if (shareBtn) { shareBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      memoShareOpen(m.id);
    }); }

    var doneBtn = card.querySelector('[data-act="done"]');
    if (doneBtn) { doneBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      post('set_status', { id: m.id, status: 'done' }, function () { load(); });
    }); }
    var reopenBtn = card.querySelector('[data-act="reopen"]');
    if (reopenBtn) { reopenBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      post('set_status', { id: m.id, status: 'open' }, function () { load(); });
    }); }
    var editBtn = card.querySelector('[data-act="edit"]');
    if (editBtn) { editBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      openModal(m);
    }); }
    var delCardBtn = card.querySelector('[data-act="delcard"]');
    if (delCardBtn) { delCardBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (!confirm('Eliminare questo memo?')) { return; }
      post('delete', { id: m.id }, function () { load(); });
    }); }

    if (isOwner) {
      card.addEventListener('dragstart', function () { dragId = m.id; card.classList.add('dragging'); });
      card.addEventListener('dragend',   function () { card.classList.remove('dragging'); dragId = null; });
    }
    card.addEventListener('dragover',  function (e) { e.preventDefault(); });
    card.addEventListener('drop', function (e) {
      e.preventDefault();
      if (dragId === null || String(dragId) === String(m.id)) { return; }
      reorderDrop(dragId, m.id);
    });

    return card;
  }

  function reorderDrop(fromId, toId) {
    var cards = board.querySelectorAll('.memo-card');
    var ids = [];
    for (var i = 0; i < cards.length; i++) { ids.push(cards[i].getAttribute('data-id')); }
    var fi = ids.indexOf(String(fromId));
    if (fi > -1) { ids.splice(fi, 1); }
    var ti = ids.indexOf(String(toId));
    if (ti < 0) { ti = ids.length; }
    ids.splice(ti, 0, String(fromId));
    post('reorder', { ids: ids }, function () { load(); });
  }

  // ---------- memo modal ----------
  function openModal(m) {
    var isOwner = m ? Number(m.is_owner) === 1 : true;
    document.getElementById('memoModalTitle').textContent = m ? 'Edit memo' : 'New memo';
    document.getElementById('m_id').value       = m ? m.id : '';
    document.getElementById('m_title').value    = m ? (m.title    || '') : '';
    document.getElementById('m_priority').value = m ? (m.priority || 'normal') : 'normal';
    document.getElementById('m_color').value    = m && m.color ? m.color : '';
    document.getElementById('m_due_date').value = m && m.due_date ? m.due_date : '';

    var bodyHtml = m ? (m.body || '') : '';
    document.getElementById('m_body').value = bodyHtml;
    if (memoQuill) {
      memoQuill.setContents([]);
      if (bodyHtml) { memoQuill.clipboard.dangerouslyPasteHTML(0, bodyHtml); }
    }

    var hasReminder = m && m.reminder_at;
    var chk = document.getElementById('m_send_mail');
    chk.checked = !!hasReminder;
    document.getElementById('m_reminder_fields').classList.toggle('visible', !!hasReminder);

    var rDate = '', rHour = '09', rMin = '00', rRecur = 'none';
    if (hasReminder) {
      var parts = m.reminder_at.split(' ');
      rDate = parts[0] || '';
      var tp = (parts[1] || '09:00').split(':');
      rHour = tp[0] || '09';
      var rawMin = parseInt(tp[1] || '0', 10);
      var rounded = Math.round(rawMin / 15) * 15;
      if (rounded >= 60) { rounded = 45; }
      rMin = pad(rounded);
      rRecur = m.recur_rule || 'none';
    }
    document.getElementById('m_reminder_date').value = rDate;
    document.getElementById('m_reminder_hour').value = rHour;
    document.getElementById('m_reminder_min').value  = rMin;
    document.getElementById('m_recur_rule').value    = rRecur;

    // delete only for owner
    document.getElementById('memoDeleteBtn').style.display = (m && isOwner) ? 'inline-block' : 'none';
    modal.style.display = 'flex';
    document.getElementById('m_title').focus();
  }

  function closeModal() { modal.style.display = 'none'; }

  function save() {
    var id    = document.getElementById('m_id').value;
    var title = document.getElementById('m_title').value.replace(/^\s+|\s+$/g, '');
    if (title === '') { alert('Title is required.'); return; }

    if (memoQuill) {
      document.getElementById('m_body').value = memoQuill.root.innerHTML;
    }

    var reminderAt = '';
    if (document.getElementById('m_send_mail').checked) {
      var rDate = document.getElementById('m_reminder_date').value;
      if (rDate) {
        var rHour = document.getElementById('m_reminder_hour').value;
        var rMin  = document.getElementById('m_reminder_min').value;
        reminderAt = rDate + ' ' + rHour + ':' + rMin;
      }
    }

    var data = {
      title:       title,
      body:        document.getElementById('m_body').value,
      priority:    document.getElementById('m_priority').value,
      color:       document.getElementById('m_color').value,
      due_date:    document.getElementById('m_due_date').value,
      reminder_at: reminderAt,
      recur_rule:  document.getElementById('m_recur_rule').value
    };
    if (id) { data.id = id; }

    post(id ? 'update' : 'create', data, function (res) {
      if (!res || !res.ok) { alert(res && res.error ? res.error : 'Save failed.'); return; }
      closeModal();
      load();
    });
  }

  function del() {
    var id = document.getElementById('m_id').value;
    if (!id || !confirm('Delete this memo?')) { return; }
    post('delete', { id: id }, function () { closeModal(); load(); });
  }

  document.getElementById('memoNewBtn').addEventListener('click',   function () { openModal(null); });
  document.getElementById('memoSaveBtn').addEventListener('click',  save);
  document.getElementById('memoCancelBtn').addEventListener('click', closeModal);
  document.getElementById('memoDeleteBtn').addEventListener('click', del);
  modal.addEventListener('click', function (e) { if (e.target === modal) { closeModal(); } });

  document.getElementById('memoHelpBtn').addEventListener('click', function () {
    var panel = document.getElementById('memoHelpPanel');
    panel.classList.toggle('visible');
    this.style.color = panel.classList.contains('visible') ? 'var(--red)' : '';
    this.style.borderColor = panel.classList.contains('visible') ? 'var(--red)' : '';
  });

  // ---------- share modal ----------
  var shareModal   = document.getElementById('memoShareModal');
  var allUsers     = [];   // populated once on first open

  function memoShareOpen(memoId) {
    document.getElementById('share_memo_id').value = memoId;
    document.getElementById('share_all_on').checked   = false;
    document.getElementById('share_all_edit').checked = false;
    document.getElementById('shareUserList').innerHTML = '<p style="color:#aaa;font-size:.8rem;">Caricamento…</p>';
    shareModal.style.display = 'flex';

    // load users list if not yet loaded, then load current shares
    function loadShares() {
      get('get_shares', { id: memoId }, function (res) {
        if (!res || !res.ok) { return; }
        var shares = res.shares || [];

        // populate "all" row
        for (var i = 0; i < shares.length; i++) {
          if (shares[i].shared_with_user_id === null || shares[i].shared_with_user_id === '') {
            document.getElementById('share_all_on').checked   = true;
            document.getElementById('share_all_edit').checked = Number(shares[i].can_edit) === 1;
          }
        }

        // build per-user rows
        var html = '';
        for (var j = 0; j < allUsers.length; j++) {
          var u = allUsers[j];
          var checked = false, editChecked = false;
          for (var k = 0; k < shares.length; k++) {
            if (String(shares[k].shared_with_user_id) === String(u.id)) {
              checked = true;
              editChecked = Number(shares[k].can_edit) === 1;
            }
          }
          html += '<div class="memo-share-row">' +
            '<span class="share-name">' + esc(u.full_name) + '</span>' +
            '<div class="memo-share-toggle">' +
            '<input type="checkbox" class="sh-on"  data-uid="' + u.id + '"' + (checked     ? ' checked' : '') + '>' +
            '<span>Share</span>' +
            '<input type="checkbox" class="sh-edit" data-uid="' + u.id + '"' + (editChecked ? ' checked' : '') + '>' +
            '<span>Can edit</span>' +
            '</div></div>';
        }
        document.getElementById('shareUserList').innerHTML = html || '<p style="color:#aaa;font-size:.8rem;">No other users.</p>';
      });
    }

    if (allUsers.length > 0) {
      loadShares();
    } else {
      get('get_users', {}, function (res) {
        allUsers = (res && res.ok && res.users) ? res.users : [];
        loadShares();
      });
    }
  }

  window.memoShareOpen  = memoShareOpen;

  window.memoShareAllToggle = function () {
    // no extra logic needed — just let save read the checkboxes
  };

  window.memoShareClose = function () {
    shareModal.style.display = 'none';
  };

  window.memoShareSave = function () {
    var memoId = document.getElementById('share_memo_id').value;
    var shares = [];

    // "all" entry
    if (document.getElementById('share_all_on').checked) {
      shares.push({ user_id: null, can_edit: document.getElementById('share_all_edit').checked ? 1 : 0 });
    }

    // per-user entries
    var onChecks = document.getElementById('shareUserList').querySelectorAll('.sh-on');
    for (var i = 0; i < onChecks.length; i++) {
      if (onChecks[i].checked) {
        var uid2  = onChecks[i].getAttribute('data-uid');
        var editEl = document.querySelector('.sh-edit[data-uid="' + uid2 + '"]');
        shares.push({ user_id: uid2, can_edit: (editEl && editEl.checked) ? 1 : 0 });
      }
    }

    post('save_shares', { id: memoId, shares: JSON.stringify(shares) }, function (res) {
      if (!res || !res.ok) { alert('Errore nel salvataggio condivisione.'); return; }
      window.memoShareClose();
      load();
    });
  };

  shareModal.addEventListener('click', function (e) { if (e.target === shareModal) { window.memoShareClose(); } });

  // ---------- init ----------
  initQuill();
  load();
})();
