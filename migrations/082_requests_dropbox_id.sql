-- 082: stable link request ↔ Dropbox folder (13 Nov 2026).
-- dropbox_id = the folder's Dropbox id ("id:…"), which never changes on move or rename. The Hub still reads the path
-- from dropbox_url; when that path is gone (folder moved / renamed outside the Hub) it finds the folder by this id and
-- rewrites dropbox_url / practice_code itself (folder_heal(), modules/leads/includes/folder_link_service.php).
-- Not unique: the members of an imported group share one folder.
-- Also applied lazily by fl_schema() (folder_link_service.php). MySQL: no ADD COLUMN IF NOT EXISTS — run once.
ALTER TABLE requests
  ADD COLUMN dropbox_id VARCHAR(64) NULL AFTER dropbox_url,
  ADD KEY idx_requests_dropbox_id (dropbox_id);
