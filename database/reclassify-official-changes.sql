-- =============================================================================
-- Step 3 of the 2026-11 official-source rebuild: refine change_source classifications.
-- Only the CATEGORY of a logged change is adjusted; no row of corrections_history is deleted,
-- and old_value/new_value are untouched. Each rule below is a representation difference
-- between the 2026-05 search-portal export and the official grants.csv, not a content change.
-- =============================================================================

SET @ed = (SELECT edition_id FROM grants_source_editions WHERE edition_code = '2026-11-17_open-canada-grants-csv');

-- R1. Money field: the export wrote 0.00 where the official file leaves it blank (no foreign currency).
UPDATE corrections_history SET change_source = 'data_cleaning'
 WHERE edition_id = @ed AND field_name IN ('foreign_currency_value', 'agreement_value')
   AND change_source = 'value_cleared' AND old_value IN ('0', '0.00');

-- R2. Postal codes that differ only by internal spacing (K1S 5B6 vs K1S5B6).
UPDATE corrections_history SET change_source = 'data_cleaning'
 WHERE edition_id = @ed AND field_name = 'recipient_postal_code' AND change_source = 'government_update'
   AND REPLACE(UPPER(old_value), ' ', '') = REPLACE(UPPER(new_value), ' ', '');

-- R3. Placeholder values the export used for "empty" (clean-csv.py NULL_SENTINELS) now blank.
UPDATE corrections_history SET change_source = 'data_cleaning'
 WHERE edition_id = @ed AND change_source = 'value_cleared'
   AND TRIM(old_value) IN ('-', 'N/A', 'n/a', 'none', 'None', 'null', 'NULL');

-- R4. Typographic-only differences (curly vs straight apostrophes, double spaces, dash variants).
UPDATE corrections_history SET change_source = 'data_cleaning'
 WHERE edition_id = @ed AND change_source = 'government_update'
   AND LOWER(REGEXP_REPLACE(REPLACE(REPLACE(REPLACE(old_value, '’', ''''), '–', '-'), '—', '-'), '[[:space:]]+', ' '))
     = LOWER(REGEXP_REPLACE(REPLACE(REPLACE(REPLACE(new_value, '’', ''''), '–', '-'), '—', '-'), '[[:space:]]+', ' '));

-- Removal kinds, so a real removal can be told apart from a version that was only relabelled.
ALTER TABLE grants_removed_records
  ADD COLUMN removal_kind ENUM('agreement_removed', 'older_version_removed', 'version_renumbered',
                               'relabelled_as_current', 'moved_department') NULL AFTER last_seen_edition_id;
UPDATE grants_removed_records r
LEFT JOIN tmp_src_latest l ON l.owner_org = SUBSTRING_INDEX(r.id, ',', 1) AND l.ref_number = r.ref_number
SET r.removal_kind = CASE
  WHEN l.ref_number IS NULL AND EXISTS (SELECT 1 FROM tmp_src_latest x WHERE x.ref_number = r.ref_number) THEN 'moved_department'
  WHEN l.ref_number IS NULL THEN 'agreement_removed'
  WHEN SUBSTRING_INDEX(r.id, ',', -1) = 'current' THEN 'agreement_removed'
  WHEN CAST(SUBSTRING_INDEX(r.id, ',', -1) AS UNSIGNED) = l.max_amend THEN 'relabelled_as_current'
  WHEN CAST(SUBSTRING_INDEX(r.id, ',', -1) AS UNSIGNED) > l.max_amend THEN 'version_renumbered'
  ELSE 'older_version_removed' END
WHERE r.removed_in_edition_id = @ed;

SELECT change_source, COUNT(*) FROM corrections_history WHERE edition_id = @ed GROUP BY 1 ORDER BY 2 DESC;
SELECT removal_kind, COUNT(*), ROUND(SUM(agreement_value)) FROM grants_removed_records WHERE removed_in_edition_id = @ed GROUP BY 1;
SELECT field_name, COUNT(*) FROM corrections_history WHERE edition_id = @ed AND change_source = 'government_update' GROUP BY 1 ORDER BY 2 DESC LIMIT 15;
