-- Country pages: ISO codes, Canada's aid by recipient, where members of Parliament were born, laws and bills that
-- name a country, and the precomputed report payloads the site reads. Run as root on govdata-serving after
-- schema.sql and schema_parl.sql; then grants.sql. Every table is loaded by run.py (see README "Country pages").

-- One row per imm_country that is a country. Built from country_iso.csv (reviewed); historical states keep NULL codes.
CREATE TABLE IF NOT EXISTS imm_country_iso (
  country_id    SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
  iso2          CHAR(2)      NULL,
  iso3          CHAR(3)      NULL,
  oecd_code     VARCHAR(8)   NULL,
  display_name  VARCHAR(200) NOT NULL,
  slug          VARCHAR(140) NOT NULL,
  notes         VARCHAR(400) NULL,
  UNIQUE KEY uniq_slug (slug),
  UNIQUE KEY uniq_iso2 (iso2),
  KEY idx_oecd (oecd_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- OECD DAC2a: Canada's official development assistance by recipient, 1960 onward, millions of US dollars.
-- price_base V = current prices, Q = constant prices (base year in base_year). country_id is NULL for regions,
-- income groups and multilateral agencies (recipient_code kept).
CREATE TABLE IF NOT EXISTS cty_oda (
  recipient_code  VARCHAR(12)  NOT NULL,
  measure         VARCHAR(8)   NOT NULL,
  price_base      CHAR(1)      NOT NULL,
  year            SMALLINT     NOT NULL,
  recipient_label VARCHAR(200) NOT NULL,
  measure_label   VARCHAR(200) NOT NULL,
  country_id      SMALLINT UNSIGNED NULL,
  usd_millions    DECIMAL(18,6) NULL,
  base_year       SMALLINT     NULL,
  fetched_at      DATETIME     NOT NULL,
  PRIMARY KEY (recipient_code, measure, price_base, year),
  KEY idx_country (country_id, measure, price_base, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Members of the House of Commons and senators since 1867 with their place of birth (Wikidata, CC0; its
-- statements cite the Library of Parliament). country_id is the CURRENT country of the birthplace.
CREATE TABLE IF NOT EXISTS parl_person_birth (
  wikidata_qid        VARCHAR(16)  NOT NULL PRIMARY KEY,
  name                VARCHAR(200) NOT NULL,
  lop_person_id       VARCHAR(16)  NULL,             -- Library of Parliament (Parlinfo) person ID
  birth_date          DATE         NULL,
  birth_place         VARCHAR(200) NULL,
  birth_place_qid     VARCHAR(16)  NULL,
  birth_country_label VARCHAR(200) NULL,
  birth_iso2          CHAR(2)      NULL,
  country_id          SMALLINT UNSIGNED NULL,
  was_mp              TINYINT(1)   NOT NULL DEFAULT 0,
  was_senator         TINYINT(1)   NOT NULL DEFAULT 0,
  first_mp_start      DATE         NULL,
  last_mp_end         DATE         NULL,
  first_senate_start  DATE         NULL,
  last_senate_end     DATE         NULL,
  terms_json          JSON         NULL,             -- [[role, start, end], ...]
  vote_person_id      INT UNSIGNED NULL,             -- parl_division_votes.person_id (House PersonId), name-matched
  vote_match          VARCHAR(40)  NULL,             -- how vote_person_id was matched
  fetched_at          DATETIME     NOT NULL,
  KEY idx_country (country_id),
  KEY idx_vote_person (vote_person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Consolidated federal laws (Justice Laws) and House bills (LEGISinfo, 1994 onward) whose title names a country.
CREATE TABLE IF NOT EXISTS cty_law_tags (
  ref_kind      ENUM('act','regulation','bill') NOT NULL,
  ref_id        VARCHAR(64)  NOT NULL,             -- Justice Laws UniqueId, or LEGISinfo bill id
  country_id    SMALLINT UNSIGNED NOT NULL,
  title         VARCHAR(1000) NOT NULL,
  matched_term  VARCHAR(120) NOT NULL,
  topic         VARCHAR(40)  NULL,                 -- immigration | social security | military | sanctions | trade | tax | other
  official_number VARCHAR(120) NULL,
  current_to    DATE         NULL,
  url           VARCHAR(400) NULL,
  review_status ENUM('auto','confirmed','rejected') NOT NULL DEFAULT 'auto',
  tagged_at     DATETIME     NOT NULL,
  PRIMARY KEY (ref_kind, ref_id, country_id),
  KEY idx_country (country_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Precomputed page payloads: 'index', 'analysis', and 'country:<slug>'. The site reads only this table.
CREATE TABLE IF NOT EXISTS cty_report (
  report_key    VARCHAR(160) NOT NULL PRIMARY KEY,
  country_id    SMALLINT UNSIGNED NULL,
  payload       LONGTEXT     NOT NULL,
  generated_at  DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- OECD DAC1: Canada as a donor, every measure (total, bilateral, multilateral by channel, in-donor refugee costs,
-- administrative costs, ODA as % of GNI, GNI), 1960 onward. unit XDC = Canadian dollars (current prices).
CREATE TABLE IF NOT EXISTS cty_dac1 (
  measure        VARCHAR(12)  NOT NULL,
  flow_type      VARCHAR(8)   NOT NULL,
  unit           VARCHAR(8)   NOT NULL,
  price_base     CHAR(1)      NOT NULL,
  year           SMALLINT     NOT NULL,
  measure_label  VARCHAR(200) NOT NULL,
  flow_label     VARCHAR(80)  NOT NULL,
  value          DECIMAL(20,6) NULL,          -- millions for money; percent for ratio measures
  base_year      SMALLINT     NULL,
  fetched_at     DATETIME     NOT NULL,
  PRIMARY KEY (measure, flow_type, unit, price_base, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Federal acts, regulations and House bills about foreign aid, debt relief and the international financial
-- institutions (title match; country-specific debt remission orders are also in cty_law_tags).
CREATE TABLE IF NOT EXISTS aid_law_tags (
  ref_kind      ENUM('act','regulation','bill') NOT NULL,
  ref_id        VARCHAR(64)  NOT NULL,
  title         VARCHAR(1000) NOT NULL,
  matched_term  VARCHAR(120) NOT NULL,
  official_number VARCHAR(120) NULL,
  url           VARCHAR(400) NULL,
  tagged_at     DATETIME     NOT NULL,
  PRIMARY KEY (ref_kind, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
