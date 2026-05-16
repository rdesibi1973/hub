<?php
require_once 'config.php';
$pageTitle = 'Dropbox Reconciliation';

if (isLeadsRestricted() && !in_array(current_user()['role_name'], ['accountant'], true)) {
    flash('Access denied.', 'error');
    header('Location: requests.php');
    exit;
}

$db = db();

// ── Helpers ───────────────────────────────────────────────────────────────────

function reconcile_extractName(string $folder): string {
    $s = preg_replace('/^\d+_/', '', $folder);
    $s = preg_replace('/^\d{2}[A-Z]{3}_/i', '', $s);
    if (preg_match('/^([^(_]+)/', $s, $m)) return trim($m[1]);
    return trim($s);
}

function reconcile_norm(string $s): string {
    return strtolower(preg_replace('/[\s\'\-\.]+/', '', $s));
}

function reconcile_match(string $folderName, array $byPC, array $byName): array {
    if (isset($byPC[strtolower($folderName)])) {
        return ['exact' => true, 'confidence' => 'exact', 'matches' => [$byPC[strtolower($folderName)]]];
    }
    $custPart = reconcile_extractName($folderName);
    $normCust = reconcile_norm($custPart);
    if (isset($byName[$normCust])) {
        return ['exact' => false, 'confidence' => 'high', 'matches' => $byName[$normCust]];
    }
    $candidates = [];
    if (strlen($normCust) >= 4) {
        foreach ($byName as $norm => $reqs) {
            if (str_contains($norm, $normCust) || str_contains($normCust, $norm)) {
                foreach ($reqs as $r) $candidates[] = $r;
            }
        }
    }
    if ($candidates) {
        return ['exact' => false, 'confidence' => 'medium', 'matches' => array_values($candidates)];
    }
    return ['exact' => false, 'confidence' => 'none', 'matches' => []];
}

// ── APPLY ─────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    $applied = 0;
    foreach ($_POST['sel'] ?? [] as $encoded) {
        $item = json_decode(base64_decode($encoded), true);
        if (empty($item['request_id']) || empty($item['folder_name'])) continue;
        $db->prepare("UPDATE requests SET practice_code = ? WHERE id = ?")
           ->execute([$item['folder_name'], (int)$item['request_id']]);
        $applied++;
    }
    flash($applied > 0 ? "✔ Updated practice_code for $applied request(s)." : 'Nothing to apply.');
    header('Location: reconcile.php');
    exit;
}

// ── SCAN ──────────────────────────────────────────────────────────────────────

$results    = [];
$scanError  = '';
$scanned    = false;
$countExact = 0;
$scanPath   = $_POST['scan_path'] ?? '001_Safari'; // default

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'scan') {
    $scanned  = true;
    set_time_limit(120);

    $allReqs = $db->query(
        "SELECT id, customer_name, practice_code, group_folder, status
         FROM requests WHERE status NOT IN ('Cancelled','Lost')"
    )->fetchAll();

    $byPC = [];
    foreach ($allReqs as $r) {
        if ($r['practice_code'] !== null && $r['practice_code'] !== '') {
            $byPC[strtolower($r['practice_code'])] = $r;
        }
    }
    $byName = [];
    foreach ($allReqs as $r) {
        $norm = reconcile_norm($r['customer_name']);
        $byName[$norm][] = $r;
    }

    require_once 'dropbox_helper.php';

    try {
        $token = dropbox_get_access_token();

        if ($scanPath === '001_Safari') {
            $dirs = dropbox_list_folder($token, '/001_Safari');
            sort($dirs);
            foreach ($dirs as $dirName) {
                $isGrp = stripos($dirName, 'GRP') !== false;
                if ($isGrp) {
                    try {
                        $subs = dropbox_list_folder($token, '/001_Safari/' . $dirName);
                        sort($subs);
                        foreach ($subs as $subName) {
                            $m = reconcile_match($subName, $byPC, $byName);
                            if ($m['exact']) { $countExact++; continue; }
                            $results[] = ['source'=>'001_Safari','folder'=>$subName,
                                          'parent'=>$dirName,'is_grp'=>true,
                                          'confidence'=>$m['confidence'],'matches'=>$m['matches']];
                        }
                    } catch (RuntimeException $e) { /* skip */ }
                } else {
                    $m = reconcile_match($dirName, $byPC, $byName);
                    if ($m['exact']) { $countExact++; continue; }
                    $results[] = ['source'=>'001_Safari','folder'=>$dirName,
                                  'parent'=>null,'is_grp'=>false,
                                  'confidence'=>$m['confidence'],'matches'=>$m['matches']];
                }
            }
        } else {
            $dirs = dropbox_list_folder($token, '/2026');
            sort($dirs);
            foreach ($dirs as $dirName) {
                $m = reconcile_match($dirName, $byPC, $byName);
                if ($m['exact']) { $countExact++; continue; }
                $results[] = ['source'=>'2026','folder'=>$dirName,
                              'parent'=>null,'is_grp'=>false,
                              'confidence'=>$m['confidence'],'matches'=>$m['matches']];
            }
        }

    } catch (RuntimeException $e) {
        $scanError = $e->getMessage();
    }
}

include 'includes/header.php';
?>

<style>
.rc-section-hdr {
  font-size:.68rem; font-weight:700; text-transform:uppercase;
  letter-spacing:.12em; color:var(--grey-mid);
  margin: 28px 0 10px;
  display:flex; align-items:center; gap:8px;
}
.rc-section-hdr::before {
  content:''; display:inline-block;
  width:7px; height:7px; border-radius:50%; background:var(--red);
}
.badge-high   { background:#D1FAE5; color:#065F46; }
.badge-medium { background:#FEF9C3; color:#854D0E; }
.badge-none   { background:#F3F4F6; color:#6B7280; }
.rc-grp-tag   { font-size:.68rem; background:var(--red-lt); color:var(--red-dk); border-radius:4px; padding:1px 6px; font-weight:700; }
.rc-folder    { font-family:'Courier New',monospace; font-size:.78rem; word-break:break-all; }
.rc-parent    { font-size:.68rem; color:var(--grey-mid); margin-top:2px; }
.match-select { font-size:.78rem; max-width:340px; padding:3px 6px; }
</style>

<div class="page-header">
  <div>
    <h2>Dropbox Reconciliation</h2>
    <div class="sub">Sync Dropbox folder names → <code>practice_code</code> in DB</div>
  </div>
</div>

<?php if ($scanError): ?>
  <div class="flash flash-error">Dropbox error: <?= h($scanError) ?></div>
<?php endif; ?>

<?php if (!$scanned): ?>

  <div class="form-card" style="max-width:520px;">
    <p style="margin-bottom:18px;font-size:.88rem;color:var(--grey-dk);">
      Scans one Dropbox folder at a time. Folders whose name already matches
      <code>practice_code</code> in the DB are skipped — only mismatches shown.
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="scan">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <label style="font-weight:700;font-size:.85rem;">Scan:</label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.85rem;">
          <input type="radio" name="scan_path" value="001_Safari" checked> /001_Safari
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.85rem;">
          <input type="radio" name="scan_path" value="2026"> /2026
        </label>
        <button type="submit" class="btn btn-red">🔍 Scan</button>
      </div>
    </form>
  </div>

<?php else: ?>

  <?php
  $countHigh   = count(array_filter($results, fn($r) => $r['confidence'] === 'high'));
  $countMedium = count(array_filter($results, fn($r) => $r['confidence'] === 'medium'));
  $countNone   = count(array_filter($results, fn($r) => $r['confidence'] === 'none'));
  ?>

  <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:16px;font-size:.82rem;align-items:center;">
    <strong style="font-size:.85rem;">📁 /<?= h($scanPath) ?></strong>
    <span>✅ Exact (skipped): <strong><?= $countExact ?></strong></span>
    <span style="color:#065F46;">🟢 High: <strong><?= $countHigh ?></strong></span>
    <span style="color:#854D0E;">🟡 Review: <strong><?= $countMedium ?></strong></span>
    <span style="color:var(--grey-mid);">⚪ None: <strong><?= $countNone ?></strong></span>
    <a href="reconcile.php" class="btn btn-outline btn-sm" style="margin-left:auto;">↺ New Scan</a>
  </div>

  <?php if (count($results) === 0 && !$scanError): ?>
    <div class="flash flash-success">All folders already match. Nothing to reconcile.</div>
  <?php else: ?>

  <form method="POST">
    <input type="hidden" name="action" value="apply">

    <div style="display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap;">
      <button type="submit" class="btn btn-red"
              onclick="return confirm('Update practice_code for all checked rows?')">✔ Apply Selected</button>
      <button type="button" class="btn btn-outline btn-sm"
              onclick="document.querySelectorAll('.row-sel').forEach(c=>c.checked=true)">Select all with match</button>
      <button type="button" class="btn btn-grey btn-sm"
              onclick="document.querySelectorAll('.row-sel').forEach(c=>c.checked=false)">Clear</button>
    </div>

    <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th style="width:32px;"></th>
          <th>Dropbox Folder</th>
          <th>Matched Request</th>
          <th style="width:110px;">Confidence</th>
          <th style="width:210px;">Link to</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($results as $idx => $row):
        $hasMatch  = !empty($row['matches']);
        $bestMatch = $hasMatch ? $row['matches'][0] : null;
        $badgeCls  = match($row['confidence']) {
          'high'   => 'badge-high',
          'medium' => 'badge-medium',
          default  => 'badge-none',
        };
        $badgeLbl = match($row['confidence']) {
          'high'   => '🟢 High',
          'medium' => '🟡 Review',
          default  => '⚪ None',
        };
        $uid      = 'r' . $idx;
        $defReqId = $bestMatch['id'] ?? '';
        $encoded  = base64_encode(json_encode([
          'folder_name' => $row['folder'],
          'request_id'  => $defReqId,
        ]));
      ?>
      <tr>
        <td>
          <?php if ($hasMatch): ?>
            <input type="checkbox" class="row-sel" id="chk_<?= $uid ?>" style="width:14px;height:14px;">
            <input type="hidden" name="sel[]" id="apl_<?= $uid ?>"
                   value="<?= h($encoded) ?>"
                   data-folder="<?= h($row['folder']) ?>">
          <?php endif; ?>
        </td>

        <td>
          <div class="rc-folder"><?= h($row['folder']) ?></div>
          <?php if ($row['is_grp']): ?>
            <div class="rc-parent"><span class="rc-grp-tag">GRP</span> in <?= h($row['parent']) ?></div>
          <?php endif; ?>
        </td>

        <td style="font-size:.78rem;">
          <?php if (!$hasMatch): ?>
            <span style="color:var(--grey-mid);">No match</span>
          <?php else: ?>
            <a href="request_view.php?id=<?= $bestMatch['id'] ?>" target="_blank" style="font-weight:600;">
              <?= h($bestMatch['customer_name']) ?>
            </a>
            <?php if ($bestMatch['practice_code']): ?>
              <div style="color:var(--grey-mid);font-size:.72rem;margin-top:2px;">
                DB now: <code><?= h($bestMatch['practice_code']) ?></code>
              </div>
            <?php else: ?>
              <div style="color:var(--grey-mid);font-size:.72rem;margin-top:2px;"><em>no practice_code set</em></div>
            <?php endif; ?>
          <?php endif; ?>
        </td>

        <td><span class="badge <?= $badgeCls ?>"><?= $badgeLbl ?></span></td>

        <td>
          <?php if (count($row['matches']) > 1): ?>
            <select class="match-select"
                    onchange="updateSel('apl_<?= $uid ?>','<?= addslashes(h($row['folder'])) ?>',this.value,'chk_<?= $uid ?>')">
              <option value="">— choose —</option>
              <?php foreach ($row['matches'] as $m): ?>
                <option value="<?= $m['id'] ?>" <?= $m['id'] == $defReqId ? 'selected' : '' ?>>
                  #<?= $m['id'] ?> <?= h($m['customer_name']) ?>
                  <?= $m['practice_code'] ? ' ['.h(substr($m['practice_code'],0,22)).']' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php elseif ($hasMatch): ?>
            <span style="font-size:.75rem;color:var(--grey-mid);">#<?= $bestMatch['id'] ?> auto</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <div style="margin-top:12px;">
      <button type="submit" class="btn btn-red"
              onclick="return confirm('Update practice_code for all checked rows?')">✔ Apply Selected</button>
    </div>
  </form>

  <?php endif; ?>
<?php endif; ?>

<script>
function updateSel(aplId, folderName, requestId, chkId) {
  var enc = btoa(JSON.stringify({folder_name: folderName, request_id: requestId ? parseInt(requestId) : ''}));
  document.getElementById(aplId).value = enc;
  if (requestId) document.getElementById(chkId).checked = true;
}
</script>

<?php include 'includes/footer.php'; ?>
