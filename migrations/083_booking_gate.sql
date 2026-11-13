-- 083_booking_gate.sql — Booking gate: AI booking check before a folder leaves _PROGRESS (13 Nov 2026).
-- Same DDL as bg_schema() in modules/leads/includes/booking_gate_service.php, which creates the tables on first use;
-- this file is for the record / a manual run. MySQL (no IF NOT EXISTS on columns).

CREATE TABLE IF NOT EXISTS booking_gate_runs (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    request_id           INT NOT NULL,
    trigger_src          VARCHAR(20) NOT NULL DEFAULT 'manual',   -- manual | status_change | api | external
    started_by           INT NULL,
    owner_user_id        INT NULL,          -- gets the booking lines: the user who tried the status change / ran the check
    started_at           DATETIME NOT NULL,
    finished_at          DATETIME NULL,
    state                ENUM('queued','running','passed','blocked','error','overridden') NOT NULL DEFAULT 'queued',
    folder               VARCHAR(500) NULL,
    calc_file            VARCHAR(255) NULL,
    calc_sheet           VARCHAR(100) NULL,
    calc_hash            VARCHAR(64) NULL,  -- Dropbox content_hash of the Calc when the run started
    evidence_fingerprint CHAR(40) NULL,     -- sha1 of the invoices/ + bookings/ files and their content_hash
    docs_total           INT NOT NULL DEFAULT 0,
    docs_done            INT NOT NULL DEFAULT 0,
    blocking_count       INT NOT NULL DEFAULT 0,
    warning_count        INT NOT NULL DEFAULT 0,
    snapshot_json        MEDIUMTEXT NULL,   -- Calc services + evidence at collect time (no guests, no passports)
    result_json          MEDIUMTEXT NULL,   -- evaluation: services, blocking[], warnings[], notes[]
    report_path          VARCHAR(500) NULL,
    tokens_in            INT NOT NULL DEFAULT 0,
    tokens_out           INT NOT NULL DEFAULT 0,
    cost_usd             DECIMAL(10,5) NOT NULL DEFAULT 0,
    model                VARCHAR(60) NULL,
    error                VARCHAR(1000) NULL,
    override_by          INT NULL,
    override_reason      VARCHAR(500) NULL,
    override_at          DATETIME NULL,
    locked_until         DATETIME NULL,
    KEY idx_gate_request (request_id, id),
    KEY idx_gate_state (state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS booking_gate_docs (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    run_id         INT NOT NULL,
    dropbox_path   VARCHAR(1000) NOT NULL,
    rel_path       VARCHAR(500) NOT NULL,
    content_hash   VARCHAR(64) NULL,
    kind           VARCHAR(20) NULL,
    extracted_json MEDIUMTEXT NULL,
    status         ENUM('pending','done','error') NOT NULL DEFAULT 'pending',
    cached         TINYINT(1) NOT NULL DEFAULT 0,
    error          VARCHAR(500) NULL,
    tokens_in      INT NOT NULL DEFAULT 0,
    tokens_out     INT NOT NULL DEFAULT 0,
    KEY idx_gate_doc_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extraction cache: a document already read (same Dropbox content_hash, same extraction version) is not sent again.
-- Also keeps the lodge-alias answers (key "A:<sha1>").
CREATE TABLE IF NOT EXISTS booking_gate_cache (
    cache_key      VARCHAR(80) NOT NULL PRIMARY KEY,
    version        SMALLINT NOT NULL,
    extracted_json MEDIUMTEXT NOT NULL,
    model          VARCHAR(60) NULL,
    created_at     DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Booking follow-up: new line type 'gate' (followup_schema_gate() does the same on first use).
ALTER TABLE booking_followups MODIFY type ENUM('supplier','ticket','group','ck','safaricheck','manual','gate') NOT NULL;
