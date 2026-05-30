<?php
/**
 * iti_import_flight_routes.php
 * Static import of Auric Air flight routes (deduplicated — one entry per unique route).
 * Source: Auric Air rate sheet G15 Ver 1.4, valid 15 Jun 2025 – 31 Dec 2026.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db       = db();
$_cu      = current_user();
$can_edit = in_array($_cu['role_name'], ['admin', 'manager']);

// Existing routes (from+to lowercase) for duplicate detection
$existing = [];
foreach ($db->query("SELECT LOWER(CONCAT(from_airport,'|',to_airport)) AS k FROM iti_flight_routes")->fetchAll() as $r)
    $existing[] = $r['k'];

// ── Route data ────────────────────────────────────────────────────────────────
// All unique origin→destination pairs from Auric Air G15.
// duration_min = rough estimate from dep/arr times (first flight of the day).
// operator = 'Auric Air' throughout.
$ROUTES = [
    // ── FROM ARUSHA ──────────────────────────────────────────────────────────
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Chem Chem','tc'=>'','min'=>50,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Dar Es Salaam','tc'=>'DAR','min'=>115,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Entebbe','tc'=>'EBB','min'=>240,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Mafia Island','tc'=>'MFA','min'=>225,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Lake Manyara','tc'=>'LKY','min'=>25,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Pangani (Kwajoni)','tc'=>'','min'=>160,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Pemba Island','tc'=>'PMA','min'=>115,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Saadani','tc'=>'','min'=>160,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Serengeti / Fort Ikoma','tc'=>'','min'=>120,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Serengeti / Grumeti','tc'=>'GTZ','min'=>130,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Serengeti / Kogatende','tc'=>'','min'=>45,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Serengeti / Lamai','tc'=>'','min'=>160,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Serengeti / Lobo','tc'=>'','min'=>140,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Serengeti / Mwiba','tc'=>'','min'=>70,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Serengeti / Ndutu','tc'=>'','min'=>70,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Serengeti / Seronera','tc'=>'SEU','min'=>90,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Serengeti / Sasakwa','tc'=>'','min'=>110,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Serengeti / Serengeti South','tc'=>'','min'=>80,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Tanga','tc'=>'TGT','min'=>175,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Tarangire / Kuro','tc'=>'','min'=>30,'type'=>'scheduled'],
    ['from'=>'Arusha','fc'=>'ARK','to'=>'Zanzibar','tc'=>'ZNZ','min'=>65,'type'=>'scheduled'],
    // ── FROM CHEM CHEM ───────────────────────────────────────────────────────
    ['from'=>'Chem Chem','fc'=>'','to'=>'Arusha','tc'=>'ARK','min'=>30,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Dar Es Salaam','tc'=>'DAR','min'=>200,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Entebbe','tc'=>'EBB','min'=>250,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Kigali','tc'=>'KGL','min'=>190,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Kilimanjaro','tc'=>'JRO','min'=>15,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Mafia Island','tc'=>'MFA','min'=>310,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Lake Manyara','tc'=>'LKY','min'=>5,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Migori (Kenya)','tc'=>'','min'=>195,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Pangani (Kwajoni)','tc'=>'','min'=>245,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Pemba Island','tc'=>'PMA','min'=>200,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Saadani','tc'=>'','min'=>245,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Serengeti / Grumeti','tc'=>'GTZ','min'=>95,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Serengeti / Kogatende','tc'=>'','min'=>95,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Serengeti / Lamai','tc'=>'','min'=>95,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Serengeti / Lobo','tc'=>'','min'=>95,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Serengeti / Mwiba','tc'=>'','min'=>75,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Serengeti / Ndutu','tc'=>'','min'=>75,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Serengeti / Seronera','tc'=>'SEU','min'=>35,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Serengeti / Sasakwa','tc'=>'','min'=>55,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Serengeti / Serengeti South','tc'=>'','min'=>75,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Serengeti / Fort Ikoma','tc'=>'','min'=>95,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Tanga','tc'=>'TGT','min'=>255,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Tarangire / Kuro','tc'=>'','min'=>5,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Tarime','tc'=>'','min'=>115,'type'=>'scheduled'],
    ['from'=>'Chem Chem','fc'=>'','to'=>'Zanzibar','tc'=>'ZNZ','min'=>150,'type'=>'scheduled'],
    // ── FROM DAR ES SALAAM ───────────────────────────────────────────────────
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Arusha','tc'=>'ARK','min'=>110,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Chem Chem','tc'=>'','min'=>215,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Ifakara','tc'=>'','min'=>60,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Iringa','tc'=>'','min'=>90,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Mafia Island','tc'=>'MFA','min'=>30,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Lake Manyara','tc'=>'LKY','min'=>160,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Pangani (Kwajoni)','tc'=>'','min'=>70,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Pemba Island','tc'=>'PMA','min'=>70,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Ruaha','tc'=>'','min'=>155,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Saadani','tc'=>'','min'=>70,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Selous (Nyerere NP)','tc'=>'','min'=>35,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Serengeti / Fort Ikoma','tc'=>'','min'=>305,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Serengeti / Grumeti','tc'=>'GTZ','min'=>315,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Serengeti / Kogatende','tc'=>'','min'=>185,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Serengeti / Lamai','tc'=>'','min'=>345,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Serengeti / Lobo','tc'=>'','min'=>325,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Serengeti / Mwiba','tc'=>'','min'=>235,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Serengeti / Ndutu','tc'=>'','min'=>255,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Serengeti / Seronera','tc'=>'SEU','min'=>190,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Serengeti / Sasakwa','tc'=>'','min'=>295,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Serengeti / Serengeti South','tc'=>'','min'=>265,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Tanga','tc'=>'TGT','min'=>85,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Tarangire / Kuro','tc'=>'','min'=>195,'type'=>'scheduled'],
    ['from'=>'Dar Es Salaam','fc'=>'DAR','to'=>'Zanzibar','tc'=>'ZNZ','min'=>15,'type'=>'scheduled'],
    // ── FROM ENTEBBE ─────────────────────────────────────────────────────────
    ['from'=>'Entebbe','fc'=>'EBB','to'=>'Chem Chem','tc'=>'','min'=>300,'type'=>'scheduled'],
    ['from'=>'Entebbe','fc'=>'EBB','to'=>'Lake Manyara','tc'=>'LKY','min'=>300,'type'=>'scheduled'],
    ['from'=>'Entebbe','fc'=>'EBB','to'=>'Mwanza','tc'=>'MWZ','min'=>60,'type'=>'scheduled'],
    ['from'=>'Entebbe','fc'=>'EBB','to'=>'Serengeti / Fort Ikoma','tc'=>'','min'=>255,'type'=>'scheduled'],
    ['from'=>'Entebbe','fc'=>'EBB','to'=>'Serengeti / Grumeti','tc'=>'GTZ','min'=>210,'type'=>'scheduled'],
    ['from'=>'Entebbe','fc'=>'EBB','to'=>'Serengeti / Kogatende','tc'=>'','min'=>210,'type'=>'scheduled'],
    ['from'=>'Entebbe','fc'=>'EBB','to'=>'Serengeti / Lamai','tc'=>'','min'=>210,'type'=>'scheduled'],
    ['from'=>'Entebbe','fc'=>'EBB','to'=>'Serengeti / Lobo','tc'=>'','min'=>255,'type'=>'scheduled'],
    ['from'=>'Entebbe','fc'=>'EBB','to'=>'Serengeti / Mwiba','tc'=>'','min'=>285,'type'=>'scheduled'],
    ['from'=>'Entebbe','fc'=>'EBB','to'=>'Serengeti / Ndutu','tc'=>'','min'=>285,'type'=>'scheduled'],
    ['from'=>'Entebbe','fc'=>'EBB','to'=>'Serengeti / Sasakwa','tc'=>'','min'=>255,'type'=>'scheduled'],
    ['from'=>'Entebbe','fc'=>'EBB','to'=>'Serengeti / Serengeti South','tc'=>'','min'=>285,'type'=>'scheduled'],
    ['from'=>'Entebbe','fc'=>'EBB','to'=>'Serengeti / Seronera','tc'=>'SEU','min'=>255,'type'=>'scheduled'],
    ['from'=>'Entebbe','fc'=>'EBB','to'=>'Tarangire / Kuro','tc'=>'','min'=>300,'type'=>'scheduled'],
    // ── FROM KIGALI ──────────────────────────────────────────────────────────
    ['from'=>'Kigali','fc'=>'KGL','to'=>'Chem Chem','tc'=>'','min'=>300,'type'=>'scheduled'],
    ['from'=>'Kigali','fc'=>'KGL','to'=>'Lake Manyara','tc'=>'LKY','min'=>300,'type'=>'scheduled'],
    ['from'=>'Kigali','fc'=>'KGL','to'=>'Mwanza','tc'=>'MWZ','min'=>60,'type'=>'scheduled'],
    ['from'=>'Kigali','fc'=>'KGL','to'=>'Serengeti / Fort Ikoma','tc'=>'','min'=>255,'type'=>'scheduled'],
    ['from'=>'Kigali','fc'=>'KGL','to'=>'Serengeti / Grumeti','tc'=>'GTZ','min'=>225,'type'=>'scheduled'],
    ['from'=>'Kigali','fc'=>'KGL','to'=>'Serengeti / Kogatende','tc'=>'','min'=>225,'type'=>'scheduled'],
    ['from'=>'Kigali','fc'=>'KGL','to'=>'Serengeti / Lamai','tc'=>'','min'=>225,'type'=>'scheduled'],
    ['from'=>'Kigali','fc'=>'KGL','to'=>'Serengeti / Lobo','tc'=>'','min'=>255,'type'=>'scheduled'],
    ['from'=>'Kigali','fc'=>'KGL','to'=>'Serengeti / Mwiba','tc'=>'','min'=>285,'type'=>'scheduled'],
    ['from'=>'Kigali','fc'=>'KGL','to'=>'Serengeti / Ndutu','tc'=>'','min'=>285,'type'=>'scheduled'],
    ['from'=>'Kigali','fc'=>'KGL','to'=>'Serengeti / Sasakwa','tc'=>'','min'=>255,'type'=>'scheduled'],
    ['from'=>'Kigali','fc'=>'KGL','to'=>'Serengeti / Serengeti South','tc'=>'','min'=>285,'type'=>'scheduled'],
    ['from'=>'Kigali','fc'=>'KGL','to'=>'Serengeti / Seronera','tc'=>'SEU','min'=>255,'type'=>'scheduled'],
    ['from'=>'Kigali','fc'=>'KGL','to'=>'Tarangire / Kuro','tc'=>'','min'=>300,'type'=>'scheduled'],
    // ── FROM KILIMANJARO ─────────────────────────────────────────────────────
    ['from'=>'Kilimanjaro','fc'=>'JRO','to'=>'Chem Chem','tc'=>'','min'=>25,'type'=>'scheduled'],
    ['from'=>'Kilimanjaro','fc'=>'JRO','to'=>'Lake Manyara','tc'=>'LKY','min'=>35,'type'=>'scheduled'],
    ['from'=>'Kilimanjaro','fc'=>'JRO','to'=>'Serengeti / Fort Ikoma','tc'=>'','min'=>115,'type'=>'scheduled'],
    ['from'=>'Kilimanjaro','fc'=>'JRO','to'=>'Serengeti / Grumeti','tc'=>'GTZ','min'=>125,'type'=>'scheduled'],
    ['from'=>'Kilimanjaro','fc'=>'JRO','to'=>'Serengeti / Kogatende','tc'=>'','min'=>145,'type'=>'scheduled'],
    ['from'=>'Kilimanjaro','fc'=>'JRO','to'=>'Serengeti / Lamai','tc'=>'','min'=>155,'type'=>'scheduled'],
    ['from'=>'Kilimanjaro','fc'=>'JRO','to'=>'Serengeti / Lobo','tc'=>'','min'=>135,'type'=>'scheduled'],
    ['from'=>'Kilimanjaro','fc'=>'JRO','to'=>'Serengeti / Mwiba','tc'=>'','min'=>45,'type'=>'scheduled'],
    ['from'=>'Kilimanjaro','fc'=>'JRO','to'=>'Serengeti / Ndutu','tc'=>'','min'=>65,'type'=>'scheduled'],
    ['from'=>'Kilimanjaro','fc'=>'JRO','to'=>'Serengeti / Seronera','tc'=>'SEU','min'=>95,'type'=>'scheduled'],
    ['from'=>'Kilimanjaro','fc'=>'JRO','to'=>'Serengeti / Sasakwa','tc'=>'','min'=>105,'type'=>'scheduled'],
    ['from'=>'Kilimanjaro','fc'=>'JRO','to'=>'Serengeti / Serengeti South','tc'=>'','min'=>75,'type'=>'scheduled'],
    ['from'=>'Kilimanjaro','fc'=>'JRO','to'=>'Tarangire / Kuro','tc'=>'','min'=>5,'type'=>'scheduled'],
    // ── FROM MAFIA ───────────────────────────────────────────────────────────
    ['from'=>'Mafia Island','fc'=>'MFA','to'=>'Dar Es Salaam','tc'=>'DAR','min'=>30,'type'=>'scheduled'],
    ['from'=>'Mafia Island','fc'=>'MFA','to'=>'Zanzibar','tc'=>'ZNZ','min'=>140,'type'=>'scheduled'],
    // ── FROM LAKE MANYARA ────────────────────────────────────────────────────
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Arusha','tc'=>'ARK','min'=>65,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Chem Chem','tc'=>'','min'=>5,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Dar Es Salaam','tc'=>'DAR','min'=>205,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Entebbe','tc'=>'EBB','min'=>255,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Kigali','tc'=>'KGL','min'=>255,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Kilimanjaro','tc'=>'JRO','min'=>35,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Mafia Island','tc'=>'MFA','min'=>315,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Migori (Kenya)','tc'=>'','min'=>185,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Pangani (Kwajoni)','tc'=>'','min'=>250,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Pemba Island','tc'=>'PMA','min'=>240,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Rubondo','tc'=>'','min'=>135,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Saadani','tc'=>'','min'=>250,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Serengeti / Fort Ikoma','tc'=>'','min'=>55,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Serengeti / Grumeti','tc'=>'GTZ','min'=>65,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Serengeti / Kogatende','tc'=>'','min'=>55,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Serengeti / Lamai','tc'=>'','min'=>95,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Serengeti / Lobo','tc'=>'','min'=>75,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Serengeti / Mwiba','tc'=>'','min'=>5,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Serengeti / Ndutu','tc'=>'','min'=>5,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Serengeti / Seronera','tc'=>'SEU','min'=>35,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Serengeti / Sasakwa','tc'=>'','min'=>45,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Serengeti / Serengeti South','tc'=>'','min'=>5,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Tarangire / Kuro','tc'=>'','min'=>15,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Tanga','tc'=>'TGT','min'=>265,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Tarime','tc'=>'','min'=>105,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Zanzibar','tc'=>'ZNZ','min'=>155,'type'=>'scheduled'],
    // ── FROM MWANZA ──────────────────────────────────────────────────────────
    ['from'=>'Mwanza','fc'=>'MWZ','to'=>'Entebbe','tc'=>'EBB','min'=>60,'type'=>'scheduled'],
    ['from'=>'Mwanza','fc'=>'MWZ','to'=>'Kigali','tc'=>'KGL','min'=>60,'type'=>'scheduled'],
    ['from'=>'Mwanza','fc'=>'MWZ','to'=>'Serengeti / Fort Ikoma','tc'=>'','min'=>75,'type'=>'scheduled'],
    ['from'=>'Mwanza','fc'=>'MWZ','to'=>'Serengeti / Grumeti','tc'=>'GTZ','min'=>45,'type'=>'scheduled'],
    ['from'=>'Mwanza','fc'=>'MWZ','to'=>'Serengeti / Kogatende','tc'=>'','min'=>45,'type'=>'scheduled'],
    ['from'=>'Mwanza','fc'=>'MWZ','to'=>'Serengeti / Lamai','tc'=>'','min'=>45,'type'=>'scheduled'],
    ['from'=>'Mwanza','fc'=>'MWZ','to'=>'Serengeti / Lobo','tc'=>'','min'=>75,'type'=>'scheduled'],
    ['from'=>'Mwanza','fc'=>'MWZ','to'=>'Serengeti / Mwiba','tc'=>'','min'=>105,'type'=>'scheduled'],
    ['from'=>'Mwanza','fc'=>'MWZ','to'=>'Serengeti / Ndutu','tc'=>'','min'=>105,'type'=>'scheduled'],
    ['from'=>'Mwanza','fc'=>'MWZ','to'=>'Serengeti / Sasakwa','tc'=>'','min'=>75,'type'=>'scheduled'],
    ['from'=>'Mwanza','fc'=>'MWZ','to'=>'Serengeti / Serengeti South','tc'=>'','min'=>105,'type'=>'scheduled'],
    ['from'=>'Mwanza','fc'=>'MWZ','to'=>'Serengeti / Seronera','tc'=>'SEU','min'=>75,'type'=>'scheduled'],
    // ── FROM PANGANI ─────────────────────────────────────────────────────────
    ['from'=>'Pangani (Kwajoni)','fc'=>'','to'=>'Arusha','tc'=>'ARK','min'=>270,'type'=>'scheduled'],
    ['from'=>'Pangani (Kwajoni)','fc'=>'','to'=>'Dar Es Salaam','tc'=>'DAR','min'=>60,'type'=>'scheduled'],
    ['from'=>'Pangani (Kwajoni)','fc'=>'','to'=>'Zanzibar','tc'=>'ZNZ','min'=>20,'type'=>'scheduled'],
    // ── FROM PEMBA ───────────────────────────────────────────────────────────
    ['from'=>'Pemba Island','fc'=>'PMA','to'=>'Arusha','tc'=>'ARK','min'=>190,'type'=>'scheduled'],
    ['from'=>'Pemba Island','fc'=>'PMA','to'=>'Dar Es Salaam','tc'=>'DAR','min'=>70,'type'=>'scheduled'],
    ['from'=>'Pemba Island','fc'=>'PMA','to'=>'Mafia Island','tc'=>'MFA','min'=>185,'type'=>'scheduled'],
    ['from'=>'Pemba Island','fc'=>'PMA','to'=>'Pangani (Kwajoni)','tc'=>'','min'=>225,'type'=>'scheduled'],
    ['from'=>'Pemba Island','fc'=>'PMA','to'=>'Saadani','tc'=>'','min'=>225,'type'=>'scheduled'],
    ['from'=>'Pemba Island','fc'=>'PMA','to'=>'Selous (Nyerere NP)','tc'=>'','min'=>240,'type'=>'scheduled'],
    ['from'=>'Pemba Island','fc'=>'PMA','to'=>'Tanga','tc'=>'TGT','min'=>240,'type'=>'scheduled'],
    ['from'=>'Pemba Island','fc'=>'PMA','to'=>'Zanzibar','tc'=>'ZNZ','min'=>30,'type'=>'scheduled'],
    // ── FROM RUAHA ───────────────────────────────────────────────────────────
    ['from'=>'Ruaha','fc'=>'','to'=>'Dar Es Salaam','tc'=>'DAR','min'=>150,'type'=>'scheduled'],
    ['from'=>'Ruaha','fc'=>'','to'=>'Mafia Island','tc'=>'MFA','min'=>290,'type'=>'scheduled'],
    ['from'=>'Ruaha','fc'=>'','to'=>'Pemba Island','tc'=>'PMA','min'=>285,'type'=>'scheduled'],
    ['from'=>'Ruaha','fc'=>'','to'=>'Selous (Nyerere NP)','tc'=>'','min'=>105,'type'=>'scheduled'],
    ['from'=>'Ruaha','fc'=>'','to'=>'Serengeti / Seronera','tc'=>'SEU','min'=>125,'type'=>'scheduled'],
    ['from'=>'Ruaha','fc'=>'','to'=>'Zanzibar','tc'=>'ZNZ','min'=>185,'type'=>'scheduled'],
    // ── FROM RUBONDO ─────────────────────────────────────────────────────────
    ['from'=>'Rubondo','fc'=>'','to'=>'Lake Manyara','tc'=>'LKY','min'=>120,'type'=>'scheduled'],
    ['from'=>'Rubondo','fc'=>'','to'=>'Serengeti / Fort Ikoma','tc'=>'','min'=>75,'type'=>'scheduled'],
    ['from'=>'Rubondo','fc'=>'','to'=>'Serengeti / Grumeti','tc'=>'GTZ','min'=>45,'type'=>'scheduled'],
    ['from'=>'Rubondo','fc'=>'','to'=>'Serengeti / Kogatende','tc'=>'','min'=>45,'type'=>'scheduled'],
    ['from'=>'Rubondo','fc'=>'','to'=>'Serengeti / Lamai','tc'=>'','min'=>45,'type'=>'scheduled'],
    ['from'=>'Rubondo','fc'=>'','to'=>'Serengeti / Lobo','tc'=>'','min'=>75,'type'=>'scheduled'],
    ['from'=>'Rubondo','fc'=>'','to'=>'Serengeti / Mwiba','tc'=>'','min'=>105,'type'=>'scheduled'],
    ['from'=>'Rubondo','fc'=>'','to'=>'Serengeti / Ndutu','tc'=>'','min'=>105,'type'=>'scheduled'],
    ['from'=>'Rubondo','fc'=>'','to'=>'Serengeti / Seronera','tc'=>'SEU','min'=>75,'type'=>'scheduled'],
    ['from'=>'Rubondo','fc'=>'','to'=>'Serengeti / Sasakwa','tc'=>'','min'=>75,'type'=>'scheduled'],
    ['from'=>'Rubondo','fc'=>'','to'=>'Serengeti / Serengeti South','tc'=>'','min'=>105,'type'=>'scheduled'],
    // ── FROM SAADANI ─────────────────────────────────────────────────────────
    ['from'=>'Saadani','fc'=>'','to'=>'Arusha','tc'=>'ARK','min'=>270,'type'=>'scheduled'],
    ['from'=>'Saadani','fc'=>'','to'=>'Dar Es Salaam','tc'=>'DAR','min'=>60,'type'=>'scheduled'],
    ['from'=>'Saadani','fc'=>'','to'=>'Zanzibar','tc'=>'ZNZ','min'=>20,'type'=>'scheduled'],
    // ── FROM SELOUS / NYERERE NP ─────────────────────────────────────────────
    ['from'=>'Selous (Nyerere NP)','fc'=>'','to'=>'Dar Es Salaam','tc'=>'DAR','min'=>35,'type'=>'scheduled'],
    ['from'=>'Selous (Nyerere NP)','fc'=>'','to'=>'Mafia Island','tc'=>'MFA','min'=>175,'type'=>'scheduled'],
    ['from'=>'Selous (Nyerere NP)','fc'=>'','to'=>'Pangani (Kwajoni)','tc'=>'','min'=>215,'type'=>'scheduled'],
    ['from'=>'Selous (Nyerere NP)','fc'=>'','to'=>'Pemba Island','tc'=>'PMA','min'=>170,'type'=>'scheduled'],
    ['from'=>'Selous (Nyerere NP)','fc'=>'','to'=>'Ruaha','tc'=>'','min'=>105,'type'=>'scheduled'],
    ['from'=>'Selous (Nyerere NP)','fc'=>'','to'=>'Saadani','tc'=>'','min'=>215,'type'=>'scheduled'],
    ['from'=>'Selous (Nyerere NP)','fc'=>'','to'=>'Zanzibar','tc'=>'ZNZ','min'=>120,'type'=>'scheduled'],
    // ── SERENGETI INTERNAL ───────────────────────────────────────────────────
    ['from'=>'Serengeti / Fort Ikoma','fc'=>'','to'=>'Serengeti / Grumeti','tc'=>'GTZ','min'=>5,'type'=>'scheduled'],
    ['from'=>'Serengeti / Fort Ikoma','fc'=>'','to'=>'Serengeti / Kogatende','tc'=>'','min'=>25,'type'=>'scheduled'],
    ['from'=>'Serengeti / Fort Ikoma','fc'=>'','to'=>'Serengeti / Lamai','tc'=>'','min'=>35,'type'=>'scheduled'],
    ['from'=>'Serengeti / Fort Ikoma','fc'=>'','to'=>'Serengeti / Lobo','tc'=>'','min'=>15,'type'=>'scheduled'],
    ['from'=>'Serengeti / Fort Ikoma','fc'=>'','to'=>'Serengeti / Mwiba','tc'=>'','min'=>55,'type'=>'scheduled'],
    ['from'=>'Serengeti / Fort Ikoma','fc'=>'','to'=>'Serengeti / Ndutu','tc'=>'','min'=>25,'type'=>'scheduled'],
    ['from'=>'Serengeti / Fort Ikoma','fc'=>'','to'=>'Serengeti / Seronera','tc'=>'SEU','min'=>55,'type'=>'scheduled'],
    ['from'=>'Serengeti / Fort Ikoma','fc'=>'','to'=>'Serengeti / Sasakwa','tc'=>'','min'=>5,'type'=>'scheduled'],
    ['from'=>'Serengeti / Fort Ikoma','fc'=>'','to'=>'Serengeti / Serengeti South','tc'=>'','min'=>15,'type'=>'scheduled'],
    ['from'=>'Serengeti / Seronera','fc'=>'SEU','to'=>'Serengeti / Fort Ikoma','tc'=>'','min'=>15,'type'=>'scheduled'],
    ['from'=>'Serengeti / Seronera','fc'=>'SEU','to'=>'Serengeti / Grumeti','tc'=>'GTZ','min'=>25,'type'=>'scheduled'],
    ['from'=>'Serengeti / Seronera','fc'=>'SEU','to'=>'Serengeti / Kogatende','tc'=>'','min'=>45,'type'=>'scheduled'],
    ['from'=>'Serengeti / Seronera','fc'=>'SEU','to'=>'Serengeti / Lamai','tc'=>'','min'=>55,'type'=>'scheduled'],
    ['from'=>'Serengeti / Seronera','fc'=>'SEU','to'=>'Serengeti / Lobo','tc'=>'','min'=>35,'type'=>'scheduled'],
    ['from'=>'Serengeti / Seronera','fc'=>'SEU','to'=>'Serengeti / Mwiba','tc'=>'','min'=>25,'type'=>'scheduled'],
    ['from'=>'Serengeti / Seronera','fc'=>'SEU','to'=>'Serengeti / Ndutu','tc'=>'','min'=>15,'type'=>'scheduled'],
    ['from'=>'Serengeti / Seronera','fc'=>'SEU','to'=>'Serengeti / Sasakwa','tc'=>'','min'=>5,'type'=>'scheduled'],
    ['from'=>'Serengeti / Seronera','fc'=>'SEU','to'=>'Serengeti / Serengeti South','tc'=>'','min'=>5,'type'=>'scheduled'],
    // ── SERENGETI → DESTINATIONS ─────────────────────────────────────────────
    ['from'=>'Serengeti / Fort Ikoma','fc'=>'','to'=>'Arusha','tc'=>'ARK','min'=>130,'type'=>'scheduled'],
    ['from'=>'Serengeti / Fort Ikoma','fc'=>'','to'=>'Chem Chem','tc'=>'','min'=>95,'type'=>'scheduled'],
    ['from'=>'Serengeti / Fort Ikoma','fc'=>'','to'=>'Dar Es Salaam','tc'=>'DAR','min'=>300,'type'=>'scheduled'],
    ['from'=>'Serengeti / Fort Ikoma','fc'=>'','to'=>'Kilimanjaro','tc'=>'JRO','min'=>115,'type'=>'scheduled'],
    ['from'=>'Serengeti / Fort Ikoma','fc'=>'','to'=>'Lake Manyara','tc'=>'LKY','min'=>85,'type'=>'scheduled'],
    ['from'=>'Serengeti / Fort Ikoma','fc'=>'','to'=>'Ruaha','tc'=>'','min'=>280,'type'=>'scheduled'],
    ['from'=>'Serengeti / Fort Ikoma','fc'=>'','to'=>'Tarangire / Kuro','tc'=>'','min'=>105,'type'=>'scheduled'],
    ['from'=>'Serengeti / Fort Ikoma','fc'=>'','to'=>'Zanzibar','tc'=>'ZNZ','min'=>250,'type'=>'scheduled'],
    ['from'=>'Serengeti / Seronera','fc'=>'SEU','to'=>'Arusha','tc'=>'ARK','min'=>35,'type'=>'scheduled'],
    ['from'=>'Serengeti / Seronera','fc'=>'SEU','to'=>'Chem Chem','tc'=>'','min'=>30,'type'=>'scheduled'],
    ['from'=>'Serengeti / Seronera','fc'=>'SEU','to'=>'Dar Es Salaam','tc'=>'DAR','min'=>255,'type'=>'scheduled'],
    ['from'=>'Serengeti / Seronera','fc'=>'SEU','to'=>'Kilimanjaro','tc'=>'JRO','min'=>95,'type'=>'scheduled'],
    ['from'=>'Serengeti / Seronera','fc'=>'SEU','to'=>'Lake Manyara','tc'=>'LKY','min'=>35,'type'=>'scheduled'],
    ['from'=>'Serengeti / Seronera','fc'=>'SEU','to'=>'Ruaha','tc'=>'','min'=>125,'type'=>'scheduled'],
    ['from'=>'Serengeti / Seronera','fc'=>'SEU','to'=>'Tarangire / Kuro','tc'=>'','min'=>40,'type'=>'scheduled'],
    ['from'=>'Serengeti / Seronera','fc'=>'SEU','to'=>'Zanzibar','tc'=>'ZNZ','min'=>100,'type'=>'scheduled'],
    ['from'=>'Serengeti / Kogatende','fc'=>'','to'=>'Arusha','tc'=>'ARK','min'=>165,'type'=>'scheduled'],
    ['from'=>'Serengeti / Kogatende','fc'=>'','to'=>'Zanzibar','tc'=>'ZNZ','min'=>250,'type'=>'scheduled'],
    ['from'=>'Serengeti / Ndutu','fc'=>'','to'=>'Arusha','tc'=>'ARK','min'=>100,'type'=>'scheduled'],
    ['from'=>'Serengeti / Ndutu','fc'=>'','to'=>'Zanzibar','tc'=>'ZNZ','min'=>220,'type'=>'scheduled'],
    ['from'=>'Serengeti / Mwiba','fc'=>'','to'=>'Arusha','tc'=>'ARK','min'=>65,'type'=>'scheduled'],
    ['from'=>'Serengeti / Mwiba','fc'=>'','to'=>'Dar Es Salaam','tc'=>'DAR','min'=>250,'type'=>'scheduled'],
    ['from'=>'Serengeti / Mwiba','fc'=>'','to'=>'Zanzibar','tc'=>'ZNZ','min'=>200,'type'=>'scheduled'],
    ['from'=>'Serengeti / Sasakwa','fc'=>'','to'=>'Arusha','tc'=>'ARK','min'=>120,'type'=>'scheduled'],
    ['from'=>'Serengeti / Sasakwa','fc'=>'','to'=>'Dar Es Salaam','tc'=>'DAR','min'=>250,'type'=>'scheduled'],
    ['from'=>'Serengeti / Sasakwa','fc'=>'','to'=>'Zanzibar','tc'=>'ZNZ','min'=>240,'type'=>'scheduled'],
    // ── FROM TANGA ───────────────────────────────────────────────────────────
    ['from'=>'Tanga','fc'=>'TGT','to'=>'Dar Es Salaam','tc'=>'DAR','min'=>85,'type'=>'scheduled'],
    ['from'=>'Tanga','fc'=>'TGT','to'=>'Pemba Island','tc'=>'PMA','min'=>285,'type'=>'scheduled'],
    ['from'=>'Tanga','fc'=>'TGT','to'=>'Zanzibar','tc'=>'ZNZ','min'=>45,'type'=>'scheduled'],
    // ── FROM TARANGIRE / KURO ────────────────────────────────────────────────
    ['from'=>'Tarangire / Kuro','fc'=>'','to'=>'Arusha','tc'=>'ARK','min'=>20,'type'=>'scheduled'],
    ['from'=>'Tarangire / Kuro','fc'=>'','to'=>'Chem Chem','tc'=>'','min'=>5,'type'=>'scheduled'],
    ['from'=>'Tarangire / Kuro','fc'=>'','to'=>'Dar Es Salaam','tc'=>'DAR','min'=>190,'type'=>'scheduled'],
    ['from'=>'Tarangire / Kuro','fc'=>'','to'=>'Kilimanjaro','tc'=>'JRO','min'=>5,'type'=>'scheduled'],
    ['from'=>'Tarangire / Kuro','fc'=>'','to'=>'Lake Manyara','tc'=>'LKY','min'=>15,'type'=>'scheduled'],
    ['from'=>'Tarangire / Kuro','fc'=>'','to'=>'Serengeti / Fort Ikoma','tc'=>'','min'=>75,'type'=>'scheduled'],
    ['from'=>'Tarangire / Kuro','fc'=>'','to'=>'Serengeti / Grumeti','tc'=>'GTZ','min'=>85,'type'=>'scheduled'],
    ['from'=>'Tarangire / Kuro','fc'=>'','to'=>'Serengeti / Kogatende','tc'=>'','min'=>105,'type'=>'scheduled'],
    ['from'=>'Tarangire / Kuro','fc'=>'','to'=>'Serengeti / Lamai','tc'=>'','min'=>115,'type'=>'scheduled'],
    ['from'=>'Tarangire / Kuro','fc'=>'','to'=>'Serengeti / Lobo','tc'=>'','min'=>95,'type'=>'scheduled'],
    ['from'=>'Tarangire / Kuro','fc'=>'','to'=>'Serengeti / Mwiba','tc'=>'','min'=>25,'type'=>'scheduled'],
    ['from'=>'Tarangire / Kuro','fc'=>'','to'=>'Serengeti / Ndutu','tc'=>'','min'=>25,'type'=>'scheduled'],
    ['from'=>'Tarangire / Kuro','fc'=>'','to'=>'Serengeti / Seronera','tc'=>'SEU','min'=>45,'type'=>'scheduled'],
    ['from'=>'Tarangire / Kuro','fc'=>'','to'=>'Serengeti / Sasakwa','tc'=>'','min'=>65,'type'=>'scheduled'],
    ['from'=>'Tarangire / Kuro','fc'=>'','to'=>'Serengeti / Serengeti South','tc'=>'','min'=>35,'type'=>'scheduled'],
    ['from'=>'Tarangire / Kuro','fc'=>'','to'=>'Zanzibar','tc'=>'ZNZ','min'=>125,'type'=>'scheduled'],
    // ── FROM TARIME ──────────────────────────────────────────────────────────
    ['from'=>'Tarime','fc'=>'','to'=>'Chem Chem','tc'=>'','min'=>115,'type'=>'scheduled'],
    ['from'=>'Tarime','fc'=>'','to'=>'Lake Manyara','tc'=>'LKY','min'=>105,'type'=>'scheduled'],
    ['from'=>'Tarime','fc'=>'','to'=>'Serengeti / Fort Ikoma','tc'=>'','min'=>45,'type'=>'scheduled'],
    ['from'=>'Tarime','fc'=>'','to'=>'Serengeti / Grumeti','tc'=>'GTZ','min'=>35,'type'=>'scheduled'],
    ['from'=>'Tarime','fc'=>'','to'=>'Serengeti / Kogatende','tc'=>'','min'=>15,'type'=>'scheduled'],
    ['from'=>'Tarime','fc'=>'','to'=>'Serengeti / Lamai','tc'=>'','min'=>5,'type'=>'scheduled'],
    ['from'=>'Tarime','fc'=>'','to'=>'Serengeti / Lobo','tc'=>'','min'=>25,'type'=>'scheduled'],
    ['from'=>'Tarime','fc'=>'','to'=>'Serengeti / Mwiba','tc'=>'','min'=>95,'type'=>'scheduled'],
    ['from'=>'Tarime','fc'=>'','to'=>'Serengeti / Ndutu','tc'=>'','min'=>85,'type'=>'scheduled'],
    ['from'=>'Tarime','fc'=>'','to'=>'Serengeti / Seronera','tc'=>'SEU','min'=>65,'type'=>'scheduled'],
    ['from'=>'Tarime','fc'=>'','to'=>'Serengeti / Sasakwa','tc'=>'','min'=>55,'type'=>'scheduled'],
    ['from'=>'Tarime','fc'=>'','to'=>'Serengeti / Serengeti South','tc'=>'','min'=>75,'type'=>'scheduled'],
    ['from'=>'Tarime','fc'=>'','to'=>'Tarangire / Kuro','tc'=>'','min'=>125,'type'=>'scheduled'],
    // ── MASAI MARA CONNECTIONS ───────────────────────────────────────────────
    ['from'=>'Chem Chem','fc'=>'','to'=>'Masai Mara','tc'=>'MRE','min'=>280,'type'=>'scheduled'],
    ['from'=>'Lake Manyara','fc'=>'LKY','to'=>'Masai Mara','tc'=>'MRE','min'=>270,'type'=>'scheduled'],
    ['from'=>'Serengeti / Fort Ikoma','fc'=>'','to'=>'Masai Mara','tc'=>'MRE','min'=>210,'type'=>'scheduled'],
    ['from'=>'Serengeti / Grumeti','fc'=>'GTZ','to'=>'Masai Mara','tc'=>'MRE','min'=>200,'type'=>'scheduled'],
    ['from'=>'Serengeti / Kogatende','fc'=>'','to'=>'Masai Mara','tc'=>'MRE','min'=>180,'type'=>'scheduled'],
    ['from'=>'Serengeti / Lamai','fc'=>'','to'=>'Masai Mara','tc'=>'MRE','min'=>170,'type'=>'scheduled'],
    ['from'=>'Serengeti / Lobo','fc'=>'','to'=>'Masai Mara','tc'=>'MRE','min'=>190,'type'=>'scheduled'],
    ['from'=>'Serengeti / Mwiba','fc'=>'','to'=>'Masai Mara','tc'=>'MRE','min'=>260,'type'=>'scheduled'],
    ['from'=>'Serengeti / Ndutu','fc'=>'','to'=>'Masai Mara','tc'=>'MRE','min'=>250,'type'=>'scheduled'],
    ['from'=>'Serengeti / Sasakwa','fc'=>'','to'=>'Masai Mara','tc'=>'MRE','min'=>220,'type'=>'scheduled'],
    ['from'=>'Serengeti / Serengeti South','fc'=>'','to'=>'Masai Mara','tc'=>'MRE','min'=>240,'type'=>'scheduled'],
    ['from'=>'Serengeti / Seronera','fc'=>'SEU','to'=>'Masai Mara','tc'=>'MRE','min'=>230,'type'=>'scheduled'],
    ['from'=>'Tarangire / Kuro','fc'=>'','to'=>'Masai Mara','tc'=>'MRE','min'=>295,'type'=>'scheduled'],
    // ── FROM ZANZIBAR ────────────────────────────────────────────────────────
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Arusha','tc'=>'ARK','min'=>65,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Chem Chem','tc'=>'','min'=>170,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Dar Es Salaam','tc'=>'DAR','min'=>20,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Mafia Island','tc'=>'MFA','min'=>135,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Lake Manyara','tc'=>'LKY','min'=>115,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Pangani (Kwajoni)','tc'=>'','min'=>30,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Pemba Island','tc'=>'PMA','min'=>30,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Ruaha','tc'=>'','min'=>185,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Saadani','tc'=>'','min'=>30,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Selous (Nyerere NP)','tc'=>'','min'=>75,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Serengeti / Fort Ikoma','tc'=>'','min'=>260,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Serengeti / Grumeti','tc'=>'GTZ','min'=>270,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Serengeti / Kogatende','tc'=>'','min'=>290,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Serengeti / Lamai','tc'=>'','min'=>300,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Serengeti / Lobo','tc'=>'','min'=>280,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Serengeti / Mwiba','tc'=>'','min'=>190,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Serengeti / Ndutu','tc'=>'','min'=>210,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Serengeti / Seronera','tc'=>'SEU','min'=>240,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Serengeti / Sasakwa','tc'=>'','min'=>250,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Serengeti / Serengeti South','tc'=>'','min'=>220,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Tanga','tc'=>'TGT','min'=>45,'type'=>'scheduled'],
    ['from'=>'Zanzibar','fc'=>'ZNZ','to'=>'Tarangire / Kuro','tc'=>'','min'=>150,'type'=>'scheduled'],
];

// Flag duplicates
foreach ($ROUTES as &$r) {
    $r['is_duplicate'] = in_array(strtolower($r['from'].'|'.$r['to']), $existing);
}
unset($r);

// ── POST: import ──────────────────────────────────────────────────────────────
$import_log  = [];
$import_done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $stmt = $db->prepare(
        'INSERT INTO iti_flight_routes
         (from_airport,from_code,to_airport,to_code,operator,flight_type,duration_min,
          notes_en,notes_it,notes_fr,notes_es,notes_de,is_active)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)'
    );
    $ok = $skip = $err = 0;
    foreach ($ROUTES as $i => $r) {
        if (isset($_POST["skip_{$i}"])) { $skip++; continue; }
        if ($r['is_duplicate']) { $skip++; $import_log[] = "⏭ Exists: {$r['from']} → {$r['to']}"; continue; }
        try {
            $operator = $_POST["op_{$i}"] ?? 'Auric Air';
            $stmt->execute([
                $r['from'], $r['fc'], $r['to'], $r['tc'],
                $operator, $r['type'], $r['min'],
                '','','','','',
            ]);
            $existing[]   = strtolower($r['from'].'|'.$r['to']);
            $import_log[] = "✅ {$r['from']} → {$r['to']}";
            $ok++;
        } catch (Exception $e) {
            $import_log[] = "❌ {$r['from']} → {$r['to']}: " . $e->getMessage();
            $err++;
        }
    }
    $import_log[] = "─── Done: {$ok} imported, {$skip} skipped, {$err} errors ───";
    $import_done  = true;
}

$new_count = count(array_filter($ROUTES, fn($r) => !$r['is_duplicate']));
$dup_count = count(array_filter($ROUTES, fn($r) => $r['is_duplicate']));

$page_title = 'Import Flight Routes — Auric Air';
$extra_css  = iti_extra_css() . '
.import-table{width:100%;border-collapse:collapse;background:#fff;font-size:.78rem;border:1px solid var(--grey-lt);}
.import-table th{background:#f0f0ef;padding:7px 10px;text-align:left;font-size:.71rem;white-space:nowrap;border-bottom:1.5px solid var(--grey-lt);}
.import-table td{padding:7px 10px;border-bottom:1px solid #f0f0ef;vertical-align:middle;}
.import-table tr.dup td{background:#fffbeb;}
.badge-dup{display:inline-block;padding:1px 7px;border-radius:4px;background:#FEF3C7;color:#92400E;font-size:.65rem;font-weight:700;margin-left:4px;}
.log-box{background:#1a1a1a;color:#a8e6a3;font-family:monospace;font-size:.75rem;padding:14px 16px;border-radius:8px;max-height:240px;overflow-y:auto;white-space:pre-wrap;margin:12px 0;}
';
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
<?php iti_nav('Import Flight Routes'); ?>
<?php iti_flash_render(); ?>

<div class="page-header">
  <div>
    <h2>✈️ Import Flight Routes — Auric Air</h2>
    <div class="sub">Master Data › Transfers &amp; Flights › Route Import — <?= count($ROUTES) ?> unique routes</div>
  </div>
  <a href="transfers.php?tab=flight" class="btn btn-outline btn-sm">← Back to Flight Routes</a>
</div>

<?php if ($import_done): ?>
<div class="form-card">
  <div class="form-section-title">Import Result</div>
  <div class="log-box"><?= implode("\n", array_map('htmlspecialchars', $import_log)) ?></div>
  <div style="margin-top:16px;display:flex;gap:10px;">
    <a href="transfers.php?tab=flight" class="btn btn-red">→ View Flight Routes</a>
    <a href="iti_import_flight_routes.php" class="btn btn-outline">↩ Import again</a>
  </div>
</div>

<?php else: ?>
<form method="POST" action="iti_import_flight_routes.php">
<div class="form-card">
  <div class="form-section-title">Review &amp; Import</div>
  <p style="font-size:.8rem;color:var(--grey-mid);margin-bottom:4px;">
    <?= $new_count ?> new routes ready · <?= $dup_count ?> already in DB (pre-checked for skip).
    Operator defaults to <strong>Auric Air</strong> — change where needed.
  </p>
  <p style="font-size:.75rem;color:var(--grey-mid);margin-bottom:16px;">
    Source: Auric Air Rate Sheet G15 Ver 1.4 — valid 15 Jun 2025 to 31 Dec 2026.
    Duration is approximate (taken from first scheduled flight of the day).
  </p>

  <table class="import-table">
    <thead>
      <tr>
        <th style="width:34px;text-align:center;">Skip</th>
        <th>From</th>
        <th>To</th>
        <th style="width:60px;">Min</th>
        <th style="min-width:150px;">Operator</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($ROUTES as $i => $r): ?>
    <tr class="<?= $r['is_duplicate'] ? 'dup' : '' ?>">
      <td style="text-align:center;">
        <input type="checkbox" name="skip_<?= $i ?>" value="1" <?= $r['is_duplicate'] ? 'checked' : '' ?>>
      </td>
      <td><?= h($r['from']) ?><?= $r['fc'] ? ' <span style="font-size:.68rem;color:var(--grey-mid);">('.h($r['fc']).')</span>' : '' ?>
        <?php if ($r['is_duplicate']): ?><span class="badge-dup">EXISTS</span><?php endif; ?>
      </td>
      <td><?= h($r['to']) ?><?= $r['tc'] ? ' <span style="font-size:.68rem;color:var(--grey-mid);">('.h($r['tc']).')</span>' : '' ?></td>
      <td style="color:var(--grey-mid);"><?= $r['min'] ?></td>
      <td><input type="text" name="op_<?= $i ?>" value="Auric Air" style="font-size:.78rem;padding:4px 8px;border:1.5px solid var(--grey-lt);border-radius:5px;width:140px;"></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($can_edit): ?>
  <div style="margin-top:18px;display:flex;gap:10px;align-items:center;">
    <button type="submit" class="btn btn-red">⬆ Import Selected Routes</button>
    <a href="transfers.php?tab=flight" class="btn btn-outline">Cancel</a>
    <span style="margin-left:auto;font-size:.75rem;color:var(--grey-mid);">Unchecked rows will be imported.</span>
  </div>
  <?php endif; ?>
</div>
</form>
<?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
