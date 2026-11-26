-- House of Commons votes, bills and federal law amendments, from the official publishers, for linking
-- immigration figures to the legislation and votes behind them. Loaded by run.py parl / laws.
--
-- People: parl_division_votes.person_id is the House of Commons PersonId. It joins to Open Parliament politicians
-- through op_core_politicianinfo (schema = 'parl_mp_id') and to the site's MP posts (post meta pt_hoc_person_id).

CREATE TABLE IF NOT EXISTS parl_bills (
  legisinfo_id        INT UNSIGNED NOT NULL PRIMARY KEY,
  parliament          TINYINT UNSIGNED NOT NULL,
  session             TINYINT UNSIGNED NOT NULL,
  number_code         VARCHAR(16)  NOT NULL,             -- C-31, S-7
  is_government       TINYINT(1)   NOT NULL DEFAULT 0,
  long_title_en       TEXT         NULL,
  short_title_en      VARCHAR(600) NULL,
  status_en           VARCHAR(300) NULL,
  sponsor_person_id   INT UNSIGNED NULL,
  sponsor_name        VARCHAR(200) NULL,
  sponsor_title_en    VARCHAR(400) NULL,                 -- e.g. Minister of Citizenship and Immigration
  originating_chamber VARCHAR(40)  NULL,
  house_1r            DATETIME NULL, house_2r DATETIME NULL, house_3r DATETIME NULL,
  senate_1r           DATETIME NULL, senate_2r DATETIME NULL, senate_3r DATETIME NULL,
  royal_assent        DATETIME NULL,
  statute_year        SMALLINT UNSIGNED NULL,            -- with statute_chapter: the Act this bill became
  statute_chapter     SMALLINT UNSIGNED NULL,
  latest_event_at     DATETIME NULL,
  summary_en          MEDIUMTEXT NULL,
  raw_json            JSON NOT NULL,                     -- the full LEGISinfo record, for every detail not modelled
  fetched_at          DATETIME NOT NULL,
  KEY idx_session_number (parliament, session, number_code),
  KEY idx_statute (statute_year, statute_chapter)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS parl_divisions (
  parliament    TINYINT UNSIGNED NOT NULL,
  session       TINYINT UNSIGNED NOT NULL,
  division_no   SMALLINT UNSIGNED NOT NULL,
  held_at       DATETIME NOT NULL,
  subject       TEXT NOT NULL,
  result        VARCHAR(80)  NOT NULL,
  yeas          SMALLINT UNSIGNED NOT NULL,
  nays          SMALLINT UNSIGNED NOT NULL,
  paired        SMALLINT UNSIGNED NOT NULL,
  doc_type      VARCHAR(120) NULL,
  bill_number   VARCHAR(16)  NULL,                     -- set when the division was on a bill
  legisinfo_id  INT UNSIGNED NULL,                     -- resolved from (parliament, session, bill_number)
  ballots       SMALLINT UNSIGNED NULL,                -- per-member rows loaded (NULL = not yet fetched)
  fetched_at    DATETIME NOT NULL,
  PRIMARY KEY (parliament, session, division_no),
  KEY idx_bill (legisinfo_id),
  KEY idx_held (held_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS parl_division_votes (
  parliament    TINYINT UNSIGNED NOT NULL,
  session       TINYINT UNSIGNED NOT NULL,
  division_no   SMALLINT UNSIGNED NOT NULL,
  person_id     INT UNSIGNED NOT NULL,
  vote          ENUM('Yea','Nay','Paired') NOT NULL,
  caucus        VARCHAR(80)  NULL,
  constituency  VARCHAR(200) NULL,
  province      VARCHAR(60)  NULL,
  first_name    VARCHAR(100) NULL,
  last_name     VARCHAR(100) NULL,
  PRIMARY KEY (parliament, session, division_no, person_id),
  KEY idx_person (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Consolidated federal Acts and regulations (Justice Laws catalogue) relevant to immigration.
CREATE TABLE IF NOT EXISTS law_instruments (
  unique_id        VARCHAR(64)  NOT NULL PRIMARY KEY,   -- I-2.5, SOR-2002-227
  kind             ENUM('act','regulation') NOT NULL,
  title            VARCHAR(800) NOT NULL,
  official_number  VARCHAR(120) NULL,
  enabling_act     VARCHAR(64)  NULL,                   -- for a regulation: the Act it is made under
  current_to       DATE NULL,
  last_amended     DATE NULL,
  xml_url          VARCHAR(400) NOT NULL,
  xml_sha256       CHAR(64)     NULL,
  fetched_at       DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Every amendment citation in an instrument's historical notes, one row per (provision, amending Act section).
CREATE TABLE IF NOT EXISTS law_amendments (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  unique_id        VARCHAR(64)  NOT NULL,
  provision        VARCHAR(120) NULL,                   -- section label the note belongs to
  citation         VARCHAR(300) NOT NULL,               -- one citation as printed, e.g. "2012, c. 17, s. 2"
  statute_year     SMALLINT UNSIGNED NULL,
  statute_chapter  SMALLINT UNSIGNED NULL,
  sections         VARCHAR(200) NULL,
  inforce_start    DATE NULL,                           -- lims:inforce-start-date of the provision, when given
  KEY idx_instrument (unique_id),
  KEY idx_statute (statute_year, statute_chapter)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Which bills are about immigration, and why. 'auto' rows come from rules; a person can confirm or reject.
CREATE TABLE IF NOT EXISTS imm_bill_tags (
  legisinfo_id   INT UNSIGNED NOT NULL PRIMARY KEY,
  reasons        VARCHAR(600) NOT NULL,                 -- e.g. amends:I-2.5; sponsor:Minister of Citizenship and Immigration
  review_status  ENUM('auto','confirmed','rejected') NOT NULL DEFAULT 'auto',
  reviewed_at    DATETIME NULL,
  tagged_at      DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Immigration bills with every House division on them and the Act each became.
CREATE OR REPLACE SQL SECURITY INVOKER VIEW imm_bill_divisions AS
SELECT b.legisinfo_id, b.parliament, b.session, b.number_code, b.short_title_en, b.long_title_en, b.sponsor_title_en,
       b.royal_assent, b.statute_year, b.statute_chapter, t.reasons, t.review_status,
       d.division_no, d.held_at, d.subject, d.result, d.yeas, d.nays, d.paired
FROM parl_bills b
JOIN imm_bill_tags t ON t.legisinfo_id = b.legisinfo_id AND t.review_status <> 'rejected'
LEFT JOIN parl_divisions d ON d.legisinfo_id = b.legisinfo_id;
