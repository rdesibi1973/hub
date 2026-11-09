-- Migration 077: free wording of the participants line on personal programs
-- pax_label_<lang>: when filled it replaces the computed "4 adulti" on the cover, the recap, the
-- Magazine and every iti_document format ("da 4 a 6 partecipanti + 1 accompagnatore").
-- pax_open already exists (added by iti_ensure_doc_columns()).
-- Idempotent: iti_ensure_doc_columns() also adds these columns on first use, in either order.

SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'iti_programs' AND COLUMN_NAME = 'pax_label_en') = 0,
    'ALTER TABLE iti_programs ADD COLUMN pax_label_en VARCHAR(200) NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'iti_programs' AND COLUMN_NAME = 'pax_label_it') = 0,
    'ALTER TABLE iti_programs ADD COLUMN pax_label_it VARCHAR(200) NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'iti_programs' AND COLUMN_NAME = 'pax_label_fr') = 0,
    'ALTER TABLE iti_programs ADD COLUMN pax_label_fr VARCHAR(200) NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'iti_programs' AND COLUMN_NAME = 'pax_label_es') = 0,
    'ALTER TABLE iti_programs ADD COLUMN pax_label_es VARCHAR(200) NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'iti_programs' AND COLUMN_NAME = 'pax_label_de') = 0,
    'ALTER TABLE iti_programs ADD COLUMN pax_label_de VARCHAR(200) NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
