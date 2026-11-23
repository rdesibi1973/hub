/**
 * program_chat.js — the program assistant panel of modules/iti/program_final.php (#pchat).
 * Talks to program_chat.php (logic: includes/iti_ai_chat_service.php). Cards (mapping / sample proposals) are saved
 * only when the seller presses Confirm; the page itself shows them after a reload.
 */
(function () {
  'use strict';
  var box = document.getElementById('pchat');
  if (!box) return;
  var body = box.querySelector('.pchat-body');
  var D = box.dataset;
  var chat = null, busy = false, changed = false, limit = 0;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  // Plain text with **bold** and line breaks (what the assistant writes).
  function fmt(s) {
    return esc(s).replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>');
  }

  function call(params, method) {
    var url = D.endpoint, opts = { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } };
    if (method === 'GET') {
      url += '?' + new URLSearchParams(params).toString();
    } else {
      var fd = new FormData();
      fd.append('csrf', D.csrf);
      Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
      opts.method = 'POST';
      opts.body = fd;
    }
    return fetch(url, opts).then(function (r) {
      return r.text().then(function (t) {
        try { return JSON.parse(t); } catch (e) { return { ok: false, error: 'Unexpected answer from the server (HTTP ' + r.status + ').' }; }
      });
    }).catch(function () { return { ok: false, error: 'No connection to the Hub.' }; });
  }

  function cardHtml(c) {
    if (!c) return '';
    var st = c.status;
    var h = '<div class="pchat-card" style="margin:6px 0;padding:8px 10px;border:1px solid ' + (st === 'pending' ? '#f0ad00' : 'var(--grey-lt)') + ';border-radius:6px;background:' + (st === 'pending' ? '#fffdf5' : '#fafafa') + ';">';
    if (c.kind === 'ready') {
      h += '<div style="font-weight:600;color:#2e7d32;">✓ ' + esc(c.label) + '</div>'
         + '<a class="btn btn-red btn-sm" style="margin-top:6px;" href="' + esc(c.url) + '">Open the page with the data confirmed →</a>';
      return h + '</div>';
    }
    h += '<div style="font-weight:600;">' + esc(c.label) + '</div>';
    if (c.why) h += '<div style="font-size:.78rem;color:var(--grey-dk);margin-top:2px;">' + esc(c.why) + '</div>';
    if (st === 'pending') {
      h += '<div style="margin-top:6px;display:flex;gap:6px;">'
         + '<button type="button" class="btn btn-red btn-sm" data-card="' + esc(c.id) + '" data-do="confirm">✓ Confirm</button>'
         + '<button type="button" class="btn btn-outline btn-sm" data-card="' + esc(c.id) + '" data-do="refuse">✗ Not right</button></div>';
    } else {
      h += '<div style="font-size:.75rem;margin-top:4px;color:' + (st === 'done' ? '#2e7d32' : 'var(--grey-mid)') + ';">' + (st === 'done' ? '✓ Confirmed' : '✗ Not right') + '</div>';
    }
    return h + '</div>';
  }

  function msgHtml(m) {
    if (m.role === 'card') return cardHtml(chat.cards[m.id]);
    if (m.role === 'note') return '<div style="font-size:.76rem;color:var(--grey-mid);margin:4px 0;">' + esc(m.text) + '</div>';
    var me = m.role === 'user';
    return '<div style="display:flex;justify-content:' + (me ? 'flex-end' : 'flex-start') + ';margin:6px 0;">'
         + '<div style="max-width:85%;padding:8px 11px;border-radius:10px;line-height:1.45;font-size:.86rem;'
         + (me ? 'background:#e8eef9;' : 'background:#fff;border:1px solid var(--grey-lt);') + '">' + fmt(m.text) + '</div></div>';
  }

  function render(err) {
    body.style.color = 'inherit';   // "Loading…" was grey
    if (D.aiOk !== '1' && !chat) {
      body.innerHTML = '<div style="font-size:.85rem;">The assistant starts when the Calc has nothing to fix (red lines in the pre-check above): '
        + 'no AI credit is spent on a Calc that is not ready. Correct the Calc in Dropbox and reload the page.</div>';
      return;
    }
    if (!chat) {
      body.innerHTML = '<div style="font-size:.85rem;color:inherit;margin-bottom:8px;">A chat that guides you from this Calc to the program: what to check, the hotels / activities / transfers to map, the sample. '
        + 'It proposes, you confirm each step. Uses AI credit (limit ' + limit.toFixed(2) + ' USD per conversation).</div>'
        + (err ? '<div style="color:var(--red);font-size:.82rem;margin-bottom:6px;">' + esc(err) + '</div>' : '')
        + '<button type="button" class="btn btn-red btn-sm" data-do="start"' + (busy ? ' disabled' : '') + '>' + (busy ? '✨ Reading the Calc… (up to a minute)' : '✨ Start the assistant') + '</button>';
      return;
    }
    var h = '<div class="pchat-log" style="max-height:440px;overflow-y:auto;padding:4px 6px;background:#f7f8fa;border-radius:6px;border:1px solid var(--grey-lt);">';
    chat.display.forEach(function (m) { h += msgHtml(m); });
    if (busy) h += '<div style="font-size:.8rem;color:var(--grey-mid);margin:6px 0;">✨ Thinking… (up to a minute)</div>';
    h += '</div>';
    if (err) h += '<div style="color:var(--red);font-size:.82rem;margin-top:6px;">' + esc(err) + '</div>';
    if (chat.status === 'open') {
      h += '<div style="display:flex;gap:6px;margin-top:8px;align-items:flex-end;">'
         + '<textarea class="pchat-input" rows="2" style="flex:1;resize:vertical;font:inherit;font-size:.86rem;" placeholder="Write to the assistant… (Enter sends, Shift+Enter new line)"' + (busy ? ' disabled' : '') + '></textarea>'
         + '<button type="button" class="btn btn-red btn-sm" data-do="send"' + (busy ? ' disabled' : '') + '>Send</button></div>';
    }
    h += '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;font-size:.76rem;color:var(--grey-mid);margin-top:6px;">'
       + '<span>This conversation: ' + Number(chat.cost).toFixed(2) + ' of ' + Number(chat.limit).toFixed(2) + ' USD</span>'
       + (changed ? '<a href="#" data-do="reload" style="font-weight:600;">↻ Reload the page to see the saved mappings</a>' : '')
       + '<a href="#" data-do="new">New conversation</a><a href="#" data-do="close">Close</a></div>';
    body.innerHTML = h;
    var log = body.querySelector('.pchat-log');
    if (log) log.scrollTop = log.scrollHeight;
    var ta = body.querySelector('.pchat-input');
    if (ta && !busy) ta.focus({ preventScroll: true });
  }

  function apply(res) {
    if (res.chat !== undefined) chat = res.chat;
    if (res.limit) limit = Number(res.limit);
    busy = false;
    render(res.ok ? '' : res.error);
  }

  function start() {
    busy = true; render();
    call({ action: 'start', request_id: D.request, file: D.file, sheet: D.sheet, lang: D.lang,
           sample_id: D.sample, group_open: D.groupOpen }, 'POST').then(apply);
  }

  function send() {
    var ta = body.querySelector('.pchat-input');
    var text = ta ? ta.value.trim() : '';
    if (!text || busy) return;
    chat.display.push({ role: 'user', text: text });   // shown at once; the server's copy replaces it
    busy = true; render();
    call({ action: 'send', chat_id: chat.id, text: text }, 'POST').then(apply);
  }

  body.addEventListener('click', function (e) {
    var t = e.target.closest('[data-do]');
    if (!t) return;
    e.preventDefault();
    var what = t.getAttribute('data-do');
    if (what === 'start') start();
    else if (what === 'send') send();
    else if (what === 'reload') location.reload();
    else if (what === 'confirm' || what === 'refuse') {
      t.disabled = true;
      call({ action: what, chat_id: chat.id, card_id: t.getAttribute('data-card') }, 'POST').then(function (res) {
        if (res.ok && what === 'confirm') changed = true;
        apply(res);
      });
    } else if (what === 'new' || what === 'close') {
      if (what === 'new' && !confirm('Start a new conversation? This one is closed (it stays in the log).')) return;
      call({ action: 'close', chat_id: chat.id }, 'POST').then(function (res) {
        chat = null;
        if (what === 'new' && res.ok) start(); else apply(res);
      });
    }
  });
  body.addEventListener('keydown', function (e) {
    if (e.target.classList.contains('pchat-input') && e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
  });

  call({ action: 'state', request_id: D.request, file: D.file }, 'GET').then(function (res) {
    if (res.limit) limit = Number(res.limit);
    chat = res.ok ? res.chat : null;
    render(res.ok ? '' : res.error);
  });
})();
