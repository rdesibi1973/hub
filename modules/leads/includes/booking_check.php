<?php
/**
 * booking_check.php — data for the "booking check" of a confirmed safari: what
 * the booking team had to book (the Calc, grouped into services) and the
 * evidence they saved in Dropbox (invoices/, bookings/).
 *
 * The matching itself (reading invoices in any format, one invoice covering
 * several services) is done by the Cowork skill booking-check, which reads the
 * files from the local Dropbox and writes booking-check.html into the folder.
 *
 *   bc_services()  Calc days → services (accommodation blocks, flights, transfers, activities)
 *   bc_evidence()  files in invoices/ and bookings/ of the folder (and of its GRP folder)
 *   bc_request()   both for a Hub request (Agent API booking_check)
 *
 * Needs calc_reader.php, dropbox_helper.php, booking_service.php (req_folder_path)
 * and calc_service.php (calc_dbx_download). Keep PHP-7 style (no match / arrow functions).
 */
require_once __DIR__ . '/calc_reader.php';

/** Hotel texts of The Orangi Collection lodges: booked in ResRequest, evidence = the "SV" file. */
const BC_OWN_LODGES = '/\b(orangi|marera|kifaru|arusha explorers?)\b/i';

/**
 * ACTIVITY DESC items handled in-house, not booked with a supplier: Maasai visits, Napuro
 * waterfalls and Lake Eyasi activities are arranged by the safari guide, Olduvai is paid
 * with the Ngorongoro permit.
 */
const BC_INTERNAL = '/^(emergency|medi ?vac|amref|lunch ?box|hot lunch|picnic|gates?\b|paid\b|maa?sai|olduvai|oldupai|napuro|(lake )?eyasi|(cathedral )?church visit|(short )?city tour|ok$|-+$)/i';

/** Activities run by our own lodges ("Natural Walk" = Marera Walking and Iraqw families): no supplier booking. */
const BC_OWN_ACTIVITY = '/\b(orangi|marera|kifaru|arusha explorers?)\b|natural walk/i';

/** Hotel text that is not a stay (e.g. the departure day's note). */
const BC_NOT_STAY = '/^(transfer|day ?room|n\/?a|-+)\b/i';

/** Lower-case letters/digits only: "Orangi -FB" and "Orangi-FB" are the same hotel. */
function bc_key(string $s): string {
    return preg_replace('/[^a-z0-9]+/', '', strtolower($s));
}

/**
 * Calc data (calc_read_file()) → services, in date order. Each service:
 *   accommodation: name, check_in, check_out, nights, own (Orangi Collection → ResRequest),
 *                  calc_invoice (INVOICE cells of the block), calc_checked (CHECKED cells), rows
 *   flight:        date, route (PARK/OVERNIGHT label), cost (flight cost column)
 *   transfer / activity: date, text (one ACTIVITY DESC item), route
 * An INVOICE cell sits on the first row of a block; one supplier invoice may cover
 * several services (e.g. "ARUSHA, MARERA & KIFARU - SV").
 */
function bc_services(array $calc): array {
    $out = [];
    $days = $calc['days'];
    $n = count($days);
    $stay = null;
    $close = function () use (&$stay, &$out) {
        if ($stay === null) return;
        $stay['check_out'] = $stay['last'] ? date('Y-m-d', strtotime($stay['last'] . ' +1 day')) : null;
        unset($stay['last'], $stay['key']);
        $out[] = $stay;
        $stay = null;
    };
    foreach ($days as $i => $d) {
        // Accommodation: consecutive nights with the same hotel text. The last day is departure.
        $hotel = trim($d['hotel_text']);
        $isStay = $hotel !== '' && $i < $n - 1 && !preg_match(BC_NOT_STAY, $hotel);
        $key = $isStay ? bc_key($hotel) : '';
        if ($stay !== null && $key !== $stay['key']) $close();
        if ($isStay) {
            if ($stay === null) {
                $stay = ['type' => 'accommodation', 'name' => $hotel, 'check_in' => $d['date'], 'nights' => 0,
                         'own' => (bool)preg_match(BC_OWN_LODGES, $hotel), 'calc_invoice' => [], 'calc_checked' => [],
                         'rows' => [], 'key' => $key, 'last' => null];
            }
            $stay['nights']++;
            $stay['last']   = $d['date'];
            $stay['rows'][] = $d['row'];
            if ($d['invoice'] !== '') $stay['calc_invoice'][] = $d['invoice'];
            if ($d['checked'] !== '') $stay['calc_checked'][] = $d['checked'];
        }
        // Flight: a cost in the FLIGHTS column (0 = included in another leg, still a flight).
        if ($d['flight_cost'] !== null) {
            $out[] = ['type' => 'flight', 'date' => $d['date'], 'route' => $d['label'], 'cost' => $d['flight_cost'], 'rows' => [$d['row']]];
        }
        // Transfers / activities: each "+"-separated ACTIVITY DESC item, in-house items skipped.
        foreach (preg_split('/\s*\+\s*/', $d['activity_desc']) as $item) {
            $item = trim($item);
            if ($item === '' || preg_match(BC_INTERNAL, $item)) continue;
            $isTransfer = (bool)preg_match('/transfer/i', $item);
            $svc = ['type' => $isTransfer ? 'transfer' : 'activity', 'date' => $d['date'],
                    'text' => $item, 'route' => $d['label'], 'rows' => [$d['row']]];
            if (!$isTransfer) $svc['own'] = (bool)preg_match(BC_OWN_ACTIVITY, $item);
            $out[] = $svc;
        }
    }
    $close();
    usort($out, function ($a, $b) {
        $da = $a['type'] === 'accommodation' ? $a['check_in'] : $a['date'];
        $db = $b['type'] === 'accommodation' ? $b['check_in'] : $b['date'];
        return strcmp((string)$da, (string)$db) ?: $a['rows'][0] <=> $b['rows'][0];
    });
    return $out;
}

/**
 * Files saved as evidence in invoices/ and bookings/ (any depth) of $dir; with
 * $groupDir (a GRP member) those of the group folder too, scope 'group'.
 * Each: scope, path (relative to its folder), name, ext, size, old (inside an "old…" sub-folder).
 */
function bc_evidence(string $token, string $dir, string $groupDir = ''): array {
    $out = [];
    $dirs = ['own' => $dir];
    if ($groupDir !== '' && strcasecmp($groupDir, $dir) !== 0) $dirs['group'] = $groupDir;
    foreach ($dirs as $scope => $d) {
        try {
            $list = dropbox_list_recursive($token, $d);
        } catch (RuntimeException $e) {
            continue;
        }
        foreach ($list as $e) {
            if ($e['tag'] !== 'file') continue;
            $parts = explode('/', $e['path']);
            $top = strtolower($parts[0]);
            if (count($parts) < 2 || ($top !== 'invoices' && $top !== 'bookings')) continue;
            $name = (string)end($parts);
            $old = false;
            foreach (array_slice($parts, 1, -1) as $p) { if (stripos($p, 'old') === 0) $old = true; }
            $out[] = ['scope' => $scope, 'path' => $e['path'], 'name' => $name,
                      'ext' => strtolower(pathinfo($name, PATHINFO_EXTENSION)), 'size' => $e['size'], 'old' => $old];
        }
    }
    usort($out, function ($a, $b) { return strcmp($a['scope'] . $a['path'], $b['scope'] . $b['path']); });
    return $out;
}

/**
 * Booking-check data of a Hub request: its Calc (a group calc is read on RECAP
 * unless $sheet is given), the services and the evidence files.
 * @throws InvalidArgumentException (no folder / no Calc / Calc not finalised) | RuntimeException (Dropbox)
 */
function bc_request(PDO $db, int $requestId, string $file = '', string $sheet = ''): array {
    try {
        $calc = calc_read_request($db, $requestId, $file, $sheet);
    } catch (InvalidArgumentException $e) {
        if ($sheet !== '' || stripos($e->getMessage(), 'RECAP') === false) throw $e;
        $calc = calc_read_request($db, $requestId, $file, 'RECAP');
    }
    $st = $db->prepare('SELECT id, customer_name, practice_code, group_folder, status, payment_status FROM requests WHERE id = ?');
    $st->execute([$requestId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);

    $dir = $calc['folder'];
    $groupDir = (!empty($r['group_folder']) && trim($r['group_folder']) !== trim((string)$r['practice_code'])) ? dirname($dir) : '';
    $services = bc_services($calc);
    $evidence = bc_evidence(dropbox_get_access_token(), $dir, $groupDir);

    $count = [];
    foreach ($services as $s) $count[$s['type']] = ($count[$s['type']] ?? 0) + 1;
    $noMark = [];
    foreach ($services as $s) {
        if ($s['type'] === 'accommodation' && !$s['calc_invoice'] && !$s['calc_checked']) {
            $noMark[] = $s['name'] . ' (' . $s['check_in'] . ')';
        }
    }
    unset($calc['days']);   // replaced by the services
    return [
        'request_id'    => (int)$requestId,
        'customer'      => $r['customer_name'] ?? '',
        'status'        => $r['status'] ?? '',
        'payment'       => $r['payment_status'] ?? '',
        'folder'        => $dir,
        'group_folder'  => $groupDir,
        'local_folder'  => 'C:\\Dropbox' . str_replace('/', '\\', $dir),
        'calc'          => $calc,
        'services'      => $services,
        'evidence'      => $evidence,
        'summary'       => ['services' => $count, 'evidence_files' => count($evidence),
                            'stays_without_invoice_or_checked' => $noMark],
    ];
}
