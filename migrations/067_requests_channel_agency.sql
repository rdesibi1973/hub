-- 067: store the request channel and agency (until now they only lived in the folder name).
-- Written by bs_create_request (New Request, API create_request), api_create_request.php
-- and staging_action.php; old rows backfilled by tools/backfill_request_channel.php.
-- Apply BEFORE deploying the code that writes these columns.
ALTER TABLE requests
  ADD COLUMN channel   VARCHAR(10) NULL AFTER source,
  ADD COLUMN agency_id INT         NULL AFTER channel,
  ADD INDEX idx_requests_channel (channel),
  ADD INDEX idx_requests_agency  (agency_id);
