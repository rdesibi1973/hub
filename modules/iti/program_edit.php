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
            $start_raw      = trim($_POST['start_lodge_id'] ?? '');
            $start_lodge_id = null; $start_dest_id = null; $start_txt = null;
            if (str_starts_with($start_raw, 'lodge_'))     $start_lodge_id = (int)substr($start_raw, 6) ?: null;
            elseif (str_starts_with($start_raw, 'dest_'))  $start_dest_id  = (int)substr($start_raw, 5) ?: null;
            else                                            $start_txt      = trim($_POST['start_lodge_custom'] ?? '') ?: null;

            $dest_id  = ($_POST['destination_id']  !== '' ? (int)$_POST['destination_id']  : null);
            $dest_txt = ($dest_id  === null ? trim($_POST['destination_custom']  ?? '') : null) ?: null;
            $end_raw  = trim($_POST['end_lodge_id'] ?? '');
            if ($end_raw === 'own') {
                $end_id  = null;
                $end_txt = 'Own Arrangement';
            } else {
                $end_id  = ($end_raw !== '' ? (int)$end_raw : null);
                $end_txt = ($end_id  === null ? trim($_POST['end_lodge_custom'] ?? '') : null) ?: null;
            }

            try {
                $own_arr        = isset($_POST['own_arrangement']) ? 1 : 0;
                $own_arr_nights = $own_arr ? max(1, (int)($_POST['own_arrangement_nights'] ?? 1)) : 0;
                // When own_arrangement is on but no lodge selected, mark as Own Arrangement text
                if ($own_arr && $end_id === null && empty($end_txt)) {
                    $end_txt = 'Own Arrangement';
                }

                $db->prepare(
                    'UPDATE iti_program_days SET
                     day_title_en=?,day_title_it=?,day_title_fr=?,day_title_es=?,day_title_de=?,
                     start_lodge_id=?,start_destination_id=?,start_custom=?,
                     destination_id=?,destination_custom=?,
                     narrative_en=?,narrative_it=?,narrative_fr=?,narrative_es=?,narrative_de=?,
                     end_lodge_id=?,end_lodge_custom=?,
                     meal_breakfast=?,meal_lunch=?,meal_dinner=?,
                     meal_all_inclusive=?,meal_game_package=?
                     WHERE id=? AND program_id=?'
                )->execute([
                    trim($_POST['day_title_en']),trim($_POST['day_title_it']),
                    trim($_POST['day_title_fr']),trim($_POST['day_title_es']),trim($_POST['day_title_de']),
                    $start_lodge_id, $start_dest_id, $start_txt,
                    $dest_id, $dest_txt,
                    trim($_POST['narrative_en']),trim($_POST['narrative_it']),
                    trim($_POST['narrative_fr']),trim($_POST['narrative_es']),trim($_POST['narrative_de']),
                    $end_id, $end_txt,
                    isset($_POST['meal_breakfast'])?1:0,
                    isset($_POST['meal_lunch'])?1:0,
                    isset($_POST['meal_dinner'])?1:0,
                    isset($_POST['meal_all_inclusive'])?1:0,
                    isset($_POST['meal_game_package'])?1:0,
                    $day_id, $id,
                ]);

                // OA columns — separate update in case ALTER TABLE not yet run on live DB
                try {
                    $db->prepare(
                        'UPDATE iti_program_days SET own_arrangement=?,own_arrangement_nights=? WHERE id=?'
                    )->execute([$own_arr, $own_arr_nights, $day_id]);
                } catch (\PDOException $oa_e) { /* columns not yet in DB — skip silently */ }

                // ── Transfers: delete all then re-insert from array ──
                $db->prepare('DELETE FROM iti_day_transfers WHERE program_day_id=?')->execute([$day_id]);
                $tr_descs = $_POST['transfer_desc'] ?? [];
                foreach (array_values($tr_descs) as $i => $desc) {
                    $desc = trim($desc);
                    if ($desc === '') continue;
                    $db->prepare('INSERT INTO iti_day_transfers (program_day_id,description,sort_order) VALUES (?,?,?)')->execute([$day_id, $desc, $i+1]);
                }

                // ── Activities: delete all then re-insert from array ──
                $db->prepare('DELETE FROM iti_day_activities WHERE program_day_id=?')->execute([$day_id]);
                $act_ids     = $_POST['activity_id']     ?? [];
                $act_customs = $_POST['activity_custom'] ?? [];
                foreach (array_values($act_ids) as $i => $aid) {
                    $aid  = (int)$aid;
                    $cust = trim($act_customs[$i] ?? '');
                    if (!$aid && $cust === '') continue;
                    $db->prepare('INSERT INTO iti_day_activities (program_day_id,activity_id,activity_custom,sort_order) VALUES (?,?,?,?)')->execute([$day_id, $aid ?: null, $cust ?: null, $i+1]);
                }

                // ── Flights: delete all then re-insert from array ──
                $db->prepare('DELETE FROM iti_day_flights WHERE program_day_id=?')->execute([$day_id]);
                $fl_routes  = $_POST['flight_route_id']  ?? [];
                $fl_customs = $_POST['flight_custom']    ?? [];
                $fl_airline = $_POST['airline_company']  ?? [];
                $fl_dep_h   = $_POST['dep_h']            ?? [];
                $fl_dep_m   = $_POST['dep_m']            ?? [];
                $fl_arr_h   = $_POST['arr_h']            ?? [];
                $fl_arr_m   = $_POST['arr_m']            ?? [];
                foreach (array_values($fl_routes) as $i => $fid) {
                    $fid  = (int)$fid;
                    $cust = trim($fl_customs[$i] ?? '');
                    if (!$fid && $cust === '') continue;
                    $dh = $fl_dep_h[$i] ?? ''; $dm = $fl_dep_m[$i] ?? '';
                    $ah = $fl_arr_h[$i] ?? ''; $am = $fl_arr_m[$i] ?? '';
                    $dep = ($dh !== '' && $dm !== '') ? "{$dh}:{$dm}" : null;
                    $arr = ($ah !== '' && $am !== '') ? "{$ah}:{$am}" : null;
                    $db->prepare('INSERT INTO iti_day_flights (program_day_id,flight_route_id,flight_custom,airline_company,departure_time,arrival_time,sort_order) VALUES (?,?,?,?,?,?,?)')->execute([
                        $day_id, $fid ?: null, $cust ?: null,
                        trim($fl_airline[$i] ?? '') ?: null,
                        $dep, $arr, $i+1,
                    ]);
                }

                iti_flash_set('success', 'Day saved.');
            } catch (\PDOException $e) {
                iti_flash_set('error', 'Save failed: ' . $e->getMessage());
            }
        } else {
            iti_flash_set('error', 'Save failed: day_id missing.');
        }
        iti_redirect("program_edit.php?id={$id}&tab=days&day={$day_id}");
    }

    // ── Aggiungi attività ──
    if ($sub === 'add_activity') {
        $day_id         = (int)($_POST['day_id']          ?? 0);
        $activity_id    = ($_POST['activity_id'] !== '' ? (int)$_POST['activity_id'] : 0);
        $activity_custom = trim($_POST['activity_custom'] ?? '');
        if ($day_id && ($activity_id || $activity_custom !== '')) {
            $ms = $db->prepare('SELECT COALESCE(MAX(sort_order),0) FROM iti_day_activities WHERE program_day_id=?');
            $ms->execute([$day_id]); $max_sort = (int)$ms->fetchColumn();
            $db->prepare('INSERT INTO iti_day_activities (program_day_id,activity_id,activity_custom,sort_order) VALUES (?,?,?,?)')->execute([
                $day_id, $activity_id ?: 0, $activity_custom ?: null, $max_sort+1
            ]);
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

    // ── Aggiungi transfer ──
    if ($sub === 'add_transfer') {
        $day_id = (int)($_POST['day_id'] ?? 0);
        $desc   = trim($_POST['transfer_desc'] ?? '');
        if ($day_id && $desc !== '') {
            $ms = $db->prepare('SELECT COALESCE(MAX(sort_order),0) FROM iti_day_transfers WHERE program_day_id=?');
            $ms->execute([$day_id]);
            $db->prepare('INSERT INTO iti_day_transfers (program_day_id,description,sort_order) VALUES (?,?,?)')->execute([$day_id, $desc, (int)$ms->fetchColumn()+1]);
        }
        iti_redirect("program_edit.php?id={$id}&day={$day_id}");
    }

    // ── Rimuovi transfer ──
    if ($sub === 'remove_transfer') {
        $tr_id  = (int)($_POST['tr_id']  ?? 0);
        $day_id = (int)($_POST['day_id'] ?? 0);
        if ($tr_id) $db->prepare('DELETE FROM iti_day_transfers WHERE id=?')->execute([$tr_id]);
        iti_redirect("program_edit.php?id={$id}&day={$day_id}");
    }


    // ── Aggiungi giorno ──
    if ($sub === 'add_day') {
        try {
            $last_st = $db->prepare(
                'SELECT day_number, own_arrangement_nights FROM iti_program_days
                  WHERE program_id=? ORDER BY day_number DESC LIMIT 1'
            );
            $last_st->execute([$id]);
            $last_row = $last_st->fetch();
            if ($last_row) {
                $oa_extra = (int)($last_row['own_arrangement_nights'] ?? 0);
                $new_num  = (int)$last_row['day_number'] + max(1, $oa_extra);
            } else {
                $new_num = 1;
            }
        } catch (\PDOException $e) {
            // own_arrangement_nights column not yet in DB — fall back to MAX+1
            $max_st = $db->prepare('SELECT COALESCE(MAX(day_number),0) FROM iti_program_days WHERE program_id=?');
            $max_st->execute([$id]);
            $new_num = (int)$max_st->fetchColumn() + 1;
        }
        $db->prepare('INSERT INTO iti_program_days (program_id, day_number) VALUES (?,?)')->execute([$id, $new_num]);
        $db->prepare('UPDATE iti_programs SET duration_days=? WHERE id=?')->execute([$new_num, $id]);
        iti_flash_set('success', "Day {$new_num} added.");
        iti_redirect("program_edit.php?id={$id}&tab=days");
    }

    // ── Cancella giorno ──
    if ($sub === 'delete_day') {
        $day_id = (int)($_POST['day_id'] ?? 0);
        if ($day_id) {
            $db->prepare('DELETE FROM iti_program_days WHERE id=?')->execute([$day_id]);
            // Renumber: first set negatives (guaranteed no collision), then set final values
            $remaining = $db->prepare('SELECT id FROM iti_program_days WHERE program_id=? ORDER BY day_number');
            $remaining->execute([$id]);
            $rows = $remaining->fetchAll();
            foreach ($rows as $i => $r)
                $db->prepare('UPDATE iti_program_days SET day_number=? WHERE id=?')->execute([-(($i) + 1), $r['id']]);
            foreach ($rows as $i => $r)
                $db->prepare('UPDATE iti_program_days SET day_number=? WHERE id=?')->execute([$i + 1, $r['id']]);
            $new_count = count($rows);
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
            // Get all days ordered by day_number to find the positional neighbour
            $all_st = $db->prepare('SELECT id, day_number FROM iti_program_days WHERE program_id=? ORDER BY day_number');
            $all_st->execute([$id]);
            $all_rows = $all_st->fetchAll();
            $pos = null;
            foreach ($all_rows as $i => $r) {
                if ((int)$r['id'] === $day_id) { $pos = $i; break; }
            }
            if ($pos !== null) {
                $swap_pos = $direction === 'up' ? $pos - 1 : $pos + 1;
                if (isset($all_rows[$swap_pos])) {
                    $cur_num  = (int)$all_rows[$pos]['day_number'];
                    $swap_id  = (int)$all_rows[$swap_pos]['id'];
                    $swap_num = (int)$all_rows[$swap_pos]['day_number'];
                    // Use negative temp to avoid duplicate key during swap
                    $db->prepare('UPDATE iti_program_days SET day_number=-1 WHERE id=?')->execute([$day_id]);
                    $db->prepare('UPDATE iti_program_days SET day_number=? WHERE id=?')->execute([$cur_num, $swap_id]);
                    $db->prepare('UPDATE iti_program_days SET day_number=? WHERE id=?')->execute([$swap_num, $day_id]);
                }
            }
        }
        iti_redirect("program_edit.php?id={$id}&tab=days");
    }

    // ── Reorder giorni via drag & drop (AJAX, returns JSON) ──
    if ($sub === 'reorder_days') {
        header('Content-Type: application/json');
        $order = $_POST['order'] ?? []; // array of day ids in new order
        if ($order && is_array($order)) {
            // Get current day_numbers ordered by position
            $cur_st = $db->prepare('SELECT id, day_number, own_arrangement_nights FROM iti_program_days WHERE program_id=? ORDER BY day_number');
            $cur_st->execute([$id]);
            $cur_rows = $cur_st->fetchAll();
            // Build the sequence of day_numbers to assign (respecting OA gaps)
            $day_nums = [];
            $n = 1;
            foreach ($cur_rows as $r) {
                $day_nums[] = $n;
                $oa = (int)($r['own_arrangement_nights'] ?? 0);
                $n += max(1, $oa ?: 1);
            }
            // Map submitted id order to day_numbers using temp negatives then final
            foreach ($order as $pos => $rid) {
                $rid = (int)$rid;
                if (isset($day_nums[$pos]))
                    $db->prepare('UPDATE iti_program_days SET day_number=? WHERE id=? AND program_id=?')
                       ->execute([-($pos+1), $rid, $id]);
            }
            foreach ($order as $pos => $rid) {
                $rid = (int)$rid;
                if (isset($day_nums[$pos]))
                    $db->prepare('UPDATE iti_program_days SET day_number=? WHERE id=? AND program_id=?')
                       ->execute([$day_nums[$pos], $rid, $id]);
            }
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Aggiungi volo ──
    if ($sub === 'add_flight') {
        $day_id          = (int)($_POST['day_id']            ?? 0);
        $flight_route_id = ($_POST['flight_route_id'] !== '' ? (int)$_POST['flight_route_id'] : 0);
        $flight_custom   = trim($_POST['flight_custom'] ?? '');
        if ($day_id && ($flight_route_id || $flight_custom !== '')) {
            $ms = $db->prepare('SELECT COALESCE(MAX(sort_order),0) FROM iti_day_flights WHERE program_day_id=?');
            $ms->execute([$day_id]); $max_sort = (int)$ms->fetchColumn();
            $db->prepare('INSERT INTO iti_day_flights (program_day_id,flight_route_id,flight_custom,airline_company,departure_time,arrival_time,sort_order) VALUES (?,?,?,?,?,?,?)')->execute([
                $day_id, $flight_route_id ?: 0, $flight_custom ?: null,
                trim($_POST['airline_company'] ?? '') ?: null,
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
// Distinct airline operators from flight routes for combo suggestions
$airline_opts = [];
try {
    // Try iti_airlines table first (preferred)
    $ao = db()->query("SELECT name FROM iti_airlines WHERE is_active=1 ORDER BY type, name");
    foreach ($ao->fetchAll() as $_r) $airline_opts[] = ['id'=>$_r['name'], 'label'=>$_r['name'], 'group'=>'Airlines'];
} catch (Exception $e) {
    // Fallback: distinct operators from flight routes
    try {
        $ao = db()->query("SELECT DISTINCT operator FROM iti_flight_routes WHERE operator IS NOT NULL AND operator <> '' ORDER BY operator");
        foreach ($ao->fetchAll() as $_r) $airline_opts[] = ['id'=>'', 'label'=>$_r['operator'], 'group'=>'Known operators'];
    } catch (Exception $e2) {}
}
$activities_list = iti_get_activities();

// Attività, voli e transfer del giorno corrente
$current_day_data  = null;
$current_acts      = [];
$current_flights   = [];
$current_transfers = [];
foreach ($days as $d) {
    if ((int)$d['id'] === $active_day) {
        $current_day_data  = $d;
        $current_acts      = iti_get_day_activities((int)$d['id']);
        $current_flights   = iti_get_day_flights((int)$d['id']);
        $current_transfers = iti_get_day_transfers((int)$d['id']);
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
    <h2><?= h($program['title_en']) ?> <a href="program_edit.php?id=<?= $id ?>&tab=info" title="Edit title" style="font-size:.75rem;font-weight:400;color:var(--grey-mid);text-decoration:none;vertical-align:middle;">✏️</a></h2>
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
      // Calculate total nights — safe fallback if OA columns not yet in DB
      $total_nights = 0;
      foreach ($days as $_d) {
          $oa_n = (int)(isset($_d['own_arrangement_nights']) ? $_d['own_arrangement_nights'] : 0);
          $total_nights += max(1, $oa_n ?: 1);
      }
      $total_nights = max(0, $total_nights - 1);
    ?>
    <?php if ($start_date): ?>
    <div style="font-size:.72rem;color:var(--grey-mid);margin-bottom:10px;padding:6px 10px;background:var(--off-white);border-radius:6px;">
      📅 <?= $start_date->format('d M Y') ?><br>
      <?php $end = clone $start_date; $end->modify("+{$total_nights} days"); ?>
      → <?= $end->format('d M Y') ?><br>
      <strong><?= $total_days ?> days / <?= $total_nights ?> nights</strong>
    </div>
    <?php else: ?>
    <div style="font-size:.72rem;color:var(--amber);margin-bottom:10px;">
      ⚠ Set start date in Settings
    </div>
    <?php endif; ?>
    <?php
      // Build a date map: day_id → actual calendar date
      $day_date_map = [];
      if ($start_date) {
          $cur_date = clone $start_date;
          foreach ($days as $_d) {
              $day_date_map[(int)$_d['id']] = clone $cur_date;
              $oa_n = (int)(isset($_d['own_arrangement_nights']) ? $_d['own_arrangement_nights'] : 0);
              $cur_date->modify('+'.max(1, $oa_n ?: 1).' days');
          }
      }
    ?>
    <div id="day-sort-list" style="margin-bottom:4px;">
    <?php foreach ($days as $d_idx => $d): ?>
    <?php $has_lodge = !empty($d['start_lodge_id']) || !empty($d['end_lodge_id']); ?>
    <?php
      $d_is_oa = !empty($d['own_arrangement'] ?? 0);
      $d_oa_n  = (int)(isset($d['own_arrangement_nights']) ? $d['own_arrangement_nights'] : 0);
    ?>
    <div class="day-sort-item" data-id="<?= $d['id'] ?>" style="display:flex;gap:3px;align-items:stretch;margin-bottom:6px;cursor:default;">
      <div class="day-drag-handle" title="Drag to reorder"
           style="display:flex;align-items:center;padding:0 4px;color:var(--grey-mid);cursor:grab;font-size:.9rem;user-select:none;">⠿</div>
      <a href="program_edit.php?id=<?= $id ?>&tab=days&day=<?= $d['id'] ?>"
         class="day-btn<?= $active_day===(int)$d['id']?' active':'' ?><?= $has_lodge?' filled':'' ?>"
         style="flex:1;display:block;text-align:center;">
        Day <?= $d['day_number'] ?><?= ($d_is_oa && $d_oa_n > 1) ? '–'.($d['day_number']+$d_oa_n-1) : '' ?>
        <?php if ($d_is_oa): ?>
          <div style="font-size:.62rem;font-weight:700;color:#7A4F01;background:#fff8e1;border-radius:3px;padding:1px 4px;margin-top:2px;">🏨 OA ×<?= $d_oa_n ?>n</div>
        <?php elseif ($d['day_title_en']): ?>
          <div style="font-size:.68rem;font-weight:400;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= h(mb_substr($d['day_title_en'],0,20)) ?></div>
        <?php endif; ?>
        <?php if (isset($day_date_map[(int)$d['id']])): ?>
          <div style="font-size:.62rem;color:var(--grey-mid);margin-top:1px;"><?= $day_date_map[(int)$d['id']]->format('d M') ?></div>
        <?php endif; ?>
      </a>
      <form method="POST" action="program_edit.php?id=<?= $id ?>&tab=days" style="margin:0;"
            onsubmit="return confirm('Delete Day <?= $d['day_number'] ?>?')">
        <input type="hidden" name="_sub" value="delete_day">
        <input type="hidden" name="day_id" value="<?= $d['id'] ?>">
        <button title="Delete day" style="height:100%;padding:2px 6px;font-size:.65rem;border:1px solid var(--red-lt);border-radius:4px;background:var(--red-lt);color:var(--red-dk);cursor:pointer;">✕</button>
      </form>
    </div>
    <?php endforeach; ?>
    </div>
        <form method="POST" action="program_edit.php?id=<?= $id ?>&tab=days" style="margin:0;"
              onsubmit="return confirm('Delete Day <?= $d['day_number'] ?>?')">
          <input type="hidden" name="_sub" value="delete_day">
          <input type="hidden" name="day_id" value="<?= $d['id'] ?>">
          <input type="hidden" name="day_num" value="<?= $d['day_number'] ?>">
          <button title="Delete day" style="padding:2px 5px;font-size:.65rem;border:1px solid var(--red-lt);border-radius:4px;background:var(--red-lt);color:var(--red-dk);cursor:pointer;">✕</button>
    <form method="POST" action="program_edit.php?id=<?= $id ?>&tab=days" style="margin-top:10px;">
      <input type="hidden" name="_sub" value="add_day">
      <button class="btn btn-red" style="width:100%;font-size:.75rem;">+ Add Day</button>
    </form>
  </div>

  <!-- Day editor -->
  <div>
  <?php if ($current_day_data): ?>
  <!-- Single form wrapping the entire day editor -->
  <form id="day-save-form"
        method="POST"
        action="program_edit.php?id=<?= $id ?>&tab=days&day=<?= $active_day ?>">
    <input type="hidden" name="_sub"   value="day">
    <input type="hidden" name="day_id" value="<?= $active_day ?>">

    <div style="margin-bottom:16px;">
      <div style="font-family:'Merriweather',serif;font-size:1rem;font-weight:700;">Day <?= $current_day_data['day_number'] ?></div>
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
        } elseif (!empty($current_day_data['start_destination_id'])) {
            foreach ($destinations_list as $_d)
                if ((int)$_d['id'] === (int)$current_day_data['start_destination_id'])
                    $start_display = $_d['name_en'];
        } elseif (!empty($current_day_data['start_custom'])) {
            $start_display = $current_day_data['start_custom'];
        } elseif ($prev_lodge_name) {
            $start_display = '↖ Inherited — ' . $prev_lodge_name;
        }
        // Determine current hidden id value (prefixed)
        $start_hidden_id = '';
        if (!empty($current_day_data['start_lodge_id']))
            $start_hidden_id = 'lodge_' . $current_day_data['start_lodge_id'];
        elseif (!empty($current_day_data['start_destination_id']))
            $start_hidden_id = 'dest_' . $current_day_data['start_destination_id'];
        // Build options JSON for JS
        $start_opts = [];
        if ($prev_lodge_name) $start_opts[] = ['id'=>'', 'label'=>'↖ Inherited — '.$prev_lodge_name, 'group'=>'Inherited'];
        // Destinations first (cities, airports, hubs)
        foreach ($destinations_list as $_d)
            $start_opts[] = ['id'=>'dest_'.(string)$_d['id'], 'label'=>$_d['name_en'], 'group'=>'Destinations'];
        // Then lodges grouped by destination
        foreach ($lodges_grouped as $_dname => $_ls)
            foreach ($_ls as $_l)
                $start_opts[] = ['id'=>'lodge_'.(string)$_l['id'], 'label'=>$_l['name'], 'group'=>$_dname];
        ?>
        <div class="iti-combo" data-field="start_lodge">
          <div class="iti-combo-inner">
            <input type="text" class="iti-combo-text" autocomplete="off"
                   placeholder="Type or choose from list…"
                   value="<?= h($start_display) ?>">
            <button type="button" class="iti-combo-arrow" tabindex="-1">▾</button>
          </div>
          <input type="hidden" name="start_lodge_id"     value="<?= h($start_hidden_id) ?>">
          <input type="hidden" name="start_lodge_custom" value="<?= h($current_day_data['start_custom'] ?? '') ?>">
          <div class="iti-combo-drop" data-opts='<?= json_encode($start_opts, JSON_HEX_APOS) ?>'></div>
        </div>
      </div>

      <!-- 2. TRANSFER (multi-line, optional) -->
      <div style="background:var(--off-white,#f7f6f3);border-bottom:1px dashed var(--grey-lt);padding:12px 20px;">
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);margin-bottom:10px;">🚌 Transfer <span style="font-weight:400;">(optional)</span></div>

        <?php
        $tr_combo_opts = [];
        foreach ($transfer_map as $_tid => $_tr)
            $tr_combo_opts[] = ['label' => ($_tr['from_name']??'').' → '.($_tr['to_name']??'').' ('.($_tr['duration_min']??0).' min)'];
        foreach ($flight_map as $_fid => $_fl)
            $tr_combo_opts[] = ['label' => 'Flight: '.($_fl['from_airport']??'').' → '.($_fl['to_airport']??'')];
        $tr_opts_json = json_encode(array_map(fn($o)=>['id'=>'','label'=>$o['label'],'group'=>'Suggestions'], $tr_combo_opts), JSON_HEX_APOS);
        ?>

        <div id="transfer-rows">
        <?php foreach ($current_transfers as $tr): ?>
        <div class="array-row" style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">
          <div class="iti-combo" style="flex:1;max-width:none;">
            <div class="iti-combo-inner">
              <input type="text" name="transfer_desc[]" class="iti-combo-text" autocomplete="off"
                     value="<?= h($tr['description']) ?>"
                     placeholder="e.g. Transfer to Arusha airport ~30 min">
              <button type="button" class="iti-combo-arrow" tabindex="-1">▾</button>
            </div>
            <div class="iti-combo-drop" data-opts='<?= $tr_opts_json ?>' data-no-clear="1"></div>
          </div>
          <button type="button" class="btn btn-danger btn-sm" onclick="this.closest('.array-row').remove()">✕</button>
        </div>
        <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-outline btn-sm" style="margin-top:6px;"
                onclick="addTransferRow()">+ Add Transfer</button>
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

        <!-- Own Arrangement toggle -->
        <?php $is_oa = !empty($current_day_data['own_arrangement']); ?>
        <label style="display:flex;align-items:center;gap:8px;margin-bottom:10px;cursor:pointer;">
          <input type="checkbox" name="own_arrangement" value="1" id="oa-check"
                 <?= $is_oa ? 'checked' : '' ?>
                 style="width:16px;height:16px;accent-color:var(--red);"
                 onchange="toggleOA(this.checked)">
          <span style="font-size:.85rem;font-weight:600;">🏨 Own Arrangement</span>
        </label>
        <div id="oa-nights-wrap" style="margin-bottom:10px;<?= $is_oa ? '' : 'display:none;' ?>">
          <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-dk);display:block;margin-bottom:4px;">Own Arrangement Nights</label>
          <input type="number" name="own_arrangement_nights" id="oa-nights"
                 min="1" max="30" value="<?= (int)($current_day_data['own_arrangement_nights'] ?? 1) ?>"
                 style="width:100px;padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.9rem;">
          <span style="font-size:.75rem;color:var(--grey-mid);margin-left:8px;">nights in own accommodation</span>
        </div>

        <?php
        $acc_display = '';
        if (!empty($current_day_data['end_lodge_id']))
            foreach ($lodges_grouped as $_d => $_ls)
                foreach ($_ls as $_l)
                    if ((int)$_l['id'] === (int)$current_day_data['end_lodge_id'])
                        $acc_display = $_l['name'];
        if (!$acc_display && !empty($current_day_data['end_lodge_custom'])) {
            $acc_display = $current_day_data['end_lodge_custom'];
        }
        // Map back Own Arrangement to the special 'own' id for the combo
        $acc_hidden_id = $current_day_data['end_lodge_id'] ?? '';
        if (strcasecmp(trim($current_day_data['end_lodge_custom'] ?? ''), 'own arrangement') === 0 && !$acc_hidden_id) {
            $acc_hidden_id = 'own';
            $acc_display   = '🏨 Own Arrangement';
        }
        $acc_opts = [
            ['id'=>'', 'label'=>'— No overnight —', 'group'=>''],
            ['id'=>'own', 'label'=>'🏨 Own Arrangement', 'group'=>'Special'],
        ];
        foreach ($lodges_grouped as $_dname => $_ls)
            foreach ($_ls as $_l)
                $acc_opts[] = ['id'=>(string)$_l['id'], 'label'=>$_l['name'], 'group'=>$_dname];
        ?>
        <div id="oa-lodge-wrap">
        <div style="font-size:.72rem;color:var(--grey-mid);margin-bottom:6px;font-style:italic;">Lodge (optional — leave blank if fully independent)</div>
        <div class="iti-combo" data-field="end_lodge">
          <div class="iti-combo-inner">
            <input type="text" class="iti-combo-text" autocomplete="off"
                   placeholder="Type or choose from list…"
                   value="<?= h($acc_display) ?>">
            <button type="button" class="iti-combo-arrow" tabindex="-1">▾</button>
          </div>
          <input type="hidden" name="end_lodge_id"     value="<?= h($acc_hidden_id) ?>">
          <input type="hidden" name="end_lodge_custom" value="<?= h($current_day_data['end_lodge_custom'] ?? '') ?>">
          <div class="iti-combo-drop" data-opts='<?= json_encode($acc_opts, JSON_HEX_APOS) ?>'></div>
        </div>
        </div>
      </div>

      <!-- 6. INCLUDED -->
      <div style="padding:12px 20px;background:var(--off-white,#f7f6f3);">
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);margin-bottom:8px;">🍽️ Included</div>
        <div style="display:flex;gap:20px;flex-wrap:wrap;">
          <label class="meal-check"><input type="checkbox" name="meal_breakfast" value="1" <?= $current_day_data['meal_breakfast']?'checked':'' ?> style="accent-color:var(--red);"> Breakfast</label>
          <label class="meal-check"><input type="checkbox" name="meal_lunch"     value="1" <?= $current_day_data['meal_lunch']?'checked':'' ?>     style="accent-color:var(--red);"> Lunch</label>
          <label class="meal-check"><input type="checkbox" name="meal_dinner"    value="1" <?= $current_day_data['meal_dinner']?'checked':'' ?>    style="accent-color:var(--red);"> Dinner</label>
          <label class="meal-check"><input type="checkbox" name="meal_all_inclusive" value="1" <?= ($current_day_data['meal_all_inclusive']??0)?'checked':'' ?> style="accent-color:var(--red);"> All Inclusive</label>
          <label class="meal-check"><input type="checkbox" name="meal_game_package"  value="1" <?= ($current_day_data['meal_game_package']??0)?'checked':'' ?>  style="accent-color:var(--red);"> Game Package</label>
        </div>
      </div>

    </div><!-- end blocchi giorno -->

  <!-- Activities -->
  <div class="form-card" style="margin-top:16px;padding:20px 24px;">
    <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);margin-bottom:14px;">🦁 Activities</div>

    <?php
    $act_opts = [];
    foreach ($activities_list as $a)
        $act_opts[] = ['id'=>(string)$a['id'], 'label'=>($a['name_en']).(($a['dest_name_en']??'') ? ' — '.$a['dest_name_en'] : ''), 'group'=>ITI_ACTIVITY_TYPES[$a['activity_type']] ?? 'Other'];
    $act_opts_json = json_encode($act_opts, JSON_HEX_APOS);
    ?>

    <div id="activity-rows">
    <?php foreach ($current_acts as $a): ?>
    <div class="act-row array-row">
      <div style="font-size:1.1rem;"><?= !empty($a['activity_type']) ? (ITI_ACTIVITY_ICONS[$a['activity_type']] ?? '🎯') : '🎯' ?></div>
      <div class="act-info" style="flex:1;">
        <div class="iti-combo" style="max-width:none;">
          <div class="iti-combo-inner">
            <input type="text" class="iti-combo-text" autocomplete="off"
                   value="<?= h(!empty($a['activity_id']) ? ($a['name_en'] ?? '') : ($a['activity_custom'] ?? '')) ?>"
                   placeholder="Type or choose activity…">
            <button type="button" class="iti-combo-arrow" tabindex="-1">▾</button>
          </div>
          <input type="hidden" name="activity_id[]"     value="<?= h($a['activity_id'] ?? '') ?>">
          <input type="hidden" name="activity_custom[]" value="<?= h($a['activity_custom'] ?? '') ?>">
          <div class="iti-combo-drop" data-opts='<?= $act_opts_json ?>'></div>
        </div>
      </div>
      <button type="button" class="btn btn-danger btn-sm" onclick="this.closest('.array-row').remove()">✕</button>
    </div>
    <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-outline btn-sm" style="margin-top:8px;"
            onclick="addActivityRow()">+ Add Activity</button>
  </div>

  <!-- Flights -->
  <div class="form-card" style="margin-top:16px;padding:20px 24px;">
    <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);margin-bottom:14px;">✈️ Flights</div>

    <?php
    $fl_opts = [];
    foreach ($flight_map as $_fid => $_fl)
        $fl_opts[] = ['id'=>(string)$_fid, 'label'=>($_fl['from_airport']??'').' → '.($_fl['to_airport']??''), 'group'=>'Flight routes'];
    $fl_opts_json = json_encode($fl_opts, JSON_HEX_APOS);
    ?>

    <div id="flight-rows">
    <?php foreach ($current_flights as $fl): ?>
    <div class="act-row array-row">
      <div style="font-size:1.1rem;">✈️</div>
      <div class="act-info" style="flex:1;display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;">
        <div class="iti-combo" style="flex:2;min-width:180px;max-width:none;">
          <div class="iti-combo-inner">
            <input type="text" class="iti-combo-text" autocomplete="off"
                   value="<?= h(!empty($fl['flight_route_id']) ? (($fl['from_airport']??'').' → '.($fl['to_airport']??'')) : ($fl['flight_custom']??'')) ?>"
                   placeholder="Route or custom flight…">
            <button type="button" class="iti-combo-arrow" tabindex="-1">▾</button>
          </div>
          <input type="hidden" name="flight_route_id[]"  value="<?= h($fl['flight_route_id'] ?? '') ?>">
          <input type="hidden" name="flight_custom[]"    value="<?= h($fl['flight_custom'] ?? '') ?>">
          <div class="iti-combo-drop" data-opts='<?= $fl_opts_json ?>'></div>
        </div>
        <div class="iti-combo" style="min-width:160px;max-width:200px;">
          <div class="iti-combo-inner">
            <input type="text" class="iti-combo-text" autocomplete="off"
                   name="airline_company[]"
                   value="<?= h($fl['airline_company'] ?? $fl['operator'] ?? '') ?>"
                   placeholder="Airline…">
            <button type="button" class="iti-combo-arrow" tabindex="-1">▾</button>
          </div>
          <div class="iti-combo-drop" data-opts='<?= json_encode($airline_opts, JSON_HEX_APOS) ?>' data-no-clear="1"></div>
        </div>
        <?php
        // Helper: parse HH:MM from stored value
        $dep_parts = explode(':', $fl['departure_time'] ?? '');
        $arr_parts = explode(':', $fl['arrival_time']   ?? '');
        $dep_h = $dep_parts[0] ?? ''; $dep_m = $dep_parts[1] ?? '';
        $arr_h = $arr_parts[0] ?? ''; $arr_m = $arr_parts[1] ?? '';
        // HH options 00-23, MM options 00-59 step 5
        $hours = array_map(fn($h)=>str_pad($h,2,'0',STR_PAD_LEFT), range(0,23));
        $mins  = array_map(fn($m)=>str_pad($m,2,'0',STR_PAD_LEFT), range(0,59,5));
        ?>
        <div style="display:flex;align-items:center;gap:3px;">
          <select name="dep_h[]" style="padding:6px 4px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.82rem;width:58px;">
            <option value="">HH</option>
            <?php foreach ($hours as $h): ?><option value="<?= $h ?>" <?= $dep_h===$h?'selected':'' ?>><?= $h ?></option><?php endforeach; ?>
          </select>
          <span style="color:var(--grey-mid);font-weight:700;">:</span>
          <select name="dep_m[]" style="padding:6px 4px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.82rem;width:58px;">
            <option value="">MM</option>
            <?php foreach ($mins as $m): ?><option value="<?= $m ?>" <?= $dep_m===$m?'selected':'' ?>><?= $m ?></option><?php endforeach; ?>
          </select>
          <span style="color:var(--grey-mid);font-size:.7rem;margin:0 4px;">→</span>
          <select name="arr_h[]" style="padding:6px 4px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.82rem;width:58px;">
            <option value="">HH</option>
            <?php foreach ($hours as $h): ?><option value="<?= $h ?>" <?= $arr_h===$h?'selected':'' ?>><?= $h ?></option><?php endforeach; ?>
          </select>
          <span style="color:var(--grey-mid);font-weight:700;">:</span>
          <select name="arr_m[]" style="padding:6px 4px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.82rem;width:58px;">
            <option value="">MM</option>
            <?php foreach ($mins as $m): ?><option value="<?= $m ?>" <?= $arr_m===$m?'selected':'' ?>><?= $m ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <button type="button" class="btn btn-danger btn-sm" onclick="this.closest('.array-row').remove()">✕</button>
    </div>
    <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-outline btn-sm" style="margin-top:8px;"
            onclick="addFlightRow()">+ Add Flight</button>
  </div>

  </form><!-- end day-save-form -->
  <div style="position:sticky;bottom:16px;margin-top:12px;z-index:10;">
    <button type="submit" form="day-save-form" id="btn-save-day"
            class="btn btn-red" style="width:100%;padding:12px;font-size:.95rem;box-shadow:0 2px 8px rgba(0,0,0,.2);">
      💾 Save
    </button>
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
      <label>Pax — Adults</label>
      <input type="number" name="pax_adults" min="1" value="<?= (int)$program['pax_adults'] ?>">
      <span class="form-hint">Used in preview and .docx</span>
    </div>
    <div class="form-group">
      <label>Pax — Children</label>
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
    if (!input || !drop) return;
    var opts   = JSON.parse(drop.dataset.opts || '[]');

    function renderDrop(q) {
        drop.innerHTML = '';
        var words = q.toLowerCase().trim().split(/\s+/).filter(Boolean);
        var q2 = q.toLowerCase();
        var lastGroup = null;
        var shown = 0;
        opts.forEach(function(o) {
            var label = o.label.toLowerCase();
            // Multi-word: ALL words must appear somewhere in the label
            if (words.length > 1) {
                if (!words.every(function(w){ return label.includes(w); })) return;
            } else if (words.length === 1) {
                if (!label.includes(words[0])) return;
            }
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
                input.value = o.label;
                if (hidId)  hidId.value  = o.id;
                if (hidTxt) hidTxt.value = '';
                closeDrop();
            });
            drop.appendChild(d);
            shown++;
        });
        if (words.length > 0 && shown === 0) {
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

    function openDrop() {
        renderDrop(input.value);
        drop.classList.add('open');
        // Flip upward if not enough space below
        var rect = combo.getBoundingClientRect();
        var spaceBelow = window.innerHeight - rect.bottom;
        var spaceAbove = rect.top;
        if (spaceBelow < 240 && spaceAbove > spaceBelow) {
            drop.style.top  = 'auto';
            drop.style.bottom = 'calc(100% + 2px)';
        } else {
            drop.style.top  = 'calc(100% + 2px)';
            drop.style.bottom = 'auto';
        }
    }
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

<?php if ($current_day_data): ?>
<script>
var trOptsJson   = <?= json_encode(array_map(fn($o)=>['id'=>'','label'=>$o['label'],'group'=>'Suggestions'], array_merge(
    array_map(fn($_tr)=>['label'=>($_tr['from_name']??'').' → '.($_tr['to_name']??'').' ('.($_tr['duration_min']??0).' min)'], array_values($transfer_map)),
    array_map(fn($_fl)=>['label'=>'Flight: '.($_fl['from_airport']??'').' → '.($_fl['to_airport']??'')], array_values($flight_map))
))) ?>;
var actOptsJson  = <?= json_encode(array_map(fn($a)=>['id'=>(string)$a['id'],'label'=>$a['name_en'].(($a['dest_name_en']??'')?' — '.($a['dest_name_en']??''):''),'group'=>ITI_ACTIVITY_TYPES[$a['activity_type']]??'Other'], $activities_list)) ?>;
var flOptsJson   = <?= json_encode(array_map(fn($_fl)=>['id'=>(string)array_search($_fl,$flight_map),'label'=>($_fl['from_airport']??'').' → '.($_fl['to_airport']??''),'group'=>'Flight routes'], array_values($flight_map))) ?>;
var airlineOptsJson = <?= json_encode($airline_opts) ?>;

function makeCombo(inputAttrs, hiddenName, hiddenVal, opts, noId) {
    var wrap = document.createElement('div');
    wrap.className = 'iti-combo';
    wrap.style.cssText = 'flex:1;max-width:none;';
    var inner = document.createElement('div');
    inner.className = 'iti-combo-inner';
    var inp = document.createElement('input');
    inp.type = 'text'; inp.className = 'iti-combo-text'; inp.autocomplete = 'off';
    Object.assign(inp, inputAttrs);
    var arrow = document.createElement('button');
    arrow.type = 'button'; arrow.className = 'iti-combo-arrow'; arrow.tabIndex = -1; arrow.textContent = '▾';
    inner.appendChild(inp); inner.appendChild(arrow); wrap.appendChild(inner);
    if (!noId) {
        var hid = document.createElement('input');
        hid.type = 'hidden'; hid.name = hiddenName; hid.value = hiddenVal || '';
        wrap.appendChild(hid);
    }
    var drop = document.createElement('div');
    drop.className = 'iti-combo-drop';
    drop.dataset.opts = JSON.stringify(opts);
    if (inputAttrs.name && inputAttrs.name.indexOf('transfer') > -1) drop.dataset.noClear = '1';
    wrap.appendChild(drop);
    // Init combo behaviour
    initCombo(wrap);
    return wrap;
}

function addTransferRow() {
    var row = document.createElement('div');
    row.className = 'array-row';
    row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px;';
    var combo = makeCombo({name:'transfer_desc[]', placeholder:'e.g. Transfer to Arusha airport ~30 min'}, '', '', trOptsJson, true);
    combo.style.flex = '1';
    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'btn btn-danger btn-sm'; btn.textContent = '✕';
    btn.onclick = function(){ this.closest('.array-row').remove(); };
    row.appendChild(combo); row.appendChild(btn);
    document.getElementById('transfer-rows').appendChild(row);
    row.querySelector('.iti-combo-text').focus();
}

function addActivityRow() {
    var row = document.createElement('div');
    row.className = 'act-row array-row';
    var icon = document.createElement('div'); icon.style.fontSize='1.1rem'; icon.textContent='🎯';
    var info = document.createElement('div'); info.className='act-info'; info.style.flex='1';
    var combo = makeCombo({placeholder:'Type or choose activity…'}, 'activity_id[]', '', actOptsJson, false);
    // rename hidden inputs for activity
    var hids = combo.querySelectorAll('input[type=hidden]');
    if (hids[0]) hids[0].name = 'activity_id[]';
    // add activity_custom hidden
    var hc = document.createElement('input'); hc.type='hidden'; hc.name='activity_custom[]'; hc.value='';
    combo.appendChild(hc);
    combo.style.maxWidth='none';
    info.appendChild(combo);
    var btn = document.createElement('button');
    btn.type='button'; btn.className='btn btn-danger btn-sm'; btn.textContent='✕';
    btn.onclick=function(){ this.closest('.array-row').remove(); };
    row.appendChild(icon); row.appendChild(info); row.appendChild(btn);
    document.getElementById('activity-rows').appendChild(row);
    row.querySelector('.iti-combo-text').focus();
}

function addFlightRow() {
    var row = document.createElement('div');
    row.className = 'act-row array-row';
    var icon = document.createElement('div'); icon.style.fontSize='1.1rem'; icon.textContent='✈️';
    var info = document.createElement('div'); info.className='act-info';
    info.style.cssText = 'flex:1;display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;';
    var combo = makeCombo({placeholder:'Route or custom flight…'}, 'flight_route_id[]', '', flOptsJson, false);
    var hids = combo.querySelectorAll('input[type=hidden]');
    if (hids[0]) hids[0].name = 'flight_route_id[]';
    var hc = document.createElement('input'); hc.type='hidden'; hc.name='flight_custom[]'; hc.value='';
    combo.appendChild(hc);
    combo.style.cssText = 'flex:2;min-width:180px;max-width:none;';
    var mkInput = function(name, type, placeholder) {
        var i = document.createElement('input');
        i.type=type; i.name=name; i.placeholder=placeholder||'';
        i.style.cssText='padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.82rem;'+(type==='text'?'min-width:130px;':'');
        return i;
    };
    info.appendChild(combo);
    var airlineCombo = makeCombo({placeholder:'Airline…', name:'airline_company[]'}, '', '', airlineOptsJson, true);
    airlineCombo.style.cssText = 'min-width:160px;max-width:200px;';
    info.appendChild(airlineCombo);
    // Time selects HH:MM dep → arr
    var hours = [], mins = [];
    for(var h=0;h<24;h++) hours.push((h<10?'0':'')+h);
    for(var m=0;m<60;m+=5) mins.push((m<10?'0':'')+m);
    function mkTimeSelects(nameH, nameM) {
        var wrap = document.createElement('div');
        wrap.style.cssText='display:flex;align-items:center;gap:3px;';
        var sh=document.createElement('select'); sh.name=nameH;
        sh.style.cssText='padding:6px 4px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.82rem;width:58px;';
        sh.innerHTML='<option value="">HH</option>'+hours.map(function(v){return'<option value="'+v+'">'+v+'</option>';}).join('');
        var sep=document.createElement('span'); sep.textContent=':'; sep.style.cssText='color:var(--grey-mid);font-weight:700;';
        var sm=document.createElement('select'); sm.name=nameM;
        sm.style.cssText='padding:6px 4px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.82rem;width:58px;';
        sm.innerHTML='<option value="">MM</option>'+mins.map(function(v){return'<option value="'+v+'">'+v+'</option>';}).join('');
        wrap.appendChild(sh); wrap.appendChild(sep); wrap.appendChild(sm);
        return wrap;
    }
    var depWrap = mkTimeSelects('dep_h[]','dep_m[]');
    var arrow = document.createElement('span'); arrow.textContent='→'; arrow.style.cssText='color:var(--grey-mid);font-size:.7rem;margin:0 4px;';
    var arrWrap = mkTimeSelects('arr_h[]','arr_m[]');
    var timeWrap = document.createElement('div'); timeWrap.style.cssText='display:flex;align-items:center;gap:3px;';
    timeWrap.appendChild(depWrap); timeWrap.appendChild(arrow); timeWrap.appendChild(arrWrap);
    info.appendChild(timeWrap);
    var btn = document.createElement('button');
    btn.type='button'; btn.className='btn btn-danger btn-sm'; btn.textContent='✕';
    btn.onclick=function(){ this.closest('.array-row').remove(); };
    row.appendChild(icon); row.appendChild(info); row.appendChild(btn);
    document.getElementById('flight-rows').appendChild(row);
    row.querySelector('.iti-combo-text').focus();
}

// Wire up combo selection to update hidden id/custom fields
function initCombo(combo) {
    var input = combo.querySelector('.iti-combo-text');
    var drop  = combo.querySelector('.iti-combo-drop');
    if (!input || !drop) return;
    var hidId  = combo.querySelector('input[name$="_id[]"], input[name$="_id"]');
    var hidTxt = combo.querySelector('input[name*="custom"]');
    var opts   = [];
    try { opts = JSON.parse(drop.dataset.opts || '[]'); } catch(e){}
    var filtered = [], focusIdx = -1;
    function renderDrop(q) {
        q = (q||'').trim();
        var words = q.toLowerCase().split(/\s+/).filter(Boolean);
        filtered = words.length ? opts.filter(function(o){
            var label = o.label.toLowerCase();
            return words.every(function(w){ return label.includes(w); });
        }) : opts;
        drop.innerHTML = '';
        var groups = {};
        filtered.forEach(function(o){ if(!groups[o.group]) groups[o.group]=[]; groups[o.group].push(o); });
        Object.keys(groups).forEach(function(g){
            var gh=document.createElement('div'); gh.className='iti-combo-group'; gh.textContent=g; drop.appendChild(gh);
            groups[g].forEach(function(o){
                var el=document.createElement('div'); el.className='iti-combo-opt'; el.textContent=o.label;
                el.addEventListener('mousedown',function(e){
                    e.preventDefault();
                    input.value = o.label;
                    if (hidId)  hidId.value  = o.id || '';
                    if (hidTxt) hidTxt.value = o.id ? '' : o.label;
                    closeDrop();
                });
                drop.appendChild(el);
            });
        });
        focusIdx = -1;
        drop.classList.toggle('open', filtered.length > 0);
        // Flip upward if near bottom
        var rect = combo.getBoundingClientRect();
        if (window.innerHeight - rect.bottom < 240 && rect.top > window.innerHeight - rect.bottom) {
            drop.style.top = 'auto'; drop.style.bottom = 'calc(100% + 2px)';
        } else {
            drop.style.top = 'calc(100% + 2px)'; drop.style.bottom = 'auto';
        }
    }
    function closeDrop(){ drop.classList.remove('open'); }
    input.addEventListener('input', function(){ renderDrop(this.value); if(hidId) hidId.value=''; if(hidTxt) hidTxt.value=this.value; });
    input.addEventListener('focus', function(){ renderDrop(this.value); });
    input.addEventListener('blur',  function(){ setTimeout(closeDrop, 150); });
    combo.querySelector('.iti-combo-arrow').addEventListener('click', function(){
        if(drop.classList.contains('open')){ closeDrop(); } else { input.focus(); renderDrop(input.value); }
    });
}

function toggleOA(checked) {
    document.getElementById('oa-nights-wrap').style.display = checked ? '' : 'none';
    if (!checked) {
        // Clear OA nights when unchecked
        var n = document.getElementById('oa-nights');
        if (n) n.value = '1';
    }
}

// ── Ctrl+S to save day ──
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        var form = document.getElementById('day-save-form');
        if (form) form.submit();
    }
});

// ── Drag & drop day reorder via SortableJS ──
(function() {
    var list = document.getElementById('day-sort-list');
    if (!list) return;
    var script = document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js';
    script.onload = function() {
        Sortable.create(list, {
            handle: '.day-drag-handle',
            animation: 150,
            ghostClass: 'day-sort-ghost',
            onEnd: function() {
                var ids = Array.from(list.querySelectorAll('.day-sort-item'))
                              .map(function(el){ return el.dataset.id; });
                var fd = new FormData();
                fd.append('_sub', 'reorder_days');
                ids.forEach(function(id){ fd.append('order[]', id); });
                fetch('program_edit.php?id=<?= $id ?>', { method:'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(){ window.location.reload(); })
                    .catch(function(){ window.location.reload(); });
            }
        });
    };
    document.head.appendChild(script);
})();
</script>
<style>
.day-sort-ghost  { opacity:.4; background:var(--red-lt,#fde8e8); }
.day-drag-handle { touch-action:none; }
</style>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
