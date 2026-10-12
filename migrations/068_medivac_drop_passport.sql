-- 068: Medivac no longer stores passport numbers (rule: never store or read passports).
-- The Arusha Medivac tracker keeps an empty "Passport #" column; nothing in the Hub fills it.
-- Apply AFTER deploying the code that stops writing passport_number
-- (medivac_save / medivac_update / medivac_archive), otherwise their INSERT/UPDATE fail.
-- Blank first, then drop (MySQL: no IF EXISTS on DROP COLUMN — run once).
UPDATE medivac_travelers         SET passport_number = NULL WHERE passport_number IS NOT NULL;
UPDATE medivac_travelers_archive SET passport_number = NULL WHERE passport_number IS NOT NULL;

ALTER TABLE medivac_travelers         DROP COLUMN passport_number;
ALTER TABLE medivac_travelers_archive DROP COLUMN passport_number;
