-- =============================================================================
-- Recorded fix (2026-11-18): amendment record ids use the version's POSITION, not its number.
--
-- Verified on search.open.canada.ca: the record-id suffix ",NN" is the 0-based position of that
-- version in the agreement's version list ordered by amendment_number (e.g. amendments 0,2,3,4,5
-- appear as ,00 ,01 ,02 ,03 ,04), and the portal DISPLAYS that position as "Amendment Number".
-- The latest version also exists as ",current". rebuild-master-from-official.sql had padded the
-- amendment_number itself. The May 2026 export ids (master_table_pre_official_20261117) were already
-- position-based, so the edition-2 comparison paired the wrong versions wherever an agreement's
-- amendment numbers have gaps (219,096 agreements do).
--
-- This step: (1) re-keys non-current master_table ids to position ids, amendment_number keeps the TRUE
-- number; (2) keeps a copy of the edition-2 comparison output, then clears it so it can be re-run with
-- correct pairing (log-master-changes.py --old-table master_table_pre_official_20261117 --new-table
-- master_table, then reclassify-official-changes.sql); (3) re-derives removed records.
-- The superseded comparison rows are preserved in *_ed2_superseded tables.
-- =============================================================================

SET @ed = (SELECT edition_id FROM grants_source_editions WHERE edition_code = '2026-11-17_open-canada-grants-csv');

-- 1. Position of every version within its agreement, from the verbatim source
DROP TABLE IF EXISTS tmp_version_pos;
CREATE TABLE tmp_version_pos ENGINE=InnoDB AS
SELECT owner_org, TRIM(ref_number) AS ref_number, CAST(amendment_number AS UNSIGNED) AS amend,
       ROW_NUMBER() OVER (PARTITION BY owner_org, TRIM(ref_number) ORDER BY CAST(amendment_number AS UNSIGNED)) - 1 AS pos
FROM src_grants_20261117;
ALTER TABLE tmp_version_pos ADD PRIMARY KEY (owner_org, ref_number, amend);

-- Two passes so the UNIQUE id never collides mid-update
UPDATE master_table m
JOIN tmp_version_pos v ON v.owner_org = SUBSTRING_INDEX(m.id, ',', 1) AND v.ref_number = m.ref_number AND v.amend = m.amendment_number
SET m.id = CONCAT(m.id, '#re');
UPDATE master_table m
JOIN tmp_version_pos v ON v.owner_org = SUBSTRING_INDEX(m.id, ',', 1) AND v.ref_number = m.ref_number AND v.amend = m.amendment_number
SET m.id = CONCAT(v.owner_org, ',', v.ref_number, ',', LPAD(v.pos, 2, '0'))
WHERE m.id LIKE '%#re';
SELECT 'ids still tagged (expect 0)', COUNT(*) FROM master_table WHERE id LIKE '%#re';

-- 2. Preserve, then clear, the edition-2 comparison output (it will be regenerated)
DROP TABLE IF EXISTS corrections_history_ed2_superseded;
CREATE TABLE corrections_history_ed2_superseded AS SELECT * FROM corrections_history WHERE edition_id = @ed;
DROP TABLE IF EXISTS grants_removed_records_ed2_superseded;
CREATE TABLE grants_removed_records_ed2_superseded AS SELECT * FROM grants_removed_records WHERE removed_in_edition_id = @ed;
DELETE FROM corrections_history WHERE edition_id = @ed AND change_source IN ('new_record', 'removed');
DELETE FROM grants_removed_records WHERE removed_in_edition_id = @ed;

-- needs_review rows reference record ids: re-key them to the new ids
UPDATE corrections_history c
JOIN tmp_version_pos v ON v.owner_org = SUBSTRING_INDEX(c.record_id, ',', 1) AND v.ref_number = c.ref_number AND v.amend = c.amendment_number
SET c.record_id = CONCAT(v.owner_org, ',', v.ref_number, ',', LPAD(v.pos, 2, '0'))
WHERE c.edition_id = @ed AND c.change_source = 'needs_review' AND c.amendment_number IS NOT NULL;

-- 3. Removed and new records, by id (now consistent with the May export's position ids)
INSERT INTO grants_removed_records
SELECT o.*, @ed, (SELECT edition_id FROM grants_source_editions WHERE edition_code = '2026-05-30_search-portal-export'), NULL, CURRENT_TIMESTAMP
FROM master_table_pre_official_20261117 o LEFT JOIN master_table n ON n.id = o.id WHERE n.id IS NULL;
INSERT INTO corrections_history (record_id, ref_number, amendment_number, field_name, old_value, new_value, change_source, edition_id, prev_edition_id)
SELECT o.id, o.ref_number, o.amendment_number, '*', CONCAT('agreement_value=', IFNULL(o.agreement_value, 'NULL')), NULL, 'removed', @ed, 1
FROM master_table_pre_official_20261117 o LEFT JOIN master_table n ON n.id = o.id WHERE n.id IS NULL;
INSERT INTO corrections_history (record_id, ref_number, amendment_number, field_name, old_value, new_value, change_source, edition_id, prev_edition_id)
SELECT n.id, n.ref_number, n.amendment_number, '*', NULL, CONCAT('agreement_value=', IFNULL(n.agreement_value, 'NULL')), 'new_record', @ed, 1
FROM master_table n LEFT JOIN master_table_pre_official_20261117 o ON o.id = n.id WHERE o.id IS NULL;

-- removal kinds, judged on the agreement and its versions in the official source
UPDATE grants_removed_records r
LEFT JOIN tmp_src_latest l ON l.owner_org = SUBSTRING_INDEX(r.id, ',', 1) AND l.ref_number = r.ref_number
SET r.removal_kind = CASE
  WHEN l.ref_number IS NULL AND EXISTS (SELECT 1 FROM tmp_src_latest x WHERE x.ref_number = r.ref_number) THEN 'moved_department'
  WHEN l.ref_number IS NULL THEN 'agreement_removed'
  WHEN SUBSTRING_INDEX(r.id, ',', -1) = 'current' THEN 'agreement_removed'
  WHEN CAST(SUBSTRING_INDEX(r.id, ',', -1) AS UNSIGNED) + 1 = l.versions THEN 'relabelled_as_current'
  WHEN CAST(SUBSTRING_INDEX(r.id, ',', -1) AS UNSIGNED) >= l.versions THEN 'version_renumbered'
  ELSE 'older_version_removed' END
WHERE r.removed_in_edition_id = @ed;

SELECT change_source, COUNT(*) FROM corrections_history WHERE edition_id = @ed GROUP BY 1;
SELECT removal_kind, COUNT(*), ROUND(SUM(agreement_value)) FROM grants_removed_records WHERE removed_in_edition_id = @ed GROUP BY 1;
