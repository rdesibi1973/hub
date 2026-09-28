-- 063_iti_final_programme.sql — ITI final programme from a confirmed booking (Phase 1: data model).
-- See ITI_FINAL_PROGRAMME_HANDOFF.md §4.
--
-- Everything below is ALSO created lazily by modules/iti/includes/iti_functions.php
-- (iti_ensure_final_schema(), run by the ITI Lodges / Aliases pages), which checks
-- each column first: the live ITI schema differs from the repo SQL, and MySQL on
-- BlueHost has no IF NOT EXISTS on ADD COLUMN. Running this file by hand is
-- optional; if you do, skip any statement whose column already exists.
-- Requires 062 (iti_programs.lead_request_id).

-- Programme stage, source Calc and versions (one active final per request;
-- regenerating supersedes the previous one).
ALTER TABLE iti_programs
  ADD COLUMN stage ENUM('proposal','final') NOT NULL DEFAULT 'proposal',
  ADD COLUMN hub_program_code VARCHAR(80) NULL DEFAULT NULL,      -- samples: 'GranSafariTanzania' (from *_Calc.xlsx)
  ADD COLUMN source_calc_path VARCHAR(512) NULL DEFAULT NULL,     -- Dropbox path of the Calc used
  ADD COLUMN source_calc_rev VARCHAR(64) NULL DEFAULT NULL,       -- Dropbox rev at generation
  ADD COLUMN generated_at DATETIME NULL DEFAULT NULL,
  ADD COLUMN generated_by INT NULL DEFAULT NULL,                  -- users.id
  ADD COLUMN superseded_by INT UNSIGNED NULL DEFAULT NULL,        -- newer final version
  ADD COLUMN superseded_at DATETIME NULL DEFAULT NULL,
  ADD KEY idx_prog_lead_stage (lead_request_id, stage),
  ADD KEY idx_prog_code (hub_program_code);
-- iti_programs.start_date: already in the live table (program_edit.php); add it if missing:
-- ALTER TABLE iti_programs ADD COLUMN start_date DATE NULL DEFAULT NULL;

-- Per-night booking state (Calc INVOICE / CHECKED columns) + review flag.
ALTER TABLE iti_program_days
  ADD COLUMN booking_ref VARCHAR(100) NULL DEFAULT NULL,
  ADD COLUMN booked_status VARCHAR(40) NULL DEFAULT NULL,         -- 'OK' or an amount such as '$430'
  ADD COLUMN booked_by VARCHAR(60) NULL DEFAULT NULL,             -- e.g. 'Glady'
  ADD COLUMN needs_review TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN review_note VARCHAR(255) NULL DEFAULT NULL;

-- Supplier contacts (Supplier list of the final programme).
ALTER TABLE iti_lodges
  ADD COLUMN phone VARCHAR(80) NULL DEFAULT NULL,
  ADD COLUMN email VARCHAR(160) NULL DEFAULT NULL,
  ADD COLUMN address VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN emergency_phone VARCHAR(80) NULL DEFAULT NULL;

-- One-off: phone/address from the voucher lodge directory (054), where its
-- name key matches exactly one lodge.
UPDATE iti_lodges l
  JOIN (SELECT v.id AS vid, MIN(l2.id) AS lid
          FROM iti_voucher_lodges v
          JOIN iti_lodges l2 ON LOWER(l2.name) LIKE CONCAT('%', v.name_key, '%')
         WHERE v.is_active = 1
         GROUP BY v.id HAVING COUNT(*) = 1) m ON m.lid = l.id
  JOIN iti_voucher_lodges v ON v.id = m.vid
   SET l.phone = COALESCE(NULLIF(l.phone, ''), v.phone),
       l.address = COALESCE(NULLIF(l.address, ''), v.address);

-- Booking data of a final programme (1:1).
CREATE TABLE IF NOT EXISTS iti_program_booking (
  program_id        INT UNSIGNED NOT NULL PRIMARY KEY,
  room_config       VARCHAR(100) NULL,                  -- Calc A37, e.g. '1 triple'
  pax_adults        TINYINT NOT NULL DEFAULT 0,
  pax_teen          TINYINT NOT NULL DEFAULT 0,
  pax_child         TINYINT NOT NULL DEFAULT 0,
  arrival_details   TEXT NULL,                          -- Calc A51
  departure_details TEXT NULL,                          -- Calc A56
  extra_details     TEXT NULL,                          -- Calc A39 (dietary / occasions)
  show_prices       TINYINT(1) NOT NULL DEFAULT 1,
  price_text        VARCHAR(255) NULL,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pbk_prog FOREIGN KEY (program_id) REFERENCES iti_programs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Guests (Calc rows 43+; 'TBA' allowed). Passport numbers are deliberately NOT stored.
CREATE TABLE IF NOT EXISTS iti_program_guests (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  program_id  INT UNSIGNED NOT NULL,
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  full_name   VARCHAR(160) NOT NULL,
  title       VARCHAR(10) NULL,                         -- MR / MRS / MS
  dob         DATE NULL,
  country     VARCHAR(80) NULL,                         -- no passport numbers (not stored in the Hub for now)
  KEY idx_pg_prog (program_id, sort_order),
  CONSTRAINT fk_pg_prog FOREIGN KEY (program_id) REFERENCES iti_programs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Calc free text → master data. alias = lower-case, trimmed, spaces collapsed.
-- A NULL target = "known text, nothing to show" (e.g. 'medivac', or a label with no transfer).
CREATE TABLE IF NOT EXISTS iti_lodge_aliases (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  alias       VARCHAR(150) NOT NULL,
  lodge_id    INT UNSIGNED NULL,
  meal_basis  ENUM('BB','HB','FB','AI') NULL,            -- e.g. 'arusha explorers in hb' → HB
  created_by  VARCHAR(80) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lodge_alias (alias),
  KEY idx_la_lodge (lodge_id),
  CONSTRAINT fk_la_lodge FOREIGN KEY (lodge_id) REFERENCES iti_lodges (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS iti_activity_aliases (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  alias       VARCHAR(150) NOT NULL,                     -- one part of 'Maasai+Olduvai' (split on '+')
  activity_id INT UNSIGNED NULL,
  created_by  VARCHAR(80) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_activity_alias (alias),
  KEY idx_aa_act (activity_id),
  CONSTRAINT fk_aa_act FOREIGN KEY (activity_id) REFERENCES iti_activities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS iti_route_aliases (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  alias             VARCHAR(150) NOT NULL,               -- PARK/OVERNIGHT label, e.g. 'karatu-nca-serengeti'
  transfer_route_id INT UNSIGNED NULL,
  flight_route_id   INT UNSIGNED NULL,
  created_by        VARCHAR(80) NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_route_alias (alias),
  KEY idx_ra_tr (transfer_route_id),
  KEY idx_ra_fl (flight_route_id),
  CONSTRAINT fk_ra_tr FOREIGN KEY (transfer_route_id) REFERENCES iti_transfer_routes (id) ON DELETE CASCADE,
  CONSTRAINT fk_ra_fl FOREIGN KEY (flight_route_id) REFERENCES iti_flight_routes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aliases are seeded from ITI → Aliases → "Seed from Calc templates" (iti_seed_aliases()).
