-- =============================================================================
-- Rebuild grantconts.master_table from an official open.canada.ca grants.csv edition,
-- recording EVERY difference from the previous master_table. Nothing is discarded:
--   * grants_source_editions   — one row per source edition (provenance)
--   * corrections_history      — one row per changed field (old/new value, classified)
--   * grants_removed_records   — full copy of every record that vanished from the source
--   * src_grants_<date>        — the verbatim source table is kept permanently
--   * the previous master_table is kept as master_table_pre_official_20261117
--
-- Prerequisite: database/load-official-grants.py has loaded src_grants_20261117.
-- Recorded step of the 2026-11 rebuild. Run with:  mysql grantconts < this file
--
-- NOTE: TRIM() strips spaces only; text values may still carry CR/LF/TAB — fix-whitespace-official.sql
-- (run after this file) strips all leading/trailing whitespace and logs each change.
-- Conventions kept from the previous pipeline (normalize.py and the site depend on them):
--   * the LATEST version of an agreement has amendment_number = NULL and id '<org>,<ref>,current'
--   * earlier versions carry amendment_number and id '<org>,<ref>,NN' (zero-padded)
-- fiscal_year / quarter_en are derived from ref_number ONLY when it carries a consecutive
-- 'YYYY-YYYY' (or YYYY_YYYY) disclosure period; otherwise NULL. They are the quarterly
-- DISCLOSURE period, not the agreement start year.
-- =============================================================================

SET SESSION group_concat_max_len = 1048576;
SET @src_edition  = '2026-11-17_open-canada-grants-csv';
SET @prev_edition = '2026-05-30_search-portal-export';

-- ---------------------------------------------------------------------------
-- 1. Provenance
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS grants_source_editions (
    edition_id    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    edition_code  VARCHAR(64)  NOT NULL UNIQUE,
    source_desc   VARCHAR(500) NOT NULL,
    source_url    VARCHAR(500) NULL,
    file_sha256   CHAR(64)     NULL,
    row_count     INT UNSIGNED NULL,
    retrieved_at  DATE         NULL,
    loaded_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes         TEXT         NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO grants_source_editions (edition_code, source_desc, source_url, file_sha256, row_count, retrieved_at, notes) VALUES
 (@prev_edition, 'search.open.canada.ca bulk export, per-year CSVs, cleaned by database/pipeline.py and loaded by rebuild-master.py',
  'https://search.open.canada.ca/grants/', NULL, 1264823, '2026-05-30',
  'agreement_value in this export is rounded to float32 precision (~7 significant digits); fiscal_year column blank in source; 2025-26 codes were blanked by a clean-csv.py bug (fixed 2026-11).'),
 (@src_edition, 'open.canada.ca Grants and Contributions, dataset 432527ab-7aac-45b5-81d6-7597107a7013, resource 1d15a62f-5656-49ad-8c88-f40ce689d831 (grants.csv)',
  'https://open.canada.ca/data/en/dataset/432527ab-7aac-45b5-81d6-7597107a7013', 'e39d38e98f8d81bae75e4e77db248cc1d9b754343e3451cb4bd37b001e50e5cf',
  1383280, '2026-11-17', 'Verbatim copy kept in src_grants_20261117. Full-precision agreement_value. No id/fiscal_year columns.');
SET @src_ed  = (SELECT edition_id FROM grants_source_editions WHERE edition_code = @src_edition);
SET @prev_ed = (SELECT edition_id FROM grants_source_editions WHERE edition_code = @prev_edition);

-- ---------------------------------------------------------------------------
-- 2. Change log: the snapshot's corrections_history columns, plus edition and wider classes
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS corrections_history (
    correction_id    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    record_id        VARCHAR(255) NOT NULL,
    ref_number       VARCHAR(100) NOT NULL,
    amendment_number INT UNSIGNED NULL,
    field_name       VARCHAR(100) NOT NULL,
    old_value        MEDIUMTEXT   NULL,
    new_value        MEDIUMTEXT   NULL,
    change_source    ENUM('government_update','data_cleaning','new_record','needs_review',
                          'removed','restored','precision_correction','null_fill','value_cleared') NOT NULL,
    changed_at       TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    edition_id       SMALLINT UNSIGNED NULL,
    prev_edition_id  SMALLINT UNSIGNED NULL,
    KEY idx_record (record_id), KEY idx_ref (ref_number), KEY idx_field (field_name),
    KEY idx_source (change_source), KEY idx_changed (changed_at), KEY idx_edition (edition_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Every field-level difference between consecutive source editions. Never pruned.';

-- ---------------------------------------------------------------------------
-- 3. Widen columns so no official value is truncated (old pipeline silently cut them)
-- ---------------------------------------------------------------------------
ALTER TABLE master_table
  DROP INDEX idx_cleaned_new_research_org, DROP INDEX idx_cleaned_new_recipient_city,
  MODIFY ref_number                 VARCHAR(100)  NULL,
  MODIFY federal_riding_name_en     VARCHAR(400)  NULL,
  MODIFY federal_riding_name_fr     VARCHAR(400)  NULL,
  MODIFY prog_name_en               VARCHAR(700)  NULL,
  MODIFY prog_name_fr               VARCHAR(1400) NULL,
  MODIFY recipient_city             VARCHAR(900)  NULL,
  MODIFY recipient_legal_name       VARCHAR(600)  NULL,
  MODIFY recipient_operating_name   VARCHAR(600)  NULL,
  MODIFY research_organization_name VARCHAR(3000) NULL,
  ADD INDEX idx_cleaned_new_research_org (research_organization_name(255)),
  ADD INDEX idx_cleaned_new_recipient_city (recipient_city(255));
ALTER TABLE recipients     MODIFY recipient_legal_name VARCHAR(600) NULL, MODIFY recipient_operating_name VARCHAR(600) NULL,
                           MODIFY research_organization_name VARCHAR(3000) NULL;
ALTER TABLE programs       MODIFY prog_name_en VARCHAR(700) NULL, MODIFY prog_name_fr VARCHAR(1400) NULL;
ALTER TABLE locations      DROP INDEX idx_locations_recipient_city, MODIFY recipient_city VARCHAR(900) NULL,
                           MODIFY federal_riding_name_en VARCHAR(400) NULL, MODIFY federal_riding_name_fr VARCHAR(400) NULL,
                           MODIFY recipient_postal_code VARCHAR(10) NULL,
                           ADD INDEX idx_locations_recipient_city (recipient_city(255));
ALTER TABLE agreements     MODIFY ref_number VARCHAR(100) NULL;
ALTER TABLE agreement_urls MODIFY ref_number VARCHAR(100) NOT NULL;

-- ---------------------------------------------------------------------------
-- 4. Latest version per (owner_org, ref_number) in the source
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS tmp_src_latest;
CREATE TABLE tmp_src_latest ENGINE=InnoDB AS
  SELECT owner_org, ref_number, MAX(CAST(amendment_number AS UNSIGNED)) AS max_amend, COUNT(*) AS versions
  FROM src_grants_20261117 GROUP BY owner_org, ref_number;
ALTER TABLE tmp_src_latest ADD PRIMARY KEY (owner_org, ref_number);

-- ---------------------------------------------------------------------------
-- 5. Build master_new from the source (no trigger on it, so normalisation is not re-fired)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS master_new;
CREATE TABLE master_new LIKE master_table;

INSERT INTO master_new (
  ref_number, amendment_number, amendment_date, agreement_type, recipient_type, recipient_business_number,
  recipient_legal_name, recipient_operating_name, research_organization_name, recipient_country, recipient_province,
  recipient_city, recipient_postal_code, federal_riding_name_en, federal_riding_name_fr, federal_riding_number,
  prog_name_en, prog_name_fr, prog_purpose_en, prog_purpose_fr, agreement_title_en, agreement_title_fr, agreement_number,
  agreement_value, foreign_currency_type, foreign_currency_value, agreement_start_date, year, agreement_end_date,
  coverage, description_eng, description_fr, naics_identifier, expected_results_en, expected_results_fr,
  additional_information_en, additional_information_fr, agreement_value_range_en, coverage_en, has_amendments_en,
  format_en, recipient_city_en, fiscal_year, quarter_en, id, owner_org_en, owner_org_title)
SELECT
  TRIM(s.ref_number),
  IF(CAST(s.amendment_number AS UNSIGNED) = l.max_amend, NULL, CAST(s.amendment_number AS UNSIGNED)),
  IF(TRIM(s.amendment_date) REGEXP '^(19|20|21)[0-9]{2}-[0-9]{2}-[0-9]{2}', LEFT(TRIM(s.amendment_date), 10), NULL),
  CASE WHEN TRIM(s.agreement_type) IN ('C','G','O') THEN TRIM(s.agreement_type)
       WHEN TRIM(s.agreement_type) = 'Contribution' THEN 'C' WHEN TRIM(s.agreement_type) = 'Grant' THEN 'G'
       WHEN TRIM(s.agreement_type) = 'Other transfer payment' THEN 'O' ELSE NULL END,
  IF(TRIM(s.recipient_type) IN ('N','F','P','A','O','G','S','I'), TRIM(s.recipient_type), NULL),
  NULLIF(TRIM(s.recipient_business_number), ''),
  NULLIF(TRIM(s.recipient_legal_name), ''), NULLIF(TRIM(s.recipient_operating_name), ''),
  NULLIF(TRIM(s.research_organization_name), ''),
  IF(TRIM(s.recipient_country) REGEXP '^[A-Za-z]{2}$', UPPER(TRIM(s.recipient_country)), NULL),
  IF(TRIM(s.recipient_province) REGEXP '^[A-Za-z]{2}$', UPPER(TRIM(s.recipient_province)), NULL),
  NULLIF(TRIM(s.recipient_city), ''),
  IF(CHAR_LENGTH(REGEXP_REPLACE(UPPER(s.recipient_postal_code), '[[:space:]]', '')) BETWEEN 1 AND 7, REGEXP_REPLACE(UPPER(s.recipient_postal_code), '[[:space:]]', ''), NULL),  -- strip ALL whitespace (source has leading newlines)
  NULLIF(TRIM(s.federal_riding_name_en), ''), NULLIF(TRIM(s.federal_riding_name_fr), ''),
  IF(TRIM(s.federal_riding_number) REGEXP '^[0-9]{1,9}$', CAST(TRIM(s.federal_riding_number) AS UNSIGNED), NULL),
  NULLIF(TRIM(s.prog_name_en), ''), NULLIF(TRIM(s.prog_name_fr), ''),
  NULLIF(TRIM(s.prog_purpose_en), ''), NULLIF(TRIM(s.prog_purpose_fr), ''),
  NULLIF(TRIM(s.agreement_title_en), ''), NULLIF(TRIM(s.agreement_title_fr), ''),
  NULLIF(TRIM(s.agreement_number), ''),
  s.agreement_value,
  IF(TRIM(s.foreign_currency_type) REGEXP '^[A-Za-z]{3}$', UPPER(TRIM(s.foreign_currency_type)), NULL),
  s.foreign_currency_value,
  IF(TRIM(s.agreement_start_date) REGEXP '^(19|20|21)[0-9]{2}-[0-9]{2}-[0-9]{2}', LEFT(TRIM(s.agreement_start_date), 10), NULL),
  IF(TRIM(s.agreement_start_date) REGEXP '^(19|20|21)[0-9]{2}-', CAST(LEFT(TRIM(s.agreement_start_date), 4) AS UNSIGNED), NULL),
  IF(TRIM(s.agreement_end_date) REGEXP '^(19|20|21)[0-9]{2}-[0-9]{2}-[0-9]{2}', LEFT(TRIM(s.agreement_end_date), 10), NULL),
  NULLIF(TRIM(s.coverage), ''), NULLIF(TRIM(s.description_en), ''), NULLIF(TRIM(s.description_fr), ''),
  IF(TRIM(s.naics_identifier) REGEXP '^[0-9]{1,9}$' AND TRIM(s.naics_identifier) NOT REGEXP '^0+$', CAST(TRIM(s.naics_identifier) AS UNSIGNED), NULL),  -- all-zero = no code
  NULLIF(TRIM(s.expected_results_en), ''), NULLIF(TRIM(s.expected_results_fr), ''),
  NULLIF(TRIM(s.additional_information_en), ''), NULLIF(TRIM(s.additional_information_fr), ''),
  o.agreement_value_range_en, o.coverage_en,
  IF(l.versions > 1, 'Yes', COALESCE(o.has_amendments_en, 'No')),
  COALESCE(o.format_en, 'DEFAULT'),
  COALESCE(o.recipient_city_en, NULLIF(TRIM(SUBSTRING_INDEX(s.recipient_city, '|', 1)), '')),
  CASE WHEN REGEXP_SUBSTR(s.ref_number, '20[0-9]{2}[-_]20[0-9]{2}') IS NOT NULL
        AND CAST(RIGHT(REGEXP_SUBSTR(s.ref_number, '20[0-9]{2}[-_]20[0-9]{2}'), 4) AS UNSIGNED)
          = CAST(LEFT(REGEXP_SUBSTR(s.ref_number, '20[0-9]{2}[-_]20[0-9]{2}'), 4) AS UNSIGNED) + 1
       THEN REPLACE(REGEXP_SUBSTR(s.ref_number, '20[0-9]{2}[-_]20[0-9]{2}'), '_', '-') END,
  CASE WHEN REGEXP_SUBSTR(s.ref_number, '20[0-9]{2}[-_]20[0-9]{2}') IS NOT NULL
       THEN CONCAT('Q', REGEXP_SUBSTR(REGEXP_SUBSTR(s.ref_number, '[-_]Q(rt)?[1-4]([-_]|$)'), '[1-4]')) END,
  CONCAT(s.owner_org, ',', TRIM(s.ref_number), ',',
         IF(CAST(s.amendment_number AS UNSIGNED) = l.max_amend, 'current', LPAD(CAST(s.amendment_number AS UNSIGNED), 2, '0'))),
  NULLIF(TRIM(SUBSTRING_INDEX(s.owner_org_title, '|', 1)), ''),
  NULLIF(TRIM(s.owner_org_title), '')
FROM src_grants_20261117 s
JOIN tmp_src_latest l ON l.owner_org = s.owner_org AND l.ref_number = s.ref_number
LEFT JOIN master_table o ON o.id = CONCAT(s.owner_org, ',', TRIM(s.ref_number), ',',
         IF(CAST(s.amendment_number AS UNSIGNED) = l.max_amend, 'current', LPAD(CAST(s.amendment_number AS UNSIGNED), 2, '0')));

-- Codes the source carried as free text are kept verbatim in src_grants_20261117 and flagged for review.
INSERT INTO corrections_history (record_id, ref_number, amendment_number, field_name, old_value, new_value, change_source, edition_id, prev_edition_id)
SELECT n.id, n.ref_number, n.amendment_number, f.field, f.raw, NULL, 'needs_review', @src_ed, @prev_ed
FROM master_new n
JOIN tmp_src_latest l ON l.ref_number = n.ref_number AND l.owner_org = SUBSTRING_INDEX(n.id, ',', 1)
JOIN src_grants_20261117 s ON s.owner_org = l.owner_org AND s.ref_number = l.ref_number
 AND CAST(s.amendment_number AS UNSIGNED) = IFNULL(n.amendment_number, l.max_amend)
JOIN LATERAL (
  SELECT 'recipient_province' AS field, s.recipient_province AS raw, n.recipient_province AS kept
  UNION ALL SELECT 'recipient_country', s.recipient_country, n.recipient_country
  UNION ALL SELECT 'recipient_postal_code', s.recipient_postal_code, n.recipient_postal_code
  UNION ALL SELECT 'agreement_type', s.agreement_type, n.agreement_type
  UNION ALL SELECT 'recipient_type', s.recipient_type, n.recipient_type
) f
WHERE NULLIF(TRIM(f.raw), '') IS NOT NULL AND f.kept IS NULL;

-- ---------------------------------------------------------------------------
-- 6. Records that vanished from the source: keep a full copy, log them
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS grants_removed_records LIKE master_table;
ALTER TABLE grants_removed_records
  ADD COLUMN removed_in_edition_id   SMALLINT UNSIGNED NULL,
  ADD COLUMN last_seen_edition_id    SMALLINT UNSIGNED NULL,
  ADD COLUMN removed_detected_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  DROP INDEX uk_rb_id, ADD INDEX idx_removed_id (id);
INSERT INTO grants_removed_records
SELECT o.*, @src_ed, @prev_ed, CURRENT_TIMESTAMP
FROM master_table o LEFT JOIN master_new n ON n.id = o.id WHERE n.id IS NULL;
INSERT INTO corrections_history (record_id, ref_number, amendment_number, field_name, old_value, new_value, change_source, edition_id, prev_edition_id)
SELECT o.id, o.ref_number, o.amendment_number, '*', CONCAT('agreement_value=', IFNULL(o.agreement_value, 'NULL')), NULL, 'removed', @src_ed, @prev_ed
FROM master_table o LEFT JOIN master_new n ON n.id = o.id WHERE n.id IS NULL;

-- New records
INSERT INTO corrections_history (record_id, ref_number, amendment_number, field_name, old_value, new_value, change_source, edition_id, prev_edition_id)
SELECT n.id, n.ref_number, n.amendment_number, '*', NULL, CONCAT('agreement_value=', IFNULL(n.agreement_value, 'NULL')), 'new_record', @src_ed, @prev_ed
FROM master_new n LEFT JOIN master_table o ON o.id = n.id WHERE o.id IS NULL;
