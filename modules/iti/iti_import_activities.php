<?php
/**
 * iti_import_activities.php
 * Static import of standard Savannah Explorers activities — 5 languages.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db       = db();
$_cu      = current_user();
$can_edit = in_array($_cu['role_name'], ['admin', 'manager']);

// ── Destination map ───────────────────────────────────────────────────────────
$dest_rows    = iti_get_destinations(false);
$dest_by_code = [];
$dest_by_id   = [];
foreach ($dest_rows as $d) {
    $dest_by_code[$d['code']] = $d['id'];
    $dest_by_id[$d['id']]     = $d['name_en'];
}
function adid(string $code): int { global $dest_by_code; return $dest_by_code[$code] ?? 0; }

// ── Existing activity names (lowercase EN) ────────────────────────────────────
$existing = [];
foreach ($db->query("SELECT LOWER(name_en) AS n FROM iti_activities")->fetchAll() as $r)
    $existing[] = $r['n'];

// ── Activity data ─────────────────────────────────────────────────────────────
// dest_code: null = no specific destination (generic)
// activity_type: game_drive | walking_safari | cultural | boat | balloon | hiking | beach | other
$ACTIVITIES = [
    [
        'dest_code'      => 'ARU',
        'activity_type'  => 'cultural',
        'name_en'        => 'Arusha City & Local Market Tour',
        'name_it'        => 'Visita di Arusha e del mercato locale',
        'name_fr'        => 'Visite d\'Arusha et du marché local',
        'name_es'        => 'Visita de Arusha y el mercado local',
        'name_de'        => 'Besuch von Arusha und dem lokalen Markt',
        'description_en' => 'Explore the vibrant town of Arusha, the gateway to Tanzania\'s northern safari circuit. Visit the colourful Arusha Central Market, where locals trade fresh produce, spices, fabrics, and crafts. Discover the cultural melting pot of the Chagga, Meru, and Maasai peoples and enjoy views of Mount Meru in the background.',
        'description_it' => 'Esplora la vivace città di Arusha, porta d\'ingresso al circuito safari del nord della Tanzania. Visita il colorato Mercato Centrale di Arusha, dove i locali commerciano frutta, spezie, stoffe e artigianato. Scopri il crogiolo culturale dei popoli Chagga, Meru e Maasai con sullo sfondo il Monte Meru.',
        'description_fr' => 'Explorez la ville animée d\'Arusha, porte d\'entrée du circuit safari nord de la Tanzanie. Visitez le coloré marché central d\'Arusha, où les habitants échangent fruits frais, épices, tissus et artisanat. Découvrez le melting-pot culturel des peuples Chagga, Meru et Maasai avec le mont Meru en toile de fond.',
        'description_es' => 'Explora la vibrante ciudad de Arusha, puerta de entrada al circuito safari del norte de Tanzania. Visita el colorido Mercado Central de Arusha, donde los lugareños comercian productos frescos, especias, telas y artesanías. Descubre el crisol cultural de los pueblos Chagga, Meru y Maasai con el Monte Meru de fondo.',
        'description_de' => 'Erkunden Sie die lebhafte Stadt Arusha, das Tor zum nördlichen Safarikreis Tansanias. Besuchen Sie den farbenfrohen Zentralmarkt von Arusha, wo Einheimische frische Produkte, Gewürze, Stoffe und Kunsthandwerk handeln. Entdecken Sie den kulturellen Schmelztiegel der Chagga-, Meru- und Maasai-Völker vor der Kulisse des Meru.',
        'duration_hours' => 3,
    ],
    [
        'dest_code'      => null,
        'activity_type'  => 'game_drive',
        'name_en'        => 'Game Drive',
        'name_it'        => 'Fotosafari',
        'name_fr'        => 'Safari photo',
        'name_es'        => 'Safari fotográfico',
        'name_de'        => 'Fotosafari',
        'description_en' => 'Explore the park or reserve by 4×4 vehicle with an expert guide, searching for the Big Five and other wildlife. Game drives can be scheduled in the morning, afternoon, or as a full-day excursion depending on the destination and season.',
        'description_it' => 'Esplora il parco o la riserva a bordo di un veicolo 4×4 con una guida esperta, alla ricerca dei Big Five e di altra fauna selvatica. I fotosafari possono essere programmati al mattino, nel pomeriggio o come escursione di un giorno intero a seconda della destinazione e della stagione.',
        'description_fr' => 'Explorez le parc ou la réserve à bord d\'un véhicule 4×4 avec un guide expert, à la recherche des Big Five et d\'autres animaux sauvages. Les safaris photo peuvent être programmés le matin, l\'après-midi ou en excursion d\'une journée complète selon la destination et la saison.',
        'description_es' => 'Explora el parque o reserva en un vehículo 4×4 con un guía experto, buscando los Cinco Grandes y otra fauna salvaje. Los safaris fotográficos se pueden programar por la mañana, tarde o como excursión de día completo según el destino y la temporada.',
        'description_de' => 'Erkunden Sie den Park oder das Reservat in einem 4×4-Fahrzeug mit einem erfahrenen Guide auf der Suche nach den Big Five und anderen Wildtieren. Fotosafaris können morgens, nachmittags oder als ganztägiger Ausflug geplant werden, je nach Ziel und Jahreszeit.',
        'duration_hours' => 4,
    ],
    [
        'dest_code'      => null,
        'activity_type'  => 'walking_safari',
        'name_en'        => 'Walking Safari',
        'name_it'        => 'Safari a piedi',
        'name_fr'        => 'Safari à pied',
        'name_es'        => 'Safari a pie',
        'name_de'        => 'Fußsafari',
        'description_en' => 'Experience Africa at ground level on a guided walking safari with armed rangers. Discover the smaller wonders of the bush — tracks, insects, medicinal plants — and learn to read the landscape as your ancestors did. An exhilarating and intimate way to connect with the wild.',
        'description_it' => 'Vivi l\'Africa a livello del suolo con un safari a piedi guidato da ranger armati. Scopri le meraviglie più piccole della savana — tracce, insetti, piante medicinali — e impara a leggere il paesaggio come facevano i tuoi antenati. Un modo emozionante e intimo per connettersi con la natura selvaggia.',
        'description_fr' => 'Vivez l\'Afrique au niveau du sol lors d\'un safari à pied guidé par des rangers armés. Découvrez les petites merveilles de la brousse — traces, insectes, plantes médicinales — et apprenez à lire le paysage comme vos ancêtres. Une façon exaltante et intime de se connecter avec la nature sauvage.',
        'description_es' => 'Vive África a nivel del suelo en un safari a pie guiado por guardabosques armados. Descubre las pequeñas maravillas del bush — rastros, insectos, plantas medicinales — y aprende a leer el paisaje como lo hacían tus antepasados. Una forma emocionante e íntima de conectar con la naturaleza salvaje.',
        'description_de' => 'Erleben Sie Afrika hautnah bei einer geführten Fußsafari mit bewaffneten Rangern. Entdecken Sie die kleinen Wunder des Busches — Spuren, Insekten, Heilpflanzen — und lernen Sie, die Landschaft wie Ihre Vorfahren zu lesen. Eine aufregende und intime Art, sich mit der Wildnis zu verbinden.',
        'duration_hours' => 3,
    ],
    [
        'dest_code'      => 'MWB',
        'activity_type'  => 'cultural',
        'name_en'        => 'Mto wa Mbu Village Visit',
        'name_it'        => 'Visita del villaggio di Mto wa Mbu',
        'name_fr'        => 'Visite du village de Mto wa Mbu',
        'name_es'        => 'Visita al pueblo de Mto wa Mbu',
        'name_de'        => 'Dorfbesuch in Mto wa Mbu',
        'description_en' => 'Stroll through the lively village of Mto wa Mbu — "River of Mosquitoes" — a fascinating crossroads of over 120 Tanzanian tribes. Visit local banana beer breweries, craft workshops, rice paddies, and the colourful market. An authentic glimpse into rural Tanzanian life at the foot of the Rift Valley escarpment.',
        'description_it' => 'Passeggia per il vivace villaggio di Mto wa Mbu — "Fiume delle zanzare" — un affascinante crocevia di oltre 120 tribù tanzaniane. Visita le birrerie locali di banana beer, laboratori artigianali, risaie e il colorato mercato. Un autentico spaccato della vita rurale tanzaniana ai piedi della scarpata della Rift Valley.',
        'description_fr' => 'Flânez dans le village animé de Mto wa Mbu — "Rivière des Moustiques" — un fascinant carrefour de plus de 120 tribus tanzaniennes. Visitez les brasseries locales de bière à la banane, les ateliers artisanaux, les rizières et le marché coloré. Un aperçu authentique de la vie rurale tanzanienne au pied de l\'escarpement de la Rift Valley.',
        'description_es' => 'Pasea por el animado pueblo de Mto wa Mbu — "Río de los Mosquitos" — un fascinante cruce de más de 120 tribus tanzanas. Visita las cervecerías locales de banana beer, talleres artesanales, arrozales y el colorido mercado. Un auténtico vistazo a la vida rural tanzana al pie del escarpe del Valle del Rift.',
        'description_de' => 'Schlendern Sie durch das lebhafte Dorf Mto wa Mbu — "Fluss der Mücken" — ein faszinierendes Zusammentreffen von über 120 tansanischen Stämmen. Besuchen Sie lokale Bananenbierbetriebe, Kunsthandwerkswerkstätten, Reisfelder und den bunten Markt. Ein authentischer Einblick in das ländliche Leben Tansanias am Fuß der Rift-Valley-Böschung.',
        'duration_hours' => 3,
    ],
    [
        'dest_code'      => 'EYS',
        'activity_type'  => 'cultural',
        'name_en'        => 'Lake Eyasi & Hadzabe / Datoga Tribes Visit',
        'name_it'        => 'Visita del lago Eyasi e delle tribù Hadzabe e Datoga',
        'name_fr'        => 'Visite du lac Eyasi et des tribus Hadzabe et Datoga',
        'name_es'        => 'Visita al lago Eyasi y las tribus Hadzabe y Datoga',
        'name_de'        => 'Besuch des Lake Eyasi und der Hadzabe- und Datoga-Stämme',
        'description_en' => 'A unique cultural journey to Lake Eyasi in the Rift Valley. Meet the Hadzabe — one of the last hunter-gatherer tribes in Africa — who still use bows and arrows and live entirely off the land. Also visit the Datoga blacksmiths, masters of traditional metalwork. An unforgettable encounter with ancient ways of life.',
        'description_it' => 'Un viaggio culturale unico al lago Eyasi nella Rift Valley. Incontra gli Hadzabe — una delle ultime tribù di cacciatori-raccoglitori in Africa — che usano ancora archi e frecce e vivono interamente della terra. Visita anche i fabbri Datoga, maestri della lavorazione tradizionale del metallo. Un incontro indimenticabile con antichi stili di vita.',
        'description_fr' => 'Un voyage culturel unique au lac Eyasi dans la Rift Valley. Rencontrez les Hadzabe — l\'une des dernières tribus de chasseurs-cueilleurs d\'Afrique — qui utilisent encore arc et flèches et vivent entièrement de la terre. Visitez également les forgerons Datoga, maîtres de la métallurgie traditionnelle. Une rencontre inoubliable avec d\'anciennes façons de vivre.',
        'description_es' => 'Un viaje cultural único al lago Eyasi en el Valle del Rift. Conoce a los Hadzabe — una de las últimas tribus cazadoras-recolectoras de África — que aún usan arcos y flechas y viven enteramente de la tierra. Visita también a los herreros Datoga, maestros de la metalurgia tradicional. Un encuentro inolvidable con antiguas formas de vida.',
        'description_de' => 'Eine einzigartige Kulturreise zum Lake Eyasi im Rift Valley. Treffen Sie die Hadzabe — einen der letzten Jäger-und-Sammler-Stämme Afrikas — die noch Pfeil und Bogen benutzen und vollständig vom Land leben. Besuchen Sie auch die Datoga-Schmiede, Meister der traditionellen Metallverarbeitung. Eine unvergessliche Begegnung mit alten Lebensweisen.',
        'duration_hours' => 6,
    ],
    [
        'dest_code'      => 'KILI',
        'activity_type'  => 'hiking',
        'name_en'        => 'Materuni Waterfalls Visit',
        'name_it'        => 'Visita delle cascate Materuni',
        'name_fr'        => 'Visite des chutes de Materuni',
        'name_es'        => 'Visita a las cataratas de Materuni',
        'name_de'        => 'Besuch der Materuni-Wasserfälle',
        'description_en' => 'A half-day hike through lush Chagga coffee farms and forest on the lower slopes of Mount Kilimanjaro to reach the dramatic Materuni Waterfalls. Swim in the natural pool, visit a local coffee plantation, and enjoy panoramic views of the surrounding landscape. A refreshing alternative to the summit trek, suitable for all fitness levels.',
        'description_it' => 'Un\'escursione di mezza giornata attraverso lussureggianti piantagioni di caffè Chagga e foresta sulle pendici inferiori del Kilimanjaro fino alle spettacolari Cascate Materuni. Nuota nella pozza naturale, visita una piantagione di caffè locale e goditi le viste panoramiche del paesaggio circostante. Un\'alternativa rinfrescante al trekking in vetta, adatta a tutti i livelli di forma fisica.',
        'description_fr' => 'Une randonnée d\'une demi-journée à travers les plantations de café Chagga et la forêt sur les basses pentes du Kilimandjaro jusqu\'aux spectaculaires chutes de Materuni. Nagez dans la piscine naturelle, visitez une plantation de café locale et profitez des vues panoramiques sur le paysage environnant. Une alternative rafraîchissante à la randonnée au sommet, accessible à tous les niveaux.',
        'description_es' => 'Una caminata de medio día a través de exuberantes cafetales Chagga y bosque en las laderas bajas del Kilimanjaro hasta las espectaculares cataratas Materuni. Nada en la piscina natural, visita una plantación de café local y disfruta de vistas panorámicas del paisaje circundante. Una alternativa refrescante al trekking de cumbre, apta para todos los niveles.',
        'description_de' => 'Eine halbtägige Wanderung durch üppige Chagga-Kaffeeplantagen und Wald an den unteren Hängen des Kilimandscharo zu den beeindruckenden Materuni-Wasserfällen. Schwimmen Sie im natürlichen Pool, besuchen Sie eine lokale Kaffeeplantage und genießen Sie Panoramablicke auf die umliegende Landschaft. Eine erfrischende Alternative zur Gipfelwanderung, geeignet für alle Fitnessniveaus.',
        'duration_hours' => 5,
    ],
    [
        'dest_code'      => null,
        'activity_type'  => 'balloon',
        'name_en'        => 'Balloon Safari',
        'name_it'        => 'Safari in mongolfiera',
        'name_fr'        => 'Safari en montgolfière',
        'name_es'        => 'Safari en globo',
        'name_de'        => 'Ballonsafari',
        'description_en' => 'Drift silently over the plains at dawn in a hot air balloon for a bird\'s-eye view of the wildlife below. Watch the sun rise over the savannah as herds of wildebeest, zebra, and elephant move across the landscape. The flight ends with a traditional champagne breakfast in the bush — an utterly magical experience.',
        'description_it' => 'Vola silenziosamente sulle pianure all\'alba in mongolfiera per una vista dall\'alto della fauna sottostante. Guarda il sole sorgere sulla savana mentre mandrie di gnu, zebre ed elefanti si muovono nel paesaggio. Il volo si conclude con una tradizionale colazione a champagne nella savana — un\'esperienza assolutamente magica.',
        'description_fr' => 'Survolez silencieusement les plaines à l\'aube en montgolfière pour une vue à vol d\'oiseau sur les animaux en dessous. Regardez le soleil se lever sur la savane tandis que des troupeaux de gnous, zèbres et éléphants traversent le paysage. Le vol se termine par un traditionnel petit-déjeuner au champagne dans la brousse — une expérience tout simplement magique.',
        'description_es' => 'Sobrevuela silenciosamente las llanuras al amanecer en globo aerostático para una vista aérea de la fauna salvaje. Contempla el amanecer sobre la sabana mientras manadas de ñus, cebras y elefantes se mueven por el paisaje. El vuelo termina con un tradicional desayuno con champán en el bush — una experiencia absolutamente mágica.',
        'description_de' => 'Gleiten Sie im Morgengrauen lautlos über die Ebenen in einem Heißluftballon für einen Vogelblick auf die Tierwelt darunter. Beobachten Sie den Sonnenaufgang über der Savanne, während Herden von Gnus, Zebras und Elefanten durch die Landschaft ziehen. Der Flug endet mit einem traditionellen Champagnerfrühstück im Bush — ein absolut magisches Erlebnis.',
        'duration_hours' => 3,
    ],
    [
        'dest_code'      => null,
        'activity_type'  => 'game_drive',
        'name_en'        => 'Night Game Drive',
        'name_it'        => 'Fotosafari notturno',
        'name_fr'        => 'Safari photo nocturne',
        'name_es'        => 'Safari fotográfico nocturno',
        'name_de'        => 'Nächtlicher Fotosafari',
        'description_en' => 'Venture out after dark with powerful spotlights to discover the nocturnal wildlife that is rarely seen during daytime game drives. Leopards, genets, civets, bush babies, porcupines, and owls are among the fascinating creatures that come alive at night. A thrilling complement to the standard safari experience.',
        'description_it' => 'Avventurati nel buio con potenti faretti per scoprire la fauna notturna che raramente si vede durante i fotosafari diurni. Leopardi, genette, civette, galagoni, istrici e gufi sono tra le affascinanti creature che si animano di notte. Un emozionante complemento alla normale esperienza safari.',
        'description_fr' => 'Partez après la tombée de la nuit avec de puissants projecteurs pour découvrir la faune nocturne rarement vue lors des safaris photo de jour. Léopards, genettes, civettes, galagos, porcs-épics et hiboux font partie des créatures fascinantes qui s\'animent la nuit. Un complément palpitant à l\'expérience safari standard.',
        'description_es' => 'Aventúrate en la oscuridad con potentes focos para descubrir la fauna nocturna que rara vez se ve durante los safaris fotográficos diurnos. Leopardos, ginetas, civetas, gálagos, puercoespines y búhos son algunas de las fascinantes criaturas que cobran vida por la noche. Un emocionante complemento a la experiencia safari estándar.',
        'description_de' => 'Brechen Sie nach Einbruch der Dunkelheit mit leistungsstarken Scheinwerfern auf, um die Nachttiere zu entdecken, die bei Tagessafaris selten zu sehen sind. Leoparden, Ginsterkatzen, Zibetkatzen, Galagos, Stachelschweine und Eulen gehören zu den faszinierenden Tieren, die nachts aktiv werden. Eine aufregende Ergänzung zum Standard-Safarierlebnis.',
        'duration_hours' => 3,
    ],
    [
        'dest_code'      => 'NAT',
        'activity_type'  => 'hiking',
        'name_en'        => 'Napuru Waterfalls Visit',
        'name_it'        => 'Visita delle cascate Napuro',
        'name_fr'        => 'Visite des chutes de Napuru',
        'name_es'        => 'Visita a las cataratas de Napuru',
        'name_de'        => 'Besuch der Napuru-Wasserfälle',
        'description_en' => 'A scenic hike to the Napuru Waterfalls near Lake Natron, passing through dramatic volcanic landscape with views of the alkaline lake and Ol Doinyo Lengai volcano. The walk takes you through Maasai land with opportunities to encounter local herdsmen and experience the remote, otherworldly beauty of the Natron basin.',
        'description_it' => 'Un\'escursione panoramica alle Cascate Napuro vicino al Lago Natron, attraverso un paesaggio vulcanico spettacolare con viste sul lago alcalino e sul vulcano Ol Doinyo Lengai. Il percorso attraversa le terre Maasai con opportunità di incontrare pastori locali e vivere la bellezza remota e surreale del bacino di Natron.',
        'description_fr' => 'Une randonnée pittoresque jusqu\'aux chutes de Napuru près du lac Natron, à travers un paysage volcanique spectaculaire avec des vues sur le lac alcalin et le volcan Ol Doinyo Lengai. La marche traverse les terres Maasai avec des opportunités de rencontrer des bergers locaux et de découvrir la beauté lointaine et surréelle du bassin de Natron.',
        'description_es' => 'Una pintoresca caminata hasta las cataratas Napuru cerca del lago Natron, atravesando un espectacular paisaje volcánico con vistas al lago alcalino y al volcán Ol Doinyo Lengai. El recorrido atraviesa tierras Maasai con oportunidades de encontrar pastores locales y experimentar la remota y surrealista belleza de la cuenca de Natron.',
        'description_de' => 'Eine malerische Wanderung zu den Napuru-Wasserfällen nahe Lake Natron durch eine dramatische Vulkanlandschaft mit Ausblicken auf den alkalischen See und den Vulkan Ol Doinyo Lengai. Der Weg führt durch Maasai-Land mit Möglichkeiten, lokale Hirten zu treffen und die abgelegene, unwirkliche Schönheit des Natron-Beckens zu erleben.',
        'duration_hours' => 3,
    ],
    [
        'dest_code'      => null,
        'activity_type'  => 'cultural',
        'name_en'        => 'Maasai Boma Visit',
        'name_it'        => 'Visita del Masai boma',
        'name_fr'        => 'Visite d\'un boma Maasai',
        'name_es'        => 'Visita a un boma Maasai',
        'name_de'        => 'Besuch eines Maasai-Bomas',
        'description_en' => 'Step inside a traditional Maasai homestead (boma) and experience the living culture of one of Africa\'s most iconic peoples. Watch warriors perform the traditional adumu jumping dance, learn about Maasai customs, cattle herding, and beadwork, and browse handmade jewellery and crafts. A genuine cultural exchange rather than a staged show.',
        'description_it' => 'Entra in un tradizionale villaggio Maasai (boma) e vivi la cultura viva di uno dei popoli più iconici dell\'Africa. Guarda i guerrieri eseguire il tradizionale ballo adumu, impara le usanze Maasai, la pastorizia e la lavorazione delle perline, e sfoglia gioielli e artigianato fatto a mano. Un autentico scambio culturale piuttosto che uno spettacolo preparato.',
        'description_fr' => 'Entrez dans un homestead Maasai traditionnel (boma) et découvrez la culture vivante de l\'un des peuples les plus emblématiques d\'Afrique. Regardez les guerriers exécuter la danse sautée adumu traditionnelle, apprenez les coutumes Maasai, l\'élevage et la perleculture, et parcourez bijoux et artisanat faits main. Un véritable échange culturel plutôt qu\'un spectacle mis en scène.',
        'description_es' => 'Entra en un homestead Maasai tradicional (boma) y vive la cultura viva de uno de los pueblos más icónicos de África. Observa a los guerreros ejecutar el tradicional baile adumu, aprende sobre las costumbres Maasai, la ganadería y el trabajo de abalorios, y examina joyas y artesanías hechas a mano. Un auténtico intercambio cultural más que un espectáculo preparado.',
        'description_de' => 'Betreten Sie einen traditionellen Maasai-Gehöft (Boma) und erleben Sie die lebendige Kultur eines der ikonischsten Völker Afrikas. Schauen Sie Kriegern beim traditionellen Adumu-Sprungsang zu, lernen Sie Maasai-Bräuche, Viehzucht und Perlenstickerei kennen und stöbern Sie durch handgefertigten Schmuck und Kunsthandwerk. Ein echter kultureller Austausch statt einer inszenierten Show.',
        'duration_hours' => 2,
    ],
    [
        'dest_code'      => 'KAR',
        'activity_type'  => 'cultural',
        'name_en'        => 'Iraqw Boma Visit',
        'name_it'        => 'Visita del Iraqw boma',
        'name_fr'        => 'Visite d\'un boma Iraqw',
        'name_es'        => 'Visita a un boma Iraqw',
        'name_de'        => 'Besuch eines Iraqw-Bomas',
        'description_en' => 'Visit a traditional Iraqw homestead near Karatu and learn about this fascinating Cushitic people, believed to have migrated from Ethiopia thousands of years ago. Discover their unique underground dwellings, terrace farming methods, and distinctive traditions. An off-the-beaten-track cultural encounter rarely offered on standard northern circuit itineraries.',
        'description_it' => 'Visita un tradizionale villaggio Iraqw vicino a Karatu e scopri questo affascinante popolo cuscitico, ritenuto migrante dall\'Etiopia migliaia di anni fa. Scopri le loro particolari abitazioni sotterranee, i metodi di agricoltura a terrazze e le tradizioni distintive. Un incontro culturale fuori dai percorsi battuti raramente offerto negli itinerari standard del circuito nord.',
        'description_fr' => 'Visitez un homestead Iraqw traditionnel près de Karatu et découvrez ce peuple cushitique fascinant, dont on pense qu\'il a migré d\'Éthiopie il y a des milliers d\'années. Découvrez leurs habitations souterraines uniques, leurs méthodes d\'agriculture en terrasses et leurs traditions distinctives. Une rencontre culturelle hors des sentiers battus rarement proposée sur les itinéraires standard du circuit nord.',
        'description_es' => 'Visita un homestead Iraqw tradicional cerca de Karatu y aprende sobre este fascinante pueblo cuchítico, que se cree emigró de Etiopía hace miles de años. Descubre sus únicas viviendas subterráneas, métodos de agricultura en terrazas y tradiciones distintivas. Un encuentro cultural fuera de lo común raramente ofrecido en los itinerarios estándar del circuito norte.',
        'description_de' => 'Besuchen Sie ein traditionelles Iraqw-Gehöft bei Karatu und erfahren Sie mehr über dieses faszinierende kuschitische Volk, das vor Tausenden von Jahren aus Äthiopien eingewandert sein soll. Entdecken Sie ihre einzigartigen unterirdischen Behausungen, Terrassenanbaumethoden und besonderen Traditionen. Eine abseits der üblichen Routen gelegene Kulturbegegnung, die auf Standarditineraren selten angeboten wird.',
        'duration_hours' => 2,
    ],
    [
        'dest_code'      => null,
        'activity_type'  => 'cultural',
        'name_en'        => 'Coffee Plantation Visit',
        'name_it'        => 'Visita della piantagione di caffè',
        'name_fr'        => 'Visite d\'une plantation de café',
        'name_es'        => 'Visita a una plantación de café',
        'name_de'        => 'Besuch einer Kaffeeplantage',
        'description_en' => 'Learn the full journey of Tanzania\'s renowned Arabica coffee — from flowering tree to roasted bean. Walk through the plantation with a local guide, watch the traditional wet processing, hand-pound the beans using age-old techniques, and taste freshly brewed Tanzanian coffee. A delightful sensory experience suitable for all ages.',
        'description_it' => 'Scopri il percorso completo del rinomato caffè Arabica tanzaniano — dall\'albero in fiore al chicco tostato. Cammina tra la piantagione con una guida locale, osserva la tradizionale lavorazione a umido, macina i chicchi a mano con tecniche antichissime e assaggia il caffè tanzaniano appena preparato. Un\'esperienza sensoriale deliziosa adatta a tutte le età.',
        'description_fr' => 'Découvrez le parcours complet du célèbre café Arabica tanzanien — de l\'arbre en fleurs au grain torréfié. Promenez-vous dans la plantation avec un guide local, observez le traitement traditionnel par voie humide, broyez les grains à la main selon des techniques ancestrales et dégustez un café tanzanien fraîchement infusé. Une délicieuse expérience sensorielle accessible à tous les âges.',
        'description_es' => 'Aprende el recorrido completo del renombrado café Arábica tanzano — del árbol en flor al grano tostado. Camina por la plantación con un guía local, observa el procesamiento húmedo tradicional, muele los granos a mano con técnicas milenarias y degusta café tanzano recién preparado. Una deliciosa experiencia sensorial apta para todas las edades.',
        'description_de' => 'Lernen Sie den vollständigen Weg des berühmten tansanischen Arabica-Kaffees kennen — vom blühenden Baum bis zur gerösteten Bohne. Spazieren Sie mit einem lokalen Guide durch die Plantage, schauen Sie der traditionellen Nassverarbeitung zu, mahlen Sie die Bohnen per Hand mit uralten Techniken und genießen Sie frisch gebrühten tansanischen Kaffee. Ein köstliches Sinneserlebnis für alle Altersgruppen.',
        'duration_hours' => 2,
    ],
    [
        'dest_code'      => 'KILI',
        'activity_type'  => 'hiking',
        'name_en'        => 'Kilimanjaro Trekking',
        'name_it'        => 'Trekking sul Kilimanjaro',
        'name_fr'        => 'Trekking sur le Kilimandjaro',
        'name_es'        => 'Trekking en el Kilimanjaro',
        'name_de'        => 'Kilimandscharo-Trekking',
        'description_en' => 'Climb Africa\'s highest peak and the world\'s tallest free-standing mountain. Multiple routes of varying difficulty lead to the Uhuru Peak at 5,895 m above sea level. The climb typically takes 5 to 9 days depending on the chosen route (Marangu, Machame, Lemosho, Rongai, and others). Expert guides, porters, and cooks are included throughout.',
        'description_it' => 'Scala la vetta più alta dell\'Africa e la montagna indipendente più alta del mondo. Diversi percorsi di varia difficoltà conducono all\'Uhuru Peak a 5.895 m sul livello del mare. La salita richiede tipicamente da 5 a 9 giorni a seconda del percorso scelto (Marangu, Machame, Lemosho, Rongai e altri). Guide esperte, portatori e cuochi sono inclusi per tutto il percorso.',
        'description_fr' => 'Gravissez le point culminant de l\'Afrique et la plus haute montagne indépendante du monde. Plusieurs itinéraires de difficultés variées mènent au pic Uhuru à 5 895 m d\'altitude. La montée prend généralement 5 à 9 jours selon l\'itinéraire choisi (Marangu, Machame, Lemosho, Rongai et autres). Des guides experts, porteurs et cuisiniers sont inclus tout au long du trek.',
        'description_es' => 'Escala el pico más alto de África y la montaña independiente más alta del mundo. Múltiples rutas de dificultad variada llevan al Uhuru Peak a 5.895 m sobre el nivel del mar. La subida suele durar entre 5 y 9 días según la ruta elegida (Marangu, Machame, Lemosho, Rongai y otras). Guías expertos, porteadores y cocineros están incluidos durante todo el recorrido.',
        'description_de' => 'Besteigen Sie Afrikas höchsten Gipfel und den höchsten freistehenden Berg der Welt. Mehrere Routen unterschiedlicher Schwierigkeit führen zum Uhuru Peak auf 5.895 m über dem Meeresspiegel. Die Besteigung dauert je nach gewählter Route (Marangu, Machame, Lemosho, Rongai u.a.) typischerweise 5 bis 9 Tage. Erfahrene Guides, Träger und Köche sind während des gesamten Treks inbegriffen.',
        'duration_hours' => null,
    ],
    [
        'dest_code'      => null,
        'activity_type'  => 'walking_safari',
        'name_en'        => 'Nature Walk Around the Lodge',
        'name_it'        => 'Camminata nei dintorni del lodge',
        'name_fr'        => 'Promenade nature autour du lodge',
        'name_es'        => 'Caminata por los alrededores del lodge',
        'name_de'        => 'Naturspaziergang rund ums Lodge',
        'description_en' => 'A relaxed guided walk in the immediate surroundings of the lodge, exploring the local flora, birdlife, and smaller wildlife. A perfect complement to game drives, offering a slower pace and closer attention to the details of the natural world — ideal for birdwatchers, families, and those seeking a gentler bush experience.',
        'description_it' => 'Una rilassante passeggiata guidata nell\'immediato circondario del lodge, esplorando la flora locale, l\'avifauna e la fauna minore. Un perfetto complemento ai fotosafari, che offre un ritmo più lento e una maggiore attenzione ai dettagli del mondo naturale — ideale per birdwatcher, famiglie e chi cerca un\'esperienza nella savana più tranquilla.',
        'description_fr' => 'Une promenade guidée détendue dans les environs immédiats du lodge, explorant la flore locale, les oiseaux et les petits animaux sauvages. Un complément parfait aux safaris photo, offrant un rythme plus lent et une attention plus étroite aux détails du monde naturel — idéal pour les ornithologues amateurs, les familles et ceux qui recherchent une expérience de brousse plus douce.',
        'description_es' => 'Un relajado paseo guiado en los alrededores inmediatos del lodge, explorando la flora local, las aves y la fauna menor. Un complemento perfecto a los safaris fotográficos, ofreciendo un ritmo más lento y mayor atención a los detalles del mundo natural — ideal para observadores de aves, familias y quienes buscan una experiencia de bush más tranquila.',
        'description_de' => 'Ein entspannter geführter Spaziergang in der unmittelbaren Umgebung der Lodge, bei dem lokale Flora, Vögel und kleinere Wildtiere erkundet werden. Eine perfekte Ergänzung zu Fotosafaris mit einem langsameren Tempo und genauerer Beachtung der Details der Naturwelt — ideal für Vogelbeobachter, Familien und alle, die eine ruhigere Busch-Erfahrung suchen.',
        'duration_hours' => 2,
    ],
    [
        'dest_code'      => 'KAR',
        'activity_type'  => 'cultural',
        'name_en'        => 'Karatu Village Visit',
        'name_it'        => 'Visita del villaggio di Karatu',
        'name_fr'        => 'Visite du village de Karatu',
        'name_es'        => 'Visita al pueblo de Karatu',
        'name_de'        => 'Dorfbesuch in Karatu',
        'description_en' => 'Explore the charming highland town of Karatu, nestled between the Great Rift Valley and the Ngorongoro Highlands. Walk through local markets, visit the banana and coffee farms that define the area\'s economy, and interact with the warm Iraqw and Mbulu communities. A peaceful, authentic counterpoint to the intensity of game-drive days.',
        'description_it' => 'Esplora il suggestivo centro altopiano di Karatu, incastonato tra la Grande Rift Valley e gli Altopiani del Ngorongoro. Passeggia tra i mercati locali, visita le piantagioni di banana e caffè che definiscono l\'economia della zona e interagisci con le accoglienti comunità Iraqw e Mbulu. Un controcanto pacifico e autentico all\'intensità dei giorni di fotosafari.',
        'description_fr' => 'Explorez le charmant bourg des hautes terres de Karatu, niché entre la Grande Rift Valley et les hauts plateaux du Ngorongoro. Promenez-vous dans les marchés locaux, visitez les plantations de bananes et de café qui définissent l\'économie de la région et interagissez avec les chaleureuses communautés Iraqw et Mbulu. Un contrepoint paisible et authentique à l\'intensité des journées de safari photo.',
        'description_es' => 'Explora el encantador pueblo de las tierras altas de Karatu, enclavado entre el Gran Valle del Rift y los Altiplanos del Ngorongoro. Pasea por los mercados locales, visita las plantaciones de banana y café que definen la economía de la zona e interactúa con las acogedoras comunidades Iraqw y Mbulu. Un contrapunto tranquilo y auténtico a la intensidad de los días de safari fotográfico.',
        'description_de' => 'Erkunden Sie die charmante Hochlandstadt Karatu, eingebettet zwischen dem Großen Rifttal und dem Ngorongoro-Hochland. Schlendern Sie durch lokale Märkte, besuchen Sie die Bananen- und Kaffeeplantagen, die die Wirtschaft der Region prägen, und treten Sie mit den herzlichen Iraqw- und Mbulu-Gemeinden in Kontakt. Ein ruhiger, authentischer Gegenpol zur Intensität der Fotosafaritage.',
        'duration_hours' => 3,
    ],
];

// ── Add dest_id and duplicate flag ────────────────────────────────────────────
foreach ($ACTIVITIES as &$a) {
    $a['dest_id']      = $a['dest_code'] ? adid($a['dest_code']) : 0;
    $a['is_duplicate'] = in_array(strtolower($a['name_en']), $existing);
}
unset($a);

// ── POST: import ──────────────────────────────────────────────────────────────
$import_log  = [];
$import_done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $stmt = $db->prepare(
        'INSERT INTO iti_activities
         (destination_id,activity_type,name_en,name_it,name_fr,name_es,name_de,
          description_en,description_it,description_fr,description_es,description_de,
          duration_hours,is_active)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)'
    );
    $ok = $skip = $err = 0;
    foreach ($ACTIVITIES as $i => $a) {
        if (isset($_POST["skip_{$i}"])) { $skip++; continue; }
        if (in_array(strtolower($a['name_en']), $existing)) {
            $import_log[] = "⏭ Already exists — skipped: {$a['name_en']}"; $skip++; continue;
        }
        $dest_id = (int)($_POST["dest_{$i}"] ?? $a['dest_id']);
        try {
            $stmt->execute([
                $dest_id ?: null,
                $_POST["type_{$i}"] ?? $a['activity_type'],
                $a['name_en'], $a['name_it'], $a['name_fr'], $a['name_es'], $a['name_de'],
                $a['description_en'], $a['description_it'], $a['description_fr'],
                $a['description_es'], $a['description_de'],
                $a['duration_hours'],
            ]);
            $existing[]   = strtolower($a['name_en']);
            $import_log[] = "✅ Imported: {$a['name_en']}";
            $ok++;
        } catch (Exception $e) {
            $import_log[] = "❌ Error — {$a['name_en']}: " . $e->getMessage();
            $err++;
        }
    }
    $import_log[] = "─── Done: {$ok} imported, {$skip} skipped, {$err} errors ───";
    $import_done  = true;
}

// ── Page ──────────────────────────────────────────────────────────────────────
$page_title = 'Import Standard Activities';
$extra_css  = iti_extra_css() . '
.import-table{width:100%;border-collapse:collapse;background:#fff;font-size:.78rem;border:1px solid var(--grey-lt);}
.import-table th{background:#f0f0ef;padding:7px 10px;text-align:left;font-size:.71rem;white-space:nowrap;border-bottom:1.5px solid var(--grey-lt);}
.import-table td{padding:8px 10px;border-bottom:1px solid #f0f0ef;vertical-align:middle;}
.import-table tr.dup td{background:#fffbeb;}
.badge-dup{display:inline-block;padding:1px 7px;border-radius:4px;background:#FEF3C7;color:#92400E;font-size:.65rem;font-weight:700;margin-left:6px;vertical-align:middle;}
.log-box{background:#1a1a1a;color:#a8e6a3;font-family:monospace;font-size:.75rem;padding:14px 16px;border-radius:8px;max-height:220px;overflow-y:auto;white-space:pre-wrap;margin:12px 0;}
';
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
<?php iti_nav('Import Standard Activities'); ?>
<?php iti_flash_render(); ?>

<div class="page-header">
  <div>
    <h2>🎯 Import Standard Activities</h2>
    <div class="sub">Master Data › Activities › Import — <?= count($ACTIVITIES) ?> activities in 5 languages</div>
  </div>
  <a href="activities.php" class="btn btn-outline btn-sm">← Back to Activities</a>
</div>

<?php if ($import_done): ?>
<div class="form-card">
  <div class="form-section-title">Import Result</div>
  <div class="log-box"><?= implode("\n", array_map('htmlspecialchars', $import_log)) ?></div>
  <div style="margin-top:16px;display:flex;gap:10px;">
    <a href="activities.php" class="btn btn-red">→ View All Activities</a>
    <a href="iti_import_activities.php" class="btn btn-outline">↩ Import again</a>
  </div>
</div>

<?php else: ?>
<form method="POST" action="iti_import_activities.php">
<div class="form-card">
  <div class="form-section-title">Review &amp; Import</div>
  <p style="font-size:.8rem;color:var(--grey-mid);margin-bottom:4px;">
    Review each activity. Adjust destination or type if needed. Check <strong>Skip</strong> to exclude.
    <span class="badge-dup">EXISTS</span> = already in DB (pre-checked for skip).
  </p>
  <p style="font-size:.78rem;color:var(--grey-mid);margin-bottom:16px;">
    <strong><?= count(array_filter($ACTIVITIES, fn($a) => !$a['is_duplicate'])) ?></strong> new activities ready to import
    &nbsp;·&nbsp; <strong><?= count(array_filter($ACTIVITIES, fn($a) => $a['is_duplicate'])) ?></strong> already in database
  </p>

  <table class="import-table">
    <thead>
      <tr>
        <th style="width:36px;text-align:center;">Skip</th>
        <th>Name (EN / IT)</th>
        <th style="min-width:180px;">Destination</th>
        <th>Type</th>
        <th style="width:50px;">Hours</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($ACTIVITIES as $i => $a): ?>
    <tr class="<?= $a['is_duplicate'] ? 'dup' : '' ?>">
      <td style="text-align:center;">
        <input type="checkbox" name="skip_<?= $i ?>" value="1" <?= $a['is_duplicate'] ? 'checked' : '' ?>>
      </td>
      <td>
        <strong><?= h($a['name_en']) ?></strong>
        <?php if ($a['is_duplicate']): ?><span class="badge-dup">EXISTS</span><?php endif; ?>
        <div style="font-size:.72rem;color:var(--grey-mid);"><?= h($a['name_it']) ?></div>
      </td>
      <td>
        <select name="dest_<?= $i ?>" style="font-size:.78rem;">
          <option value="0">— Generic (no destination) —</option>
          <?php foreach ($dest_by_id as $did => $dname): ?>
          <option value="<?= $did ?>" <?= $did==$a['dest_id']?'selected':'' ?>><?= h($dname) ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td>
        <select name="type_<?= $i ?>" style="font-size:.78rem;">
          <?php foreach (ITI_ACTIVITY_TYPES as $tv => $tl): ?>
          <option value="<?= $tv ?>" <?= $a['activity_type']===$tv?'selected':'' ?>><?= $tl ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td style="text-align:center;color:var(--grey-mid);">
        <?= $a['duration_hours'] ?? '—' ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($can_edit): ?>
  <div style="margin-top:20px;display:flex;gap:10px;align-items:center;">
    <button type="submit" class="btn btn-red">⬆ Import Selected Activities</button>
    <a href="activities.php" class="btn btn-outline">Cancel</a>
    <span style="margin-left:auto;font-size:.75rem;color:var(--grey-mid);">Unchecked rows will be imported. EXISTS rows are pre-skipped.</span>
  </div>
  <?php else: ?>
  <p style="color:var(--grey-mid);margin-top:16px;">Admin or manager role required to import.</p>
  <?php endif; ?>
</div>
</form>
<?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
