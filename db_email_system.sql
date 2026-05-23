-- ============================================================
-- Email System — DB tables
-- Run on: savannp5_savannah_leads
-- ============================================================

CREATE TABLE `email_templates` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `name`       varchar(200) NOT NULL,
  `category`   varchar(100) DEFAULT NULL,
  `subject`    varchar(500) NOT NULL,
  `body_html`  mediumtext NOT NULL,
  `active`     tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` smallint(6) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `request_notes` (
  `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`  int(11) NOT NULL,
  `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`  int(11) DEFAULT NULL,
  `note_type`   enum('manual','email_sent') NOT NULL DEFAULT 'manual',
  `subject`     varchar(500) DEFAULT NULL,
  `body`        mediumtext,
  PRIMARY KEY (`id`),
  KEY `idx_request_id` (`request_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
