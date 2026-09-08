<?php
/**
 * modules/iti/includes/iti_map_legend.php
 * Side legend for the itinerary map. Lists the arrival airport, every numbered
 * stop, and the departure airport, with the straight-line distance of each leg.
 *
 * Expects in scope:
 *   $map   the structure from iti_get_program_map() (uses $map['legend'])
 *   $lang  current language code
 *
 * Must sit inside a `.iti-map-wrap` flex container next to `#itiMap`.
 */
if (empty($map['legend'])) return;

static $iti_map_css_done = false;
if (!$iti_map_css_done):
    $iti_map_css_done = true; ?>
<style>
.iti-map-wrap{display:flex;flex-wrap:wrap;align-items:stretch;}
.iti-map-wrap .iti-map{flex:1 1 58%;min-width:280px;height:520px;}
.iti-map-legend{flex:1 1 260px;min-width:240px;max-height:520px;overflow:auto;
  padding:16px 22px;border-left:1px solid #E5E2DE;box-sizing:border-box;}
.iti-map-legend .lg-title{font-size:.68rem;font-weight:800;text-transform:uppercase;
  letter-spacing:.11em;color:#C0211B;margin-bottom:12px;}
.iti-map-legend .lg-row{display:flex;align-items:flex-start;gap:10px;font-size:.9rem;line-height:1.3;}
.iti-map-legend .lg-badge{flex:0 0 auto;height:24px;min-width:24px;border-radius:12px;
  display:inline-flex;align-items:center;justify-content:center;color:#fff;
  font:700 11px/1 sans-serif;padding:0 7px;box-sizing:border-box;}
.iti-map-legend .lg-badge.stop{background:#C0211B;}
.iti-map-legend .lg-badge.air{background:#1F5673;font-size:13px;padding:0;width:24px;border-radius:50%;}
.iti-map-legend .lg-name{padding-top:3px;}
.iti-map-legend .lg-role{display:block;font-size:.6rem;font-weight:700;text-transform:uppercase;
  letter-spacing:.08em;color:#1F5673;}
.iti-map-legend .lg-code{color:#999591;}
.iti-map-legend .lg-leg{margin:3px 0 3px 11px;padding-left:16px;border-left:2px dotted #C9C4BE;
  font-size:.72rem;color:#999591;}
.iti-map-legend .lg-note{margin-top:14px;font-size:.7rem;color:#999591;}
@media(max-width:660px){
  .iti-map-wrap .iti-map{flex-basis:100%;height:340px;}
  .iti-map-legend{flex-basis:100%;border-left:none;border-top:1px solid #E5E2DE;max-height:none;}
}
</style>
<?php endif; ?>
<div class="iti-map-legend">
  <div class="lg-title"><?= h(iti_lbl_map_legend($lang)) ?></div>
  <?php $has_dist = false; foreach ($map['legend'] as $row):
      $is_air = ($row['role'] === 'start' || $row['role'] === 'end');
      if ($row['dist'] !== null): $has_dist = true; ?>
      <div class="lg-leg">&approx; <?= number_format((float)$row['dist']) ?> km</div>
  <?php endif;
      $role_lbl = $row['role'] === 'start' ? iti_lbl_map_start($lang)
                : ($row['role'] === 'end' ? iti_lbl_map_end($lang) : ''); ?>
  <div class="lg-row">
    <?php if ($is_air): ?>
      <span class="lg-badge air">&#9992;</span>
    <?php else: ?>
      <span class="lg-badge stop"><?= (int)$row['num'] ?></span>
    <?php endif; ?>
    <span class="lg-name">
      <?php if ($role_lbl): ?><span class="lg-role"><?= h($role_lbl) ?></span><?php endif; ?>
      <?= h((string)$row['name']) ?><?php if (!empty($row['code'])): ?> <span class="lg-code">(<?= h($row['code']) ?>)</span><?php endif; ?>
    </span>
  </div>
  <?php endforeach; ?>
  <?php if ($has_dist): ?><div class="lg-note"><?= h(iti_lbl_map_airdist($lang)) ?></div><?php endif; ?>
</div>
