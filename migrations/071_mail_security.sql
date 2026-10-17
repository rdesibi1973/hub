-- 071_mail_security.sql — Mail security scan (modules/security/mail_scan.php, Agent API mail_security_status):
-- daily cPanel scan of mailboxes, filters, forwarders, auto-replies and catch-all, after the Oct 2026 "GLAD"
-- filter breach on agustin@. Optional: modules/security/includes/mail_security_service.php mss_schema()
-- creates the same tables on first run (CREATE TABLE IF NOT EXISTS).

-- One row per distinct state (a new row only when the hash changes; last_seen_at moves on every scan).
CREATE TABLE IF NOT EXISTS mail_security_snapshot (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    taken_at     DATETIME NOT NULL,                     -- first scan that saw this state
    last_seen_at DATETIME NOT NULL,
    hash         CHAR(40) NOT NULL,                     -- sha1 of the normalised items
    json         MEDIUMTEXT NOT NULL,                   -- normalised snapshot
    KEY idx_mss_taken (taken_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Items Roberto has seen and approved (seeded from the first run). Not alerted again unless they change.
CREATE TABLE IF NOT EXISTS mail_security_baseline (
    item_key    VARCHAR(190) NOT NULL PRIMARY KEY,      -- filter:<mailbox>:<name>, fwd:<from>><to>, autoreply:<email> …
    item_hash   CHAR(40) NOT NULL,                      -- sha1 of the approved value
    label       VARCHAR(500) NULL,
    approved_at DATETIME NOT NULL,
    approved_by VARCHAR(60) NOT NULL                    -- 'first_run' | 'cli' | user name
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every scan (also the failed ones: silence must never mean "ok").
CREATE TABLE IF NOT EXISTS mail_security_run (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ran_at       DATETIME NOT NULL,
    ok           TINYINT(1) NOT NULL,
    error        TEXT NULL,
    snapshot_id  INT NULL,
    source       VARCHAR(10) NULL,                      -- uapi | token
    counts_json  TEXT NULL,                             -- mailboxes, filters, forwarders, autoreplies, alerts, high
    alerts_json  MEDIUMTEXT NULL,                       -- alerts of this run + HIGH items still present
    mailed       VARCHAR(20) NULL,                      -- alert | weekly | first | failed | none | dry
    KEY idx_msr_ran (ran_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
