-- ============================================================
-- Credit Notes — schema
-- Run once on savannp5_savannah_leads (phpMyAdmin or CLI).
-- A credit note ALWAYS references an existing invoice.
-- ============================================================

CREATE TABLE IF NOT EXISTS `credit_notes` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `cn_number` varchar(20) NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `request_id` int(10) UNSIGNED DEFAULT NULL,
  `customer_id` int(10) UNSIGNED DEFAULT NULL,
  `bill_to_name` varchar(200) NOT NULL,
  `bill_to_address` text,
  `issuer` enum('Savannah Explorers Ltd','Savannah Holidays Ltd') NOT NULL DEFAULT 'Savannah Explorers Ltd',
  `currency` enum('USD','EUR') NOT NULL DEFAULT 'USD',
  `issue_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` varchar(20) NOT NULL DEFAULT 'Issued',   -- Issued | Cancelled
  `notes` text,
  `payment_id` int(10) UNSIGNED DEFAULT NULL,         -- linked negative invoice_payments row
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cn_number` (`cn_number`),
  KEY `invoice_id` (`invoice_id`),
  KEY `request_id` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `credit_note_items` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `credit_note_id` int(10) UNSIGNED NOT NULL,
  `sort_order` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `description` text NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT '1.00',
  `unit_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `line_total` decimal(12,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `credit_note_id` (`credit_note_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
