<?php
/**
 * relink_folders.php — bulk re-link of request folders to Dropbox.
 *
 * After bookings are moved by hand into archives (e.g. /001_Safari/00_2026 or
 * /001_Safari/00_CANCELED/00_2026), the stored dropbox_url is stale. This tool
 * scans requests in small batches (client-driven, so it never times out):
 *   1. check the stored path exists (cheap get_metadata)
 *   2. only if it's gone, search Dropbox by folder name and refresh dropbox_url
 * dropbox_url is the single source of truth used by Open Folder / Copy Programs.
 */
ob_start();
require_once 'config.php';
$pageTitle = 'Re-link Folders';
$db = db();

// ── Access: admin + manager only (mirrors backoffice.php) ─────────────────────
$currentUser = current_user();
if (!in_array($currentUser['role_name'] ?? '', ['admin','manager'], true)) {
    flash('Access denied.', 'error');
    header('Location: requests.php'); exit;
}

/** API path ('/001_Safari/…') of a request folder, dropbox_url first. */
function rlf_path(array $r): string {
    if (!empty($r['dropbox_url']) && preg_match('#dropbox\.com/home(/.*)?$#i', $r['dropbox_url'], $m)) {
        $p = rtrim(urldecode($m[1] ?? ''), '/');
        if ($p !== '') return $p;
    }
    if (!empty($r['group_folder']) && !empty($r['practice_code'])) {
        return '/001_Safari/' . $r['group_folder'] . '/' . $r['practice_code'];
    }
    return '';
}

/** WHERE clause for the chosen scope. */
function rlf_scope_sql(string $scope): string {
    if ($scope === 'cancelled') return " AND status IN ('Cancelled','Lost')";
    if ($scope === 'active')    return " AND status NOT IN ('Cancelled','Lost')";
    return '';
}

// ── AJAX: scan one batch ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'scan') {
    ob_end_clean();
    header('Content-Type: application/json');
    try {
        $offset = max(0, (int)($_POST['offset'] ?? 0));
        $limit  = min(30, max(1, (int)($_POST['limit'] ?? 20)));
        $scope  = in_array($_POST['scope'] ?? 'all', ['all','cancelled','active'], true) ? $_POST['scope'] : 'all';
        $where  = "practice_code IS NOT NULL AND practice_code <> ''" . rlf_scope_sql($scope);

        $total = (int)$db->query("SELECT COUNT(*) FROM requests WHERE $where")->fetchColumn();

        $stmt = $db->prepare("SELECT id, customer_name, practice_code, group_folder, dropbox_url, status
                              FROM requests WHERE $where ORDER BY id LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $batch = $stmt->fetchAll(PDO::FETCH_ASSOC);

        require_once 'dropbox_helper.php';
        $token = dropbox_get_access_token();

        $okCount = 0; $noFolder = 0; $relinked = []; $missing = []; $ambiguous = [];
        foreach ($batch as $r) {
            $name = trim($r['practice_code'] ?? '');
            $path = rlf_path($r);
            if ($name === '' && $path === '') { $noFolder++; continue; }

            // Already correct? cheap check first.
            if ($path !== '' && dropbox_path_exists($token, $path)) { $okCount++; continue; }

            // Moved or missing → locate by name (tolerant of status-suffix drift).
            $res = dropbox_relink_find($token, $name);
            if (in_array($res['result'], ['exact','stem','core'], true)) {
                $newUrl = 'https://www.dropbox.com/home/' . implode('/', array_map('rawurlencode', explode('/', ltrim($res['path'], '/'))));
                $db->prepare("UPDATE requests SET dropbox_url=? WHERE id=?")->execute([$newUrl, (int)$r['id']]);
                $relinked[] = ['name' => $name, 'path' => $res['path']];
            } elseif ($res['result'] === 'ambiguous') {
                $ambiguous[] = ['name' => $name, 'customer' => $r['customer_name'] ?? '',
                                'candidates' => array_map(fn($c) => $c['path'], $res['candidates'])];
            } else {
                $missing[] = ['name' => $name, 'customer' => $r['customer_name'] ?? '', 'status' => $r['status'] ?? ''];
            }
        }

        $processed = count($batch);
        echo json_encode([
            'ok'         => true,
            'total'      => $total,
            'processed'  => $processed,
            'next'       => $offset + $processed,
            'done'       => ($offset + $processed) >= $total || $processed === 0,
            'ok_count'   => $okCount,
            'no_folder'  => $noFolder,
            'relinked'   => $relinked,
            'missing'    => $missing,
            'ambiguous'  => $ambiguous,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

include 'includes/header.php';
?>

<div class="page-header"><h2>🔗 Re-link Folders (bulk)</h2></div>

<div class="table-wrap" style="max-width:900px;margin-bottom:20px">
  <div style="padding:18px 22px">
    <p style="font-size:.85rem;color:var(--grey-dk);line-height:1.6">
      Scans requests and refreshes the Dropbox link for any folder that was moved by hand
      (e.g. into <code>/001_Safari/00_2026</code> or <code>/001_Safari/00_CANCELED/00_2026</code>).
      Only folders that no longer exist at their stored path are searched by name, so already-correct
      bookings are left untouched. Runs in small batches — you can leave the page open.
    </p>
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:8px">
      <label style="font-size:.85rem">Scope
        <select id="rlf-scope" style="font-size:.85rem;padding:4px 8px;margin-left:4px">
          <option value="all">All bookings</option>
          <option value="cancelled">Cancelled / Lost only</option>
          <option value="active">Active only (not cancelled)</option>
        </select>
      </label>
      <button type="button" class="btn btn-red" id="rlf-start" onclick="rlfStart()">Start scan</button>
      <button type="button" class="btn btn-outline" id="rlf-stop" onclick="rlfStop()" style="display:none">Stop</button>
      <span id="rlf-progress" style="font-size:.85rem;color:var(--grey-mid)"></span>
    </div>
    <div id="rlf-bar" style="display:none;height:8px;background:#eee;border-radius:4px;margin-top:14px;overflow:hidden">
      <div id="rlf-bar-fill" style="height:100%;width:0;background:var(--green,#2e7d32);transition:width .2s"></div>
    </div>
    <div id="rlf-summary" style="font-size:.85rem;margin-top:14px"></div>
    <div id="rlf-log" style="font-size:.78rem;margin-top:10px"></div>
  </div>
</div>

<script>
var rlfRun = false, rlfTotals = { ok:0, relinked:0, missing:0, ambiguous:0, no_folder:0 };
var rlfRelinked = [], rlfMissing = [], rlfAmbiguous = [];

function rlfStart() {
  rlfRun = true;
  rlfTotals = { ok:0, relinked:0, missing:0, ambiguous:0, no_folder:0 };
  rlfRelinked = []; rlfMissing = []; rlfAmbiguous = [];
  document.getElementById('rlf-start').disabled = true;
  document.getElementById('rlf-scope').disabled = true;
  document.getElementById('rlf-stop').style.display = '';
  document.getElementById('rlf-bar').style.display = 'block';
  document.getElementById('rlf-summary').innerHTML = '';
  document.getElementById('rlf-log').innerHTML = '';
  rlfScan(0);
}
function rlfStop() { rlfRun = false; rlfFinish('Stopped.'); }

function rlfScan(offset) {
  if (!rlfRun) return;
  var scope = document.getElementById('rlf-scope').value;
  var fd = new FormData();
  fd.append('action', 'scan'); fd.append('offset', offset); fd.append('limit', 20); fd.append('scope', scope);
  fetch('relink_folders.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(d => {
      if (!d.ok) { rlfFinish('Error: ' + (d.msg || 'failed')); return; }
      rlfTotals.ok += d.ok_count; rlfTotals.no_folder += d.no_folder;
      rlfTotals.relinked += d.relinked.length; rlfTotals.missing += d.missing.length;
      rlfTotals.ambiguous += (d.ambiguous || []).length;
      d.relinked.forEach(x => rlfRelinked.push(x));
      d.missing.forEach(x => rlfMissing.push(x));
      (d.ambiguous || []).forEach(x => rlfAmbiguous.push(x));

      var pct = d.total ? Math.round(d.next / d.total * 100) : 100;
      document.getElementById('rlf-bar-fill').style.width = pct + '%';
      document.getElementById('rlf-progress').textContent =
        d.next + ' / ' + d.total + '  —  re-linked ' + rlfTotals.relinked +
        ', unchanged ' + rlfTotals.ok + ', ambiguous ' + rlfTotals.ambiguous + ', missing ' + rlfTotals.missing;
      rlfRenderLog();

      if (d.done || !rlfRun) { rlfFinish('Done.'); }
      else { rlfScan(d.next); }
    })
    .catch(e => rlfFinish('Error: ' + e));
}

function rlfRenderLog() {
  function esc(s){ return (s||'').replace(/</g,'&lt;'); }
  var html = '';
  if (rlfRelinked.length) {
    html += '<div style="margin-top:8px"><strong style="color:var(--green,#2e7d32)">Re-linked (' + rlfRelinked.length + ')</strong>'
          + '<ul style="margin:4px 0 0 18px">' + rlfRelinked.slice(-200).map(x =>
              '<li style="font-family:monospace">' + esc(x.name) + ' → ' + esc(x.path) + '</li>').join('') + '</ul></div>';
  }
  if (rlfAmbiguous.length) {
    html += '<div style="margin-top:8px"><strong style="color:#92400e">Ambiguous — review manually (' + rlfAmbiguous.length + ')</strong>'
          + '<ul style="margin:4px 0 0 18px">' + rlfAmbiguous.slice(-200).map(x =>
              '<li style="font-family:monospace">' + esc(x.name) + ' <span style="color:#888">→ ' + (x.candidates||[]).map(esc).join(' | ') + '</span></li>').join('') + '</ul></div>';
  }
  if (rlfMissing.length) {
    html += '<div style="margin-top:8px"><strong style="color:#C0211B">Not found in Dropbox (' + rlfMissing.length + ')</strong>'
          + '<ul style="margin:4px 0 0 18px">' + rlfMissing.slice(-200).map(x =>
              '<li style="font-family:monospace">' + esc(x.name) + ' <span style="color:#888">(' + esc(x.customer) + ', ' + esc(x.status) + ')</span></li>').join('') + '</ul></div>';
  }
  document.getElementById('rlf-log').innerHTML = html;
}

function rlfFinish(msg) {
  rlfRun = false;
  document.getElementById('rlf-start').disabled = false;
  document.getElementById('rlf-scope').disabled = false;
  document.getElementById('rlf-stop').style.display = 'none';
  document.getElementById('rlf-summary').innerHTML =
    '<strong>' + msg + '</strong> Re-linked ' + rlfTotals.relinked + ', unchanged ' + rlfTotals.ok +
    ', ambiguous ' + rlfTotals.ambiguous + ', missing ' + rlfTotals.missing + ', no folder ' + rlfTotals.no_folder + '.';
  rlfRenderLog();
}
</script>

<?php include 'includes/footer.php'; ?>
