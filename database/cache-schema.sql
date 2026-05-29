-- PublicTech riding cache — secondary database schema (pt-cache-02).
--
-- These tables hold the precomputed per-riding spending rollups (one JSON
-- payload per federal riding) that the MP profile and riding-report pages
-- read instead of querying the primary grantconts database at request time.
--
-- The warmer (database/warm-riding-cache.php / the weekly cron) creates these
-- automatically with CREATE TABLE IF NOT EXISTS, so applying this file by hand
-- is optional — it's provided for reference and for provisioning the schema
-- ahead of the first warm.
--
-- Target: the govdata_cache_02 database, e.g.
--   mysql -u gdc_02 -p -h mysql.alexmilton.ca govdata_cache_02 < cache-schema.sql

CREATE TABLE IF NOT EXISTS `riding_cache` (
	`riding_number`   VARCHAR(8)    NOT NULL,
	`riding_slug`     VARCHAR(190)  NOT NULL,
	`riding_name_en`  VARCHAR(190)  NULL,
	`riding_name_fr`  VARCHAR(190)  NULL,
	`province`        VARCHAR(8)    NULL,
	`total_value`     DECIMAL(20,2) NULL,
	`agreement_count` BIGINT        NULL,
	`first_year`      SMALLINT      NULL,
	`last_year`       SMALLINT      NULL,
	`payload`         LONGTEXT      NOT NULL,
	`generated_at`    DATETIME      NOT NULL,
	PRIMARY KEY (`riding_number`),
	KEY `riding_slug` (`riding_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Key/value metadata: the riding directory (number/slug/name for MP linking),
-- last refresh timestamp, and the keyword label catalogue.
CREATE TABLE IF NOT EXISTS `cache_meta` (
	`meta_key`   VARCHAR(64) NOT NULL,
	`meta_value` LONGTEXT    NULL,
	`updated_at` DATETIME    NOT NULL,
	PRIMARY KEY (`meta_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- =============================================================================
-- PublicTech entity-funding cache schema  (database: govdata_cache_01)
-- =============================================================================
--
-- This is the SECOND database referenced in the architecture: the front end
-- never queries the source `grantconts` database on page load. Instead a
-- refresh job (database/refresh-entity-cache.php) reads grantconts, rolls each
-- entity up into a JSON snapshot, and writes it here by CALLing a stored
-- procedure. The web pages then serve the cached snapshot, and the in-page
-- search box queries this database live through `sp_search_entity_agreements`.
--
-- Triggers are intentionally NOT used (they are disallowed on the shared host);
-- all writes flow through the stored procedures below, called from PHP.
--
-- Apply once on a fresh database (re-running is safe — it is idempotent):
--
--     mysql -u gdc_01 -p -h mysql.govdata.ca govdata_cache_01 < cache-schema.sql
--
-- Requires MySQL 8.0+ (uses JSON_TABLE in sp_upsert_entity_snapshot).
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- entity_snapshots — one cached row per entity. snapshot_json holds the full
-- pre-computed payload the page renders (totals, by-year, by-program,
-- by-department, keyword hits, top agreements). The scalar columns alongside it
-- are denormalised copies used for cheap listing / sorting without parsing JSON.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `entity_snapshots` (
	`slug`             VARCHAR(80)   NOT NULL,
	`entity_name`      VARCHAR(350)  NOT NULL,
	`source_alias`     VARCHAR(40)   DEFAULT NULL,
	`query_keywords`   TEXT          DEFAULT NULL,
	`total_funding`    DECIMAL(18,2) NOT NULL DEFAULT 0,
	`positive_funding` DECIMAL(18,2) NOT NULL DEFAULT 0,
	`negative_funding` DECIMAL(18,2) NOT NULL DEFAULT 0,
	`agreement_count`  INT UNSIGNED  NOT NULL DEFAULT 0,
	`first_year`       SMALLINT      DEFAULT NULL,
	`last_year`        SMALLINT      DEFAULT NULL,
	`snapshot_json`    LONGTEXT      NOT NULL,
	`created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (`slug`),
	CONSTRAINT `chk_snapshot_json` CHECK (JSON_VALID(`snapshot_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- entity_agreements — flattened, searchable copy of each snapshot's agreements.
-- Rebuilt for an entity every time its snapshot is upserted. A FULLTEXT index
-- backs the in-page search; (slug, value) and (slug, year) cover sorted browse.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `entity_agreements` (
	`id`                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	`slug`                     VARCHAR(80)   NOT NULL,
	`ref_number`               VARCHAR(50)   DEFAULT NULL,
	`recipient_legal_name`     VARCHAR(350)  DEFAULT NULL,
	`recipient_operating_name` VARCHAR(350)  DEFAULT NULL,
	`agreement_title`          VARCHAR(800)  DEFAULT NULL,
	`description`              TEXT          DEFAULT NULL,
	`prog_name`                VARCHAR(255)  DEFAULT NULL,
	`owner_org`                VARCHAR(255)  DEFAULT NULL,
	`agreement_value`          DECIMAL(15,2) DEFAULT NULL,
	`fiscal_year`              VARCHAR(9)    DEFAULT NULL,
	`year`                     SMALLINT      DEFAULT NULL,
	`agreement_start_date`     DATE          DEFAULT NULL,
	PRIMARY KEY (`id`),
	KEY `idx_slug_year` (`slug`, `year`),
	KEY `idx_slug_value` (`slug`, `agreement_value`),
	FULLTEXT KEY `ft_search` (`recipient_legal_name`, `recipient_operating_name`, `agreement_title`, `description`, `prog_name`, `owner_org`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Stored procedures. These are the ONLY supported way to move data in/out of
-- the cache. PHP calls them with bound parameters (see class-cache-db.php).
-- =============================================================================

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
		(`slug`, `ref_number`, `recipient_legal_name`, `recipient_operating_name`,
		 `agreement_title`, `description`, `prog_name`, `owner_org`,
		 `agreement_value`, `fiscal_year`, `year`, `agreement_start_date`)
	SELECT
		p_slug,
		jt.ref_number,
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
			ref_number VARCHAR(50)   PATH '$.ref_number',
			legal_name VARCHAR(350)  PATH '$.recipient_legal_name',
			op_name    VARCHAR(350)  PATH '$.recipient_operating_name',
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
		`ref_number`, `recipient_legal_name`, `recipient_operating_name`,
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
