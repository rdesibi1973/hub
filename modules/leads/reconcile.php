<?php
require_once 'config.php';
$pageTitle = 'Dropbox Reconciliation';

if (isLeadsRestricted() && !in_array(current_user()['role_name'], ['accountant'], true)) {
    flash('Access denied.', 'error');
    header('Location: requests.php');
    exit;
}

$db = db();

// ── Helper: extract customer name from a Dropbox folder name ─────────────────
// "08_11AUG_LuciaRossi(Amisano-Roberto)_START…" → "LuciaRossi"
// "LuciaRossi(Amisano-Roberto)_PROGRESS"        → "LuciaRossi"
// "LaSala"  (GRP sub)                           → "LaSala"
function reconcile_extractName(string $folder): string {
    $s = preg_replace('/^\d+_/', '', $folder);        // strip leading sequence "08_"
    $s = preg_replace('/^\d{2}[A-Z]{3}_/i', '', $s); // strip date "11AUG_"
    if (preg_match('/^([^(_]+)/', $s, $m)) return trim($m[1]);
    return trim($s);
}

// ── Helper: normalise for comparison ─────────────────────────────────────────
function reconcile_norm(string $s): string {
    return strtolower(preg_replace('/[\s\'\-\.]+/', '', $s));
}

// ── APPLY action ─────────────────────────────────────────────────────────────
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

// ── SCAN action ───────────────────────────────────────────────────────────────
$results    = [];
$scanError  = '';
$scanned    = false;
$countExact = 0; // exact matches skipped

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'scan') {
    $scanned = true;

    // Load all active requests into memory
    $allReqs = $db->query(
        "SELECT id, customer_name, practice_code, group_folder, status, agent_id
         FROM requests WHERE status NOT IN ('Cancelled','Lost')"
    )->fetchAll();

    // Index: lowercase(practice_code) → request  (for exact-match detection)
    $byPC = [];
    foreach ($allReqs as $r) {
        if ($r['practice_code'] !== null && $r['practice_code'] !== '') {
            $byPC[strtolower($r['practice_code'])] = $r;
        }
    }

    // Index: normalised(customer_name) → [requests]
    $byName = [];
    foreach ($allReqs as $r) {
        $norm = reconcile_norm($r['customer_name']);
        $byName[$norm][] = $r;
    }

    // Match a folder name → returns ['exact'=>bool, 'confidence'=>str, 'matches'=>[]]
    function reconcile_match(string $folderName, array $byPC, array $byName): array {
        // Exact match (practice_code already correct) → skip
        if (isset($byPC[strtolower($folderName)])) {
            return ['exact' => true, 'confidence' => 'exact', 'matches' => [$byPC[strtolower($folderName)]]];
        }

        $custPart  = reconcile_extractName($folderName);
        $normCust  = reconcile_norm($custPart);

        // High: full normalised name match
        if (isset($byName[$normCust])) {
            return ['exact' => false, 'confidence' => 'high', 'matches' => $byName[$normCust]];
        }

        // Medium: one is a substring of the other (min 4 chars to avoid false positives)
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

    require_once 'dropbox_helper.php';

    try {
        $token = dropbox_get_access_token();

        // ── 001_Safari ──────────────────────────────────────────────────────
        $safariDirs = dropbox_list_folder($token, '/001_Safari');
        sort($safariDirs);

        foreach ($safariDirs as $dirName) {
            $isGrp = stripos($dirName, 'GRP') !== false;

            if ($isGrp) {
                // Scan subfolders (each subfolder = one request's practice_code)
                try {
                    $subs = dropbox_list_folder($token, '/001_Safari/' . $dirName);
                    sort($subs);
                    foreach ($subs as $subName) {
                        $m = reconcile_match($subName, $byPC, $byName);
                        if ($m['exact']) { $countExact++; continue; }
                        $results[] = [
                            'source'     => '001_Safari',
                            'folder'     => $subName,
                            'parent'     => $dirName,
                            'is_grp'     => true,
                            'confidence' => $m['confidence'],
                            'matches'    => $m['matches'],
                        ];
                    }
                } catch (RuntimeException) { /* skip unreadable GRP */ }
            } else {
                $m = reconcile_match($dirName, $byPC, $byName);
                if ($m['exact']) { $countExact++; continue; }
                $results[] = [
                    'source'     => '001_Safari',
                    'folder'     => $dirName,
                    'parent'     => null,
                    'is_grp'     => false,
                    'confidence' => $m['confidence'],
                    'matches'    => $m['matches'],
                ];
            }
        }

        // ── 2026 ────────────────────────────────────────────────────────────
        $dirs2026 = dropbox_list_folder($token, '/2026');
        sort($dirs2026);

        foreach ($dirs2026 as $dirName) {
            $m = reconcile_match($dirName, $byPC, $byName);
            if ($m['exact']) { $countExact++; continue; }
            $results[] = [
                'source'     => '2026',
                'folder'     => $dirName,
                'parent'     => null,
                'is_grp'     => false,
                'confidence' => $m['confidence'],
                'matches'    => $m['matches'],
            ];
        }

    } catch (RuntimeException $e) {
        $scanError = $e->getMessage();
    }
}

$agentMap = [];
foreach ($db->query("SELECT id, name FROM agents") as $ag) {
    $agentMap[$ag['id']] = $ag['name'];
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
  width:7px; height:7px; border-radius:50%;
  background:var(--red);
}
.badge-high   { background:#D1FAE5; color:#065F46; }
.badge-medium { background:#FEF9C3; color:#854D0E; }
.badge-none   { background:#F3F4F6; color:#6B7280; }
.rc-grp-tag {
  font-size:.68rem; background:var(--red-lt); color:var(--red-dk);
  border-radius:4px; padding:1px 6px; font-weight:700;
}
.rc-folder {
  font-family: 'Courier New', monospace;
  font-size:.78rem; word-break:break-all;
}
.rc-parent { font-size:.68rem; color:var(--grey-mid); margin-top:2px; }
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

  <div class="form-card" style="max-width:560px;">
    <p style="margin-bottom:16px;font-size:.88rem;color:var(--grey-dk);">
      Scans <strong>/001_Safari/</strong> then <strong>/2026/</strong> in Dropbox.
      Folders whose name already matches <code>practice_code</code> in the DB are skipped.
      Only mismatches are shown for review.
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="scan">
      <button type="submit" class="btn btn-red">🔍 Scan Dropbox</button>
    </form>
  </div>

<?php else: ?>

  <?php
  $countHigh   = count(array_filter($results, fn($r) => $r['confidence'] === 'high'));
  $countMedium = count(array_filter($results, fn($r) => $r['confidence'] === 'medium'));
  $countNone   = count(array_filter($results, fn($r) => $r['confidence'] === 'none'));
  $total       = count($results);
  ?>

  <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:20px;font-size:.82rem;">
    <span>✅ Exact match (skipped): <strong><?= $countExact ?></strong></span>
    <span style="color:#065F46;">🟢 High confidence: <strong><?= $countHigh ?></strong></span>
    <span style="color:#854D0E;">🟡 Needs review: <strong><?= $countMedium ?></strong></span>
    <span style="color:var(--grey-mid);">⚪ No match: <strong><?= $countNone ?></strong></span>
  </div>

  <?php if ($total === 0 && !$scanError): ?>
    <div class="flash flash-success">All Dropbox folders already match DB records. Nothing to reconcile.</div>
  <?php else: ?>

  <form method="POST">
    <input type="hidden" name="action" value="apply">

    <div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;">
      <button type="submit" class="btn btn-red" onclick="return confirm('Update practice_code for all checked rows?')">
        ✔ Apply Selected
      </button>
      <button type="button" class="btn btn-outline btn-sm"
              onclick="document.querySelectorAll('.row-sel').forEach(c=>c.checked=true)">
        Select all with match
      </button>
      <button type="button" class="btn btn-grey btn-sm"
              onclick="document.querySelectorAll('.row-sel').forEach(c=>c.checked=false)">
        Clear
      </button>
      <a href="reconcile.php" class="btn btn-outline btn-sm">↺ Re-scan</a>
    </div>

    <?php
    // Split by source for display
    $bySrc = ['001_Safari' => [], '2026' => []];
    foreach ($results as $r) $bySrc[$r['source']][] = $r;

    foreach (['001_Safari', '2026'] as $src):
      $rows = $bySrc[$src];
      if (!$rows) continue;
    ?>

    <div class="rc-section-hdr"><?= h($src) ?> &nbsp;(<?= count($rows) ?> mismatch<?= count($rows)!==1?'es':'' ?>)</div>

    <div class="table-wrap" style="margin-bottom:24px;">
    <table class="table">
      <thead>
        <tr>
          <th style="width:32px;"></th>
          <th>Dropbox Folder</th>
          <th>Match</th>
          <th style="width:120px;">Confidence</th>
          <th style="width:200px;">Link to Request</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $row):
        $hasMatch   = !empty($row['matches']);
        $bestMatch  = $hasMatch ? $row['matches'][0] : null;
        $confidence = $row['confidence'];
        $badgeCls   = match($confidence) {
          'high'   => 'badge-high',
          'medium' => 'badge-medium',
          default  => 'badge-none',
        };
        $badgeLbl = match($confidence) {
          'high'   => '🟢 High',
          'medium' => '🟡 Review',
          default  => '⚪ None',
        };

        // Build the hidden value for apply: folder_name + request_id (from the select)
        $selectId  = 'sel_' . md5($row['folder'] . $row['source'] . ($row['parent'] ?? ''));
        $applyId   = 'apply_' . $selectId;
        $checkId   = 'chk_' . $selectId;

        // Default selected request_id
        $defaultReqId = $bestMatch ? $bestMatch['id'] : '';
        $encodedVal   = base64_encode(json_encode([
          'folder_name' => $row['folder'],
          'request_id'  => $defaultReqId,
        ]));
      ?>
      <tr>
        <td>
          <?php if ($hasMatch): ?>
            <input type="checkbox" class="row-sel" id="<?= $checkId ?>" style="width:14px;height:14px;">
            <input type="hidden" name="sel[]" id="<?= $applyId ?>" value="<?= h($encodedVal) ?>"
                   data-folder="<?= h($row['folder']) ?>">
          <?php endif; ?>
        </td>

        <td>
          <div class="rc-folder"><?= h($row['folder']) ?></div>
          <?php if ($row['is_grp']): ?>
            <div class="rc-parent"><span class="rc-grp-tag">GRP</span> in <?= h($row['parent']) ?></div>
          <?php elseif ($row['parent']): ?>
            <div class="rc-parent">📁 <?= h($row['parent']) ?></div>
          <?php endif; ?>
        </td>

        <td style="font-size:.78rem;">
          <?php if (!$hasMatch): ?>
            <span style="color:var(--grey-mid);">No request found</span>
          <?php elseif (count($row['matches']) === 1): ?>
            <a href="request_view.php?id=<?= $bestMatch['id'] ?>" target="_blank" style="font-weight:600;">
              <?= h($bestMatch['customer_name']) ?>
            </a>
            <?php if ($bestMatch['practice_code']): ?>
              <div style="color:var(--grey-mid);font-size:.72rem;margin-top:2px;">
                DB: <code><?= h($bestMatch['practice_code']) ?></code>
              </div>
            <?php else: ?>
              <div style="color:var(--grey-mid);font-size:.72rem;margin-top:2px;">DB: <em>no practice_code</em></div>
            <?php endif; ?>
          <?php else: ?>
            <div style="color:var(--grey-mid);font-size:.72rem;margin-bottom:4px;">Multiple candidates:</div>
            <?php if ($bestMatch): ?>
              <a href="request_view.php?id=<?= $bestMatch['id'] ?>" target="_blank" style="font-weight:600;">
                <?= h($bestMatch['customer_name']) ?>
              </a>
              <?php if ($bestMatch['practice_code']): ?>
                <div style="color:var(--grey-mid);font-size:.72rem;margin-top:2px;">
                  DB: <code><?= h($bestMatch['practice_code']) ?></code>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          <?php endif; ?>
        </td>

        <td>
          <span class="badge <?= $badgeCls ?>"><?= $badgeLbl ?></span>
        </td>

        <td>
          <?php if (count($row['matches']) > 1): ?>
            <select class="match-select" id="<?= $selectId ?>"
                    onchange="updateApply('<?= $applyId ?>', '<?= addslashes($row['folder']) ?>', this.value, '<?= $checkId ?>')">
              <option value="">— choose —</option>
              <?php foreach ($row['matches'] as $m): ?>
                <option value="<?= $m['id'] ?>" <?= $m['id'] === $defaultReqId ? 'selected' : '' ?>>
                  #<?= $m['id'] ?> <?= h($m['customer_name']) ?>
                  <?= $m['practice_code'] ? ' ['.h(substr($m['practice_code'],0,20)).']' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php elseif ($hasMatch): ?>
            <span style="font-size:.75rem;color:var(--grey-mid);">#<?= $bestMatch['id'] ?> auto-linked</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php endforeach; ?>

    <div style="margin-top:8px;">
      <button type="submit" class="btn btn-red" onclick="return confirm('Update practice_code for all checked rows?')">
        ✔ Apply Selected
      </button>
    </div>
  </form>

  <?php endif; ?>
<?php endif; ?>

<script>
function updateApply(applyId, folderName, requestId, checkId) {
  const encoded = btoa(JSON.stringify({ folder_name: folderName, request_id: requestId ? parseInt(requestId) : '' }));
  document.getElementById(applyId).value = encoded;
  // Auto-check when a request is selected
  if (requestId) document.getElementById(checkId).checked = true;
}
</script>

<?php include 'includes/footer.php'; ?>
