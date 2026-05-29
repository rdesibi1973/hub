<?php
/**
 * iti_import_lodges_zanzibar.php
 * Static import of Zanzibar & Northern Circuit lodges.
 * Sources: Bougainvillea Group, Mvuvi, Villa Kiva, White Dream, Zanzibar Pearl,
 *          Turaco/Marriott, SeVi, RIU Palace, RIU Jambo, Z Hotel, Z2 Hotel,
 *          My Blue Hotel, Royal Zanzibar.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db       = db();
$_cu      = current_user();
$can_edit = in_array($_cu['role_name'], ['admin', 'manager']);

// ── Destination maps ─────────────────────────────────────────────────────────
$dest_rows    = iti_get_destinations(false);
$dest_by_code = [];
$dest_by_id   = [];
foreach ($dest_rows as $d) {
    $dest_by_code[$d['code']] = $d['id'];
    $dest_by_id[$d['id']]     = $d['name_en'];
}
function did2(string $code): int { global $dest_by_code; return $dest_by_code[$code] ?? 0; }

// ── Existing lodge names ──────────────────────────────────────────────────────
$existing = [];
foreach ($db->query("SELECT LOWER(name) AS n FROM iti_lodges")->fetchAll() as $r)
    $existing[] = $r['n'];

// ── Lodge data ────────────────────────────────────────────────────────────────
$LODGES = [

    // ── BOUGAINVILLEA GROUP ───────────────────────────────────────────────────
    [
        'group'          => 'Bougainvillea Group',
        'dest_code'      => 'KAR',
        'name'           => 'Bougainvillea Safari Lodge',
        'category'       => 'mid',
        'lodge_type'     => 'lodge',
        'website'        => 'https://www.bougainvilleagroup.com/bougainvillea-safari-lodge/',
        'description_en' => 'A 32-room lodge on the outskirts of Karatu, midway between Lake Manyara and the Ngorongoro Crater. Set in lush tropical gardens with a pool, offering simple homey comforts, fresh country cuisine, and warm Tanzanian hospitality. Rooms feature king or twin beds, fireplaces, and verandas. Part of Bougainvillea Group\'s northern circuit collection since 2005.',
    ],
    [
        'group'          => 'Bougainvillea Group',
        'dest_code'      => 'KAR',
        'name'           => 'Country Lodge Karatu',
        'category'       => 'mid',
        'lodge_type'     => 'lodge',
        'website'        => 'https://www.bougainvilleagroup.com/country-lodge-karatu/',
        'description_en' => 'A comfortable lodge on the outskirts of Karatu, conveniently located between Lake Manyara National Park and the Ngorongoro Crater. Offers a stepping stone for safari travellers exploring the northern circuit, with comfortable rooms, a pool, restaurant, and friendly service.',
    ],
    [
        'group'          => 'Bougainvillea Group',
        'dest_code'      => 'TRP',
        'name'           => 'Sangaiwe Tented Lodge',
        'category'       => 'mid',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://www.bougainvilleagroup.com/sangaiwe-tented-lodge/',
        'description_en' => 'A luxury tented lodge set on 40 acres of hillside woodland near the Sangaiwe gate of Tarangire National Park, with stunning views over Lake Burunge. Built with minimal environmental impact using solar heating and indigenous landscaping. Features well-appointed tents, great food, and a friendly atmosphere ideal for Tarangire safaris.',
    ],
    [
        'group'          => 'Bougainvillea Group',
        'dest_code'      => 'EYS',
        'name'           => 'Lake Eyasi Safari Lodge',
        'category'       => 'mid',
        'lodge_type'     => 'lodge',
        'website'        => 'https://www.bougainvilleagroup.com/lake-eyasi-safari-lodge/',
        'description_en' => 'A lodge close to Lake Eyasi, one of Tanzania\'s most remote and atmospheric soda lakes, famous for cultural encounters with the Hadzabe bushmen and Datoga tribe. Offers stunning views over the seasonal lake and guided cultural and nature experiences in a truly off-the-beaten-track setting.',
    ],
    [
        'group'          => 'Bougainvillea Group',
        'dest_code'      => 'NCA',
        'name'           => 'Marera Valley Lodge',
        'category'       => 'mid',
        'lodge_type'     => 'lodge',
        'website'        => 'https://www.bougainvilleagroup.com/marera-valley-lodge/',
        'description_en' => 'A lodge in the scenic Marera Valley near the Ngorongoro Conservation Area, offering panoramic views over the valley and surrounding highlands. Features comfortable rooms, a swimming pool, and easy access to Ngorongoro Crater and Lake Manyara for game drives.',
    ],
    [
        'group'          => 'Bougainvillea Group',
        'dest_code'      => 'NCA',
        'name'           => 'Ngorongoro Coffee Lodge',
        'category'       => 'luxury',
        'lodge_type'     => 'lodge',
        'website'        => 'https://www.bougainvilleagroup.com/ngorongoro-coffee-lodge-2/',
        'description_en' => 'A luxury lodge set on a working coffee farm near the Ngorongoro Conservation Area, combining the aroma of fresh coffee with spectacular crater rim views. Features elegantly appointed cottages, fine dining, a spa, and a pool. Described as the "Home of Luxury" on the northern circuit.',
    ],
    [
        'group'          => 'Bougainvillea Group',
        'dest_code'      => 'SNP',
        'name'           => 'Thorn Tree Camp',
        'category'       => 'mid',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://www.bougainvilleagroup.com/thorn-tree-camp/',
        'description_en' => 'A classic solar-powered tented safari camp in the Seronera Valley of central Serengeti National Park, about 15 minutes from Seronera. Open year-round with traditional Tanzanian hospitality and great food. The valley is famous for lion, leopard, and large elephant herds, with views of Banagi Hill and the classic Serengeti plains.',
    ],
    [
        'group'          => 'Bougainvillea Group',
        'dest_code'      => 'SNP',
        'name'           => 'Hippo Trails Camp',
        'category'       => 'mid',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://www.bougainvilleagroup.com/hippo-trails-camp/',
        'description_en' => 'A special campsite located inside Serengeti National Park offering year-round wildlife viewing. Part of the Bougainvillea Group\'s collection, the camp delivers classic tented safari accommodation with friendly service and authentic bush atmosphere in one of Africa\'s most celebrated wildlife areas.',
    ],
    [
        'group'          => 'Bougainvillea Group',
        'dest_code'      => 'SNP',
        'name'           => 'Tamba Tented Camp Serengeti',
        'category'       => 'luxury',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://tambacamp.com/',
        'description_en' => 'The newest and most upmarket property in the Bougainvillea Group, a permanent luxury camp in the Serengeti offering unparalleled comfort in the heart of nature. Features spacious luxury tents, gourmet dining, and expert guides for an elevated Serengeti safari experience.',
    ],

    // ── ZANZIBAR — BEACH & BOUTIQUE ───────────────────────────────────────────
    [
        'group'          => 'Zanzibar Beach Hotels',
        'dest_code'      => 'ZNZB',
        'name'           => 'Mvuvi Boutique Resort',
        'category'       => 'luxury',
        'lodge_type'     => 'hotel',
        'website'        => 'https://mvuvizanzibar.com/',
        'description_en' => 'An intimate beachfront boutique resort in Kiwengwa on the northeast coast of Zanzibar, known for its Swahili-Mediterranean dining, infinity pools, spa, yoga, and a full watersports programme including kitesurfing, windsurfing, wing-foiling, SUP, and diving. Features elegant rooms with sea and garden views in a relaxed, adults-friendly atmosphere.',
    ],
    [
        'group'          => 'Zanzibar Beach Hotels',
        'dest_code'      => 'ZNZB',
        'name'           => 'Villa Kiva Hotel',
        'category'       => 'mid',
        'lodge_type'     => 'hotel',
        'website'        => 'https://www.villakiva.com/',
        'description_en' => 'A 28-room boutique hotel in Zanzibar with sea-view and garden-view bungalows, a restaurant and bar, spa, and diving centre. A relaxed and romantic retreat ideal for couples seeking a quiet beach holiday or diving adventure. Offers easy access to Zanzibar\'s coral reefs and island excursions.',
    ],
    [
        'group'          => 'Zanzibar Beach Hotels',
        'dest_code'      => 'ZNZB',
        'name'           => 'White Dream Zanzibar',
        'category'       => 'mid',
        'lodge_type'     => 'hotel',
        'website'        => 'https://whitedreamzanzibar.com/',
        'description_en' => 'A private beach lodge directly on the beach of Kiwengwa, Zanzibar, with a swimming pool, lounge bar, restaurant, and a private beach. Offers an intimate and relaxed atmosphere with Italian-influenced hospitality, perfect for a quiet seaside escape on the northeast coast of the island.',
    ],
    [
        'group'          => 'Zanzibar Beach Hotels',
        'dest_code'      => 'ZNZB',
        'name'           => 'Zanzibar Pearl Boutique Hotel & Villas',
        'category'       => 'luxury',
        'lodge_type'     => 'hotel',
        'website'        => 'https://zanzibarpearl.com/',
        'description_en' => 'A unique ocean-front boutique hotel and villas in Matemwe on the spectacular northeast coast of Zanzibar. Set directly on one of the island\'s most beautiful beaches, it features suites and private villas, a restaurant and bar, water activities, and easy access to the Mnemba Atoll for snorkelling and diving.',
    ],
    [
        'group'          => 'Zanzibar Beach Hotels',
        'dest_code'      => 'ZNZB',
        'name'           => 'SeVi Boutique Hotel Zanzibar',
        'category'       => 'mid',
        'lodge_type'     => 'hotel',
        'website'        => 'https://sevihotelzanzibar.com/',
        'description_en' => 'An intimate boutique hotel in the idyllic fishing village of Kigomani in Matemwe, Zanzibar. Set in a tranquil natural environment close to the beach, SeVi offers a peaceful escape with personalised service, carefully designed rooms, and easy access to the coral reef and local village life.',
    ],

    // ── ZANZIBAR — NUNGWI RESORT BELT ─────────────────────────────────────────
    [
        'group'          => 'Zanzibar — Nungwi',
        'dest_code'      => 'ZNZB',
        'name'           => 'Turaco Nungwi Resort',
        'category'       => 'luxury',
        'lodge_type'     => 'hotel',
        'website'        => 'https://www.marriott.com/en-us/hotels/znznt-turaco-nungwi-resort-a-tribute-portfolio-hotel/overview/',
        'description_en' => 'A 4-star boutique resort in the Marriott Tribute Portfolio, nestled directly on Nungwi Beach at the northern tip of Zanzibar. Features 98 spacious rooms with balconies or terraces overlooking the pool, tropical gardens, or the Indian Ocean. Offers a freshwater pool with swim-up bar, wellness centre, two restaurants (Ngalawa and Fisherman\'s Grill), and easy access to Nungwi village and its traditional dhow craftsmanship.',
    ],
    [
        'group'          => 'Zanzibar — Nungwi',
        'dest_code'      => 'ZNZB',
        'name'           => 'Hotel RIU Palace Zanzibar',
        'category'       => 'ultra_luxury',
        'lodge_type'     => 'hotel',
        'website'        => 'https://www.riu.com/en/hotel/tanzania/zanzibar/hotel-riu-palace-zanzibar',
        'description_en' => 'A 5-star all-inclusive resort on Nungwi Beach, the north coast\'s most celebrated stretch of white sand. Features 236 elegantly designed rooms and villas — some with private pools — three outdoor pools, a full-service spa with relaxation pool and steam bath, dive centre, and multiple themed restaurants and bars with show-cooking stations. Built in traditional Zanzibar style with a romantic, refined atmosphere.',
    ],
    [
        'group'          => 'Zanzibar — Nungwi',
        'dest_code'      => 'ZNZB',
        'name'           => 'Hotel RIU Jambo',
        'category'       => 'luxury',
        'lodge_type'     => 'hotel',
        'website'        => 'https://www.riu.com/en/hotel/tanzania/zanzibar/hotel-riu-jambo',
        'description_en' => 'A large 4-star all-inclusive resort directly on Nungwi Beach, adjacent to RIU Palace, with 461 rooms in traditional architectural style. Features four outdoor pools (one adults-only with swim-up bar), nine restaurants and bars, a spa, fitness centre, kids\' club, and a packed entertainment programme. An ideal choice for families and groups looking for a lively, full-service Zanzibar beach holiday.',
    ],
    [
        'group'          => 'Zanzibar — Nungwi',
        'dest_code'      => 'ZNZB',
        'name'           => 'The Z Hotel Zanzibar',
        'category'       => 'luxury',
        'lodge_type'     => 'hotel',
        'website'        => 'https://www.thezhotel.com/',
        'description_en' => 'The original boutique hotel on Zanzibar\'s Nungwi Beach, celebrated for its contemporary design, casual luxury, and relaxed atmosphere. Features stylish rooms and suites, a pool, beach access, and a range of water activities. Known for its personable service and as the flagship property of the Z Hotel group.',
    ],
    [
        'group'          => 'Zanzibar — Nungwi',
        'dest_code'      => 'ZNZB',
        'name'           => 'The Z2 Hotel Zanzibar',
        'category'       => 'luxury',
        'lodge_type'     => 'hotel',
        'website'        => 'https://www.thez2.com/',
        'description_en' => 'A boutique hotel near Nungwi Beach from the creators of The Z Hotel, offering a fresh holiday experience that blends casual relaxation with contemporary design. Features well-appointed rooms, dining, a wellness area, and easy access to Nungwi\'s beach and activities. Book direct for best rates.',
    ],
    [
        'group'          => 'Zanzibar — Nungwi',
        'dest_code'      => 'ZNZB',
        'name'           => 'My Blue Hotel Zanzibar',
        'category'       => 'mid',
        'lodge_type'     => 'hotel',
        'website'        => 'https://mybluehotel.com/',
        'description_en' => 'A 4-star all-inclusive resort on Nungwi Beach, set in a tropical garden at the northern tip of Zanzibar, 200 metres from the local fishing village. Features 127 rooms with garden or ocean views, two outdoor pools (including an infinity pool directly on the beach), a dive centre, spa, three restaurants including the beachfront Mwezi and themed Ali Baba, and a gym. Ideal for couples and families seeking value and atmosphere.',
    ],
    [
        'group'          => 'Zanzibar — Nungwi',
        'dest_code'      => 'ZNZB',
        'name'           => 'Royal Zanzibar Beach Resort',
        'category'       => 'luxury',
        'lodge_type'     => 'hotel',
        'website'        => 'https://www.royalzanzibar.com/',
        'description_en' => 'A luxury all-inclusive beach resort on Nungwi Beach in the heart of the village, offering 160 rooms and 22 suites with stunning ocean views, designed in contemporary Swahili style with Makuti palm-leaf roofs. Features four outdoor pools, two restaurants (Spices and the cliff-top Samaki for fresh seafood), a spa, watersports, and breathtaking sunset views. One of Nungwi\'s most acclaimed properties.',
    ],
];

// ── Add dest_id and duplicate flag ───────────────────────────────────────────
foreach ($LODGES as &$l) {
    $l['dest_id']      = did2($l['dest_code']);
    $l['is_duplicate'] = in_array(strtolower($l['name']), $existing);
}
unset($l);

// ── Group by partner ──────────────────────────────────────────────────────────
$by_group = [];
foreach ($LODGES as $i => $l) {
    $l['_idx'] = $i;
    $by_group[$l['group']][] = $l;
}

// ── POST: import ──────────────────────────────────────────────────────────────
$import_log  = [];
$import_done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $stmt = $db->prepare(
        'INSERT INTO iti_lodges
         (destination_id,name,category,lodge_type,website,description_en,is_active)
         VALUES (?,?,?,?,?,?,1)'
    );
    $ok = $skip = $err = 0;
    foreach ($LODGES as $i => $l) {
        if (isset($_POST["skip_{$i}"])) { $skip++; continue; }
        $dest_id = (int)($_POST["dest_{$i}"] ?? $l['dest_id']);
        if (!$dest_id) {
            $import_log[] = "⚠ No destination — skipped: {$l['name']}"; $skip++; continue;
        }
        if (in_array(strtolower($l['name']), $existing)) {
            $import_log[] = "⏭ Already exists — skipped: {$l['name']}"; $skip++; continue;
        }
        try {
            $stmt->execute([
                $dest_id, $l['name'],
                $_POST["cat_{$i}"]  ?? $l['category'],
                $_POST["type_{$i}"] ?? $l['lodge_type'],
                $l['website'], $l['description_en'],
            ]);
            $existing[]   = strtolower($l['name']);
            $import_log[] = "✅ Imported: {$l['name']} ({$dest_by_id[$dest_id]})";
            $ok++;
        } catch (Exception $e) {
            $import_log[] = "❌ Error — {$l['name']}: " . $e->getMessage();
            $err++;
        }
    }
    $import_log[] = "─── Done: {$ok} imported, {$skip} skipped, {$err} errors ───";
    $import_done  = true;
}

// ── Page ──────────────────────────────────────────────────────────────────────
$page_title = 'Import Zanzibar & Northern Circuit Lodges';
$extra_css  = iti_extra_css() . '
.group-header{background:var(--green);color:#fff;padding:8px 14px;font-size:.75rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;border-radius:6px 6px 0 0;margin-top:20px;}
.import-table{width:100%;border-collapse:collapse;background:#fff;font-size:.78rem;border:1px solid var(--grey-lt);}
.import-table th{background:#f0f0ef;padding:7px 10px;text-align:left;font-size:.71rem;white-space:nowrap;border-bottom:1.5px solid var(--grey-lt);}
.import-table td{padding:8px 10px;border-bottom:1px solid #f0f0ef;vertical-align:middle;}
.import-table tr.dup td{background:#fffbeb;}
.badge-dup{display:inline-block;padding:1px 7px;border-radius:4px;background:#FEF3C7;color:#92400E;font-size:.65rem;font-weight:700;margin-left:6px;vertical-align:middle;}
.badge-nodest{background:#fee2e2;color:#991b1b;}
.log-box{background:#1a1a1a;color:#a8e6a3;font-family:monospace;font-size:.75rem;padding:14px 16px;border-radius:8px;max-height:220px;overflow-y:auto;white-space:pre-wrap;margin:12px 0;}
';
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
<?php iti_nav('Import Zanzibar & Northern Circuit Lodges'); ?>
<?php iti_flash_render(); ?>

<div class="page-header">
  <div>
    <h2>🌴 Import Zanzibar &amp; Northern Circuit Lodges</h2>
    <div class="sub">Master Data › Lodges › Partner Import — <?= count($LODGES) ?> lodges from <?= count($by_group) ?> groups</div>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="iti_import_lodges_web.php" class="btn btn-outline btn-sm">← Safari Partners</a>
    <a href="lodges.php" class="btn btn-outline btn-sm">← All Lodges</a>
  </div>
</div>

<?php if ($import_done): ?>
<div class="form-card">
  <div class="form-section-title">Import Result</div>
  <div class="log-box"><?= implode("\n", array_map('htmlspecialchars', $import_log)) ?></div>
  <div style="margin-top:16px;display:flex;gap:10px;">
    <a href="lodges.php" class="btn btn-red">→ View All Lodges</a>
    <a href="iti_import_lodges_zanzibar.php" class="btn btn-outline">↩ Import again</a>
  </div>
</div>

<?php else: ?>
<form method="POST" action="iti_import_lodges_zanzibar.php">
<div class="form-card">
  <div class="form-section-title">Review &amp; Import</div>
  <p style="font-size:.8rem;color:var(--grey-mid);margin-bottom:4px;">
    Review each lodge. Adjust destination, category, or type if needed. Check <strong>Skip</strong> to exclude.
    <span class="badge-dup">EXISTS</span> = already in DB (pre-checked for skip).
    <span class="badge-dup badge-nodest">NO DEST</span> = assign a destination or it will be skipped.
  </p>
  <p style="font-size:.78rem;color:var(--grey-mid);margin-bottom:16px;">
    <strong><?= count(array_filter($LODGES, fn($l) => !$l['is_duplicate'])) ?></strong> new lodges ready to import
    &nbsp;·&nbsp; <strong><?= count(array_filter($LODGES, fn($l) => $l['is_duplicate'])) ?></strong> already in database
  </p>

  <?php foreach ($by_group as $group => $lodges): ?>
  <div class="group-header"><?= h($group) ?> <span style="font-weight:400;opacity:.8;"><?= count($lodges) ?> lodges</span></div>
  <table class="import-table" style="border-radius:0 0 6px 6px;margin-bottom:0;">
    <thead>
      <tr>
        <th style="width:36px;text-align:center;">Skip</th>
        <th>Lodge Name</th>
        <th style="min-width:180px;">Destination</th>
        <th>Category</th>
        <th>Type</th>
        <th style="max-width:300px;">Description</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($lodges as $l):
      $i      = $l['_idx'];
      $is_dup = $l['is_duplicate'];
      $no_dst = !$l['dest_id'];
    ?>
    <tr class="<?= $is_dup ? 'dup' : '' ?>">
      <td style="text-align:center;">
        <input type="checkbox" name="skip_<?= $i ?>" value="1" <?= $is_dup ? 'checked' : '' ?>>
      </td>
      <td>
        <strong><?= h($l['name']) ?></strong>
        <?php if ($is_dup): ?><span class="badge-dup">EXISTS</span><?php endif; ?>
        <?php if ($no_dst && !$is_dup): ?><span class="badge-dup badge-nodest">NO DEST</span><?php endif; ?>
        <div style="font-size:.68rem;color:var(--grey-mid);margin-top:2px;">
          <a href="<?= h($l['website']) ?>" target="_blank" rel="noopener"><?= h($l['website']) ?></a>
        </div>
      </td>
      <td>
        <select name="dest_<?= $i ?>" style="font-size:.78rem;">
          <option value="">— Select —</option>
          <?php foreach ($dest_by_id as $did => $dname): ?>
          <option value="<?= $did ?>" <?= $did==$l['dest_id']?'selected':'' ?>><?= h($dname) ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td>
        <select name="cat_<?= $i ?>" style="font-size:.78rem;">
          <?php foreach (['budget'=>'Budget','mid'=>'Mid-range','luxury'=>'Luxury','ultra_luxury'=>'Ultra Luxury'] as $cv=>$cl): ?>
          <option value="<?= $cv ?>" <?= $l['category']===$cv?'selected':'' ?>><?= $cl ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td>
        <select name="type_<?= $i ?>" style="font-size:.78rem;">
          <?php foreach (['lodge'=>'Lodge','tented_camp'=>'Tented Camp','hotel'=>'Hotel','mobile_camp'=>'Mobile Camp','house'=>'House'] as $tv=>$tl): ?>
          <option value="<?= $tv ?>" <?= $l['lodge_type']===$tv?'selected':'' ?>><?= $tl ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td style="font-size:.71rem;color:#555;max-width:300px;">
        <?= h(mb_substr($l['description_en'], 0, 160)) ?>…
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endforeach; ?>

  <?php if ($can_edit): ?>
  <div style="margin-top:20px;display:flex;gap:10px;align-items:center;">
    <button type="submit" class="btn btn-red">⬆ Import Selected Lodges</button>
    <a href="lodges.php" class="btn btn-outline">Cancel</a>
    <span style="margin-left:auto;font-size:.75rem;color:var(--grey-mid);">Unchecked rows will be imported. EXISTS rows are pre-skipped.</span>
  </div>
  <?php else: ?>
  <p style="color:var(--grey-mid);margin-top:16px;">Admin or manager role required to import.</p>
  <?php endif; ?>
</div>
</form>
<?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
