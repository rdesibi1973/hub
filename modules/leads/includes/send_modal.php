<?php
/**
 * Shared Send Email Modal
 * Requires: $templates (array with id, name, category), $send_ajax_url (string)
 * Usage: include 'includes/send_modal.php';
 */
$tpl_by_cat = [];
foreach ($templates as $t) $tpl_by_cat[$t['category'] ?: 'General'][] = $t;
ksort($tpl_by_cat);
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.css">

<!-- ── Send Email Modal ────────────────────────────────────────────────────── -->
<div class="modal-overlay hidden" id="sendOverlay" style="display:none">
  <div class="modal-box" style="max-width:820px">
    <div class="modal-header">
      <h3>✉ Send Email — <span id="sendCustomer"></span></h3>
      <button type="button" class="modal-close" onclick="closeSend()">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="send_req_id">

      <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:flex-end;margin-bottom:14px">
        <div>
          <label class="m-label">To</label>
          <input type="email" id="send_to" class="m-input" autocomplete="off">
        </div>
        <div>
          <label class="m-label">Template</label>
          <select id="send_tpl" class="m-input">
            <option value="">— select template —</option>
            <?php foreach ($tpl_by_cat as $cat => $items): ?>
              <optgroup label="<?= h($cat) ?>">
                <?php foreach ($items as $t): ?>
                  <option value="<?= $t['id'] ?>"><?= h($t['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="button" class="btn btn-outline btn-sm" onclick="loadTemplate()">Load</button>
      </div>

      <div style="margin-bottom:14px">
        <label class="m-label">Subject</label>
        <input type="text" id="send_subject" class="m-input" autocomplete="off">
      </div>

      <label class="m-label">Body</label>
      <div style="border:1.5px solid var(--grey-lt);border-radius:6px;overflow:hidden">
        <div id="send-quill" style="min-height:200px;font-size:.88rem;font-family:'Open Sans',sans-serif"></div>
      </div>
      <textarea id="send_body" style="display:none"></textarea>

      <div style="margin-top:14px">
        <label class="m-label">📎 Attachments</label>
        <input type="file" id="attach_input" multiple style="display:none" onchange="handleFiles(this)">
        <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('attach_input').click()">+ Add attachment</button>
        <div id="attachList" style="margin-top:8px;display:flex;flex-wrap:wrap;gap:4px"></div>
      </div>

      <div id="sendAlert" style="display:none;margin-top:12px;padding:10px 14px;border-radius:6px;font-size:.82rem"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline" onclick="closeSend()">Cancel</button>
      <button type="button" class="btn btn-red" id="btnSend" onclick="doSend()">✉ Send Email</button>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"></script>
<script>
(function() {
  var SEND_URL = <?= json_encode($send_ajax_url) ?>;
  var attachedFiles = [];
  var sendQuill;

  // Init Quill after DOM ready
  document.addEventListener('DOMContentLoaded', function() {
    sendQuill = new Quill('#send-quill', {
      theme: 'snow',
      modules: {
        toolbar: [
          ['bold','italic','underline'],
          [{'list':'ordered'},{'list':'bullet'}],
          ['link','clean'],
          [{'color':[]},{'align':[]}]
        ]
      }
    });
  });

  // Prevent modal box clicks from closing overlay
  document.querySelector('#sendOverlay .modal-box').addEventListener('click', function(e) {
    e.stopPropagation();
  });

  window.openSend = function(id, customer, to) {
    document.getElementById('send_req_id').value        = id;
    document.getElementById('sendCustomer').textContent = customer;
    document.getElementById('send_to').value            = to || '';
    document.getElementById('send_tpl').value           = '';
    document.getElementById('send_subject').value       = '';
    document.getElementById('sendAlert').style.display  = 'none';
    document.getElementById('btnSend').disabled         = false;
    document.getElementById('btnSend').textContent      = '✉ Send Email';
    if (sendQuill) sendQuill.root.innerHTML = '';
    attachedFiles = [];
    document.getElementById('attachList').innerHTML = '';
    document.getElementById('attach_input').value   = '';
    document.getElementById('sendOverlay').style.display = 'flex';
  };

  window.closeSend = function() {
    document.getElementById('sendOverlay').style.display = 'none';
  };

  window.loadTemplate = function() {
    var tpl_id = document.getElementById('send_tpl').value;
    var req_id = document.getElementById('send_req_id').value;
    if (!tpl_id) { alert('Select a template first.'); return; }
    fetch(SEND_URL, {
      method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'action=preview_email&request_id='+req_id+'&template_id='+tpl_id
    }).then(function(r){return r.json();}).then(function(d) {
      if (!d.ok) { alert(d.msg); return; }
      document.getElementById('send_subject').value = d.subject;
      if (sendQuill) {
        sendQuill.root.innerHTML = '';
        sendQuill.clipboard.dangerouslyPasteHTML(0, d.body || '');
      }
    });
  };

  window.doSend = function() {
    var btn  = document.getElementById('btnSend');
    var alrt = document.getElementById('sendAlert');
    alrt.style.display = 'none';
    btn.disabled = true;
    btn.textContent = 'Sending…';

    var fd = new FormData();
    fd.append('action',     'send_email');
    fd.append('request_id', document.getElementById('send_req_id').value);
    fd.append('to',         document.getElementById('send_to').value);
    fd.append('subject',    document.getElementById('send_subject').value);
    fd.append('body',       sendQuill ? sendQuill.root.innerHTML : '');
    attachedFiles.forEach(function(f) { fd.append('attachments[]', f); });

    fetch(SEND_URL, {method:'POST', body:fd})
      .then(function(r){return r.json();})
      .then(function(d) {
        if (d.ok) {
          alrt.style.cssText = 'display:block;background:#e8f5e9;color:#2e7d32;margin-top:12px;padding:10px 14px;border-radius:6px;font-size:.82rem';
          alrt.textContent = 'Email sent and logged successfully.';
          btn.textContent = '✓ Sent';
          setTimeout(function() { closeSend(); }, 2000);
        } else {
          alrt.style.cssText = 'display:block;background:#ffebee;color:#c62828;margin-top:12px;padding:10px 14px;border-radius:6px;font-size:.82rem';
          alrt.textContent = d.msg || 'Send failed.';
          btn.disabled = false;
          btn.textContent = '✉ Send Email';
        }
      })
      .catch(function(err) {
        alrt.style.cssText = 'display:block;background:#ffebee;color:#c62828;margin-top:12px;padding:10px 14px;border-radius:6px;font-size:.82rem';
        alrt.textContent = 'Error: ' + err.message;
        btn.disabled = false;
        btn.textContent = '✉ Send Email';
      });
  };

  window.handleFiles = function(input) {
    Array.from(input.files).forEach(function(f) {
      if (!attachedFiles.find(function(x){return x.name===f.name&&x.size===f.size;}))
        attachedFiles.push(f);
    });
    input.value = '';
    renderAttachments();
  };

  window.removeAttach = function(idx) {
    attachedFiles.splice(idx, 1);
    renderAttachments();
  };

  function renderAttachments() {
    document.getElementById('attachList').innerHTML = attachedFiles.map(function(f,i) {
      return '<span class="attach-chip">📎 '+escH(f.name)+
             ' <small style="color:var(--grey-mid)">('+Math.round(f.size/1024)+'KB)</small>'+
             '<button type="button" onclick="removeAttach('+i+')">×</button></span>';
    }).join('');
  }

  function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
})();
</script>
