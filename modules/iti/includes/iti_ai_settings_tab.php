<?php
/**
 * ITI → Settings → AI: limits, who sees the buttons being tested, this month's spending.
 * Included by settings.php, which provides $db, $_cu, $admin and $user_list.
 */
$aiSt  = iti_ai_status($db, $_cu['id'] ?? null);
$aiRep = $admin ? iti_ai_report($db) : null;
$aiWhy = iti_ai_limit_reason($aiSt, 0.0);
$aiDis = $admin ? '' : 'disabled';
?>
<div class="page-header"><div><h2>AI</h2>
  <div class="sub">Limits on what the AI buttons of the Hub can spend. The month is the calendar month; costs in USD, from the real use of each call.</div></div></div>
<?php if (!$admin): ?><div class="ro-note">🔒 Read-only. Only administrators can change the AI limits.</div><?php endif; ?>
<?php if ($aiWhy !== ''): ?><div class="form-card" style="border-left:4px solid var(--red);margin-bottom:16px;font-size:.85rem;"><?= h($aiWhy) ?></div><?php endif; ?>

<div class="form-card" style="margin-bottom:18px;">
  <div class="form-section-title">This month</div>
  <div class="form-grid" style="grid-template-columns:1fr 1fr;">
    <div class="form-group"><label>Whole Hub</label><?= iti_ai_meter($aiSt['month_spent'], $aiSt['month']) ?></div>
    <div class="form-group"><label>You</label><?= iti_ai_meter($aiSt['user_spent'], $aiSt['user']) ?></div>
  </div>
</div>

<form method="POST" action="settings.php?tab=ai" class="form-card" style="margin-bottom:18px;">
  <input type="hidden" name="_action" value="ai_save">
  <div class="form-section-title">Limits</div>
  <div class="form-group">
    <label class="check-label"><input type="checkbox" name="ai_enabled" value="1" <?= $aiSt['enabled'] ? 'checked' : '' ?> <?= $aiDis ?>> AI on (off stops every AI button at once)</label>
  </div>
  <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr;">
    <div class="form-group"><label>Whole Hub, per month (USD)</label><input type="number" name="ai_limit_month" min="0" step="0.01" value="<?= h((string)$aiSt['month']) ?>" <?= $aiDis ?>></div>
    <div class="form-group"><label>Each person, per month (USD)</label><input type="number" name="ai_limit_user" min="0" step="0.01" value="<?= h((string)$aiSt['user']) ?>" <?= $aiDis ?>></div>
    <div class="form-group"><label>One click (USD)</label><input type="number" name="ai_limit_click" min="0" step="0.01" value="<?= h((string)$aiSt['click']) ?>" <?= $aiDis ?>></div>
  </div>
  <?php if ($admin): ?>
  <div class="form-section-title" style="margin-top:14px;">Who sees the buttons being tested</div>
  <div style="font-size:.72rem;color:var(--grey-mid);margin-bottom:8px;">Administrators always see them.</div>
  <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px;">
    <?php foreach ($user_list as $u): ?>
      <label style="font-size:.85rem;white-space:nowrap;"><input type="checkbox" name="ai_users[]" value="<?= (int)$u['id'] ?>" <?= in_array((int)$u['id'], $aiSt['users'], true) ? 'checked' : '' ?>> <?= h($u['full_name'] ?: $u['username']) ?></label>
    <?php endforeach; ?>
  </div>
  <button type="submit" class="btn btn-red">💾 Save</button>
  <?php endif; ?>
</form>

<div class="form-card" style="margin-bottom:18px;">
  <div class="form-section-title">AI buttons</div>
  <table class="kv-table">
    <thead><tr><th style="width:220px;">Button</th><th style="width:auto;">What it does</th><th style="width:110px;">State</th></tr></thead>
    <tbody>
    <?php foreach (iti_ai_actions() as $a): ?>
      <tr class="term-row"><td><strong><?= h($a['label']) ?></strong></td><td><?= h($a['help']) ?></td><td><?= $a['state'] === 'live' ? 'Active' : 'Not active yet' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($aiRep): $aiLabels = array_map(function ($a) { return $a['label']; }, iti_ai_actions()); ?>
<div class="form-card" style="margin-bottom:18px;">
  <div class="form-section-title">Spending this month</div>
  <table class="kv-table">
    <thead><tr><th style="width:auto;">Person</th><th style="width:80px;">Calls</th><th style="width:320px;">Spent</th></tr></thead>
    <tbody>
    <?php foreach ($aiRep['users'] as $r): ?>
      <tr class="term-row"><td><?= h($r['username']) ?></td><td><?= (int)$r['calls'] ?></td><td><?= iti_ai_meter((float)$r['cost'], $aiSt['user']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$aiRep['users']): ?><tr><td colspan="3" style="text-align:center;padding:20px;color:var(--grey-mid);">No AI calls this month.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <?php if ($aiRep['actions']): ?>
  <table class="kv-table" style="margin-top:14px;">
    <thead><tr><th style="width:auto;">Button</th><th style="width:80px;">Calls</th><th style="width:320px;">USD</th></tr></thead>
    <tbody>
    <?php foreach ($aiRep['actions'] as $r): ?>
      <tr class="term-row"><td><?= h($aiLabels[$r['action']] ?? $r['action']) ?></td><td><?= (int)$r['calls'] ?></td><td><?= number_format((float)$r['cost'], 2) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if ($aiRep['recent']): ?>
<div class="form-card" style="padding:0;overflow:auto;">
  <table class="kv-table">
    <thead><tr><th style="width:150px;">When</th><th style="width:110px;">Who</th><th style="width:auto;">Button</th><th style="width:140px;">Model</th><th style="width:120px;">Tokens in / out</th><th style="width:70px;">USD</th><th style="width:auto;">Result</th></tr></thead>
    <tbody>
    <?php foreach ($aiRep['recent'] as $r): ?>
      <tr class="term-row">
        <td><?= h($r['created_at']) ?></td><td><?= h($r['username'] ?? '—') ?></td><td><?= h($aiLabels[$r['action']] ?? $r['action']) ?></td>
        <td><?= h($r['model']) ?></td><td><?= (int)$r['input_tokens'] ?> / <?= (int)$r['output_tokens'] ?></td><td><?= number_format((float)$r['cost_usd'], 3) ?></td>
        <td><?= h($r['status'] === 'ok' ? 'OK' : ($r['status'] === 'blocked' ? 'Blocked' : 'Error')) ?><?= $r['note'] ? ' — ' . h($r['note']) : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php endif; ?>
