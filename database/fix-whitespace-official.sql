-- =============================================================================
-- Recorded fix (2026-11-18): whitespace other than spaces in the official grants.csv.
-- MySQL TRIM() removes spaces only, so values with leading/trailing newlines, carriage returns or tabs
-- were kept with them (text fields) or failed validation and were stored as NULL (postal codes:
-- e.g. "\nJ0G 1H0" — 2,570 rows, which also made riding totals undercount because the riding warmer
-- joins on postal code). Every change is logged in corrections_history as data_cleaning.
-- rebuild-master-from-official.sql now strips all whitespace the same way.
-- =============================================================================
SET @ed = (SELECT edition_id FROM grants_source_editions WHERE edition_code = '2026-11-17_open-canada-grants-csv');

-- Pair each master row with its source row (same pairing as the build: org, trimmed ref, amendment).
DROP TABLE IF EXISTS tmp_master_src;
CREATE TABLE tmp_master_src ENGINE=InnoDB AS
SELECT m.primary_key, s.src_row
FROM master_table m
JOIN tmp_src_latest l ON l.owner_org = SUBSTRING_INDEX(m.id, ',', 1) AND l.ref_number = m.ref_number
JOIN tmp_version_pos v ON v.owner_org = l.owner_org AND v.ref_number = m.ref_number AND v.amend = IFNULL(m.amendment_number, l.max_amend)
JOIN src_grants_20261117 s ON s.owner_org = v.owner_org AND TRIM(s.ref_number) = v.ref_number AND CAST(s.amendment_number AS UNSIGNED) = v.amend;
ALTER TABLE tmp_master_src ADD PRIMARY KEY (primary_key), ADD INDEX (src_row);

-- 1. Postal codes rejected only because of embedded whitespace
DROP TABLE IF EXISTS tmp_postal_fix;
CREATE TABLE tmp_postal_fix ENGINE=InnoDB AS
SELECT m.primary_key, m.id, m.ref_number, m.amendment_number,
       REGEXP_REPLACE(UPPER(s.recipient_postal_code), '[[:space:]]', '') AS pc
FROM master_table m JOIN tmp_master_src x ON x.primary_key = m.primary_key JOIN src_grants_20261117 s ON s.src_row = x.src_row
WHERE m.recipient_postal_code IS NULL AND s.recipient_postal_code IS NOT NULL
  AND CHAR_LENGTH(REGEXP_REPLACE(UPPER(s.recipient_postal_code), '[[:space:]]', '')) BETWEEN 1 AND 7;
INSERT INTO corrections_history (record_id, ref_number, amendment_number, field_name, old_value, new_value, change_source, edition_id)
SELECT id, ref_number, amendment_number, 'recipient_postal_code', NULL, pc, 'data_cleaning', @ed FROM tmp_postal_fix;
UPDATE master_table m JOIN tmp_postal_fix f ON f.primary_key = m.primary_key SET m.recipient_postal_code = f.pc;
SELECT 'postal codes recovered', COUNT(*) FROM tmp_postal_fix;

-- 2. Leading/trailing CR/LF/TAB (and stray spaces) on text fields
DROP PROCEDURE IF EXISTS pt_strip_ws;
DELIMITER $$
CREATE PROCEDURE pt_strip_ws(IN col VARCHAR(64))
BEGIN
  SET @sql = CONCAT(
    'INSERT INTO corrections_history (record_id, ref_number, amendment_number, field_name, old_value, new_value, change_source, edition_id) ',
    'SELECT id, ref_number, amendment_number, ''', col, ''', `', col, '`, ',
    'NULLIF(REGEXP_REPLACE(`', col, '`, ''^[[:space:]]+|[[:space:]]+$'', ''''), ''''), ''data_cleaning'', ', @ed, ' ',
    'FROM master_table WHERE `', col, '` REGEXP ''^[[:space:]]|[[:space:]]$''');
  PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
  SET @sql = CONCAT('UPDATE master_table SET `', col, '` = NULLIF(REGEXP_REPLACE(`', col, '`, ''^[[:space:]]+|[[:space:]]+$'', ''''), '''') ',
                    'WHERE `', col, '` REGEXP ''^[[:space:]]|[[:space:]]$''');
  PREPARE st FROM @sql; EXECUTE st; SELECT col, ROW_COUNT() AS rows_fixed; DEALLOCATE PREPARE st;
END$$
DELIMITER ;
CALL pt_strip_ws('recipient_business_number'); CALL pt_strip_ws('recipient_legal_name'); CALL pt_strip_ws('recipient_operating_name');
CALL pt_strip_ws('research_organization_name'); CALL pt_strip_ws('recipient_city'); CALL pt_strip_ws('federal_riding_name_en');
CALL pt_strip_ws('federal_riding_name_fr'); CALL pt_strip_ws('prog_name_en'); CALL pt_strip_ws('prog_name_fr');
CALL pt_strip_ws('prog_purpose_en'); CALL pt_strip_ws('prog_purpose_fr'); CALL pt_strip_ws('agreement_title_en');
CALL pt_strip_ws('agreement_title_fr'); CALL pt_strip_ws('agreement_number'); CALL pt_strip_ws('coverage');
CALL pt_strip_ws('description_eng'); CALL pt_strip_ws('description_fr'); CALL pt_strip_ws('expected_results_en');
CALL pt_strip_ws('expected_results_fr'); CALL pt_strip_ws('additional_information_en'); CALL pt_strip_ws('additional_information_fr');
CALL pt_strip_ws('owner_org_en'); CALL pt_strip_ws('owner_org_title'); CALL pt_strip_ws('recipient_city_en');
DROP PROCEDURE pt_strip_ws;

-- 3. "Removals" that are only a trailing space in the previous edition's ref_number
UPDATE grants_removed_records r
SET r.removal_kind = 'relabelled_as_current'
WHERE r.removed_in_edition_id = @ed AND r.ref_number <> TRIM(r.ref_number)
  AND EXISTS (SELECT 1 FROM master_table m WHERE m.id = CONCAT(SUBSTRING_INDEX(r.id, ',', 1), ',', TRIM(r.ref_number), ',', SUBSTRING_INDEX(r.id, ',', -1)));
SELECT removal_kind, COUNT(*), ROUND(SUM(agreement_value)) FROM grants_removed_records WHERE removed_in_edition_id = @ed GROUP BY 1;

-- 3b. Same, where the trailing space sits in the previous edition's record id (ref_number column was trimmed).
UPDATE grants_removed_records r SET r.removal_kind = 'relabelled_as_current'
WHERE r.removed_in_edition_id = @ed AND r.id REGEXP '[[:space:]],'
  AND EXISTS (SELECT 1 FROM master_table m WHERE m.id = REGEXP_REPLACE(r.id, '[[:space:]]+,', ','));
