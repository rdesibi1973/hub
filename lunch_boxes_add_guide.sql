-- ============================================================
-- Lunch Boxes — Add guide column
-- Run once on savannp5_savannah_leads
-- ============================================================

ALTER TABLE `lunch_boxes`         ADD COLUMN `guide` varchar(100) DEFAULT NULL AFTER `jeeps`;
ALTER TABLE `lunch_boxes_history` ADD COLUMN `guide` varchar(100) DEFAULT NULL AFTER `jeeps`;
