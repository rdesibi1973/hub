-- Migration 054: Voucher lodge directory
-- Reusable per-lodge contact data needed on printed vouchers but NOT present in
-- the WeTu Word/Excel exports: GPS coordinates, provider phone, postal address.
-- Matched to a lodge name (from the Word "Sistemazioni" table) via `name_key`,
-- a normalized, accent-stripped, lower-cased fragment (see voucher_norm() in
-- includes/voucher_lib.php). Rows below are seeded from the vouchers already
-- produced for the Mandanici and Scremin safaris; the list grows over time
-- (missing lodges can be filled straight from the voucher review screen).

CREATE TABLE IF NOT EXISTS iti_voucher_lodges (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name_key      VARCHAR(120) NOT NULL COMMENT 'Normalized match fragment, e.g. "melia serengeti"',
    display_name  VARCHAR(160) NOT NULL COMMENT 'Provider name as shown on the voucher',
    phone         VARCHAR(80)  DEFAULT NULL,
    address       VARCHAR(255) DEFAULT NULL,
    gps           VARCHAR(120) DEFAULT NULL COMMENT 'e.g. S 2° 28'' 19.555", E 34° 32'' 48.395"',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_voucher_lodge_key (name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO iti_voucher_lodges (name_key, display_name, phone, address, gps) VALUES
  ('melia serengeti',
   'Melià Serengeti Lodge',
   '255746810810',
   'Nyamuma Hills, Serengeti National Park, 01184',
   'S 2° 28'' 19.555", E 34° 32'' 48.395"'),
  ('arusha explorers',
   'Arusha Explorers Lodge',
   NULL,
   NULL,
   'S 3° 23'' 34.951", E 36° 40'' 40.577"'),
  ('marera',
   'Ngorongoro Marera Mountain View Lodge',
   '+255 759 969 200',
   'Rhotia Valley Road',
   'S 3° 17'' 21.108", E 35° 43'' 24.045"'),
  ('kifaru',
   'Serengeti Kifaru Tented Lodge',
   '+255 759 969 200',
   'Serengeti Kifaru Tented Lodge, Serengeti National Park',
   'S 2° 21'' 25.200", E 34° 45'' 25.200"')
ON DUPLICATE KEY UPDATE
   display_name = VALUES(display_name),
   phone        = VALUES(phone),
   address      = VALUES(address),
   gps          = VALUES(gps);
