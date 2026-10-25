-- 074_bookings_pipeline.sql — One pipeline: every check writes into booking_followups (one list of open problems per
-- booking), lines found by several checks are merged, each line has a deadline (due_date) that sets its priority.
-- Run after 073. Optional: followup_schema() (modules/operations/includes/followup_service.php) does the same on first
-- use; the first run also gives the open lines their default owner and service_key (followup_backfill(), once).

-- New line types: SafariCheck booking points and lines added by hand from the page.
ALTER TABLE booking_followups MODIFY type ENUM('supplier','ticket','group','ck','safaricheck','manual') NOT NULL;

-- sources:     JSON {source: {key, issue, text, since}} — the checks still reporting the line
--              (supplier_scan, ticket_scan, group_scan, safaricheck, claude_check, manual). NULL = the line's own
--              check only (lines written before 074). The line closes when no source reports it anymore.
-- service_key: request | service type | service date | normalised supplier: lines of different checks about the
--              same service are merged into one (followup_service_match()).
-- due_date:    nearest deadline (provisional expiry, supplier payment, group release, ticketing, service − 14 days);
--              priority comes from it (followup_due()).
ALTER TABLE booking_followups
    ADD COLUMN sources     TEXT NULL,
    ADD COLUMN service_key VARCHAR(190) NULL,
    ADD COLUMN due_date    DATE NULL,
    ADD COLUMN due_reason  VARCHAR(80) NULL,
    ADD KEY idx_followup_service (service_key),
    ADD KEY idx_followup_due (due_date);

-- Once-only jobs of the follow-up (owners / service_key backfill, SafariCheck points of the last checks).
CREATE TABLE IF NOT EXISTS booking_followup_meta (
    k VARCHAR(40) NOT NULL PRIMARY KEY,
    v VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
