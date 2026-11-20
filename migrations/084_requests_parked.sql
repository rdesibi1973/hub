-- Status "Parked" (waiting for the agency), 20 Nov 2026 — modules/leads/includes/parked_service.php.
-- requests.status is VARCHAR(20): the value 'Parked' needs no schema change, but STATUSES in the server-only
-- modules/leads/config.php must list it: 'Parked'=>'status-parked'.
-- pk_schema() adds these columns lazily too; run this once to have them before the first use.
ALTER TABLE requests ADD COLUMN parked_at   DATETIME    NULL;
ALTER TABLE requests ADD COLUMN parked_by   INT         NULL;
ALTER TABLE requests ADD COLUMN parked_from VARCHAR(20) NULL;
ALTER TABLE requests ADD INDEX idx_req_parked (status, parked_at);
