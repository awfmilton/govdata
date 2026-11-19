-- =============================================================================
-- Recorded fix (2026-11-18) for matrix findings SRC-04 and EXT-14, run AFTER fix-whitespace-official.sql.
--   SRC-04: the official file also carries NO-BREAK SPACE (U+00A0) and TAB inside/around postal codes,
--           riding numbers and NAICS codes ("R3H 0S5 ", "59015\t", "\t914110"). Those values failed
--           validation (stored NULL) or kept the character. 7 current postal codes ($720,983) were missing
--           from riding totals.
--   EXT-14: NAICS codes that arrive with a label ("541712 - Research and development…") or padding were
--           stored NULL; the leading 2-6 digit code is recoverable.
-- Every change is logged in corrections_history as data_cleaning. Requires tmp_master_src
-- (from fix-whitespace-official.sql). Re-run normalize.py and the riding warm afterwards.
-- =============================================================================
SET @ed = (SELECT edition_id FROM grants_source_editions WHERE edition_code = '2026-11-17_open-canada-grants-csv');
SET @ws = '[[:space:]\\x{00A0}]';

DROP TABLE IF EXISTS tmp_src04;
CREATE TABLE tmp_src04 ENGINE=InnoDB AS
SELECT m.primary_key, m.id, m.ref_number, m.amendment_number,
       m.recipient_postal_code AS old_pc, REGEXP_REPLACE(UPPER(s.recipient_postal_code), @ws, '') AS new_pc,
       m.federal_riding_number AS old_rn, REGEXP_REPLACE(s.federal_riding_number, @ws, '') AS new_rn,
       m.naics_identifier AS old_naics, REGEXP_SUBSTR(REGEXP_REPLACE(s.naics_identifier, @ws, ' '), '^ *[0-9]{2,6}') AS naics_lead
FROM master_table m JOIN tmp_master_src x ON x.primary_key = m.primary_key JOIN src_grants_20261117 s ON s.src_row = x.src_row
WHERE s.recipient_postal_code REGEXP @ws OR s.federal_riding_number REGEXP @ws
   OR (m.naics_identifier IS NULL AND s.naics_identifier REGEXP '^[^0-9]*[0-9]{2,6}');

-- Postal codes
INSERT INTO corrections_history (record_id, ref_number, amendment_number, field_name, old_value, new_value, change_source, edition_id)
SELECT id, ref_number, amendment_number, 'recipient_postal_code', old_pc, new_pc, 'data_cleaning', @ed FROM tmp_src04
WHERE CHAR_LENGTH(new_pc) BETWEEN 1 AND 7 AND NOT (old_pc <=> new_pc);
UPDATE master_table m JOIN tmp_src04 t ON t.primary_key = m.primary_key
SET m.recipient_postal_code = t.new_pc WHERE CHAR_LENGTH(t.new_pc) BETWEEN 1 AND 7 AND NOT (t.old_pc <=> t.new_pc);
SELECT 'postal codes fixed', ROW_COUNT();

-- Riding numbers
INSERT INTO corrections_history (record_id, ref_number, amendment_number, field_name, old_value, new_value, change_source, edition_id)
SELECT id, ref_number, amendment_number, 'federal_riding_number', old_rn, new_rn, 'data_cleaning', @ed FROM tmp_src04
WHERE old_rn IS NULL AND new_rn REGEXP '^[0-9]{1,9}$';
UPDATE master_table m JOIN tmp_src04 t ON t.primary_key = m.primary_key
SET m.federal_riding_number = CAST(t.new_rn AS UNSIGNED) WHERE t.old_rn IS NULL AND t.new_rn REGEXP '^[0-9]{1,9}$';
SELECT 'riding numbers fixed', ROW_COUNT();

-- NAICS: leading 2-6 digit code (never an all-zero "no code")
INSERT INTO corrections_history (record_id, ref_number, amendment_number, field_name, old_value, new_value, change_source, edition_id)
SELECT id, ref_number, amendment_number, 'naics_identifier', NULL, TRIM(naics_lead), 'data_cleaning', @ed FROM tmp_src04
WHERE old_naics IS NULL AND naics_lead IS NOT NULL AND TRIM(naics_lead) NOT REGEXP '^0+$';
UPDATE master_table m JOIN tmp_src04 t ON t.primary_key = m.primary_key
SET m.naics_identifier = CAST(TRIM(t.naics_lead) AS UNSIGNED)
WHERE t.old_naics IS NULL AND t.naics_lead IS NOT NULL AND TRIM(t.naics_lead) NOT REGEXP '^0+$';
SELECT 'naics codes recovered', ROW_COUNT();

-- SRC-23: recipient type 'A' label
UPDATE recipient_types SET recipient_type_description = 'Indigenous recipients' WHERE recipient_type_code = 'A';
