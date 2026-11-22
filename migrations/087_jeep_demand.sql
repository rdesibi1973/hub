-- 087: Operations → Jeep demand (phase 1 — 22 Nov 2026).
-- Jeeps each confirmed safari needs per day, read from its Calc (JEEP column / Cost per Jeep).
-- Logic: modules/operations/includes/jeep_service.php. Also created lazily by jeep_schema() (CREATE TABLE IF NOT EXISTS).
--
-- ops_jeep_scan: one row per booking / group folder of /001_Safari (+ archives 00_YYYY), keyed by the folder's Dropbox id
--   so a status rename (_DEPOSIT → _PAID → _CK) or the move to 00_YYYY never makes a second row.
-- ops_jeep_days: the folder's jeeps per day, replaced as a whole on each rescan (scan_id → ops_jeep_scan.id).

CREATE TABLE IF NOT EXISTS ops_jeep_scan (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  dropbox_id    VARCHAR(64)  NULL,
  folder_path   VARCHAR(500) NOT NULL,
  folder_name   VARCHAR(300) NOT NULL,
  request_id    INT NULL,                 -- Hub request when linked (GRP: only a request whose own folder is the group)
  stage         VARCHAR(20)  NULL,        -- PROGRESS|PROVISIONAL|DEPOSIT|BALANCE|BALANCE-CASH|PAID from the folder name
  provisional   TINYINT(1)   NOT NULL DEFAULT 0,   -- _PROVISIONAL / _PROGRESS
  calc_file     VARCHAR(300) NULL,
  calc_rev      VARCHAR(64)  NULL,
  calc_mtime    DATETIME     NULL,
  files_sig     CHAR(40)     NULL,        -- sha1 of the folder's Excel names + revs: unchanged → not read again
  sheet         VARCHAR(255) NULL,
  kind          ENUM('PVT','GRP') NULL,
  jeeps_header  DECIMAL(4,1) NULL,        -- "Number of Jeeps" (B5)
  pax           INT NULL,                 -- "TOT PAX"
  cost_per_jeep DECIMAL(8,2) NULL,        -- "Cost per Jeep" (D5)
  start_day     DATE NULL,
  end_day       DATE NULL,
  jeep_days     DECIMAL(7,2) NULL,
  guide         VARCHAR(100) NULL,        -- as written in the Calc
  status        ENUM('ok','no_calc','old_xls','no_values','ambiguous_sheet','multi_calc','error') NOT NULL DEFAULT 'ok',
  message       VARCHAR(500) NULL,
  need_scan     TINYINT(1)   NOT NULL DEFAULT 1,
  reader_v      SMALLINT     NOT NULL DEFAULT 0,   -- JEEP_READER_VERSION when read
  checked_at    DATETIME     NULL,        -- last time the folder's files were listed
  scanned_at    DATETIME     NULL,        -- last time the Calc was read
  UNIQUE KEY uq_jeep_dropbox_id (dropbox_id),
  KEY idx_jeep_path (folder_path(191)),
  KEY idx_jeep_status (status)
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ops_jeep_days (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  scan_id    INT NOT NULL,
  request_id INT NULL,
  day        DATE NOT NULL,
  jeeps      DECIMAL(5,2) NOT NULL,
  guide      VARCHAR(100) NULL,
  KEY idx_jeep_day (day),
  KEY idx_jeep_scan (scan_id)
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ops_jeep_meta (
  k VARCHAR(40) PRIMARY KEY,
  v TEXT NULL
) DEFAULT CHARSET=utf8mb4;
