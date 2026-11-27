-- 089: the booking gate no longer writes memos (Roberto, 27 Nov 2026).
-- One-off: soft-delete any open gate memo left on any board ("Booking check passed / blocked — …",
-- "Folder moved out of PROGRESS without a passed booking check"). Safe to run more than once.
UPDATE memos
   SET deleted_at = NOW()
 WHERE deleted_at IS NULL
   AND status NOT IN ('done', 'archived')
   AND (ext_key LIKE 'gate:%' OR ext_key LIKE 'gate-external:%');
