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
