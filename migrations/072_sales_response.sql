-- 072_sales_response.sql — Sales responsiveness report (modules/leads/sales_response_scan.php, page
-- modules/leads/sales_response.php, Agent API sales_response_status): how fast the sellers answer clients by email.
-- HEADERS ONLY — never bodies or attachments. Optional: modules/leads/includes/sales_response_service.php
-- sr_schema() creates the same tables on first run (CREATE TABLE IF NOT EXISTS). Rows older than 12 months are deleted.

-- One row per mail file (the same message in several mailboxes = several rows with the same msg_key).
CREATE TABLE IF NOT EXISTS mail_headers_index (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    mailbox      VARCHAR(120) NOT NULL,                 -- nuru@savannahexplorers.com
    folder       VARCHAR(190) NOT NULL,                 -- INBOX | Sent | Archive …
    file_key     VARCHAR(190) NOT NULL,                 -- Maildir base name (without :2,flags)
    msg_key      CHAR(40) NOT NULL,                     -- sha1(Message-ID), else sha1(mailbox|folder|file_key)
    message_id   VARCHAR(255) NULL,
    in_reply_to  VARCHAR(255) NULL,
    refs         TEXT NULL,                             -- References: last 20 message ids, space-separated
    date_utc     DATETIME NOT NULL,                     -- Date header in UTC (file mtime if missing / absurd)
    date_src     VARCHAR(6) NOT NULL DEFAULT 'header',  -- header | mtime
    from_addr    VARCHAR(190) NOT NULL DEFAULT '',
    to_addrs     TEXT NULL,                             -- To + Cc addresses, comma-separated
    subject      VARCHAR(500) NULL,
    direction    VARCHAR(10) NOT NULL,                  -- client | savannah | internal | auto
    request_id   INT NULL,
    match_rule   VARCHAR(10) NULL,                      -- thread | address | subject
    ambiguous    TINYINT(1) NOT NULL DEFAULT 0,         -- several open requests matched: the most recent was taken
    indexed_at   DATETIME NOT NULL,
    UNIQUE KEY uq_mhi_file (mailbox, folder, file_key),
    KEY idx_mhi_msg (msg_key),
    KEY idx_mhi_mid (message_id(100)),
    KEY idx_mhi_date (date_utc),
    KEY idx_mhi_req (request_id, date_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Incremental scan: last file mtime read per mailbox folder.
CREATE TABLE IF NOT EXISTS sales_response_scan_state (
    mailbox     VARCHAR(120) NOT NULL,
    folder      VARCHAR(190) NOT NULL,
    last_mtime  INT NOT NULL,
    files       INT NOT NULL DEFAULT 0,                 -- files read by the last scan
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (mailbox, folder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every run (also the failed ones: silence must never mean "ok").
CREATE TABLE IF NOT EXISTS sales_response_run (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ran_at       DATETIME NOT NULL,
    ok           TINYINT(1) NOT NULL,
    error        TEXT NULL,
    counts_json  TEXT NULL,                             -- files read / new, links, ambiguous
    mailed       VARCHAR(20) NULL,                      -- none | daily | weekly | daily+weekly | first | failed | mail_failed
    KEY idx_srr_ran (ran_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
