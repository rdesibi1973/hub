<?php
/**
 * predeparture_service.php — pre-departure travel-information mail for a booked request (Booked page,
 * "✈ Travel info"): Jambo PDF, passport, visa / Kenya eTA, NIC / ZIC / Kenya health insurance, Ebola health
 * form, own travel insurance. Built per language (it, en, fr, es) and per country (tz = mainland Tanzania,
 * znz = Zanzibar, ke = Kenya); the seller edits it in the send modal before sending.
 * Facts: docs/skills/agency-email.md §5 (entry requirements, 26 Oct 2026). Jambo / insurance PDFs:
 * modules/iti/includes/client_docs.php (public links on the website).
 */

/** Subject prefixes per language: also how the page spots a mail already sent (request_notes.subject). */
const PREDEP_SUBJECTS = [
    'it' => 'Informazioni di viaggio',
    'en' => 'Travel information',
    'fr' => 'Informations de voyage',
    'es' => 'Información para el viaje',
];
const PREDEP_COUNTRIES = ['tz', 'znz', 'ke'];
/** Booked page: the button turns red when the arrival is this close and no travel info was sent. */
const PREDEP_DUE_DAYS = 21;

/** SQL condition on a request_notes alias: the note is a pre-departure mail. */
function predep_note_sql(PDO $pdo, string $alias = 'rn'): string {
    $or = [];
    foreach (PREDEP_SUBJECTS as $s) $or[] = $alias . '.subject LIKE ' . $pdo->quote($s . '%');
    return "({$alias}.note_type = 'email_sent' AND (" . implode(' OR ', $or) . '))';
}

/** Client documents (client_docs.php), loaded once: it declares a constant. */
function predep_client_docs(): array {
    static $docs = null;
    if ($docs === null) {
        $f = __DIR__ . '/../../iti/includes/client_docs.php';
        $docs = is_file($f) ? (require $f) : [];
    }
    return $docs;
}

/** Public link of a client document group in $lang (EN when that language is missing), '' if none. */
function predep_doc_url(string $group, string $lang): string {
    $files = predep_client_docs()[$group] ?? [];
    $file  = $files[strtoupper($lang)] ?? ($files['EN'] ?? '');
    return ($file !== '' && defined('ITI_CLIENT_DOCS_BASE')) ? ITI_CLIENT_DOCS_BASE . rawurlencode($file) : '';
}

/**
 * Default language and countries for a request: its linked itinerary (iti_programs.lead_request_id —
 * display_language, country, title), then words in the folder / request type. Only a starting point:
 * the seller checks them in the dialog.
 */
function predep_defaults(PDO $pdo, array $req): array {
    $lang = 'it';
    $text = ($req['practice_code'] ?? '') . ' ' . ($req['group_folder'] ?? '') . ' ' . ($req['destination'] ?? '');
    $pc   = '';
    try {
        $st = $pdo->prepare("SELECT * FROM iti_programs WHERE lead_request_id = ? ORDER BY (stage = 'final') DESC, id DESC LIMIT 1");
        $st->execute([(int)$req['id']]);
        if ($p = $st->fetch(PDO::FETCH_ASSOC)) {
            if (isset(PREDEP_SUBJECTS[$p['display_language'] ?? ''])) $lang = $p['display_language'];
            $pc = strtoupper((string)($p['country'] ?? ''));
            foreach ($p as $k => $v) if (str_starts_with($k, 'title') && is_string($v)) $text .= ' ' . $v;
        }
    } catch (Throwable $e) {}   // ITI tables/columns missing: folder words only

    $ke  = str_contains($pc, 'KE') || preg_match('/kenya|masai\s*mara|amboseli|tsavo|samburu|naivasha|nakuru|nairobi/i', $text);
    $znz = (bool)preg_match('/zanzibar|\bznz\b|beach|\bmare\b|pemba|mafia/i', $text);
    // Mainland: the itinerary says so, a Tanzania park is named, or no Kenya and not a beach-only stay.
    $tz  = str_contains($pc, 'TZ') || preg_match('/serengeti|ngorongoro|tarangire|manyara|arusha|kilimanjaro|meru/i', $text)
        || (!$ke && (!$znz || preg_match('/safari|kili|trek/i', $text)));
    return ['lang' => $lang, 'countries' => array_keys(array_filter(['tz' => (bool)$tz, 'znz' => $znz, 'ke' => (bool)$ke]))];
}

function predep_date(?string $ymd, string $lang): string {
    if (!$ymd || !($ts = strtotime($ymd))) return '';
    $m = [
        'it' => ['gennaio','febbraio','marzo','aprile','maggio','giugno','luglio','agosto','settembre','ottobre','novembre','dicembre'],
        'fr' => ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'],
        'es' => ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'],
    ];
    if ($lang === 'en') return date('j F Y', $ts);
    $d = (int)date('j', $ts);
    return ($lang === 'fr' && $d === 1 ? '1er' : $d) . ' ' . ($lang === 'es' ? 'de ' : '') . $m[$lang][(int)date('n', $ts) - 1] . ($lang === 'es' ? ' de ' : ' ') . date('Y', $ts);
}

function predep_link(string $url, string $label = ''): string {
    return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($label !== '' ? $label : preg_replace('#^https?://#', '', $url)) . '</a>';
}

/** Texts per language. Items with a country key are kept only when that country is in the trip. */
function predep_texts(string $lang): array {
    $T = [
        'it' => [
            'hello'   => 'Buongiorno %s,',
            'intro'   => 'manca poco alla vostra partenza%s! Vi riepiloghiamo qui le informazioni utili e i documenti da preparare prima del viaggio.',
            'on'      => ' del %s',
            'h_info'  => 'Informazioni di viaggio:',
            'jambo'   => 'la nostra guida "Jambo" con tutte le informazioni pratiche (clima, abbigliamento, valuta, mance, cosa portare): %s',
            'jambo_ke'=> 'la guida "Jambo" per il Kenya: %s',
            'h_docs'  => 'Documenti:',
            'passport'=> 'passaporto con validità di almeno 6 mesi dalla data di ingresso e almeno 2 pagine libere;',
            'visa_tz' => 'visto per la Tanzania (anche per Zanzibar): da richiedere online prima della partenza solo sul sito ufficiale %s (USD 50 per i cittadini italiani). È possibile farlo anche all\'arrivo, ma lo sconsigliamo per evitare lunghe code;',
            'eta_ke'  => 'Kenya: eTA (autorizzazione elettronica di viaggio) da richiedere online prima della partenza su %s.',
            'h_ins'   => 'Assicurazioni obbligatorie:',
            'nic'     => 'Tanzania: assicurazione obbligatoria NIC, USD 44 per persona (in vigore dal 1° ottobre 2026), da acquistare prima della partenza o all\'arrivo. Non sostituisce la vostra assicurazione di viaggio;',
            'zic'     => 'Zanzibar: assicurazione obbligatoria ZIC, USD 44 per persona, da acquistare prima dell\'arrivo su %s, anche se avete già un\'assicurazione di viaggio;',
            'ins_pdf' => 'tutti i dettagli su NIC e ZIC in questo documento: %s',
            'ins_ke'  => 'Kenya: assicurazione sanitaria obbligatoria, USD 44 per persona (bambini inclusi) più le commissioni del portale, da acquistare sul portale governativo insieme all\'eTA (%s). Non c\'è un documento di polizza: conservate il riferimento e la schermata con lo stato "Valid".',
            'h_health'=> 'Modulo sanitario (Ebola):',
            'eb_tz'   => 'per l\'ingresso in Tanzania ogni viaggiatore, bambini inclusi, compila il Traveller\'s Health Surveillance Form del Ministero della Salute su %s, possibilmente nelle 24 ore prima dell\'arrivo (si può fare anche all\'arrivo);',
            'eb_znz'  => 'per Zanzibar il modulo è sul portale %s;',
            'eb_qr'   => 'conservate il codice QR (anche uno screenshot) per il controllo sanitario in aeroporto, dove viene misurata anche la temperatura.',
            'h_other' => 'Da ricordare:',
            'travel'  => 'la vostra assicurazione di viaggio deve coprire l\'evacuazione sanitaria d\'emergenza; se non ce l\'avete ancora mandata, inviateci per favore una copia della polizza;',
            'znz_tax' => 'a Zanzibar la tassa di soggiorno di USD 5 per persona per notte si paga direttamente in hotel.',
            'close'   => 'Rimango a disposizione per maggiori informazioni e domande.',
            'bye'     => 'Buon viaggio e buona giornata',
        ],
        'en' => [
            'hello'   => 'Dear %s,',
            'intro'   => 'your trip%s is getting close! Here is a summary of the useful information and the documents to prepare before you travel.',
            'on'      => ' starting on %s',
            'h_info'  => 'Travel information:',
            'jambo'   => 'our "Jambo" guide with all the practical information (weather, clothing, currency, tips, what to pack): %s',
            'jambo_ke'=> 'the "Jambo" guide for Kenya: %s',
            'h_docs'  => 'Documents:',
            'passport'=> 'passport valid for at least 6 months from the date of entry, with at least 2 blank pages;',
            'visa_tz' => 'Tanzania visa (Zanzibar included): apply online before departure, only on the official website %s (the fee depends on your nationality). A visa on arrival is possible, but we don\'t recommend it because of the queues;',
            'eta_ke'  => 'Kenya: eTA (electronic travel authorisation), to apply for online before departure on %s.',
            'h_ins'   => 'Mandatory insurance:',
            'nic'     => 'Tanzania: NIC mandatory insurance, USD 44 per person (in force since 1 October 2026), to buy before travelling or on arrival. It does not replace your own travel insurance;',
            'zic'     => 'Zanzibar: ZIC mandatory insurance, USD 44 per person, to buy before arrival on %s, even if you already have travel insurance;',
            'ins_pdf' => 'all the details on NIC and ZIC in this document: %s',
            'ins_ke'  => 'Kenya: mandatory Kenya health insurance, USD 44 per person (children included) plus portal fees, bought only on the government portal together with the eTA (%s). There is no policy document: keep the reference and the screenshot showing "Valid".',
            'h_health'=> 'Health declaration (Ebola):',
            'eb_tz'   => 'to enter Tanzania every traveller, children included, fills in the Ministry of Health Traveller\'s Health Surveillance Form at %s, ideally within 24 hours before arrival (also possible on arrival);',
            'eb_znz'  => 'for Zanzibar the form is on %s;',
            'eb_qr'   => 'keep the QR code (a screenshot is fine) for the port health check at the airport, where your temperature is also taken.',
            'h_other' => 'Please remember:',
            'travel'  => 'your travel insurance must cover emergency medical evacuation; if you haven\'t sent it yet, please send us a copy of the policy;',
            'znz_tax' => 'in Zanzibar the infrastructure tax of USD 5 per person per night is paid directly at the hotel.',
            'close'   => 'I remain at your disposal for any further information or questions.',
            'bye'     => 'Have a great trip and kind regards',
        ],
        'fr' => [
            'hello'   => 'Bonjour %s,',
            'intro'   => 'votre départ%s approche ! Voici un récapitulatif des informations utiles et des documents à préparer avant le voyage.',
            'on'      => ' du %s',
            'h_info'  => 'Informations de voyage :',
            'jambo'   => 'notre guide « Jambo » avec toutes les informations pratiques (climat, vêtements, monnaie, pourboires, que mettre dans la valise) : %s',
            'jambo_ke'=> 'le guide « Jambo » pour le Kenya : %s',
            'h_docs'  => 'Documents :',
            'passport'=> 'passeport valable au moins 6 mois après la date d\'entrée, avec au moins 2 pages vierges ;',
            'visa_tz' => 'visa pour la Tanzanie (Zanzibar compris) : à demander en ligne avant le départ, uniquement sur le site officiel %s (le tarif dépend de la nationalité). Le visa à l\'arrivée est possible, mais nous le déconseillons à cause de l\'attente ;',
            'eta_ke'  => 'Kenya : eTA (autorisation de voyage électronique), à demander en ligne avant le départ sur %s.',
            'h_ins'   => 'Assurances obligatoires :',
            'nic'     => 'Tanzanie : assurance obligatoire NIC, USD 44 par personne (en vigueur depuis le 1er octobre 2026), à acheter avant le départ ou à l\'arrivée. Elle ne remplace pas votre assurance voyage ;',
            'zic'     => 'Zanzibar : assurance obligatoire ZIC, USD 44 par personne, à acheter avant l\'arrivée sur %s, même si vous avez déjà une assurance voyage ;',
            'ins_pdf' => 'tous les détails sur NIC et ZIC dans ce document : %s',
            'ins_ke'  => 'Kenya : assurance santé obligatoire, USD 44 par personne (enfants compris) plus les frais du portail, à acheter uniquement sur le portail du gouvernement avec l\'eTA (%s). Il n\'y a pas de document de police : gardez la référence et la capture d\'écran avec le statut « Valid ».',
            'h_health'=> 'Formulaire sanitaire (Ebola) :',
            'eb_tz'   => 'pour entrer en Tanzanie, chaque voyageur, enfants compris, remplit le Traveller\'s Health Surveillance Form du Ministère de la Santé sur %s, de préférence dans les 24 heures avant l\'arrivée (possible aussi à l\'arrivée) ;',
            'eb_znz'  => 'pour Zanzibar, le formulaire est sur %s ;',
            'eb_qr'   => 'gardez le code QR (une capture d\'écran suffit) pour le contrôle sanitaire à l\'aéroport, où la température est aussi prise.',
            'h_other' => 'À retenir :',
            'travel'  => 'votre assurance voyage doit couvrir l\'évacuation sanitaire d\'urgence ; si vous ne nous l\'avez pas encore envoyée, merci de nous transmettre une copie de la police ;',
            'znz_tax' => 'à Zanzibar, la taxe d\'infrastructure de USD 5 par personne et par nuit se paie directement à l\'hôtel.',
            'close'   => 'Je reste à votre disposition pour toute information ou question.',
            'bye'     => 'Bon voyage et bonne journée',
        ],
        'es' => [
            'hello'   => 'Buenos días %s:',
            'intro'   => '¡ya falta poco para vuestro viaje%s! Os resumimos aquí la información útil y los documentos que hay que preparar antes de salir.',
            'on'      => ' del %s',
            'h_info'  => 'Información para el viaje:',
            'jambo'   => 'nuestra guía "Jambo" con toda la información práctica (clima, ropa, moneda, propinas, qué llevar): %s',
            'jambo_ke'=> 'la guía "Jambo" para Kenia: %s',
            'h_docs'  => 'Documentos:',
            'passport'=> 'pasaporte con validez mínima de 6 meses desde la fecha de entrada y al menos 2 páginas en blanco;',
            'visa_tz' => 'visado para Tanzania (también para Zanzíbar): se solicita online antes de salir, solo en la web oficial %s (el precio depende de la nacionalidad). También se puede obtener a la llegada, pero no lo recomendamos por las colas;',
            'eta_ke'  => 'Kenia: eTA (autorización electrónica de viaje), que se solicita online antes de salir en %s.',
            'h_ins'   => 'Seguros obligatorios:',
            'nic'     => 'Tanzania: seguro obligatorio NIC, USD 44 por persona (vigente desde el 1 de octubre de 2026), que se compra antes del viaje o a la llegada. No sustituye vuestro seguro de viaje;',
            'zic'     => 'Zanzíbar: seguro obligatorio ZIC, USD 44 por persona, que se compra antes de la llegada en %s, aunque ya tengáis seguro de viaje;',
            'ins_pdf' => 'todos los detalles sobre NIC y ZIC en este documento: %s',
            'ins_ke'  => 'Kenia: seguro médico obligatorio, USD 44 por persona (niños incluidos) más las comisiones del portal, que se compra solo en el portal del gobierno junto con la eTA (%s). No hay documento de póliza: guardad la referencia y la captura de pantalla con el estado "Valid".',
            'h_health'=> 'Formulario sanitario (ébola):',
            'eb_tz'   => 'para entrar en Tanzania cada viajero, niños incluidos, rellena el Traveller\'s Health Surveillance Form del Ministerio de Sanidad en %s, preferiblemente en las 24 horas antes de la llegada (también es posible a la llegada);',
            'eb_znz'  => 'para Zanzíbar el formulario está en %s;',
            'eb_qr'   => 'guardad el código QR (vale una captura de pantalla) para el control sanitario en el aeropuerto, donde también se toma la temperatura.',
            'h_other' => 'Para recordar:',
            'travel'  => 'vuestro seguro de viaje debe cubrir la evacuación médica de emergencia; si todavía no nos lo habéis enviado, mandadnos por favor una copia de la póliza;',
            'znz_tax' => 'en Zanzíbar la tasa de infraestructura de USD 5 por persona y noche se paga directamente en el hotel.',
            'close'   => 'Quedo a vuestra disposición para cualquier información o pregunta.',
            'bye'     => 'Buen viaje y un saludo',
        ],
    ];
    return $T[$lang] ?? $T['en'];
}

/**
 * The mail for a request: ['subject', 'body' (HTML, without signature: send_hub_email() adds it)].
 * $countries ⊆ PREDEP_COUNTRIES; $start_date 'YYYY-MM-DD' or null.
 */
function predep_build(array $req, string $lang, array $countries, ?string $start_date): array {
    $lang = isset(PREDEP_SUBJECTS[$lang]) ? $lang : 'en';
    $c    = array_flip(array_intersect(PREDEP_COUNTRIES, $countries));
    $tz   = isset($c['tz']); $znz = isset($c['znz']); $ke = isset($c['ke']);
    $t    = predep_texts($lang);
    $e    = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);
    $L    = fn(string $key, string $url, string $label = '') => sprintf($e($t[$key]), predep_link($url, $label));

    $sections = [];
    $info = [];
    if (($tz || $znz) && ($u = predep_doc_url('Travel info Tanzania (Jambo)', $lang)) !== '') $info[] = $L('jambo', $u, 'Jambo (PDF)');
    if ($ke && ($u = predep_doc_url('Travel info Kenya (Jambo)', $lang)) !== '') $info[] = $L($tz || $znz ? 'jambo_ke' : 'jambo', $u, 'Jambo Kenya (PDF)');
    $sections['h_info'] = $info;

    $docs = [$e($t['passport'])];
    if ($tz || $znz) $docs[] = $L('visa_tz', 'https://eservices.immigration.go.tz');
    if ($ke)         $docs[] = $L('eta_ke', 'https://www.etakenya.go.ke');
    $sections['h_docs'] = $docs;

    $ins = [];
    if ($tz)  $ins[] = $e($t['nic']);
    if ($znz) $ins[] = $L('zic', 'https://visitzanzibar.go.tz');
    if (($tz || $znz) && ($u = predep_doc_url('Insurance Tanzania & Zanzibar (NIC / ZIC)', $lang)) !== '') $ins[] = $L('ins_pdf', $u, 'NIC / ZIC (PDF)');
    if ($ke)  $ins[] = $L('ins_ke', 'https://www.etakenya.go.ke');
    $sections['h_ins'] = $ins;

    $health = [];
    if ($tz)  $health[] = $L('eb_tz', 'https://afyamsafiri.moh.go.tz');
    if ($znz) $health[] = $L('eb_znz', 'https://healthtravelznz.mohz.go.tz');
    if ($health) $health[] = $e($t['eb_qr']);
    $sections['h_health'] = $health;

    $other = [$e($t['travel'])];
    if ($znz) $other[] = $e($t['znz_tax']);
    $sections['h_other'] = $other;

    $when = predep_date($start_date, $lang);
    $name = trim((string)($req['customer_name'] ?? ''));
    $html = '<p>' . sprintf($e($t['hello']), $e($name)) . '</p>'
          . '<p>' . $e(sprintf($t['intro'], $when !== '' ? sprintf($t['on'], $when) : '')) . '</p>';
    foreach ($sections as $h => $items) {
        if (!$items) continue;
        $items[count($items) - 1] = preg_replace('/\s*;$/u', '.', end($items));   // the list's last item ends with a full stop
        $html .= '<p><br></p><p>' . $e($t[$h]) . '</p><ul>';
        foreach ($items as $it) $html .= '<li>' . $it . '</li>';
        $html .= '</ul>';
    }
    $html .= '<p><br></p><p>' . $e($t['close']) . '</p><p><br></p><p>' . $e($t['bye']) . '</p>';

    $pc     = trim((string)($req['practice_code'] ?? ''));
    $folder = folder_mail_subject(stripos($pc, '_END') !== false ? $pc : get_date_folder($req));
    return [
        'subject' => PREDEP_SUBJECTS[$lang] . ($folder !== '' ? ' - ' . $folder : ''),
        'body'    => $html,
    ];
}
