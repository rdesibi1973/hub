-- 081: Booking follow-up — manual expiry when the supplier extends a provisional booking by mail (12 Nov 2026).
-- The supplier often confirms a longer hold by mail without re-issuing the pro-forma: staff set the new expiry by hand,
-- with the mail saved as .eml in the booking's mails/ folder as proof.
--   expiry_override        the expiry confirmed by mail; effective expiry = COALESCE(expiry_override, expiry_date)
--   expiry_override_by     users.id of who set it (the Agent API user claude_agent included)
--   expiry_override_at     when it was set
--   expiry_override_proof  the proof file, relative to the line's booking folder ("mails/2026-10-22_Uzuri_P247 extension to 30-11.eml")
-- The supplier scan keeps writing expiry_date from the document and never these columns, except: a newer document with
-- expiry >= the override clears them (event "override superseded by <file>").
-- Also applied lazily by followup_schema_override() (modules/operations/includes/followup_service.php, meta expiry_override).
-- MySQL: no ADD COLUMN IF NOT EXISTS — run once.
ALTER TABLE booking_followups
  ADD COLUMN expiry_override       DATE NULL AFTER expiry_date,
  ADD COLUMN expiry_override_by    INT NULL AFTER expiry_override,
  ADD COLUMN expiry_override_at    DATETIME NULL AFTER expiry_override_by,
  ADD COLUMN expiry_override_proof VARCHAR(500) NULL AFTER expiry_override_at;
