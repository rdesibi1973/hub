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

static $_iti_pdo = null;

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
        } catch (PDOException $ex) {
            http_response_code(500);
            die('DB connection failed: ' . $ex->getMessage());
        }
    }
    return $_iti_pdo;
}

// ── Costanti ──────────────────────────────────────────────────────────────────
define('ITI_VERSION',    '1.0.0');
define('ITI_BASE_URL',   BASE_URL . '/modules/iti');
define('ITI_MODULE_URL', BASE_URL . '/modules/iti');
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
            ['p.title_en','p.title_it','r.client_name','p.ref_number'],
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
    $sql = 'SELECT p.*, r.client_name
              FROM iti_programs p
              LEFT JOIN iti_requests r ON r.id = p.request_id'
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

function iti_get_program_by_token(string $token): array|false {
    $st = db()->prepare(
        'SELECT * FROM iti_programs WHERE public_token = ? AND is_published = 1'
    );
    $st->execute([$token]);
    return $st->fetch();
}

// ── PROGRAM DAYS ──────────────────────────────────────────────────────────────
function iti_get_days(int $program_id): array {
    $st = db()->prepare(
        'SELECT pd.*,
                sl.name    AS start_lodge_name,
                sd.name_en AS start_dest_name,
                el.name    AS end_lodge_name,
                dest.name_en AS destination_name_en
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
function iti_get_inclusions(int $program_id): array {
    $lang = iti_lang();
    $col  = "COALESCE(NULLIF(pi.text_{$lang},''), NULLIF(si.text_{$lang},''), pi.text_en, si.text_en) AS display_text";
    $st   = db()->prepare(
        "SELECT pi.id, pi.item_type, pi.sort_order, {$col}
           FROM iti_program_inclusions pi
           LEFT JOIN iti_standard_inclusions si ON si.id = pi.standard_inclusion_id
          WHERE pi.program_id = ?
          ORDER BY pi.item_type DESC, pi.sort_order"
    );
    $st->execute([$program_id]);
    return $st->fetchAll();
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
