-- ============================================================
-- Lunch Boxes — Add box_type column
-- Run once on savannp5_savannah_leads
-- ============================================================

ALTER TABLE `lunch_boxes`
  ADD COLUMN `box_type` enum('HUMPERS','LUNCH BOXES') NOT NULL DEFAULT 'HUMPERS' AFTER `guide`;

ALTER TABLE `lunch_boxes_history`
  ADD COLUMN `box_type` enum('HUMPERS','LUNCH BOXES') NOT NULL DEFAULT 'HUMPERS' AFTER `guide`;
