<?php
require_once 'config.php';
requireLogin();  // uses require_permission('leads') from config.php

$pageTitle = 'Request Lookup';

// ── Search ────────────────────────────────────────────────────────────────────
$q    = trim($_GET['q'] ?? '');
$rows = [];

if (strlen($q) >= 2) {
    $db   = db();
    $like = "%$q%";
    $stmt = $db->prepare("
        SELECT
            r.id,
            r.customer_name,
            r.practice_code,
            r.status,
            r.date_received,
            r.destination,
            r.pax,
            r.period,
            a.name AS agent_name
        FROM   requests r
        LEFT JOIN agents a ON a.id = r.agent_id
        WHERE  r.customer_name LIKE ?
            OR r.practice_code LIKE ?
        ORDER  BY r.date_received DESC
        LIMIT  30
    ");
    $stmt->execute([$like, $like]);
    $rows = $stmt->fetchAll();
}

include 'includes/header.php';
?>

<style>
/* ── Lookup-specific overrides ─────────────────────────────────── */
.lookup-wrap       { max-width: 1200px; }
.lookup-search-bar { display:flex; gap:10px; margin-bottom:24px; }
.lookup-search-bar input {
    flex:1;
    border:1px solid var(--border);
    border-radius:7px;
    padding:10px 16px;
    font-size:.9rem;
    outline:none;
    transition:border-color .15s;
}
.lookup-search-bar input:focus { border-color:var(--red); }
.lookup-search-bar button {
    white-space:nowrap;
}

/* Clickable rows */
tbody tr.clickable {
    cursor:pointer;
    transition:background .1s;
}
tbody tr.clickable:hover { background:#fafafa; }
tbody tr.clickable td    { border-bottom:1px solid var(--border); }

/* Highlight the matching part */
mark { background:#FEF3C7; color:inherit; border-radius:2px; padding:0 1px; }

/* ── Fix: prevent the Status column from being clipped ──────────────
   The shared .table-wrap uses overflow:hidden, which cut off the last
   column (Status) when the folder name / practice_code is very long.
   These overrides are scoped to the lookup page only. */
.lookup-wrap .table-wrap { overflow-x:auto; }
.lookup-wrap table       { min-width:760px; }
/* Let the long folder/practice_code wrap instead of forcing the table wider */
.lookup-wrap td:first-child { word-break:break-word; }
/* Keep the status badge and its header on one line */
.lookup-wrap td:last-child .badge,
.lookup-wrap th:last-child  { white-space:nowrap; }
</style>

<div class="page-header">
  <div style="display:flex;align-items:center;gap:12px;">
    <a href="requests.php" class="btn btn-outline btn-sm" style="font-size:.72rem;">&#8592; All Requests</a>
    <div>
      <h2>Request Lookup</h2>
      <div class="sub">
        <?php if ($q !== ''): ?>
          Searching for: <strong><?= h($q) ?></strong>
        <?php else: ?>
          Enter a customer name or folder code
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="lookup-wrap">

  <!-- Search bar — pre-filled with ?q value from GUI -->
  <form method="GET" class="lookup-search-bar">
    <input  type="text"
            name="q"
            value="<?= h($q) ?>"
            placeholder="Customer name or folder code… e.g. GaiaDellaValle(Anderson)"
            autofocus
            autocomplete="off"
            spellcheck="false">
    <button type="submit" class="btn btn-red">🔍 Search</button>
    <?php if ($q !== ''): ?>
      <a href="lookup.php" class="btn btn-outline">✕ Clear</a>
    <?php endif; ?>
  </form>

  <?php if ($q !== '' && !$rows): ?>
    <!-- No results -->
    <div class="empty-state">
      <div class="icon">🔍</div>
      <p>No requests found for <strong><?= h($q) ?></strong></p>
      <p style="font-size:.8rem;color:var(--grey-mid);margin-top:8px;">
        Try a shorter name, the customer's surname only, or part of the folder code.
      </p>
    </div>

  <?php elseif ($rows): ?>
    <!-- Results table -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Folder / Customer</th>
            <th>Agent</th>
            <th>Date</th>
            <th>Type &amp; Period</th>
            <th>Status</th>
            <th style="width:80px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $editUrl = 'request_edit.php?id=' . $r['id'];
            $viewUrl = 'request_view.php?id=' . $r['id'];
          ?>
          <tr class="clickable"
              onclick="window.location='<?= $viewUrl ?>'"
              ondblclick="window.location='<?= $editUrl ?>'"
              title="Click to view · Double-click to edit">

            <td>
              <div style="font-weight:600;line-height:1.3;">
                <?= highlight(h($r['practice_code'] ?: $r['customer_name']), $q) ?>
              </div>
              <?php if ($r['practice_code'] && $r['customer_name']): ?>
                <div style="font-size:.72rem;color:var(--grey-mid);margin-top:2px;">
                  <?= highlight(h($r['customer_name']), $q) ?>
                </div>
              <?php endif; ?>
            </td>

            <td><?= h($r['agent_name'] ?? '—') ?></td>

            <td style="white-space:nowrap;color:var(--grey-mid);font-size:.83rem;">
              <?= $r['date_received'] ? date('d M Y', strtotime($r['date_received'])) : '—' ?>
            </td>

            <td style="font-size:.83rem;color:var(--grey-mid);">
              <?= h($r['destination'] ?? '—') ?>
              <?php if ($r['period']): ?>
                <div style="font-size:.7rem;"><?= h($r['period']) ?><?= $r['pax'] ? ' · ' . h($r['pax']) . ' pax' : '' ?></div>
              <?php endif; ?>
            </td>

            <td>
              <span class="badge <?= STATUSES[$r['status']] ?? '' ?>">
                <?= h($r['status']) ?>
              </span>
            </td>

            <td style="white-space:nowrap">
              <a href="<?= $viewUrl ?>"
                 class="btn btn-outline btn-sm"
                 onclick="event.stopPropagation()">View</a>
              <a href="<?= $editUrl ?>"
                 class="btn btn-red btn-sm"
                 onclick="event.stopPropagation()">Edit</a>
            </td>

          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p style="font-size:.71rem;color:var(--grey-mid);margin-top:10px;text-align:right;">
      <?= count($rows) ?> result<?= count($rows) !== 1 ? 's' : '' ?> &mdash;
      double-click a row to <strong>edit</strong> · single-click or <strong>View</strong> to open
    </p>

  <?php endif; ?>

</div><!-- /.lookup-wrap -->

<?php
// ── Highlight helper ─────────────────────────────────────────────────────────
function highlight(string $text, string $query): string {
    if ($query === '') return $text;
    // Split on non-word chars to highlight each token
    $tokens = preg_split('/[\s\(\)\-\_]+/', $query, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($tokens as $t) {
        $t    = preg_quote($t, '/');
        $text = preg_replace('/(' . $t . ')/iu', '<mark>$1</mark>', $text);
    }
    return $text;
}
?>

<?php include 'includes/footer.php'; ?>
