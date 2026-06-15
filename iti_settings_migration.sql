-- ============================================================
-- Savannah Explorers Hub — ITI Settings migration
-- Run once on phpMyAdmin (DB: savannp5_savannah_leads)
-- Safe to re-run: uses IF NOT EXISTS guards where supported.
-- ============================================================

-- ── 1. iti_settings (key/value store) ──────────────────────────────
CREATE TABLE IF NOT EXISTS `iti_settings` (
  `skey`          varchar(64)  NOT NULL,
  `svalue`        text         DEFAULT NULL,
  `is_admin_only` tinyint(1)   NOT NULL DEFAULT 0,
  `updated_at`    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed company defaults (INSERT IGNORE keeps existing values on re-run)
INSERT IGNORE INTO `iti_settings` (`skey`,`svalue`,`is_admin_only`) VALUES
  ('company_name',     'Savannah Explorers',          1),
  ('company_tagline',  '',                            1),
  ('logo_url',         '',                            1),
  ('office_email',     'info@savannahexplorers.com',  1),
  ('office_phone',     '+255 768 900 199',            1),
  ('office_address',   'Arusha, Tanzania',            1),
  ('website',          'savannahexplorers.net',       1),
  ('emergency_name',   '',                            1),
  ('emergency_phone1', '+255 768 900 199',            1),
  ('emergency_phone2', '+255 747 777 315',            1);

-- ── 2. users — personal profile fields ─────────────────────────────
-- MySQL 5.7/8 on BlueHost does not support IF NOT EXISTS on ADD COLUMN
-- reliably; run each line, ignore "Duplicate column" if re-running.
ALTER TABLE `users` ADD COLUMN `phone`     varchar(40)  DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `photo_url` varchar(255) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `bio`       text         DEFAULT NULL;

-- ── 3. iti_terms_conditions — per-program override support ─────────
-- Ensure table exists (no-op if it already does)
CREATE TABLE IF NOT EXISTS `iti_terms_conditions` (
  `id`             int(11)      NOT NULL AUTO_INCREMENT,
  `version`        varchar(80)  NOT NULL DEFAULT '',
  `effective_date` date         DEFAULT NULL,
  `text_en`        text         DEFAULT NULL,
  `text_it`        text         DEFAULT NULL,
  `text_fr`        text         DEFAULT NULL,
  `text_es`        text         DEFAULT NULL,
  `text_de`        text         DEFAULT NULL,
  `is_active`      tinyint(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- program_id: NULL = standard library version; set = override for one program
ALTER TABLE `iti_terms_conditions` ADD COLUMN `program_id` int(11) DEFAULT NULL;
ALTER TABLE `iti_terms_conditions` ADD INDEX `idx_program_id` (`program_id`);
