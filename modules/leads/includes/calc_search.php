<?php
/**
 * calc_search.php — the q / search filter of get_rates (calc_service.php): every word must be
 * found in one of the columns ("Arusha Zanzibar" → routes with both). LIKE wildcards in the
 * text are taken literally. No DB here (tools/tests/iti_terms_test.php).
 */

/** [SQL condition (or '' for no filter), args] for $q over $cols. */
function calc_search_where(string $q, array $cols): array {
    $words = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY);
    if (!$words || !$cols) return ['', []];
    $groups = []; $args = [];
    foreach ($words as $w) {
        $like = '%' . addcslashes($w, '\\%_') . '%';
        $groups[] = '(' . implode(' OR ', array_map(function ($c) { return $c . ' LIKE ?'; }, $cols)) . ')';
        foreach ($cols as $c) $args[] = $like;
    }
    return ['(' . implode(' AND ', $groups) . ')', $args];
}
