-- 088 — Commercial referrers and commission split per agency / agency contact / booking (27 Nov 2026).
-- Logic: modules/leads/includes/commission_service.php. comm_schema() creates the same on first use,
-- so running this file is optional; the data backfill is the Agent API action commission_backfill
-- (dry run first) or Commissions → Backfill, not this file.

CREATE TABLE IF NOT EXISTS commercial_referrers (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    kind          ENUM('rep','internal') NOT NULL DEFAULT 'rep',
    default_rate  DECIMAL(5,2) NOT NULL DEFAULT 0,
    on_penalties  TINYINT(1)   NOT NULL DEFAULT 1,      -- 0 = no commission on cancellation penalties (Promoservice)
    period        ENUM('month','semester') NOT NULL DEFAULT 'month',   -- Lamprati: semester
    agency_type   VARCHAR(20)  NULL,                    -- agencies.type it stands for (promoservice / lamprati)
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NULL,
    UNIQUE KEY uq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO commercial_referrers (id, name, kind, default_rate, on_penalties, period, agency_type, active, created_at) VALUES
    (1, 'Promoservice',   'rep',      5.00, 0, 'month',    'promoservice', 1, NOW()),
    (2, 'Lamprati',       'rep',      3.00, 1, 'semester', 'lamprati',     1, NOW()),
    (3, 'Elena Perfetti', 'internal', 5.00, 1, 'month',    NULL,           1, NOW());

CREATE TABLE IF NOT EXISTS agency_contact_referrer_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    agency_id   INT NOT NULL,
    contact_id  INT NULL,                -- NULL = the agency default
    changed_at  DATETIME NOT NULL,
    actor       VARCHAR(100) NULL,
    from_json   TEXT NULL,
    to_json     TEXT NULL,
    from_date   DATE NULL,
    to_date     DATE NULL,
    note        VARCHAR(500) NULL,
    KEY idx_contact (contact_id),
    KEY idx_agency (agency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Split = JSON list [{"referrer_id":1,"rate":5.0}]; NULL on an agency = from agencies.type, on a contact = agency default.
ALTER TABLE agencies        ADD COLUMN referrer_split_json TEXT NULL;
ALTER TABLE agency_contacts ADD COLUMN referrer_split_json TEXT NULL,
                            ADD COLUMN referrer_from DATE NULL,
                            ADD COLUMN referrer_note VARCHAR(500) NULL;
ALTER TABLE requests        ADD COLUMN agency_contact_id INT NULL,
                            ADD COLUMN commission_split_json TEXT NULL,      -- snapshot taken at confirmation
                            ADD COLUMN commission_split_at DATETIME NULL,
                            ADD COLUMN commission_split_by VARCHAR(100) NULL,
                            ADD COLUMN commission_split_src VARCHAR(20) NULL; -- confirm | group_import | backfill | manual
