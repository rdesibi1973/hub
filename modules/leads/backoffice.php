<?php
/**
 * backoffice.php — Hub BackOffice (replaces the Java desktop tool, incrementally).
 *
 * Feature 1: Change booking status.
 *   Renames the Dropbox folder server-side (Dropbox API) and updates the DB
 *   (practice_code, dropbox_url, status, payment_status) in one atomic action.
 *   No local Dropbox sync / Java runtime needed — works from any browser.
 *
 * Scope of this first version: private safaris (non-GRP). Group bookings share
 * a parent folder that carries the status tag, so they are handled separately
 * (coming next) and are blocked here with a clear message.
 */
require_once 'config.php';
require_once 'includes/folder_parser.php';
$pageTitle = 'BackOffice';
$db = db();

// ── Access: admin + manager only ──────────────────────────────────────────────
$currentUser = current_user();
if (!in_array($currentUser['role_name'] ?? '', ['admin','manager'], true)) {
    flash('Access denied.', 'error');
    header('Location: requests.php'); exit;
}

// ── Target status → folder tag + DB status/payment_status ─────────────────────
// Mirrors the Java tool and api_rename_folder.php. A null 'ps' clears payment_status.
$STATUS_MAP = [
    'Progress'     => ['tag' => 'PROGRESS',     'status' => 'Booked',      'ps' => null],
    'Provisional'  => ['tag' => 'PROVISIONAL',  'status' => 'Provisional', 'ps' => null],
    'Deposit'      => ['tag' => 'DEPOSIT',      'status' => 'Booked',      'ps' => 'Deposit'],
    'Balance'      => ['tag' => 'BALANCE',      'status' => 'Booked',      'ps' => 'Balance'],
    'Balance-Cash' => ['tag' => 'BALANCE-CASH', 'status' => 'Booked',      'ps' => 'Balance-Cash'],
    'Paid'         => ['tag' => 'PAID',         'status' => 'Booked',      'ps' => 'Paid'],
    'Cancelled'    => ['tag' => 'CANCELLED',    'status' => 'Cancelled',   'ps' => null],
];

// Existing folder tags to detect + replace (longest / most specific first).
$KNOWN_TAGS = ['_BALANCE-CASH','_BALANCE_CASH','_BALANCE','_DEPOSIT','_PAID',
               '_PROGRESS','_CONFIRMED','_PROVISIONAL','_CANCELLED'];

/**
 * Build the new folder name: replace the current status tag in place (keeping any
 * trailing marker such as _CK), or append the new tag if none is present.
 */
function bo_new_folder_name(string $folder, string $newTag, array $knownTags): string {
    foreach ($knownTags as $tag) {
        if (stripos($folder, $tag) !== false) {
            return str_ireplace($tag, '_' . $newTag, $folder);
        }
    }
    return $folder . '_' . $newTag;
}

/** Rebuild a Dropbox web URL from an API path_display ('/001_Safari/Foo/Bar'). */
function bo_url_from_path(string $path): string {
    $enc = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    return 'https://www.dropbox.com/home/' . $enc;
}

// ── Apply a status change ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_status') {
    $reqId   = (int)($_POST['request_id'] ?? 0);
    $target  = trim($_POST['new_status'] ?? '');

    $stmt = $db->prepare("SELECT id, customer_name, practice_code, group_folder, dropbox_url, status, payment_status
                          FROM requests WHERE id = ?");
    $stmt->execute([$reqId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$r) {
        flash('Request not found.', 'error');
    } elseif (!isset($STATUS_MAP[$target])) {
        flash('Invalid target status.', 'error');
    } elseif (trim($r['group_folder'] ?? '') !== '') {
        flash('This is a group booking (GRP). Group status changes are not handled here yet — use the desktop tool for now.', 'error');
    } else {
        $folder = trim($r['practice_code'] ?? '');
        if ($folder === '') {
            flash('This request has no folder (practice_code) to rename.', 'error');
        } else {
            $newTag    = $STATUS_MAP[$target]['tag'];
            $newFolder = bo_new_folder_name($folder, $newTag, $KNOWN_TAGS);

            if (strcasecmp($newFolder, $folder) === 0) {
                flash('The folder is already at that status — nothing to change.', 'error');
            } else {
                require_once 'dropbox_helper.php';
                try {
                    $token   = dropbox_get_access_token();
                    // Locate the real folder by its current name (robust against a stale dropbox_url).
                    $curPath = dropbox_find_folder($token, $folder);
                    if ($curPath === null) {
                        flash('Could not find the folder "' . $folder . '" in Dropbox. Check the name, then retry.', 'error');
                    } else {
                        $parent  = rtrim(substr($curPath, 0, strrpos($curPath, '/')), '/');
                        $newPath = $parent . '/' . $newFolder;

                        dropbox_move_folder($token, $curPath, $newPath);

                        // Dropbox rename OK → update the DB.
                        $newUrl = bo_url_from_path($newPath);
                        $upd = $db->prepare("UPDATE requests
                                             SET practice_code = ?, dropbox_url = ?, status = ?, payment_status = ?
                                             WHERE id = ?");
                        $upd->execute([
                            $newFolder,
                            $newUrl,
                            $STATUS_MAP[$target]['status'],
                            $STATUS_MAP[$target]['ps'], // null clears it
                            $reqId,
                        ]);
                        flash('✔ ' . $r['customer_name'] . ': renamed to "' . $newFolder . '" and set to ' . $target . '.');
                    }
                } catch (Throwable $e) {
                    flash('Dropbox/DB error — nothing was changed: ' . $e->getMessage(), 'error');
                }
            }
        }
    }
    $qs = array_filter([
        'q'    => trim($_POST['q'] ?? ''),
        'root' => trim($_POST['root'] ?? ''),
    ], fn($x) => $x !== '');
    header('Location: backoffice.php' . ($qs ? '?' . http_build_query($qs) : ''));
    exit;
}

// ── Search ────────────────────────────────────────────────────────────────────
// Folder-root filter: which Dropbox root the request's folder lives in
// (matched literally against dropbox_url). 'All' removes the restriction.
$ROOT_MAP = [
    '2026'       => '/home/2026/',
    '001_Safari' => '/home/001_Safari/',
    'Contracts'  => '/home/00_Contracts/',
];
$q    = trim($_GET['q'] ?? '');
$root = $_GET['root'] ?? '2026';
if ($root !== 'All' && !isset($ROOT_MAP[$root])) $root = '2026';

$rows = [];
if ($q !== '') {
    $like   = '%' . $q . '%';
    $sql    = "SELECT r.id, r.customer_name, r.practice_code, r.group_folder, r.status, r.payment_status,
                      r.dropbox_url, a.name AS agent_name
               FROM requests r LEFT JOIN agents a ON a.id = r.agent_id
               WHERE r.status NOT IN ('Cancelled','Lost')
                 AND (r.customer_name LIKE ? OR r.practice_code LIKE ? OR r.group_folder LIKE ?)";
    $params = [$like, $like, $like];
    if (isset($ROOT_MAP[$root])) {
        $sql     .= " AND LOCATE(?, r.dropbox_url) > 0";
        $params[] = $ROOT_MAP[$root];
    }
    $sql .= " ORDER BY r.id DESC LIMIT 60";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$extra_css = '
.bo-note{background:#EAF1F8;border-left:4px solid #1a3a5c;padding:12px 16px;border-radius:8px;font-size:.85rem;color:#1a3a5c;margin-bottom:18px}
.bo-table{width:100%;border-collapse:collapse}
.bo-table th{text-align:left;font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;color:var(--grey-mid);padding:8px 10px;border-bottom:1px solid var(--grey-lt)}
.bo-table td{padding:8px 10px;border-bottom:1px solid var(--grey-lt);font-size:.83rem;vertical-align:middle}
.bo-folder{font-family:monospace;font-size:.75rem;word-break:break-all}
.bo-grp{font-size:.66rem;color:#8a6d3b;background:#fcf3e3;border-radius:6px;padding:1px 5px;margin-left:4px}
';
include 'includes/header.php';
?>

<div class="page-header">
  <h2>🛠 BackOffice — Change booking status</h2>
</div>

<div class="bo-note">
  Renames the Dropbox folder <strong>server-side</strong> (no Java, no local Dropbox needed) and updates
  the request status + payment in one step. First version handles <strong>private safaris</strong>;
  group bookings (GRP) are coming next.
</div>

<form method="GET" class="filters">
  <div>
    <label>Search</label>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Customer or folder…" autofocus style="width:260px">
  </div>
  <div>
    <label>Folder</label>
    <select name="root">
      <?php foreach (['2026'=>'2026','001_Safari'=>'001_Safari','Contracts'=>'Contracts','All'=>'All'] as $val=>$lbl): ?>
        <option value="<?= h($val) ?>" <?= $root===$val?'selected':'' ?>><?= h($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>&nbsp;</label>
    <button type="submit" class="btn btn-outline">Search</button>
  </div>
</form>

<?php if ($q !== ''): ?>
  <?php if (!$rows): ?>
    <p style="color:var(--grey-mid);padding:20px">No matching bookings.</p>
  <?php else: ?>
  <div class="table-wrap">
  <table class="bo-table">
    <thead>
      <tr>
        <th>Customer</th>
        <th>Current folder</th>
        <th style="width:110px">Status</th>
        <th style="width:320px">Change to</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $isGrp   = trim($r['group_folder'] ?? '') !== '';
        $folder  = $isGrp ? trim($r['group_folder']) : trim($r['practice_code'] ?? '');
        $psLabel = $r['payment_status'] ?: $r['status'];
    ?>
      <tr>
        <td>
          <strong><?= h($r['customer_name']) ?></strong>
          <?php if ($r['agent_name']): ?><div style="font-size:.7rem;color:var(--grey-mid)">👤 <?= h($r['agent_name']) ?></div><?php endif; ?>
        </td>
        <td class="bo-folder">
          📁 <?= h($folder ?: '—') ?><?php if ($isGrp): ?><span class="bo-grp">GRP</span><?php endif; ?>
          <?php $sPath = savannah_local_path($r); $sUrl = savannah_open_url($r); ?>
          <?php if ($sPath !== ''): ?>
            <div style="margin-top:3px;font-family:'Open Sans',sans-serif">
              <a href="<?= h($sUrl) ?>" title="Open in Windows Explorer" style="font-size:.68rem;text-decoration:none">📂 Open</a>
              <a href="#" data-path="<?= h($sPath) ?>" onclick="copyPath(this);return false" title="Copy Windows path" style="font-size:.68rem;text-decoration:none;margin-left:8px">📋 Copy path</a>
            </div>
          <?php endif; ?>
        </td>
        <td><span class="badge"><?= h($psLabel) ?></span></td>
        <td>
          <?php if ($isGrp): ?>
            <span style="font-size:.75rem;color:var(--grey-mid)">Group booking — use the desktop tool for now.</span>
          <?php else: ?>
          <form method="POST" style="display:flex;gap:6px;align-items:center;margin:0"
                onsubmit="return confirm('Rename the Dropbox folder and set this booking to ' + this.new_status.value + '?\n\nThis renames the real Dropbox folder.');">
            <input type="hidden" name="action" value="change_status">
            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="q" value="<?= h($q) ?>">
            <input type="hidden" name="root" value="<?= h($root) ?>">
            <select name="new_status" class="m-input" style="width:150px;padding:5px 8px;font-size:.8rem">
              <?php foreach (array_keys($STATUS_MAP) as $st): ?>
                <option value="<?= h($st) ?>"><?= h($st) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-red btn-sm">Apply</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
<?php else: ?>
  <p style="color:var(--grey-mid);padding:20px">Search a customer name or folder to begin.</p>
<?php endif; ?>

<script>
// Copy a Windows path to the clipboard (paste into Explorer's address bar).
function copyPath(el) {
  var t = el.getAttribute('data-path') || '';
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(t).then(function(){ flashCopied(el); }, function(){ fallbackCopy(t, el); });
  } else { fallbackCopy(t, el); }
}
function fallbackCopy(t, el) {
  var ta = document.createElement('textarea');
  ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0';
  document.body.appendChild(ta); ta.select();
  try { document.execCommand('copy'); flashCopied(el); } catch (e) { prompt('Copy this path:', t); }
  document.body.removeChild(ta);
}
function flashCopied(el) { var o = el.textContent; el.textContent = '✓ Copied'; setTimeout(function(){ el.textContent = o; }, 1200); }
</script>

<?php include 'includes/footer.php'; ?>
