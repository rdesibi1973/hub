<?php
require_once 'config.php';

// Admin/manager only
if (!isInvoiceAdmin()) {
    header('Location: invoices.php');
    exit;
}

require_once __DIR__ . '/../leads/dropbox_constants.php';
require_once __DIR__ . '/../leads/dropbox_helper.php';

$pageTitle = 'Invoice Check — Dropbox';

// ── Month selection ──────────────────────────────────────────────────────────
$months = [
    '01'=>'January','02'=>'February','03'=>'March','04'=>'April',
    '05'=>'May','06'=>'June','07'=>'July','08'=>'August',
    '09'=>'September','10'=>'October','11'=>'November','12'=>'December',
];
$selectedMonth = $_GET['month'] ?? date('m');
if (!array_key_exists($selectedMonth, $months)) {
    $selectedMonth = date('m');
}

// ── Run scan only when requested ─────────────────────────────────────────────
$results   = null;   // null = not run yet
$scanError = null;
$scanYear  = date('Y');

if (isset($_GET['scan'])) {
    try {
        $token       = dropbox_get_access_token();
        $safariPath  = '/001_safari';
        $prefix      = $selectedMonth . '_';          // e.g. "06_"
        $invoicePat  = '/^Invoice S[EH]-\d{4}-\d+/i'; // Invoice SE-2026-XXXX or SH-

        // List all folders in /001_safari
        $allFolders = dropbox_list_folder($token, $safariPath);

        // Filter to selected month
        $monthFolders = array_filter($allFolders, fn($f) => stripos($f, $prefix) === 0);
        sort($monthFolders);

        $results = [];

        foreach ($monthFolders as $folder) {
            $isGrp      = stripos($folder, 'GRP') !== false;
            $folderPath = $safariPath . '/' . $folder;

            if ($isGrp) {
                // ── GRP folder: check each sub-folder ──────────────────────
                $subFolders = dropbox_list_folder($token, $folderPath);
                sort($subFolders);

                $subResults = [];
                foreach ($subFolders as $sub) {
                    $subPath  = $folderPath . '/' . $sub;
                    $subFiles = dropbox_list_files($token, $subPath);
                    $hasInv   = false;
                    foreach ($subFiles as $fname) {
                        if (preg_match($invoicePat, $fname)) { $hasInv = true; break; }
                    }
                    $subResults[] = [
                        'name'    => $sub,
                        'path'    => $subPath,
                        'has_inv' => $hasInv,
                    ];
                }

                $results[] = [
                    'folder'  => $folder,
                    'path'    => $folderPath,
                    'is_grp'  => true,
                    'subs'    => $subResults,
                    'has_inv' => null, // GRP itself doesn't need an invoice
                ];

            } else {
                // ── Normal folder: check for invoice ───────────────────────
                $files  = dropbox_list_files($token, $folderPath);
                $hasInv = false;
                foreach ($files as $fname) {
                    if (preg_match($invoicePat, $fname)) { $hasInv = true; break; }
                }

                $results[] = [
                    'folder'  => $folder,
                    'path'    => $folderPath,
                    'is_grp'  => false,
                    'subs'    => [],
                    'has_inv' => $hasInv,
                ];
            }
        }

    } catch (\Throwable $e) {
        $scanError = $e->getMessage();
    }
}

// ── Summary counts ────────────────────────────────────────────────────────────
$totalFolders  = 0;
$missingCount  = 0;
if ($results !== null) {
    foreach ($results as $r) {
        if ($r['is_grp']) {
            foreach ($r['subs'] as $s) {
                $totalFolders++;
                if (!$s['has_inv']) $missingCount++;
            }
        } else {
            $totalFolders++;
            if (!$r['has_inv']) $missingCount++;
        }
    }
}

include 'includes/header.php';

/**
 * List files (not folders) one level deep in a Dropbox path.
 */
function dropbox_list_files(string $token, string $path): array
{
    $names  = [];
    $cursor = null;

    do {
        if ($cursor) {
            $url     = 'https://api.dropboxapi.com/2/files/list_folder/continue';
            $payload = json_encode(['cursor' => $cursor]);
        } else {
            $url     = 'https://api.dropboxapi.com/2/files/list_folder';
            $payload = json_encode(['path' => $path, 'recursive' => false]);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            // Folder might be empty or inaccessible — return empty
            return [];
        }
        $data = json_decode($body, true);
        foreach ($data['entries'] ?? [] as $entry) {
            if ($entry['.tag'] === 'file') {
                $names[] = $entry['name'];
            }
        }
        $cursor  = $data['cursor']   ?? null;
        $hasMore = $data['has_more'] ?? false;
    } while ($hasMore && $cursor);

    return $names;
}
?>

<style>
.check-wrap   { max-width: 900px; }
.filter-bar   { display:flex; align-items:center; gap:12px; margin-bottom:24px; flex-wrap:wrap; }
.filter-bar select { padding:7px 12px; border:1px solid var(--grey-lt); border-radius:6px; font-size:.85rem; }
.filter-bar .btn  { padding:8px 22px; }

.summary-row  { display:flex; gap:14px; margin-bottom:22px; flex-wrap:wrap; }
.s-card       { border-radius:8px; padding:12px 20px; min-width:130px; text-align:center; }
.s-card .num  { font-size:1.6rem; font-weight:700; }
.s-card .lbl  { font-size:.72rem; color:var(--grey-mid); margin-top:2px; }
.s-card.blue  { background:#E3F2FD; }  .s-card.blue  .num { color:#1565C0; }
.s-card.green { background:#E8F5E9; }  .s-card.green .num { color:#2E7D32; }
.s-card.red   { background:#FFEBEE; }  .s-card.red   .num { color:#C62828; }

.folder-table         { width:100%; border-collapse:collapse; font-size:.84rem; }
.folder-table th      { text-align:left; padding:9px 12px; border-bottom:2px solid var(--grey-lt);
                        font-size:.75rem; color:var(--grey-mid); text-transform:uppercase; letter-spacing:.04em; }
.folder-table td      { padding:8px 12px; border-bottom:1px solid var(--grey-lt); vertical-align:middle; }
.folder-table tr:hover td { background:#FAFAFA; }

.badge-ok     { display:inline-block; background:#E8F5E9; color:#2E7D32;
                border-radius:4px; padding:2px 9px; font-size:.75rem; font-weight:600; }
.badge-miss   { display:inline-block; background:#FFEBEE; color:#C62828;
                border-radius:4px; padding:2px 9px; font-size:.75rem; font-weight:600; }
.badge-grp    { display:inline-block; background:#FFF8E1; color:#E65100;
                border-radius:4px; padding:2px 9px; font-size:.72rem; font-weight:600; margin-left:6px; }

.grp-parent   { background:#FFFDE7 !important; }
.grp-parent td { font-weight:600; color:#5D4037; padding-top:10px; }
.sub-row td:first-child { padding-left:32px; color:var(--grey-mid); }
.sub-row td:first-child::before { content:'↳ '; color:#BDBDBD; }

.folder-name  { font-family: monospace; font-size:.82rem; }
.empty-state  { text-align:center; padding:48px 20px; color:var(--grey-mid); font-size:.9rem; }
.error-box    { background:#FFEBEE; border:1px solid #EF9A9A; border-radius:8px;
                padding:14px 18px; color:#B71C1C; font-size:.85rem; margin-bottom:20px; }

.legend       { font-size:.75rem; color:var(--grey-mid); margin-top:12px; }
.legend span  { margin-right:16px; }

/* Show/hide missing only */
.show-missing-only .row-ok   { display:none; }
</style>

<div class="page-header">
  <div>
    <h2>Invoice Check — Dropbox</h2>
    <div class="sub">Verify that safari folders have an invoice PDF</div>
  </div>
</div>

<div class="check-wrap">

  <!-- Filter bar -->
  <form method="get" class="filter-bar">
    <label style="font-size:.84rem;font-weight:600;">Month:</label>
    <select name="month">
      <?php foreach ($months as $val => $lbl): ?>
        <option value="<?= $val ?>" <?= $val === $selectedMonth ? 'selected' : '' ?>>
          <?= $lbl ?> <?= $scanYear ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" name="scan" value="1" class="btn btn-red">🔍 Scan Dropbox</button>
    <?php if ($results !== null): ?>
      <label style="font-size:.82rem;margin-left:8px;cursor:pointer;display:flex;align-items:center;gap:6px;">
        <input type="checkbox" id="chkMissing" onchange="toggleMissing(this)">
        Show missing only
      </label>
    <?php endif; ?>
  </form>

  <?php if ($scanError): ?>
    <div class="error-box">⚠️ Dropbox error: <?= h($scanError) ?></div>
  <?php endif; ?>

  <?php if ($results !== null && !$scanError): ?>

    <!-- Summary -->
    <div class="summary-row">
      <div class="s-card blue">
        <div class="num"><?= count($results) ?></div>
        <div class="lbl">Folders scanned</div>
      </div>
      <div class="s-card blue">
        <div class="num"><?= $totalFolders ?></div>
        <div class="lbl">Safari practices</div>
      </div>
      <div class="s-card green">
        <div class="num"><?= $totalFolders - $missingCount ?></div>
        <div class="lbl">✓ Have invoice</div>
      </div>
      <div class="s-card <?= $missingCount > 0 ? 'red' : 'green' ?>">
        <div class="num"><?= $missingCount ?></div>
        <div class="lbl"><?= $missingCount > 0 ? '✗ Missing invoice' : '✓ All invoiced' ?></div>
      </div>
    </div>

    <?php if (empty($results)): ?>
      <div class="empty-state">No folders starting with <strong><?= h($selectedMonth) ?>_</strong> found in /001_safari.</div>
    <?php else: ?>

      <div id="folderTable">
        <table class="folder-table">
          <thead>
            <tr>
              <th style="width:60%">Folder / Client</th>
              <th>Type</th>
              <th>Invoice</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($results as $r): ?>

            <?php if ($r['is_grp']): ?>
              <!-- GRP parent row -->
              <tr class="grp-parent">
                <td class="folder-name">
                  📁 <?= h($r['folder']) ?>
                  <span class="badge-grp">GRP</span>
                </td>
                <td style="color:#795548;font-size:.78rem;">Group</td>
                <td>—</td>
              </tr>
              <!-- Sub-client rows -->
              <?php foreach ($r['subs'] as $sub): ?>
                <?php $rowClass = $sub['has_inv'] ? 'row-ok' : 'row-miss'; ?>
                <tr class="sub-row <?= $rowClass ?>">
                  <td class="folder-name"><?= h($sub['name']) ?></td>
                  <td style="color:var(--grey-mid);font-size:.78rem;">Client</td>
                  <td>
                    <?php if ($sub['has_inv']): ?>
                      <span class="badge-ok">✓ OK</span>
                    <?php else: ?>
                      <span class="badge-miss">✗ Missing</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>

            <?php else: ?>
              <!-- Normal folder row -->
              <?php $rowClass = $r['has_inv'] ? 'row-ok' : 'row-miss'; ?>
              <tr class="<?= $rowClass ?>">
                <td class="folder-name">📁 <?= h($r['folder']) ?></td>
                <td style="color:var(--grey-mid);font-size:.78rem;">Individual</td>
                <td>
                  <?php if ($r['has_inv']): ?>
                    <span class="badge-ok">✓ OK</span>
                  <?php else: ?>
                    <span class="badge-miss">✗ Missing</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endif; ?>

          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="legend">
        <span>Looks for: <code>Invoice SE-2026-XXXX.pdf</code> or <code>Invoice SH-2026-XXXX.pdf</code></span>
        <span>Path: <code>/001_safari/<?= h($selectedMonth) ?>_*</code></span>
      </div>

    <?php endif; ?>

  <?php elseif ($results === null && !$scanError): ?>
    <div class="empty-state" style="padding:60px 20px;">
      Select a month and click <strong>Scan Dropbox</strong> to check which safari folders are missing an invoice.
    </div>
  <?php endif; ?>

</div><!-- .check-wrap -->

<script>
function toggleMissing(cb) {
    const rows = document.querySelectorAll('.row-ok');
    rows.forEach(r => r.style.display = cb.checked ? 'none' : '');
}
</script>

<?php include 'includes/footer.php'; ?>
