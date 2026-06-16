-- ============================================================
-- Soft-delete archive for requests
-- Run once on savannp5_savannah_leads
-- ============================================================
CREATE TABLE IF NOT EXISTS `deleted_requests` (
  `del_id`            int(11)      NOT NULL AUTO_INCREMENT,
  `orig_id`           int(11)      NOT NULL,
  `practice_code`     varchar(200) DEFAULT NULL,
  `customer_name`     varchar(200) DEFAULT NULL,
  `deleted_by`        int(11)      DEFAULT NULL,
  `deleted_by_name`   varchar(150) DEFAULT NULL,
  `deleted_at`        datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dropbox_from_path` varchar(500) DEFAULT NULL,   -- path before cancel (for restore)
  `dropbox_to_path`   varchar(500) DEFAULT NULL,   -- path after move to 00_CANCELED
  `row_data`          json         NOT NULL,        -- full original requests row
  PRIMARY KEY (`del_id`),
  KEY `orig_id` (`orig_id`),
  KEY `practice_code` (`practice_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
