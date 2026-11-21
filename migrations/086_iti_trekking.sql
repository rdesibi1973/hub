-- ITI trekking programmes (Kilimanjaro / Meru), 21 Nov 2026 — modules/iti/includes/iti_functions.php
-- (iti_ensure_trek_schema() does the same lazily on first use; run this once to have it before, or skip it).
-- MySQL: no ADD COLUMN IF NOT EXISTS — a statement whose column already exists fails: skip it.

-- Standard included / not included rows per kind of programme: ALL | safari | trekking.
ALTER TABLE iti_standard_inclusions ADD COLUMN program_kind VARCHAR(10) NOT NULL DEFAULT 'ALL';
UPDATE iti_standard_inclusions SET program_kind = 'safari' WHERE item_type = 'exclusion' AND text_en LIKE 'Tips (%';
INSERT INTO iti_standard_inclusions (item_type, country, program_kind, sort_order, text_en, text_it, text_fr, text_es, text_de, is_active)
SELECT 'exclusion', 'ALL', 'trekking', 801,
       'tips for the trekking crew (around USD 150-200 per participant)',
       'mance allo staff del trekking (indicativamente 150-200 USD per partecipante)',
       'pourboires pour l''équipe de trekking (environ 150-200 USD par participant)',
       'propinas para el equipo del trekking (unos 150-200 USD por participante)',
       'Trinkgelder für das Trekking-Team (ca. 150-200 USD pro Teilnehmer)', 1
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM iti_standard_inclusions WHERE program_kind = 'trekking');

-- Cover photo chosen for a programme (URL of a destination / lodge photo); NULL = automatic.
ALTER TABLE iti_programs ADD COLUMN cover_photo VARCHAR(255) NULL DEFAULT NULL;

-- Mountain huts and high-altitude campsites; no category for them.
ALTER TABLE iti_lodges MODIFY lodge_type ENUM('lodge','tented_camp','hotel','mobile_camp','house','hut','campsite') NOT NULL DEFAULT 'lodge';
ALTER TABLE iti_lodges MODIFY category ENUM('budget','mid','luxury','ultra_luxury') NULL DEFAULT 'mid';
-- The Marangu huts (lodges 154, 155, 156) move to lodge_type hut through iti_update_lodge, so the change is logged (ITI → History).
