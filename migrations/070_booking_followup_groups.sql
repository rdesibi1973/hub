-- 070_booking_followup_groups.sql — Booking follow-up: group check 60 days before arrival
-- (GRP, Il Diamante SIC, private groups tagged _PROVISIONAL): type 'group', issues group_to_finalize /
-- group_znz_flight, and `details` (what the check collected: Excel sheets, client folders, blocks, flights).
-- Run after 069. Optional: followup_schema() does the same on first use (followup_schema_upgrade()).

ALTER TABLE booking_followups MODIFY type ENUM('supplier','ticket','group') NOT NULL;
ALTER TABLE booking_followups MODIFY issue ENUM('provisional','provisional_expired','on_request','orangi_rooms_provisional',
    'ticket_missing','unreadable','group_to_finalize','group_znz_flight') NOT NULL;
ALTER TABLE booking_followups ADD COLUMN details TEXT NULL;
