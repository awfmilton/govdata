-- =============================================================================
-- Public notices attached to grant records (2026-11-18). First use: the 179 agreements the Government
-- of Canada deleted between the May 2026 data and the 2026-11-17 official edition. Wording approved by
-- the owner. Portal verification: all 179 return the empty record template on search.open.canada.ca
-- (checked 2026-11-18 10:24 UTC, /opt/govdata-tests/results/removed-179/).
-- The notice text is stored per record so later editions can add notices without code changes.
-- =============================================================================
CREATE TABLE IF NOT EXISTS grants_record_notices (
    notice_id     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    record_id     VARCHAR(255) NOT NULL,
    ref_number    VARCHAR(100) NOT NULL,
    notice_type   ENUM('deleted_by_government','missing_from_open_data','other') NOT NULL,
    title         VARCHAR(255) NOT NULL,
    body          TEXT NOT NULL,
    first_seen    VARCHAR(40)  NULL COMMENT 'human wording of the earliest copy we hold',
    portal_checked_at DATETIME NULL,
    edition_id    SMALLINT UNSIGNED NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_record_type (record_id, notice_type), KEY idx_ref (ref_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

SET @ed = (SELECT edition_id FROM grants_source_editions WHERE edition_code = '2026-11-17_open-canada-grants-csv');

INSERT INTO grants_record_notices (record_id, ref_number, notice_type, title, body, first_seen, portal_checked_at, edition_id)
SELECT r.id, r.ref_number, 'deleted_by_government',
  'Deleted by the Government of Canada',
  CONCAT(
    'This record was published in the Government of Canada''s grants and contributions data ',
    IF(early.ref_number IS NOT NULL, 'as early as March 2025 and was still published in May 2026', 'as of May 2026'),
    ', but it was removed (not amended or replaced) by November 17, 2026. ',
    'It no longer appears in the official open-data file or on the Government''s search portal (checked November 18, 2026). ',
    'No amendment or correction explains the removal, and we don''t know why it was deleted. ',
    'This record requires further investigation. The record below is preserved exactly as it was last published.',
    IF(amended.ref_number IS NOT NULL, ' This agreement had earlier amendments, but the removal itself was not an amendment.', '')),
  IF(early.ref_number IS NOT NULL, 'as early as March 2025', 'as of May 2026'),
  '2026-11-18 10:24:00', @ed
FROM grants_removed_records r
LEFT JOIN (SELECT DISTINCT ref_number FROM master_table_pre_rebuild_20260531) early ON early.ref_number = r.ref_number
LEFT JOIN (SELECT DISTINCT SUBSTRING_INDEX(id, ',', 1) AS org, ref_number FROM master_table_pre_official_20261117 WHERE id NOT LIKE '%,current') amended
       ON amended.org = SUBSTRING_INDEX(r.id, ',', 1) AND amended.ref_number = r.ref_number
WHERE r.removal_kind = 'agreement_removed' AND r.removed_in_edition_id = @ed
ON DUPLICATE KEY UPDATE body = VALUES(body), first_seen = VALUES(first_seen), portal_checked_at = VALUES(portal_checked_at);

SELECT notice_type, first_seen, COUNT(*), SUM(body LIKE '%earlier amendments%') FROM grants_record_notices GROUP BY 1, 2;

-- Lookups used by the site (recipient pages match like Entity_Reports::source_query; riding pages by number).
ALTER TABLE grants_removed_records
  ADD FULLTEXT INDEX ft_recipient (recipient_legal_name, recipient_operating_name),
  ADD INDEX idx_riding (federal_riding_number);
