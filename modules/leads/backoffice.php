<?php
/**
 * backoffice.php — Hub BackOffice (replaces the Java desktop tool, incrementally).
 *
 * Feature 1: Change booking status.
 *   Renames the Dropbox folder server-side (Dropbox API) and updates the DB
 *   (practice_code, dropbox_url, status, payment_status) in one atomic action.
 *   No local Dropbox sync / Java runtime needed — works from any browser.
 *
 * Handles private safaris and group bookings. A group carries its status tag on
 * the shared parent folder: changing a group renames that parent and updates
 * every request in the group (status, payment_status and each sub-booking's
 * dropbox_url), since the subfolders move with the parent.
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

// Folder suffix → [status, payment_status]. "Contains" match, longest first
// (tolerates a trailing _CK). Used to keep the DB in sync after a free rename.
$BO_TAG_STATUS = [
    '_BALANCE-CASH' => ['Booked',      'Balance-Cash'],
    '_BALANCE_CASH' => ['Booked',      'Balance-Cash'],
    '_BALANCE'      => ['Booked',      'Balance'],
    '_DEPOSIT'      => ['Booked',      'Deposit'],
    '_PAID'         => ['Booked',      'Paid'],
    '_PROGRESS'     => ['Booked',      null],
    '_CONFIRMED'    => ['Booked',      null],
    '_PROVISIONAL'  => ['Provisional', null],
    '_CANCELLED'    => ['Cancelled',   null],
];

/** Derive [status, payment_status, matched] from a folder name, or matched=false. */
function bo_status_from_name(string $name, array $tagStatus): array {
    $up = strtoupper($name);
    foreach ($tagStatus as $tag => $sp) {
        if (strpos($up, $tag) !== false) return ['status' => $sp[0], 'ps' => $sp[1], 'matched' => true];
    }
    return ['status' => null, 'ps' => null, 'matched' => false];
}

/**
 * Rename a booking's Dropbox folder (private = its own folder; group = the shared
 * parent) and sync the DB. When $setStatus, also writes status/payment_status
 * (for a group, to every request in it, rebuilding each sub's dropbox_url).
 * Dropbox move happens first; the DB is only touched if it succeeds.
 */
function bo_do_rename(PDO $db, string $token, array $r, bool $isGrp,
                      string $folder, string $newFolder,
                      ?string $newStatus, $newPs, bool $setStatus): array {
    $curPath = dropbox_find_folder($token, $folder);
    if ($curPath === null) {
        return ['ok' => false, 'msg' => 'Could not find the folder "' . $folder . '" in Dropbox. Check the name, then retry.'];
    }
    $parentDir = rtrim(substr($curPath, 0, strrpos($curPath, '/')), '/');
    $newPath   = $parentDir . '/' . $newFolder;

    dropbox_move_folder($token, $curPath, $newPath);   // subfolders move with the parent

    if ($isGrp) {
        $subs = $db->prepare("SELECT id, practice_code FROM requests WHERE group_folder = ?");
        $subs->execute([$folder]);
        $rowsG = $subs->fetchAll(PDO::FETCH_ASSOC);
        $upd = $setStatus
            ? $db->prepare("UPDATE requests SET group_folder=?, dropbox_url=?, status=?, payment_status=? WHERE id=?")
            : $db->prepare("UPDATE requests SET group_folder=?, dropbox_url=? WHERE id=?");
        $n = 0;
        foreach ($rowsG as $g) {
            $sub    = trim($g['practice_code'] ?? '');
            $subUrl = bo_url_from_path($sub !== '' ? $newPath . '/' . $sub : $newPath);
            if ($setStatus) $upd->execute([$newFolder, $subUrl, $newStatus, $newPs, (int)$g['id']]);
            else            $upd->execute([$newFolder, $subUrl, (int)$g['id']]);
            $n++;
        }
        return ['ok' => true, 'msg' => '✔ Group "' . $newFolder . '": renamed (' . $n . ' booking(s) updated).'];
    }

    $newUrl = bo_url_from_path($newPath);
    if ($setStatus) {
        $db->prepare("UPDATE requests SET practice_code=?, dropbox_url=?, status=?, payment_status=? WHERE id=?")
           ->execute([$newFolder, $newUrl, $newStatus, $newPs, (int)$r['id']]);
    } else {
        $db->prepare("UPDATE requests SET practice_code=?, dropbox_url=? WHERE id=?")
           ->execute([$newFolder, $newUrl, (int)$r['id']]);
    }
    return ['ok' => true, 'msg' => '✔ ' . $r['customer_name'] . ': renamed to "' . $newFolder . '".'];
}

/** API path ('/001_Safari/…') from a stored dropbox_url, or '' if unparseable. */
function bo_path_from_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    $p = parse_url($url, PHP_URL_PATH);
    if (!$p) return '';
    $p = urldecode($p);
    $p = preg_replace('#^/?home/#i', '', ltrim($p, '/')); // strip leading /home/
    $p = trim((string)$p, '/');
    return $p !== '' ? '/' . $p : '';
}

/**
 * Re-group a confirmed booking that was filed under the wrong grouping: move ONLY
 * this booking's Dropbox folder and sync the DB. $targetGrp === '' → make it a
 * private (top-level) safari; otherwise it becomes a member of that GRP (the GRP
 * folder is created if it does not exist). GRP siblings are left untouched.
 *
 * The customer folder name (practice_code) is preserved — only its parent changes.
 */
function bo_do_regroup(PDO $db, string $token, array $r, string $targetGrp): array {
    $base = '/001_Safari';
    $cust = trim($r['practice_code'] ?? '');
    if ($cust === '') {
        return ['ok' => false, 'msg' => 'This booking has no customer folder (practice_code) to move.'];
    }

    // Where the folder is now: trust dropbox_url, fall back to group_folder/practice_code.
    $curPath = bo_path_from_url($r['dropbox_url'] ?? '');
    if ($curPath === '') {
        $oldGrp  = trim($r['group_folder'] ?? '');
        $curPath = $base . '/' . ($oldGrp !== '' ? $oldGrp . '/' : '') . $cust;
    }

    if ($targetGrp === '') {
        $newParent = $base;
        $newGroup  = null;
    } else {
        $newParent = $base . '/' . $targetGrp;
        $newGroup  = $targetGrp;
        dropbox_create_folder($token, $newParent, false); // idempotent: makes a new GRP, no-op if it exists
    }
    $newPath = $newParent . '/' . $cust;

    if (strcasecmp($newPath, $curPath) === 0) {
        return ['ok' => false, 'msg' => 'The booking is already in that location — nothing to change.'];
    }

    $oldParent = rtrim(substr($curPath, 0, (int)strrpos($curPath, '/')), '/');

    dropbox_move_folder($token, $curPath, $newPath); // 409 conflict (name clash) throws → caller reports it

    $db->prepare("UPDATE requests SET group_folder = ?, dropbox_url = ? WHERE id = ?")
       ->execute([$newGroup, bo_url_from_path($newPath), (int)$r['id']]);

    $msg = '✔ ' . ($r['customer_name'] ?? 'Booking') . ': moved to '
         . ($newGroup !== null ? 'group “' . $newGroup . '”' : 'private (no group)') . '.';

    // If it came out of a GRP that is now empty, flag the orphan folder (do not auto-delete).
    $wasGrp = trim($r['group_folder'] ?? '') !== '';
    if ($wasGrp && $oldParent !== $base && strcasecmp($oldParent, $newParent) !== 0) {
        try {
            if (count(dropbox_list_folder($token, $oldParent)) === 0) {
                $msg .= ' The old group folder “' . basename($oldParent)
                      . '” has no sub-folders left — delete it in Dropbox if it is no longer needed.';
            }
        } catch (Throwable $e) { /* advisory only — ignore */ }
    }
    return ['ok' => true, 'msg' => $msg];
}

// ── Actions: change status / free rename ──────────────────────────────────────
$act = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($act, ['change_status', 'rename'], true)) {
    $reqId = (int)($_POST['request_id'] ?? 0);

    $stmt = $db->prepare("SELECT id, customer_name, practice_code, group_folder, dropbox_url, status, payment_status
                          FROM requests WHERE id = ?");
    $stmt->execute([$reqId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$r) {
        flash('Request not found.', 'error');
    } elseif (($folder = ($isGrp = trim($r['group_folder'] ?? '') !== '')
                       ? trim($r['group_folder'])
                       : trim($r['practice_code'] ?? '')) === '') {
        // tag lives on the parent for a group, on the folder itself for a private safari.
        flash('This request has no folder to rename.', 'error');
    } else {
        // Work out the new folder name + whether/how to touch status.
        $newFolder = '';
        $newStatus = null; $newPs = null; $setStatus = false;
        $ok = true;

        if ($act === 'change_status') {
            $target = trim($_POST['new_status'] ?? '');
            if (!isset($STATUS_MAP[$target])) { flash('Invalid target status.', 'error'); $ok = false; }
            else {
                $newFolder = bo_new_folder_name($folder, $STATUS_MAP[$target]['tag'], $KNOWN_TAGS);
                $newStatus = $STATUS_MAP[$target]['status'];
                $newPs     = $STATUS_MAP[$target]['ps'];
                $setStatus = true;
            }
        } else { // rename
            $newFolder = trim($_POST['new_name'] ?? '');
            if ($newFolder === '') { flash('New name is empty.', 'error'); $ok = false; }
            elseif (preg_match('#[\\\\/:*?"<>|]#', $newFolder)) { flash('Invalid characters in the new name (\\ / : * ? " < > | are not allowed).', 'error'); $ok = false; }
            else {
                // Keep the DB status in sync with the new name's suffix (if it has one).
                $d = bo_status_from_name($newFolder, $BO_TAG_STATUS);
                $setStatus = $d['matched'];
                $newStatus = $d['status'];
                $newPs     = $d['ps'];
            }
        }

        if ($ok && strcmp($newFolder, $folder) === 0) {
            flash('The new name is identical — nothing to change.', 'error'); $ok = false;
        }

        if ($ok) {
            require_once 'dropbox_helper.php';
            try {
                $token = dropbox_get_access_token();
                $res   = bo_do_rename($db, $token, $r, $isGrp, $folder, $newFolder, $newStatus, $newPs, $setStatus);
                flash($res['msg'], $res['ok'] ? 'info' : 'error');
            } catch (Throwable $e) {
                flash('Dropbox/DB error — nothing was changed: ' . $e->getMessage(), 'error');
            }
        }
    }
    $qs = array_filter([
        'q'        => trim($_POST['q'] ?? ''),
        'root'     => trim($_POST['root'] ?? ''),
        'show_all' => !empty($_POST['show_all']) ? '1' : '',
    ], fn($x) => $x !== '');
    header('Location: backoffice.php' . ($qs ? '?' . http_build_query($qs) : ''));
    exit;
}

// ── Action: re-group a booking (fix a wrong private/GRP confirmation) ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regroup') {
    $reqId = (int)($_POST['request_id'] ?? 0);
    $type  = trim($_POST['regroup_type'] ?? 'private');   // private | grp
    $grp   = trim($_POST['target_grp'] ?? '');

    $stmt = $db->prepare("SELECT id, customer_name, practice_code, group_folder, dropbox_url, status, payment_status
                          FROM requests WHERE id = ?");
    $stmt->execute([$reqId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$r) {
        flash('Request not found.', 'error');
    } elseif ($type === 'grp' && $grp === '') {
        flash('Enter or pick a group folder name.', 'error');
    } elseif ($type === 'grp' && preg_match('#[\\\\/:*?"<>|]#', $grp)) {
        flash('Invalid characters in the group name (\\ / : * ? " < > | are not allowed).', 'error');
    } else {
        require_once 'dropbox_helper.php';
        try {
            $token = dropbox_get_access_token();
            $res   = bo_do_regroup($db, $token, $r, $type === 'grp' ? $grp : '');
            flash($res['msg'], $res['ok'] ? 'info' : 'error');
        } catch (Throwable $e) {
            flash('Dropbox/DB error — nothing was changed: ' . $e->getMessage(), 'error');
        }
    }
    $qs = array_filter([
        'q'        => trim($_POST['q'] ?? ''),
        'root'     => trim($_POST['root'] ?? ''),
        'show_all' => !empty($_POST['show_all']) ? '1' : '',
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
];
// Contracts are NOT bookings — they live only as Dropbox folders under this root.
// Selecting "Contracts" searches Dropbox directly (recursively, subfolders included)
// instead of the requests table. Keep in sync with the old Java BackOffice.
$CONTRACTS_ROOT = '/000_Contracts';

$q       = trim($_GET['q'] ?? '');
$root    = $_GET['root'] ?? '2026';
$showAll = !empty($_GET['show_all']);   // include Cancelled/Lost bookings too
if ($root !== 'All' && $root !== 'Contracts' && !isset($ROOT_MAP[$root])) $root = '2026';

$rows          = [];   // booking rows (from requests table)
$contractRows  = [];   // contract folders (from Dropbox search)
$searchError   = '';
$isContracts   = ($root === 'Contracts');

if ($q !== '' && $isContracts) {
    // ── Contracts: recursive Dropbox folder search ─────────────────────────────
    require_once 'dropbox_helper.php';
    try {
        $token        = dropbox_get_access_token();
        $contractRows = dropbox_search_folders($token, $q, $CONTRACTS_ROOT, 40);
    } catch (Throwable $e) {
        $searchError = 'Dropbox search failed: ' . $e->getMessage();
    }
} elseif ($q !== '') {
    // ── Bookings: search the requests table ────────────────────────────────────
    $like   = '%' . $q . '%';
    $sql    = "SELECT r.id, r.customer_name, r.practice_code, r.group_folder, r.status, r.payment_status,
                      r.dropbox_url, a.name AS agent_name
               FROM requests r LEFT JOIN agents a ON a.id = r.agent_id
               WHERE (r.customer_name LIKE ? OR r.practice_code LIKE ? OR r.group_folder LIKE ?)";
    $params = [$like, $like, $like];
    if (!$showAll) {
        $sql .= " AND r.status NOT IN ('Cancelled','Lost')";
    }
    if (isset($ROOT_MAP[$root])) {
        $sql     .= " AND LOCATE(?, r.dropbox_url) > 0";
        $params[] = $ROOT_MAP[$root];
    }
    $sql .= " ORDER BY r.id DESC LIMIT 60";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Existing GRP names — autocomplete for the "Re-group" action.
$existingGrps = [];
if ($rows) {
    try {
        $existingGrps = $db->query(
            "SELECT DISTINCT group_folder FROM requests
             WHERE group_folder IS NOT NULL AND group_folder <> '' ORDER BY group_folder"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $existingGrps = []; }
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
  <h2>🛠 BackOffice — Bookings &amp; folders</h2>
</div>

<form method="GET" class="filters">
  <div>
    <label>Folder</label>
    <select name="root">
      <?php foreach (['2026'=>'2026','001_Safari'=>'001_Safari','Contracts'=>'Contracts','All'=>'All'] as $val=>$lbl): ?>
        <option value="<?= h($val) ?>" <?= $root===$val?'selected':'' ?>><?= h($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Search</label>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Customer or folder…" autofocus style="width:260px">
  </div>
  <div>
    <label>&nbsp;</label>
    <label style="font-weight:400;font-size:.82rem;display:flex;align-items:center;gap:6px;white-space:nowrap">
      <input type="checkbox" name="show_all" value="1" <?= $showAll?'checked':'' ?>> Show cancelled / lost
    </label>
  </div>
  <div>
    <label>&nbsp;</label>
    <button type="submit" class="btn btn-outline">Search</button>
  </div>
</form>

<?php if ($q !== ''): ?>
  <?php if ($searchError): ?>
    <div class="bo-note" style="background:#fbeaea;border-left-color:#a33;color:#a33"><?= h($searchError) ?></div>
  <?php endif; ?>
  <?php if ($isContracts): ?>
    <?php if (!$contractRows): ?>
      <?php if (!$searchError): ?><p style="color:var(--grey-mid);padding:20px">No matching contract folders under <?= h($CONTRACTS_ROOT) ?> (subfolders included).</p><?php endif; ?>
    <?php else: ?>
    <div class="table-wrap">
    <table class="bo-table">
      <thead><tr><th>Contract folder</th><th>Location</th><th style="width:240px">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($contractRows as $c):
          $cPath = $c['path'];                                       // /000_Contracts/Zanzibar/Mvuvi
          $cRel  = ltrim($cPath, '/');                               // 000_Contracts/Zanzibar/Mvuvi
          $cWin  = '%DROPBOX_HOME%\\' . str_replace('/', '\\', $cRel);
          $cOpen = 'savannah://open?path=' . implode('/', array_map('rawurlencode', explode('/', $cRel)));
          $cDir  = trim(dirname($cPath), '/');                       // parent path, for context
      ?>
        <tr>
          <td class="bo-folder">📁 <?= h($c['name']) ?></td>
          <td class="bo-folder" style="color:var(--grey-mid)"><?= h($cDir ?: '—') ?></td>
          <td>
            <div style="font-family:'Open Sans',sans-serif">
              <a href="<?= h($cOpen) ?>" title="Open in Windows Explorer" style="font-size:.68rem;text-decoration:none">📂 Open</a>
              <a href="#" data-copy="<?= h($cWin) ?>" onclick="copyPath(this);return false" title="Copy Windows path" style="font-size:.68rem;text-decoration:none;margin-left:8px">📋 Copy path</a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  <?php else: ?>
  <?php if (!$rows): ?>
    <p style="color:var(--grey-mid);padding:20px">No matching bookings.</p>
  <?php else: ?>
  <datalist id="bo-grp-names">
    <?php foreach ($existingGrps as $g): ?><option value="<?= h($g) ?>"></option><?php endforeach; ?>
  </datalist>
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
        // Re-group only makes sense on a confirmed safari (its folder is under 001_Safari).
        $canRegroup = in_array($r['status'] ?? '', ['Booked', 'Provisional'], true);
    ?>
      <?php $isCancelled = in_array($r['status'] ?? '', ['Cancelled', 'Lost'], true); ?>
      <tr<?= $isCancelled ? ' style="background:#fcf0f0"' : '' ?>>
        <td>
          <a href="request_view.php?id=<?= (int)$r['id'] ?>" target="_blank" title="Open this request in a new tab" style="text-decoration:none;color:var(--black,#111)">
            <strong><?= h($r['customer_name']) ?></strong>
          </a>
          <?php if ($isCancelled): ?><span class="bo-grp" style="color:#a33;background:#f7dede"><?= h($r['status']) ?></span><?php endif; ?>
          <?php if ($r['agent_name']): ?><div style="font-size:.7rem;color:var(--grey-mid)">👤 <?= h($r['agent_name']) ?></div><?php endif; ?>
        </td>
        <td class="bo-folder">
          📁 <?= h($folder ?: '—') ?><?php if ($isGrp): ?><span class="bo-grp">GRP</span><?php endif; ?>
          <?php $sPath = savannah_local_path($r); $sUrl = savannah_open_url($r); ?>
          <div style="margin-top:3px;font-family:'Open Sans',sans-serif">
            <a href="request_view.php?id=<?= (int)$r['id'] ?>" target="_blank" title="Open the booking request in the Hub" style="font-size:.68rem;text-decoration:none">🔗 Open Request</a>
            <?php if ($sPath !== ''): ?>
              <a href="<?= h($sUrl) ?>" title="Open in Windows Explorer" style="font-size:.68rem;text-decoration:none;margin-left:8px">📂 Open</a>
              <a href="#" data-copy="<?= h($sPath) ?>" onclick="copyPath(this);return false" title="Copy Windows path" style="font-size:.68rem;text-decoration:none;margin-left:8px">📋 Copy path</a>
            <?php endif; ?>
            <?php if ($folder !== ''): ?>
              <a href="#" data-copy="<?= h($folder) ?>" onclick="copyPath(this);return false" title="Copy the folder name (to paste into an email)" style="font-size:.68rem;text-decoration:none;margin-left:<?= $sPath!==''?'8px':'0' ?>">📄 Copy folder name</a>
              <a href="#" onclick="toggleRename(<?= (int)$r['id'] ?>);return false" title="Rename the Dropbox folder" style="font-size:.68rem;text-decoration:none;margin-left:8px">✏ Rename…</a>
              <?php if ($canRegroup): ?>
              <a href="#" onclick="toggleEl('rg<?= (int)$r['id'] ?>');return false" title="Fix a wrong confirmation: move this booking between private and a group" style="font-size:.68rem;text-decoration:none;margin-left:8px">👥 Re-group…</a>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <?php if ($folder !== '' && $canRegroup): ?>
          <?php $curGrp = trim($r['group_folder'] ?? ''); ?>
          <form method="POST" id="rg<?= (int)$r['id'] ?>" style="display:none;margin-top:6px;padding:8px;background:#f6f6f4;border-radius:6px"
                onsubmit="return confirm('Move this booking\'s Dropbox folder and update its group?\n\nThis moves the real Dropbox folder.');">
            <input type="hidden" name="action" value="regroup">
            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="q" value="<?= h($q) ?>">
            <input type="hidden" name="root" value="<?= h($root) ?>">
            <input type="hidden" name="show_all" value="<?= $showAll ? '1' : '' ?>">
            <div style="font-size:.68rem;color:var(--grey-mid);margin-bottom:4px">Now: <?= $curGrp !== '' ? 'group “' . h($curGrp) . '”' : 'private (no group)' ?></div>
            <select name="regroup_type" onchange="var g=document.getElementById('rgn<?= (int)$r['id'] ?>');g.style.display=this.value==='grp'?'block':'none'" style="font-size:.72rem;padding:3px 5px;margin-bottom:4px">
              <option value="grp"<?= $curGrp!==''?' selected':'' ?>>Group (join existing / create new)</option>
              <option value="private"<?= $curGrp!==''?'':' selected' ?>>Private (no group)</option>
            </select>
            <input type="text" id="rgn<?= (int)$r['id'] ?>" name="target_grp" list="bo-grp-names" value="<?= h($curGrp) ?>"
                   placeholder="GRP folder name (existing or new)" spellcheck="false"
                   style="display:<?= $curGrp!==''?'block':'none' ?>;width:100%;font-family:monospace;font-size:.72rem;padding:5px 7px;border:1.5px solid var(--grey-lt);border-radius:5px;margin-bottom:4px">
            <div style="display:flex;gap:6px">
              <button type="submit" class="btn btn-red btn-sm">Move</button>
              <button type="button" class="btn btn-outline btn-sm" onclick="toggleEl('rg<?= (int)$r['id'] ?>')">Cancel</button>
            </div>
          </form>
          <?php endif; ?>
          <?php if ($folder !== ''): ?>
          <form method="POST" id="rn<?= (int)$r['id'] ?>" style="display:none;margin-top:6px"
                onsubmit="return confirm('<?= $isGrp ? 'GROUP: this renames the shared group folder and updates ALL its bookings.\\n\\n' : '' ?>Rename the real Dropbox folder to the new name?');">
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="q" value="<?= h($q) ?>">
            <input type="hidden" name="root" value="<?= h($root) ?>">
            <input type="hidden" name="show_all" value="<?= $showAll ? '1' : '' ?>">
            <input type="text" name="new_name" value="<?= h($folder) ?>" spellcheck="false"
                   style="width:100%;font-family:monospace;font-size:.72rem;padding:5px 7px;border:1.5px solid var(--grey-lt);border-radius:5px">
            <div style="margin-top:4px;display:flex;gap:6px">
              <button type="submit" class="btn btn-red btn-sm">Rename</button>
              <button type="button" class="btn btn-outline btn-sm" onclick="toggleRename(<?= (int)$r['id'] ?>)">Cancel</button>
            </div>
          </form>
          <?php endif; ?>
        </td>
        <td><span class="badge"><?= h($psLabel) ?></span></td>
        <td>
          <form method="POST" style="display:flex;gap:6px;align-items:center;margin:0"
                onsubmit="return confirm('<?= $isGrp ? 'GROUP: this renames the shared group folder and updates ALL its bookings.\\n\\n' : '' ?>Rename the Dropbox folder and set to ' + this.new_status.value + '?\n\nThis renames the real Dropbox folder.');">
            <input type="hidden" name="action" value="change_status">
            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="q" value="<?= h($q) ?>">
            <input type="hidden" name="root" value="<?= h($root) ?>">
            <input type="hidden" name="show_all" value="<?= $showAll ? '1' : '' ?>">
            <select name="new_status" class="m-input" style="width:150px;padding:5px 8px;font-size:.8rem">
              <?php foreach (array_keys($STATUS_MAP) as $st): ?>
                <option value="<?= h($st) ?>"><?= h($st) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-red btn-sm"><?= $isGrp ? 'Apply (group)' : 'Apply' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  <?php endif; ?>
<?php else: ?>
  <p style="color:var(--grey-mid);padding:20px">Search a customer name or folder to begin.</p>
<?php endif; ?>

<script>
function toggleRename(id) {
  var f = document.getElementById('rn' + id);
  if (f) f.style.display = (f.style.display === 'none' || !f.style.display) ? 'block' : 'none';
}
// Toggle any inline box (e.g. the re-group form) by full element id.
function toggleEl(elId) {
  var f = document.getElementById(elId);
  if (f) f.style.display = (f.style.display === 'none' || !f.style.display) ? 'block' : 'none';
}
// Copy text (Windows path or folder name) to the clipboard.
function copyPath(el) {
  var t = el.getAttribute('data-copy') || el.getAttribute('data-path') || '';
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
