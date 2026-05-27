<?php
/**
 * iti_functions.php
 * Helper condivisi — Itinerary Builder, Savannah Explorers Hub
 * Path: hub/modules/iti/includes/iti_functions.php
 *
 * Dipende da: db(), h(), BASE_URL  (già disponibili dal hub)
 */

// ─────────────────────────────────────────────────────────────
// COSTANTI
// ─────────────────────────────────────────────────────────────

define('ITI_LANGS', ['en', 'it', 'fr', 'es', 'de']);

define('ITI_LANG_LABELS', [
    'en' => 'English',
    'it' => 'Italiano',
    'fr' => 'Français',
    'es' => 'Español',
    'de' => 'Deutsch',
]);

define('ITI_CURRENCIES', ['USD', 'EUR']);

define('ITI_CURRENCY_SYMBOLS', [
    'USD' => '$',
    'EUR' => '€',
]);

define('ITI_PRICE_CATEGORIES', [
    'rack'    => 'Rack (Direct Client)',
    'sto'     => 'STO (Agency)',
    'stospec' => 'STO Special',
]);

define('ITI_LODGE_CATEGORIES', [
    'budget'       => 'Budget',
    'mid'          => 'Mid-range',
    'luxury'       => 'Luxury',
    'ultra_luxury' => 'Ultra Luxury',
]);

define('ITI_LODGE_TYPES', [
    'lodge'       => 'Lodge',
    'tented_camp' => 'Tented Camp',
    'hotel'       => 'Hotel',
    'mobile_camp' => 'Mobile Camp',
    'house'       => 'House',
]);

define('ITI_ACTIVITY_TYPES', [
    'game_drive'     => 'Game Drive',
    'walking_safari' => 'Walking Safari',
    'cultural'       => 'Cultural',
    'boat'           => 'Boat Safari',
    'balloon'        => 'Balloon Safari',
    'hiking'         => 'Hiking',
    'beach'          => 'Beach',
    'other'          => 'Other',
]);

define('ITI_ACTIVITY_ICONS', [
    'game_drive'     => '🚙',
    'walking_safari' => '🦶',
    'cultural'       => '🏛️',
    'boat'           => '⛵',
    'balloon'        => '🎈',
    'hiking'         => '🥾',
    'beach'          => '🏖️',
    'other'          => '⭐',
]);

define('ITI_PROGRAM_STATUSES', [
    'draft'     => 'Draft',
    'sent'      => 'Sent',
    'confirmed' => 'Confirmed',
    'cancelled' => 'Cancelled',
]);

define('ITI_PROGRAM_STATUS_BADGE', [
    'draft'     => 'status-inquiry',
    'sent'      => 'status-quoted',
    'confirmed' => 'status-booked',
    'cancelled' => 'status-cancelled',
]);

define('ITI_REQUEST_STATUSES', [
    'open'      => 'Open',
    'quoted'    => 'Quoted',
    'confirmed' => 'Confirmed',
    'cancelled' => 'Cancelled',
]);

define('ITI_REQUEST_STATUS_BADGE', [
    'open'      => 'status-inquiry',
    'quoted'    => 'status-quoted',
    'confirmed' => 'status-booked',
    'cancelled' => 'status-cancelled',
]);

define('ITI_ROAD_TYPES', [
    'tarmac' => 'Tarmac',
    'gravel' => 'Gravel',
    'mixed'  => 'Mixed',
]);

define('ITI_FLIGHT_TYPES', [
    'scheduled' => 'Scheduled',
    'charter'   => 'Charter',
]);

define('ITI_CALC_TYPES', [
    'fixed'      => 'Fixed amount',
    'per_pax'    => 'Per person',
    'percentage' => 'Percentage %',
]);

define('ITI_DISCOUNT_TYPES', [
    'early_bird' => 'Early Bird',
    'group'      => 'Group',
    'child'      => 'Child',
    'honeymoon'  => 'Honeymoon',
    'repeat'     => 'Repeat Client',
    'other'      => 'Other',
]);

define('ITI_MODULE_URL', BASE_URL . '/modules/iti');


// ─────────────────────────────────────────────────────────────
// MULTILINGUA
// ─────────────────────────────────────────────────────────────

function iti_field(array $row, string $field, string $lang = 'en'): string
{
    $lang = in_array($lang, ITI_LANGS) ? $lang : 'en';
    $val  = trim($row["{$field}_{$lang}"] ?? '');
    if ($val !== '') return $val;
    return trim($row["{$field}_en"] ?? '');
}

function iti_h(array $row, string $field, string $lang = 'en'): string
{
    return h(iti_field($row, $field, $lang));
}


// ─────────────────────────────────────────────────────────────
// DESTINAZIONI
// ─────────────────────────────────────────────────────────────

function iti_get_destinations(bool $active_only = true): array
{
    $db  = db();
    $sql = 'SELECT * FROM iti_destinations'
         . ($active_only ? ' WHERE is_active = 1' : '')
         . ' ORDER BY sort_order, name_en';
    return $db->query($sql)->fetchAll();
}

function iti_get_destination(int $id): array|false
{
    $stmt = db()->prepare('SELECT * FROM iti_destinations WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function iti_destinations_map(string $lang = 'en', bool $active_only = true): array
{
    $map = [];
    foreach (iti_get_destinations($active_only) as $r) {
        $map[$r['id']] = iti_field($r, 'name', $lang);
    }
    return $map;
}


// ─────────────────────────────────────────────────────────────
// LODGE
// ─────────────────────────────────────────────────────────────

function iti_get_lodges(bool $active_only = true, ?int $destination_id = null): array
{
    $db     = db();
    $where  = $active_only ? ['l.is_active = 1'] : [];
    $params = [];
    if ($destination_id !== null) {
        $where[]  = 'l.destination_id = ?';
        $params[] = $destination_id;
    }
    $sql = 'SELECT l.*, d.name_en AS dest_name_en, d.region
              FROM iti_lodges l
              LEFT JOIN iti_destinations d ON d.id = l.destination_id'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY d.sort_order, d.name_en, l.name';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function iti_get_lodge(int $id): array|false
{
    $stmt = db()->prepare(
        'SELECT l.*, d.name_en AS dest_name_en
           FROM iti_lodges l
           LEFT JOIN iti_destinations d ON d.id = l.destination_id
          WHERE l.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function iti_lodges_map(bool $active_only = true): array
{
    $map = [];
    foreach (iti_get_lodges($active_only) as $r) {
        $map[$r['id']] = $r['name'] . ' — ' . $r['dest_name_en'];
    }
    return $map;
}

/** Lodge raggruppati per destinazione — per i dropdown del builder */
function iti_lodges_grouped(): array
{
    $grouped = [];
    foreach (iti_get_lodges(true) as $l) {
        $grouped[$l['dest_name_en']][] = $l;
    }
    return $grouped;
}


// ─────────────────────────────────────────────────────────────
// ATTIVITÀ
// ─────────────────────────────────────────────────────────────

function iti_get_activities(bool $active_only = true, ?int $destination_id = null): array
{
    $db     = db();
    $where  = $active_only ? ['a.is_active = 1'] : [];
    $params = [];
    if ($destination_id !== null) {
        $where[]  = '(a.destination_id = ? OR a.destination_id IS NULL)';
        $params[] = $destination_id;
    }
    $sql = 'SELECT a.*, d.name_en AS dest_name_en
              FROM iti_activities a
              LEFT JOIN iti_destinations d ON d.id = a.destination_id'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY a.destination_id IS NULL, d.sort_order, a.activity_type, a.name_en';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function iti_get_activity(int $id): array|false
{
    $stmt = db()->prepare('SELECT * FROM iti_activities WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}


// ─────────────────────────────────────────────────────────────
// TRANSFER ROUTES
// ─────────────────────────────────────────────────────────────

function iti_get_transfer_routes(bool $active_only = true): array
{
    $sql = 'SELECT tr.*,
                   df.name_en AS from_name,
                   dt.name_en AS to_name
              FROM iti_transfer_routes tr
              LEFT JOIN iti_destinations df ON df.id = tr.from_destination
              LEFT JOIN iti_destinations dt ON dt.id = tr.to_destination'
         . ($active_only ? ' WHERE tr.is_active = 1' : '')
         . ' ORDER BY df.name_en, dt.name_en';
    return db()->query($sql)->fetchAll();
}

function iti_get_transfer_route(int $id): array|false
{
    $stmt = db()->prepare(
        'SELECT tr.*, df.name_en AS from_name, dt.name_en AS to_name
           FROM iti_transfer_routes tr
           LEFT JOIN iti_destinations df ON df.id = tr.from_destination
           LEFT JOIN iti_destinations dt ON dt.id = tr.to_destination
          WHERE tr.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/** Mappa [id => "Arusha → Tarangire (120 min)"] per dropdown */
function iti_transfer_routes_map(): array
{
    $map = [];
    foreach (iti_get_transfer_routes() as $r) {
        $map[$r['id']] = $r['from_name'] . ' → ' . $r['to_name']
                       . ' (' . $r['duration_min'] . ' min)';
    }
    return $map;
}


// ─────────────────────────────────────────────────────────────
// FLIGHT ROUTES
// ─────────────────────────────────────────────────────────────

function iti_get_flight_routes(bool $active_only = true): array
{
    $sql = 'SELECT * FROM iti_flight_routes'
         . ($active_only ? ' WHERE is_active = 1' : '')
         . ' ORDER BY from_airport, to_airport';
    return db()->query($sql)->fetchAll();
}

function iti_get_flight_route(int $id): array|false
{
    $stmt = db()->prepare('SELECT * FROM iti_flight_routes WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/** Mappa [id => "Arusha → Seronera — Coastal Aviation"] */
function iti_flight_routes_map(): array
{
    $map = [];
    foreach (iti_get_flight_routes() as $r) {
        $label  = $r['from_airport'] . ' → ' . $r['to_airport'];
        if ($r['operator']) $label .= ' — ' . $r['operator'];
        $map[$r['id']] = $label;
    }
    return $map;
}


// ─────────────────────────────────────────────────────────────
// RICHIESTE
// ─────────────────────────────────────────────────────────────

function iti_get_request(int $id): array|false
{
    $stmt = db()->prepare('SELECT * FROM iti_requests WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function iti_get_requests(array $filters = []): array
{
    $db     = db();
    $where  = ['1=1'];
    $params = [];

    if (!empty($filters['q'])) {
        $where[]  = '(client_name LIKE ? OR client_email LIKE ? OR agent_name LIKE ?)';
        $params[] = '%' . $filters['q'] . '%';
        $params[] = '%' . $filters['q'] . '%';
        $params[] = '%' . $filters['q'] . '%';
    }
    if (!empty($filters['status'])) {
        $where[]  = 'status = ?';
        $params[] = $filters['status'];
    }

    $stmt = $db->prepare(
        'SELECT r.*,
                (SELECT COUNT(*) FROM iti_programs p WHERE p.request_id = r.id) AS program_count
           FROM iti_requests r
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY r.id DESC'
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}


// ─────────────────────────────────────────────────────────────
// PROGRAMMI
// ─────────────────────────────────────────────────────────────

function iti_get_program(int $id): array|false
{
    $stmt = db()->prepare(
        'SELECT p.*, r.client_name, r.agent_name,
                r.pax_adults AS req_adults, r.pax_children AS req_children
           FROM iti_programs p
           LEFT JOIN iti_requests r ON r.id = p.request_id
          WHERE p.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function iti_get_programs(string $type = 'sample', array $filters = []): array
{
    $db     = db();
    $where  = ['p.program_type = ?'];
    $params = [$type];

    if (!empty($filters['q'])) {
        $where[]  = '(p.title_en LIKE ? OR r.client_name LIKE ?)';
        $params[] = '%' . $filters['q'] . '%';
        $params[] = '%' . $filters['q'] . '%';
    }
    if (!empty($filters['status'])) {
        $where[]  = 'p.status = ?';
        $params[] = $filters['status'];
    }

    $stmt = $db->prepare(
        'SELECT p.*, r.client_name, r.agent_name
           FROM iti_programs p
           LEFT JOIN iti_requests r ON r.id = p.request_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY p.id DESC'
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function iti_get_program_days(int $program_id): array
{
    $stmt = db()->prepare(
        'SELECT pd.*,
                ls.name AS start_lodge_name,
                le.name AS end_lodge_name,
                ds.name_en AS start_dest_name,
                de.name_en AS end_dest_name
           FROM iti_program_days pd
           LEFT JOIN iti_lodges ls ON ls.id = pd.start_lodge_id
           LEFT JOIN iti_lodges le ON le.id = pd.end_lodge_id
           LEFT JOIN iti_destinations ds ON ds.id = ls.destination_id
           LEFT JOIN iti_destinations de ON de.id = le.destination_id
          WHERE pd.program_id = ?
          ORDER BY pd.day_number'
    );
    $stmt->execute([$program_id]);
    return $stmt->fetchAll();
}

function iti_get_day_activities(int $program_day_id): array
{
    $stmt = db()->prepare(
        'SELECT da.*, a.name_en, a.name_it, a.name_fr, a.name_es, a.name_de,
                a.activity_type, a.duration_hours
           FROM iti_day_activities da
           JOIN  iti_activities a ON a.id = da.activity_id
          WHERE  da.program_day_id = ?
          ORDER  BY da.sort_order, da.id'
    );
    $stmt->execute([$program_day_id]);
    return $stmt->fetchAll();
}

function iti_get_day_flights(int $program_day_id): array
{
    $stmt = db()->prepare(
        'SELECT df.*, fr.from_airport, fr.to_airport,
                fr.from_code, fr.to_code, fr.operator, fr.flight_type, fr.duration_min
           FROM iti_day_flights df
           JOIN  iti_flight_routes fr ON fr.id = df.flight_route_id
          WHERE  df.program_day_id = ?
          ORDER  BY df.sort_order, df.id'
    );
    $stmt->execute([$program_day_id]);
    return $stmt->fetchAll();
}

function iti_get_program_prices(int $program_id): array
{
    $stmt = db()->prepare(
        'SELECT * FROM iti_program_prices
          WHERE program_id = ?
          ORDER BY FIELD(price_category,"rack","sto","stospec")'
    );
    $stmt->execute([$program_id]);
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $map[$r['price_category']] = $r;
    }
    return $map;
}

function iti_get_program_supplements(int $program_id): array
{
    $stmt = db()->prepare(
        'SELECT * FROM iti_price_supplements WHERE program_id = ? ORDER BY sort_order, id'
    );
    $stmt->execute([$program_id]);
    return $stmt->fetchAll();
}

function iti_get_program_discounts(int $program_id): array
{
    $stmt = db()->prepare(
        'SELECT * FROM iti_price_discounts WHERE program_id = ? ORDER BY sort_order, id'
    );
    $stmt->execute([$program_id]);
    return $stmt->fetchAll();
}

function iti_get_program_inclusions(int $program_id): array
{
    $stmt = db()->prepare(
        'SELECT pi.*,
                COALESCE(pi.text_en, si.text_en) AS resolved_en,
                COALESCE(pi.text_it, si.text_it) AS resolved_it,
                COALESCE(pi.text_fr, si.text_fr) AS resolved_fr,
                COALESCE(pi.text_es, si.text_es) AS resolved_es,
                COALESCE(pi.text_de, si.text_de) AS resolved_de
           FROM iti_program_inclusions pi
           LEFT JOIN iti_standard_inclusions si ON si.id = pi.standard_inclusion_id
          WHERE pi.program_id = ?
          ORDER BY pi.item_type DESC, pi.sort_order, pi.id'
    );
    $stmt->execute([$program_id]);
    return $stmt->fetchAll();
}

function iti_get_standard_inclusions(): array
{
    return db()->query(
        'SELECT * FROM iti_standard_inclusions WHERE is_active=1 ORDER BY item_type DESC, sort_order, id'
    )->fetchAll();
}

function iti_get_terms(bool $active_only = true): array
{
    $sql = 'SELECT * FROM iti_terms_conditions'
         . ($active_only ? " WHERE is_active = 1" : '')
         . ' ORDER BY effective_date DESC';
    return db()->query($sql)->fetchAll();
}


// ─────────────────────────────────────────────────────────────
// CLONA SAMPLE → PERSONAL
// ─────────────────────────────────────────────────────────────

function iti_clone_sample_to_personal(
    int    $sample_id,
    int    $request_id,
    string $price_category = 'rack',
    string $lang           = 'en',
    string $currency       = 'USD'
): int|false {

    $db = db();
    $sample = iti_get_program($sample_id);
    if (!$sample || $sample['program_type'] !== 'sample') return false;

    // Carica la request per pax
    $req = iti_get_request($request_id);

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'INSERT INTO iti_programs
             (program_type, request_id, sample_program_id, terms_id,
              title_en, title_it, title_fr, title_es, title_de,
              subtitle_en, subtitle_it, subtitle_fr, subtitle_es, subtitle_de,
              duration_days, pax_adults, pax_children, flights_included,
              status, display_language, display_currency,
              public_token, is_published, created_by)
             VALUES
             ("personal", ?, ?, ?,
              ?, ?, ?, ?, ?,
              ?, ?, ?, ?, ?,
              ?, ?, ?, ?,
              "draft", ?, ?,
              UUID(), 0, ?)'
        );
        $stmt->execute([
            $request_id, $sample_id, $sample['terms_id'],
            $sample['title_en'], $sample['title_it'], $sample['title_fr'],
            $sample['title_es'], $sample['title_de'],
            $sample['subtitle_en'], $sample['subtitle_it'], $sample['subtitle_fr'],
            $sample['subtitle_es'], $sample['subtitle_de'],
            $sample['duration_days'],
            $req['pax_adults']   ?? $sample['pax_adults'],
            $req['pax_children'] ?? $sample['pax_children'],
            $sample['flights_included'] ?? 1,
            $lang, $currency,
            $_SESSION['user'] ?? 'system',
        ]);
        $new_id = (int)$db->lastInsertId();

        // Giorni
        foreach (iti_get_program_days($sample_id) as $d) {
            $s2 = $db->prepare(
                'INSERT INTO iti_program_days
                 (program_id, day_number,
                  day_title_en, day_title_it, day_title_fr, day_title_es, day_title_de,
                  start_lodge_id, end_lodge_id, transfer_route_id,
                  narrative_en, narrative_it, narrative_fr, narrative_es, narrative_de,
                  meal_breakfast, meal_lunch, meal_dinner)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $s2->execute([
                $new_id, $d['day_number'],
                $d['day_title_en'], $d['day_title_it'], $d['day_title_fr'],
                $d['day_title_es'], $d['day_title_de'],
                $d['start_lodge_id'], $d['end_lodge_id'], $d['transfer_route_id'],
                $d['narrative_en'], $d['narrative_it'], $d['narrative_fr'],
                $d['narrative_es'], $d['narrative_de'],
                $d['meal_breakfast'], $d['meal_lunch'], $d['meal_dinner'],
            ]);
            $new_day_id = (int)$db->lastInsertId();

            foreach (iti_get_day_activities((int)$d['id']) as $a) {
                $db->prepare(
                    'INSERT INTO iti_day_activities
                     (program_day_id, activity_id, sort_order,
                      custom_note_en, custom_note_it, custom_note_fr, custom_note_es, custom_note_de)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $new_day_id, $a['activity_id'], $a['sort_order'],
                    $a['custom_note_en'], $a['custom_note_it'], $a['custom_note_fr'],
                    $a['custom_note_es'], $a['custom_note_de'],
                ]);
            }

            foreach (iti_get_day_flights((int)$d['id']) as $fl) {
                $db->prepare(
                    'INSERT INTO iti_day_flights
                     (program_day_id, flight_route_id, departure_time, arrival_time,
                      sort_order, note_en, note_it, note_fr, note_es, note_de)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $new_day_id, $fl['flight_route_id'],
                    $fl['departure_time'], $fl['arrival_time'], $fl['sort_order'],
                    $fl['note_en'], $fl['note_it'], $fl['note_fr'],
                    $fl['note_es'], $fl['note_de'],
                ]);
            }
        }

        // Solo la categoria prezzo richiesta
        $prices = iti_get_program_prices($sample_id);
        if (isset($prices[$price_category])) {
            $p = $prices[$price_category];
            $db->prepare(
                'INSERT INTO iti_program_prices
                 (program_id, price_category,
                  price_per_pax_usd, price_per_pax_eur,
                  single_suppl_usd, single_suppl_eur,
                  child_price_usd, child_price_eur,
                  min_pax, valid_from, valid_to, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $new_id, $price_category,
                $p['price_per_pax_usd'], $p['price_per_pax_eur'],
                $p['single_suppl_usd'],  $p['single_suppl_eur'],
                $p['child_price_usd'],   $p['child_price_eur'],
                $p['min_pax'], $p['valid_from'], $p['valid_to'], $p['notes'],
            ]);
        }

        // Supplementi e sconti (categoria o 'all')
        $sups = $db->prepare("SELECT * FROM iti_price_supplements WHERE program_id = ? AND (price_category = ? OR price_category = 'all')");
        $sups->execute([$sample_id, $price_category]);
        foreach ($sups->fetchAll() as $sr) {
            $db->prepare(
                'INSERT INTO iti_price_supplements
                 (program_id, price_category, name_en, name_it, name_fr, name_es, name_de,
                  amount_usd, amount_eur, calc_type, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $new_id, $sr['price_category'],
                $sr['name_en'], $sr['name_it'], $sr['name_fr'], $sr['name_es'], $sr['name_de'],
                $sr['amount_usd'], $sr['amount_eur'], $sr['calc_type'], $sr['sort_order'],
            ]);
        }
        $discs = $db->prepare("SELECT * FROM iti_price_discounts WHERE program_id = ? AND (price_category = ? OR price_category = 'all')");
        $discs->execute([$sample_id, $price_category]);
        foreach ($discs->fetchAll() as $sr) {
            $db->prepare(
                'INSERT INTO iti_price_discounts
                 (program_id, price_category, name_en, name_it, name_fr, name_es, name_de,
                  discount_type, value_usd, value_eur, value_type,
                  conditions_en, conditions_it, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $new_id, $sr['price_category'],
                $sr['name_en'], $sr['name_it'], $sr['name_fr'], $sr['name_es'], $sr['name_de'],
                $sr['discount_type'], $sr['value_usd'], $sr['value_eur'], $sr['value_type'],
                $sr['conditions_en'], $sr['conditions_it'], $sr['sort_order'],
            ]);
        }

        // Inclusi/esclusi
        foreach (iti_get_program_inclusions($sample_id) as $inc) {
            $db->prepare(
                'INSERT INTO iti_program_inclusions
                 (program_id, item_type, standard_inclusion_id,
                  text_en, text_it, text_fr, text_es, text_de, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $new_id, $inc['item_type'], $inc['standard_inclusion_id'],
                $inc['text_en'], $inc['text_it'], $inc['text_fr'],
                $inc['text_es'], $inc['text_de'], $inc['sort_order'],
            ]);
        }

        $db->commit();
        return $new_id;

    } catch (Throwable $e) {
        $db->rollBack();
        error_log('iti_clone_sample_to_personal: ' . $e->getMessage());
        return false;
    }
}


// ─────────────────────────────────────────────────────────────
// PREZZI
// ─────────────────────────────────────────────────────────────

function iti_money(?float $amount, string $currency = 'USD', bool $decimals = false): string
{
    if ($amount === null || $amount == 0) return '—';
    $sym = ITI_CURRENCY_SYMBOLS[$currency] ?? $currency . ' ';
    return $sym . number_format($amount, $decimals ? 2 : 0);
}

function iti_duration_label(int $days, string $lang = 'en'): string
{
    $nights = max(0, $days - 1);
    $labels = [
        'en' => ['day','days','night','nights'],
        'it' => ['giorno','giorni','notte','notti'],
        'fr' => ['jour','jours','nuit','nuits'],
        'es' => ['día','días','noche','noches'],
        'de' => ['Tag','Tage','Nacht','Nächte'],
    ];
    $l = $labels[$lang] ?? $labels['en'];
    return "{$days} " . ($days === 1 ? $l[0] : $l[1])
         . ' / ' . $nights . ' ' . ($nights === 1 ? $l[2] : $l[3]);
}


// ─────────────────────────────────────────────────────────────
// FLASH / REDIRECT / UI HELPERS
// ─────────────────────────────────────────────────────────────

function iti_flash_set(string $type, string $msg): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['iti_flash'] = ['type' => $type, 'msg' => $msg];
}

function iti_flash_render(): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['iti_flash'])) {
        $f   = $_SESSION['iti_flash'];
        $cls = $f['type'] === 'success' ? 'flash-success' : 'flash-error';
        echo '<div class="flash ' . $cls . '">' . h($f['msg']) . '</div>';
        unset($_SESSION['iti_flash']);
    }
}

function iti_redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function iti_options(array $options, mixed $selected = null, string $placeholder = ''): string
{
    $html = '';
    if ($placeholder !== '') {
        $html .= '<option value="">' . h($placeholder) . '</option>';
    }
    foreach ($options as $val => $label) {
        $sel   = ((string)$val === (string)$selected) ? ' selected' : '';
        $html .= '<option value="' . h((string)$val) . '"' . $sel . '>' . h($label) . '</option>';
    }
    return $html;
}

function iti_breadcrumb(array $crumbs): void
{
    echo '<nav style="font-size:.75rem;color:var(--grey-mid);margin-bottom:20px;'
       . 'display:flex;align-items:center;gap:6px;flex-wrap:wrap;">';
    $last = count($crumbs) - 1;
    foreach ($crumbs as $i => $c) {
        if ($i === $last) {
            echo '<span style="color:var(--grey-dk);font-weight:600;">' . h($c['label']) . '</span>';
        } else {
            echo '<a href="' . h($c['url']) . '" style="color:var(--grey-mid);text-decoration:none;">'
               . h($c['label']) . '</a><span>›</span>';
        }
    }
    echo '</nav>';
}

/** Breadcrumb standard modulo ITI */
function iti_nav(string $current_label, array $extra = []): void
{
    $crumbs = [
        ['label' => 'Hub',               'url' => BASE_URL . '/hub.php'],
        ['label' => 'Itinerary Builder', 'url' => ITI_MODULE_URL . '/index.php'],
    ];
    foreach ($extra as $e) $crumbs[] = $e;
    $crumbs[] = ['label' => $current_label];
    iti_breadcrumb($crumbs);
}
