</main>

<footer>
  Savannah Explorers &mdash; Lead Tracker &mdash; Internal Use Only
</footer>

<!-- ── Shared Yes/No modal (used by deleteRequest) ────────────────────────── -->
<div id="seModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:10px;padding:28px 32px;max-width:440px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.22);font-family:'Open Sans',sans-serif;">
    <div id="seModalTitle" style="font-weight:700;font-size:1rem;margin-bottom:12px;color:#1A1A1A;"></div>
    <div id="seModalMsg"   style="font-size:.88rem;color:#444;line-height:1.6;white-space:pre-wrap;margin-bottom:22px;"></div>
    <div style="display:flex;gap:10px;justify-content:flex-end;">
      <button id="seModalNo"  style="padding:8px 22px;border-radius:6px;border:1px solid #ccc;background:#fff;font-size:.88rem;cursor:pointer;">No</button>
      <button id="seModalYes" style="padding:8px 22px;border-radius:6px;border:none;background:#C0211B;color:#fff;font-size:.88rem;font-weight:600;cursor:pointer;">Yes</button>
    </div>
  </div>
</div>

<script>
// ── Lightweight Yes/No modal ──────────────────────────────────────────────────
function seConfirm(title, message) {
  return new Promise(function(resolve) {
    var modal = document.getElementById('seModal');
    document.getElementById('seModalTitle').textContent = title;
    document.getElementById('seModalMsg').textContent   = message;
    modal.style.display = 'flex';

    function cleanup(result) {
      modal.style.display = 'none';
      document.getElementById('seModalYes').onclick = null;
      document.getElementById('seModalNo').onclick  = null;
      resolve(result);
    }
    document.getElementById('seModalYes').onclick = function() { cleanup(true);  };
    document.getElementById('seModalNo').onclick  = function() { cleanup(false); };
  });
}

// ── Shared delete logic ───────────────────────────────────────────────────────
/**
 * deleteRequest(id, name, folder, status, redirect)
 * Used by requests.php (list) and request_view.php (detail).
 */
async function deleteRequest(id, name, folder, status, redirect, grpFolder) {
  if (!confirm('Delete request for "' + name + '"?\n\nThis action cannot be undone.')) return;

  // Confirmed bookings cannot be deleted — status changes are done via the Java GUI
  if (status === 'Booked') {
    alert('Cannot delete a confirmed booking (Booked).\n\nTo cancel, change the folder status to CANCELLED manually via the Java GUI.');
    return;
  }

  var deleteDropbox = false;
  if (folder) {
    var dbxDir = (status === 'Booked') ? '001_Safari' : '2026';
    var dbxDisplay = (grpFolder && status === 'Booked')
      ? dbxDir + '/' + grpFolder + '/' + folder
      : dbxDir + '/' + folder;
    deleteDropbox = await seConfirm(
      'Delete Dropbox folder?',
      'Also delete the Dropbox folder?\n\n' + dbxDisplay
    );
  }

  var fd = new FormData();
  fd.append('ids[]', id);
  if (deleteDropbox) fd.append('delete_dropbox', '1');

  fetch('request_delete.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.ok) {
        if (deleteDropbox && d.dropbox && Object.keys(d.dropbox).length > 0) {
          var msgs = Object.values(d.dropbox).join('\n');
          if (msgs.includes('error')) alert('Request deleted.\nDropbox note:\n' + msgs);
        }
        if (redirect) { window.location.href = redirect; } else { location.reload(); }
      } else {
        alert('Error: ' + (d.error || 'unknown'));
      }
    })
    .catch(function(e) { alert('Network error: ' + e); });
}

/**
 * deleteSelectedRequests(folderMap, statusMap)
 * Bulk delete for the requests list.
 */
async function deleteSelectedRequests(folderMap, statusMap) {
  var ids = [...document.querySelectorAll('.row-sel:checked')].map(function(el) { return el.value; });
  if (!ids.length) { alert('No requests selected.'); return; }

  if (!confirm('Delete ' + ids.length + ' selected request' + (ids.length > 1 ? 's' : '') + '?\n\nThis action cannot be undone.')) return;

  // Block if any selected request is Booked
  var bookedNames = ids.filter(function(id) { return statusMap[id] === 'Booked'; });
  if (bookedNames.length) {
    alert('Cannot delete confirmed (Booked) requests.\n\nTo cancel a booking, change the folder status to CANCELLED manually via the Java GUI.');
    return;
  }

  var hasFolders = ids.some(function(id) { return folderMap[id]; });
  var deleteDropbox = false;
  if (hasFolders) {
    deleteDropbox = await seConfirm(
      'Delete Dropbox folders?',
      'Also delete the corresponding Dropbox folder(s) for the selected requests?'
    );
  }

  var fd = new FormData();
  ids.forEach(function(id) { fd.append('ids[]', id); });
  if (deleteDropbox) fd.append('delete_dropbox', '1');

  fetch('request_delete.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.ok) {
        if (deleteDropbox && d.dropbox && Object.keys(d.dropbox).length > 0) {
          var msgs = Object.values(d.dropbox).join('\n');
          if (msgs.includes('error')) alert('Some requests deleted.\nDropbox notes:\n' + msgs);
        }
        location.reload();
      } else {
        alert('Error: ' + (d.error || 'unknown'));
      }
    })
    .catch(function(e) { alert('Network error: ' + e); });
}
</script>

</body>
</html>
