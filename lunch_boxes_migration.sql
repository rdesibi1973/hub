-- ============================================================
-- Lunch Boxes — Migration
-- Run once on savannp5_savannah_leads
-- ============================================================

CREATE TABLE IF NOT EXISTS `lunch_boxes` (
  `id`            int(11)       NOT NULL AUTO_INCREMENT,
  `group_ref`     varchar(40)   NOT NULL COMMENT 'Unique key per import batch',
  `client_name`   varchar(200)  NOT NULL,
  `safari_date`   date          DEFAULT NULL COMMENT 'First date with park fees populated',
  `travelers`     tinyint(4)    DEFAULT NULL COMMENT 'Number of pax',
  `jeeps`         tinyint(4)    DEFAULT NULL COMMENT 'Number of jeeps',
  `extra_details` text          DEFAULT NULL COMMENT 'Content below EXTRA section in Excel',
  `source_file`   varchar(300)  DEFAULT NULL COMMENT 'Original uploaded filename',
  `folder_name`   varchar(300)  DEFAULT NULL COMMENT 'Dropbox folder name (source of client name)',
  `notes`         text          DEFAULT NULL,
  `created_by`    int(11)       DEFAULT NULL,
  `created_at`    datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lb_safari_date` (`safari_date`),
  KEY `idx_lb_group_ref`   (`group_ref`),
  KEY `idx_lb_client`      (`client_name`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Lunch box entries extracted from Safari Calc files';

CREATE TABLE IF NOT EXISTS `lunch_boxes_history` (
  `id`            int(11)       NOT NULL,
  `group_ref`     varchar(40)   NOT NULL,
  `client_name`   varchar(200)  NOT NULL,
  `safari_date`   date          DEFAULT NULL,
  `travelers`     tinyint(4)    DEFAULT NULL,
  `jeeps`         tinyint(4)    DEFAULT NULL,
  `extra_details` text          DEFAULT NULL,
  `source_file`   varchar(300)  DEFAULT NULL,
  `folder_name`   varchar(300)  DEFAULT NULL,
  `notes`         text          DEFAULT NULL,
  `created_by`    int(11)       DEFAULT NULL,
  `created_at`    datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `archived_at`   datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When staged to history',
  PRIMARY KEY (`id`),
  KEY `idx_lbh_safari_date` (`safari_date`),
  KEY `idx_lbh_group_ref`   (`group_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Archived Lunch Box entries';
