-- Seed lodge GPS coordinates for the itinerary map.
-- Sources: voucher GPS (exact), Google Maps place pins, OpenStreetMap.
-- Idempotent-ish: plain UPDATEs by id; safe to re-run.
-- A few rows are flagged CHECK (name variant or pin further from the expected
-- area than usual) — verify those before relying on them. 4 mobile Serengeti
-- camps have no fixed pin and are intentionally left NULL (map falls back to
-- the destination centre).
USE savannp5_savannah_leads;

-- ── Exact (voucher GPS) ──────────────────────────────────────────────
UPDATE iti_lodges SET latitude=-3.289197, longitude=35.723346 WHERE id=2;  -- Ngorongoro Marera Mountain View Lodge
UPDATE iti_lodges SET latitude=-2.357000, longitude=34.757000 WHERE id=3;  -- Serengeti Kifaru Tented Lodge
UPDATE iti_lodges SET latitude=-3.393042, longitude=36.677938 WHERE id=4;  -- Arusha Explorers Lodge

-- ── Arusha Town ──────────────────────────────────────────────────────
UPDATE iti_lodges SET latitude=-3.3764527, longitude=37.0338966 WHERE id=27; -- Airport Planet Lodge (@ Kilimanjaro Airport)
UPDATE iti_lodges SET latitude=-3.4004868, longitude=36.7401233 WHERE id=28; -- Arusha Planet Lodge
UPDATE iti_lodges SET latitude=-3.374565,  longitude=36.643384  WHERE id=20; -- Elewana Arusha Coffee Lodge
UPDATE iti_lodges SET latitude=-3.376288,  longitude=36.700463  WHERE id=39; -- Gold Crest Hotel Arusha
UPDATE iti_lodges SET latitude=-3.341376,  longitude=37.057347  WHERE id=40; -- Moyoni Airport Lodge
UPDATE iti_lodges SET latitude=-3.4357952, longitude=36.7943067 WHERE id=38; -- Mtoni River Lodge

-- ── Karatu ───────────────────────────────────────────────────────────
UPDATE iti_lodges SET latitude=-3.338018, longitude=35.659565 WHERE id=46; -- Bougainvillea Safari Lodge
UPDATE iti_lodges SET latitude=-3.339528, longitude=35.659638 WHERE id=47; -- Country Lodge Karatu
UPDATE iti_lodges SET latitude=-3.323903, longitude=35.710279 WHERE id=50; -- Marera Valley Lodge
UPDATE iti_lodges SET latitude=-3.303008, longitude=35.699509 WHERE id=51; -- Ngorongoro Coffee Lodge

-- ── Lake Eyasi / Manyara / Natron ────────────────────────────────────
UPDATE iti_lodges SET latitude=-3.486507,  longitude=35.361373  WHERE id=49; -- Lake Eyasi Safari Lodge
UPDATE iti_lodges SET latitude=-3.3455137, longitude=35.8343058 WHERE id=7;  -- Lake Manyara Kilimamoja Lodge
UPDATE iti_lodges SET latitude=-2.6198563, longitude=35.8798409 WHERE id=44; -- Natron River Camp  [CHECK: pin ~35km S of Lake Natron]

-- ── Mikumi / Ruaha ───────────────────────────────────────────────────
UPDATE iti_lodges SET latitude=-7.345133,  longitude=37.1359446 WHERE id=9;  -- Mikumi Wildlife Lodge (Mikumi Wildlife Camp)
UPDATE iti_lodges SET latitude=-7.906902,  longitude=34.570056  WHERE id=34; -- Jongomero Camp (Ruaha)

-- ── Ngorongoro Conservation Area ─────────────────────────────────────
UPDATE iti_lodges SET latitude=-3.3022948, longitude=35.6407725 WHERE id=22; -- Elewana The Manor at Ngorongoro
UPDATE iti_lodges SET latitude=-3.303928,  longitude=35.700993  WHERE id=41; -- Ngorongoro Forest Tented Lodge
UPDATE iti_lodges SET latitude=-3.1255987, longitude=35.6613691 WHERE id=15; -- Ngorongoro Lion's Paw
UPDATE iti_lodges SET latitude=-3.382691,  longitude=35.597108  WHERE id=5;  -- Ngorongoro Oldeani Mountain Lodge

-- ── Serengeti National Park ──────────────────────────────────────────
UPDATE iti_lodges SET latitude=-2.677720,  longitude=34.711462  WHERE id=32; -- Dunia Camp
UPDATE iti_lodges SET latitude=-1.9269212, longitude=35.0200477 WHERE id=24; -- Elewana Serengeti Migration Camp
UPDATE iti_lodges SET latitude=-2.6703242, longitude=34.7373188 WHERE id=23; -- Elewana Serengeti Pioneer Camp
UPDATE iti_lodges SET latitude=-1.6471273, longitude=34.8688137 WHERE id=30; -- Gnus Lair Camp
UPDATE iti_lodges SET latitude=-2.305815,  longitude=34.833781  WHERE id=53; -- Hippo Trails Camp
UPDATE iti_lodges SET latitude=-2.3695625, longitude=35.0409375 WHERE id=31; -- Jackals Lair Camp
UPDATE iti_lodges SET latitude=-2.4873534, longitude=34.6833765 WHERE id=45; -- Makoma Ndogo Camp  [CHECK: matched "Serengeti Makoma Luxury Tented Lodge"]
UPDATE iti_lodges SET latitude=-2.5501727, longitude=35.1016731 WHERE id=36; -- Namiri Plains Camp
UPDATE iti_lodges SET latitude=-2.9826433, longitude=34.973406  WHERE id=43; -- Ndutu Wildlands Camp  [CHECK: matched "Ndutu Wilderness Camp", same Ndutu area]
UPDATE iti_lodges SET latitude=-1.579710,  longitude=34.880748  WHERE id=11; -- Ole Serai Kogatende
UPDATE iti_lodges SET latitude=-2.618900,  longitude=34.732399  WHERE id=13; -- Ole Serai Moru Kopjes
UPDATE iti_lodges SET latitude=-2.494724,  longitude=34.771773  WHERE id=12; -- Ole Serai Seronera
UPDATE iti_lodges SET latitude=-2.437130,  longitude=34.933661  WHERE id=14; -- Ole Serai Turner Springs
UPDATE iti_lodges SET latitude=-1.581750,  longitude=34.909514  WHERE id=33; -- Sayari Camp
UPDATE iti_lodges SET latitude=-2.641811,  longitude=34.781065  WHERE id=8;  -- Serengeti Lake Magadi Lodge
UPDATE iti_lodges SET latitude=-1.597048,  longitude=34.810029  WHERE id=18; -- Serengeti Mara River Camp
UPDATE iti_lodges SET latitude=-2.475411,  longitude=34.993214  WHERE id=17; -- Serengeti Sametu Camp
UPDATE iti_lodges SET latitude=-2.835847,  longitude=34.996846  WHERE id=16; -- Serengeti Woodlands Camp
UPDATE iti_lodges SET latitude=-2.367189,  longitude=34.838185  WHERE id=52; -- Thorn Tree Camp
-- No fixed Google pin (mobile camps) -> left NULL, map uses destination centre:
--   id 1  Serengeti Orangi River Luxury Lodge
--   id 42 Serengeti Wildlands Camp
--   id 54 Tamba Tented Camp Serengeti
--   id 35 Ubuntu Migration Camp

-- ── Tarangire National Park ──────────────────────────────────────────
UPDATE iti_lodges SET latitude=-3.8051558, longitude=36.0868725 WHERE id=29; -- Elephants Lair Camp
UPDATE iti_lodges SET latitude=-3.775041,  longitude=36.154426  WHERE id=21; -- Elewana Tarangire Treetops
UPDATE iti_lodges SET latitude=-3.9508615, longitude=35.8657915 WHERE id=48; -- Sangaiwe Tented Lodge
UPDATE iti_lodges SET latitude=-4.0056077, longitude=36.0060816 WHERE id=19; -- Tarangire Elephant Springs
UPDATE iti_lodges SET latitude=-3.9645752, longitude=36.0538707 WHERE id=6;  -- Tarangire Kuro Treetops Lodge
UPDATE iti_lodges SET latitude=-3.770454,  longitude=36.021304  WHERE id=37; -- Tarangire Safari Lodge
UPDATE iti_lodges SET latitude=-3.907386,  longitude=36.095077  WHERE id=26; -- Tarangire Sopa Lodge

-- ── Zanzibar ─────────────────────────────────────────────────────────
UPDATE iti_lodges SET latitude=-5.760256,  longitude=39.2903384 WHERE id=25; -- Elewana Kilindi Zanzibar
UPDATE iti_lodges SET latitude=-5.744463,  longitude=39.290482  WHERE id=62; -- Hotel RIU Jambo
UPDATE iti_lodges SET latitude=-5.747510,  longitude=39.290968  WHERE id=61; -- Hotel RIU Palace Zanzibar
UPDATE iti_lodges SET latitude=-5.9924869, longitude=39.3797733 WHERE id=55; -- Mvuvi Boutique Resort
UPDATE iti_lodges SET latitude=-5.735272,  longitude=39.291154  WHERE id=65; -- My Blue Hotel Zanzibar
UPDATE iti_lodges SET latitude=-5.7369367, longitude=39.2924201 WHERE id=66; -- Royal Zanzibar Beach Resort
UPDATE iti_lodges SET latitude=-5.850951,  longitude=39.356408  WHERE id=59; -- SeVi Boutique Hotel Zanzibar
UPDATE iti_lodges SET latitude=-5.730597,  longitude=39.291611  WHERE id=63; -- The Z Hotel Zanzibar
UPDATE iti_lodges SET latitude=-5.7302444, longitude=39.292403  WHERE id=64; -- The Z2 Hotel Zanzibar
UPDATE iti_lodges SET latitude=-5.7243434, longitude=39.2950801 WHERE id=60; -- Turaco Nungwi Resort
UPDATE iti_lodges SET latitude=-5.8725768, longitude=39.3531664 WHERE id=56; -- Villa Kiva Hotel  [CHECK: pin on SE coast, Villa Kiva is usually Kendwa NW]
UPDATE iti_lodges SET latitude=-6.2028167, longitude=39.2064645 WHERE id=10; -- Wellworth Zanzibar Beach Resort
UPDATE iti_lodges SET latitude=-5.9830394, longitude=39.3768019 WHERE id=57; -- White Dream Zanzibar (White Dream Lodge)
UPDATE iti_lodges SET latitude=-5.8685934, longitude=39.353567  WHERE id=58; -- Zanzibar Pearl Boutique Hotel & Villas
