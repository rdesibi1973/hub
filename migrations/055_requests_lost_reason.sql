-- Track WHY a request was marked Lost (structured reason + optional free note).
-- Reason stores a stable slug: insufficient_budget | trip_postponed |
-- no_more_replies | other. Both are cleared when the status leaves 'Lost'.
-- MySQL (BlueHost): no IF NOT EXISTS on ADD COLUMN.
ALTER TABLE requests
  ADD COLUMN lost_reason VARCHAR(32) NULL AFTER status,
  ADD COLUMN lost_note   TEXT        NULL AFTER lost_reason;
