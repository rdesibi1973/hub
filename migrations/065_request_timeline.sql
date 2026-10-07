-- 065_request_timeline.sql — running history + summary per request (Request Timeline).
-- Also created on first use by tl_schema() in modules/leads/includes/timeline_service.php,
-- so running this by hand is optional. Additive only.

CREATE TABLE IF NOT EXISTS request_timeline (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_id    INT NOT NULL,
  event_at      DATETIME NOT NULL,                 -- when it happened (can be in the past), EAT
  event_type    VARCHAR(30) NOT NULL,              -- TL_TYPES in timeline_service.php
  title         VARCHAR(200) NOT NULL,
  body          TEXT NULL,
  next_step     VARCHAR(500) NULL,
  author_type   ENUM('user','claude','system') NOT NULL DEFAULT 'user',
  user_id       INT NULL,                          -- users.id (agent user for Claude; who triggered a system event)
  source        VARCHAR(20) NOT NULL DEFAULT 'hub',-- hub | api | cowork | auto
  session_url   VARCHAR(500) NULL,                 -- https://claude.ai/… chat
  refs          JSON NULL,                         -- program_id, invoice_number, calc_file, mail_message_id, …
  pinned        TINYINT(1) NOT NULL DEFAULT 0,
  hidden        TINYINT(1) NOT NULL DEFAULT 0,     -- soft delete
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_req_time (request_id, event_at),
  KEY idx_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS request_summary (
  request_id      INT NOT NULL PRIMARY KEY,
  summary         TEXT NOT NULL,                   -- max 1500 chars (PHP)
  next_step       VARCHAR(500) NULL,
  waiting_on      VARCHAR(30) NULL,                -- client | agency | supplier | us | NULL
  updated_by_type ENUM('user','claude') NOT NULL,
  updated_by      INT NULL,
  session_url     VARCHAR(500) NULL,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
