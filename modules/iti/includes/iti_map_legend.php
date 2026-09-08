<?php
/**
 * modules/iti/includes/iti_map_legend.php
 * Shared legend for the itinerary map. Lists every numbered stop and its name
 * so that markers combining several stops (e.g. "2 & 4" for a lodge visited
 * twice) stay readable.
 *
 * Expects in scope:
 *   $map_points  ordered points from iti_get_program_map_points() (1-based)
 *   $lang        current language code
 */
if (empty($map_points)) return;
?>
<div style="padding:14px 24px 20px;border-top:1px solid #E5E2DE;">
  <div style="font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.11em;color:#C0211B;margin-bottom:10px;">
    <?= h(iti_lbl_map_legend($lang)) ?>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:7px 22px;">
    <?php foreach ($map_points as $i => $mp): ?>
    <div style="display:flex;align-items:center;gap:9px;font-size:.9rem;line-height:1.3;">
      <span style="flex:0 0 auto;background:#C0211B;color:#fff;min-width:22px;height:22px;padding:0 6px;box-sizing:border-box;border-radius:11px;display:inline-flex;align-items:center;justify-content:center;font:700 11px/1 sans-serif;">
        <?= (int)$i + 1 ?>
      </span>
      <span><?= h((string)($mp['name'] ?? '')) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
