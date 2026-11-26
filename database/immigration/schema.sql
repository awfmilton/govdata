-- Immigration data domain (grantconts on govdata-serving).
--
-- Every number traces to a published file: imm_editions records each download (sha256, HTTP validators, CKAN
-- modified time, the archived raw copy), every fact row carries the edition it came from, and when a publisher
-- revises a figure the old and new values are kept in imm_fact_revisions. Nothing is overwritten silently.
--
-- Apply as an admin user (govadmin), then grant the loader with grants.sql.

CREATE TABLE IF NOT EXISTS imm_sources (
  source_key        VARCHAR(64)  NOT NULL PRIMARY KEY,   -- e.g. ircc_pr_citz_immcat, statcan_17100040
  publisher         VARCHAR(120) NOT NULL,
  title             VARCHAR(400) NOT NULL,
  download_url      VARCHAR(600) NOT NULL,
  landing_url       VARCHAR(600) NULL,
  detect_method     VARCHAR(40)  NOT NULL,               -- ckan | statcan_wds | http_validators | static
  detect_ref        VARCHAR(200) NULL,                   -- CKAN package id / StatCan product id
  grain             ENUM('month','quarter','year') NOT NULL,
  coverage_note     VARCHAR(400) NULL,
  rounding_note     VARCHAR(400) NULL,
  enabled           TINYINT(1)   NOT NULL DEFAULT 1,
  updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS imm_editions (
  edition_id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_key             VARCHAR(64)  NOT NULL,
  retrieved_at           DATETIME     NOT NULL,
  file_sha256            CHAR(64)     NOT NULL,
  file_bytes             BIGINT UNSIGNED NOT NULL,
  http_etag              VARCHAR(200) NULL,
  http_last_modified     VARCHAR(100) NULL,
  publisher_modified     VARCHAR(100) NULL,               -- CKAN metadata_modified / StatCan releaseTime
  raw_path               VARCHAR(400) NOT NULL,           -- archived verbatim copy (gzip) on the loader host
  row_count              INT UNSIGNED NULL,
  status                 ENUM('loading','loaded','failed') NOT NULL DEFAULT 'loading',
  rows_inserted          INT UNSIGNED NULL,
  rows_revised           INT UNSIGNED NULL,
  rows_removed           INT UNSIGNED NULL,
  loaded_at              DATETIME     NULL,
  notes                  VARCHAR(1000) NULL,
  KEY idx_source (source_key, edition_id),
  UNIQUE KEY uniq_source_sha (source_key, file_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One row per (source, check). A check that finds nothing new is logged too, so silence is never ambiguous.
CREATE TABLE IF NOT EXISTS imm_refresh_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id        CHAR(26)     NOT NULL,
  source_key    VARCHAR(64)  NOT NULL,
  checked_at    DATETIME     NOT NULL,
  outcome       ENUM('unchanged','loaded','failed','skipped') NOT NULL,
  edition_id    INT UNSIGNED NULL,
  detail        VARCHAR(2000) NULL,
  KEY idx_source_time (source_key, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Canonical countries/regions. Names follow IRCC 2015+ open data; other spellings map through imm_country_alias.
CREATE TABLE IF NOT EXISTS imm_country (
  country_id    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name_en       VARCHAR(200) NOT NULL,
  name_fr       VARCHAR(200) NULL,
  kind          ENUM('country','region','group','other') NOT NULL DEFAULT 'country',
  parent_id     SMALLINT UNSIGNED NULL,               -- region a historical group belongs to, when known
  notes         VARCHAR(400) NULL,
  UNIQUE KEY uniq_name (name_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS imm_country_alias (
  alias_norm    VARCHAR(200) NOT NULL PRIMARY KEY,     -- normalised spelling (see govdata_imm/country.py)
  alias_raw     VARCHAR(200) NOT NULL,
  country_id    SMALLINT UNSIGNED NOT NULL,
  source_key    VARCHAR(64)  NULL,
  method        ENUM('exact','normalised','manual') NOT NULL,
  KEY idx_country (country_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- The facts. One table for every series and grain; typed columns for the dimensions publishers use, NULL where a
-- series does not have that dimension. country_id is NULL only for national totals or an unmatched spelling
-- (country_raw is always kept). row_key identifies the same cell across editions so revisions can be detected.
CREATE TABLE IF NOT EXISTS imm_fact (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_key      VARCHAR(64)  NOT NULL,
  edition_id      INT UNSIGNED NOT NULL,
  row_key         BINARY(20)   NOT NULL,               -- sha1(source_key | dimension values | period)
  grain           ENUM('month','quarter','year') NOT NULL,
  ref_period      DATE         NOT NULL,               -- first day of the month/quarter/year
  population      VARCHAR(40)  NOT NULL DEFAULT 'permanent_residents',
  country_basis   ENUM('citizenship','birth','last_permanent_residence') NULL,
  country_raw     VARCHAR(200) NULL,
  country_id      SMALLINT UNSIGNED NULL,
  province        VARCHAR(80)  NULL,
  cma             VARCHAR(160) NULL,
  census_division VARCHAR(160) NULL,
  census_subdiv   VARCHAR(200) NULL,
  cat_main        VARCHAR(160) NULL,
  cat_group       VARCHAR(200) NULL,
  cat_component   VARCHAR(255) NULL,
  occupation      VARCHAR(300) NULL,
  gender          VARCHAR(60)  NULL,
  age_group       VARCHAR(60)  NULL,
  value           INT          NULL,                   -- NULL when the publisher suppressed it
  suppressed      TINYINT(1)   NOT NULL DEFAULT 0,     -- IRCC prints "--" for 1-4
  rounded         TINYINT(1)   NOT NULL DEFAULT 0,     -- IRCC open data rounds to the nearest 5
  UNIQUE KEY uniq_row (source_key, row_key),
  KEY idx_country_period (country_id, ref_period),
  KEY idx_source_period (source_key, ref_period),
  KEY idx_province (province, ref_period),
  KEY idx_cat (cat_main, ref_period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS imm_fact_revisions (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_key       VARCHAR(64)  NOT NULL,
  row_key          BINARY(20)   NOT NULL,
  ref_period       DATE         NOT NULL,
  country_raw      VARCHAR(200) NULL,
  change_kind      ENUM('value_changed','suppression_changed','row_removed','row_restored') NOT NULL,
  old_value        INT NULL,
  new_value        INT NULL,
  old_suppressed   TINYINT(1) NULL,
  new_suppressed   TINYINT(1) NULL,
  prev_edition_id  INT UNSIGNED NULL,
  edition_id       INT UNSIGNED NOT NULL,
  detected_at      DATETIME NOT NULL,
  KEY idx_source_row (source_key, row_key),
  KEY idx_edition (edition_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Rows a newer edition no longer contains are copied here before they leave imm_fact (same rule as
-- grants_removed_records): the full row as last published, and the edition that dropped it.
CREATE TABLE IF NOT EXISTS imm_fact_removed (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  removed_in_edition_id  INT UNSIGNED NOT NULL,
  removed_at             DATETIME     NOT NULL,
  source_key      VARCHAR(64)  NOT NULL,
  edition_id      INT UNSIGNED NOT NULL,                -- the last edition that still had the row
  row_key         BINARY(20)   NOT NULL,
  grain           ENUM('month','quarter','year') NOT NULL,
  ref_period      DATE         NOT NULL,
  population      VARCHAR(40)  NOT NULL,
  country_basis   ENUM('citizenship','birth','last_permanent_residence') NULL,
  country_raw     VARCHAR(200) NULL,
  country_id      SMALLINT UNSIGNED NULL,
  province        VARCHAR(80)  NULL,
  cma             VARCHAR(160) NULL,
  census_division VARCHAR(160) NULL,
  census_subdiv   VARCHAR(200) NULL,
  cat_main        VARCHAR(160) NULL,
  cat_group       VARCHAR(200) NULL,
  cat_component   VARCHAR(255) NULL,
  occupation      VARCHAR(300) NULL,
  gender          VARCHAR(60)  NULL,
  age_group       VARCHAR(60)  NULL,
  value           INT          NULL,
  suppressed      TINYINT(1)   NOT NULL,
  rounded         TINYINT(1)   NOT NULL,
  KEY idx_source_row (source_key, row_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
