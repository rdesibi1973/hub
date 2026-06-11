-- ============================================================
-- Migration: add rich-text message body to request_todos
-- Adds a formatted HTML message field. The existing `title`
-- column keeps acting as the plain-text subject (used as the
-- reminder email subject and the short list label).
-- ============================================================

ALTER TABLE `request_todos`
  ADD COLUMN `body_html` MEDIUMTEXT NULL AFTER `title`;
