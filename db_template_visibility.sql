-- Add visibility and agent_id to email_templates
-- Run on: savannp5_savannah_leads

ALTER TABLE `email_templates`
  ADD COLUMN `visibility` ENUM('public','private') NOT NULL DEFAULT 'public' AFTER `active`,
  ADD COLUMN `agent_id`   INT DEFAULT NULL AFTER `visibility`,
  ADD KEY `idx_visibility` (`visibility`),
  ADD KEY `idx_agent_id`   (`agent_id`);
