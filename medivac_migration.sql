-- ============================================================
-- Medivac Travelers — Migration
-- Run once on savannp5_savannah_leads
-- ============================================================

CREATE TABLE IF NOT EXISTS `medivac_travelers` (
  `id`             int(11)       NOT NULL AUTO_INCREMENT,
  `group_ref`      varchar(40)   NOT NULL COMMENT 'Unique key per import batch (uniqid)',
  `group_name`     varchar(200)  NOT NULL,
  `tour_agent`     varchar(100)  DEFAULT NULL,
  `coverage_start` date          DEFAULT NULL,
  `coverage_end`   date          DEFAULT NULL,
  `full_name`      varchar(200)  NOT NULL,
  `title`          enum('MR','MRS','MS','') NOT NULL DEFAULT '',
  `dob`            date          DEFAULT NULL,
  `passport_number` varchar(60)  DEFAULT NULL,
  `country`        varchar(100)  DEFAULT NULL,
  `insurance_name` varchar(200)  DEFAULT NULL,
  `policy_number`  varchar(100)  DEFAULT NULL,
  `source_file`    varchar(300)  DEFAULT NULL COMMENT 'Original uploaded filename',
  `notes`          text          DEFAULT NULL,
  `created_by`     int(11)       DEFAULT NULL,
  `created_at`     datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_group_ref`      (`group_ref`),
  KEY `idx_coverage_start` (`coverage_start`),
  KEY `idx_coverage_end`   (`coverage_end`),
  KEY `idx_name_dob`       (`full_name`(100), `dob`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Medivac traveler registry';
