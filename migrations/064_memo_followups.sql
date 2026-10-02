-- 064_memo_followups.sql — Memo Board follow-ups: waiting for someone, link to a
-- booking / invoice, auto-close when a payment is recorded, next steps.
-- Optional: modules/memo/memo_lib.php memo_schema() applies the same changes on
-- first use (each ALTER fails harmlessly if already done — BlueHost MySQL has no
-- ADD COLUMN IF NOT EXISTS).

ALTER TABLE memos MODIFY status ENUM('open','doing','waiting','pending','done','archived') NOT NULL DEFAULT 'open';
ALTER TABLE memos ADD COLUMN waiting_on VARCHAR(120) NULL AFTER status;
ALTER TABLE memos ADD COLUMN request_id INT NULL AFTER waiting_on;
ALTER TABLE memos ADD COLUMN invoice_id INT NULL AFTER request_id;
ALTER TABLE memos ADD COLUMN auto_close VARCHAR(20) NULL AFTER invoice_id;       -- 'payment'
ALTER TABLE memos ADD COLUMN parent_id INT NULL AFTER auto_close;               -- next step of memo parent_id
ALTER TABLE memos ADD COLUMN next_offset_days SMALLINT NULL AFTER parent_id;    -- due = opened + N days
ALTER TABLE memos ADD COLUMN source VARCHAR(20) NULL AFTER next_offset_days;    -- 'claude'
ALTER TABLE memos ADD COLUMN ext_key VARCHAR(120) NULL AFTER source;            -- dedupe key (Agent API)
ALTER TABLE memos ADD INDEX idx_memo_invoice (invoice_id);
ALTER TABLE memos ADD INDEX idx_memo_request (request_id);
ALTER TABLE memos ADD INDEX idx_memo_parent (parent_id);
ALTER TABLE memos ADD INDEX idx_memo_ext (user_id, ext_key);
