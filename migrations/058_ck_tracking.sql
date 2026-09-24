-- CK tracker: follow every confirmed booking folder in /001_Safari from _PROGRESS
-- to _DEPOSIT/_BALANCE/… and record when it got its _CK (checked) marker.
-- One row per top-level folder, keyed by the Dropbox folder ID, which survives
-- renames (status tag / _CK changes). ck_events is the history of every change.
-- Filled by modules/leads/includes/ck_lib.php (ck_scan) — the folder name stays
-- the visible marker; these tables add the when / who / how long.
-- MySQL (BlueHost): plain CREATE TABLE IF NOT EXISTS is fine (no ADD COLUMN here).
CREATE TABLE IF NOT EXISTS ck_folders (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  dropbox_id      VARCHAR(64)  NOT NULL,
  folder_name     VARCHAR(255) NOT NULL,
  stage           VARCHAR(20)  NULL,       -- Progress|Provisional|Deposit|Balance|Balance-Cash|Paid|Cancelled|NULL
  has_ck          TINYINT(1)   NOT NULL DEFAULT 0,
  start_date      DATE         NULL,
  end_date        DATE         NULL,
  stage_since     DATETIME     NULL,       -- NULL = already in this stage when tracking started
  booking_done_at DATETIME     NULL,       -- first time seen in Deposit/Balance/Balance-Cash/Paid
  ck_at           DATETIME     NULL,
  ck_by           INT          NULL,       -- users.id; NULL when _CK was added outside the Hub
  first_seen_at   DATETIME     NOT NULL,
  last_seen_at    DATETIME     NOT NULL,
  gone            TINYINT(1)   NOT NULL DEFAULT 0,  -- no longer at the top of /001_Safari (archived/moved)
  UNIQUE KEY uq_ck_folders_dbx (dropbox_id),
  KEY idx_ck_folders_name (folder_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ck_events (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  ck_folder_id  INT          NOT NULL,
  event         VARCHAR(20)  NOT NULL,     -- first_seen|stage|ck_set|ck_removed|renamed|gone|back
  from_value    VARCHAR(255) NULL,
  to_value      VARCHAR(255) NULL,
  user_id       INT          NULL,
  source        VARCHAR(10)  NOT NULL,     -- scan (seen in Dropbox) | hub (done from the Hub)
  created_at    DATETIME     NOT NULL,
  KEY idx_ck_events_folder (ck_folder_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
