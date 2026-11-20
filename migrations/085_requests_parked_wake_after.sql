-- Parked: wake-up window, 20 Nov 2026 — modules/leads/includes/parked_service.php (pk_wake_from_mail).
-- Only inbound mails dated after parked_wake_after can wake a Parked request up. Set to "now" whenever a request is
-- parked; the Lost → Parked migration keeps parked_at = the Lost date (reporting / auto-close) but this = the migration
-- time. pk_schema() adds the column and runs the same backfill lazily; run this once to have it before the first use.
ALTER TABLE requests ADD COLUMN parked_wake_after DATETIME NULL;

-- Backfill: the requests Parked now get the time they were parked (last status_change to Parked), never earlier.
UPDATE requests r
SET r.parked_wake_after = COALESCE(
    (SELECT MAX(t.event_at) FROM request_timeline t
     WHERE t.request_id = r.id AND t.event_type = 'status_change'
       AND (t.title LIKE '% → Parked' OR t.title LIKE 'Migrated from Lost%')),
    NOW())
WHERE r.status = 'Parked' AND r.parked_wake_after IS NULL;
