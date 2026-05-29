<?php
/**
 * iti_import_lodges_web.php
 * Static import of partner lodges — data hardcoded from official websites.
 * Sources: Wellworth, Karibu, Elewana, Sopa, Planet, Asilia, Tarangire Safari Lodge,
 *          Mtoni River Lodge, Gold Crest Hotel, Wildlands
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db       = db();
$_cu      = current_user();
$can_edit = in_array($_cu['role_name'], ['admin', 'manager']);

// ── Destination maps ─────────────────────────────────────────────────────────
$dest_rows    = iti_get_destinations(false);
$dest_by_code = [];
$dest_by_id   = [];
foreach ($dest_rows as $d) {
    $dest_by_code[$d['code']] = $d['id'];
    $dest_by_id[$d['id']]     = $d['name_en'];
}
function did(string $code): int { global $dest_by_code; return $dest_by_code[$code] ?? 0; }

// ── Existing lodge names (lowercase) ─────────────────────────────────────────
$existing = [];
foreach ($db->query("SELECT LOWER(name) AS n FROM iti_lodges")->fetchAll() as $r)
    $existing[] = $r['n'];

// ── Lodge data ───────────────────────────────────────────────────────────────
// dest_code must map to a national park / town — never an airstrip.
$LODGES = [

    // ── WELLWORTH COLLECTION ─────────────────────────────────────────────────
    [
        'group'          => 'Wellworth Collection',
        'dest_code'      => 'NCA',
        'name'           => 'Ngorongoro Oldeani Mountain Lodge',
        'category'       => 'luxury',
        'lodge_type'     => 'lodge',
        'website'        => 'https://wellworthcollection.co.tz/ngorongoro-oldeani/',
        'description_en' => 'A 5-star colonial lodge perched on a hilltop with 360-degree views of Oldeani Mountain, the Ngorongoro Crater Rim, Lake Eyasi, and Lake Manyara. Set on 40 acres of pristine gardens with over 130 bird species. Features 50 deluxe rooms, a rim-flow pool, spa, fine dining, and conference facilities. Located 9 km from Ngorongoro Gate.',
    ],
    [
        'group'          => 'Wellworth Collection',
        'dest_code'      => 'TRP',
        'name'           => 'Tarangire Kuro Treetops Lodge',
        'category'       => 'luxury',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://wellworthcollection.co.tz/tarangire-kuro/',
        'description_en' => 'An eco-friendly luxury tented lodge with 25 tents perched along an ancient baobab tree line inside Tarangire National Park. Each tent features luxury bedding, en-suite bathroom with indoor/outdoor shower, and a private veranda. Solar-powered, with fine dining, a fully equipped bar, and day/night game drives. Located 8 km from Kuro gate.',
    ],
    [
        'group'          => 'Wellworth Collection',
        'dest_code'      => 'LMN',
        'name'           => 'Lake Manyara Kilimamoja Lodge',
        'category'       => 'luxury',
        'lodge_type'     => 'lodge',
        'website'        => 'https://wellworthcollection.co.tz/lake-manyara-kilimamoja/',
        'description_en' => 'A luxury lodge set on the Great Rift Valley escarpment overlooking Lake Manyara National Park. Offers sweeping panoramic views of the lake and surrounding landscape, with comfortable rooms, fine dining, a pool, and easy access to game drives in one of Tanzania\'s most scenic parks.',
    ],
    [
        'group'          => 'Wellworth Collection',
        'dest_code'      => 'SNP',
        'name'           => 'Serengeti Lake Magadi Lodge',
        'category'       => 'luxury',
        'lodge_type'     => 'lodge',
        'website'        => 'https://wellworthcollection.co.tz/serengeti-lake-magadi/',
        'description_en' => 'A 5-star eco-friendly lodge on a prime hilltop inside Serengeti National Park, overlooking the game-filled plains towards Moru Kopjes and the alkaline Lake Magadi. Features 60 handcrafted suites with open-to-sky stone showers, 24-hour solar power, spa, gym, fine dining, and conference facilities.',
    ],
    [
        'group'          => 'Wellworth Collection',
        'dest_code'      => 'MKM',
        'name'           => 'Mikumi Wildlife Lodge',
        'category'       => 'mid',
        'lodge_type'     => 'lodge',
        'website'        => 'https://wellworthcollection.co.tz/mikumi-wildlife-lodge/',
        'description_en' => 'A comfortable lodge inside Mikumi National Park offering direct access to one of Tanzania\'s most accessible wildlife areas. Features comfortable rooms, restaurant and bar, swimming pool, and guided game drives through the park\'s open floodplains.',
    ],
    [
        'group'          => 'Wellworth Collection',
        'dest_code'      => 'ZNZB',
        'name'           => 'Wellworth Zanzibar Beach Resort',
        'category'       => 'luxury',
        'lodge_type'     => 'hotel',
        'website'        => 'https://wellworthcollection.co.tz/zanzibar-beach/',
        'description_en' => 'A luxury beach resort on the shores of Zanzibar featuring white sand beaches, tropical gardens, and elegant accommodation. Offers water sports, spa, multiple dining options, and easy access to Stone Town and the island\'s spice plantations.',
    ],
    [
        'group'          => 'Wellworth Collection',
        'dest_code'      => 'SNP',   // northern Serengeti — not the airstrip
        'name'           => 'Ole Serai Kogatende',
        'category'       => 'luxury',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://wellworthcollection.co.tz/ole-serai-kogatende/',
        'description_en' => 'A luxury tented camp in the remote northern Serengeti near Kogatende, positioned in prime territory for witnessing the dramatic river crossings of the Great Wildebeest Migration. Offers spacious tents, fine dining, hot air balloon safaris, and expert-guided game drives.',
    ],
    [
        'group'          => 'Wellworth Collection',
        'dest_code'      => 'SNP',
        'name'           => 'Ole Serai Seronera',
        'category'       => 'luxury',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://wellworthcollection.co.tz/ole-serai-seronera/',
        'description_en' => 'A luxury tented camp in the heart of Serengeti National Park near Seronera, centrally located for year-round wildlife viewing. Famous for resident leopards, lions, and cheetahs. Offers fine dining, a full bar, and expert-guided game drives departing directly from camp.',
    ],
    [
        'group'          => 'Wellworth Collection',
        'dest_code'      => 'SNP',
        'name'           => 'Ole Serai Moru Kopjes',
        'category'       => 'luxury',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://wellworthcollection.co.tz/ole-serai-moru-kopjes/',
        'description_en' => 'A luxury tented camp set among the ancient granite kopjes of the southern Serengeti. The Moru area is home to black rhino, lion prides, and ancient rock paintings. Features spacious tents, fine dining, guided walking safaris, and exceptional big cat sightings in a remote setting.',
    ],
    [
        'group'          => 'Wellworth Collection',
        'dest_code'      => 'SNP',
        'name'           => 'Ole Serai Turner Springs',
        'category'       => 'luxury',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://wellworthcollection.co.tz/ole-serai-turner-springs/',
        'description_en' => 'A luxury tented camp in the western Serengeti near Turner Springs, offering exclusive access to a remote area of the park with exceptional predator sightings and diverse birdlife. Features luxury tents, gourmet dining, guided game drives, and sundowner experiences on the open plains.',
    ],

    // ── KARIBU CAMPS & LODGES ────────────────────────────────────────────────
    [
        'group'          => 'Karibu Camps & Lodges',
        'dest_code'      => 'NCA',
        'name'           => "Ngorongoro Lion's Paw",
        'category'       => 'luxury',
        'lodge_type'     => 'lodge',
        'website'        => 'https://karibucamps.com/lions-paw/',
        'description_en' => 'A luxury lodge on the eastern rim of the Ngorongoro Crater with direct views of Lake Magadi and the caldera below. Located 10 minutes from the crater entrance, guests can spot tusked elephants and black rhinos from the bar and lounge. Offers bush dinners, guided crater descents, and an intimate atmosphere in one of Africa\'s most iconic landscapes.',
    ],
    [
        'group'          => 'Karibu Camps & Lodges',
        'dest_code'      => 'SNP',
        'name'           => 'Serengeti Woodlands Camp',
        'category'       => 'luxury',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://karibucamps.com/woodlands/',
        'description_en' => 'A luxury permanent tented camp nestled in the woodlands of the Serengeti, offering an intimate and immersive safari experience. Positioned for excellent wildlife viewing throughout the year, with expert guides, gourmet cuisine, and a warm personal atmosphere that captures the essence of classic East African safari.',
    ],
    [
        'group'          => 'Karibu Camps & Lodges',
        'dest_code'      => 'SNP',
        'name'           => 'Serengeti Sametu Camp',
        'category'       => 'luxury',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://karibucamps.com/sametu-camp/',
        'description_en' => 'A luxury tented camp in the central Serengeti offering undisturbed serenity and prime wildlife viewing. Combines spacious luxury tents with expert-led game drives, bush walks, and sundowner experiences. Positioned for year-round big cat sightings in one of the Serengeti\'s most productive game zones.',
    ],
    [
        'group'          => 'Karibu Camps & Lodges',
        'dest_code'      => 'SNP',
        'name'           => 'Serengeti Mara River Camp',
        'category'       => 'luxury',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://karibucamps.com/river-camp/',
        'description_en' => 'A luxury tented camp on the banks of the Mara River in the northern Serengeti, strategically positioned to witness the dramatic wildebeest river crossings during the Great Migration. Features spacious tents with river views, fine dining, and expert guides who know the best crossing points.',
    ],
    [
        'group'          => 'Karibu Camps & Lodges',
        'dest_code'      => 'TRP',
        'name'           => 'Tarangire Elephant Springs',
        'category'       => 'luxury',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://karibucamps.com/elephant-springs/',
        'description_en' => 'A luxury camp nestled in the heart of Tarangire where towering baobabs line the banks of the Tarangire River. Suites blend stone architecture with open skies. Elephants regularly stroll past the camp and the savannah buzzes with wildlife. Offers an understated, elegant retreat deeply connected to the wild.',
    ],

    // ── ELEWANA COLLECTION (Tanzania only) ──────────────────────────────────
    [
        'group'          => 'Elewana Collection',
        'dest_code'      => 'ARU',
        'name'           => 'Elewana Arusha Coffee Lodge',
        'category'       => 'luxury',
        'lodge_type'     => 'lodge',
        'website'        => 'https://www.elewanacollection.com/arusha-coffee-lodge/at-a-glance',
        'description_en' => 'A boutique luxury lodge set on a working coffee estate on the slopes of Mount Meru, minutes from Arusha town. Features elegant cottages surrounded by coffee trees, an award-winning farm-to-table restaurant, a spa, and manicured gardens. The ideal start or end point for a Northern Circuit safari.',
    ],
    [
        'group'          => 'Elewana Collection',
        'dest_code'      => 'TRP',
        'name'           => 'Elewana Tarangire Treetops',
        'category'       => 'ultra_luxury',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://www.elewanacollection.com/tarangire-treetops/at-a-glance',
        'description_en' => 'An iconic ultra-luxury camp in Tarangire National Park where 20 treehouses are built among giant baobab and marula trees. Each treehouse blends into the forest canopy with open-air baths and sweeping savannah views. Offers the highest level of personalised service, guided walks, and extraordinary stargazing.',
    ],
    [
        'group'          => 'Elewana Collection',
        'dest_code'      => 'NCA',
        'name'           => 'Elewana The Manor at Ngorongoro',
        'category'       => 'ultra_luxury',
        'lodge_type'     => 'house',
        'website'        => 'https://www.elewanacollection.com/the-manor-at-ngorongoro/at-a-glance',
        'description_en' => 'A gracious colonial manor house on a coffee and wheat farm on the slopes above the Ngorongoro Conservation Area. Evokes the atmosphere of old East Africa with antique furnishings, log fires, and warm hospitality. Features 18 suites, fine dining, a billiard room, and guided excursions into the Crater and surrounding wilderness.',
    ],
    [
        'group'          => 'Elewana Collection',
        'dest_code'      => 'SNP',
        'name'           => 'Elewana Serengeti Pioneer Camp',
        'category'       => 'ultra_luxury',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://www.elewanacollection.com/serengeti-pioneer-camp/at-a-glance',
        'description_en' => 'An intimate ultra-luxury camp in the central Serengeti evoking the golden era of East African exploration. Just 9 classic canvas tents under thatch with four-poster beds, copper baths, and en-suite bathrooms. Offers exceptional personalised guiding, Maasai village walks, and bush breakfasts in one of the Serengeti\'s most wildlife-rich zones.',
    ],
    [
        'group'          => 'Elewana Collection',
        'dest_code'      => 'SNP',
        'name'           => 'Elewana Serengeti Migration Camp',
        'category'       => 'ultra_luxury',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://www.elewanacollection.com/serengeti-migration-camp/at-a-glance',
        'description_en' => 'A luxury semi-permanent camp in the northern Serengeti positioned on a ridge above the Grumeti River valley. Features 20 spacious tents on raised platforms with panoramic views, a pool, and expert guides specialising in tracking the wildebeest migration.',
    ],
    [
        'group'          => 'Elewana Collection',
        'dest_code'      => 'ZNZB',
        'name'           => 'Elewana Kilindi Zanzibar',
        'category'       => 'ultra_luxury',
        'lodge_type'     => 'hotel',
        'website'        => 'https://www.kilindizanzibar.com/',
        'description_en' => 'An ultra-luxury boutique retreat on the unspoiled northwest coast of Zanzibar, set in 50 acres of tropical gardens fronting a private beach. Features 15 spacious pavilions with private plunge pools, an award-winning restaurant, a spa, and water sports. One of Zanzibar\'s most exclusive addresses.',
    ],

    // ── SOPA LODGES ──────────────────────────────────────────────────────────
    [
        'group'          => 'Sopa Lodges',
        'dest_code'      => 'TRP',
        'name'           => 'Tarangire Sopa Lodge',
        'category'       => 'mid',
        'lodge_type'     => 'lodge',
        'website'        => 'https://www.sopalodges.com/tarangire-sopa-lodge/the-lodge',
        'description_en' => 'A well-established lodge hidden among ancient baobab trees and kopjes inside Tarangire National Park. Features comfortable rooms with private verandas, a swimming pool, restaurant, and bar. Located 129 km from Arusha with year-round elephant sightings around the lodge.',
    ],

    // ── PLANET LODGES & LAIRS CAMPS ──────────────────────────────────────────
    [
        'group'          => 'Planet Lodges',
        'dest_code'      => 'ARU',
        'name'           => 'Airport Planet Lodge',
        'category'       => 'mid',
        'lodge_type'     => 'hotel',
        'website'        => 'https://planet-lodges.com/lodges/airport-planet-lodge/',
        'description_en' => 'A 3-star lodge between Moshi and Arusha, just 12 minutes from Kilimanjaro International Airport. Features African-style chalets in tropical gardens, restaurant, bar, swimming pool, and spa. The perfect overnight stop before or after a safari or Kilimanjaro climb.',
    ],
    [
        'group'          => 'Planet Lodges',
        'dest_code'      => 'ARU',
        'name'           => 'Arusha Planet Lodge',
        'category'       => 'mid',
        'lodge_type'     => 'lodge',
        'website'        => 'https://planet-lodges.com/lodges/arusha-planet-lodge/',
        'description_en' => 'A comfortable 3-4 star lodge in Arusha with a swimming pool, restaurant, bar, and spa. Well located for safari departures to the Northern Circuit and for acclimatisation before a Kilimanjaro expedition.',
    ],
    [
        'group'          => 'Planet Lodges',
        'dest_code'      => 'SNP',
        'name'           => 'Elephants Lair Camp',
        'category'       => 'mid',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://planet-lodges.com/elephants-lair-camp/',
        'description_en' => 'A tented camp in the Serengeti offering an authentic safari experience at accessible rates. Features comfortable furnished tents, meals, and guided game drives. An affordable option for travellers seeking the classic Serengeti experience.',
    ],
    [
        'group'          => 'Planet Lodges',
        'dest_code'      => 'SNP',
        'name'           => 'Gnus Lair Camp',
        'category'       => 'mid',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://planet-lodges.com/gnus-lair/',
        'description_en' => 'A tented camp in the Serengeti ideally positioned for witnessing the Great Wildebeest Migration. Features comfortable tents and guided game drives following the gnu herds across the Serengeti plains.',
    ],
    [
        'group'          => 'Planet Lodges',
        'dest_code'      => 'SNP',
        'name'           => "Jackals Lair Camp",
        'category'       => 'mid',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://planet-lodges.com/lodges/jackals-lair-camp/',
        'description_en' => 'A tented camp in the Serengeti offering comfortable accommodation and guided game drives at competitive rates. Good location for year-round wildlife viewing across the Serengeti ecosystem.',
    ],

    // ── ASILIA AFRICA ────────────────────────────────────────────────────────
    [
        'group'          => 'Asilia Africa',
        'dest_code'      => 'SNP',
        'name'           => 'Dunia Camp',
        'category'       => 'luxury',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://www.asiliaafrica.com/our-camps-lodges/dunia-camp/',
        'description_en' => 'An intimate luxury tented camp in the heart of the Serengeti with just 8 spacious tents and panoramic views over the plains. Celebrated for outstanding year-round wildlife — resident lions, leopards, cheetahs, and elephants — and for exceptional personalised guiding. Offers fly-camping extensions and authentic bush immersion.',
    ],
    [
        'group'          => 'Asilia Africa',
        'dest_code'      => 'SNP',   // northern Serengeti — not the airstrip
        'name'           => 'Sayari Camp',
        'category'       => 'luxury',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://www.asiliaafrica.com/our-camps-lodges/sayari-camp/',
        'description_en' => 'A flagship luxury camp in the far north of the Serengeti, renowned as one of the best positions for witnessing the Great Migration river crossings. Features 15 spacious tents on raised platforms with sweeping views, a pool, top-rated cuisine, and expert guides with deep knowledge of the northern ecosystem.',
    ],
    [
        'group'          => 'Asilia Africa',
        'dest_code'      => 'RNP',
        'name'           => 'Jongomero Camp',
        'category'       => 'ultra_luxury',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://www.asiliaafrica.com/our-camps-lodges/jongomero/',
        'description_en' => 'An ultra-exclusive remote camp in the southern Ruaha National Park beside the seasonal Jongomero River. Just 8 spacious tents with outstanding predator and elephant sightings. Features a pool, gourmet bush dining, fly-camping, and walking safaris in genuine off-the-beaten-track Africa.',
    ],
    [
        'group'          => 'Asilia Africa',
        'dest_code'      => 'SNP',
        'name'           => 'Ubuntu Migration Camp',
        'category'       => 'luxury',
        'lodge_type'     => 'mobile_camp',
        'website'        => 'https://www.asiliaafrica.com/our-camps-lodges/ubuntu-migration-camp/',
        'description_en' => 'A semi-permanent luxury mobile camp that follows the Great Migration across the Serengeti throughout the year, ensuring guests are always in the heart of the action. Features canvas tents with proper beds, en-suite bathrooms, and expert migration guides.',
    ],
    [
        'group'          => 'Asilia Africa',
        'dest_code'      => 'SNP',   // eastern Serengeti — not Ndutu airstrip
        'name'           => 'Namiri Plains Camp',
        'category'       => 'luxury',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://www.asiliaafrica.com/our-camps-lodges/namiri-plains/',
        'description_en' => 'A luxury camp in the exclusive Namiri Plains concession in the eastern Serengeti, an area famous for the highest density of cheetahs in East Africa. Features 8 tents on a rocky ridge with sweeping grassland views, exceptional big cat guiding, and access to a private concession with no day-visitors.',
    ],

    // ── TARANGIRE SAFARI LODGE ───────────────────────────────────────────────
    [
        'group'          => 'Tarangire Safari Lodge',
        'dest_code'      => 'TRP',
        'name'           => 'Tarangire Safari Lodge',
        'category'       => 'mid',
        'lodge_type'     => 'lodge',
        'website'        => 'https://www.tarangiresafarilodge.com/',
        'description_en' => 'A family-owned and managed lodge inside Tarangire National Park since 1985, renowned for its panoramic views over the Tarangire River valley. Features comfortable rooms and tented accommodation, a restaurant and bar, swimming pool, and guided game drives. One of the original lodges in the park, ideal for wildlife enthusiasts and those seeking a classic Tanzanian safari.',
    ],

    // ── MTONI RIVER LODGE ────────────────────────────────────────────────────
    [
        'group'          => 'Mtoni River Lodge',
        'dest_code'      => 'ARU',
        'name'           => 'Mtoni River Lodge',
        'category'       => 'luxury',
        'lodge_type'     => 'lodge',
        'website'        => 'https://mtoniriverlodge.com/',
        'description_en' => 'An intimate luxury eco-lodge on the banks of the Nduruma River in Arusha, with 24 rooms inspired by Maasai boma design featuring circular forms, natural textures, and private river-facing decks. Offers fireside dining, guided walks, cycling, canoeing on Lake Duluti, and a tranquil escape before or after safari. Mount Meru is visible in the distance at dawn.',
    ],

    // ── GOLD CREST HOTEL ARUSHA ──────────────────────────────────────────────
    [
        'group'          => 'Gold Crest Hotel',
        'dest_code'      => 'ARU',
        'name'           => 'Gold Crest Hotel Arusha',
        'category'       => 'mid',
        'lodge_type'     => 'hotel',
        'website'        => 'https://www.goldcresthotel.com/arusha/',
        'description_en' => 'A boutique all-suites hotel and conference centre on Old Moshi Road, 1 km from central Arusha with views of snow-capped Mount Kilimanjaro. Features comfortable suites, gymnasium, swimming pool, restaurant, 24-hour room service, airport shuttle, and state-of-the-art meeting facilities. An ideal base for Northern Circuit safaris and Kilimanjaro expeditions.',
    ],

    // ── WILDLANDS CAMPS & LODGES ─────────────────────────────────────────────
    [
        'group'          => 'Wildlands Camps & Lodges',
        'dest_code'      => 'ARU',
        'name'           => 'Moyoni Airport Lodge',
        'category'       => 'mid',
        'lodge_type'     => 'lodge',
        'website'        => 'https://wildlandscampsandlodges.com/moyoni-airport-lodge/',
        'description_en' => 'A comfortable lodge near Kilimanjaro International Airport offering a welcoming gateway to Tanzania\'s natural beauty. Features well-appointed rooms in lush gardens, restaurant, bar, and easy access to Arusha and the Northern Circuit national parks. Ideal for overnight stays between flights and safaris.',
    ],
    [
        'group'          => 'Wildlands Camps & Lodges',
        'dest_code'      => 'NCA',
        'name'           => 'Ngorongoro Forest Tented Lodge',
        'category'       => 'mid',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://wildlandscampsandlodges.com/ngorongoro-forest-tented-lodge/',
        'description_en' => 'A tented lodge hidden away in the forest on the rim of the Ngorongoro Conservation Area. Features comfortable tents tucked among the trees, offering a peaceful and atmospheric retreat close to one of Africa\'s most spectacular wildlife areas.',
    ],
    [
        'group'          => 'Wildlands Camps & Lodges',
        'dest_code'      => 'SNP',
        'name'           => 'Serengeti Wildlands Camp',
        'category'       => 'mid',
        'lodge_type'     => 'mobile_camp',
        'website'        => 'https://wildlandscampsandlodges.com/serengeti-wildlands-camp/',
        'description_en' => 'A mobile tented camp in the Serengeti offering an authentic and flexible safari experience. Positioned to follow seasonal wildlife movements, the camp provides comfortable tents, guided game drives, and an unforgettable immersion in the Serengeti ecosystem at accessible rates.',
    ],
    [
        'group'          => 'Wildlands Camps & Lodges',
        'dest_code'      => 'SNP',
        'name'           => 'Ndutu Wildlands Camp',
        'category'       => 'mid',
        'lodge_type'     => 'mobile_camp',
        'website'        => 'https://wildlandscampsandlodges.com/ndutu-wildlands-camp-2/',
        'description_en' => 'A mobile tented camp strategically located in the Ndutu area of the southern Serengeti, in the direct path of the wildebeest migration calving season (December–March). Offers comfortable tents, guided game drives, and excellent opportunities to witness one of nature\'s most dramatic events.',
    ],
    [
        'group'          => 'Wildlands Camps & Lodges',
        'dest_code'      => 'NAT',
        'name'           => 'Natron River Camp',
        'category'       => 'mid',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://wildlandscampsandlodges.com/natron-river-camp/',
        'description_en' => 'A tented camp beside the river at Lake Natron, well off the beaten track in one of Tanzania\'s most dramatic landscapes. The lake is home to over 2.5 million endangered lesser flamingos. Offers guided walks to Ol Doinyo Lengai volcano, waterfall hikes, Maasai cultural visits, and an extraordinary remote wilderness experience.',
    ],
    [
        'group'          => 'Wildlands Camps & Lodges',
        'dest_code'      => 'SNP',
        'name'           => 'Makoma Ndogo Camp',
        'category'       => 'mid',
        'lodge_type'     => 'tented_camp',
        'website'        => 'https://wildlandscampsandlodges.com/makoma-ndogo-camp/',
        'description_en' => 'A semi-permanent safari camp in the Serengeti designed for those who value comfort, calm, and a deep connection to nature. Features well-appointed tents in a tranquil bush setting, with guided game drives and personalised service in one of the world\'s greatest wildlife destinations.',
    ],
];

// ── Add dest_id and duplicate flag ───────────────────────────────────────────
foreach ($LODGES as &$l) {
    $l['dest_id']      = did($l['dest_code']);
    $l['is_duplicate'] = in_array(strtolower($l['name']), $existing);
}
unset($l);

// ── Group by partner ─────────────────────────────────────────────────────────
$by_group = [];
foreach ($LODGES as $i => $l) {
    $l['_idx'] = $i;
    $by_group[$l['group']][] = $l;
}

// ── POST: import ─────────────────────────────────────────────────────────────
$import_log  = [];
$import_done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $stmt = $db->prepare(
        'INSERT INTO iti_lodges
         (destination_id,name,category,lodge_type,website,description_en,is_active)
         VALUES (?,?,?,?,?,?,1)'
    );
    $ok = $skip = $err = 0;
    foreach ($LODGES as $i => $l) {
        if (isset($_POST["skip_{$i}"])) { $skip++; continue; }
        $dest_id = (int)($_POST["dest_{$i}"] ?? $l['dest_id']);
        if (!$dest_id) {
            $import_log[] = "⚠ No destination — skipped: {$l['name']}";
            $skip++; continue;
        }
        if (in_array(strtolower($l['name']), $existing)) {
            $import_log[] = "⏭ Already exists — skipped: {$l['name']}";
            $skip++; continue;
        }
        try {
            $cat  = $_POST["cat_{$i}"]  ?? $l['category'];
            $type = $_POST["type_{$i}"] ?? $l['lodge_type'];
            $stmt->execute([$dest_id, $l['name'], $cat, $type, $l['website'], $l['description_en']]);
            $existing[]   = strtolower($l['name']);
            $import_log[] = "✅ Imported: {$l['name']} ({$dest_by_id[$dest_id]})";
            $ok++;
        } catch (Exception $e) {
            $import_log[] = "❌ Error — {$l['name']}: " . $e->getMessage();
            $err++;
        }
    }
    $import_log[] = "─── Done: {$ok} imported, {$skip} skipped, {$err} errors ───";
    $import_done  = true;
}

// ── Page ─────────────────────────────────────────────────────────────────────
$page_title = 'Import Partner Lodges';
$extra_css  = iti_extra_css() . '
.group-header{background:var(--green);color:#fff;padding:8px 14px;font-size:.75rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;border-radius:6px 6px 0 0;margin-top:20px;}
.import-table{width:100%;border-collapse:collapse;background:#fff;font-size:.78rem;border:1px solid var(--grey-lt);}
.import-table th{background:#f0f0ef;padding:7px 10px;text-align:left;font-size:.71rem;white-space:nowrap;border-bottom:1.5px solid var(--grey-lt);}
.import-table td{padding:8px 10px;border-bottom:1px solid #f0f0ef;vertical-align:middle;}
.import-table tr.dup td{background:#fffbeb;}
.badge-dup{display:inline-block;padding:1px 7px;border-radius:4px;background:#FEF3C7;color:#92400E;font-size:.65rem;font-weight:700;margin-left:6px;vertical-align:middle;}
.badge-nodest{background:#fee2e2;color:#991b1b;}
.log-box{background:#1a1a1a;color:#a8e6a3;font-family:monospace;font-size:.75rem;padding:14px 16px;border-radius:8px;max-height:220px;overflow-y:auto;white-space:pre-wrap;margin:12px 0;}
';
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
<?php iti_nav('Import Partner Lodges'); ?>
<?php iti_flash_render(); ?>

<div class="page-header">
  <div>
    <h2>🏕️ Import Partner Lodges</h2>
    <div class="sub">Master Data › Lodges › Partner Import — <?= count($LODGES) ?> lodges from <?= count($by_group) ?> partners</div>
  </div>
  <a href="lodges.php" class="btn btn-outline btn-sm">← Back to Lodges</a>
</div>

<?php if ($import_done): ?>
<div class="form-card">
  <div class="form-section-title">Import Result</div>
  <div class="log-box"><?= implode("\n", array_map('htmlspecialchars', $import_log)) ?></div>
  <div style="margin-top:16px;display:flex;gap:10px;">
    <a href="lodges.php" class="btn btn-red">→ View All Lodges</a>
    <a href="iti_import_lodges_web.php" class="btn btn-outline">↩ Import again</a>
  </div>
</div>

<?php else: ?>

<form method="POST" action="iti_import_lodges_web.php">
<div class="form-card">
  <div class="form-section-title">Review &amp; Import</div>
  <p style="font-size:.8rem;color:var(--grey-mid);margin-bottom:4px;">
    Review each lodge. Adjust destination, category, or type if needed. Check <strong>Skip</strong> to exclude.
    <span class="badge-dup">EXISTS</span> = already in DB (pre-checked for skip).
    <span class="badge-dup badge-nodest">NO DEST</span> = assign a destination or it will be skipped.
  </p>
  <p style="font-size:.78rem;color:var(--grey-mid);margin-bottom:16px;">
    <strong><?= count(array_filter($LODGES, fn($l) => !$l['is_duplicate'])) ?></strong> new lodges ready to import
    &nbsp;·&nbsp; <strong><?= count(array_filter($LODGES, fn($l) => $l['is_duplicate'])) ?></strong> already in database
  </p>

  <?php foreach ($by_group as $group => $lodges): ?>
  <div class="group-header"><?= h($group) ?> <span style="font-weight:400;opacity:.8;"><?= count($lodges) ?> lodges</span></div>
  <table class="import-table" style="border-radius:0 0 6px 6px;margin-bottom:0;">
    <thead>
      <tr>
        <th style="width:36px;text-align:center;">Skip</th>
        <th>Lodge Name</th>
        <th style="min-width:180px;">Destination</th>
        <th>Category</th>
        <th>Type</th>
        <th style="max-width:300px;">Description</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($lodges as $l):
      $i      = $l['_idx'];
      $is_dup = $l['is_duplicate'];
      $no_dst = !$l['dest_id'];
    ?>
    <tr class="<?= $is_dup ? 'dup' : '' ?>">
      <td style="text-align:center;">
        <input type="checkbox" name="skip_<?= $i ?>" value="1" <?= $is_dup ? 'checked' : '' ?>>
      </td>
      <td>
        <strong><?= h($l['name']) ?></strong>
        <?php if ($is_dup): ?><span class="badge-dup">EXISTS</span><?php endif; ?>
        <?php if ($no_dst && !$is_dup): ?><span class="badge-dup badge-nodest">NO DEST</span><?php endif; ?>
        <div style="font-size:.68rem;color:var(--grey-mid);margin-top:2px;">
          <a href="<?= h($l['website']) ?>" target="_blank" rel="noopener"><?= h($l['website']) ?></a>
        </div>
      </td>
      <td>
        <select name="dest_<?= $i ?>" style="font-size:.78rem;">
          <option value="">— Select —</option>
          <?php foreach ($dest_by_id as $did => $dname): ?>
          <option value="<?= $did ?>" <?= $did==$l['dest_id']?'selected':'' ?>><?= h($dname) ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td>
        <select name="cat_<?= $i ?>" style="font-size:.78rem;">
          <?php foreach (['budget'=>'Budget','mid'=>'Mid-range','luxury'=>'Luxury','ultra_luxury'=>'Ultra Luxury'] as $cv=>$cl): ?>
          <option value="<?= $cv ?>" <?= $l['category']===$cv?'selected':'' ?>><?= $cl ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td>
        <select name="type_<?= $i ?>" style="font-size:.78rem;">
          <?php foreach (['lodge'=>'Lodge','tented_camp'=>'Tented Camp','hotel'=>'Hotel','mobile_camp'=>'Mobile Camp','house'=>'House'] as $tv=>$tl): ?>
          <option value="<?= $tv ?>" <?= $l['lodge_type']===$tv?'selected':'' ?>><?= $tl ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td style="font-size:.71rem;color:#555;max-width:300px;">
        <?= h(mb_substr($l['description_en'], 0, 160)) ?>…
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endforeach; ?>

  <?php if ($can_edit): ?>
  <div style="margin-top:20px;display:flex;gap:10px;align-items:center;">
    <button type="submit" class="btn btn-red">⬆ Import Selected Lodges</button>
    <a href="lodges.php" class="btn btn-outline">Cancel</a>
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
