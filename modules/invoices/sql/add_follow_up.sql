-- Payment follow-up flag for invoices issued for extra services
-- on already-settled / past trips. These must be chased separately
-- from the normal Booked-request payment cycle.
--
-- Run once on the live DB (phpMyAdmin or MySQL CLI).

ALTER TABLE `invoices`
  ADD COLUMN `follow_up` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`,
  ADD COLUMN `follow_up_note` VARCHAR(255) DEFAULT NULL AFTER `follow_up`;

-- Optional: speeds up the "pending follow-up" list
ALTER TABLE `invoices`
  ADD INDEX `idx_follow_up` (`follow_up`, `balance_due`);
