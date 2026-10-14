-- 069_booking_followup.sql — Booking follow-up (Operations → Booking follow-up, Agent API followup_*):
-- supplier bookings not confirmed (provisional / expired / on request / Orangi rooms still provisional)
-- and domestic flight tickets not saved, chased by the booking team. Lines are never deleted, only closed.
-- Optional: modules/operations/includes/followup_service.php followup_schema() creates the same
-- tables on first use (CREATE TABLE IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS booking_followups (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    type            ENUM('supplier','ticket') NOT NULL,
    dedupe_key      VARCHAR(190) NOT NULL,              -- S|request|ref, S|request|supplier|date, T|request|date|route
    request_id      INT NULL,
    folder          VARCHAR(255) NULL,                  -- path under /001_Safari (GRP member: group/member)
    start_date      DATE NULL,
    end_date        DATE NULL,
    service_date    DATE NULL,                          -- arrival at the supplier / flight date
    supplier        VARCHAR(150) NULL,
    supplier_ref    VARCHAR(60) NULL,                   -- WB41803, booking ID …
    service         VARCHAR(255) NULL,                  -- tickets: "Auric Air Seronera-Zanzibar"
    source_file     VARCHAR(500) NULL,                  -- relative to the booking folder: invoices/Lion's paw-INV.pdf
    issue           ENUM('provisional','provisional_expired','on_request','orangi_rooms_provisional','ticket_missing','unreadable') NOT NULL,
    doc_status_text VARCHAR(255) NULL,                  -- status as read in the document
    expiry_date     DATE NULL,
    booked_by       VARCHAR(80) NULL,                   -- contact printed on the supplier document (info only)
    owner_user_id   INT NULL,                           -- staff: NULL until taken in charge
    contacted_at    DATE NULL,                          -- staff
    staff_status    ENUM('open','in_progress','problem','resolved','not_relevant') NOT NULL DEFAULT 'open',
    notes           TEXT NULL,                          -- staff
    first_seen_at   DATETIME NOT NULL,
    last_seen_at    DATETIME NOT NULL,
    closed_at       DATETIME NULL,
    closed_by       VARCHAR(20) NULL,                   -- user id or 'system'
    close_reason    ENUM('auto_confirmed','auto_ticket_saved','auto_trip_ended','auto_not_found','manual') NULL,
    scan_source     VARCHAR(10) NULL,                   -- server | local | agent
    UNIQUE KEY uq_followup_key (dedupe_key),
    KEY idx_followup_request (request_id),
    KEY idx_followup_closed (closed_at),
    KEY idx_followup_owner (owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every change by staff or by the check (user_id NULL = the check).
CREATE TABLE IF NOT EXISTS booking_followup_events (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    followup_id INT NOT NULL,
    at          DATETIME NOT NULL,
    user_id     INT NULL,
    field       VARCHAR(40) NOT NULL,
    old_value   TEXT NULL,
    new_value   TEXT NULL,
    KEY idx_followup_event (followup_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Supplier PDFs already read (classified once per Dropbox revision).
CREATE TABLE IF NOT EXISTS booking_followup_docs (
    path_hash   CHAR(40) NOT NULL PRIMARY KEY,          -- sha1(lower(path))
    path        VARCHAR(1000) NOT NULL,
    rev         VARCHAR(64) NULL,
    request_id  INT NULL,
    result_json TEXT NULL,
    read_at     DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Check runs (page header "last check", digest).
CREATE TABLE IF NOT EXISTS booking_followup_runs (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    at           DATETIME NOT NULL,
    type         VARCHAR(10) NOT NULL,                  -- supplier | ticket
    source       VARCHAR(10) NOT NULL,                  -- page | api | cron | local
    user_id      INT NULL,
    partial      TINYINT(1) NOT NULL DEFAULT 0,
    summary_json MEDIUMTEXT NULL,
    KEY idx_followup_run_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Edit permission (take in charge, contacted, status, notes, Run check now). Admin gets it here;
-- Roberto assigns it to the booking-team role in Admin → Roles ("Booking follow-up (edit)").
INSERT IGNORE INTO role_permissions (role_id, module)
SELECT id, 'booking_followup_edit' FROM roles WHERE name = 'admin';
