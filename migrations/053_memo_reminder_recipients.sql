-- Migration 053: extra reminder recipients for memos
-- A memo reminder is always sent to its owner. This column stores any ADDITIONAL
-- recipients chosen by the owner, as a comma-separated list of email addresses.
-- Idempotent: guarded so it can be re-run after a BlueHost ERROR 2006 interruption.

SET @col_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'memos'
      AND COLUMN_NAME  = 'reminder_emails'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE memos ADD COLUMN reminder_emails TEXT NULL AFTER recur_rule',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
