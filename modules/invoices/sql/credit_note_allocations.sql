-- ============================================================
-- Credit Note Allocations — schema
-- Tracks how much of a credit note's credit has been used to pay
-- other invoices. Supports cross-currency (CN currency -> invoice
-- currency) via a manually entered fx_rate.
-- Run once on savannp5_savannah_leads.
-- ============================================================

CREATE TABLE IF NOT EXISTS `credit_note_allocations` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `credit_note_id` int(10) UNSIGNED NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `payment_id` int(10) UNSIGNED DEFAULT NULL,        -- linked positive invoice_payments row on the target invoice
  `amount_cn` decimal(12,2) NOT NULL,                -- amount deducted from the CN, in CN currency
  `amount_invoice` decimal(12,2) NOT NULL,           -- amount applied to invoice, in invoice currency (floored to unit)
  `fx_rate` decimal(12,6) NOT NULL DEFAULT '1.000000', -- CN currency -> invoice currency
  `alloc_date` date NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `credit_note_id` (`credit_note_id`),
  KEY `invoice_id` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
