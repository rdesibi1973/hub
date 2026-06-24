// modules/memo/memo.js — Memo Board front-end
// Conventions for this codebase: use var + string concatenation (no template literals
// in innerHTML), no const/let at top scope, native HTML5 drag-and-drop.

(function () {
  'use strict';

  var AJAX = 'ajax.php';
  var board = document.getElementById('memoBoard');
  var modal = document.getElementById('memoModal');
  var dragId = null;

  // ---------- helpers ----------
  function post(action, data, cb) {
    var fd = new FormData();
    fd.append('action', action);
    for (var k in data) {
      if (data.hasOwnProperty(k)) {
        if (Object.prototype.toString.call(data[k]) === '[object Array]') {
          for (var i = 0; i < data[k].length; i++) {
            fd.append(k + '[]', data[k][i]);
          }
        } else {
          fd.append(k, data[k]);
        }
      }
    }
    var xhr = new XMLHttpRequest();
    xhr.open('POST', AJAX, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState === 4) {
        var res = null;
        try { res = JSON.parse(xhr.responseText); } catch (e) { res = null; }
        if (cb) { cb(res); }
      }
    };
    xhr.send(fd);
  }

  function esc(s) {
    if (s === null || s === undefined) { return ''; }
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // ---------- rendering ----------
  function load() {
    post('list', {}, function (res) {
      if (!res || !res.ok) { board.innerHTML = '<p class="memo-empty">Could not load memos.</p>'; return; }
      render(res.memos || []);
    });
  }

  function render(memos) {
    board.innerHTML = '';
    if (memos.length === 0) {
      board.innerHTML = '<p class="memo-empty">No memos yet. Click "+ New memo" to start.</p>';
      return;
    }
    for (var i = 0; i < memos.length; i++) {
      board.appendChild(buildCard(memos[i]));
    }
  }

  function buildCard(m) {
    var card = document.createElement('div');
    card.className = 'memo-card prio-' + esc(m.priority) +
      (m.status === 'done' ? ' is-done' : '') +
      (Number(m.pinned) === 1 ? ' is-pinned' : '');
    card.setAttribute('draggable', 'true');
    card.setAttribute('data-id', m.id);
    if (m.color) { card.style.background = m.color; }

    var pinTxt = Number(m.pinned) === 1 ? '\u2605' : '\u2606'; // ★ / ☆

    var html = '';
    html += '<div class="memo-card-head">';
    html += '  <span class="memo-type-badge">' + esc(m.type) + '</span>';
    html += '  <button class="memo-pin" data-act="pin" title="Pin">' + pinTxt + '</button>';
    html += '</div>';
    html += '<div class="memo-card-title">' + esc(m.title) + '</div>';
    if (m.body) {
      html += '<div class="memo-card-body">' + esc(m.body) + '</div>';
    }

    var meta = '';
    if (m.due_date) { meta += '<span class="memo-meta-item">Due ' + esc(m.due_date) + '</span>'; }
    if (m.reminder_at) {
      var rec = (m.recur_rule && m.recur_rule !== 'none') ? ' (' + esc(m.recur_rule) + ')' : '';
      meta += '<span class="memo-meta-item">\u23F0 ' + esc(m.reminder_at) + rec + '</span>';
    }
    if (meta) { html += '<div class="memo-card-meta">' + meta + '</div>'; }

    html += '<div class="memo-card-actions">';
    if (m.status !== 'done') {
      html += '<button class="memo-mini" data-act="done">\u2713 Done</button>';
    } else {
      html += '<button class="memo-mini" data-act="reopen">Reopen</button>';
    }
    html += '<button class="memo-mini" data-act="edit">Edit</button>';
    html += '</div>';

    card.innerHTML = html;

    // events
    card.querySelector('[data-act="pin"]').addEventListener('click', function (e) {
      e.stopPropagation();
      post('toggle_pin', { id: m.id }, function () { load(); });
    });
    var doneBtn = card.querySelector('[data-act="done"]');
    if (doneBtn) {
      doneBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        post('set_status', { id: m.id, status: 'done' }, function () { load(); });
      });
    }
    var reopenBtn = card.querySelector('[data-act="reopen"]');
    if (reopenBtn) {
      reopenBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        post('set_status', { id: m.id, status: 'open' }, function () { load(); });
      });
    }
    card.querySelector('[data-act="edit"]').addEventListener('click', function (e) {
      e.stopPropagation();
      openModal(m);
    });

    // drag
    card.addEventListener('dragstart', function () { dragId = m.id; card.classList.add('dragging'); });
    card.addEventListener('dragend', function () { card.classList.remove('dragging'); dragId = null; });
    card.addEventListener('dragover', function (e) { e.preventDefault(); });
    card.addEventListener('drop', function (e) {
      e.preventDefault();
      if (dragId === null || String(dragId) === String(m.id)) { return; }
      reorderDrop(dragId, m.id);
    });

    return card;
  }

  function reorderDrop(fromId, toId) {
    // build new order array from current DOM, moving fromId before toId
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

  // ---------- modal ----------
  function openModal(m) {
    document.getElementById('memoModalTitle').textContent = m ? 'Edit memo' : 'New memo';
    document.getElementById('m_id').value = m ? m.id : '';
    document.getElementById('m_title').value = m ? (m.title || '') : '';
    document.getElementById('m_body').value = m ? (m.body || '') : '';
    document.getElementById('m_type').value = m ? (m.type || 'memo') : 'memo';
    document.getElementById('m_priority').value = m ? (m.priority || 'normal') : 'normal';
    document.getElementById('m_color').value = m && m.color ? m.color : '';
    document.getElementById('m_due_date').value = m && m.due_date ? m.due_date : '';
    document.getElementById('m_recur_rule').value = m ? (m.recur_rule || 'none') : 'none';
    // datetime-local needs "YYYY-MM-DDTHH:MM"
    var rv = '';
    if (m && m.reminder_at) { rv = m.reminder_at.replace(' ', 'T').substring(0, 16); }
    document.getElementById('m_reminder_at').value = rv;

    document.getElementById('memoDeleteBtn').style.display = m ? 'inline-block' : 'none';
    modal.style.display = 'flex';
  }

  function closeModal() { modal.style.display = 'none'; }

  function save() {
    var id = document.getElementById('m_id').value;
    var title = document.getElementById('m_title').value.replace(/^\s+|\s+$/g, '');
    if (title === '') { alert('Title is required.'); return; }

    var data = {
      title: title,
      body: document.getElementById('m_body').value,
      type: document.getElementById('m_type').value,
      priority: document.getElementById('m_priority').value,
      color: document.getElementById('m_color').value,
      due_date: document.getElementById('m_due_date').value,
      reminder_at: document.getElementById('m_reminder_at').value,
      recur_rule: document.getElementById('m_recur_rule').value
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
    if (!id) { return; }
    if (!confirm('Delete this memo?')) { return; }
    post('delete', { id: id }, function () { closeModal(); load(); });
  }

  // ---------- wire up ----------
  document.getElementById('memoNewBtn').addEventListener('click', function () { openModal(null); });
  document.getElementById('memoSaveBtn').addEventListener('click', save);
  document.getElementById('memoCancelBtn').addEventListener('click', closeModal);
  document.getElementById('memoDeleteBtn').addEventListener('click', del);
  modal.addEventListener('click', function (e) { if (e.target === modal) { closeModal(); } });

  load();
})();
