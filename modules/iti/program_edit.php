<?php
/**
 * modules/iti/program_edit.php
 * Costruttore itinerario — giorno per giorno
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db   = db();
$_cu  = current_user();
$id   = (int)($_GET['id'] ?? 0);

if (!$id) { iti_flash_set('error','No program specified.'); iti_redirect('programs.php'); }

$program = iti_get_program($id);
if (!$program) { iti_flash_set('error','Program not found.'); iti_redirect('programs.php'); }

// ── SALVA GIORNO (POST) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sub = $_POST['_sub'] ?? '';

    // ── Salva header programma ──
    if ($sub === 'header') {
        $db->prepare(
            'UPDATE iti_programs SET
             ref_number=?,
             title_en=?,title_it=?,title_fr=?,title_es=?,title_de=?,
             subtitle_en=?,subtitle_it=?,subtitle_fr=?,subtitle_es=?,subtitle_de=?,
             start_date=?,pax_adults=?,pax_children=?,flights_included=?,
             display_language=?,display_currency=?,terms_id=?,status=?
             WHERE id=?'
        )->execute([
            trim($_POST['ref_number'] ?? '') ?: null,
            trim($_POST['title_en']),trim($_POST['title_it']),trim($_POST['title_fr']),
            trim($_POST['title_es']),trim($_POST['title_de']),
            trim($_POST['subtitle_en']),trim($_POST['subtitle_it']),trim($_POST['subtitle_fr']),
            trim($_POST['subtitle_es']),trim($_POST['subtitle_de']),
            ($_POST['start_date'] ?? '') ?: null,
            max(1,(int)$_POST['pax_adults']),
            max(0,(int)$_POST['pax_children']),
            isset($_POST['flights_included'])?1:0,
            $_POST['display_language']??'en',
            $_POST['display_currency']??'USD',
            ($_POST['terms_id']!==''?(int)$_POST['terms_id']:null),
            $_POST['status']??'draft',
            $id,
        ]);
        iti_flash_set('success','Program header saved.');
        iti_redirect("program_edit.php?id={$id}&tab=info");
    }

    // ── Salva giorno ──
    if ($sub === 'day') {
        $day_id = (int)($_POST['day_id'] ?? 0);
        if ($day_id) {
            // Combo fields: se c'è un id FK lo usa, altrimenti salva il testo libero
            $start_id   = ($_POST['start_lodge_id']   !== '' ? (int)$_POST['start_lodge_id']   : null);
            $start_txt  = ($start_id  === null ? trim($_POST['start_lodge_custom']  ?? '') : null) ?: null;
            $tr_id      = ($_POST['transfer_route_id'] !== '' ? (int)$_POST['transfer_route_id'] : null);
            $tr_txt     = ($tr_id     === null ? trim($_POST['transfer_custom']      ?? '') : null) ?: null;
            $dest_id    = ($_POST['destination_id']   !== '' ? (int)$_POST['destination_id']   : null);
            $dest_txt   = ($dest_id   === null ? trim($_POST['destination_custom']   ?? '') : null) ?: null;
            $end_id     = ($_POST['end_lodge_id']     !== '' ? (int)$_POST['end_lodge_id']     : null);
            $end_txt    = ($end_id    === null ? trim($_POST['end_lodge_custom']     ?? '') : null) ?: null;

            $db->prepare(
                'UPDATE iti_program_days SET
                 day_title_en=?,day_title_it=?,day_title_fr=?,day_title_es=?,day_title_de=?,
                 start_lodge_id=?,start_custom=?,
                 transfer_route_id=?,transfer_custom=?,
                 destination_id=?,destination_custom=?,
                 narrative_en=?,narrative_it=?,narrative_fr=?,narrative_es=?,narrative_de=?,
                 end_lodge_id=?,end_lodge_custom=?,
                 meal_breakfast=?,meal_lunch=?,meal_dinner=?
                 WHERE id=? AND program_id=?'
            )->execute([
                trim($_POST['day_title_en']),trim($_POST['day_title_it']),
                trim($_POST['day_title_fr']),trim($_POST['day_title_es']),trim($_POST['day_title_de']),
                $start_id, $start_txt,
                $tr_id, $tr_txt,
                $dest_id, $dest_txt,
                trim($_POST['narrative_en']),trim($_POST['narrative_it']),
                trim($_POST['narrative_fr']),trim($_POST['narrative_es']),trim($_POST['narrative_de']),
                $end_id, $end_txt,
                isset($_POST['meal_breakfast'])?1:0,
                isset($_POST['meal_lunch'])?1:0,
                isset($_POST['meal_dinner'])?1:0,
                $day_id, $id,
            ]);
        }
        iti_flash_set('success','Day saved.');
        iti_redirect("program_edit.php?id={$id}&day={$day_id}");
    }

    // ── Aggiungi attività ──
    if ($sub === 'add_activity') {
        $day_id      = (int)($_POST['day_id']     ?? 0);
        $activity_id = (int)($_POST['activity_id']?? 0);
        if ($day_id && $activity_id) {
            $max_sort = (int)$db->prepare('SELECT MAX(sort_order) FROM iti_day_activities WHERE program_day_id=?')->execute([$day_id]) ? $db->prepare('SELECT COALESCE(MAX(sort_order),0) FROM iti_day_activities WHERE program_day_id=?') : 0;
            $ms = $db->prepare('SELECT COALESCE(MAX(sort_order),0) FROM iti_day_activities WHERE program_day_id=?');
            $ms->execute([$day_id]); $max_sort = (int)$ms->fetchColumn();
            $db->prepare('INSERT INTO iti_day_activities (program_day_id,activity_id,sort_order) VALUES (?,?,?)')->execute([$day_id,$activity_id,$max_sort+1]);
        }
        iti_redirect("program_edit.php?id={$id}&day={$day_id}");
    }

    // ── Rimuovi attività ──
    if ($sub === 'remove_activity') {
        $da_id  = (int)($_POST['da_id']  ?? 0);
        $day_id = (int)($_POST['day_id'] ?? 0);
        if ($da_id) $db->prepare('DELETE FROM iti_day_activities WHERE id=?')->execute([$da_id]);
        iti_redirect("program_edit.php?id={$id}&day={$day_id}");
    }


    // ── Aggiungi giorno ──
    if ($sub === 'add_day') {
        $max = (int)$db->prepare('SELECT COALESCE(MAX(day_number),0) FROM iti_program_days WHERE program_id=?')->execute([$id]) ? $db->query("SELECT COALESCE(MAX(day_number),0) FROM iti_program_days WHERE program_id={$id}")->fetchColumn() : 0;
        $max_st = $db->prepare('SELECT COALESCE(MAX(day_number),0) FROM iti_program_days WHERE program_id=?');
        $max_st->execute([$id]);
        $max = (int)$max_st->fetchColumn();
        $new_num = $max + 1;
        $db->prepare('INSERT INTO iti_program_days (program_id, day_number) VALUES (?,?)')->execute([$id, $new_num]);
        $db->prepare('UPDATE iti_programs SET duration_days=? WHERE id=?')->execute([$new_num, $id]);
        iti_flash_set('success', "Day {$new_num} added.");
        iti_redirect("program_edit.php?id={$id}&tab=days");
    }

    // ── Cancella giorno ──
    if ($sub === 'delete_day') {
        $day_id  = (int)($_POST['day_id'] ?? 0);
        $day_num = (int)($_POST['day_num'] ?? 0);
        if ($day_id) {
            $db->prepare('DELETE FROM iti_program_days WHERE id=?')->execute([$day_id]);
            // Rinumera i giorni successivi
            $remaining = $db->prepare('SELECT id FROM iti_program_days WHERE program_id=? ORDER BY day_number');
            $remaining->execute([$id]);
            $num = 1;
            foreach ($remaining->fetchAll() as $r) {
                $db->prepare('UPDATE iti_program_days SET day_number=? WHERE id=?')->execute([$num++, $r['id']]);
            }
            $new_count = $num - 1;
            $db->prepare('UPDATE iti_programs SET duration_days=? WHERE id=?')->execute([$new_count, $id]);
            iti_flash_set('success', 'Day deleted and days renumbered.');
        }
        iti_redirect("program_edit.php?id={$id}&tab=days");
    }

    // ── Sposta giorno su / giù ──
    if ($sub === 'move_day') {
        $day_id    = (int)($_POST['day_id']    ?? 0);
        $direction = $_POST['direction'] ?? 'up';
        if ($day_id) {
            $cur = $db->prepare('SELECT day_number FROM iti_program_days WHERE id=?');
            $cur->execute([$day_id]); $cur_num = (int)$cur->fetchColumn();
            $swap_num = $direction === 'up' ? $cur_num - 1 : $cur_num + 1;
            $swap = $db->prepare('SELECT id FROM iti_program_days WHERE program_id=? AND day_number=?');
            $swap->execute([$id, $swap_num]); $swap_id = (int)$swap->fetchColumn();
            if ($swap_id) {
                $db->prepare('UPDATE iti_program_days SET day_number=? WHERE id=?')->execute([$swap_num, $day_id]);
                $db->prepare('UPDATE iti_program_days SET day_number=? WHERE id=?')->execute([$cur_num, $swap_id]);
            }
        }
        iti_redirect("program_edit.php?id={$id}&tab=days");
    }

    // ── Aggiungi volo ──
    if ($sub === 'add_flight') {
        $day_id          = (int)($_POST['day_id']          ?? 0);
        $flight_route_id = (int)($_POST['flight_route_id'] ?? 0);
        if ($day_id && $flight_route_id) {
            $ms = $db->prepare('SELECT COALESCE(MAX(sort_order),0) FROM iti_day_flights WHERE program_day_id=?');
            $ms->execute([$day_id]); $max_sort = (int)$ms->fetchColumn();
            $db->prepare('INSERT INTO iti_day_flights (program_day_id,flight_route_id,departure_time,arrival_time,sort_order) VALUES (?,?,?,?,?)')->execute([
                $day_id,$flight_route_id,
                ($_POST['departure_time']??'')?:null,
                ($_POST['arrival_time']??'')?:null,
                $max_sort+1,
            ]);
        }
        iti_redirect("program_edit.php?id={$id}&day={$day_id}");
    }

    // ── Rimuovi volo ──
    if ($sub === 'remove_flight') {
        $df_id  = (int)($_POST['df_id']  ?? 0);
        $day_id = (int)($_POST['day_id'] ?? 0);
        if ($df_id) $db->prepare('DELETE FROM iti_day_flights WHERE id=?')->execute([$df_id]);
        iti_redirect("program_edit.php?id={$id}&day={$day_id}");
    }

    // ── Salva prezzi ──
    if ($sub === 'prices') {
        foreach (array_keys(ITI_PRICE_CATEGORIES) as $cat) {
            if (!isset($_POST["price_{$cat}"])) continue;
            $pp_usd  = $_POST["pp_usd_{$cat}"]  !== '' ? (float)$_POST["pp_usd_{$cat}"]  : null;
            $pp_eur  = $_POST["pp_eur_{$cat}"]  !== '' ? (float)$_POST["pp_eur_{$cat}"]  : null;
            $ss_usd  = $_POST["ss_usd_{$cat}"]  !== '' ? (float)$_POST["ss_usd_{$cat}"]  : null;
            $ss_eur  = $_POST["ss_eur_{$cat}"]  !== '' ? (float)$_POST["ss_eur_{$cat}"]  : null;
            $ch_usd  = $_POST["ch_usd_{$cat}"]  !== '' ? (float)$_POST["ch_usd_{$cat}"]  : null;
            $ch_eur  = $_POST["ch_eur_{$cat}"]  !== '' ? (float)$_POST["ch_eur_{$cat}"]  : null;

            // UPSERT
            $existing = $db->prepare('SELECT id FROM iti_program_prices WHERE program_id=? AND price_category=?');
            $existing->execute([$id,$cat]);
            if ($existing->fetchColumn()) {
                $db->prepare('UPDATE iti_program_prices SET price_per_pax_usd=?,price_per_pax_eur=?,single_suppl_usd=?,single_suppl_eur=?,child_price_usd=?,child_price_eur=? WHERE program_id=? AND price_category=?')
                   ->execute([$pp_usd,$pp_eur,$ss_usd,$ss_eur,$ch_usd,$ch_eur,$id,$cat]);
            } else {
                $db->prepare('INSERT INTO iti_program_prices (program_id,price_category,price_per_pax_usd,price_per_pax_eur,single_suppl_usd,single_suppl_eur,child_price_usd,child_price_eur) VALUES (?,?,?,?,?,?,?,?)')
                   ->execute([$id,$cat,$pp_usd,$pp_eur,$ss_usd,$ss_eur,$ch_usd,$ch_eur]);
            }
        }
        iti_flash_set('success','Prices saved.');
        iti_redirect("program_edit.php?id={$id}&tab=prices");
    }

    // ── Salva inclusi ──
    if ($sub === 'inclusions') {
        $db->prepare('DELETE FROM iti_program_inclusions WHERE program_id=?')->execute([$id]);
        $items     = $_POST['inc_type']  ?? [];
        $texts_en  = $_POST['inc_en']    ?? [];
        $texts_it  = $_POST['inc_it']    ?? [];
        $texts_fr  = $_POST['inc_fr']    ?? [];
        $texts_es  = $_POST['inc_es']    ?? [];
        $texts_de  = $_POST['inc_de']    ?? [];
        $std_ids   = $_POST['inc_std']   ?? [];
        foreach ($items as $i => $type) {
            if (trim($texts_en[$i] ?? '') === '') continue;
            $db->prepare('INSERT INTO iti_program_inclusions (program_id,item_type,standard_inclusion_id,text_en,text_it,text_fr,text_es,text_de,sort_order) VALUES (?,?,?,?,?,?,?,?,?)')
               ->execute([$id,$type,$std_ids[$i]??null,$texts_en[$i],$texts_it[$i]??'',$texts_fr[$i]??'',$texts_es[$i]??'',$texts_de[$i]??'',$i]);
        }
        iti_flash_set('success','Inclusions saved.');
        iti_redirect("program_edit.php?id={$id}&tab=inclusions");
    }

    // ── Pubblica / genera token ──
    if ($sub === 'publish') {
        $db->prepare("UPDATE iti_programs SET is_published=1, public_token=COALESCE(public_token,UUID()), published_at=NOW(), status='sent' WHERE id=?")->execute([$id]);
        iti_flash_set('success','Program published. Public link is now active.');
        iti_redirect("program_edit.php?id={$id}&tab=info");
    }
    if ($sub === 'unpublish') {
        $db->prepare("UPDATE iti_programs SET is_published=0 WHERE id=?")->execute([$id]);
        iti_flash_set('success','Program unpublished.');
        iti_redirect("program_edit.php?id={$id}&tab=info");
    }
    if ($sub === 'cancel_program') {
        $db->prepare("UPDATE iti_programs SET status='cancelled', is_published=0 WHERE id=?")->execute([$id]);
        iti_flash_set('success','Program cancelled.');
        iti_redirect("programs.php?type={$program['program_type']}");
    }
    if ($sub === 'hard_delete_program') {
        $chk = $db->prepare("SELECT status FROM iti_programs WHERE id=?");
        $chk->execute([$id]);
        $row = $chk->fetch();
        if ($row && $row['status'] === 'cancelled') {
            $db->prepare("DELETE FROM iti_programs WHERE id=?")->execute([$id]);
            iti_flash_set('success','Program permanently deleted.');
            iti_redirect("programs.php?type={$program['program_type']}");
        } else {
            iti_flash_set('error','Only cancelled programs can be permanently deleted.');
            iti_redirect("program_edit.php?id={$id}&tab=info");
        }
    }
}

// ── Dati per la pagina ───────────────────────────────────────
$program  = iti_get_program($id); // ricarica dopo eventuali update
$days     = iti_get_program_days($id);
$prices   = iti_get_program_prices($id);
$inclusions = iti_get_program_inclusions($id);
$terms    = iti_get_terms();

// Quale tab / giorno
$active_tab = $_GET['tab']  ?? 'days';
$active_day = (int)($_GET['day'] ?? ($days[0]['id'] ?? 0));

// Dati per dropdown
$lodges_grouped  = iti_lodges_grouped();
$destinations_list = iti_get_destinations();
$transfer_map    = iti_transfer_routes_map();
$flight_map      = iti_flight_routes_map();
$activities_list = iti_get_activities(true);

// Attività e voli del giorno corrente
$current_day_data  = null;
$current_acts      = [];
$current_flights   = [];
foreach ($days as $d) {
    if ((int)$d['id'] === $active_day) {
        $current_day_data = $d;
        $current_acts    = iti_get_day_activities((int)$d['id']);
        $current_flights = iti_get_day_flights((int)$d['id']);
        break;
    }
}

$public_url = BASE_URL . '/modules/iti/itinerary.php?token=' . ($program['public_token'] ?? '');

$page_title = 'Edit: ' . $program['title_en'] . ' — ITI Builder';
$extra_css = iti_extra_css();
include __DIR__ . '/../../includes/layout_header.php';
?>

<style>
.day-nav { display:flex; gap:4px; flex-wrap:wrap; margin-bottom:20px; }
.day-btn { padding:6px 14px; border-radius:6px; font-size:.78rem; font-weight:700;
           text-decoration:none; border:1.5px solid var(--grey-lt); background:var(--white); color:var(--grey-dk);
           transition:all .15s; }
.day-btn:hover  { border-color:var(--grey-mid); }
.day-btn.active { background:var(--red); border-color:var(--red); color:var(--white); }
.day-btn.filled { border-color:var(--green); color:var(--green); }
.day-btn.active.filled { background:var(--green); border-color:var(--green); color:var(--white); }
.iti-tabs { display:flex; gap:0; border-bottom:2px solid var(--grey-lt); margin-bottom:24px; }
.iti-tab  { padding:10px 20px; font-size:.8rem; font-weight:700; text-decoration:none; color:var(--grey-mid);
            border-bottom:2px solid transparent; margin-bottom:-2px; transition:color .15s; }
.iti-tab.active { color:var(--red); border-bottom-color:var(--red); }
.meal-check { display:inline-flex; align-items:center; gap:6px; font-size:.82rem; font-weight:600; cursor:pointer; }
.act-row { display:flex; align-items:center; gap:10px; padding:10px 14px;
           border-radius:8px; background:var(--off-white); margin-bottom:8px; }
.act-row .act-info { flex:1; }
.lang-tabs { display:flex; gap:0; border-bottom:1px solid var(--grey-lt); margin-bottom:12px; }
.lang-tab  { padding:6px 14px; font-size:.75rem; font-weight:700; cursor:pointer;
             border-bottom:2px solid transparent; margin-bottom:-1px; color:var(--grey-mid); }
.lang-tab.active { color:var(--red); border-bottom-color:var(--red); }
.lang-panel { display:none; }
.lang-panel.active { display:block; }
.iti-combo { position:relative; max-width:480px; }
.iti-combo-inner { display:flex; border:1.5px solid var(--grey-lt); border-radius:6px; background:#fff; overflow:hidden; }
.iti-combo-inner:focus-within { border-color:var(--red); }
.iti-combo-text { flex:1; border:none; outline:none; padding:8px 10px; font-size:.85rem; background:transparent; color:var(--black,#1a1a1a); font-family:inherit; }
.iti-combo-text::placeholder { color:var(--grey-mid); }
.iti-combo-arrow { border:none; background:var(--off-white,#f7f6f3); padding:0 10px; cursor:pointer; font-size:.75rem; color:var(--grey-mid); border-left:1px solid var(--grey-lt); }
.iti-combo-arrow:hover { background:var(--grey-lt); }
.iti-combo-drop { display:none; position:absolute; left:0; right:0; top:calc(100% + 2px); background:#fff; border:1.5px solid var(--grey-lt); border-radius:6px; z-index:200; max-height:220px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,.08); }
.iti-combo-drop.open { display:block; }
.iti-combo-group { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--grey-mid); padding:7px 12px 3px; }
.iti-combo-opt { padding:7px 14px; font-size:.83rem; cursor:pointer; color:var(--grey-dk,#333); }
.iti-combo-opt:hover, .iti-combo-opt.focused { background:var(--red-lt,#fdf0f0); color:var(--red); }
.iti-combo-opt.custom-hint { color:var(--amber,#b87c00); font-style:italic; }
</style>

<main>
<?php iti_nav('Edit Program', [
    ['label' => ($program['program_type']==='sample'?'Samples':'Personal'), 'url' => ITI_MODULE_URL.'/programs.php?type='.$program['program_type']],
]); ?>
<?php iti_flash_render(); ?>

<!-- Page header -->
<div class="page-header" style="margin-bottom:16px;">
  <div>
    <h2><?= h($program['title_en']) ?></h2>
    <div class="sub">
      <?= $program['program_type']==='sample'?'Sample':'Personal' ?>
      &nbsp;·&nbsp; <?= iti_duration_label((int)$program['duration_days']) ?>
      &nbsp;·&nbsp; <?= $program['pax_adults'] ?>A<?= $program['pax_children']?'+'.$program['pax_children'].'C':'' ?>
      &nbsp;·&nbsp; <span class="badge <?= ITI_PROGRAM_STATUS_BADGE[$program['status']] ?? '' ?>"><?= h($program['status']) ?></span>
      <?php if (!empty($program['ref_number'])): ?>
      &nbsp;·&nbsp; <span style="font-family:monospace;font-size:.8rem;font-weight:700;color:var(--grey-dk);"><?= h($program['ref_number']) ?></span>
      <?php endif; ?>
      <?php if ($program['flights_included']): ?>&nbsp;·&nbsp; ✈️ Flights included<?php else: ?>&nbsp;·&nbsp; <span style="color:var(--amber);">✈️ Flights extra</span><?php endif; ?>
    </div>
  </div>
  <div class="gap-8">
    <a href="program_view.php?id=<?= $id ?>" class="btn btn-outline btn-sm" target="_blank">👁 Preview</a>
    <?php if ($program['is_published']): ?>
    <a href="<?= h($public_url) ?>" target="_blank" class="btn btn-green btn-sm">🔗 Public Link</a>
    <form method="POST" action="program_edit.php?id=<?= $id ?>" style="display:inline;">
      <input type="hidden" name="_sub" value="unpublish">
      <button class="btn btn-outline btn-sm">Unpublish</button>
    </form>
    <?php else: ?>
    <form method="POST" action="program_edit.php?id=<?= $id ?>" style="display:inline;">
      <input type="hidden" name="_sub" value="publish">
      <button class="btn btn-red btn-sm">🚀 Publish</button>
    </form>
    <?php endif; ?>
    <a href="export_word.php?id=<?= $id ?>" class="btn btn-outline btn-sm">⬇ Export .docx</a>
    <?php if ($program['status'] !== 'cancelled'): ?>
    <form method="POST" action="program_edit.php?id=<?= $id ?>" style="display:inline;"
          onsubmit="return confirm('Cancel this program?')">
      <input type="hidden" name="_sub" value="cancel_program">
      <button class="btn btn-danger btn-sm" style="color:#fff;">🗑 Cancel</button>
    </form>
    <?php else: ?>
    <form method="POST" action="program_edit.php?id=<?= $id ?>" style="display:inline;"
          onsubmit="return confirm('PERMANENTLY delete this program? This cannot be undone.')">
      <input type="hidden" name="_sub" value="hard_delete_program">
      <button class="btn btn-danger btn-sm" style="background:#7b1010;border-color:#7b1010;color:#fff;">🗑 Delete permanently</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- Main tabs -->
<nav class="iti-tabs">
  <a class="iti-tab <?= $active_tab==='days'?'active':'' ?>"       href="program_edit.php?id=<?= $id ?>&tab=days">📅 Days</a>
  <a class="iti-tab <?= $active_tab==='prices'?'active':'' ?>"     href="program_edit.php?id=<?= $id ?>&tab=prices">💰 Prices</a>
  <a class="iti-tab <?= $active_tab==='inclusions'?'active':'' ?>" href="program_edit.php?id=<?= $id ?>&tab=inclusions">✅ Inclusions</a>
  <a class="iti-tab <?= $active_tab==='info'?'active':'' ?>"       href="program_edit.php?id=<?= $id ?>&tab=info">⚙️ Settings</a>
</nav>

<?php if ($active_tab === 'days'): ?>
<!-- ═══════════ TAB DAYS ═══════════ -->
<div style="display:grid;grid-template-columns:220px 1fr;gap:24px;align-items:start;">

  <!-- Day navigator -->
  <div>
    <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);margin-bottom:6px;">
      Days
    </div>
    <?php
      $start_date = !empty($program['start_date']) ? new DateTime($program['start_date']) : null;
      $total_days = count($days);
    ?>
    <?php if ($start_date): ?>
    <div style="font-size:.72rem;color:var(--grey-mid);margin-bottom:10px;padding:6px 10px;background:var(--off-white);border-radius:6px;">
      📅 <?= $start_date->format('d M Y') ?><br>
      <?php $end = clone $start_date; $end->modify('+'.($total_days-1).' days'); ?>
      → <?= $end->format('d M Y') ?><br>
      <strong><?= $total_days ?> days / <?= max(0,$total_days-1) ?> nights</strong>
    </div>
    <?php else: ?>
    <div style="font-size:.72rem;color:var(--amber);margin-bottom:10px;">
      ⚠ Set start date in Settings
    </div>
    <?php endif; ?>
    <?php foreach ($days as $d): ?>
    <?php $has_lodge = !empty($d['start_lodge_id']) || !empty($d['end_lodge_id']); ?>
    <div style="display:flex;gap:3px;align-items:stretch;margin-bottom:6px;">
      <a href="program_edit.php?id=<?= $id ?>&tab=days&day=<?= $d['id'] ?>"
         class="day-btn<?= $active_day===(int)$d['id']?' active':'' ?><?= $has_lodge?' filled':'' ?>"
         style="flex:1;display:block;text-align:center;">
        Day <?= $d['day_number'] ?>
        <?php if ($d['day_title_en']): ?><div style="font-size:.68rem;font-weight:400;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= h(mb_substr($d['day_title_en'],0,20)) ?></div><?php endif; ?>
      </a>
      <div style="display:flex;flex-direction:column;gap:2px;">
        <?php if ($d['day_number'] > 1): ?>
        <form method="POST" action="program_edit.php?id=<?= $id ?>&tab=days" style="margin:0;">
          <input type="hidden" name="_sub" value="move_day">
          <input type="hidden" name="day_id" value="<?= $d['id'] ?>">
          <input type="hidden" name="direction" value="up">
          <button title="Move up" style="padding:2px 5px;font-size:.65rem;border:1px solid var(--grey-lt);border-radius:4px;background:var(--white);cursor:pointer;">▲</button>
        </form>
        <?php endif; ?>
        <?php if ($d['day_number'] < $total_days): ?>
        <form method="POST" action="program_edit.php?id=<?= $id ?>&tab=days" style="margin:0;">
          <input type="hidden" name="_sub" value="move_day">
          <input type="hidden" name="day_id" value="<?= $d['id'] ?>">
          <input type="hidden" name="direction" value="down">
          <button title="Move down" style="padding:2px 5px;font-size:.65rem;border:1px solid var(--grey-lt);border-radius:4px;background:var(--white);cursor:pointer;">▼</button>
        </form>
        <?php endif; ?>
        <form method="POST" action="program_edit.php?id=<?= $id ?>&tab=days" style="margin:0;"
              onsubmit="return confirm('Delete Day <?= $d['day_number'] ?>?')">
          <input type="hidden" name="_sub" value="delete_day">
          <input type="hidden" name="day_id" value="<?= $d['id'] ?>">
          <input type="hidden" name="day_num" value="<?= $d['day_number'] ?>">
          <button title="Delete day" style="padding:2px 5px;font-size:.65rem;border:1px solid var(--red-lt);border-radius:4px;background:var(--red-lt);color:var(--red-dk);cursor:pointer;">✕</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
    <form method="POST" action="program_edit.php?id=<?= $id ?>&tab=days" style="margin-top:10px;">
      <input type="hidden" name="_sub" value="add_day">
      <button class="btn btn-red" style="width:100%;font-size:.75rem;">+ Add Day</button>
    </form>
  </div>

  <!-- Day editor -->
  <div>
  <?php if ($current_day_data): ?>
  <form method="POST" action="program_edit.php?id=<?= $id ?>&tab=days&day=<?= $active_day ?>">
    <input type="hidden" name="_sub"    value="day">
    <input type="hidden" name="day_id"  value="<?= $active_day ?>">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
      <div style="font-family:'Merriweather',serif;font-size:1rem;font-weight:700;">Day <?= $current_day_data['day_number'] ?></div>
      <button type="submit" class="btn btn-red btn-sm">💾 Save Day</button>
    </div>

    <!-- ── BLOCCHI SEQUENZIALI DEL GIORNO ── -->

    <?php
    // Prev day lodge per "inherited" starting point
    $prev_lodge_name = null;
    foreach ($days as $_d) {
        if ((int)$_d['day_number'] === (int)$current_day_data['day_number'] - 1) {
            $prev_lodge_name = !empty($_d['end_lodge_name']) ? $_d['end_lodge_name']
                             : (!empty($_d['start_lodge_name']) ? $_d['start_lodge_name'] : null);
            break;
        }
    }
    $start_is_inherited = empty($current_day_data['start_lodge_id']) && $prev_lodge_name;
    ?>

    <div class="form-card" style="margin-bottom:16px;padding:0;overflow:hidden;">

      <!-- 1. STARTING POINT -->
      <div style="padding:14px 20px;border-bottom:1px solid var(--grey-lt);">
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);margin-bottom:8px;">📍 Starting point</div>
        <?php
        // Determine current display value for starting point
        $start_display = '';
        if (!empty($current_day_data['start_lodge_id'])) {
            foreach ($lodges_grouped as $_d => $_ls)
                foreach ($_ls as $_l)
                    if ((int)$_l['id'] === (int)$current_day_data['start_lodge_id'])
                        $start_display = $_l['name'];
        } elseif (!empty($current_day_data['start_custom'])) {
            $start_display = $current_day_data['start_custom'];
        } elseif ($prev_lodge_name) {
            $start_display = '↖ Inherited — ' . $prev_lodge_name;
        }
        // Build options JSON for JS
        $start_opts = [];
        if ($prev_lodge_name) $start_opts[] = ['id'=>'', 'label'=>'↖ Inherited — '.$prev_lodge_name, 'group'=>'Inherited'];
        foreach ($lodges_grouped as $_dname => $_ls)
            foreach ($_ls as $_l)
                $start_opts[] = ['id'=>(string)$_l['id'], 'label'=>$_l['name'], 'group'=>$_dname];
        ?>
        <div class="iti-combo" data-field="start_lodge">
          <div class="iti-combo-inner">
            <input type="text" class="iti-combo-text" autocomplete="off"
                   placeholder="Type or choose from list…"
                   value="<?= h($start_display) ?>">
            <button type="button" class="iti-combo-arrow" tabindex="-1">▾</button>
          </div>
          <input type="hidden" name="start_lodge_id"     value="<?= h($current_day_data['start_lodge_id'] ?? '') ?>">
          <input type="hidden" name="start_lodge_custom" value="<?= h($current_day_data['start_custom'] ?? '') ?>">
          <div class="iti-combo-drop" data-opts='<?= json_encode($start_opts, JSON_HEX_APOS) ?>'></div>
        </div>
      </div>

      <!-- 2. ROAD TRANSFER (optional) -->
      <div style="background:var(--off-white,#f7f6f3);border-bottom:1px dashed var(--grey-lt);padding:12px 20px;">
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);margin-bottom:6px;">🚗 Road transfer <span style="font-weight:400;">(optional)</span></div>
        <?php
        $tr_display = '';
        $sel_tr = (string)($current_day_data['transfer_route_id'] ?? '');
        if ($sel_tr !== '') {
            foreach ($transfer_map as $_tid => $_tr)
                if ((string)$_tid === $sel_tr)
                    $tr_display = ($_tr['from_name'] ?? '') . ' → ' . ($_tr['to_name'] ?? '') . ' (' . ($_tr['duration_min'] ?? 0) . ' min)';
        } elseif (!empty($current_day_data['transfer_custom'])) {
            $tr_display = $current_day_data['transfer_custom'];
        }
        $tr_opts = [['id'=>'', 'label'=>'— No road transfer —', 'group'=>'']];
        foreach ($transfer_map as $_tid => $_tr)
            $tr_opts[] = ['id'=>(string)$_tid, 'label'=>($_tr['from_name']??'') . ' → ' . ($_tr['to_name']??'') . ' (' . ($_tr['duration_min']??0) . ' min)', 'group'=>'Routes'];
        ?>
        <div class="iti-combo" data-field="transfer">
          <div class="iti-combo-inner">
            <input type="text" class="iti-combo-text" autocomplete="off"
                   placeholder="Type or choose from list…"
                   value="<?= h($tr_display) ?>">
            <button type="button" class="iti-combo-arrow" tabindex="-1">▾</button>
          </div>
          <input type="hidden" name="transfer_route_id" value="<?= h($current_day_data['transfer_route_id'] ?? '') ?>">
          <input type="hidden" name="transfer_custom"   value="<?= h($current_day_data['transfer_custom'] ?? '') ?>">
          <div class="iti-combo-drop" data-opts='<?= json_encode($tr_opts, JSON_HEX_APOS) ?>'></div>
        </div>
      </div>

      <!-- 3. MAIN DESTINATION -->
      <div style="padding:14px 20px;border-bottom:1px solid var(--grey-lt);">
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);margin-bottom:8px;">🗺️ Main destination</div>
        <?php
        $dest_display = '';
        if (!empty($current_day_data['destination_id']))
            foreach ($destinations_list as $_d)
                if ((int)$_d['id'] === (int)$current_day_data['destination_id'])
                    $dest_display = $_d['name_en'];
        if (!$dest_display && !empty($current_day_data['destination_custom']))
            $dest_display = $current_day_data['destination_custom'];
        $dest_opts = [];
        foreach ($destinations_list as $_d)
            $dest_opts[] = ['id'=>(string)$_d['id'], 'label'=>$_d['name_en'], 'group'=>$_d['region'] ?? ''];
        ?>
        <div class="iti-combo" data-field="destination">
          <div class="iti-combo-inner">
            <input type="text" class="iti-combo-text" autocomplete="off"
                   placeholder="Type or choose from list…"
                   value="<?= h($dest_display) ?>">
            <button type="button" class="iti-combo-arrow" tabindex="-1">▾</button>
          </div>
          <input type="hidden" name="destination_id"     value="<?= h($current_day_data['destination_id'] ?? '') ?>">
          <input type="hidden" name="destination_custom" value="<?= h($current_day_data['destination_custom'] ?? '') ?>">
          <div class="iti-combo-drop" data-opts='<?= json_encode($dest_opts, JSON_HEX_APOS) ?>'></div>
        </div>
      </div>

      <!-- 4. DAY TITLE & DESCRIPTION -->
      <div style="padding:14px 20px;border-bottom:1px solid var(--grey-lt);">
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);margin-bottom:10px;">📝 Day title &amp; description</div>
        <div class="lang-tabs" id="narr-tabs">
          <?php foreach (ITI_LANGS as $i => $lang): ?>
          <div class="lang-tab <?= $i===0?'active':'' ?>" onclick="switchLang('narr','<?= $lang ?>')"><?= strtoupper($lang) ?></div>
          <?php endforeach; ?>
        </div>
        <?php foreach (ITI_LANGS as $i => $lang): ?>
        <div class="lang-panel <?= $i===0?'active':'' ?>" id="narr-<?= $lang ?>">
          <div class="form-group" style="margin-bottom:10px;">
            <label style="font-size:.75rem;"><?= ITI_LANG_LABELS[$lang] ?> — Title</label>
            <input type="text" name="day_title_<?= $lang ?>" maxlength="200"
                   placeholder="e.g. Arrival in Arusha…"
                   value="<?= h($current_day_data["day_title_{$lang}"] ?? '') ?>">
          </div>
          <div class="form-group">
            <label style="font-size:.75rem;"><?= ITI_LANG_LABELS[$lang] ?> — Narrative</label>
            <textarea name="narrative_<?= $lang ?>" class="tall" style="min-height:120px;"><?= h($current_day_data["narrative_{$lang}"] ?? '') ?></textarea>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- 5. ACCOMMODATION / OVERNIGHT -->
      <div style="padding:14px 20px;border-bottom:1px solid var(--grey-lt);">
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);margin-bottom:8px;">🏕️ Accommodation / overnight</div>
        <?php
        $acc_display = '';
        if (!empty($current_day_data['end_lodge_id']))
            foreach ($lodges_grouped as $_d => $_ls)
                foreach ($_ls as $_l)
                    if ((int)$_l['id'] === (int)$current_day_data['end_lodge_id'])
                        $acc_display = $_l['name'];
        if (!$acc_display && !empty($current_day_data['end_lodge_custom']))
            $acc_display = $current_day_data['end_lodge_custom'];
        $acc_opts = [['id'=>'', 'label'=>'— No overnight —', 'group'=>'']];
        foreach ($lodges_grouped as $_dname => $_ls)
            foreach ($_ls as $_l)
                $acc_opts[] = ['id'=>(string)$_l['id'], 'label'=>$_l['name'], 'group'=>$_dname];
        ?>
        <div class="iti-combo" data-field="end_lodge">
          <div class="iti-combo-inner">
            <input type="text" class="iti-combo-text" autocomplete="off"
                   placeholder="Type or choose from list…"
                   value="<?= h($acc_display) ?>">
            <button type="button" class="iti-combo-arrow" tabindex="-1">▾</button>
          </div>
          <input type="hidden" name="end_lodge_id"     value="<?= h($current_day_data['end_lodge_id'] ?? '') ?>">
          <input type="hidden" name="end_lodge_custom" value="<?= h($current_day_data['end_lodge_custom'] ?? '') ?>">
          <div class="iti-combo-drop" data-opts='<?= json_encode($acc_opts, JSON_HEX_APOS) ?>'></div>
        </div>
      </div>

      <!-- 6. MEALS -->
      <div style="padding:12px 20px;background:var(--off-white,#f7f6f3);">
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);margin-bottom:8px;">🍽️ Meals included</div>
        <div style="display:flex;gap:20px;">
          <label class="meal-check"><input type="checkbox" name="meal_breakfast" value="1" <?= $current_day_data['meal_breakfast']?'checked':'' ?> style="accent-color:var(--red);"> Breakfast</label>
          <label class="meal-check"><input type="checkbox" name="meal_lunch"     value="1" <?= $current_day_data['meal_lunch']?'checked':'' ?>     style="accent-color:var(--red);"> Lunch</label>
          <label class="meal-check"><input type="checkbox" name="meal_dinner"    value="1" <?= $current_day_data['meal_dinner']?'checked':'' ?>    style="accent-color:var(--red);"> Dinner</label>
        </div>
      </div>

    </div><!-- end blocchi giorno -->

  </form>

  <!-- Activities -->
  <div class="form-card" style="margin-top:16px;padding:20px 24px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
      <div class="form-section-title" style="margin:0;">Activities</div>
    </div>

    <?php if ($current_acts): ?>
    <?php foreach ($current_acts as $a): ?>
    <div class="act-row">
      <div style="font-size:1.2rem;"><?= ITI_ACTIVITY_ICONS[$a['activity_type']] ?? '⭐' ?></div>
      <div class="act-info">
        <div style="font-weight:600;font-size:.85rem;"><?= h($a['name_en']) ?></div>
        <div style="font-size:.72rem;color:var(--grey-mid);"><?= ITI_ACTIVITY_TYPES[$a['activity_type']] ?? '' ?><?= $a['duration_hours'] ? ' · '.$a['duration_hours'].'h' : '' ?></div>
      </div>
      <form method="POST" action="program_edit.php?id=<?= $id ?>&tab=days&day=<?= $active_day ?>">
        <input type="hidden" name="_sub"    value="remove_activity">
        <input type="hidden" name="day_id"  value="<?= $active_day ?>">
        <input type="hidden" name="da_id"   value="<?= $a['id'] ?>">
        <button class="btn btn-danger btn-sm" onclick="return confirm('Remove this activity?')">✕</button>
      </form>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div style="color:var(--grey-mid);font-size:.83rem;margin-bottom:12px;">No activities yet.</div>
    <?php endif; ?>

    <form method="POST" action="program_edit.php?id=<?= $id ?>&tab=days&day=<?= $active_day ?>"
          style="display:flex;gap:8px;align-items:flex-end;margin-top:8px;">
      <input type="hidden" name="_sub"   value="add_activity">
      <input type="hidden" name="day_id" value="<?= $active_day ?>">
      <div style="flex:1;">
        <label style="font-size:.72rem;font-weight:700;color:var(--grey-dk);display:block;margin-bottom:4px;">Add Activity</label>
        <select name="activity_id" style="width:100%;padding:8px 11px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.82rem;">
          <option value="">— Select —</option>
          <?php foreach ($activities_list as $a): ?>
          <option value="<?= $a['id'] ?>"><?= ITI_ACTIVITY_ICONS[$a['activity_type']]??'⭐' ?> <?= h($a['name_en']) ?><?= $a['dest_name_en'] ? ' — '.$a['dest_name_en'] : ' (generic)' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-outline btn-sm">+ Add</button>
    </form>
  </div>

  <!-- Flights -->
  <div class="form-card" style="margin-top:16px;padding:20px 24px;">
    <div class="form-section-title" style="margin-top:0;">Flights
      <span style="font-weight:400;font-size:.75rem;color:var(--grey-mid);"><?= $program['flights_included']?'(included in price)':'(extra cost)' ?></span>
    </div>

    <?php if ($current_flights): ?>
    <?php foreach ($current_flights as $fl): ?>
    <div class="act-row">
      <div style="font-size:1.2rem;">✈️</div>
      <div class="act-info">
        <div style="font-weight:600;font-size:.85rem;"><?= h($fl['from_airport']) ?> → <?= h($fl['to_airport']) ?></div>
        <div style="font-size:.72rem;color:var(--grey-mid);">
          <?= h($fl['operator'] ?? '') ?>
          <?= $fl['departure_time'] ? ' · Dep '.h($fl['departure_time']) : '' ?>
          <?= $fl['arrival_time']   ? ' · Arr '.h($fl['arrival_time'])   : '' ?>
        </div>
      </div>
      <form method="POST" action="program_edit.php?id=<?= $id ?>&tab=days&day=<?= $active_day ?>">
        <input type="hidden" name="_sub"    value="remove_flight">
        <input type="hidden" name="day_id"  value="<?= $active_day ?>">
        <input type="hidden" name="df_id"   value="<?= $fl['id'] ?>">
        <button class="btn btn-danger btn-sm" onclick="return confirm('Remove this flight?')">✕</button>
      </form>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <form method="POST" action="program_edit.php?id=<?= $id ?>&tab=days&day=<?= $active_day ?>"
          style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:8px;">
      <input type="hidden" name="_sub"   value="add_flight">
      <input type="hidden" name="day_id" value="<?= $active_day ?>">
      <div style="flex:2;min-width:180px;">
        <label style="font-size:.72rem;font-weight:700;color:var(--grey-dk);display:block;margin-bottom:4px;">Flight Route</label>
        <select name="flight_route_id" style="width:100%;padding:8px 11px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.82rem;">
          <option value="">— Select —</option>
          <?= iti_options($flight_map) ?>
        </select>
      </div>
      <div>
        <label style="font-size:.72rem;font-weight:700;color:var(--grey-dk);display:block;margin-bottom:4px;">Dep.</label>
        <input type="time" name="departure_time" style="padding:8px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.82rem;">
      </div>
      <div>
        <label style="font-size:.72rem;font-weight:700;color:var(--grey-dk);display:block;margin-bottom:4px;">Arr.</label>
        <input type="time" name="arrival_time" style="padding:8px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.82rem;">
      </div>
      <button type="submit" class="btn btn-outline btn-sm">+ Add</button>
    </form>
  </div>

  <?php else: ?>
  <div class="empty-state"><div class="icon">📅</div><p>Select a day to edit.</p></div>
  <?php endif; ?>
  </div>
</div><!-- end grid -->

<?php elseif ($active_tab === 'prices'): ?>
<!-- ═══════════ TAB PRICES ═══════════ -->
<form method="POST" action="program_edit.php?id=<?= $id ?>&tab=prices">
<input type="hidden" name="_sub" value="prices">
<div class="form-card">
  <div class="form-section-title" style="margin-top:0;">Prices per Person</div>
  <div style="overflow-x:auto;">
  <table style="width:100%;border-collapse:collapse;">
    <thead>
      <tr style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);">
        <th style="padding:10px 12px;text-align:left;"></th>
        <th style="padding:10px 12px;text-align:right;">Price/Pax USD</th>
        <th style="padding:10px 12px;text-align:right;">Price/Pax EUR</th>
        <th style="padding:10px 12px;text-align:right;">Single Suppl USD</th>
        <th style="padding:10px 12px;text-align:right;">Single Suppl EUR</th>
        <th style="padding:10px 12px;text-align:right;">Child USD</th>
        <th style="padding:10px 12px;text-align:right;">Child EUR</th>
        <th style="padding:10px 12px;text-align:center;">Include</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach (ITI_PRICE_CATEGORIES as $cat => $cat_label): ?>
    <?php $p = $prices[$cat] ?? []; ?>
    <tr style="border-top:1px solid var(--grey-lt);">
      <td style="padding:12px;font-weight:700;font-size:.85rem;"><?= h($cat_label) ?></td>
      <?php foreach (['pp_usd','pp_eur','ss_usd','ss_eur','ch_usd','ch_eur'] as $col): ?>
      <?php
        $field_map = ['pp_usd'=>'price_per_pax_usd','pp_eur'=>'price_per_pax_eur','ss_usd'=>'single_suppl_usd','ss_eur'=>'single_suppl_eur','ch_usd'=>'child_price_usd','ch_eur'=>'child_price_eur'];
        $db_col = $field_map[$col];
      ?>
      <td style="padding:8px 12px;">
        <input type="number" name="<?= $col ?>_<?= $cat ?>" step="0.01" min="0"
               style="width:100px;padding:6px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.82rem;text-align:right;"
               value="<?= $p[$db_col] ?? '' ?>">
      </td>
      <?php endforeach; ?>
      <td style="padding:8px 12px;text-align:center;">
        <input type="checkbox" name="price_<?= $cat ?>" value="1"
               <?= !empty($p)?'checked':'' ?>
               style="width:16px;height:16px;accent-color:var(--red);">
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div class="form-actions">
    <button type="submit" class="btn btn-red">💾 Save Prices</button>
  </div>
</div>
</form>

<?php elseif ($active_tab === 'inclusions'): ?>
<!-- ═══════════ TAB INCLUSIONS ═══════════ -->
<div class="form-card">
<form method="POST" action="program_edit.php?id=<?= $id ?>&tab=inclusions">
<input type="hidden" name="_sub" value="inclusions">

<?php foreach (['inclusion' => '✅ Included','exclusion' => '❌ Excluded'] as $type => $label): ?>
<div class="form-section-title" style="<?= $type==='exclusion'?'margin-top:28px;':'' ?>"><?= $label ?></div>
<?php $items = array_filter($inclusions, fn($i) => $i['item_type'] === $type); ?>
<div id="inc-list-<?= $type ?>">
<?php foreach (array_values($items) as $idx => $inc): ?>
<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;" class="inc-row">
  <input type="hidden" name="inc_type[]"  value="<?= $type ?>">
  <input type="hidden" name="inc_std[]"   value="<?= $inc['standard_inclusion_id'] ?? '' ?>">
  <input type="text"   name="inc_en[]"    value="<?= h($inc['resolved_en'] ?? '') ?>"
         style="flex:1;padding:8px 11px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.83rem;"
         placeholder="English text">
  <input type="text"   name="inc_it[]"    value="<?= h($inc['resolved_it'] ?? '') ?>"
         style="width:180px;padding:8px 11px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.83rem;"
         placeholder="Italiano">
  <button type="button" onclick="this.closest('.inc-row').remove()" class="btn btn-danger btn-sm">✕</button>
</div>
<?php endforeach; ?>
</div>
<button type="button" class="btn btn-outline btn-sm" style="margin-top:4px;"
        onclick="addIncRow('<?= $type ?>')">+ Add <?= $type === 'inclusion' ? 'inclusion' : 'exclusion' ?></button>
<?php endforeach; ?>

<div class="form-actions">
  <button type="submit" class="btn btn-red">💾 Save Inclusions</button>
</div>
</form>
</div>

<?php elseif ($active_tab === 'info'): ?>
<!-- ═══════════ TAB INFO / SETTINGS ═══════════ -->
<div class="form-card">
<form method="POST" action="program_edit.php?id=<?= $id ?>&tab=info">
<input type="hidden" name="_sub" value="header">

  <div class="form-section-title" style="margin-top:0;">Program Settings</div>
  <div class="form-grid">
    <div class="form-group">
      <label>Ref. Number</label>
      <input type="text" name="ref_number" maxlength="60"
             placeholder="e.g. SE-2025-001"
             value="<?= h($program['ref_number'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Start Date</label>
      <input type="date" name="start_date" value="<?= h($program['start_date'] ?? '') ?>">
      <span class="form-hint">End date and duration calculated automatically</span>
    </div>
    <div class="form-group">
      <label>Adults</label>
      <input type="number" name="pax_adults" min="1" value="<?= (int)$program['pax_adults'] ?>">
    </div>
    <div class="form-group">
      <label>Children</label>
      <input type="number" name="pax_children" min="0" value="<?= (int)$program['pax_children'] ?>">
    </div>
    <div class="form-group">
      <label>Status</label>
      <select name="status"><?= iti_options(ITI_PROGRAM_STATUSES, $program['status']) ?></select>
    </div>
    <div class="form-group">
      <label>Display Language</label>
      <select name="display_language"><?= iti_options(ITI_LANG_LABELS, $program['display_language']) ?></select>
    </div>
    <div class="form-group">
      <label>Display Currency</label>
      <select name="display_currency">
        <option value="USD" <?= $program['display_currency']==='USD'?'selected':'' ?>>USD — $</option>
        <option value="EUR" <?= $program['display_currency']==='EUR'?'selected':'' ?>>EUR — €</option>
      </select>
    </div>
    <div class="form-group">
      <label>Terms &amp; Conditions</label>
      <select name="terms_id">
        <option value="">— None —</option>
        <?php foreach ($terms as $t): ?>
        <option value="<?= $t['id'] ?>" <?= (int)($program['terms_id']??0)===$t['id']?'selected':'' ?>><?= h($t['version']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex-direction:row;align-items:center;gap:10px;align-self:flex-end;">
      <input type="checkbox" name="flights_included" value="1" id="fi2"
             <?= $program['flights_included']?'checked':'' ?>
             style="width:16px;height:16px;accent-color:var(--red);">
      <label for="fi2" style="margin:0;text-transform:none;font-size:.85rem;">Flights included in price</label>
    </div>
  </div>

  <div class="form-section-title">Title × 5 languages</div>
  <div class="form-grid">
    <?php foreach (ITI_LANGS as $lang): ?>
    <div class="form-group">
      <label><?= ITI_LANG_LABELS[$lang] ?></label>
      <input type="text" name="title_<?= $lang ?>" maxlength="200" value="<?= h($program["title_{$lang}"] ?? '') ?>">
    </div>
    <?php endforeach; ?>
  </div>

  <div class="form-section-title">Subtitle × 5 languages</div>
  <div class="form-grid">
    <?php foreach (ITI_LANGS as $lang): ?>
    <div class="form-group">
      <label><?= ITI_LANG_LABELS[$lang] ?></label>
      <input type="text" name="subtitle_<?= $lang ?>" maxlength="255" value="<?= h($program["subtitle_{$lang}"] ?? '') ?>">
    </div>
    <?php endforeach; ?>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-red">💾 Save Settings</button>
    <?php if ($program['is_published']): ?>
    <div style="margin-left:auto;display:flex;align-items:center;gap:8px;">
      <span style="font-size:.78rem;color:var(--green);font-weight:700;">🟢 Published</span>
      <a href="<?= h($public_url) ?>" target="_blank" class="btn btn-green btn-sm">Open public link</a>
    </div>
    <?php endif; ?>
  </div>
</form>
</div>

<?php endif; ?>
</main>

<script>
function switchLang(prefix, lang) {
  document.querySelectorAll(`[id^="${prefix}-"]`).forEach(el => el.classList.remove('active'));
  document.querySelectorAll(`#${prefix}-tabs .lang-tab`).forEach(el => el.classList.remove('active'));
  document.getElementById(`${prefix}-${lang}`).classList.add('active');
  event.target.classList.add('active');
}
function addIncRow(type) {
  const list = document.getElementById('inc-list-' + type);
  const div  = document.createElement('div');
  div.className = 'inc-row';
  div.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:8px;';
  div.innerHTML = `<input type="hidden" name="inc_type[]" value="${type}">
    <input type="hidden" name="inc_std[]" value="">
    <input type="text" name="inc_en[]" style="flex:1;padding:8px 11px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.83rem;" placeholder="English text">
    <input type="text" name="inc_it[]" style="width:180px;padding:8px 11px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.83rem;" placeholder="Italiano">
    <button type="button" onclick="this.closest('.inc-row').remove()" class="btn btn-danger btn-sm">✕</button>`;
  list.appendChild(div);
}

// ── ITI Combo field ──────────────────────────────────────────
document.querySelectorAll('.iti-combo').forEach(function(combo) {
    var input  = combo.querySelector('.iti-combo-text');
    var drop   = combo.querySelector('.iti-combo-drop');
    var hidId  = combo.querySelector('input[name$="_id"]');
    var hidTxt = combo.querySelector('input[name$="_custom"]');
    var opts   = JSON.parse(drop.dataset.opts || '[]');

    function renderDrop(q) {
        drop.innerHTML = '';
        var q2 = q.toLowerCase();
        var lastGroup = null;
        var shown = 0;
        opts.forEach(function(o) {
            if (q2 && !o.label.toLowerCase().includes(q2)) return;
            if (o.group && o.group !== lastGroup) {
                var g = document.createElement('div');
                g.className = 'iti-combo-group';
                g.textContent = o.group;
                drop.appendChild(g);
                lastGroup = o.group;
            }
            var d = document.createElement('div');
            d.className = 'iti-combo-opt';
            d.textContent = o.label;
            d.dataset.id  = o.id;
            d.addEventListener('mousedown', function(e) {
                e.preventDefault();
                input.value  = o.label;
                hidId.value  = o.id;
                hidTxt.value = '';
                closeDrop();
            });
            drop.appendChild(d);
            shown++;
        });
        if (q2 && shown === 0) {
            var hint = document.createElement('div');
            hint.className = 'iti-combo-opt custom-hint';
            hint.textContent = '✎ Save as custom: "' + q + '"';
            hint.addEventListener('mousedown', function(e) {
                e.preventDefault();
                hidId.value  = '';
                hidTxt.value = q;
                closeDrop();
            });
            drop.appendChild(hint);
        }
    }

    function openDrop() { renderDrop(input.value); drop.classList.add('open'); }
    function closeDrop() { drop.classList.remove('open'); }

    input.addEventListener('focus', function() { openDrop(); });
    input.addEventListener('input', function() {
        hidId.value  = '';
        hidTxt.value = this.value;
        renderDrop(this.value);
        drop.classList.add('open');
    });
    input.addEventListener('blur', function() { setTimeout(closeDrop, 150); });
    combo.querySelector('.iti-combo-arrow').addEventListener('click', function() {
        if (drop.classList.contains('open')) { closeDrop(); } else { input.focus(); openDrop(); }
    });
});
</script>

<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
