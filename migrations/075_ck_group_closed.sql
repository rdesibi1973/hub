-- 075_ck_group_closed.sql — "Group closed" on the CK tracker: a group (GRP, Il Diamante / Panorama, GRUPPO, _PROVISIONAL
-- blocks) gets _CK only once a person marked it closed (final pax and room list, unsold rooms / seats released).
-- Changes are logged in ck_events (group_closed / group_reopened). Run after 073.
-- Optional: ck_ensure_schema() (modules/leads/includes/ck_lib.php) adds the same columns on first use.

ALTER TABLE ck_folders
    ADD COLUMN group_closed_at DATETIME NULL,
    ADD COLUMN group_closed_by INT NULL;
