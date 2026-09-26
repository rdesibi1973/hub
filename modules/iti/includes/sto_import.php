<?php
/**
 * sto_import.php — read the STO sample programmes (Wetu "Itinerary" Word exports,
 * e.g. /itineraries/SafariClassic/it/Agenzia/2026-27/STO/*.docx) and turn them into
 * ITI SAMPLE programmes.
 *
 * Wetu export structure (Italian):
 *   Heading1  <TITLE>
 *   "<route> N Giorni / M Notti Numero di Partecipanti …"
 *   Heading2  Introduzione / Prezzo / Incluso / Escluso
 *   Heading2  "Giorno N: <Lodge>, <Place>"   ("Giorno N: Fine dell'itinerario" = last day)
 *     Heading3 Itinerario del Giorno  → transfer line(s), then "Programma: …" = narrative
 *     Heading3 <Destination>          → destination description
 *     Heading3 Pernottamento: <Lodge> → lodge description
 *     Heading3 Attivita'              → one-column table of activities
 *     Heading3 Trattamento            → meal plan text
 *   Heading1  Trasporti …             → end (transport, contacts, T&C are not imported)
 *
 * Style ids are localised (Heading1 / Titolo1 …): they are resolved through the
 * style *names* ("heading 1"). Content inside content controls (w:sdt) is read too.
 * Keep PHP-7 style (no match / arrow functions / str_contains).
 */

const STO_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

/** styleId → 'Heading1'.. from word/styles.xml (language independent). */
function sto_style_levels(ZipArchive $zip): array {
    $xml = $zip->getFromName('word/styles.xml');
    $map = [];
    if ($xml === false) return $map;
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadXML($xml);
    libxml_clear_errors();
    foreach ($dom->getElementsByTagNameNS(STO_W, 'style') as $st) {
        $id = $st->getAttributeNS(STO_W, 'styleId');
        foreach ($st->getElementsByTagNameNS(STO_W, 'name') as $nm) {
            if (preg_match('/^heading\s*(\d)$/i', trim($nm->getAttributeNS(STO_W, 'val')), $m)) $map[$id] = 'Heading' . $m[1];
            elseif (preg_match('/^list paragraph$/i', trim($nm->getAttributeNS(STO_W, 'val'))))  $map[$id] = 'ListParagraph';
            break;
        }
    }
    return $map;
}

/** [style, text] of a w:p (text normalised: nbsp → space, runs of spaces collapsed). */
function sto_para(DOMElement $p, array $styles): array {
    $style = '';
    foreach ($p->getElementsByTagNameNS(STO_W, 'pStyle') as $ps) {
        $style = $ps->getAttributeNS(STO_W, 'val');
        if (isset($styles[$style])) $style = $styles[$style];
        break;
    }
    $txt = '';
    foreach ($p->getElementsByTagNameNS(STO_W, '*') as $n) {
        if ($n->localName === 't')                                $txt .= $n->textContent;
        elseif ($n->localName === 'br' || $n->localName === 'cr') $txt .= "\n";
        elseif ($n->localName === 'tab')                          $txt .= ' ';
    }
    $txt = str_replace("\xC2\xA0", ' ', $txt);
    $txt = trim(preg_replace('/[ \t]+/', ' ', $txt));
    return [$style, $txt];
}

/** Body blocks in order: ['p', style, text] | ['tbl', rows[][]], descending into content controls. */
function sto_walk(DOMNode $node, array $styles, array &$out): void {
    foreach ($node->childNodes as $n) {
        if ($n->nodeType !== XML_ELEMENT_NODE) continue;
        $ln = $n->localName;
        if ($ln === 'p') {
            $pt = sto_para($n, $styles);
            $out[] = ['p', $pt[0], $pt[1]];
        } elseif (in_array($ln, ['sdt', 'sdtContent', 'customXml', 'smartTag'], true)) {
            sto_walk($n, $styles, $out);
        } elseif ($ln === 'tbl') {
            $rows = [];
            foreach ($n->getElementsByTagNameNS(STO_W, 'tr') as $tr) {
                $cells = [];
                foreach ($tr->getElementsByTagNameNS(STO_W, 'tc') as $tc) {
                    $parts = [];
                    foreach ($tc->getElementsByTagNameNS(STO_W, 'p') as $cp) { $pt = sto_para($cp, $styles); $parts[] = $pt[1]; }
                    $cells[] = trim(implode(' ', $parts));
                }
                $rows[] = $cells;
            }
            $out[] = ['tbl', $rows];
        }
    }
}

/** Parse one STO .docx (local path) into a programme array. */
function sto_parse_docx(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Not a valid .docx file.');
    $styles = sto_style_levels($zip);
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) throw new RuntimeException('document.xml missing.');
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadXML($xml);
    libxml_clear_errors();
    $body = $dom->getElementsByTagNameNS(STO_W, 'body')->item(0);
    $blocks = [];
    if ($body) sto_walk($body, $styles, $blocks);

    $prog = ['title' => '', 'route' => '', 'days_n' => null, 'intro' => [], 'include' => [], 'exclude' => [],
             'days' => [], 'dest_desc' => [], 'lodge_desc' => []];
    $sec = null; $sub = null; $di = -1;

    foreach ($blocks as $blk) {
        if ($blk[0] === 'tbl') {
            // Activities: one-column table under "Attivita'".
            if ($sec === 'day' && $sub && $sub[0] === 'act') {
                foreach ($blk[1] as $row) foreach ($row as $c) { if (trim($c) !== '') $prog['days'][$di]['activities'][] = trim($c); }
            }
            continue;
        }
        $st = $blk[1]; $t = $blk[2];
        if ($t === '') continue;

        if ($st === 'Heading1') {
            if ($prog['title'] === '') $prog['title'] = preg_replace('/\s+/', ' ', $t);
            elseif (stripos($t, 'trasporti') === 0) $sec = 'end';
            continue;
        }
        if ($sec === 'end') continue;

        if ($prog['route'] === '' && preg_match('/^(.*?)(\d+)\s+Giorni\s*\/\s*(\d+)\s+Nott/su', $t, $m)) {
            $prog['route']  = trim(preg_replace('/\s+/', ' ', $m[1]));
            $prog['days_n'] = (int)$m[2];
            continue;
        }

        if ($st === 'Heading2') {
            if (preg_match('/^Giorno\s+(\d+)\s*:\s*(.*)$/iu', $t, $m)) {
                $head = trim($m[2]); $lodge = null; $place = null;
                if (!preg_match('/^fine dell/iu', $head)) {
                    $pos = strrpos($head, ',');
                    if ($pos !== false) { $lodge = trim(substr($head, 0, $pos)); $place = trim(substr($head, $pos + 1)); }
                    else                { $lodge = $head; }
                }
                $prog['days'][] = ['n' => (int)$m[1], 'lodge' => $lodge, 'place' => $place, 'end' => $lodge === null,
                                   'transfer' => '', 'narrative' => [], 'dests' => [], 'activities' => [], 'meals' => '',
                                   'in_prog' => false];
                $di = count($prog['days']) - 1;
                $sec = 'day'; $sub = null;
            } else {
                $low = mb_strtolower($t);
                $map = ['introduzione' => 'intro', 'incluso' => 'include', 'escluso' => 'exclude', 'prezzo' => 'price'];
                $sec = isset($map[$low]) ? $map[$low] : 'other';
            }
            continue;
        }

        if ($sec === 'day' && $st === 'Heading3') {
            $low = mb_strtolower($t);
            if (strpos($low, 'itinerario del giorno') === 0)   $sub = ['itin', null];
            elseif (strpos($low, 'pernottamento:') === 0)      $sub = ['lodge', trim(substr($t, strpos($t, ':') + 1))];
            elseif (strpos($low, 'attivit') === 0)             $sub = ['act', null];
            elseif (strpos($low, 'trattamento') === 0)         $sub = ['meal', null];
            else { $sub = ['dest', $t]; $prog['days'][$di]['dests'][] = $t; }
            continue;
        }

        if ($sec === 'intro') {
            // List items keep a "- " marker; the document renders runs of them as a bulleted list.
            if (!preg_match('/^Pasti\s/u', $t)) $prog['intro'][] = ($st === 'ListParagraph' ? '- ' : '') . $t;
        } elseif ($sec === 'include' || $sec === 'exclude') {
            $prog[$sec][] = $t;
        } elseif ($sec === 'day' && $sub) {
            $d =& $prog['days'][$di];
            if ($sub[0] === 'itin') {
                if (stripos($t, 'programma') === 0) $d['in_prog'] = true;
                if (!$d['in_prog']) $d['transfer'] = trim($d['transfer'] . ' ' . $t);
                else                $d['narrative'][] = preg_replace('/^Programma:\s*/iu', '', $t);
            } elseif ($sub[0] === 'dest') {
                $prog['dest_desc'][$sub[1]][] = $t;
            } elseif ($sub[0] === 'lodge') {
                $prog['lodge_desc'][$sub[1]][] = $t;
            } elseif ($sub[0] === 'act') {
                $d['activities'][] = $t;
            } elseif ($sub[0] === 'meal') {
                $d['meals'] = $t;
            }
            unset($d);
        }
    }
    return $prog;
}

/** Meal plan text → [breakfast, lunch, dinner, all_inclusive]. */
function sto_meals(string $txt): array {
    $l = mb_strtolower($txt);
    if (strpos($l, 'all inclusive') !== false)                                   return [1, 1, 1, 1];
    if (strpos($l, 'pensione completa') !== false || preg_match('/\bfb\b/', $l)) return [1, 1, 1, 0];
    if (strpos($l, 'mezza pensione') !== false || preg_match('/\bhb\b/', $l))    return [1, 0, 1, 0];
    if (strpos($l, 'colazione') !== false || strpos($l, 'b&b') !== false)        return [1, 0, 0, 0];
    return [0, 0, 0, 0];
}

/** Day title from the transfer line: "Aeroporto del Kilimanjaro – Arusha 1 ora di trasferimento." → "Aeroporto del Kilimanjaro – Arusha". */
function sto_day_title(array $d): string {
    $t = trim($d['transfer']);
    if ($t !== '') {
        $t = preg_replace('/\s*[\d,.\-]+\s*(ora|ore|minuti|h)\b.*$/iu', '', $t);
        $t = preg_replace('/\s*(circa|con volo interno|con fotosafari.*)$/iu', '', $t);
        $t = trim($t, " .\t");
        if ($t !== '' && mb_strlen($t) <= 120) return $t;
    }
    if (!empty($d['place'])) return $d['place'];
    return !empty($d['end']) ? "Fine dell'itinerario" : '';
}

/** Case/space/punctuation-insensitive key for name matching. */
function sto_key(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/\(.*?\)/u', '', $s);
    $s = preg_replace('/[^a-z0-9]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** name-key → row for lodges / destinations (destinations keyed by every language name). */
function sto_index(PDO $db): array {
    $lodges = []; $dests = [];
    foreach ($db->query('SELECT id, name, destination_id, description_it, description_en FROM iti_lodges WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $lodges[sto_key($r['name'])] = $r;
    }
    foreach ($db->query('SELECT * FROM iti_destinations WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        foreach (['name_en', 'name_it', 'code'] as $f) { if (!empty($r[$f])) $dests[sto_key($r[$f])] = $r; }
    }
    return ['lodges' => $lodges, 'dests' => $dests];
}

function sto_find(array $idx, string $name) {
    $k = sto_key($name);
    if ($k === '') return null;
    if (isset($idx[$k])) return $idx[$k];
    // Loose: one name contains the other ("Marera Mountain View Lodge" ⊂ "Ngorongoro Marera Mountain View Lodge").
    $hit = null;
    foreach ($idx as $key => $row) {
        if (strlen($key) >= 6 && (strpos($key, $k) !== false || strpos($k, $key) !== false)) {
            if ($hit !== null && $hit['id'] !== $row['id']) return null;   // ambiguous
            $hit = $row;
        }
    }
    return $hit;
}

/** Column names of a table (cached) — the live ITI schema differs from the repo SQL. */
function sto_columns(PDO $db, string $table): array {
    static $cache = [];
    if (!isset($cache[$table])) {
        $cache[$table] = [];
        foreach ($db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $c) $cache[$table][$c['Field']] = $c;
    }
    return $cache[$table];
}

/** INSERT only the columns that exist in the live table. */
function sto_insert(PDO $db, string $table, array $row): int {
    $cols = sto_columns($db, $table);
    $row = array_intersect_key($row, $cols);
    $db->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($row)) . '`) VALUES ('
                 . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    return (int)$db->lastInsertId();
}

/** Extra columns used by the import / the new outputs (created lazily). */
function sto_ensure_schema(PDO $db): void {
    foreach (ITI_LANGUAGES as $l) {
        try { $db->exec("ALTER TABLE iti_programs ADD COLUMN intro_{$l} TEXT NULL DEFAULT NULL"); } catch (PDOException $e) {}
    }
    try { $db->exec("ALTER TABLE iti_programs ADD COLUMN source_ref VARCHAR(255) NULL DEFAULT NULL"); } catch (PDOException $e) {}
}

/**
 * Preview (no writes): what the import would create and which lodges/destinations
 * are matched in the ITI database.
 */
function sto_preview(PDO $db, array $prog, string $sourceRef): array {
    $idx = sto_index($db);
    $days = [];
    foreach ($prog['days'] as $d) {
        $lodge = $d['lodge'] ? sto_find($idx['lodges'], $d['lodge']) : null;
        $dest  = $d['place'] ? sto_find($idx['dests'], $d['place']) : null;
        $days[] = ['n' => $d['n'], 'title' => sto_day_title($d), 'lodge' => $d['lodge'], 'lodge_id' => $lodge ? (int)$lodge['id'] : null,
                   'place' => $d['place'], 'dest_id' => $dest ? (int)$dest['id'] : null, 'meals' => $d['meals'],
                   'activities' => $d['activities']];
    }
    $st = $db->prepare('SELECT id FROM iti_programs WHERE source_ref = ? AND program_type = "sample" LIMIT 1');
    try { $st->execute([$sourceRef]); $existing = (int)($st->fetchColumn() ?: 0); } catch (PDOException $e) { $existing = 0; }
    return ['title' => $prog['title'], 'route' => $prog['route'], 'days_n' => $prog['days_n'], 'days' => $days,
            'include' => count($prog['include']), 'exclude' => count($prog['exclude']), 'existing_id' => $existing];
}

/**
 * Create (or replace, when $replace and it was imported before) the SAMPLE programme.
 * Lodge / destination descriptions in $lang are filled only where empty.
 * Returns ['program_id', 'replaced', 'filled' => [...], 'unmatched' => [...]].
 */
function sto_import(PDO $db, array $prog, string $sourceRef, string $lang, bool $replace, string $user): array {
    sto_ensure_schema($db);
    $idx = sto_index($db);
    $out = ['program_id' => 0, 'replaced' => false, 'filled' => [], 'unmatched' => []];

    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT id FROM iti_programs WHERE source_ref = ? AND program_type = "sample" LIMIT 1');
        $st->execute([$sourceRef]);
        $pid = (int)($st->fetchColumn() ?: 0);
        if ($pid && !$replace) throw new RuntimeException('Already imported as programme #' . $pid . ' (tick "replace" to overwrite).');

        $title = preg_replace('/\s*STO\s*\d{4}.*$/i', '', $prog['title']);   // "DUMA SHORT IN ITALIANO STO 2026-27" → "DUMA SHORT IN ITALIANO"
        $title = trim($title) !== '' ? trim($title) : $prog['title'];
        $hdr = [
            'program_type' => 'sample', 'ref_number' => mb_substr($prog['title'], 0, 50),
            'title_en' => $title, 'title_it' => '', 'title_fr' => '', 'title_es' => '', 'title_de' => '',
            'subtitle_en' => '', 'subtitle_it' => '', 'subtitle_fr' => '', 'subtitle_es' => '', 'subtitle_de' => '',
            'duration_days' => max(1, count($prog['days'])), 'pax_adults' => 2, 'pax_children' => 0, 'flights_included' => 0,
            'status' => 'draft', 'display_language' => $lang, 'display_currency' => 'USD', 'created_by' => $user,
            'source_ref' => $sourceRef,
        ];
        $hdr['title_' . $lang]    = $title;
        $hdr['subtitle_' . $lang] = $prog['route'];
        $hdr['intro_' . $lang]    = implode("\n\n", $prog['intro']);

        if ($pid) {
            // Replace: drop days (+ their activities / flights / transfers) and inclusions, rewrite the header.
            foreach (['iti_day_activities', 'iti_day_flights', 'iti_day_transfers'] as $child) {
                $db->prepare('DELETE c FROM ' . $child . ' c JOIN iti_program_days d ON d.id = c.program_day_id WHERE d.program_id = ?')->execute([$pid]);
            }
            $db->prepare('DELETE FROM iti_program_days WHERE program_id = ?')->execute([$pid]);
            $db->prepare('DELETE FROM iti_program_inclusions WHERE program_id = ?')->execute([$pid]);
            $cols = array_intersect_key($hdr, sto_columns($db, 'iti_programs'));
            unset($cols['program_type'], $cols['created_by'], $cols['status']);
            $set = [];
            foreach (array_keys($cols) as $c) $set[] = '`' . $c . '` = ?';
            $db->prepare('UPDATE iti_programs SET ' . implode(', ', $set) . ' WHERE id = ?')->execute(array_merge(array_values($cols), [$pid]));
            $out['replaced'] = true;
        } else {
            $pid = sto_insert($db, 'iti_programs', $hdr);
        }
        $out['program_id'] = $pid;

        // Inclusions / exclusions (custom text in $lang).
        $i = 0;
        foreach (['include' => 'inclusion', 'exclude' => 'exclusion'] as $k => $type) {
            foreach ($prog[$k] as $txt) {
                $txt = mb_substr(preg_replace('/\s*\n\s*/u', ' — ', $txt), 0, 255);
                sto_insert($db, 'iti_program_inclusions', ['program_id' => $pid, 'item_type' => $type, 'text_' . $lang => $txt, 'sort_order' => ++$i]);
            }
        }

        // Descriptions: fill only where empty.
        $fill = function (string $table, $row, array $paras, string $label) use ($db, $lang, &$out) {
            if (!$row || !$paras) return;
            $cur = $db->prepare('SELECT description_' . $lang . ' FROM ' . $table . ' WHERE id = ?');
            $cur->execute([(int)$row['id']]);
            if (trim((string)$cur->fetchColumn()) !== '') return;
            $db->prepare('UPDATE ' . $table . ' SET description_' . $lang . ' = ? WHERE id = ?')->execute([implode("\n\n", $paras), (int)$row['id']]);
            $out['filled'][] = $label;
        };
        foreach ($prog['dest_desc'] as $name => $paras) {
            $d = sto_find($idx['dests'], $name);
            if ($d) $fill('iti_destinations', $d, $paras, 'Destination: ' . $name); else $out['unmatched']['Destination: ' . $name] = true;
        }
        foreach ($prog['lodge_desc'] as $name => $paras) {
            $l = sto_find($idx['lodges'], $name);
            if ($l) $fill('iti_lodges', $l, $paras, 'Lodge: ' . $name); else $out['unmatched']['Lodge: ' . $name] = true;
        }

        // Days.
        $prevLodge = null; $prevLodgeTxt = null;
        foreach ($prog['days'] as $d) {
            $lodge = $d['lodge'] ? sto_find($idx['lodges'], $d['lodge']) : null;
            $dest  = $d['place'] ? sto_find($idx['dests'], $d['place']) : null;
            if ($d['lodge'] && !$lodge) $out['unmatched']['Lodge: ' . $d['lodge']] = true;
            if ($d['place'] && !$dest)  $out['unmatched']['Destination: ' . $d['place']] = true;
            $meals = sto_meals($d['meals']);
            $row = [
                'program_id' => $pid, 'day_number' => $d['n'],
                'day_title_en' => '', 'day_title_it' => '', 'day_title_fr' => '', 'day_title_es' => '', 'day_title_de' => '',
                'narrative_en' => '', 'narrative_it' => '', 'narrative_fr' => '', 'narrative_es' => '', 'narrative_de' => '',
                'start_lodge_id' => $prevLodge, 'start_custom' => $prevLodge ? null : $prevLodgeTxt,
                'destination_id' => $dest ? (int)$dest['id'] : null, 'destination_custom' => $dest ? null : $d['place'],
                'end_lodge_id' => $lodge ? (int)$lodge['id'] : null, 'end_lodge_custom' => $lodge ? null : $d['lodge'],
                'meal_breakfast' => $d['n'] === 1 ? 0 : $meals[0], 'meal_lunch' => $meals[1], 'meal_dinner' => $d['end'] ? 0 : $meals[2],
                'meal_all_inclusive' => $meals[3], 'meal_game_package' => 0,
            ];
            $row['day_title_' . $lang] = mb_substr(sto_day_title($d), 0, 200);
            $row['narrative_' . $lang] = implode("\n\n", $d['narrative']);
            $dayId = sto_insert($db, 'iti_program_days', $row);

            if ($d['transfer'] !== '') {
                sto_insert($db, 'iti_day_transfers', ['program_day_id' => $dayId, 'description' => $d['transfer'], 'sort_order' => 1]);
            }
            foreach ($d['activities'] as $k => $a) {
                sto_insert($db, 'iti_day_activities', ['program_day_id' => $dayId, 'activity_id' => null, 'activity_custom' => mb_substr($a, 0, 255),
                                                       'custom_note_' . $lang => $a, 'sort_order' => $k + 1]);
            }
            $prevLodge = $lodge ? (int)$lodge['id'] : null;
            $prevLodgeTxt = $lodge ? null : $d['lodge'];
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    $out['unmatched'] = array_keys($out['unmatched']);
    return $out;
}
