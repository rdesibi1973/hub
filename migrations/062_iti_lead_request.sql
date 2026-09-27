-- 062_iti_lead_request.sql — link ITI programmes to Hub leads requests.
-- A personal programme belongs to one leads request (requests.id); the request page
-- lists its itineraries and can create one from a sample.
-- Also created lazily by modules/iti/includes/iti_functions.php (iti_ensure_lead_link).

ALTER TABLE iti_programs
  ADD COLUMN lead_request_id INT NULL DEFAULT NULL,
  ADD KEY idx_prog_lead_request (lead_request_id),
  ADD CONSTRAINT fk_prog_lead_request FOREIGN KEY (lead_request_id) REFERENCES requests (id) ON DELETE SET NULL;
