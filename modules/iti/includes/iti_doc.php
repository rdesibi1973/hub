<?php
/**
 * iti_doc.php — the programme document in the "Etnia" layout (the Word programme
 * sent to agencies), rendered as HTML for the internal preview (program_doc.php)
 * and the public link (p.php). The Word export reuses iti_doc_data().
 *
 * Sections: header · the safari at a glance (intro + day table) · prices (when
 * present) · included / not included · day by day (transfer · meals, text,
 * destination, OVERNIGHT box) · useful contacts · terms and conditions.
 *
 * Keep PHP-7 style (no match / arrow functions / str_contains).
 */

/** UI strings per language. */
function iti_doc_labels(string $lang): array {
    $L = [
        'it' => ['kicker' => 'Safari privato in Tanzania', 'days' => 'giorni', 'day1' => 'giorno', 'nights' => 'notti', 'night1' => 'notte',
                 'brief' => 'Il safari in breve', 'day' => 'Giorno', 'route' => 'Percorso', 'overnight' => 'Pernottamento', 'board' => 'Trattamento',
                 'prices' => 'Quote per persona', 'group' => 'Gruppo', 'pp' => 'Per persona', 'pax' => 'partecipanti',
                 'incl' => 'La quota comprende', 'excl' => 'La quota non comprende', 'program' => 'Programma giorno per giorno',
                 'transfer' => 'Trasferimento', 'meals' => 'Pasti', 'about' => 'circa', 'contacts' => 'Contatti utili',
                 'ref' => 'Riferimento', 'phone' => 'Telefono', 'email' => 'E-mail', 'office' => 'ufficio', 'emergency' => 'Emergenze',
                 'terms' => 'Termini e condizioni', 'activities' => 'Attività', 'end' => 'Fine dei nostri servizi',
                 'B' => 'colazione', 'L' => 'pranzo', 'D' => 'cena', 'FB' => 'pensione completa', 'HB' => 'mezza pensione', 'AI' => 'all inclusive', 'none' => '—'],
        'en' => ['kicker' => 'Private safari in Tanzania', 'days' => 'days', 'day1' => 'day', 'nights' => 'nights', 'night1' => 'night',
                 'brief' => 'Safari at a glance', 'day' => 'Day', 'route' => 'Route', 'overnight' => 'Overnight', 'board' => 'Meal plan',
                 'prices' => 'Rates per person', 'group' => 'Group', 'pp' => 'Per person', 'pax' => 'people',
                 'incl' => 'Included', 'excl' => 'Not included', 'program' => 'Day-by-day itinerary',
                 'transfer' => 'Transfer', 'meals' => 'Meals', 'about' => 'approx.', 'contacts' => 'Useful contacts',
                 'ref' => 'Contact', 'phone' => 'Phone', 'email' => 'E-mail', 'office' => 'office', 'emergency' => 'Emergencies',
                 'terms' => 'Terms and conditions', 'activities' => 'Activities', 'end' => 'End of our services',
                 'B' => 'breakfast', 'L' => 'lunch', 'D' => 'dinner', 'FB' => 'full board', 'HB' => 'half board', 'AI' => 'all inclusive', 'none' => '—'],
        'fr' => ['kicker' => 'Safari privé en Tanzanie', 'days' => 'jours', 'day1' => 'jour', 'nights' => 'nuits', 'night1' => 'nuit',
                 'brief' => 'Le safari en bref', 'day' => 'Jour', 'route' => 'Itinéraire', 'overnight' => 'Hébergement', 'board' => 'Formule',
                 'prices' => 'Tarifs par personne', 'group' => 'Groupe', 'pp' => 'Par personne', 'pax' => 'participants',
                 'incl' => 'Le prix comprend', 'excl' => 'Le prix ne comprend pas', 'program' => 'Programme jour par jour',
                 'transfer' => 'Transfert', 'meals' => 'Repas', 'about' => 'environ', 'contacts' => 'Contacts utiles',
                 'ref' => 'Contact', 'phone' => 'Téléphone', 'email' => 'E-mail', 'office' => 'bureau', 'emergency' => 'Urgences',
                 'terms' => 'Conditions générales', 'activities' => 'Activités', 'end' => 'Fin de nos services',
                 'B' => 'petit-déjeuner', 'L' => 'déjeuner', 'D' => 'dîner', 'FB' => 'pension complète', 'HB' => 'demi-pension', 'AI' => 'tout compris', 'none' => '—'],
        'es' => ['kicker' => 'Safari privado en Tanzania', 'days' => 'días', 'day1' => 'día', 'nights' => 'noches', 'night1' => 'noche',
                 'brief' => 'El safari en breve', 'day' => 'Día', 'route' => 'Recorrido', 'overnight' => 'Alojamiento', 'board' => 'Régimen',
                 'prices' => 'Precios por persona', 'group' => 'Grupo', 'pp' => 'Por persona', 'pax' => 'participantes',
                 'incl' => 'El precio incluye', 'excl' => 'El precio no incluye', 'program' => 'Programa día a día',
                 'transfer' => 'Traslado', 'meals' => 'Comidas', 'about' => 'aprox.', 'contacts' => 'Contactos útiles',
                 'ref' => 'Contacto', 'phone' => 'Teléfono', 'email' => 'E-mail', 'office' => 'oficina', 'emergency' => 'Emergencias',
                 'terms' => 'Términos y condiciones', 'activities' => 'Actividades', 'end' => 'Fin de nuestros servicios',
                 'B' => 'desayuno', 'L' => 'almuerzo', 'D' => 'cena', 'FB' => 'pensión completa', 'HB' => 'media pensión', 'AI' => 'todo incluido', 'none' => '—'],
        'de' => ['kicker' => 'Private Safari in Tansania', 'days' => 'Tage', 'day1' => 'Tag', 'nights' => 'Nächte', 'night1' => 'Nacht',
                 'brief' => 'Die Safari auf einen Blick', 'day' => 'Tag', 'route' => 'Route', 'overnight' => 'Übernachtung', 'board' => 'Verpflegung',
                 'prices' => 'Preise pro Person', 'group' => 'Gruppe', 'pp' => 'Pro Person', 'pax' => 'Teilnehmer',
                 'incl' => 'Im Preis enthalten', 'excl' => 'Nicht im Preis enthalten', 'program' => 'Reiseverlauf Tag für Tag',
                 'transfer' => 'Transfer', 'meals' => 'Mahlzeiten', 'about' => 'ca.', 'contacts' => 'Nützliche Kontakte',
                 'ref' => 'Kontakt', 'phone' => 'Telefon', 'email' => 'E-Mail', 'office' => 'Büro', 'emergency' => 'Notfälle',
                 'terms' => 'Geschäftsbedingungen', 'activities' => 'Aktivitäten', 'end' => 'Ende unserer Leistungen',
                 'B' => 'Frühstück', 'L' => 'Mittagessen', 'D' => 'Abendessen', 'FB' => 'Vollpension', 'HB' => 'Halbpension', 'AI' => 'All inclusive', 'none' => '—'],
    ];
    return isset($L[$lang]) ? $L[$lang] : $L['en'];
}

/** Localised field with fallback: lang → en → it → any language. */
function iti_doc_pick(array $row, string $field, string $lang): string {
    foreach (array_merge([$lang, 'en', 'it'], ITI_LANGUAGES) as $l) {
        $v = trim((string)($row[$field . '_' . $l] ?? ''));
        if ($v !== '') return $v;
    }
    return '';
}

/** Meal flags of a day → short text ("pensione completa", "cena", …). */
function iti_doc_meals(array $d, array $T): string {
    if (!empty($d['meal_all_inclusive'])) return $T['AI'];
    $b = !empty($d['meal_breakfast']); $l = !empty($d['meal_lunch']); $n = !empty($d['meal_dinner']);
    if ($b && $l && $n) return $T['FB'];
    if ($b && !$l && $n) return $T['HB'];
    $p = [];
    if ($b) $p[] = $T['B'];
    if ($l) $p[] = $T['L'];
    if ($n) $p[] = $T['D'];
    return $p ? implode(', ', $p) : $T['none'];
}

/** "Arusha – Tarangire 2 ore di trasferimento." → "2 ore" (duration only), or ''. */
function iti_doc_duration(string $transfer): string {
    if (preg_match('/(\d+(?:[.,]\d+)?(?:\s*-\s*\d+(?:[.,]\d+)?)?\s*(?:ore|ora|minuti|hours?|hrs?|h|heures?|horas?|Stunden?))\b/iu', $transfer, $m)) {
        return trim($m[1]);
    }
    return '';
}

/**
 * Everything the document needs, in $lang:
 * program, days[] (title, transfer, duration, meals, narrative, destination, lodge, activities),
 * glance rows, included/excluded, prices (optional), terms HTML, contacts.
 */
function iti_doc_data(int $id, string $lang): ?array {
    $db = db();
    $p = iti_get_program($id);
    if (!$p) return null;
    $T = iti_doc_labels($lang);

    $days = iti_get_days($id);
    $lodgeIds = []; $destIds = [];
    foreach ($days as $d) {
        if (!empty($d['end_lodge_id']))   $lodgeIds[(int)$d['end_lodge_id']] = true;
        if (!empty($d['destination_id'])) $destIds[(int)$d['destination_id']] = true;
    }
    $lodges = []; $dests = [];
    if ($lodgeIds) {
        $in = implode(',', array_keys($lodgeIds));
        foreach ($db->query("SELECT * FROM iti_lodges WHERE id IN ($in)")->fetchAll() as $r) $lodges[(int)$r['id']] = $r;
    }
    if ($destIds) {
        $in = implode(',', array_keys($destIds));
        foreach ($db->query("SELECT * FROM iti_destinations WHERE id IN ($in)")->fetchAll() as $r) $dests[(int)$r['id']] = $r;
    }

    $actSt = $db->prepare('SELECT da.*, a.name_en, a.name_it, a.name_fr, a.name_es, a.name_de
                             FROM iti_day_activities da LEFT JOIN iti_activities a ON a.id = da.activity_id
                            WHERE da.program_day_id = ? ORDER BY da.sort_order, da.id');

    $out = []; $seenLodge = []; $seenDest = [];
    foreach ($days as $d) {
        $transfers = [];
        foreach (iti_get_day_transfers((int)$d['id']) as $t) { if (trim($t['description']) !== '') $transfers[] = trim($t['description']); }
        $acts = [];
        $actSt->execute([(int)$d['id']]);
        foreach ($actSt->fetchAll() as $a) {
            $name = !empty($a['activity_id']) ? iti_doc_pick($a, 'name', $lang) : '';
            if ($name === '') $name = trim((string)($a['custom_note_' . $lang] ?? '')) ?: trim((string)($a['activity_custom'] ?? ''));
            if ($name !== '') $acts[] = $name;
        }

        $lodgeName = ''; $lodgeDesc = '';
        if (!empty($d['end_lodge_id']) && isset($lodges[(int)$d['end_lodge_id']])) {
            $lr = $lodges[(int)$d['end_lodge_id']];
            $lodgeName = $lr['name'];
            if (empty($seenLodge[$lr['id']])) { $lodgeDesc = iti_doc_pick($lr, 'description', $lang); $seenLodge[$lr['id']] = true; }
        } elseif (!empty($d['end_lodge_custom'])) {
            $lodgeName = $d['end_lodge_custom'];
        }
        $destName = ''; $destDesc = '';
        if (!empty($d['destination_id']) && isset($dests[(int)$d['destination_id']])) {
            $dr = $dests[(int)$d['destination_id']];
            $destName = iti_doc_pick($dr, 'name', $lang);
            if (empty($seenDest[$dr['id']])) { $destDesc = iti_doc_pick($dr, 'description', $lang); $seenDest[$dr['id']] = true; }
        } elseif (!empty($d['destination_custom'])) {
            $destName = $d['destination_custom'];
        }

        $title = iti_doc_pick($d, 'day_title', $lang);
        if ($title === '') $title = $destName !== '' ? $destName : ($lodgeName !== '' ? $lodgeName : '');
        $out[] = [
            'n'          => (int)$d['day_number'],
            'title'      => $title,
            'transfers'  => $transfers,
            'duration'   => $transfers ? iti_doc_duration($transfers[0]) : '',
            'meals'      => iti_doc_meals($d, $T),
            'narrative'  => iti_doc_pick($d, 'narrative', $lang),
            'dest'       => $destName,
            'dest_desc'  => $destDesc,
            'lodge'      => $lodgeName,
            'lodge_desc' => $lodgeDesc,
            'activities' => $acts,
        ];
    }

    // Included / not included: this programme's rows, text in $lang with fallbacks.
    $incl = []; $excl = [];
    $st = $db->prepare('SELECT pi.*, si.text_en AS s_en, si.text_it AS s_it, si.text_fr AS s_fr, si.text_es AS s_es, si.text_de AS s_de
                          FROM iti_program_inclusions pi LEFT JOIN iti_standard_inclusions si ON si.id = pi.standard_inclusion_id
                         WHERE pi.program_id = ? ORDER BY pi.sort_order, pi.id');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $r) {
        $txt = iti_doc_pick($r, 'text', $lang);
        if ($txt === '') $txt = iti_doc_pick($r, 's', $lang);
        if ($txt === '') continue;
        if ($r['item_type'] === 'exclusion') $excl[] = $txt; else $incl[] = $txt;
    }

    // Terms: the programme's own version, else the latest active general one.
    $terms = '';
    try {
        if (!empty($p['terms_id'])) {
            $s = $db->prepare('SELECT * FROM iti_terms_conditions WHERE id = ?');
            $s->execute([(int)$p['terms_id']]);
            $tr = $s->fetch();
        } else {
            try {
                $tr = $db->query('SELECT * FROM iti_terms_conditions WHERE is_active = 1 AND (program_id IS NULL OR program_id = 0) ORDER BY id DESC LIMIT 1')->fetch();
            } catch (PDOException $e) {   // older schema without program_id
                $tr = $db->query('SELECT * FROM iti_terms_conditions WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetch();
            }
        }
        if (!empty($tr)) $terms = iti_doc_pick($tr, 'content', $lang);
    } catch (PDOException $e) { $terms = ''; }

    // Prices: optional JSON [{"label":"2 partecipanti","price":1625}, …] + notes (filled from the Calc Excel).
    $prices = [];
    if (!empty($p['price_table_json'])) {
        $pj = json_decode($p['price_table_json'], true);
        if (is_array($pj)) $prices = $pj;
    }

    $n = count($out);
    $consultant = function_exists('iti_get_consultant') ? iti_get_consultant($p['created_by'] ?? '') : null;
    return [
        'lang'     => $lang,
        'T'        => $T,
        'program'  => $p,
        'title'    => iti_doc_pick($p, 'title', $lang),
        'subtitle' => iti_doc_pick($p, 'subtitle', $lang),
        'intro'    => iti_doc_pick($p, 'intro', $lang),
        'duration' => $n . ' ' . ($n === 1 ? $T['day1'] : $T['days']) . ' / ' . max(0, $n - 1) . ' ' . ($n - 1 === 1 ? $T['night1'] : $T['nights']),
        'days'     => $out,
        'incl'     => $incl,
        'excl'     => $excl,
        'prices'   => $prices,
        'price_notes' => trim((string)($p['price_notes_' . $lang] ?? ($p['price_notes'] ?? ''))),
        'terms'    => $terms,
        'logo'     => iti_setting('logo_url', 'https://hub.savannahexplorers.com/modules/iti/uploads/logo/logo_1781526818.png'),
        'contacts' => [
            ['name' => ($consultant['full_name'] ?? 'Roberto De Sibi') . ' — Savannah Explorers Ltd',
             'phone' => $consultant['whatsapp'] ?? '+255 784 453 520', 'email' => $consultant['email'] ?? 'savannah.explorers@gmail.com'],
            ['name' => 'Savannah Explorers Ltd — ' . $T['office'],
             'phone' => iti_setting('office_phone', '+255 768 900 199'), 'email' => iti_setting('office_email', 'info@savannahexplorers.com')],
            ['name' => $T['emergency'], 'phone' => iti_setting('emergency_phone', '+255 768 900 199 · +255 747 777 315'), 'email' => ''],
        ],
    ];
}

/** Plain text with blank-line paragraphs → <p>…</p>; runs of "- item" paragraphs → <ul>. */
function iti_doc_paras(string $txt): string {
    $out = ''; $list = [];
    $flush = function () use (&$out, &$list) {
        if ($list) { $out .= '<ul><li>' . implode('</li><li>', $list) . '</li></ul>'; $list = []; }
    };
    foreach (preg_split('/\n\s*\n/u', trim($txt)) as $para) {
        $para = trim($para);
        if ($para === '') continue;
        if (preg_match('/^[-•]\s+(.*)$/su', $para, $m)) { $list[] = nl2br(h(trim($m[1]))); continue; }
        $flush();
        $out .= '<p>' . nl2br(h($para)) . '</p>';
    }
    $flush();
    return $out;
}

/** Scoped CSS of the document (class .etn). */
function iti_doc_css(): string {
    return '
.etn{--red:#C0211B;--ink:#1f2328;--mute:#6b7280;--line:#e5e7eb;--soft:#faf7f5;
     max-width:860px;margin:0;color:var(--ink);font-family:Georgia,"Times New Roman",serif;font-size:15.5px;line-height:1.6;text-align:left}
.etn *{box-sizing:border-box}
.etn .etn-sans,.etn table,.etn .etn-kicker,.etn .etn-meta,.etn .etn-dayno,.etn .etn-box-lbl{font-family:"Segoe UI",Roboto,Arial,sans-serif}
.etn-head{padding:8px 0 22px;border-bottom:3px solid var(--red);margin-bottom:26px}
.etn-logo{height:74px;display:block;margin-bottom:14px}
.etn-kicker{font-size:12px;letter-spacing:.32em;text-transform:uppercase;color:var(--red);font-weight:700}
.etn h1{font-size:38px;line-height:1.1;margin:.18em 0 .2em;font-weight:700}
.etn-sub{font-size:17px;color:var(--ink);margin:0 0 6px}
.etn-dur{font-family:"Segoe UI",Roboto,Arial,sans-serif;font-size:14px;color:var(--mute)}
.etn h2{font-size:23px;margin:34px 0 12px;padding-bottom:6px;border-bottom:1px solid var(--line);color:var(--red);font-weight:700}
.etn p{margin:0 0 .75em}
.etn table{width:100%;border-collapse:collapse;font-size:14px;margin:6px 0 10px}
.etn th{text-align:left;font-size:11.5px;letter-spacing:.08em;text-transform:uppercase;color:#fff;background:var(--red);padding:8px 10px}
.etn td{padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:top}
.etn tr:nth-child(even) td{background:var(--soft)}
.etn td.etn-n{font-weight:700;width:48px}
.etn ul{margin:0 0 .6em 1.1em;padding:0}
.etn li{margin:0 0 .3em}
.etn-day{margin:0 0 30px;padding-top:4px}
.etn-dayno{font-size:12px;letter-spacing:.28em;text-transform:uppercase;color:var(--red);font-weight:700}
.etn h3{font-size:21px;margin:.1em 0 .25em;font-weight:700}
.etn-meta{font-size:13.5px;font-weight:600;color:var(--ink);margin:0 0 10px}
.etn-meta span+span:before{content:" · ";color:var(--mute);font-weight:400}
.etn-dest{color:#3a3f45}
.etn-acts{font-family:"Segoe UI",Roboto,Arial,sans-serif;font-size:13.5px;color:var(--mute);margin:0 0 10px}
.etn-box{border-left:4px solid var(--red);background:var(--soft);padding:10px 14px;margin:10px 0 0}
.etn-box-lbl{font-size:11.5px;letter-spacing:.2em;text-transform:uppercase;color:var(--red);font-weight:700}
.etn-box-name{font-weight:700;margin:2px 0 4px}
.etn-box p{font-size:14.5px;margin:0 0 .5em}
.etn-terms{font-size:13.5px;color:#374151}
.etn-terms h1,.etn-terms h2,.etn-terms h3{font-size:15px;color:var(--ink);border:0;margin:14px 0 6px;padding:0}
@media (max-width:640px){.etn{font-size:15px}.etn h1{font-size:28px}.etn th,.etn td{padding:6px 7px}}
@media print{.etn h2{break-after:avoid}.etn-day{break-inside:avoid-page}}
';
}

/** The document body (HTML). */
function iti_doc_render(array $D): string {
    $T = $D['T'];
    ob_start(); ?>
<div class="etn" lang="<?= h($D['lang']) ?>">
  <header class="etn-head">
    <?php if ($D['logo']): ?><img class="etn-logo" src="<?= h($D['logo']) ?>" alt="Savannah Explorers"><?php endif; ?>
    <div class="etn-kicker"><?= h($T['kicker']) ?></div>
    <h1><?= h($D['title']) ?></h1>
    <?php if ($D['subtitle'] !== ''): ?><p class="etn-sub"><?= h($D['subtitle']) ?></p><?php endif; ?>
    <div class="etn-dur"><?= h($D['duration']) ?></div>
  </header>

  <h2><?= h($T['brief']) ?></h2>
  <?= iti_doc_paras($D['intro']) ?>
  <table>
    <tr><th><?= h($T['day']) ?></th><th><?= h($T['route']) ?></th><th><?= h($T['overnight']) ?></th><th><?= h($T['board']) ?></th></tr>
    <?php foreach ($D['days'] as $d): ?>
      <tr><td class="etn-n"><?= (int)$d['n'] ?></td><td><?= h($d['title']) ?></td><td><?= $d['lodge'] !== '' ? h($d['lodge']) : '—' ?></td><td><?= h(ucfirst($d['meals'])) ?></td></tr>
    <?php endforeach; ?>
  </table>

  <?php if ($D['prices']): ?>
    <h2><?= h($T['prices']) ?></h2>
    <table style="max-width:460px">
      <tr><th><?= h($T['group']) ?></th><th><?= h($T['pp']) ?></th></tr>
      <?php foreach ($D['prices'] as $pr): ?>
        <tr><td><?= h($pr['label'] ?? '') ?></td><td><?= h(isset($pr['price']) ? number_format((float)$pr['price'], 0, ',', '.') . ' ' . ($pr['currency'] ?? 'USD') : '') ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php if ($D['price_notes'] !== ''): ?><div class="etn-sans" style="font-size:14px"><?= iti_doc_paras($D['price_notes']) ?></div><?php endif; ?>
  <?php endif; ?>

  <?php if ($D['incl']): ?>
    <h2><?= h($T['incl']) ?></h2>
    <ul><?php foreach ($D['incl'] as $x): ?><li><?= h($x) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <?php if ($D['excl']): ?>
    <h2><?= h($T['excl']) ?></h2>
    <ul><?php foreach ($D['excl'] as $x): ?><li><?= h($x) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>

  <h2><?= h($T['program']) ?></h2>
  <?php foreach ($D['days'] as $d): ?>
    <section class="etn-day">
      <div class="etn-dayno"><?= h($T['day']) ?> <?= (int)$d['n'] ?></div>
      <h3><?= h($d['title']) ?></h3>
      <div class="etn-meta">
        <?php if ($d['duration'] !== ''): ?><span><?= h($T['transfer']) ?>: <?= h($T['about']) ?> <?= h($d['duration']) ?></span><?php endif; ?>
        <span><?= h($T['meals']) ?>: <?= h($d['meals']) ?></span>
      </div>
      <?= iti_doc_paras($d['narrative']) ?>
      <?php if ($d['dest_desc'] !== ''): ?><div class="etn-dest"><?= iti_doc_paras($d['dest_desc']) ?></div><?php endif; ?>
      <?php if ($d['activities']): ?><div class="etn-acts"><?= h($T['activities']) ?>: <?= h(implode(' · ', $d['activities'])) ?></div><?php endif; ?>
      <?php if ($d['lodge'] !== ''): ?>
        <div class="etn-box">
          <div class="etn-box-lbl"><?= h($T['overnight']) ?></div>
          <div class="etn-box-name"><?= h($d['lodge']) ?></div>
          <?= iti_doc_paras($d['lodge_desc']) ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <h2><?= h($T['contacts']) ?></h2>
  <table>
    <tr><th><?= h($T['ref']) ?></th><th><?= h($T['phone']) ?></th><th><?= h($T['email']) ?></th></tr>
    <?php foreach ($D['contacts'] as $c): ?>
      <tr><td><?= h($c['name']) ?></td><td><?= h($c['phone']) ?></td><td><?= $c['email'] !== '' ? '<a href="mailto:' . h($c['email']) . '">' . h($c['email']) . '</a>' : '' ?></td></tr>
    <?php endforeach; ?>
  </table>

  <?php if ($D['terms'] !== ''): ?>
    <h2><?= h($T['terms']) ?></h2>
    <div class="etn-terms"><?= strpos($D['terms'], '<') !== false ? iti_sanitize_richtext($D['terms']) : iti_doc_paras($D['terms']) ?></div>
  <?php endif; ?>
</div>
<?php
    return (string)ob_get_clean();
}

/**
 * Full standalone HTML page for the document (public link / internal preview).
 * $bar: optional toolbar HTML shown above the document (languages, actions).
 */
function iti_doc_page(array $D, string $bar = ''): void {
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="<?= h($D['lang']) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($D['title']) ?> — Savannah Explorers</title>
<meta name="robots" content="noindex">
<style>
html,body{margin:0;background:#fff}
.etn-page{padding:22px 32px 60px}
.etn-bar{font-family:"Segoe UI",Roboto,Arial,sans-serif;font-size:13px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;
         margin:0 0 18px;padding:0 0 12px;border-bottom:1px solid #eee;max-width:860px}
.etn-bar a{color:#374151;text-decoration:none;padding:4px 10px;border-radius:14px;font-weight:600}
.etn-bar a.on{background:#C0211B;color:#fff}
.etn-bar .sep{flex:1}
@media (max-width:640px){.etn-page{padding:16px 16px 40px}}
@media print{.etn-bar{display:none}.etn-page{padding:0}}
<?= iti_doc_css() ?>
</style>
</head>
<body>
<div class="etn-page">
<?= $bar ?>
<?= iti_doc_render($D) ?>
</div>
</body>
</html>
<?php
}

/** Language switch links (keeps the other query parameters). */
function iti_doc_lang_bar(string $lang, array $keep, string $extra = ''): string {
    $out = '<div class="etn-bar">';
    foreach (ITI_LANGUAGES as $l) {
        $q = http_build_query(array_merge($keep, ['lang' => $l]));
        $out .= '<a href="?' . h($q) . '"' . ($l === $lang ? ' class="on"' : '') . '>' . strtoupper($l) . '</a>';
    }
    return $out . '<span class="sep"></span>' . $extra . '</div>';
}
