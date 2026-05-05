</main>

<footer>
  Savannah Explorers &mdash; Invoices &mdash; Internal Use Only
</footer>

<!-- ── Generic Yes/No confirmation modal ─────────────────────── -->
<div id="seModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:10px;padding:28px 32px;max-width:460px;width:92%;box-shadow:0 8px 32px rgba(0,0,0,.22);font-family:'Open Sans',sans-serif;">
    <div id="seModalTitle" style="font-weight:700;font-size:1rem;margin-bottom:10px;color:#1A1A1A;"></div>
    <div id="seModalMsg"   style="font-size:.88rem;color:#444;line-height:1.6;margin-bottom:18px;white-space:pre-wrap;"></div>
    <div id="seModalExtra"></div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
      <button id="seModalNo"  style="padding:8px 22px;border-radius:6px;border:1px solid #ccc;background:#fff;font-size:.88rem;cursor:pointer;">Cancel</button>
      <button id="seModalYes" style="padding:8px 22px;border-radius:6px;border:none;background:#C0211B;color:#fff;font-size:.88rem;font-weight:600;cursor:pointer;">Confirm</button>
    </div>
  </div>
</div>

<script>
function seConfirm(title, message, extraHtml) {
  return new Promise(function(resolve) {
    var modal = document.getElementById('seModal');
    document.getElementById('seModalTitle').textContent = title;
    document.getElementById('seModalMsg').textContent   = message;
    document.getElementById('seModalExtra').innerHTML   = extraHtml || '';
    modal.style.display = 'flex';
    function cleanup(result) {
      modal.style.display = 'none';
      document.getElementById('seModalYes').onclick = null;
      document.getElementById('seModalNo').onclick  = null;
      resolve(result);
    }
    document.getElementById('seModalYes').onclick = function() { cleanup(true); };
    document.getElementById('seModalNo').onclick  = function() { cleanup(false); };
  });
}

// Cancel a payment — requires a reason
async function cancelPayment(paymentId, invoiceId) {
  var reasonHtml = '<textarea id="cancelReason" placeholder="Reason for cancellation (required)" style="width:100%;min-height:72px;padding:8px 10px;border:1.5px solid #E8E8E8;border-radius:6px;font-family:inherit;font-size:.85rem;margin-top:2px;"></textarea>';
  var ok = await seConfirm('Cancel Payment', 'Please enter a reason for cancelling this payment:', reasonHtml);
  if (!ok) return;
  var reason = document.getElementById('cancelReason').value.trim();
  if (!reason) { alert('A cancellation reason is required.'); return; }
  var fd = new FormData();
  fd.append('action', 'cancel_payment');
  fd.append('payment_id', paymentId);
  fd.append('invoice_id', invoiceId);
  fd.append('reason', reason);
  fetch('invoice_view.php', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.ok) { location.reload(); }
      else { alert('Error: ' + (d.error || 'unknown')); }
    })
    .catch(function(e){ alert('Network error: ' + e); });
}
</script>

</body>
</html>
