-- 079_iti_std_nic_zic.sql — standard exclusion #1 (Tanzania): only ONE of NIC / ZIC is needed, depending on the
-- point of entry (mainland Tanzania → NIC, Zanzibar → ZIC), USD 44 per person. The old text said "NIC and ZIC".
-- Idempotent. Programme rows that keep their own copy of the old text do not follow this row:
-- the text of iti_program_inclusions wins over the standard one.

SET NAMES utf8mb4;

UPDATE iti_standard_inclusions
   SET text_it = 'NIC o ZIC (assicurazione obbligatoria, a seconda del punto d''ingresso), USD 44 per persona',
       text_en = 'NIC or ZIC (mandatory insurance, depending on the point of entry), USD 44 per person',
       text_fr = 'NIC ou ZIC (assurance obligatoire, selon le point d''entrée), 44 USD par personne',
       text_es = 'NIC o ZIC (seguro obligatorio, según el punto de entrada), 44 USD por persona',
       text_de = 'NIC oder ZIC (Pflichtversicherung, je nach Einreiseort), 44 USD pro Person'
 WHERE id = 1 AND item_type = 'exclusion';
