<?php
// hubspot_sync.php — polls HubSpot contacts + tickets, inserts into lead_staging.
//
// Can run as CLI script OR be included as a library:
//   define('HS_INCLUDED', true);
//   require_once __DIR__ . '/hubspot_sync.php';
//   // then call hs_fetch_all($since), hs_insert_lead($db, $lead), etc.
//
// CLI commands:
//   php hubspot_sync.php               normal run
//   php hubspot_sync.php --dry-run     shows what would be staged, inserts nothing
//   php hubspot_sync.php --reset       look back 24h ignoring saved timestamp
//   php hubspot_sync.php --since=7     look back N days
//   php hubspot_sync.php --debug       dump raw contacts + tickets, inserts nothing

// ── Credentials (safe to define here — skipped if already defined by config.php) ──
if (!defined('DB_HOST'))       define('DB_HOST',       'localhost');
if (!defined('DB_NAME'))       define('DB_NAME',       'savannp5_savannah_leads');
if (!defined('DB_USER'))       define('DB_USER',       'savannp5_rdesibi');
if (!defined('DB_PASS'))       define('DB_PASS',       'Savannah2026');
// ── HubSpot token ────────────────────────────────────────────────────────────
// When running as web request, config.php is loaded first by the caller.
// When running as CLI (cron), load config.php directly from this file.
if (!defined('HUBSPOT_TOKEN')) {
    $cfgFile = __DIR__ . '/config.php';
    if (file_exists($cfgFile)) require_once $cfgFile;
}
if (!defined('HUBSPOT_TOKEN')) {
    fwrite(STDERR, "ERROR: HUBSPOT_TOKEN not defined. Check config.php.\n");
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// ── Shared library functions (available when included as HS_INCLUDED)  ──────
// ─────────────────────────────────────────────────────────────────────────────

/** Returns a static PDO connection using the DB_* constants. */
function hs_db(): PDO {
    static $db;
    if ($db) return $db;
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $db;
}

/** Performs a HubSpot API call. POST when $body is provided, GET otherwise. */
function hs_curl(string $url, ?array $body = null): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . HUBSPOT_TOKEN,
        ],
    ];
    if ($body !== null) {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) {
        throw new RuntimeException("HubSpot API HTTP $httpCode: $resp");
    }
    return json_decode($resp, true) ?? [];
}

function hs_map_destination(string $raw): string {
    $r = strtolower($raw);
    if (str_contains($r, 'kilimanjaro'))                                   return 'Kilimanjaro';
    if (str_contains($r, 'meru'))                                          return 'Meru Trekking';
    if (str_contains($r, 'beach')    || str_contains($r, 'mare')
     || str_contains($r, 'zanzibar') || str_contains($r, 'seychell')
     || str_contains($r, 'pemba'))                                         return 'Safari+Beach';
    if (str_contains($r, 'safari')   || str_contains($r, 'tanzania')
     || str_contains($r, 'namibia')  || str_contains($r, 'uganda')
     || str_contains($r, 'rwanda')   || str_contains($r, 'kenya'))         return 'Safari';
    if (str_contains($r, 'tailor')   || str_contains($r, 'personaliz'))   return 'Tailor-made';
    return 'Other';
}

/** Converts a HubSpot date value to milliseconds.
 *  Handles both ms-as-string ("1745867520000") and ISO-8601 ("2026-04-28T19:22:00.000Z"). */
function hs_parse_ms(mixed $val): int {
    if (!$val) return 0;
    $s = (string)$val;
    // Purely numeric → already milliseconds
    if (ctype_digit($s)) return (int)$s;
    // ISO string → strtotime → seconds → ms
    $ts = strtotime($s);
    return $ts ? $ts * 1000 : 0;
}


function hs_normalize(string $s): string {
    return mb_strtolower(trim(preg_replace('/\s+/', ' ', $s)));
}

function hs_jaro_winkler(string $s1, string $s2): float {
    $s1 = hs_normalize($s1); $s2 = hs_normalize($s2);
    $l1 = strlen($s1); $l2 = strlen($s2);
    if ($l1 === 0 && $l2 === 0) return 1.0;
    if ($l1 === 0 || $l2 === 0) return 0.0;
    $matchDist = max((int)floor(max($l1,$l2)/2)-1, 0);
    $s1m = array_fill(0,$l1,false); $s2m = array_fill(0,$l2,false);
    $matches = 0; $transpositions = 0;
    for ($i=0;$i<$l1;$i++) {
        $start=max(0,$i-$matchDist); $end=min($i+$matchDist+1,$l2);
        for ($j=$start;$j<$end;$j++) {
            if ($s2m[$j]||$s1[$i]!==$s2[$j]) continue;
            $s1m[$i]=$s2m[$j]=true; $matches++; break;
        }
    }
    if ($matches===0) return 0.0;
    $k=0;
    for ($i=0;$i<$l1;$i++) {
        if (!$s1m[$i]) continue;
        while (!$s2m[$k]) $k++;
        if ($s1[$i]!==$s2[$k]) $transpositions++;
        $k++;
    }
    $jaro=($matches/$l1+$matches/$l2+($matches-$transpositions/2)/$matches)/3;
    $prefix=0;
    for ($i=0;$i<min(4,$l1,$l2);$i++) { if ($s1[$i]===$s2[$i]) $prefix++; else break; }
    return $jaro + $prefix * 0.1 * (1 - $jaro);
}

/**
 * Checks for duplicates against requests and lead_staging tables.
 * @return array{flag: string, refs: array}
 */
function hs_check_duplicates(PDO $db, string $name, string $email, string $phone, ?string $hsId): array {
    $flag = 'clean'; $refs = [];

    if ($email) {
        $r = $db->prepare("SELECT id, customer_name, created_at FROM requests WHERE email = ? LIMIT 3");
        $r->execute([$email]);
        foreach ($r->fetchAll() as $row) {
            $refs[] = ['table'=>'requests','id'=>$row['id'],'name'=>$row['customer_name'],
                       'match'=>'email','level'=>'definite','created_at'=>$row['created_at']];
            $flag = 'definite';
        }
        if ($flag !== 'definite') {
            $r = $db->prepare("SELECT id, customer_name FROM requests
                                WHERE email IS NULL
                                  AND (notes LIKE ? OR initial_request LIKE ?)
                                  AND created_at >= NOW() - INTERVAL 90 DAY LIMIT 3");
            $r->execute(["%$email%", "%$email%"]);
            foreach ($r->fetchAll() as $row) {
                $refs[] = ['table'=>'requests','id'=>$row['id'],'name'=>$row['customer_name'],
                           'match'=>'email','level'=>'definite'];
                $flag = 'definite';
            }
        }
        $r = $db->prepare("SELECT id, customer_name FROM lead_staging
                            WHERE email = ? AND (hubspot_id IS NULL OR hubspot_id != ?) LIMIT 3");
        $r->execute([$email, $hsId ?? '']);
        foreach ($r->fetchAll() as $row) {
            $refs[] = ['table'=>'staging','id'=>$row['id'],'name'=>$row['customer_name'],
                       'match'=>'email','level'=>'definite'];
            $flag = 'definite';
        }
    }

    if ($phone && strlen($phone) >= 7) {
        $phoneTail = substr(preg_replace('/\D/','',$phone), -7);
        $r = $db->prepare("SELECT id, customer_name FROM requests
                            WHERE notes LIKE ? AND created_at >= NOW() - INTERVAL 90 DAY LIMIT 3");
        $r->execute(["%$phoneTail%"]);
        foreach ($r->fetchAll() as $row) {
            if (!in_array($row['id'], array_column($refs,'id'))) {
                $refs[] = ['table'=>'requests','id'=>$row['id'],'name'=>$row['customer_name'],
                           'match'=>'phone','level'=>'definite'];
                $flag = 'definite';
            }
        }
    }

    $nameTokens = array_filter(explode(' ', hs_normalize($name)));
    if ($flag !== 'definite' && count($nameTokens) >= 2) {
        $all = $db->query("SELECT id, customer_name FROM requests
                            WHERE created_at >= NOW() - INTERVAL 90 DAY")->fetchAll();
        foreach ($all as $row) {
            if (count(array_filter(explode(' ', hs_normalize($row['customer_name'])))) < 2) continue;
            $jw = hs_jaro_winkler($name, $row['customer_name']);
            if ($jw >= 0.93) {
                $level = $jw >= 0.97 ? 'definite' : 'possible';
                $refs[] = ['table'=>'requests','id'=>$row['id'],'name'=>$row['customer_name'],
                           'match'=>'name','level'=>$level];
                if ($level === 'definite') $flag = 'definite';
                elseif ($flag === 'clean') $flag = 'possible';
            }
        }
        $allS = $db->prepare("SELECT id, customer_name FROM lead_staging WHERE hubspot_id != ? OR hubspot_id IS NULL");
        $allS->execute([$hsId ?? '']);
        foreach ($allS->fetchAll() as $row) {
            if (count(array_filter(explode(' ', hs_normalize($row['customer_name'])))) < 2) continue;
            $jw = hs_jaro_winkler($name, $row['customer_name']);
            if ($jw >= 0.93) {
                $level = $jw >= 0.97 ? 'definite' : 'possible';
                $refs[] = ['table'=>'staging','id'=>$row['id'],'name'=>$row['customer_name'],
                           'match'=>'name','level'=>$level];
                if ($flag === 'clean') $flag = 'possible';
            }
        }
    }

    return ['flag' => $flag, 'refs' => array_slice($refs, 0, 5)];
}

/**
 * Fetches HubSpot contacts created since $since (ms timestamp).
 * Returns raw HubSpot results array.
 */
function hs_fetch_contacts(int $since): array {
    $props = [
        // Standard HubSpot
        'firstname', 'lastname', 'email', 'phone', 'mobilephone',
        'createdate', 'hs_analytics_source', 'hs_latest_source',
        // Form reconversion tracking
        'recent_conversion_event_name',  // Name of the last form submitted
        'recent_conversion_date',        // Timestamp of last form submission
        // iBot — English (primary)
        'contact_name',           // Full name from iBot
        'whatsapp_phone_number',  // WhatsApp number
        'ibotdestinations',       // Destinations
        'pax',                    // Number of people
        'ibotperiod',             // Travel period
        'activities',             // Activities
        'duration',               // Duration
        'accommodation_level',    // Accommodation level
        // iBot — Italian (fallback)
        'nome_cognome',
        'numero_whatsapp',
        'ibotdestinazioni',
        'ibotperiodo',
        'attivita',
        'durata',
        'livello_sistemazione',
        // Form / legacy
        'numero_di_persone', 'periodo', 'message', 'destinazioni', 'destinations',
    ];

    // ── Pass 1: brand-new contacts (createdate >= $since) ────────────────────
    $contacts = []; $after = null;
    do {
        $body = [
            'filterGroups' => [[
                'filters' => [[
                    'propertyName' => 'createdate',
                    'operator'     => 'GTE',
                    'value'        => (string)$since,
                ]],
            ]],
            'properties' => $props,
            'sorts'      => [['propertyName' => 'createdate', 'direction' => 'ASCENDING']],
            'limit'      => 100,
        ];
        if ($after) $body['after'] = $after;
        $data     = hs_curl('https://api.hubapi.com/crm/v3/objects/contacts/search', $body);
        $contacts = array_merge($contacts, $data['results'] ?? []);
        $after    = $data['paging']['next']['after'] ?? null;
    } while ($after);

    // ── Pass 2: reconversions — existing contacts who submitted a form since $since ──
    // (contacts whose createdate predates $since but recent_conversion_date >= $since)
    $newIds = array_flip(array_column($contacts, 'id'));
    $after  = null;
    do {
        $body2 = [
            'filterGroups' => [[
                'filters' => [[
                    'propertyName' => 'recent_conversion_date',
                    'operator'     => 'GTE',
                    'value'        => (string)$since,
                ]],
            ]],
            'properties' => $props,
            'sorts'      => [['propertyName' => 'recent_conversion_date', 'direction' => 'ASCENDING']],
            'limit'      => 100,
        ];
        if ($after) $body2['after'] = $after;
        $data = hs_curl('https://api.hubapi.com/crm/v3/objects/contacts/search', $body2);
        foreach ($data['results'] ?? [] as $c) {
            if (!isset($newIds[$c['id']])) {
                $c['_is_reconversion'] = true; // flag for hs_contact_to_lead()
                $contacts[] = $c;
            }
        }
        $after = $data['paging']['next']['after'] ?? null;
    } while ($after);

    return $contacts;
}

/**
 * Fetches HubSpot tickets (iBot conversations) created since $since (ms).
 * For each ticket, resolves the associated contact and builds a normalised lead array.
 * Uses hubspot_id = 't_{ticketId}' to distinguish from contact-based entries.
 */
function hs_fetch_tickets(int $since): array {
    $ticketBody = [
        'filterGroups' => [[
            'filters' => [[
                'propertyName' => 'createdate',
                'operator'     => 'GTE',
                'value'        => (string)$since,
            ]],
        ]],
        'properties' => ['subject','content','createdate','hs_pipeline_stage'],
        'sorts'      => [['propertyName' => 'createdate', 'direction' => 'ASCENDING']],
        'limit'      => 100,
    ];
    $tickets = []; $after = null;
    do {
        if ($after) $ticketBody['after'] = $after;
        $data    = hs_curl('https://api.hubapi.com/crm/v3/objects/tickets/search', $ticketBody);
        $tickets = array_merge($tickets, $data['results'] ?? []);
        $after   = $data['paging']['next']['after'] ?? null;
    } while ($after);

    if (empty($tickets)) return [];

    $contactProps = implode(',', [
        'firstname','lastname','email','phone','mobilephone','createdate',
        'contact_name','whatsapp_phone_number',
        'ibotdestinations','pax','ibotperiod','activities','duration','accommodation_level',
        'nome_cognome','numero_whatsapp',
        'ibotdestinazioni','ibotperiodo','attivita','durata','livello_sistemazione',
        'numero_di_persone','periodo','message','destinazioni','destinations',
    ]);
    $leads = [];

    foreach ($tickets as $ticket) {
        $ticketId = $ticket['id'];
        $tp       = $ticket['properties'];

        // Get contacts associated with this ticket
        try {
            $assoc = hs_curl("https://api.hubapi.com/crm/v3/objects/tickets/{$ticketId}/associations/contacts");
        } catch (RuntimeException $e) {
            continue;
        }
        $contactIds = array_column($assoc['results'] ?? [], 'id');
        if (empty($contactIds)) continue;

        // Fetch the first associated contact's properties
        $contactId = $contactIds[0];
        try {
            $cData = hs_curl("https://api.hubapi.com/crm/v3/objects/contacts/{$contactId}?properties={$contactProps}");
        } catch (RuntimeException $e) {
            continue;
        }
        $cp = $cData['properties'] ?? [];

        // Name — iBot contact_name first, then firstname+lastname
        $contactNameRaw = trim($cp['contact_name'] ?? $cp['nome_cognome'] ?? '');
        $first = trim($cp['firstname'] ?? '');
        $last  = trim($cp['lastname']  ?? '');
        $name  = $contactNameRaw ?: trim("$first $last");
        if (!$name) $name = trim($cp['email'] ?? '');
        if (!$name) continue;

        // Contact / iBot fields with EN→IT fallbacks
        $email      = trim($cp['email'] ?? '');
        $phone      = trim($cp['whatsapp_phone_number'] ?? $cp['numero_whatsapp'] ?? $cp['phone'] ?? $cp['mobilephone'] ?? '');
        $destRaw    = trim($cp['ibotdestinations']    ?? $cp['ibotdestinazioni']    ?? $cp['destinazioni'] ?? $cp['destinations'] ?? '');
        $rawPax     = trim($cp['pax']                 ?? $cp['numero_di_persone']   ?? '');
        $period     = trim($cp['ibotperiod']          ?? $cp['ibotperiodo']         ?? $cp['periodo']      ?? '');
        $activities = trim($cp['activities']          ?? $cp['attivita']            ?? '');
        $duration   = trim($cp['duration']            ?? $cp['durata']              ?? '');
        $accomLevel = trim($cp['accommodation_level'] ?? $cp['livello_sistemazione']?? '');
        $pax        = ($rawPax !== '' && (float)$rawPax > 0) ? (int)round((float)$rawPax) : null;
        $destNorm   = $destRaw ? hs_map_destination(explode(';', $destRaw)[0]) : '';

        // Ticket conversation text
        $ticketContent = trim($tp['content'] ?? $tp['subject'] ?? '');

        // Build initial_request
        $lines = ['--- HubSpot iBot ---'];
        if ($name)        $lines[] = "Name: $name";
        if ($email)       $lines[] = "Email: $email";
        if ($phone)       $lines[] = "WhatsApp/Phone: $phone";
        if ($pax)         $lines[] = "Number of People: $pax";
        if ($period)      $lines[] = "Period: $period";
        if ($destRaw)     $lines[] = "Destinations: " . str_replace(';', ', ', $destRaw);
        if ($activities)  $lines[] = "Activities: $activities";
        if ($duration)    $lines[] = "Duration: $duration";
        if ($accomLevel)  $lines[] = "Accommodation Level: $accomLevel";
        if ($ticketContent) { $lines[] = ''; $lines[] = 'Conversation:'; $lines[] = $ticketContent; }
        $initialRequest = implode("\n", $lines);

        $leads[] = [
            'hubspot_id'      => 't_' . $ticketId,
            'contact_id'      => $contactId,
            'source'          => 'iBot',
            'customer_name'   => $name,
            'email'           => $email,
            'phone'           => $phone,
            'pax'             => $pax,
            'period'          => $period,
            'destination'     => $destNorm,
            'initial_request' => $initialRequest,
            'notes'           => "HubSpot Ticket: $ticketId | Contact: $contactId",
            'raw_data'        => array_merge($cp, ['_ticket' => $tp]),
            'date_created_ms' => hs_parse_ms($tp['createdate'] ?? 0),
        ];
    }
    return $leads;
}

/**
 * Converts a raw HubSpot contact object into a normalised lead array.
 * Returns null if no usable name found.
 */
function hs_contact_to_lead(array $contact): ?array {
    $p    = $contact['properties'];
    $hsId = $contact['id'];
    $isReconversion = !empty($contact['_is_reconversion']);

    // Name — iBot contact_name field first, then firstname+lastname
    $contactNameRaw = trim($p['contact_name'] ?? $p['nome_cognome'] ?? '');
    if ($contactNameRaw) {
        $name = $contactNameRaw;
    } else {
        $first = trim($p['firstname'] ?? '');
        $last  = trim($p['lastname']  ?? '');
        $name  = trim("$first $last");
    }
    if (!$name) $name = trim($p['email'] ?? '');
    if (!$name) return null;

    // Contact fields — WhatsApp preferred over standard phone
    $email = trim($p['email'] ?? '');
    $phone = trim(
        $p['whatsapp_phone_number'] ?? $p['numero_whatsapp'] ??
        $p['phone']                 ?? $p['mobilephone']     ?? ''
    );

    // iBot-specific fields (EN then IT fallback)
    $destRaw   = trim($p['ibotdestinations']    ?? $p['ibotdestinazioni']    ?? $p['destinazioni'] ?? $p['destinations'] ?? '');
    $rawPax    = trim($p['pax']                 ?? $p['numero_di_persone']   ?? '');
    $period    = trim($p['ibotperiod']          ?? $p['ibotperiodo']         ?? $p['periodo']      ?? '');
    $activities= trim($p['activities']          ?? $p['attivita']            ?? '');
    $duration  = trim($p['duration']            ?? $p['durata']              ?? '');
    $accomLevel= trim($p['accommodation_level'] ?? $p['livello_sistemazione']?? '');
    $message   = trim($p['message'] ?? '');

    $pax = ($rawPax !== '' && (float)$rawPax > 0) ? (int)round((float)$rawPax) : null;

    // ── Reconversion: existing contact submitted a new form ──────────────────
    if ($isReconversion) {
        $reconvMs      = hs_parse_ms($p['recent_conversion_date'] ?? 0);
        $formName      = trim($p['recent_conversion_event_name'] ?? 'Website Form');
        // Unique hubspot_id per reconversion event: f_{contactId}_{ms}
        $reconvHsId    = 'f_' . $hsId . '_' . $reconvMs;

        $lines = ['--- HubSpot Form (Reconversion) ---'];
        $lines[] = "Form: $formName";
        if ($name)    $lines[] = "Name: $name";
        if ($email)   $lines[] = "Email: $email";
        if ($phone)   $lines[] = "WhatsApp/Phone: $phone";
        if ($pax)     $lines[] = "Number of People: $pax";
        if ($period)  $lines[] = "Period: $period";
        if ($destRaw) $lines[] = "Destinations: " . str_replace(';', ', ', $destRaw);
        if ($message) { $lines[] = ''; $lines[] = 'Other Info/Requests:'; $lines[] = $message; }

        return [
            'hubspot_id'      => $reconvHsId,
            'contact_id'      => $hsId,
            'source'          => 'Form',
            'customer_name'   => $name,
            'email'           => $email,
            'phone'           => $phone,
            'pax'             => $pax,
            'period'          => $period,
            'destination'     => $destRaw ? hs_map_destination(explode(';', $destRaw)[0]) : '',
            'initial_request' => implode("\n", $lines),
            'notes'           => "HubSpot Reconversion | Contact: $hsId | Form: $formName",
            'raw_data'        => $p,
            'date_created_ms' => $reconvMs ?: hs_parse_ms($p['createdate'] ?? 0),
        ];
    }

    // Source: iBot fields present → iBot; only message → Form
    $hasIbotFields = $destRaw || $period || $activities || $duration || $accomLevel || $contactNameRaw;
    $source        = ($hasIbotFields || (!$message && !$pax)) ? 'iBot' : 'Form';
    // Override: if Form-specific fields present alongside no iBot fields → Form
    if (!$hasIbotFields && ($pax || $message)) $source = 'Form';

    $destNorm = $destRaw ? hs_map_destination(explode(';', $destRaw)[0]) : '';

    // Build initial_request
    $formName = trim($p['recent_conversion_event_name'] ?? '');
    $lines = ["--- HubSpot $source ---"];
    if ($formName)   $lines[] = "Form: $formName";
    if ($name)       $lines[] = "Name: $name";
    if ($email)      $lines[] = "Email: $email";
    if ($phone)      $lines[] = "WhatsApp/Phone: $phone";
    if ($pax)        $lines[] = "Number of People: $pax";
    if ($period)     $lines[] = "Period: $period";
    if ($destRaw)    $lines[] = "Destinations: " . str_replace(';', ', ', $destRaw);
    if ($activities) $lines[] = "Activities: $activities";
    if ($duration)   $lines[] = "Duration: $duration";
    if ($accomLevel) $lines[] = "Accommodation Level: $accomLevel";
    if ($message)  { $lines[] = ''; $lines[] = 'Other Info/Requests:'; $lines[] = $message; }

    return [
        'hubspot_id'      => $hsId,
        'contact_id'      => $hsId,
        'source'          => $source,
        'customer_name'   => $name,
        'email'           => $email,
        'phone'           => $phone,
        'pax'             => $pax,
        'period'          => $period,
        'destination'     => $destNorm,
        'initial_request' => implode("\n", $lines),
        'notes'           => "HubSpot ID: $hsId",
        'raw_data'        => $p,
        'date_created_ms' => hs_parse_ms($p['createdate'] ?? 0),
    ];
}

/**
 * Fetches all new leads (contacts + tickets) since $since ms.
 * Tickets take priority: if iBot creates both a contact and a ticket for the
 * same person, only the ticket lead is returned (richer data, avoids duplicates).
 */
function hs_fetch_all(int $since): array {
    $leads = [];

    // Fetch tickets first so we know which contacts they already cover
    $ticketLeads = hs_fetch_tickets($since);
    $contactsCoveredByTicket = array_flip(array_column($ticketLeads, 'contact_id'));

    foreach (hs_fetch_contacts($since) as $contact) {
        // Skip contacts already represented by a ticket (prevents contact+ticket duplicates)
        if (isset($contactsCoveredByTicket[$contact['id']])) continue;
        $lead = hs_contact_to_lead($contact);
        if ($lead) $leads[] = $lead;
    }
    foreach ($ticketLeads as $lead) {
        $leads[] = $lead;
    }
    return $leads;
}

/**
 * Inserts a single normalised lead into lead_staging with dup check.
 * @return array{status: string, message: string}
 */
function hs_insert_lead(PDO $db, array $lead, bool $dryRun = false): array {
    $hsId = $lead['hubspot_id'];
    $name = $lead['customer_name'];

    $exists = $db->prepare("SELECT id FROM lead_staging WHERE hubspot_id = ? LIMIT 1");
    $exists->execute([$hsId]);
    if ($exists->fetch()) {
        return ['status' => 'skipped', 'message' => "Already staged: $name"];
    }

    $dup = hs_check_duplicates($db, $name, $lead['email'], $lead['phone'], $hsId);

    // Downgrade 'definite' to 'returning' for old matches, map to 'possible' for DB storage
    $dbDupFlag = $dup['flag'];
    if ($dup['flag'] === 'definite') {
        $againstRequests = array_filter($dup['refs'], fn($r) => $r['table'] === 'requests');
        if (!empty($againstRequests)) {
            $refId = array_values($againstRequests)[0]['id'];
            $age   = $db->prepare("SELECT DATEDIFF(NOW(), created_at) AS days FROM requests WHERE id = ?");
            $age->execute([$refId]);
            $days  = (int)($age->fetchColumn() ?? 0);
            if ($days >= 180) {
                $dup['flag'] = 'returning';
                $dbDupFlag   = 'possible'; // 'returning' not in ENUM — store as 'possible'
            }
        }
    }

    if ($dryRun) {
        return ['status' => 'dry-run', 'message' => "$name | {$lead['source']} | dup={$dup['flag']}"];
    }

    $ts = (int)($lead['date_created_ms'] / 1000);
    // Sanity check: if timestamp is before year 2000, fall back to today
    $dateReceived = ($ts > 946684800)
        ? date('Y-m-d', $ts)
        : date('Y-m-d');

    try {
        $db->prepare(
            "INSERT INTO lead_staging
                 (hubspot_id, date_received, customer_name, email, phone, source,
                  destination, period, pax, initial_request, notes, raw_data, dup_flag, dup_refs)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $hsId,
            $dateReceived,
            $name,
            $lead['email']           ?: null,
            $lead['phone']           ?: null,
            $lead['source'],
            $lead['destination']     ?: null,
            $lead['period']          ?: null,
            $lead['pax'],
            $lead['initial_request'] ?: null,
            $lead['notes']           ?: null,
            json_encode($lead['raw_data'], JSON_UNESCAPED_UNICODE),
            $dbDupFlag,
            $dup['refs'] ? json_encode($dup['refs']) : null,
        ]);
        $dupLabel = $dup['flag'] !== 'clean' ? " [{$dup['flag']} dup]" : '';
        return ['status' => 'staged', 'message' => "STAGED: $name | {$lead['source']}$dupLabel"];
    } catch (PDOException $e) {
        return ['status' => 'error', 'message' => "ERROR $name: " . $e->getMessage()];
    }
}

/** Creates lead_staging table if it does not exist. */
function hs_ensure_table(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS lead_staging (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        hubspot_id      VARCHAR(30)   DEFAULT NULL,
        created_at      DATETIME      NOT NULL DEFAULT NOW(),
        date_received   DATE          NOT NULL,
        customer_name   VARCHAR(200)  NOT NULL,
        email           VARCHAR(200)  DEFAULT NULL,
        phone           VARCHAR(60)   DEFAULT NULL,
        source          VARCHAR(30)   NOT NULL DEFAULT 'Form',
        destination     VARCHAR(60)   DEFAULT NULL,
        period          VARCHAR(200)  DEFAULT NULL,
        pax             TINYINT       DEFAULT NULL,
        initial_request TEXT          DEFAULT NULL,
        notes           TEXT          DEFAULT NULL,
        raw_data        JSON          DEFAULT NULL,
        dup_flag        ENUM('clean','possible','definite') NOT NULL DEFAULT 'clean',
        dup_refs        JSON          DEFAULT NULL,
        UNIQUE KEY uk_hubspot (hubspot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ═════════════════════════════════════════════════════════════════════════════
// CLI execution block — skipped when included as a library (HS_INCLUDED=true)
// ═════════════════════════════════════════════════════════════════════════════
if (!defined('HS_INCLUDED')) {

    if (php_sapi_name() !== 'cli') {
        die("This script must be run from the command line or cron.\n");
    }

    $debugMode = in_array('--debug',   $argv, true);
    $dryRun    = in_array('--dry-run', $argv, true);
    $resetSync = in_array('--reset',   $argv, true);
    $sinceArg  = null;
    foreach ($argv as $arg) {
        if (preg_match('/^--since=(\d+)$/', $arg, $m)) { $sinceArg = (int)$m[1]; break; }
    }

    $syncFile = __DIR__ . '/hubspot_last_sync.txt';

    if ($sinceArg !== null) {
        $since = (time() - $sinceArg * 86400) * 1000;
        echo "[INFO] Looking back {$sinceArg} day(s).\n";
    } elseif ($debugMode) {
        $since = (time() - 7 * 86400) * 1000;
        echo "[DEBUG] Looking back 7 days.\n";
    } elseif ($resetSync) {
        $since = (time() - 86400) * 1000;
        echo "[RESET] Ignoring last sync timestamp, looking back 24 hours.\n";
    } elseif (file_exists($syncFile)) {
        $since = (int)trim(file_get_contents($syncFile));
        echo "[INFO] Last sync: " . date('Y-m-d H:i:s', (int)($since / 1000)) . "\n";
    } else {
        $since = (time() - 86400) * 1000;
    }

    $runStart = time() * 1000;

    // ── Fetch ────────────────────────────────────────────────────────────────
    try {
        if ($debugMode) {
            // Debug: show raw contacts first, then tickets
            $contacts = hs_fetch_contacts($since);
            $newCount   = count(array_filter($contacts, fn($c) => empty($c['_is_reconversion'])));
            $reconvCount= count(array_filter($contacts, fn($c) => !empty($c['_is_reconversion'])));
            echo "[DEBUG] " . count($contacts) . " contacts found ($newCount new, $reconvCount reconversions)\n\n";
            foreach ($contacts as $c) {
                $tag = !empty($c['_is_reconversion']) ? ' [RECONVERSION]' : '';
                echo "--- CONTACT {$c['id']}$tag ---\n";
                foreach ($c['properties'] as $k => $v) {
                    if ($v !== null && $v !== '') echo "  $k = $v\n";
                }
                echo "\n";
            }
            $tickets = hs_fetch_tickets($since);
            echo "[DEBUG] " . count($tickets) . " iBot ticket leads found\n\n";
            foreach ($tickets as $l) {
                echo "--- TICKET {$l['hubspot_id']} | {$l['customer_name']} ---\n";
                echo "  email = {$l['email']}\n  phone = {$l['phone']}\n\n";
            }
            exit(0);
        }

        $allLeads = hs_fetch_all($since);
    } catch (RuntimeException $e) {
        die("[ERROR] " . $e->getMessage() . "\n");
    }

    if (empty($allLeads)) {
        echo "[" . date('Y-m-d H:i:s') . "] No new leads.\n";
        if (!$dryRun) file_put_contents($syncFile, $runStart - 300000);
        exit(0);
    }

    // ── DB ───────────────────────────────────────────────────────────────────
    try {
        $db = hs_db();
        hs_ensure_table($db);
    } catch (PDOException $e) {
        die("[ERROR] DB: " . $e->getMessage() . "\n");
    }

    // ── Process ──────────────────────────────────────────────────────────────
    $staged = 0; $skipped = 0; $errors = 0;
    foreach ($allLeads as $lead) {
        $result = hs_insert_lead($db, $lead, $dryRun);
        echo "[" . date('H:i:s') . "] " . $result['message'] . "\n";
        match ($result['status']) {
            'staged',
            'dry-run' => $staged++,
            'skipped' => $skipped++,
            default   => $errors++,
        };
    }

    // Save with 5-minute overlap so the next run re-checks contacts that may
    // have been created just before $runStart but not yet indexed by HubSpot.
    // hs_insert_lead handles re-encounters safely via hubspot_id uniqueness.
    if (!$dryRun) file_put_contents($syncFile, $runStart - 300000);
    echo "[" . date('Y-m-d H:i:s') . "] Done — staged: $staged | skipped: $skipped | errors: $errors\n";
}
