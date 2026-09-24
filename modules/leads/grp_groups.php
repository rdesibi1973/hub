<?php
/**
 * grp_groups.php — List of GRP (group) folders in /001_Safari, grouped by month.
 *
 * Ports the Java BackOffice "Groups & CK → GRP groups" menu (dir *GRP* filtered to
 * folders starting 01_..12_), and adds for each group its customer sub-folders
 * (one per confirmed booking) with the pax recorded on the matching request, so
 * it is clear at a glance how full each group is.
 */
require_once 'config.php';
require_once 'dropbox_helper.php';
$pageTitle = 'GRP groups';
$db = db();

// ── Access: admin + manager only (same as BackOffice) ─────────────────────────
$currentUser = current_user();
if (!in_array($currentUser['role_name'] ?? '', ['admin','manager'], true)) {
    flash('Access denied.', 'error');
    header('Location: requests.php'); exit;
}

$BASE     = '/001_Safari';
$showPast = !empty($_GET['past']);   // include groups that already ended

// Standard booking sub-folders (created for every booking) — not customers.
$STD_SUBS = ['bookings','complain','flights','guestcomments','insurance','intflights',
             'invoices','mails','old','passports','vouchers'];

// Folder tag → label + colour (longest / most specific first, "contains" match).
$TAGS = [
    '_BALANCE-CASH' => ['Balance-Cash', '#1a3a5c', '#EAF1F8'],
    '_BALANCE_CASH' => ['Balance-Cash', '#1a3a5c', '#EAF1F8'],
    '_BALANCE'      => ['Balance',      '#1a3a5c', '#EAF1F8'],
    '_DEPOSIT'      => ['Deposit',      '#8a6d3b', '#fcf3e3'],
    '_PAID'         => ['Paid',         '#1A6B3A', '#E6F4EA'],
    '_PROGRESS'     => ['Progress',     '#6B7280', '#F3F4F6'],
    '_CONFIRMED'    => ['Confirmed',    '#6B7280', '#F3F4F6'],
    '_PROVISIONAL'  => ['Provisional',  '#B26A00', '#FFF4E0'],
    '_CANCELLED'    => ['Cancelled',    '#a33',    '#f7dede'],
];
$MONTHS = ['JAN'=>1,'FEB'=>2,'MAR'=>3,'APR'=>4,'MAY'=>5,'JUN'=>6,
           'JUL'=>7,'AUG'=>8,'SEP'=>9,'OCT'=>10,'NOV'=>11,'DEC'=>12];

/** [label, fg, bg] of the status tag in a folder name, or null. */
function grp_tag(string $name, array $tags): ?array {
    $up = strtoupper($name);
    foreach ($tags as $t => $v) if (strpos($up, $t) !== false) return $v;
    return null;
}

/**
 * Start / end timestamps from "…_START20OCT_…_END26OCT2026…". The start has no
 * year: it is the END year, minus one when the start month is after the end month
 * (a December group ending in January). Falls back to the MM_ prefix.
 */
function grp_dates(string $name, array $months): array {
    $u = strtoupper($name);
    $end = null; $start = null;
    if (preg_match('/_END(\d{2})([A-Z]{3})(\d{4})/', $u, $m) && isset($months[$m[2]])) {
        $end = mktime(0, 0, 0, $months[$m[2]], (int)$m[1], (int)$m[3]);
    }
    if (preg_match('/_START(\d{2})([A-Z]{3})/', $u, $m) && isset($months[$m[2]])) {
        $mon  = $months[$m[2]];
        $year = $end ? (int)date('Y', $end) - ($mon > (int)date('n', $end) ? 1 : 0) : (int)date('Y');
        $start = mktime(0, 0, 0, $mon, (int)$m[1], $year);
    }
    if (!$start && preg_match('/^(\d{2})_/', $u, $m)) {
        $start = mktime(0, 0, 0, (int)$m[1], 1, $end ? (int)date('Y', $end) : (int)date('Y'));
    }
    return [$start, $end];
}

/** Match key for a customer folder: lowercase, trailing status tags stripped
 *  ("AndreaMazzanti(X-Daniela)_PAID" ≈ practice_code "AndreaMazzanti(X-Daniela)"). */
function grp_key(string $folder): string {
    $k = mb_strtolower(trim($folder));
    return preg_replace('/(_(balance-cash|balance_cash|balance|deposit|paid|progress|confirmed|provisional|cancelled|ck))+$/', '', $k);
}

/** savannah:// open link + %DROPBOX_HOME% Windows path for an API path. */
function grp_links(string $path): array {
    $rel = ltrim($path, '/');
    return [
        'savannah://open?path=' . implode('/', array_map('rawurlencode', explode('/', $rel))),
        '%DROPBOX_HOME%\\' . str_replace('/', '\\', $rel),
    ];
}

// ── Load: GRP folders from Dropbox, their sub-folders, and the DB requests ─────
$groups = [];
$error  = '';
try {
    $token = dropbox_get_access_token();
    $names = array_filter(dropbox_list_folder($token, $BASE),
        fn($n) => stripos($n, 'GRP') !== false && preg_match('/^(0[1-9]|1[0-2])_/', $n));
    $paths = array_map(fn($n) => $BASE . '/' . $n, $names);
    $subs  = $paths ? dropbox_list_folders_multi($token, $paths) : [];

    // Requests per group folder (lowercase), for pax + Open Request links.
    $reqByGrp = [];
    if ($names) {
        $in   = implode(',', array_fill(0, count($names), '?'));
        $stmt = $db->prepare("SELECT id, customer_name, practice_code, group_folder, status, pax
                              FROM requests WHERE group_folder IN ($in)");
        $stmt->execute(array_values($names));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $reqByGrp[mb_strtolower($r['group_folder'])][] = $r;
        }
    }

    $today = strtotime('today');
    foreach ($names as $n) {
        [$start, $end] = grp_dates($n, $MONTHS);
        if (!$showPast && $end && $end < $today) continue;

        $reqs    = $reqByGrp[mb_strtolower($n)] ?? [];
        $members = [];
        $sub     = $subs[$BASE . '/' . $n] ?? null;
        foreach ($sub ?? [] as $s) {
            if (in_array(mb_strtolower($s), $STD_SUBS, true)) continue;
            // Exact folder name first, then ignoring trailing status tags.
            $match = null;
            foreach ([fn($r) => strcasecmp(trim($r['practice_code'] ?? ''), $s) === 0,
                      fn($r) => grp_key($r['practice_code'] ?? '') === grp_key($s)] as $same) {
                foreach ($reqs as $i => $r) {
                    if ($same($r)) { $match = $r; unset($reqs[$i]); break 2; }
                }
            }
            $members[] = ['folder' => $s, 'req' => $match];
        }
        $paxKnown = 0; $paxMissing = 0;
        foreach ($members as $m) {
            if ($m['req'] && (int)$m['req']['pax'] > 0) $paxKnown += (int)$m['req']['pax'];
            else $paxMissing++;
        }
        $groups[] = [
            'name'        => $n,
            'path'        => $BASE . '/' . $n,
            'start'       => $start,
            'end'         => $end,
            'tag'         => grp_tag($n, $TAGS),
            'members'     => $members,
            'listFailed'  => $sub === null,
            'dbOnly'      => array_values($reqs),   // requests in the DB with no sub-folder found
            'pax'         => $paxKnown,
            'paxMissing'  => $paxMissing,
        ];
    }
    usort($groups, fn($a, $b) => [$a['start'] ?? PHP_INT_MAX, $a['name']] <=> [$b['start'] ?? PHP_INT_MAX, $b['name']]);
} catch (Throwable $e) {
    $error = 'Dropbox error: ' . $e->getMessage();
}

// Group by month of the start date.
$byMonth = [];
foreach ($groups as $g) {
    $key = $g['start'] ? date('Y-m', $g['start']) : '9999-99';
    $byMonth[$key][] = $g;
}

$extra_css = '
.grp-month{font-size:1rem;margin:26px 0 10px;padding-bottom:4px;border-bottom:2px solid var(--grey-lt)}
.grp-month small{font-weight:400;color:var(--grey-mid);font-size:.78rem;margin-left:6px}
.grp-card{background:#fff;border:1px solid var(--grey-lt);border-radius:10px;padding:12px 14px;margin-bottom:10px}
.grp-head{display:flex;gap:10px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap}
.grp-name{font-family:monospace;font-size:.8rem;word-break:break-all;font-weight:600}
.grp-dates{font-size:.75rem;color:var(--grey-mid);margin-top:2px}
.grp-tag{font-size:.68rem;font-weight:700;border-radius:6px;padding:2px 7px;white-space:nowrap}
.grp-count{font-size:.8rem;font-weight:700;white-space:nowrap}
.grp-count small{font-weight:400;color:var(--grey-mid)}
.grp-actions a{font-size:.68rem;text-decoration:none;margin-right:10px}
.grp-members{list-style:none;margin:8px 0 0;padding:0;border-top:1px dashed var(--grey-lt)}
.grp-members li{display:flex;gap:10px;align-items:baseline;justify-content:space-between;flex-wrap:wrap;padding:5px 0 5px 14px;border-bottom:1px dashed var(--grey-lt);font-size:.78rem}
.grp-members li:last-child{border-bottom:0}
.grp-members .f{font-family:monospace;font-size:.74rem;word-break:break-all}
.grp-members .pax{font-weight:700;white-space:nowrap}
.grp-warn{font-size:.72rem;color:#B26A00;margin-top:6px}
';
include 'includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
  <h2>👥 GRP groups</h2>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="grp_groups.php<?= $showPast ? '' : '?past=1' ?>" class="btn btn-outline btn-sm"><?= $showPast ? 'Hide past groups' : 'Show past groups' ?></a>
    <a href="backoffice.php" class="btn btn-outline btn-sm">← BackOffice</a>
  </div>
</div>

<?php if ($error): ?>
  <div class="bo-note" style="background:#fbeaea;border-left:4px solid #a33;color:#a33;padding:12px 16px;border-radius:8px"><?= h($error) ?></div>
<?php elseif (!$groups): ?>
  <p style="color:var(--grey-mid);padding:20px">No GRP folders in <?= h($BASE) ?><?= $showPast ? '' : ' (upcoming)' ?>.</p>
<?php else: ?>
  <p style="font-size:.8rem;color:var(--grey-mid)">
    <?= count($groups) ?> group<?= count($groups) === 1 ? '' : 's' ?> in <?= h($BASE) ?><?= $showPast ? '' : ' — upcoming and in progress' ?>.
    Each customer sub-folder is a confirmed booking; pax come from the matching request.
  </p>

  <?php foreach ($byMonth as $key => $list): ?>
    <h3 class="grp-month">
      <?= $key === '9999-99' ? 'No date in folder name' : h(date('F Y', strtotime($key . '-01'))) ?>
      <small><?= count($list) ?> group<?= count($list) === 1 ? '' : 's' ?></small>
    </h3>

    <?php foreach ($list as $g):
        [$gOpen, $gWin] = grp_links($g['path']);
        $nMem = count($g['members']);
    ?>
      <div class="grp-card">
        <div class="grp-head">
          <div style="flex:1;min-width:260px">
            <div class="grp-name">📁 <?= h($g['name']) ?></div>
            <div class="grp-dates">
              <?php if ($g['start']): ?><?= h(date('D j M', $g['start'])) ?><?php endif; ?>
              <?php if ($g['end']): ?> → <?= h(date('D j M Y', $g['end'])) ?><?php endif; ?>
            </div>
          </div>
          <div style="display:flex;gap:10px;align-items:center">
            <?php if ($g['tag']): ?>
              <span class="grp-tag" style="color:<?= $g['tag'][1] ?>;background:<?= $g['tag'][2] ?>"><?= h($g['tag'][0]) ?></span>
            <?php endif; ?>
            <span class="grp-count" title="Customer sub-folders (confirmed bookings) and total pax from the requests">
              <?= $nMem ?> booking<?= $nMem === 1 ? '' : 's' ?> ·
              <?= $g['pax'] ?><?= $g['paxMissing'] ? '+' : '' ?> pax
              <?php if ($g['paxMissing']): ?><small>(<?= $g['paxMissing'] ?> without pax)</small><?php endif; ?>
            </span>
          </div>
        </div>

        <div class="grp-actions" style="margin-top:6px">
          <a href="<?= h($gOpen) ?>" title="Open the group folder in Windows Explorer">📂 Open</a>
          <a href="#" data-copy="<?= h($gWin) ?>" onclick="copyPath(this);return false" title="Copy Windows path">📋 Copy path</a>
          <a href="#" data-copy="<?= h($g['name']) ?>" onclick="copyPath(this);return false" title="Copy the folder name">📄 Copy folder name</a>
          <a href="backoffice.php?root=001_Safari&amp;show_all=1&amp;q=<?= rawurlencode($g['name']) ?>" title="Open this group in the BackOffice search (rename, status…)">🛠 BackOffice</a>
        </div>

        <?php if ($g['listFailed']): ?>
          <div class="grp-warn">⚠ Could not read the sub-folders of this group from Dropbox — reload to retry.</div>
        <?php elseif (!$g['members']): ?>
          <div class="grp-warn">No customer sub-folders yet.</div>
        <?php else: ?>
          <ul class="grp-members">
            <?php foreach ($g['members'] as $m):
                [$mOpen, $mWin] = grp_links($g['path'] . '/' . $m['folder']);
                $r = $m['req'];
            ?>
              <li>
                <div style="flex:1;min-width:240px">
                  <div class="f">↳ <?= h($m['folder']) ?></div>
                  <div class="grp-actions" style="margin-top:2px">
                    <?php if ($r): ?>
                      <a href="request_view.php?id=<?= (int)$r['id'] ?>" target="_blank" title="<?= h($r['customer_name']) ?>">🔗 Open Request</a>
                    <?php endif; ?>
                    <a href="<?= h($mOpen) ?>" title="Open in Windows Explorer">📂 Open</a>
                    <a href="#" data-copy="<?= h($mWin) ?>" onclick="copyPath(this);return false" title="Copy Windows path">📋 Copy path</a>
                  </div>
                </div>
                <span class="pax">
                  <?php if ($r && (int)$r['pax'] > 0): ?><?= (int)$r['pax'] ?> pax
                  <?php elseif ($r): ?><span style="color:var(--grey-mid);font-weight:400">pax ?</span>
                  <?php else: ?><span style="color:#B26A00;font-weight:400" title="No request in the Hub has this folder as its practice code">no request</span>
                  <?php endif; ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($g['dbOnly']): ?>
          <div class="grp-warn">⚠ In the Hub but no sub-folder found:
            <?php foreach ($g['dbOnly'] as $i => $r): ?><?= $i ? ', ' : '' ?><a href="request_view.php?id=<?= (int)$r['id'] ?>" target="_blank"><?= h($r['customer_name']) ?></a><?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endforeach; ?>
<?php endif; ?>

<script>
function copyPath(el) {
  var t = el.getAttribute('data-copy');
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(t).then(function () { flashCopied(el); }, function () { fallbackCopy(t, el); });
  } else { fallbackCopy(t, el); }
}
function fallbackCopy(t, el) {
  var ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta);
  ta.select(); try { document.execCommand('copy'); flashCopied(el); } catch (e) {} document.body.removeChild(ta);
}
function flashCopied(el) { var o = el.textContent; el.textContent = '✓ Copied'; setTimeout(function(){ el.textContent = o; }, 1200); }
</script>

<?php include 'includes/footer.php'; ?>
