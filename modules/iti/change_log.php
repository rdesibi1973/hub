<?php
/**
 * modules/iti/change_log.php — History of the shared ITI data (16 Nov 2026).
 * Every change to aliases, lodges, activities, destinations, routes, airlines and the staff's mapping
 * proposals, with the row before and after (iti_shared_service.php). Everyone reads it; an admin puts a
 * change back with "Restore" (the latest change of a row first, then the one before).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db       = db();
$_cu      = current_user();
$user     = $_cu['username'] ?? 'system';
$can_edit = iti_can_edit_shared();
iti_sh_schema($db);

$f = [
    'entity'    => isset(ITI_SH_ENTITIES[$_GET['entity'] ?? '']) ? $_GET['entity'] : '',
    'entity_id' => (int)($_GET['entity_id'] ?? 0),
    'user'      => trim((string)($_GET['user'] ?? '')),
    'q'         => trim((string)($_GET['q'] ?? '')),
    'since'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['since'] ?? '')) ? $_GET['since'] : '',
    'limit'     => 200,
];
$self = 'change_log.php?' . http_build_query(array_filter($f, function ($v) { return $v !== '' && $v !== 0 && $v !== 200; }));

// ── POST: restore ───────────────────────────────────────────
iti_sh_guard_post($self);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit && ($_POST['action'] ?? '') === 'restore') {
    try {
        iti_flash_set('success', iti_sh_restore($db, (int)($_POST['log_id'] ?? 0), $user));
    } catch (Throwable $e) {
        iti_flash_set('error', $e->getMessage());
    }
    iti_redirect($self);
}

$rows  = iti_sh_log_rows($db, $f);
$users = $db->query('SELECT DISTINCT changed_by FROM iti_change_log WHERE changed_by IS NOT NULL ORDER BY changed_by')->fetchAll(PDO::FETCH_COLUMN);
$entOpts = [];
foreach (ITI_SH_ENTITIES as $k => $e) $entOpts[$k] = $e['label'];
$actColor = ['create' => '#2e7d32', 'update' => '#1565c0', 'delete' => '#c62828', 'approve' => '#2e7d32', 'reject' => '#c62828', 'restore' => '#6a1b9a'];
$short = function ($v) {
    if ($v === null) return '∅';
    $v = (string)$v;
    if ($v === '') return '""';
    return mb_strlen($v) > 160 ? mb_substr($v, 0, 160) . '…' : $v;
};
$pageFor = function (array $r) {
    $id = (int)$r['entity_id'];
    switch ($r['entity']) {
        case 'lodge':          return 'lodges.php?action=edit&id=' . $id;
        case 'activity':       return 'activities.php?action=edit&id=' . $id;
        case 'destination':    return 'destinations.php?action=edit&id=' . $id;
        case 'transfer_route': return 'transfers.php?tab=road&action=edit&id=' . $id;
        case 'flight_route':   return 'transfers.php?tab=flight&action=edit&id=' . $id;
        case 'airline':        return 'airlines.php?action=edit&id=' . $id;
        case 'lodge_alias': case 'activity_alias': case 'route_alias':
            return 'aliases.php?type=' . substr($r['entity'], 0, -6) . '&q=' . rawurlencode((string)$r['label']);
        case 'proposal':       return 'aliases.php#proposals';
    }
    return '';
};

$page_title = 'History — Itinerary Builder';
$extra_css = iti_extra_css();
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
<?php iti_nav('History'); ?>
<?php iti_flash_render(); ?>

<div class="page-header">
  <div>
    <h2>History of the shared data</h2>
    <div class="sub">Every change to aliases, lodges, activities, destinations, routes, airlines and the staff's mappings — who, when, what changed.
      <?= $can_edit ? 'Restore puts a row back as it was before that change.' : 'Only Roberto restores a change.' ?></div>
  </div>
</div>

<form method="GET" action="change_log.php" class="filters">
  <div><label>What</label><select name="entity"><?= iti_options($entOpts, $f['entity'] ?: null, 'Everything') ?></select></div>
  <div><label>Who</label><select name="user"><?= iti_options(array_combine($users, $users) ?: [], $f['user'] ?: null, 'Everyone') ?></select></div>
  <div class="filter-search"><label>Name</label><input type="text" name="q" value="<?= h($f['q']) ?>" placeholder="Lodge, alias…"></div>
  <div><label>Since</label><input type="date" name="since" value="<?= h($f['since']) ?>"></div>
  <?php if ($f['entity_id']): ?><input type="hidden" name="entity_id" value="<?= (int)$f['entity_id'] ?>"><?php endif; ?>
  <div style="display:flex;gap:8px;align-items:flex-end;">
    <button type="submit" class="btn btn-outline btn-sm">🔍 Filter</button>
    <?php if ($self !== 'change_log.php?'): ?><a href="change_log.php" class="btn btn-outline btn-sm">✕ Clear</a><?php endif; ?>
  </div>
</form>

<div class="table-wrap">
  <table>
    <thead><tr><th>When</th><th>Who</th><th>What</th><th>Change</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="5"><div class="empty-state"><div class="icon">🕘</div><p>No change logged<?= $self !== 'change_log.php?' ? ' for these filters' : ' yet' ?>.</p></div></td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r):
        $b = $r['before_json'] !== null ? json_decode($r['before_json'], true) : null;
        $a = $r['after_json'] !== null ? json_decode($r['after_json'], true) : null;
        $diff = iti_sh_diff($b, $a);
        $link = $pageFor($r);
        $restorable = in_array($r['action'], ['create', 'update', 'delete'], true);
        $why = $restorable ? iti_sh_restore_blocker($db, $r) : ''; ?>
      <tr style="<?= $r['restored_at'] ? 'opacity:.55;' : '' ?>">
        <td style="white-space:nowrap;font-size:.78rem;"><?= h(substr((string)$r['changed_at'], 0, 16)) ?></td>
        <td style="font-size:.78rem;"><?= h($r['changed_by'] ?? '') ?><?= $r['source'] !== 'hub' ? '<br><span style="color:var(--grey-mid);">' . h($r['source']) . '</span>' : '' ?></td>
        <td style="font-size:.8rem;">
          <span class="badge" style="background:#f5f5f5;color:<?= $actColor[$r['action']] ?? '#555' ?>;"><?= h($r['action']) ?></span>
          <?= h(ITI_SH_ENTITIES[$r['entity']]['label'] ?? $r['entity']) ?>
          <div style="font-weight:600;"><?= $link ? '<a href="' . h($link) . '">' . h($r['label'] ?: '#' . $r['entity_id']) . '</a>' : h($r['label']) ?></div>
          <?php if ($r['note']): ?><div style="font-size:.72rem;color:var(--grey-mid);"><?= h($r['note']) ?></div><?php endif; ?>
        </td>
        <td style="font-size:.75rem;max-width:520px;">
          <?php if ($r['action'] === 'create' && $a): ?>
            <span style="color:var(--grey-mid);">new record</span>
          <?php elseif ($r['action'] === 'delete'): ?>
            <span style="color:var(--grey-mid);">deleted</span>
          <?php endif; ?>
          <?php if ($r['action'] !== 'create' && $r['action'] !== 'delete'): foreach (array_slice($diff, 0, 8, true) as $k => $v): ?>
            <div><code><?= h($k) ?></code>: <span style="color:#c62828;text-decoration:line-through;"><?= h($short($v[0])) ?></span> → <span style="color:#2e7d32;"><?= h($short($v[1])) ?></span></div>
          <?php endforeach; if (count($diff) > 8): ?><div style="color:var(--grey-mid);">+ <?= count($diff) - 8 ?> more fields</div><?php endif; endif; ?>
          <?php if ($r['restored_at']): ?><div style="color:#6a1b9a;">restored by <?= h($r['restored_by']) ?>, <?= h(substr((string)$r['restored_at'], 0, 16)) ?></div><?php endif; ?>
        </td>
        <td style="white-space:nowrap;">
          <?php if ($can_edit && $restorable && $why === ''): ?>
            <form method="POST" action="<?= h($self) ?>" style="display:inline;">
              <input type="hidden" name="action" value="restore">
              <input type="hidden" name="log_id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-outline btn-sm" onclick="return confirm('<?= $r['action'] === 'create' ? (substr($r['entity'], -6) === '_alias' ? 'Remove this alias? The text will be asked again.' : 'Deactivate this record?') : 'Put this row back as it was before this change?' ?>')">↶ Restore</button>
            </form>
          <?php elseif ($can_edit && $restorable && !$r['restored_at']): ?>
            <span style="font-size:.7rem;color:var(--grey-mid);" title="<?= h($why) ?>">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if (count($rows) >= 200): ?><div style="font-size:.78rem;color:var(--grey-mid);margin-top:6px;">Latest 200 changes shown: filter to see older ones.</div><?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
