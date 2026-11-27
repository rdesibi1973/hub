<?php
/**
 * grp_member_service.php — status tag on GRP participants' own sub-folders (Roberto, 27 Nov 2026).
 *
 * A participant's sub-folder carries its own tag (…/MarioRossi(Agency-Agent)_PROGRESS). New participants get _PROGRESS
 * at confirmation (bo_grp_member_name()); their PROGRESS → first payment tag goes through the booking gate, which blocks
 * on their own services only (bg_member_scope()). Older participants have no tag: grp_member_tags() gives them one —
 * the tag of their payment_status, else PROGRESS — so that "no tag" is never a way round the gate.
 * Agent API grp_member_tags (dry run unless confirm).
 */

require_once __DIR__ . '/grp_status.php';

/** Folder tag for a payment_status (Deposit → DEPOSIT …), '' when none. */
function gm_tag_from_payment(?string $ps): string {
    $t = strtoupper(str_replace([' ', '_'], '-', trim((string)$ps)));
    return array_key_exists($t, GRP_PAY_RANK) ? $t : '';
}

/**
 * GRP participants (sub-folder inside a group parent, request Booked, not cancelled) whose sub-folder has no status tag:
 * [['request_id', 'customer', 'folder', 'group_folder', 'payment_status', 'tag', 'new_name']].
 */
function gm_untagged_members(PDO $db): array {
    $rows = $db->query("SELECT id, customer_name, practice_code, group_folder, status, payment_status FROM requests
                        WHERE group_folder IS NOT NULL AND group_folder <> '' AND practice_code IS NOT NULL AND practice_code <> ''
                          AND practice_code <> group_folder AND status = 'Booked'
                        ORDER BY group_folder, id")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        if (($r['payment_status'] ?? '') === 'Cancelled') continue;
        [$base, $tag, $ck] = grp_split_tag(trim($r['practice_code']));
        if ($tag !== '') continue;
        $new = gm_tag_from_payment($r['payment_status']) ?: 'PROGRESS';
        $out[] = ['request_id' => (int)$r['id'], 'customer' => $r['customer_name'], 'folder' => $r['practice_code'],
                  'group_folder' => $r['group_folder'], 'group_tag' => grp_split_tag(trim($r['group_folder']))[1],
                  'payment_status' => $r['payment_status'], 'tag' => $new, 'new_name' => $base . '_' . $new . ($ck ? '_CK' : '')];
    }
    return $out;
}

/**
 * Give the untagged participants their tag. $opt: request_ids [] (only these), tags {request_id: TAG} (override the
 * proposal: PROGRESS / PROVISIONAL / DEPOSIT / BALANCE / BALANCE-CASH / PAID), confirm (else dry run).
 * Renames the sub-folder in Dropbox (it keeps its id) and updates practice_code / dropbox_url; payment_status is not
 * touched. → ['dry_run', 'members' [...], 'renamed' [...], 'errors' [...]]
 */
function grp_member_tags(PDO $db, string $token, array $opt = []): array {
    require_once __DIR__ . '/../dropbox_helper.php';
    require_once __DIR__ . '/folder_link_service.php';
    require_once __DIR__ . '/booking_service.php';
    $confirm = !empty($opt['confirm']);
    $only = array_map('intval', (array)($opt['request_ids'] ?? []));
    $over = [];
    foreach ((array)($opt['tags'] ?? []) as $rid => $t) {
        $t = strtoupper(trim((string)$t));
        if (!in_array($t, ['PROGRESS', 'PROVISIONAL', 'DEPOSIT', 'BALANCE', 'BALANCE-CASH', 'PAID'], true)) {
            return ['error' => 'tags: ' . $rid . ' → "' . $t . '" is not PROGRESS / PROVISIONAL / DEPOSIT / BALANCE / BALANCE-CASH / PAID.'];
        }
        $over[(int)$rid] = $t;
    }
    $members = [];
    foreach (gm_untagged_members($db) as $m) {
        if ($only && !in_array($m['request_id'], $only, true)) continue;
        if (isset($over[$m['request_id']])) {
            $m['tag'] = $over[$m['request_id']];
            [$base, , $ck] = grp_split_tag(trim($m['folder']));
            $m['new_name'] = $base . '_' . $m['tag'] . ($ck ? '_CK' : '');
        }
        $members[] = $m;
    }
    $out = ['dry_run' => !$confirm, 'count' => count($members), 'members' => $members, 'renamed' => [], 'errors' => []];
    if (!$confirm) return $out;

    $get = $db->prepare("SELECT * FROM requests WHERE id = ?");
    $upd = $db->prepare("UPDATE requests SET practice_code = ?, dropbox_url = ? WHERE id = ?");
    foreach ($members as $m) {
        try {
            $get->execute([$m['request_id']]);
            $r = folder_heal($db, $token, $get->fetch(PDO::FETCH_ASSOC) ?: []);
            $cur = req_folder_path($r);
            if ($cur === '' || basename($cur) !== $m['folder']) throw new RuntimeException('folder not found where expected (' . ($cur ?: '?') . ')');
            $to = rtrim(dirname($cur), '/') . '/' . $m['new_name'];
            dropbox_move_folder($token, $cur, $to);
            $upd->execute([$m['new_name'], bo_url_from_path($to), $m['request_id']]);
            timeline_log($m['request_id'], 'note', 'GRP participant folder tagged _' . $m['tag'],
                         ['body' => $m['folder'] . ' → ' . $m['new_name'] . ' (participants carry their own status tag, 27 Nov 2026)', 'refs' => ['dropbox_path' => $to]]);
            $out['renamed'][] = ['request_id' => $m['request_id'], 'from' => $m['folder'], 'to' => $m['new_name']];
        } catch (Throwable $e) {
            $out['errors'][] = ['request_id' => $m['request_id'], 'folder' => $m['folder'], 'error' => $e->getMessage()];
        }
    }
    return $out;
}
