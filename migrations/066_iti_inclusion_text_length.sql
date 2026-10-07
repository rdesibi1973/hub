-- 066_iti_inclusion_text_length.sql — included / not included items longer than 255 characters.
-- The texts were VARCHAR(255): the STO import's Medivac item (264 chars) was cut silently
-- ("… — o SE è nec") in the samples and in every programme copied from them. Widening keeps
-- the data; the cut texts themselves must be rewritten (see docs / Hub editor).

ALTER TABLE iti_program_inclusions
  MODIFY text_en VARCHAR(1000) NULL,
  MODIFY text_it VARCHAR(1000) NULL,
  MODIFY text_fr VARCHAR(1000) NULL,
  MODIFY text_es VARCHAR(1000) NULL,
  MODIFY text_de VARCHAR(1000) NULL;

ALTER TABLE iti_standard_inclusions
  MODIFY text_en VARCHAR(1000) NULL,
  MODIFY text_it VARCHAR(1000) NULL,
  MODIFY text_fr VARCHAR(1000) NULL,
  MODIFY text_es VARCHAR(1000) NULL,
  MODIFY text_de VARCHAR(1000) NULL;
