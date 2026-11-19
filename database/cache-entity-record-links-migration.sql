-- Migration (2026-11-18) for EXISTING govdata_cache_01 databases: per-entry Government of Canada record links
-- in entity_agreements (record_id, record_url) and wider name/ref columns. New installs get this from
-- cache-schema.sql. Re-run database sync of entity snapshots afterwards (Entity_Reports::sync_all).
ALTER TABLE `entity_agreements`
  MODIFY `ref_number` VARCHAR(100) DEFAULT NULL,
  ADD COLUMN `record_id` VARCHAR(255) DEFAULT NULL AFTER `ref_number`,
  ADD COLUMN `record_url` VARCHAR(1000) DEFAULT NULL AFTER `record_id`,
  MODIFY `recipient_legal_name` VARCHAR(600) DEFAULT NULL,
  MODIFY `recipient_operating_name` VARCHAR(600) DEFAULT NULL;

DROP PROCEDURE IF EXISTS `sp_upsert_entity_snapshot`;
DROP PROCEDURE IF EXISTS `sp_get_entity_snapshot`;
DROP PROCEDURE IF EXISTS `sp_search_entity_agreements`;
DROP PROCEDURE IF EXISTS `sp_list_entities`;

DELIMITER //

-- -----------------------------------------------------------------------------
-- sp_upsert_entity_snapshot — write (or replace) one entity's cached snapshot.
-- The scalar roll-ups are passed in pre-computed by the refresh job; the
-- searchable agreement rows are expanded from the JSON payload via JSON_TABLE.
-- -----------------------------------------------------------------------------
CREATE PROCEDURE `sp_upsert_entity_snapshot` (
	IN p_slug      VARCHAR(80)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
	IN p_name      VARCHAR(350) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
	IN p_keywords  TEXT         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
	IN p_source    VARCHAR(40)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
	IN p_total     DECIMAL(18,2),
	IN p_positive  DECIMAL(18,2),
	IN p_negative  DECIMAL(18,2),
	IN p_count     INT UNSIGNED,
	IN p_first_yr  SMALLINT,
	IN p_last_yr   SMALLINT,
	IN p_snapshot  LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
)
SQL SECURITY INVOKER
BEGIN
	-- Reject obviously malformed payloads before touching any table.
	IF p_snapshot IS NULL OR JSON_VALID(p_snapshot) = 0 THEN
		SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'snapshot payload is not valid JSON';
	END IF;

	INSERT INTO `entity_snapshots`
		(`slug`, `entity_name`, `source_alias`, `query_keywords`,
		 `total_funding`, `positive_funding`, `negative_funding`,
		 `agreement_count`, `first_year`, `last_year`, `snapshot_json`)
	VALUES
		(p_slug, p_name, p_source, p_keywords,
		 COALESCE(p_total, 0), COALESCE(p_positive, 0), COALESCE(p_negative, 0),
		 COALESCE(p_count, 0), p_first_yr, p_last_yr, p_snapshot)
	ON DUPLICATE KEY UPDATE
		`entity_name`      = VALUES(`entity_name`),
		`source_alias`     = VALUES(`source_alias`),
		`query_keywords`   = VALUES(`query_keywords`),
		`total_funding`    = VALUES(`total_funding`),
		`positive_funding` = VALUES(`positive_funding`),
		`negative_funding` = VALUES(`negative_funding`),
		`agreement_count`  = VALUES(`agreement_count`),
		`first_year`       = VALUES(`first_year`),
		`last_year`        = VALUES(`last_year`),
		`snapshot_json`    = VALUES(`snapshot_json`);

	-- Rebuild the searchable agreement rows for this entity.
	DELETE FROM `entity_agreements` WHERE `slug` = p_slug;

	INSERT INTO `entity_agreements`
		(`slug`, `ref_number`, `record_id`, `record_url`, `recipient_legal_name`, `recipient_operating_name`,
		 `agreement_title`, `description`, `prog_name`, `owner_org`,
		 `agreement_value`, `fiscal_year`, `year`, `agreement_start_date`)
	SELECT
		p_slug,
		jt.ref_number,
		jt.record_id,
		jt.record_url,
		jt.legal_name,
		jt.op_name,
		jt.title,
		jt.descr,
		jt.prog,
		jt.dept,
		jt.val,
		jt.fyear,
		jt.yr,
		CASE WHEN jt.sdate REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN jt.sdate ELSE NULL END
	FROM JSON_TABLE(
		CAST(p_snapshot AS JSON),
		'$.agreements[*]' COLUMNS (
			ref_number VARCHAR(100)  PATH '$.ref_number',
			record_id  VARCHAR(255)  PATH '$.record_id',
			record_url VARCHAR(1000) PATH '$.record_url',
			legal_name VARCHAR(600)  PATH '$.recipient_legal_name',
			op_name    VARCHAR(600)  PATH '$.recipient_operating_name',
			title      VARCHAR(800)  PATH '$.title',
			descr      TEXT          PATH '$.description',
			prog       VARCHAR(255)  PATH '$.program',
			dept       VARCHAR(255)  PATH '$.department',
			val        DECIMAL(15,2) PATH '$.value',
			fyear      VARCHAR(9)    PATH '$.fiscal_year',
			yr         SMALLINT      PATH '$.year',
			sdate      VARCHAR(20)   PATH '$.start_date'
		)
	) AS jt;
END //

-- -----------------------------------------------------------------------------
-- sp_get_entity_snapshot — return the cached snapshot for one entity. Used to
-- warm the page-level (WordPress transient) cache on a miss.
-- -----------------------------------------------------------------------------
CREATE PROCEDURE `sp_get_entity_snapshot` (
	IN p_slug VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
)
SQL SECURITY INVOKER
BEGIN
	SELECT
		`slug`, `entity_name`, `total_funding`, `positive_funding`,
		`negative_funding`, `agreement_count`, `first_year`, `last_year`,
		`query_keywords`, `source_alias`, `updated_at`, `snapshot_json`
	FROM `entity_snapshots`
	WHERE `slug` = p_slug
	LIMIT 1;
END //

-- -----------------------------------------------------------------------------
-- sp_search_entity_agreements — live, in-page search scoped to a single
-- entity. Empty query returns the largest agreements; otherwise FULLTEXT
-- (natural language) plus a LIKE fallback so short / partial tokens still hit.
-- -----------------------------------------------------------------------------
CREATE PROCEDURE `sp_search_entity_agreements` (
	IN p_slug  VARCHAR(80)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
	IN p_query VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
	IN p_limit INT
)
SQL SECURITY INVOKER
BEGIN
	DECLARE v_limit INT DEFAULT 50;
	SET v_limit = LEAST(GREATEST(COALESCE(p_limit, 50), 1), 500);

	SELECT
		`ref_number`, `record_id`, `record_url`, `recipient_legal_name`, `recipient_operating_name`,
		`agreement_title`, `prog_name`, `owner_org`, `agreement_value`,
		`fiscal_year`, `year`, `agreement_start_date`
	FROM `entity_agreements`
	WHERE `slug` = p_slug
	  AND (
			p_query IS NULL OR p_query = ''
			OR MATCH (`recipient_legal_name`, `recipient_operating_name`, `agreement_title`, `description`, `prog_name`, `owner_org`)
			   AGAINST (p_query IN NATURAL LANGUAGE MODE)
			OR `agreement_title` LIKE CONCAT('%', p_query, '%')
			OR `description`     LIKE CONCAT('%', p_query, '%')
			OR `prog_name`       LIKE CONCAT('%', p_query, '%')
			OR `owner_org`       LIKE CONCAT('%', p_query, '%')
	  )
	ORDER BY `agreement_value` DESC
	LIMIT v_limit;
END //

-- -----------------------------------------------------------------------------
-- sp_list_entities — directory listing for an index page, biggest first.
-- -----------------------------------------------------------------------------
CREATE PROCEDURE `sp_list_entities` ()
SQL SECURITY INVOKER
BEGIN
	SELECT
		`slug`, `entity_name`, `total_funding`, `agreement_count`,
		`first_year`, `last_year`, `updated_at`
	FROM `entity_snapshots`
	ORDER BY `total_funding` DESC;
END //

DELIMITER ;
