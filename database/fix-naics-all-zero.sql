-- Recorded fix (2026-11-18): the official file uses an all-zero NAICS code ("000000") for "no code".
-- rebuild-master-from-official.sql cast it to integer 0; it must be NULL. The raw string stays in
-- src_grants_20261117. Each change is logged in corrections_history as data_cleaning.
SET @ed = (SELECT edition_id FROM grants_source_editions WHERE edition_code = '2026-11-17_open-canada-grants-csv');
INSERT INTO corrections_history (record_id, ref_number, amendment_number, field_name, old_value, new_value, change_source, edition_id)
SELECT id, ref_number, amendment_number, 'naics_identifier', '0', NULL, 'data_cleaning', @ed FROM master_table WHERE naics_identifier = 0;
UPDATE master_table SET naics_identifier = NULL WHERE naics_identifier = 0;
SELECT ROW_COUNT() AS rows_fixed;
