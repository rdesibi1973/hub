<?php
/**
 * iti_functions.php
 * Savannah Explorers Hub — Itinerary Builder
 */

// ── Connessione al DB hub ─────────────────────────────────────────────────────
$_iti_config = dirname(__FILE__, 4) . '/includes/config.php';
if (!defined('DB_HOST')) {
    require_once $_iti_config;
}

require_once dirname(__FILE__, 4) . '/includes/timezone.php';

static $_iti_pdo = null;

require_once __DIR__ . '/iti_texts.php';   // translatable texts, day transfers (iti_transfers_replace)

// The Agent API loads this file next to the leads config, which already has db().
if (!function_exists('db')) {
function db(): PDO {
    global $_iti_pdo;
    if ($_iti_pdo === null) {
        try {
            $_iti_pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            hub_db_timezone($_iti_pdo);
        } catch (PDOException $ex) {
            http_response_code(500);
            die('DB connection failed: ' . $ex->getMessage());
        }
    }
    return $_iti_pdo;
}
}

// ── Costanti ──────────────────────────────────────────────────────────────────
define('ITI_VERSION',    '1.0.0');
define('ITI_BASE_URL',   BASE_URL . '/modules/iti');
if (!defined('ITI_MODULE_URL')) define('ITI_MODULE_URL', BASE_URL . '/modules/iti');   // iti_photos.php may define it first
define('ITI_LANGUAGES',  ['en', 'it', 'fr', 'es', 'de']);
define('ITI_CURRENCIES', ['USD', 'EUR']);
define('ITI_BRANDS', [
    'savannah_explorers' => 'Savannah Explorers',
    'orangi_collection'  => 'The Orangi Collection',
]);
define('ITI_PROGRAM_STATUS_BADGE', [
    'draft'     => 'badge-grey',
    'sent'      => 'badge-amber',
    'confirmed' => 'badge-green',
    'cancelled' => 'badge-red',
]);
define('ITI_REQUEST_STATUS_BADGE', [
    'open'      => 'badge-grey',
    'quoted'    => 'badge-amber',
    'confirmed' => 'badge-green',
    'cancelled' => 'badge-red',
]);

// ── Helper: escape HTML ───────────────────────────────────────────────────────
if (!function_exists('h')) {
    function h(?string $val): string {
        return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// ── Helper: lingua corrente ───────────────────────────────────────────────────
function iti_lang(): string {
    return $_SESSION['iti_lang'] ?? 'en';
}

// ── SETTINGS (key/value) ──────────────────────────────────────────────────────
// Cached read of the whole iti_settings table.
function iti_settings_all(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        foreach (db()->query('SELECT skey, svalue FROM iti_settings')->fetchAll() as $r) {
            $cache[$r['skey']] = $r['svalue'];
        }
    } catch (Exception $e) {
        // table missing → return empty, callers fall back to defaults
    }
    return $cache;
}

function iti_setting(string $key, string $default = ''): string {
    $all = iti_settings_all();
    return ($all[$key] ?? '') !== '' ? $all[$key] : $default;
}

function iti_set_setting(string $key, string $value): void {
    db()->prepare(
        'INSERT INTO iti_settings (skey, svalue) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)'
    )->execute([$key, $value]);
}

// ── Sanitizza HTML rich-text (output Quill) ──────────────────────────────────
// Allow-list dei soli tag/attributi prodotti dalla toolbar Quill usata nei T&C.
// Usato al SALVATAGGIO: l'HTML nel DB resta pulito e i render point possono
// stamparlo senza escape. Obbligatorio per l'override per-programma, che è
// editabile da qualsiasi utente (non solo admin).
function iti_sanitize_richtext(string $html): string {
    $html = trim($html);
    if ($html === '') return '';

    // Tag consentiti → attributi consentiti per ciascuno
    $allowed = [
        'p'      => ['class'],
        'br'     => [],
        'strong' => [], 'b' => [],
        'em'     => [], 'i' => [],
        'u'      => [],
        's'      => [], 'strike' => [],
        'ol'     => [], 'ul' => [],
        'li'     => ['class'],
        'a'      => ['href'],
        'span'   => ['style'],
    ];

    // Carica in DOMDocument (wrap per parsing affidabile dei frammenti)
    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $doc->loadHTML(
        '<?xml encoding="UTF-8"><div id="__wrap">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $wrap = $doc->getElementById('__wrap');
    if (!$wrap) return '';

    // Visita ricorsiva: rimuove tag non consentiti (preservando il testo
    // interno), ripulisce gli attributi, neutralizza href pericolosi.
    $clean = function (DOMNode $node) use (&$clean, $allowed, $doc): void {
        // Itera su copia statica: modifichiamo l'albero durante il ciclo
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                // commenti, CDATA, ecc. → via
                $node->removeChild($child);
                continue;
            }
            $tag = strtolower($child->nodeName);

            // Tag pericolosi: rimuovi tag E contenuto (niente unwrap del testo)
            static $drop_with_content = ['script','style','iframe','object','embed','noscript','template','svg','math'];
            if (in_array($tag, $drop_with_content, true)) {
                $node->removeChild($child);
                continue;
            }

            if (!isset($allowed[$tag])) {
                // Altri tag non consentiti: sostituisci con i suoi figli
                // (preserva il testo), poi rimuovi il tag.
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            // Pulisci attributi
            if ($child->attributes !== null) {
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $an = strtolower($attr->nodeName);
                    if (!in_array($an, $allowed[$tag], true)) {
                        $child->removeAttribute($attr->nodeName);
                        continue;
                    }
                    if ($an === 'href') {
                        $href = trim($attr->nodeValue);
                        // Solo http(s), mailto, tel. Blocca javascript:, data:, ecc.
                        if (!preg_match('#^(https?:|mailto:|tel:)#i', $href)) {
                            $child->removeAttribute('href');
                        }
                    }
                    if ($an === 'style') {
                        // Quill usa style solo per color/background su <span>.
                        // Tieni solo quelle due proprietà, scarta il resto.
                        $keep = [];
                        foreach (explode(';', $attr->nodeValue) as $decl) {
                            if (strpos($decl, ':') === false) continue;
                            [$prop, $val] = array_map('trim', explode(':', $decl, 2));
                            $prop = strtolower($prop);
                            if (in_array($prop, ['color', 'background-color', 'background'], true)
                                && preg_match('/^(#[0-9a-f]{3,6}|rgb\([0-9, ]+\)|[a-z]+)$/i', $val)) {
                                $keep[] = $prop . ':' . $val;
                            }
                        }
                        if ($keep) {
                            $child->setAttribute('style', implode(';', $keep));
                        } else {
                            $child->removeAttribute('style');
                        }
                    }
                    if ($an === 'class') {
                        // Tieni solo le classi di allineamento Quill
                        $classes = preg_split('/\s+/', trim($attr->nodeValue));
                        $keep = array_filter($classes, function ($c) {
                            return preg_match('/^ql-(align|indent)-/', $c);
                        });
                        if ($keep) {
                            $child->setAttribute('class', implode(' ', $keep));
                        } else {
                            $child->removeAttribute('class');
                        }
                    }
                }
            }

            // Ricorsione sui figli
            $clean($child);
        }
    };
    $clean($wrap);

    // Serializza solo il contenuto interno del wrap
    $out = '';
    foreach ($wrap->childNodes as $c) {
        $out .= $doc->saveHTML($c);
    }
    return trim($out);
}

// ── Render rich-text (HTML Quill) negli elementi nativi PHPWord ───────────────
// Evita PhpWord\Shared\Html::addHtml (fragile con l'HTML di Quill): converte a
// mano i tag prodotti dalla toolbar in addText/addListItem con run di stile.
// $section: container PhpWord; $html: stringa già sanitizzata; $fontStyle:
// nome stile font registrato (es. 'tcFont'); $paraStyle: array stile paragrafo.
function iti_richtext_to_phpword($section, string $html, string $fontStyle = 'tcFont', array $paraStyle = []): void {
    $html = trim($html);
    if ($html === '') return;

    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $doc->loadHTML(
        '<?xml encoding="UTF-8"><div id="__wrap">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $wrap = $doc->getElementById('__wrap');
    if (!$wrap) {
        // Fallback: trattalo come testo semplice
        foreach (explode("\n", strip_tags($html)) as $line) {
            if (trim($line) !== '') $section->addText(trim($line), $fontStyle, $paraStyle);
        }
        return;
    }

    // Raccoglie i run di testo di un nodo, propagando bold/italic/underline.
    $collectRuns = function (DOMNode $node, array $fmt) use (&$collectRuns): array {
        $runs = [];
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $txt = $child->nodeValue;
                if ($txt !== '') $runs[] = ['text' => $txt, 'fmt' => $fmt];
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) continue;
            $t = strtolower($child->nodeName);
            $nf = $fmt;
            if ($t === 'strong' || $t === 'b') $nf['bold'] = true;
            if ($t === 'em' || $t === 'i')     $nf['italic'] = true;
            if ($t === 'u')                    $nf['underline'] = 'single';
            if ($t === 'br') { $runs[] = ['text' => "\n", 'fmt' => $fmt]; continue; }
            $runs = array_merge($runs, $collectRuns($child, $nf));
        }
        return $runs;
    };

    // Emette un blocco di testo (paragrafo o list item) con i suoi run misti.
    $emitBlock = function (DOMNode $node, $listType = null) use ($section, $collectRuns, $fontStyle, $paraStyle): void {
        $runs = $collectRuns($node, []);
        // Salta blocchi vuoti
        $hasText = false;
        foreach ($runs as $r) { if (trim($r['text']) !== '') { $hasText = true; break; } }
        if (!$hasText) return;

        $pStyle = $paraStyle + ['spaceAfter' => 60, 'lineHeight' => 1.5];
        if ($listType) {
            $listStyle = ['listType' => $listType === 'ol'
                ? \PhpOffice\PhpWord\Style\ListItem::TYPE_NUMBER
                : \PhpOffice\PhpWord\Style\ListItem::TYPE_BULLET_FILLED];
            // PhpWord addListItemRun supporta più run nello stesso item
            $run = $section->addListItemRun(0, $listStyle, $pStyle);
        } else {
            $run = $section->addTextRun($pStyle);
        }
        foreach ($runs as $r) {
            $parts = explode("\n", $r['text']);
            foreach ($parts as $k => $part) {
                if ($part !== '') {
                    $style = [$fontStyle];
                    // merge font style name + inline flags
                    $inline = [];
                    if (!empty($r['fmt']['bold']))      $inline['bold'] = true;
                    if (!empty($r['fmt']['italic']))    $inline['italic'] = true;
                    if (!empty($r['fmt']['underline'])) $inline['underline'] = 'single';
                    $run->addText($part, array_merge(['name' => 'Calibri', 'size' => 9, 'color' => '7A7A7A'], $inline));
                }
                if ($k < count($parts) - 1) $run->addTextBreak();
            }
        }
    };

    // Itera i blocchi top-level
    foreach ($wrap->childNodes as $node) {
        if ($node->nodeType === XML_TEXT_NODE) {
            if (trim($node->nodeValue) !== '') {
                $section->addText(trim($node->nodeValue), $fontStyle, $paraStyle + ['spaceAfter' => 60, 'lineHeight' => 1.5]);
            }
            continue;
        }
        if ($node->nodeType !== XML_ELEMENT_NODE) continue;
        $t = strtolower($node->nodeName);
        if ($t === 'ul' || $t === 'ol') {
            foreach ($node->childNodes as $li) {
                if ($li->nodeType === XML_ELEMENT_NODE && strtolower($li->nodeName) === 'li') {
                    $emitBlock($li, $t);
                }
            }
        } else {
            // p, div, o testo inline sciolto
            $emitBlock($node, null);
        }
    }
}

// ── Helper: campo localizzato ─────────────────────────────────────────────────
function iti_field(array $row, string $field, string $lang = null): string {
    $lang = $lang ?? iti_lang();
    $key  = $field . '_' . $lang;
    if (!empty($row[$key])) return $row[$key];
    return $row[$field . '_en'] ?? '';
}

// ── Helper: etichetta durata ──────────────────────────────────────────────────
function iti_duration_label(int $days, string $lang = null): string {
    $nights = max(0, $days - 1);
    return $days . ' day' . ($days != 1 ? 's' : '')
         . ' / ' . $nights . ' night' . ($nights != 1 ? 's' : '');
}

// ── Helper: campo localizzato con escape HTML ─────────────────────────────────
// Shorthand per h(iti_field($row, $field, $lang))
function iti_h(array $row, string $field, string $lang = null): string {
    return h(iti_field($row, $field, $lang));
}

// ── Helper: formato prezzo ────────────────────────────────────────────────────
function iti_money(?float $amount, string $currency = 'USD'): string {
    if ($amount === null) return '—';
    $symbol = $currency === 'EUR' ? '€' : '$';
    return $symbol . number_format($amount, 2, '.', ',');
}

// ── Helper: UUID v4 ───────────────────────────────────────────────────────────
function iti_uuid(): string {
    $data    = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ── Helper: redirect ──────────────────────────────────────────────────────────
function iti_redirect(string $path): void {
    header('Location: ' . ITI_MODULE_URL . '/' . ltrim($path, '/'));
    exit;
}

// ── Helper: flash message ─────────────────────────────────────────────────────
function iti_flash(string $type, string $msg): void {
    $_SESSION['iti_flash'] = ['type' => $type, 'msg' => $msg];
}

// Alias usato da requests.php / programs.php
function iti_flash_set(string $type, string $msg): void {
    iti_flash($type, $msg);
}

function iti_flash_render(): void {
    if (empty($_SESSION['iti_flash'])) return;
    $f   = $_SESSION['iti_flash'];
    unset($_SESSION['iti_flash']);
    $cls = $f['type'] === 'success' ? 'alert-success' : 'alert-danger';
    echo '<div class="alert ' . $cls . ' alert-dismissible fade show" role="alert">'
       . h($f['msg'])
       . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

// ── DESTINATIONS ──────────────────────────────────────────────────────────────
function iti_get_destinations(bool $active_only = true): array {
    $sql = 'SELECT * FROM iti_destinations'
         . ($active_only ? ' WHERE is_active = 1' : '')
         . ' ORDER BY sort_order, name_en';
    return db()->query($sql)->fetchAll();
}

function iti_get_destination(int $id): array|false {
    $st = db()->prepare('SELECT * FROM iti_destinations WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

// ── LODGES ────────────────────────────────────────────────────────────────────
function iti_get_lodges(int $destination_id = null): array {
    if ($destination_id) {
        $st = db()->prepare(
            'SELECT l.*, d.name_en AS dest_name_en
               FROM iti_lodges l
               JOIN iti_destinations d ON d.id = l.destination_id
              WHERE l.destination_id = ? AND l.is_active = 1
              ORDER BY l.name'
        );
        $st->execute([$destination_id]);
    } else {
        $st = db()->query(
            'SELECT l.*, d.name_en AS dest_name_en
               FROM iti_lodges l
               JOIN iti_destinations d ON d.id = l.destination_id
              WHERE l.is_active = 1
              ORDER BY d.sort_order, l.name'
        );
    }
    return $st->fetchAll();
}

// One lodge (any status) with its destination name; used by lodges.php Edit.
function iti_get_lodge(int $id): array|false {
    $st = db()->prepare(
        'SELECT l.*, d.name_en AS dest_name_en
           FROM iti_lodges l
           LEFT JOIN iti_destinations d ON d.id = l.destination_id
          WHERE l.id = ?'
    );
    $st->execute([$id]);
    return $st->fetch();
}

// ── PROGRAMS ─────────────────────────────────────────────────────────────────
// $filters: ['q' => string, 'status' => string]
function iti_get_programs(string $type = null, array $filters = []): array {
    $where  = [];
    $params = [];
    if ($type) {
        $where[] = 'p.program_type = ?';
        $params[] = $type;
    }
    if (!empty($filters['status'])) {
        $where[] = 'p.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['q'])) {
        iti_search_where($filters['q'],
            ['p.title_en','p.title_it','r.client_name','lr.customer_name','lr.practice_code','p.ref_number'],
            $where, $params);
    }
    if (!empty($filters['ref'])) {
        $where[] = 'p.ref_number LIKE ?';
        $params[] = '%' . $filters['ref'] . '%';
    }
    if (!empty($filters['lang'])) {
        $where[] = 'p.display_language = ?';
        $params[] = $filters['lang'];
    }
    // Client: the ITI request, else the linked Hub leads request (customer + agency).
    iti_ensure_lead_link();
    $sql = 'SELECT p.*, COALESCE(r.client_name, lr.customer_name) AS client_name, la.name AS agent_name
              FROM iti_programs p
              LEFT JOIN iti_requests r ON r.id = p.request_id
              LEFT JOIN requests lr ON lr.id = p.lead_request_id
              LEFT JOIN agents la ON la.id = lr.agent_id'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY p.updated_at DESC';
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function iti_get_program(int $id): array|false {
    $st = db()->prepare(
        'SELECT p.*, r.client_name, r.client_email
           FROM iti_programs p
           LEFT JOIN iti_requests r ON r.id = p.request_id
          WHERE p.id = ?'
    );
    $st->execute([$id]);
    return $st->fetch();
}

// A personal program's public page is always on (its own link, no publishing step) until it is
// cancelled; a sample needs Publish. Same rule in SQL (ITI_PUBLIC_SQL) and in PHP (iti_program_is_public()).
const ITI_PUBLIC_SQL = "(is_published = 1 OR (program_type = 'personal' AND status <> 'cancelled'))";

function iti_program_is_public(array $p): bool {
    return !empty($p['is_published']) || (($p['program_type'] ?? '') === 'personal' && ($p['status'] ?? '') !== 'cancelled');
}

/**
 * Link to the digital itinerary (public page), '' when there is none. A personal program gets
 * its token the first time the link is needed (Hub page, PDF, Word, Agent API).
 */
function iti_public_url(array &$p): string {
    if (!iti_program_is_public($p)) return '';
    if (empty($p['public_token'])) {
        db()->prepare('UPDATE iti_programs SET public_token = COALESCE(public_token, UUID()) WHERE id = ?')->execute([(int)$p['id']]);
        $st = db()->prepare('SELECT public_token FROM iti_programs WHERE id = ?');
        $st->execute([(int)$p['id']]);
        $p['public_token'] = (string)$st->fetchColumn();
    }
    return ITI_MODULE_URL . '/itinerary.php?token=' . $p['public_token'];
}

function iti_get_program_by_token(string $token): array|false {
    $st = db()->prepare(
        'SELECT * FROM iti_programs WHERE public_token = ? AND ' . ITI_PUBLIC_SQL
    );
    $st->execute([$token]);
    return $st->fetch();
}

// ── PROGRAM DAYS ──────────────────────────────────────────────────────────────
function iti_get_days(int $program_id): array {
    $st = db()->prepare(
        'SELECT pd.*,
                sl.name    AS start_lodge_name,
                sl.latitude  AS start_lodge_lat,
                sl.longitude AS start_lodge_lng,
                sd.name_en AS start_dest_name,
                sd.latitude  AS start_dest_lat,
                sd.longitude AS start_dest_lng,
                el.name    AS end_lodge_name,
                el.latitude  AS end_lodge_lat,
                el.longitude AS end_lodge_lng,
                dest.name_en AS destination_name_en,
                dest.latitude  AS dest_lat,
                dest.longitude AS dest_lng
           FROM iti_program_days pd
           LEFT JOIN iti_lodges       sl   ON sl.id   = pd.start_lodge_id
           LEFT JOIN iti_destinations sd   ON sd.id   = pd.start_destination_id
           LEFT JOIN iti_lodges       el   ON el.id   = pd.end_lodge_id
           LEFT JOIN iti_destinations dest ON dest.id = pd.destination_id
          WHERE pd.program_id = ?
          ORDER BY pd.day_number'
    );
    $st->execute([$program_id]);
    return $st->fetchAll();
}

/**
 * Returns the best display name for the starting point of a day row.
 * Priority: lodge name > destination name > custom text > null
 */
function iti_start_display_name(array $day): ?string {
    if (!empty($day['start_lodge_name']))  return $day['start_lodge_name'];
    if (!empty($day['start_dest_name']))   return $day['start_dest_name'];
    if (!empty($day['start_custom']))      return $day['start_custom'];
    return null;
}

/**
 * Ordered geo-points for the itinerary map, one per overnight stop.
 * For each day it prefers the end-lodge coordinates, falling back to the
 * day's destination centre. Days with no coordinates are skipped, and
 * consecutive stops at the same spot (multi-night) are merged into one point.
 * Returns: [ ['day'=>int, 'name'=>string, 'lat'=>float, 'lng'=>float], ... ]
 */
function iti_get_program_map_points(array $days): array {
    $points = [];
    foreach ($days as $d) {
        $lat = $lng = null; $name = '';
        if ($d['end_lodge_lat'] !== null && $d['end_lodge_lng'] !== null) {
            $lat = (float)$d['end_lodge_lat'];
            $lng = (float)$d['end_lodge_lng'];
            $name = $d['end_lodge_name'] ?: ($d['destination_name_en'] ?? '');
        } elseif (($d['dest_lat'] ?? null) !== null && ($d['dest_lng'] ?? null) !== null) {
            $lat = (float)$d['dest_lat'];
            $lng = (float)$d['dest_lng'];
            $name = $d['destination_name_en'] ?: ($d['end_lodge_name'] ?? '');
        }
        if ($lat === null || $lng === null) continue;

        // Merge consecutive stops at the same coordinates (multi-night stays)
        $last = end($points);
        if ($last && abs($last['lat'] - $lat) < 0.0001 && abs($last['lng'] - $lng) < 0.0001) {
            continue;
        }
        $points[] = [
            'day'  => (int)$d['day_number'],
            'name' => $name,
            'lat'  => $lat,
            'lng'  => $lng,
        ];
    }
    return $points;
}

/**
 * Collapse map points that share the same coordinates into a single marker,
 * combining their stop numbers (e.g. returning to a lodge on day 2 and day 4
 * yields one marker labelled "2 & 4" instead of two overlapping pins).
 *
 * Input:  the ordered points from iti_get_program_map_points() (1-based
 *         numbering follows their order in the array).
 * Returns: [ ['nums'=>[2,4], 'label'=>'2 & 4', 'name'=>string,
 *            'lat'=>float, 'lng'=>float], ... ] in first-visit order.
 *
 * The route polyline should still be drawn from the full point list so the
 * out-and-back leg to a repeated stop remains visible.
 */
function iti_group_map_points(array $points): array {
    $groups = [];
    $index  = []; // "lat,lng" (rounded) => group position
    foreach (array_values($points) as $i => $p) {
        $num = $i + 1;
        $key = round((float)$p['lat'], 4) . ',' . round((float)$p['lng'], 4);
        if (isset($index[$key])) {
            $groups[$index[$key]]['nums'][] = $num;
        } else {
            $index[$key] = count($groups);
            $groups[] = [
                'nums' => [$num],
                'name' => (string)($p['name'] ?? ''),
                'lat'  => (float)$p['lat'],
                'lng'  => (float)$p['lng'],
            ];
        }
    }
    foreach ($groups as &$g) {
        $g['label'] = implode(' & ', $g['nums']);
    }
    unset($g);
    return $groups;
}

/** Great-circle (straight-line) distance in km between two lat/lng points. */
function iti_haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/** Known airports used for the trip start/end pins: IATA => [lat, lng, name]. */
function iti_map_airports(): array {
    return [
        'JRO' => [-3.4294, 37.0745, 'Kilimanjaro International Airport'],
        'ARK' => [-3.3677, 36.6333, 'Arusha Airport'],
        'ZNZ' => [-6.2220, 39.2249, 'Zanzibar Airport'],
        'DAR' => [-6.8781, 39.2026, 'Dar es Salaam Airport'],
        'MWZ' => [-2.4444, 32.9327, 'Mwanza Airport'],
        'SEU' => [-2.4581, 34.8222, 'Seronera Airstrip'],
        'LKY' => [-3.3763, 35.8183, 'Lake Manyara Airport'],
    ];
}

/**
 * Identify an airport from an explicit IATA code and/or free text (transfer
 * description, flight label). Returns ['code','name','lat','lng'] or null.
 */
function iti_match_airport(?string $text, ?string $code = null): ?array {
    $A = iti_map_airports();
    $mk = fn(string $c) => ['code' => $c, 'name' => $A[$c][2], 'lat' => $A[$c][0], 'lng' => $A[$c][1]];
    if ($code) {
        $c = strtoupper(trim($code));
        if (isset($A[$c])) return $mk($c);
    }
    if ($text === null || $text === '') return null;
    if (preg_match_all('/\b([A-Z]{3})\b/', $text, $m)) {
        foreach ($m[1] as $c) if (isset($A[$c])) return $mk($c);
    }
    $low = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
    $kw = [
        'kilimanjaro'   => 'JRO',
        'arusha airport'=> 'ARK', 'arusha airstrip' => 'ARK',
        'zanzibar'      => 'ZNZ', 'abeid amani' => 'ZNZ',
        'dar es salaam' => 'DAR', 'julius nyerere' => 'DAR', 'nyerere' => 'DAR',
        'mwanza'        => 'MWZ',
        'seronera'      => 'SEU',
        'manyara airport'=> 'LKY', 'manyara airstrip' => 'LKY',
    ];
    foreach ($kw as $k => $c) if (strpos($low, $k) !== false) return $mk($c);
    return null;
}

/**
 * Find the arrival ('from') or departure ('to') airport of a day, scanning its
 * transfers (free-text descriptions) then its flights (route codes / text).
 * Returns ['code','name','lat','lng'] or null.
 */
function iti_day_airport(array $day, string $dir): ?array {
    $tr = iti_get_day_transfers((int)$day['id']);
    if ($tr) {
        $row  = $dir === 'from' ? reset($tr) : end($tr);
        $desc = (string)($row['description'] ?? '');
        // Split on a directional/dash separator; take the origin or destination half.
        $parts = preg_split('/\s*(?:→|=>|->|–|—|\bto\b|\ba\b)\s*/ui', $desc, 2);
        $seg   = $dir === 'from' ? ($parts[0] ?? $desc) : ($parts[count($parts) - 1] ?? $desc);
        $hit   = iti_match_airport($seg) ?? iti_match_airport($desc);
        if ($hit) return $hit;
    }
    $fl = iti_get_day_flights((int)$day['id']);
    if ($fl) {
        $row   = $dir === 'from' ? reset($fl) : end($fl);
        $code  = $dir === 'from' ? ($row['from_code'] ?? null) : ($row['to_code'] ?? null);
        $label = $dir === 'from' ? ($row['from_airport'] ?? '') : ($row['to_airport'] ?? '');
        if ($label === '' || $label === null) $label = (string)($row['flight_custom'] ?? $row['flight_label'] ?? '');
        $hit = iti_match_airport($label, $code);
        if ($hit) return $hit;
    }
    return null;
}

/**
 * Full itinerary map data: airport start/end pins + numbered overnight stops.
 * The airport is derived from the first/last day's transfers or flights; when
 * none is found it falls back to the day's start / destination coordinates.
 *
 * Returns:
 *   ['route'   => ordered points  ['role'=>'start'|'stop'|'end','num'=>?int,
 *                                  'name'=>str,'lat'=>float,'lng'=>float,'code'=>?str],
 *    'markers' => grouped pins     ['lat','lng','name','label'=>'2 & 4'|'','airport'=>bool],
 *    'legend'  => ordered rows     ['role','num'=>?,'name','code'=>?,'dist'=>?float(km)] ]
 */
function iti_get_program_map(array $days): array {
    $days  = array_values($days);
    $stops = iti_get_program_map_points($days);
    $route = [];

    if ($days) {
        $first = $days[0];
        $start = iti_day_airport($first, 'from');
        if (!$start) {
            if (($first['start_lodge_lat'] ?? null) !== null) {
                $start = ['name' => $first['start_lodge_name'], 'lat' => (float)$first['start_lodge_lat'], 'lng' => (float)$first['start_lodge_lng'], 'code' => null];
            } elseif (($first['start_dest_lat'] ?? null) !== null) {
                $start = ['name' => $first['start_dest_name'], 'lat' => (float)$first['start_dest_lat'], 'lng' => (float)$first['start_dest_lng'], 'code' => null];
            }
        }
        if ($start && (!$stops || abs($start['lat'] - $stops[0]['lat']) > 0.0005 || abs($start['lng'] - $stops[0]['lng']) > 0.0005)) {
            $route[] = ['role' => 'start'] + $start;
        }
    }

    $num = 0;
    foreach ($stops as $s) {
        $num++;
        $route[] = ['role' => 'stop', 'num' => $num, 'name' => $s['name'], 'lat' => (float)$s['lat'], 'lng' => (float)$s['lng'], 'code' => null];
    }

    if ($days) {
        $last = $days[count($days) - 1];
        $end  = iti_day_airport($last, 'to');
        if (!$end && ($last['dest_lat'] ?? null) !== null) {
            $end = ['name' => $last['destination_name_en'], 'lat' => (float)$last['dest_lat'], 'lng' => (float)$last['dest_lng'], 'code' => null];
        }
        $lastStop = $stops ? $stops[count($stops) - 1] : null;
        if ($end && (!$lastStop || abs($end['lat'] - $lastStop['lat']) > 0.0005 || abs($end['lng'] - $lastStop['lng']) > 0.0005)) {
            $route[] = ['role' => 'end'] + $end;
        }
    }

    // Markers: group by coordinate so overlapping stops share a pin ("2 & 4")
    // and an airport used for both arrival and departure shows only once.
    $markers = []; $mi = [];
    foreach ($route as $p) {
        $key = round($p['lat'], 4) . ',' . round($p['lng'], 4);
        if (isset($mi[$key])) {
            if ($p['role'] === 'stop') $markers[$mi[$key]]['nums'][] = $p['num'];
            else                       $markers[$mi[$key]]['airport'] = true;
        } else {
            $mi[$key] = count($markers);
            $markers[] = [
                'lat'     => $p['lat'],
                'lng'     => $p['lng'],
                'name'    => $p['name'],
                'nums'    => $p['role'] === 'stop' ? [$p['num']] : [],
                'airport' => $p['role'] !== 'stop',
            ];
        }
    }
    foreach ($markers as &$m) { $m['label'] = implode(' & ', $m['nums']); }
    unset($m);

    // Legend rows carry the straight-line leg distance from the previous point.
    $legend = []; $prev = null;
    foreach ($route as $p) {
        $legend[] = [
            'role' => $p['role'],
            'num'  => $p['num'] ?? null,
            'name' => $p['name'],
            'code' => $p['code'] ?? null,
            'dist' => $prev ? iti_haversine($prev['lat'], $prev['lng'], $p['lat'], $p['lng']) : null,
        ];
        $prev = $p;
    }

    return ['route' => $route, 'markers' => $markers, 'legend' => $legend];
}

// ── PRICES ────────────────────────────────────────────────────────────────────
function iti_get_prices(int $program_id): array {
    $st = db()->prepare(
        "SELECT * FROM iti_program_prices
          WHERE program_id = ?
          ORDER BY FIELD(price_category,'rack','sto','stospec')"
    );
    $st->execute([$program_id]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['price_category']] = $r;
    }
    return $out;
}

// ── INCLUSIONS ────────────────────────────────────────────────────────────────
// Each row carries its own texts (text_xx, program override / custom text), the
// linked standard texts (std_xx) and display_text in the current language.
function iti_get_inclusions(int $program_id): array {
    $lang = iti_lang();
    $cols = "COALESCE(NULLIF(pi.text_{$lang},''), NULLIF(si.text_{$lang},''), NULLIF(pi.text_en,''), si.text_en) AS display_text";
    foreach (ITI_LANGUAGES as $l) {
        $cols .= ", pi.text_{$l}, si.text_{$l} AS std_{$l}";
    }
    $st   = db()->prepare(
        "SELECT pi.id, pi.item_type, pi.sort_order, pi.standard_inclusion_id, {$cols}
           FROM iti_program_inclusions pi
           LEFT JOIN iti_standard_inclusions si ON si.id = pi.standard_inclusion_id
          WHERE pi.program_id = ?
          ORDER BY pi.item_type DESC, pi.sort_order"
    );
    $st->execute([$program_id]);
    return $st->fetchAll();
}

// ── DUPLICATE ────────────────────────────────────────────────────────────────
// Live column names of a table (cached); [] if the table does not exist.
// Copies are built from SHOW COLUMNS so columns added later are not forgotten.
function iti_table_columns(string $table): array {
    static $cache = array();
    if (!isset($cache[$table])) {
        $cache[$table] = array();
        try {
            foreach (db()->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cache[$table][] = $c['Field'];
            }
        } catch (PDOException $e) {
            $cache[$table] = array();
        }
    }
    return $cache[$table];
}

// INSERT ... SELECT one table's rows where $fk_col = $old_fk, re-parented to $new_fk.
// $set overrides columns with fixed values; id / timestamps are left to their defaults.
// Returns [old_id => new_id].
function iti_copy_rows(string $table, string $fk_col, int $old_fk, int $new_fk, array $set = array()): array {
    $db   = db();
    $cols = iti_table_columns($table);
    if (!$cols) return array();
    $skip = array('id', 'created_at', 'updated_at', $fk_col);
    $copy = array();
    foreach ($cols as $c) {
        if (!in_array($c, $skip, true) && !array_key_exists($c, $set)) $copy[] = $c;
    }
    $set_cols = array();
    foreach (array_keys($set) as $c) {
        if (in_array($c, $cols, true)) $set_cols[] = $c;
    }

    $ins_cols = array_merge(array($fk_col), $set_cols, $copy);
    $sel      = array_merge(array('?'), array_fill(0, count($set_cols), '?'), array_map(function ($c) { return '`' . $c . '`'; }, $copy));
    $ins      = $db->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $ins_cols) . '`) SELECT ' . implode(',', $sel)
                           . ' FROM `' . $table . '` WHERE id = ?');
    $params = array($new_fk);
    foreach ($set_cols as $c) $params[] = $set[$c];

    $ids = $db->prepare('SELECT id FROM `' . $table . '` WHERE `' . $fk_col . '` = ? ORDER BY id');
    $ids->execute(array($old_fk));
    $map = array();
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $old_id) {
        $ins->execute(array_merge($params, array((int)$old_id)));
        $map[(int)$old_id] = (int)$db->lastInsertId();
    }
    return $map;
}

// Full copy of a program: header, days (+ activities, flights, transfers), prices,
// supplements, discounts, inclusions and the dedicated T&C. All-or-nothing.
// $set overrides header columns (e.g. request_id, display_language). Returns the new id.
function iti_duplicate_program(int $src_id, string $dest_type, string $created_by, array $set = array()): int {
    $db  = db();
    $src = iti_get_program($src_id);
    if (!$src) throw new RuntimeException('Program #' . $src_id . ' not found.');

    $hdr = array_merge(array(
        'program_type'      => $dest_type,
        'sample_program_id' => $src_id,
        'title_en'          => trim(($src['title_en'] ?? '') . ' (copy)'),
        'status'            => 'draft',
        'created_by'        => $created_by,
        'request_id'        => null,
        'lead_request_id'   => null,
        'is_published'      => 0,
        'public_token'      => null,
        'published_at'      => null,
    ), $set);

    $db->beginTransaction();
    try {
        // Header: same technique as the child tables, keyed on the row's own id.
        $cols = iti_table_columns('iti_programs');
        $copy = array(); $set_cols = array(); $params = array();
        foreach ($cols as $c) {
            if (in_array($c, array('id', 'created_at', 'updated_at'), true)) continue;
            if (array_key_exists($c, $hdr)) { $set_cols[] = $c; $params[] = $hdr[$c]; }
            else $copy[] = $c;
        }
        $sel = array_merge(array_fill(0, count($set_cols), '?'), array_map(function ($c) { return '`' . $c . '`'; }, $copy));
        $params[] = $src_id;
        $db->prepare('INSERT INTO iti_programs (`' . implode('`,`', array_merge($set_cols, $copy)) . '`) SELECT '
                     . implode(',', $sel) . ' FROM iti_programs WHERE id = ?')->execute($params);
        $new_id = (int)$db->lastInsertId();

        $day_map = iti_copy_rows('iti_program_days', 'program_id', $src_id, $new_id);
        foreach ($day_map as $old_day => $new_day) {
            foreach (array('iti_day_activities', 'iti_day_flights', 'iti_day_transfers') as $t) {
                iti_copy_rows($t, 'program_day_id', $old_day, $new_day);
            }
        }
        foreach (array('iti_program_prices', 'iti_price_supplements', 'iti_price_discounts', 'iti_program_inclusions') as $t) {
            iti_copy_rows($t, 'program_id', $src_id, $new_id);
        }

        // Dedicated T&C: copy it and point the new program at its own copy.
        $tc_map = iti_copy_rows('iti_terms_conditions', 'program_id', $src_id, $new_id);
        if (!empty($src['terms_id']) && isset($tc_map[(int)$src['terms_id']]) && !array_key_exists('terms_id', $set)) {
            $db->prepare('UPDATE iti_programs SET terms_id = ? WHERE id = ?')->execute(array($tc_map[(int)$src['terms_id']], $new_id));
        }

        $db->commit();
        return $new_id;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// ── LINK TO HUB LEADS REQUESTS ───────────────────────────────────────────────
// iti_programs.lead_request_id → requests.id (migration 062, also created lazily here).
function iti_ensure_lead_link(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = db();
    if ($db->query("SHOW COLUMNS FROM iti_programs LIKE 'lead_request_id'")->fetch()) return;
    $db->exec('ALTER TABLE iti_programs ADD COLUMN lead_request_id INT NULL DEFAULT NULL, ADD KEY idx_prog_lead_request (lead_request_id)');
    try {
        $db->exec('ALTER TABLE iti_programs ADD CONSTRAINT fk_prog_lead_request FOREIGN KEY (lead_request_id) REFERENCES requests (id) ON DELETE SET NULL');
    } catch (PDOException $e) {
        error_log('iti_ensure_lead_link: FK not created: ' . $e->getMessage());
    }
}

// Hub leads request (id, customer_name, period, pax) or false.
function iti_get_lead_request(int $id) {
    if ($id <= 0) return false;
    $st = db()->prepare('SELECT id, customer_name, period, pax, status FROM requests WHERE id = ?');
    $st->execute(array($id));
    return $st->fetch();
}

// Ref. number of a client programme = the start of the request's Word / Calc file names,
// e.g. "02_BRACHELENTE(GoWorld-Roberto)". The number comes from $file when it is such a
// file (the Calc of a final programme), else from the highest "NN_" file in the request's
// Dropbox folder (needs the leads Dropbox helpers + booking_service loaded), else "01".
// Null when the request has no practice code.
function iti_lead_ref_number(int $lead_id, string $file = '') {
    if ($lead_id <= 0) return null;
    $st = db()->prepare('SELECT id, practice_code, group_folder, dropbox_url FROM requests WHERE id = ?');
    $st->execute(array($lead_id));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $practice = $r ? trim((string)$r['practice_code']) : '';
    if ($practice === '') return null;

    $num = '';
    if ($file !== '' && preg_match('/^(\d{1,3})_/', basename($file), $m)) $num = $m[1];
    if ($num === '' && function_exists('dropbox_list_files') && function_exists('req_folder_path') && defined('DROPBOX_REFRESH_TOKEN')) {
        try {
            $dir = req_folder_path($r);
            if ($dir !== '') {
                $max = 0;
                foreach (dropbox_list_files(dropbox_get_access_token(), $dir) as $fn) {
                    if (preg_match('/^(\d{1,3})_/', $fn, $m) && (int)$m[1] > $max) { $max = (int)$m[1]; $num = $m[1]; }
                }
            }
        } catch (Exception $e) {
            error_log('iti_lead_ref_number #' . $lead_id . ': ' . $e->getMessage());
        }
    }
    if ($num === '') $num = '01';
    return mb_substr(str_pad($num, 2, '0', STR_PAD_LEFT) . '_' . $practice, 0, 50);   // same cap as sto_import
}

// ── FINAL PROGRAMME (confirmed booking) — data model ─────────────────────────
// Migration 063, also created lazily here: the live ITI schema differs from the
// repo SQL, so each column / table is checked before it is added (MySQL on
// BlueHost: no IF NOT EXISTS on ADD COLUMN). DDL commits implicitly: never call
// this inside a transaction.
if (!defined('ITI_MEAL_BASIS')) {
    define('ITI_MEAL_BASIS', array('BB' => 'Bed & Breakfast', 'HB' => 'Half Board', 'FB' => 'Full Board', 'AI' => 'All Inclusive'));
}
const ITI_FINAL_SCHEMA_VERSION = '1';

// ADD COLUMN unless it exists. True when it was added.
function iti_add_column(string $table, string $col, string $ddl): bool {
    $db = db();
    if ($db->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $db->quote($col))->fetch()) return false;
    $db->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $col . '` ' . $ddl);
    return true;
}

// CREATE TABLE unless it exists; foreign keys are added one by one and only
// logged when they fail (e.g. a live id column with another type).
function iti_create_table(string $table, string $body, array $fks = array()): void {
    $db = db();
    if ($db->query('SHOW TABLES LIKE ' . $db->quote($table))->fetch()) return;
    $db->exec('CREATE TABLE `' . $table . '` (' . $body . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    foreach ($fks as $fk) {
        try {
            $db->exec('ALTER TABLE `' . $table . '` ADD ' . $fk);
        } catch (PDOException $e) {
            error_log('iti_create_table ' . $table . ': FK not created: ' . $e->getMessage());
        }
    }
}

// Columns of the client document, added once if missing: teenagers (under 16) of a program next to
// adults / children (under 12); the room type of a night ("1 Double + 1 Twin", shown with the lodge);
// "beach / relax stay" destinations, whose consecutive days in one hotel are shown as one block.
// Call it outside transactions (ALTER TABLE commits) and before iti_table_columns() caches the lists.
function iti_ensure_doc_columns(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        iti_add_column('iti_programs', 'pax_teens', 'TINYINT NOT NULL DEFAULT 0');
        iti_add_column('iti_program_days', 'room_type', 'VARCHAR(100) NULL DEFAULT NULL');
        iti_add_column('iti_day_flights', 'flight_no', 'VARCHAR(20) NULL DEFAULT NULL');   // "UI 403": vouchers, guide, client docs
        if (iti_add_column('iti_destinations', 'is_beach_stay', 'TINYINT(1) NOT NULL DEFAULT 0')) {
            // First run: the coast and islands (not Stone Town, a sightseeing stop, nor the airports).
            db()->exec("UPDATE iti_destinations SET is_beach_stay = 1
                         WHERE CONCAT_WS(' ', name_en, region, code) REGEXP 'zanzibar|pemba|mafia|pangani'
                           AND name_en NOT LIKE '%stone town%' AND name_en NOT LIKE '%airport%' AND COALESCE(region, '') <> 'Airports'");
        }
    } catch (PDOException $e) {
        error_log('iti_ensure_doc_columns: ' . $e->getMessage());
    }
}

function iti_ensure_final_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    iti_ensure_doc_columns();   // own check, not tied to final_schema_version
    if (iti_setting('final_schema_version') === ITI_FINAL_SCHEMA_VERSION) return;
    $db = db();
    iti_ensure_lead_link();

    // Programme: proposal vs final (built from the booking's Calc), versions.
    iti_add_column('iti_programs', 'stage', "ENUM('proposal','final') NOT NULL DEFAULT 'proposal'");
    iti_add_column('iti_programs', 'start_date', 'DATE NULL DEFAULT NULL');
    iti_add_column('iti_programs', 'hub_program_code', 'VARCHAR(80) NULL DEFAULT NULL');
    iti_add_column('iti_programs', 'source_calc_path', 'VARCHAR(512) NULL DEFAULT NULL');
    iti_add_column('iti_programs', 'source_calc_rev', 'VARCHAR(64) NULL DEFAULT NULL');
    iti_add_column('iti_programs', 'generated_at', 'DATETIME NULL DEFAULT NULL');
    iti_add_column('iti_programs', 'generated_by', 'INT NULL DEFAULT NULL');
    iti_add_column('iti_programs', 'superseded_by', 'INT UNSIGNED NULL DEFAULT NULL');
    iti_add_column('iti_programs', 'superseded_at', 'DATETIME NULL DEFAULT NULL');
    foreach (array('ADD KEY idx_prog_lead_stage (lead_request_id, stage)', 'ADD KEY idx_prog_code (hub_program_code)') as $k) {
        try { $db->exec('ALTER TABLE iti_programs ' . $k); } catch (PDOException $e) { /* already there */ }
    }

    // Per-night booking state, from the Calc INVOICE / CHECKED columns.
    iti_add_column('iti_program_days', 'booking_ref', 'VARCHAR(100) NULL DEFAULT NULL');
    iti_add_column('iti_program_days', 'booked_status', 'VARCHAR(40) NULL DEFAULT NULL');
    iti_add_column('iti_program_days', 'booked_by', 'VARCHAR(60) NULL DEFAULT NULL');
    iti_add_column('iti_program_days', 'needs_review', 'TINYINT(1) NOT NULL DEFAULT 0');
    iti_add_column('iti_program_days', 'review_note', 'VARCHAR(255) NULL DEFAULT NULL');

    // Supplier contacts (Supplier list / Useful numbers of the final programme).
    $newPhone = iti_add_column('iti_lodges', 'phone', 'VARCHAR(80) NULL DEFAULT NULL');
    iti_add_column('iti_lodges', 'email', 'VARCHAR(160) NULL DEFAULT NULL');
    iti_add_column('iti_lodges', 'address', 'VARCHAR(255) NULL DEFAULT NULL');
    iti_add_column('iti_lodges', 'emergency_phone', 'VARCHAR(80) NULL DEFAULT NULL');
    if ($newPhone) {
        // One-off: copy phone/address from the voucher lodge directory (migration 054),
        // only where its name key matches exactly one lodge.
        try {
            $db->exec("UPDATE iti_lodges l
                         JOIN (SELECT v.id AS vid, MIN(l2.id) AS lid
                                 FROM iti_voucher_lodges v
                                 JOIN iti_lodges l2 ON LOWER(l2.name) LIKE CONCAT('%', v.name_key, '%')
                                WHERE v.is_active = 1
                                GROUP BY v.id HAVING COUNT(*) = 1) m ON m.lid = l.id
                         JOIN iti_voucher_lodges v ON v.id = m.vid
                          SET l.phone = COALESCE(NULLIF(l.phone, ''), v.phone),
                              l.address = COALESCE(NULLIF(l.address, ''), v.address)");
        } catch (PDOException $e) {
            error_log('iti_ensure_final_schema: lodge contacts not copied: ' . $e->getMessage());
        }
    }

    iti_create_table('iti_program_booking',
        'program_id INT UNSIGNED NOT NULL PRIMARY KEY,
         room_config VARCHAR(100) NULL,
         pax_adults TINYINT NOT NULL DEFAULT 0,
         pax_teen TINYINT NOT NULL DEFAULT 0,
         pax_child TINYINT NOT NULL DEFAULT 0,
         arrival_details TEXT NULL,
         departure_details TEXT NULL,
         extra_details TEXT NULL,
         show_prices TINYINT(1) NOT NULL DEFAULT 1,
         price_text VARCHAR(255) NULL,
         updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        array('CONSTRAINT fk_pbk_prog FOREIGN KEY (program_id) REFERENCES iti_programs (id) ON DELETE CASCADE'));

    // Guests from the Calc (rows 43+). Passport numbers are deliberately not stored.
    iti_create_table('iti_program_guests',
        'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         program_id INT UNSIGNED NOT NULL,
         sort_order SMALLINT NOT NULL DEFAULT 0,
         full_name VARCHAR(160) NOT NULL,
         title VARCHAR(10) NULL,
         dob DATE NULL,
         country VARCHAR(80) NULL,
         KEY idx_pg_prog (program_id, sort_order)',
        array('CONSTRAINT fk_pg_prog FOREIGN KEY (program_id) REFERENCES iti_programs (id) ON DELETE CASCADE'));

    // Calc free text → master data. A NULL target = "known text, nothing to show"
    // (e.g. 'emergency' / 'medivac' in ACTIVITY DESC, 'Serengeti' = no transfer).
    iti_create_table('iti_lodge_aliases',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         alias VARCHAR(150) NOT NULL,
         lodge_id INT UNSIGNED NULL,
         meal_basis ENUM('BB','HB','FB','AI') NULL,
         created_by VARCHAR(80) NULL,
         created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
         UNIQUE KEY uq_lodge_alias (alias),
         KEY idx_la_lodge (lodge_id)",
        array('CONSTRAINT fk_la_lodge FOREIGN KEY (lodge_id) REFERENCES iti_lodges (id) ON DELETE CASCADE'));
    iti_create_table('iti_activity_aliases',
        'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         alias VARCHAR(150) NOT NULL,
         activity_id INT UNSIGNED NULL,
         created_by VARCHAR(80) NULL,
         created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
         UNIQUE KEY uq_activity_alias (alias),
         KEY idx_aa_act (activity_id)',
        array('CONSTRAINT fk_aa_act FOREIGN KEY (activity_id) REFERENCES iti_activities (id) ON DELETE CASCADE'));
    iti_create_table('iti_route_aliases',
        'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         alias VARCHAR(150) NOT NULL,
         transfer_route_id INT UNSIGNED NULL,
         flight_route_id INT UNSIGNED NULL,
         created_by VARCHAR(80) NULL,
         created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
         UNIQUE KEY uq_route_alias (alias),
         KEY idx_ra_tr (transfer_route_id),
         KEY idx_ra_fl (flight_route_id)',
        array('CONSTRAINT fk_ra_tr FOREIGN KEY (transfer_route_id) REFERENCES iti_transfer_routes (id) ON DELETE CASCADE',
              'CONSTRAINT fk_ra_fl FOREIGN KEY (flight_route_id) REFERENCES iti_flight_routes (id) ON DELETE CASCADE'));

    try { iti_set_setting('final_schema_version', ITI_FINAL_SCHEMA_VERSION); } catch (Exception $e) { /* retried next time */ }
}

// ── CALC ALIASES ─────────────────────────────────────────────────────────────
// The Calc Excel is free text ("Kifaru ", "Arusha Explorers in HB", "Maasai+Olduvai").
// Rule: never guess — a text without an alias is shown for review and the user's
// mapping is saved as a new alias, so each text is asked once.
const ITI_ALIAS_TYPES = array(
    'lodge'    => array('table' => 'iti_lodge_aliases',    'label' => 'Lodges'),
    'activity' => array('table' => 'iti_activity_aliases', 'label' => 'Activities'),
    'route'    => array('table' => 'iti_route_aliases',    'label' => 'Routes (PARK/OVERNIGHT)'),
);

// Lower-case, trimmed, inner spaces collapsed (non-breaking spaces included).
function iti_alias_norm(string $s): string {
    $s = preg_replace('/[\s\x{00A0}]+/u', ' ', $s);
    return trim(mb_strtolower((string)$s, 'UTF-8'));
}

// ACTIVITY DESC / FEES DESC may join several items with '+' ("Maasai+Olduvai"):
// each part is its own alias.
function iti_alias_parts(string $s): array {
    $out = array();
    foreach (explode('+', $s) as $p) {
        $p = iti_alias_norm($p);
        if ($p !== '' && !in_array($p, $out, true)) $out[] = $p;
    }
    return $out;
}

// A Calc hotel text often ends with its meal basis: "Orangi River Luxury (FB)",
// "Arusha Explorers in HB", "Kifaru - full board". Returns [base text, meal code|null];
// the meal is null (and the text unchanged) when there is no such suffix.
function iti_alias_split_meal(string $text): array {
    $words = array('bed ?(?:&|and) ?breakfast' => 'BB', 'half ?board' => 'HB', 'full ?board' => 'FB', 'all ?inclusive' => 'AI');
    $re = '/^(.*?)[\s,\-–\/]*(?:\(\s*|\bin\s+)?\b(BB|HB|FB|AI|' . implode('|', array_keys($words)) . ')\b\s*\)?\s*$/iu';
    if (!preg_match($re, $text, $m) || trim($m[1]) === '') return array($text, null);
    $meal = strtoupper($m[2]);
    if (!isset(ITI_MEAL_BASIS[$meal])) {
        foreach ($words as $w => $code) { if (preg_match('/^' . $w . '$/i', $m[2])) { $meal = $code; break; } }
    }
    return array(trim($m[1]), isset(ITI_MEAL_BASIS[$meal]) ? $meal : null);
}

/**
 * Best match of a Calc text among $options [id => label], for preselecting the
 * mapping pickers (the user still confirms with "Map"). Word overlap, with
 * "natural" ~ "nature" (common stem of 5+ letters); generic words (lodge, camp…)
 * do not count. Returns the id, or null when nothing is close or two tie.
 */
function iti_alias_suggest(string $text, array $options) {
    static $generic = array('lodge', 'camp', 'hotel', 'tented', 'resort', 'the', 'and', 'in', 'of', 'at', 'di', 'del', 'della', 'e', 'a', 'il', 'la');
    $words = function ($s) use ($generic) {
        $s = iti_alias_norm(preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string)$s));
        return array_values(array_diff(array_unique(explode(' ', $s)), $generic, array('')));
    };
    $same = function ($a, $b) {
        if ($a === $b) return true;
        $n = min(strlen($a), strlen($b));
        if ($n < 4) return false;
        $k = 0;
        while ($k < $n && $a[$k] === $b[$k]) $k++;
        return $k === $n || $k >= 5;     // one is a prefix of the other, or a 5+ letter common stem
    };
    $t = $words($text);
    if (!$t) return null;
    $best = null; $bestScore = 0.0; $tie = false;
    foreach ($options as $id => $label) {
        $o = $words($label);
        if (!$o) continue;
        $hit = 0; $long = false;
        foreach ($t as $w) {
            foreach ($o as $ow) { if ($same($w, $ow)) { $hit++; if (strlen($w) >= 4) $long = true; break; } }
        }
        if (!$hit || !$long) continue;
        $score = $hit / count($t) + 0.01 * $hit / count($o);   // tie-break: fewer extra words
        if ($score > $bestScore + 1e-9) { $best = $id; $bestScore = $score; $tie = false; }
        elseif (abs($score - $bestScore) <= 1e-9) $tie = true;
    }
    return ($best !== null && !$tie && $bestScore >= 0.5) ? $best : null;
}

// The alias row for a Calc text, or null when the text is not mapped yet.
// A row whose target columns are all NULL means "known, nothing to show".
function iti_alias_lookup(string $type, string $text) {
    if (!array_key_exists($type, ITI_ALIAS_TYPES)) return null;
    $alias = iti_alias_norm($text);
    if ($alias === '') return null;
    $st = db()->prepare('SELECT * FROM `' . ITI_ALIAS_TYPES[$type]['table'] . '` WHERE alias = ?');
    $st->execute(array($alias));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

// Insert or replace one alias. $targets: lodge_id | activity_id | transfer_route_id
// + flight_route_id (null / 0 = nothing); $meal only for lodges.
function iti_alias_save(string $type, string $text, array $targets, ?string $meal, string $user): void {
    if (!array_key_exists($type, ITI_ALIAS_TYPES)) throw new InvalidArgumentException('Unknown alias type: ' . $type);
    $alias = iti_alias_norm($text);
    if ($alias === '') throw new InvalidArgumentException('Empty alias.');
    if (mb_strlen($alias) > 150) throw new InvalidArgumentException('Alias longer than 150 characters.');
    $cols = array('lodge' => array('lodge_id'), 'activity' => array('activity_id'),
                  'route' => array('transfer_route_id', 'flight_route_id'));
    $row = array('alias' => $alias);
    foreach ($cols[$type] as $c) {
        $v = isset($targets[$c]) ? (int)$targets[$c] : 0;
        $row[$c] = $v > 0 ? $v : null;
    }
    if ($type === 'lodge') $row['meal_basis'] = ($meal !== null && array_key_exists($meal, ITI_MEAL_BASIS)) ? $meal : null;
    $row['created_by'] = $user;
    $upd = array();
    foreach (array_keys($row) as $c) if ($c !== 'alias') $upd[] = '`' . $c . '` = VALUES(`' . $c . '`)';
    db()->prepare('INSERT INTO `' . ITI_ALIAS_TYPES[$type]['table'] . '` (`' . implode('`,`', array_keys($row)) . '`) VALUES ('
                  . implode(',', array_fill(0, count($row), '?')) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $upd))
        ->execute(array_values($row));
}

// Seed from the texts found in the 27 Calc templates (/itineraries/SafariClassic/it)
// and the TouristTrophy pilot. A lodge / activity alias is created only when its
// name pattern matches exactly ONE active record; existing aliases are never
// touched. Routes are not seeded (labels like 'Serengeti' are ambiguous): they
// are mapped from the review screen. Returns [type, alias, result] rows.
function iti_seed_aliases(string $user): array {
    $db = db();
    $lodges = array(   // alias => [LIKE pattern on iti_lodges.name, meal basis]
        'arusha explorers hb'           => array('%Arusha Explorers%', 'HB'),
        'arusha explorers in hb'        => array('%Arusha Explorers%', 'HB'),
        'arusha explorers-hb'           => array('%Arusha Explorers%', 'HB'),
        'chanya lodge hb'               => array('%Chanya%', 'HB'),
        'chanya lodge in hb'            => array('%Chanya%', 'HB'),
        'planet lodge in hb'            => array('%Planet Lodge%', 'HB'),
        'eileen'                        => array('%Eileen%', null),
        "eileen's tree"                 => array('%Eileen%', null),
        'katikati'                      => array('%Katikati%', null),
        'kifaru'                        => array('%Kifaru%', null),
        'kontiki'                       => array('%Kontiki%', null),
        "lion's paw"                    => array('%Lion%Paw%', null),
        'marera view lodge'             => array('%Marera%', null),
        'natron river camp (wildlands)' => array('%Natron River%', null),
        'oldeani'                       => array('%Oldeani%', null),
        'olea africana'                 => array('%Olea Africana%', null),
        'olea africana lodge'           => array('%Olea Africana%', null),
        'orangi'                        => array('%Orangi%', null),
        'pure migration'                => array('%Pure Migration%', null),
        'pure migration ndutu'          => array('%Pure Migration%', null),
        'roika'                         => array('%Roika%', null),
        'sopa ngorongoro'               => array('%Ngorongoro Sopa%', null),
        'tarangire safari lodge'        => array('Tarangire Safari Lodge', null),
        'tarangirepure lodge'           => array('%Tarangire Pure%', null),
    );
    $activities = array(   // alias => LIKE pattern on name_en / name_it; null = not an activity
        'emergency'                     => null,
        'medivac'                       => null,
        'amref'                         => null,
        'gates'                         => null,
        'mto wa mbu'                    => '%Mto wa Mbu%',
        'mto wa mbu visit'              => '%Mto wa Mbu%',
        'iraqw boma'                    => '%Iraqw%',
        'olduvai'                       => '%Olduvai%',
        'olduvai george'                => '%Olduvai%',
        'maasai'                        => '%Maasai%',
        'marera garden and coffee tour' => '%Coffee%',
        'town tour'                     => '%Town Tour%',
        // Picnic lunch cost line (not included in the previous night's hotel), not an activity.
        'lunch boxes'                   => null,
        'lunch box'                     => null,
    );
    $out = array();
    $exists = function ($type, $alias) { return iti_alias_lookup($type, $alias) !== null; };
    $one = function ($sql, array $params) use ($db) {
        $st = $db->prepare($sql);
        $st->execute($params);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int)$ids[0] : (count($ids) ? -count($ids) : 0);
    };
    foreach ($lodges as $alias => $spec) {
        if ($exists('lodge', $alias)) { $out[] = array('lodge', $alias, 'exists'); continue; }
        $id = $one('SELECT id FROM iti_lodges WHERE is_active = 1 AND name LIKE ?', array($spec[0]));
        if ($id > 0) { iti_alias_save('lodge', $alias, array('lodge_id' => $id), $spec[1], $user); $out[] = array('lodge', $alias, 'added'); }
        else $out[] = array('lodge', $alias, $id < 0 ? (-$id) . ' lodges match — map it by hand' : 'no lodge matches');
    }
    foreach ($activities as $alias => $like) {
        if ($exists('activity', $alias)) { $out[] = array('activity', $alias, 'exists'); continue; }
        if ($like === null) { iti_alias_save('activity', $alias, array(), null, $user); $out[] = array('activity', $alias, 'added (not shown)'); continue; }
        $id = $one('SELECT id FROM iti_activities WHERE is_active = 1 AND (name_en LIKE ? OR name_it LIKE ?)', array($like, $like));
        if ($id > 0) { iti_alias_save('activity', $alias, array('activity_id' => $id), null, $user); $out[] = array('activity', $alias, 'added'); }
        else $out[] = array('activity', $alias, $id < 0 ? (-$id) . ' activities match — map it by hand' : 'no activity matches');
    }
    return $out;
}

// Request view "Clone from Sample": personal copy linked to the ITI request.
function iti_clone_sample_to_personal(int $sample_id, int $request_id, string $price_cat, string $lang, string $currency): int {
    $cu = current_user();
    $sample = iti_get_program($sample_id);
    try {
        return iti_duplicate_program($sample_id, 'personal', $cu['username'] ?? 'system', array(
            'request_id'       => $request_id,
            'title_en'         => $sample['title_en'] ?? '',
            'display_language' => $lang,
            'display_currency' => $currency,
        ));
    } catch (Exception $e) {
        error_log('iti_clone_sample_to_personal(' . $sample_id . '): ' . $e->getMessage());
        return 0;
    }
}

// ── REQUESTS ─────────────────────────────────────────────────────────────────
// $filters: ['q' => string, 'status' => string]
function iti_get_requests(array $filters = []): array {
    $where  = [];
    $params = [];
    if (!empty($filters['status'])) {
        $where[] = 'status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['q'])) {
        iti_search_where($filters['q'],
            ['client_name','client_email','agent_name'],
            $where, $params);
    }
    $sql = 'SELECT * FROM iti_requests'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY created_at DESC';
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function iti_get_request(int $id): array|false {
    $st = db()->prepare('SELECT * FROM iti_requests WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

// ── TERMS & CONDITIONS ────────────────────────────────────────────────────────
function iti_get_terms(bool $active_only = true): array {
    // Only standard library versions (program_id IS NULL); per-program
    // overrides are excluded from the selectable list.
    $sql = 'SELECT * FROM iti_terms_conditions WHERE program_id IS NULL'
         . ($active_only ? ' AND is_active = 1' : '')
         . ' ORDER BY effective_date DESC';
    return db()->query($sql)->fetchAll();
}

function iti_get_term(int $id): array|false {
    $st = db()->prepare('SELECT * FROM iti_terms_conditions WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

// ── ACTIVITIES ────────────────────────────────────────────────────────────────
function iti_get_activities(int $destination_id = null): array {
    if ($destination_id) {
        $st = db()->prepare(
            'SELECT * FROM iti_activities
              WHERE (destination_id = ? OR destination_id IS NULL) AND is_active = 1
              ORDER BY activity_type, name_en'
        );
        $st->execute([$destination_id]);
    } else {
        $st = db()->query(
            'SELECT * FROM iti_activities WHERE is_active = 1 ORDER BY activity_type, name_en'
        );
    }
    return $st->fetchAll();
}

// ── STANDARD INCLUSIONS ───────────────────────────────────────────────────────
function iti_get_standard_inclusions(string $type = null): array {
    if ($type) {
        $st = db()->prepare(
            'SELECT * FROM iti_standard_inclusions WHERE item_type = ? AND is_active = 1 ORDER BY sort_order'
        );
        $st->execute([$type]);
    } else {
        $st = db()->query(
            'SELECT * FROM iti_standard_inclusions WHERE is_active = 1 ORDER BY item_type, sort_order'
        );
    }
    return $st->fetchAll();
}

// ── Costanti lingue (alias per compatibilità) ─────────────────────────────────
if (!defined('ITI_LANGS')) {
    define('ITI_LANGS', ['en', 'it', 'fr', 'es', 'de']);
}
if (!defined('ITI_LANG_LABELS')) {
    define('ITI_LANG_LABELS', [
        'en' => 'English',
        'it' => 'Italiano',
        'fr' => 'Français',
        'es' => 'Español',
        'de' => 'Deutsch',
    ]);
}

// ── Helper: genera <option> tags ──────────────────────────────────────────────
// $options: ['value' => 'Label', ...]  oppure ['value1','value2',...]
/** Returns [id => name_en] map of all active destinations, for use in <select> */
function iti_destinations_map(bool $active_only = true): array {
    $rows = iti_get_destinations($active_only);
    $out  = [];
    foreach ($rows as $d) { $out[$d['id']] = $d['name_en']; }
    return $out;
}

/**
 * Multi-word AND search: each space-separated word must match at least one column.
 * Appends to $where and $params by reference.
 */
function iti_search_where(string $q, array $columns, array &$where, array &$params): void {
    $words = array_filter(array_map('trim', preg_split('/\s+/', $q)));
    foreach ($words as $word) {
        $like    = '%' . $word . '%';
        $clauses = implode(' OR ', array_map(fn($c) => "$c LIKE ?", $columns));
        $where[] = '(' . $clauses . ')';
        foreach ($columns as $_col) { $params[] = $like; }
    }
}

function iti_options(array $options, string|null $selected = '', ?string $placeholder = null): string {
    $selected = (string)($selected ?? '');
    $out = '';
    if ($placeholder !== null) {
        $out .= '<option value="">' . h($placeholder) . '</option>';
    }
    // A "list" array (sequential 0,1,2… keys) uses its values as both value and
    // label. An associative/id-keyed array (e.g. [10 => 'Karatu']) uses the key
    // as the option value — do NOT replace it with the label.
    $is_list = array_keys($options) === range(0, count($options) - 1);
    foreach ($options as $val => $label) {
        if ($is_list) { $val = $label; }
        $sel  = ($val == $selected && $selected !== '') ? ' selected' : '';
        $out .= '<option value="' . h((string)$val) . '"' . $sel . '>' . h((string)$label) . '</option>';
    }
    return $out;
}

// ── Helper: navigazione ITI ───────────────────────────────────────────────────
function iti_nav(string $current = '', array $breadcrumbs = []): void {
    $links = [
        'Dashboard'    => ITI_MODULE_URL . '/index.php',
        'Programs'     => ITI_MODULE_URL . '/programs.php',
        'Requests'     => ITI_MODULE_URL . '/requests.php',
        'Destinations' => ITI_MODULE_URL . '/destinations.php',
        'Lodges'       => ITI_MODULE_URL . '/lodges.php',
        'Transfers'    => ITI_MODULE_URL . '/transfers.php',
        'Activities'   => ITI_MODULE_URL . '/activities.php',
        'Airlines'     => ITI_MODULE_URL . '/airlines.php',
        'Aliases'      => ITI_MODULE_URL . '/aliases.php',
        'Vouchers'     => ITI_MODULE_URL . '/vouchers.php',
        'Settings'     => ITI_MODULE_URL . '/settings.php',
    ];
    echo '<nav class="iti-nav" style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:20px;border-bottom:1px solid var(--grey-lt);padding-bottom:8px;">';
    foreach ($links as $label => $url) {
        $active = ($label === $current)
            ? 'background:var(--red);color:#fff;'
            : 'background:transparent;color:var(--grey-dk);';
        echo '<a href="' . $url . '" style="' . $active
           . 'padding:5px 12px;border-radius:6px;text-decoration:none;font-size:.8rem;font-weight:500;">'
           . h($label) . '</a>';
    }
    echo '</nav>';
    // Breadcrumbs
    if ($breadcrumbs) {
        echo '<div style="font-size:.72rem;color:var(--grey-mid);margin-bottom:16px;">';
        echo '<a href="' . ITI_MODULE_URL . '/index.php" style="color:var(--grey-mid);text-decoration:none;">ITI</a>';
        foreach ($breadcrumbs as $b) {
            echo ' › <a href="' . h($b['url']) . '" style="color:var(--grey-mid);text-decoration:none;">' . h($b['label']) . '</a>';
        }
        if ($current) echo ' › <span style="color:var(--black);">' . h($current) . '</span>';
        echo '</div>';
    }
}

// ── Costanti prezzi ───────────────────────────────────────────────────────────
if (!defined('ITI_PRICE_CATEGORIES')) {
    define('ITI_PRICE_CATEGORIES', [
        'rack'    => 'Rack (Direct clients)',
        'sto'     => 'STO (Standard agents)',
        'stospec' => 'STO Special',
    ]);
}

// ── Costanti status programma ─────────────────────────────────────────────────
if (!defined('ITI_PROGRAM_STATUSES')) {
    define('ITI_PROGRAM_STATUSES', [
        'draft'     => 'Draft',
        'sent'      => 'Sent',
        'confirmed' => 'Confirmed',
        'cancelled' => 'Cancelled',
    ]);
}

// ── Costanti attività ─────────────────────────────────────────────────────────
if (!defined('ITI_ACTIVITY_TYPES')) {
    define('ITI_ACTIVITY_TYPES', [
        'game_drive'     => 'Game Drive',
        'walking_safari' => 'Walking Safari',
        'cultural'       => 'Cultural',
        'boat'           => 'Boat Safari',
        'balloon'        => 'Hot Air Balloon',
        'hiking'         => 'Hiking',
        'beach'          => 'Beach',
        'other'          => 'Other',
    ]);
}

if (!defined('ITI_ACTIVITY_ICONS')) {
    define('ITI_ACTIVITY_ICONS', [
        'game_drive'     => '🦁',
        'walking_safari' => '🥾',
        'cultural'       => '🏛️',
        'boat'           => '⛵',
        'balloon'        => '🎈',
        'hiking'         => '🏔️',
        'beach'          => '🏖️',
        'other'          => '⭐',
    ]);
}


// ── Costanti lodge ────────────────────────────────────────────────────────────
if (!defined('ITI_LODGE_CATEGORIES')) {
    define('ITI_LODGE_CATEGORIES', [
        'budget'      => 'Budget',
        'mid'         => 'Mid-range',
        'luxury'      => 'Luxury',
        'ultra_luxury'=> 'Ultra Luxury',
    ]);
}

if (!defined('ITI_LODGE_TYPES')) {
    define('ITI_LODGE_TYPES', [
        'lodge'       => 'Lodge',
        'tented_camp' => 'Tented Camp',
        'hotel'       => 'Hotel',
        'mobile_camp' => 'Mobile Camp',
        'house'       => 'House',
    ]);
}

if (!defined('ITI_REQUEST_STATUSES')) {
    define('ITI_REQUEST_STATUSES', [
        'open'      => 'Open',
        'quoted'    => 'Quoted',
        'confirmed' => 'Confirmed',
        'cancelled' => 'Cancelled',
    ]);
}

if (!defined('ITI_ROAD_TYPES')) {
    define('ITI_ROAD_TYPES', [
        'tarmac' => 'Tarmac',
        'gravel' => 'Gravel',
        'mixed'  => 'Mixed',
    ]);
}

if (!defined('ITI_FLIGHT_TYPES')) {
    define('ITI_FLIGHT_TYPES', [
        'scheduled' => 'Scheduled',
        'charter'   => 'Charter',
    ]);
}


// ── Alias per compatibilità con program_edit.php ──────────────────────────────
function iti_get_program_days(int $program_id): array {
    return iti_get_days($program_id);
}

function iti_get_day_activities(int $program_day_id): array {
    $st = db()->prepare(
        'SELECT da.*, a.name_en, a.name_it, a.name_fr, a.name_es, a.name_de,
                a.activity_type, a.duration_hours,
                d.name_en AS dest_name_en
           FROM iti_day_activities da
           JOIN iti_activities a ON a.id = da.activity_id
           LEFT JOIN iti_destinations d ON d.id = a.destination_id
          WHERE da.program_day_id = ?
          ORDER BY da.sort_order'
    );
    $st->execute([$program_day_id]);
    return $st->fetchAll();
}

function iti_get_day_flights(int $program_day_id): array {
    $st = db()->prepare(
        'SELECT df.*,
                fr.from_airport, fr.to_airport,
                fr.from_code, fr.to_code, fr.duration_min,
                fr.operator AS route_operator,
                COALESCE(NULLIF(df.airline_company,\'\'), fr.operator) AS operator,
                COALESCE(NULLIF(df.flight_custom,\'\'),
                    CONCAT(fr.from_airport, \' → \', fr.to_airport)) AS flight_label
           FROM iti_day_flights df
           LEFT JOIN iti_flight_routes fr ON fr.id = df.flight_route_id
          WHERE df.program_day_id = ?
          ORDER BY df.sort_order'
    );
    $st->execute([$program_day_id]);
    return $st->fetchAll();
}

function iti_get_day_transfers(int $program_day_id): array {
    $st = db()->prepare(
        'SELECT * FROM iti_day_transfers WHERE program_day_id = ? ORDER BY sort_order, id'
    );
    $st->execute([$program_day_id]);
    return $st->fetchAll();
}

function iti_get_transfer_routes(array $filters = []): array {
    $where  = [];
    $params = [];
    if (isset($filters['active']) && $filters['active'] !== '') {
        $where[] = 'tr.is_active = ?'; $params[] = (int)$filters['active'];
    } else {
        $where[] = 'tr.is_active = 1';
    }
    if (!empty($filters['q'])) {
        iti_search_where($filters['q'],
            ['fd.name_en','td.name_en'],
            $where, $params);
    }
    if (!empty($filters['road_type'])) {
        $where[] = 'tr.road_type = ?'; $params[] = $filters['road_type'];
    }
    $sql = 'SELECT tr.*,
                   fd.name_en AS from_name,
                   td.name_en AS to_name
              FROM iti_transfer_routes tr
              JOIN iti_destinations fd ON fd.id = tr.from_destination
              JOIN iti_destinations td ON td.id = tr.to_destination'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY fd.name_en, td.name_en';
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function iti_get_flight_routes(array $filters = []): array {
    $where  = [];
    $params = [];
    if (isset($filters['active']) && $filters['active'] !== '') {
        $where[] = 'is_active = ?'; $params[] = (int)$filters['active'];
    } else {
        $where[] = 'is_active = 1';
    }
    if (!empty($filters['q'])) {
        iti_search_where($filters['q'],
            ['from_airport','to_airport','operator','from_code','to_code'],
            $where, $params);
    }
    if (!empty($filters['operator'])) {
        $where[] = 'operator = ?'; $params[] = $filters['operator'];
    }
    $sql = 'SELECT * FROM iti_flight_routes'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY from_airport, to_airport';
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

// ── Format a duration in minutes as "1 h 30 min" / "45 min" / "2 h" ──────────
function iti_fmt_duration(int $min): string {
    if ($min <= 0) return '';
    $h = intdiv($min, 60);
    $m = $min % 60;
    $parts = [];
    if ($h > 0) $parts[] = $h . ' h';
    if ($m > 0) $parts[] = $m . ' min';
    return implode(' ', $parts);
}

// ── Build road transfer notes in all 5 languages (server-side) ────────────────
// Mirrors the live JS generator in transfers.php. $fromName/$toName are place
// names; $durMin minutes; $km kilometres (0/null = omit). Returns [lang => text].
function iti_build_transfer_notes(string $fromName, string $toName, int $durMin = 0, ?int $km = 0): array {
    $T = [
        'en' => 'Road transfer from {from} to {to}{km}{time}.',
        'it' => 'Trasferimento su strada da {from} a {to}{km}{time}.',
        'fr' => 'Transfert routier de {from} à {to}{km}{time}.',
        'es' => 'Traslado por carretera de {from} a {to}{km}{time}.',
        'de' => 'Straßentransfer von {from} nach {to}{km}{time}.',
    ];
    $KMW = [
        'en' => ' — approx. {n} km', 'it' => ' — circa {n} km',
        'fr' => ' — environ {n} km', 'es' => ' — aprox. {n} km',
        'de' => ' — ca. {n} km',
    ];
    $fmtTime = function (int $min, string $lang): string {
        if ($min <= 0) return '';
        $h = intdiv($min, 60);
        $m = $min % 60;
        $hUnit = ($lang === 'de') ? 'Std.' : 'h';
        $parts = [];
        if ($h > 0) $parts[] = $h . ' ' . $hUnit;
        if ($m > 0) $parts[] = $m . ' min';
        return ', ' . implode(' ', $parts);
    };
    $out = [];
    $kmVal = (int)$km;
    foreach ($T as $lang => $tpl) {
        if ($fromName === '' || $toName === '') { $out[$lang] = ''; continue; }
        $kmStr = $kmVal > 0 ? str_replace('{n}', (string)$kmVal, $KMW[$lang]) : '';
        $timeStr = $fmtTime($durMin, $lang);
        $out[$lang] = str_replace(
            ['{from}', '{to}', '{km}', '{time}'],
            [$fromName, $toName, $kmStr, $timeStr],
            $tpl
        );
    }
    return $out;
}

// ── Single route getters (for edit/view) ─────────────────────────────────────
function iti_get_transfer_route(int $id): array|false {
    $st = db()->prepare(
        'SELECT tr.*,
                fd.name_en AS from_name,
                td.name_en AS to_name
           FROM iti_transfer_routes tr
           JOIN iti_destinations fd ON fd.id = tr.from_destination
           JOIN iti_destinations td ON td.id = tr.to_destination
          WHERE tr.id = ?
          LIMIT 1'
    );
    $st->execute([$id]);
    return $st->fetch();
}

function iti_get_flight_route(int $id): array|false {
    $st = db()->prepare('SELECT * FROM iti_flight_routes WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch();
}

function iti_get_supplements(int $program_id): array {
    $st = db()->prepare(
        'SELECT * FROM iti_price_supplements WHERE program_id = ? ORDER BY sort_order'
    );
    $st->execute([$program_id]);
    return $st->fetchAll();
}

function iti_get_discounts(int $program_id): array {
    $st = db()->prepare(
        'SELECT * FROM iti_price_discounts WHERE program_id = ? ORDER BY sort_order'
    );
    $st->execute([$program_id]);
    return $st->fetchAll();
}


// ── Alias e funzioni mancanti per program_edit.php ───────────────────────────

function iti_get_program_prices(int $program_id): array {
    return iti_get_prices($program_id);
}

function iti_get_program_inclusions(int $program_id): array {
    return iti_get_inclusions($program_id);
}

// Lodge raggruppati per destinazione: ['dest_name' => [lodge, lodge, ...]]
function iti_lodges_grouped(): array {
    $rows = iti_get_lodges();
    $out  = [];
    foreach ($rows as $l) {
        $dest = $l['dest_name_en'] ?? 'Other';
        $out[$dest][] = $l;
    }
    ksort($out);
    return $out;
}

// Mappa transfer routes: [id => route_row] per lookup veloce
function iti_transfer_routes_map(): array {
    $rows = iti_get_transfer_routes();
    $map  = [];
    foreach ($rows as $r) { $map[$r['id']] = $r; }
    return $map;
}

// Mappa flight routes: [id => route_row]
function iti_flight_routes_map(): array {
    $rows = iti_get_flight_routes();
    $map  = [];
    foreach ($rows as $r) { $map[$r['id']] = $r; }
    return $map;
}

// ── ITI CSS (da iniettare via $extra_css prima di layout_header) ──────────────
function iti_extra_css(): string {
    return '
/* ── ITI MODULE STYLES ───────────────────────────────────────────── */

/* Buttons */
.btn-red     { background:var(--red);      color:#fff; }
.btn-red:hover { background:var(--red-dk); color:#fff; }
.btn-outline { background:var(--white); color:var(--grey-dk); border:1.5px solid var(--grey-lt); }
.btn-outline:hover { border-color:var(--grey-mid); background:var(--off-white); }
.btn-green   { background:var(--green);    color:#fff; }
.btn-green:hover { background:#145530; }
.btn-amber   { background:var(--amber);    color:#fff; }

/* Badges ITI */
.badge-grey  { background:var(--grey-lt);  color:var(--grey-dk); }
.badge-amber { background:var(--amber-lt); color:#7A4F01; }
.badge-green { background:var(--green-lt); color:var(--green); }
.badge-red   { background:var(--red-lt);   color:var(--red-dk); }
.badge-navy  { background:var(--navy-lt);  color:var(--navy); }

/* Page header */
.page-header { display:flex; align-items:flex-start; justify-content:space-between;
               gap:16px; margin-bottom:24px; flex-wrap:wrap; }
.page-header h2 { font-family:"Merriweather",serif; font-size:1.25rem;
                  font-weight:700; color:var(--red-dk); }
.page-header .sub { font-size:.75rem; color:var(--grey-mid); margin-top:3px; }

/* Stat grid */
.stat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr));
             gap:16px; margin-bottom:28px; }
.stat-card { background:var(--white); border-radius:10px;
             box-shadow:0 1px 8px rgba(0,0,0,.08);
             padding:18px 20px; border-top:3px solid var(--grey-lt); }
.stat-card.red   { border-top-color:var(--red); }
.stat-card.green { border-top-color:var(--green); }
.stat-card.amber { border-top-color:var(--amber); }
.stat-card.blue  { border-top-color:var(--navy); }
.stat-label { font-size:.68rem; font-weight:700; text-transform:uppercase;
              letter-spacing:.1em; color:var(--grey-mid); margin-bottom:6px; }
.stat-value { font-family:"Merriweather",serif; font-size:1.8rem;
              font-weight:700; color:var(--black); line-height:1; }
.stat-sub   { font-size:.72rem; color:var(--grey-mid); margin-top:6px; }

/* Table wrap */
.table-wrap { background:var(--white); border-radius:10px;
              box-shadow:0 1px 8px rgba(0,0,0,.08); overflow:hidden; }
.table-wrap table { width:100%; border-collapse:collapse; font-size:.82rem; }
.table-wrap thead th { background:var(--black); color:rgba(255,255,255,.8);
                       padding:10px 16px; text-align:left; font-size:.65rem;
                       font-weight:700; text-transform:uppercase;
                       letter-spacing:.1em; white-space:nowrap; }
.table-wrap tbody td { padding:11px 16px; border-bottom:1px solid var(--grey-lt);
                       color:var(--grey-dk); vertical-align:middle; }
.table-wrap tbody tr:last-child td { border-bottom:none; }
.table-wrap tbody tr:hover td { background:#FAFAFA; }

/* Form card */
.form-card { background:var(--white); border-radius:10px;
             box-shadow:0 1px 8px rgba(0,0,0,.08);
             padding:28px 32px; margin-bottom:24px; }
.form-section-title { font-size:.68rem; font-weight:700; text-transform:uppercase;
                      letter-spacing:.14em; color:var(--grey-mid);
                      padding-bottom:10px; margin-bottom:16px; margin-top:24px;
                      border-bottom:1px solid var(--grey-lt); }
.form-section-title:first-child { margin-top:0; }
.form-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr));
             gap:16px 20px; }
.form-group label { display:block; font-size:.72rem; font-weight:700;
                    text-transform:uppercase; letter-spacing:.08em;
                    color:var(--grey-dk); margin-bottom:6px; }
.form-group input[type=text],
.form-group input[type=number],
.form-group input[type=email],
.form-group input[type=date],
.form-group select,
.form-group textarea { width:100%; padding:9px 12px;
                       border:1.5px solid var(--grey-lt); border-radius:6px;
                       font-family:"Open Sans",sans-serif; font-size:.85rem;
                       color:var(--black); background:var(--white);
                       transition:border-color .15s; }
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus { outline:none; border-color:var(--red); }
.form-group textarea { resize:vertical; min-height:80px; }

/* Empty state */
.empty-state { text-align:center; padding:48px 20px; color:var(--grey-mid); }
.empty-state .icon { font-size:2.5rem; margin-bottom:12px; }
.empty-state p { font-size:.85rem; }

/* Form actions */
.form-actions { display:flex; gap:10px; align-items:center;
                padding-top:20px; margin-top:8px;
                border-top:1px solid var(--grey-lt); }

/* Utility */
.gap-8 { display:flex; gap:8px; align-items:center; }
.text-muted { color:var(--grey-mid); }

/* ITI Nav override */
.iti-nav a:hover { opacity:.85; }
';
}

// ── CONSULTANT / USER BIO (ITI programmes) ────────────────────────────────────
// The "consultant" of a programme is the user whose username == iti_programs.created_by.
// Bio is multilingual (bio_en/it/fr/es/de on the users table) + whatsapp/photo.

/**
 * Load the consultant (owner) of a programme by its created_by username.
 * Returns associative row or null. Safe against the username being empty.
 */
function iti_get_consultant(?string $username): ?array {
    $username = trim((string)$username);
    if ($username === '') return null;
    $st = db()->prepare(
        'SELECT id, username, full_name, email, whatsapp, photo_url,
                bio_en, bio_it, bio_fr, bio_es, bio_de
           FROM users
          WHERE username = ?
          LIMIT 1'
    );
    $st->execute([$username]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Return the consultant's bio HTML for the requested language, with fallback.
 * Order: requested lang → English → first non-empty language → ''.
 */
function iti_consultant_bio(array $consultant, string $lang): string {
    if (!in_array($lang, ITI_LANGS, true)) $lang = 'en';
    $col = 'bio_' . $lang;
    if (!empty($consultant[$col]) && trim((string)$consultant[$col]) !== '') {
        return (string)$consultant[$col];
    }
    if (!empty($consultant['bio_en']) && trim((string)$consultant['bio_en']) !== '') {
        return (string)$consultant['bio_en'];
    }
    foreach (ITI_LANGS as $l) {
        $c = 'bio_' . $l;
        if (!empty($consultant[$c]) && trim((string)$consultant[$c]) !== '') {
            return (string)$consultant[$c];
        }
    }
    return '';
}

/**
 * Localized "Your travel consultant" label for the itinerary consultant block.
 */
function iti_lbl_consultant(string $lang): string {
    $map = [
        'en' => 'Your Travel Consultant',
        'it' => 'Il tuo consulente di viaggio',
        'fr' => 'Votre conseiller voyage',
        'es' => 'Tu asesor de viajes',
        'de' => 'Ihr Reiseberater',
    ];
    return $map[$lang] ?? $map['en'];
}

function iti_lbl_map(string $lang): string {
    $map = [
        'en' => 'Itinerary Map',
        'it' => 'Mappa dell\'itinerario',
        'fr' => 'Carte de l\'itinéraire',
        'es' => 'Mapa del itinerario',
        'de' => 'Reiseroute-Karte',
    ];
    return $map[$lang] ?? $map['en'];
}

function iti_lbl_map_legend(string $lang): string {
    $map = [
        'en' => 'Legend',
        'it' => 'Legenda',
        'fr' => 'Légende',
        'es' => 'Leyenda',
        'de' => 'Legende',
    ];
    return $map[$lang] ?? $map['en'];
}

function iti_lbl_map_start(string $lang): string {
    $map = ['en'=>'Arrival','it'=>'Arrivo','fr'=>'Arrivée','es'=>'Llegada','de'=>'Ankunft'];
    return $map[$lang] ?? $map['en'];
}

function iti_lbl_map_end(string $lang): string {
    $map = ['en'=>'Departure','it'=>'Partenza','fr'=>'Départ','es'=>'Salida','de'=>'Abreise'];
    return $map[$lang] ?? $map['en'];
}

function iti_lbl_map_airdist(string $lang): string {
    $map = [
        'en'=>'Distances shown as the crow flies.',
        'it'=>'Distanze indicative in linea d\'aria.',
        'fr'=>'Distances à vol d\'oiseau.',
        'es'=>'Distancias en línea recta.',
        'de'=>'Entfernungen in Luftlinie.',
    ];
    return $map[$lang] ?? $map['en'];
}

function iti_lbl_map_online(string $lang): string {
    $map = [
        'en' => 'View the interactive map online:',
        'it' => 'Vedi la mappa interattiva online:',
        'fr' => 'Voir la carte interactive en ligne :',
        'es' => 'Ver el mapa interactivo online:',
        'de' => 'Interaktive Karte online ansehen:',
    ];
    return $map[$lang] ?? $map['en'];
}
