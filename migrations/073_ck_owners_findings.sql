-- 073_ck_owners_findings.sql — CK owners on the CK tracker and CK findings in Booking follow-up.
-- Run after 058 / 069 / 070. Additive: existing supplier / ticket / group lines are unchanged (audience NULL = booking).
-- Optional: ck_ensure_schema() (modules/leads/includes/ck_lib.php) and followup_schema()
-- (modules/operations/includes/followup_service.php) do the same on first use.

-- Who answers for the CK of a booking (sales side) and who fixes its bookings. NULL = the default rule
-- (CK owner: CK_OWNER_DEFAULT; booking owner: the name most often in the Calc CHECKED column).
ALTER TABLE ck_folders
    ADD COLUMN ck_owner_user_id      INT NULL,
    ADD COLUMN booking_owner_user_id INT NULL,
    ADD COLUMN owners_updated_at     DATETIME NULL,
    ADD COLUMN owners_updated_by     INT NULL,
    ADD COLUMN booking_owner_auto_id   INT NULL,            -- default booking owner read from the Calc (cache)
    ADD COLUMN booking_owner_auto_text VARCHAR(120) NULL,   -- the CHECKED names it was read from
    ADD COLUMN booking_owner_auto_at   DATETIME NULL,
    ADD COLUMN findings_checked_at   DATETIME NULL,         -- last booking check sent with ck_findings_upsert
    ADD COLUMN findings_report_path  VARCHAR(500) NULL;     -- its booking-check.html

-- CK findings: type 'ck', routed by audience; issue becomes a free short code (VARCHAR, was ENUM).
ALTER TABLE booking_followups MODIFY type ENUM('supplier','ticket','group','ck') NOT NULL;
ALTER TABLE booking_followups MODIFY issue VARCHAR(40) NOT NULL;
ALTER TABLE booking_followups MODIFY doc_status_text VARCHAR(500) NULL;
ALTER TABLE booking_followups MODIFY close_reason ENUM('auto_confirmed','auto_ticket_saved','auto_trip_ended','auto_not_found',
    'auto_resolved','manual') NULL;
ALTER TABLE booking_followups ADD COLUMN audience ENUM('booking','sales','accounts') NULL AFTER type;
