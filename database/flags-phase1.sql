-- Automated audit flags, phase 1 (grants and lobbying). Owner decision 2026-11-19: flags are published with a
-- notice that they come from an automated audit and are not yet verified by a person.
--
-- Every flag row states the rule that produced it, the numbers behind it and links back to the source records,
-- so it can be checked independently. Re-running rebuilds the phase-1 rows; review verdicts live in
-- audit_flag_reviews (keyed by flag_key, which is stable across runs) and are never touched here.
--
-- Rules
--   G1 program_outlier      one agreement worth >= 100x the median agreement of its program (same department
--                           and program, >= 20 current agreements) and >= $25M.
--   G2 riding_concentration one non-government recipient received >= 40% of all current grant and contribution
--                           value in a riding, where the riding total is >= $20M.
--   G3 department_concentration one non-government recipient received >= 25% of a department's current total,
--                           where the department total is >= $50M.
--   G4 large_individual     an agreement with an individual (recipient_type P) worth >= $500,000, excluding the
--                           research granting councils (CIHR, NSERC, SSHRC), which record grants under the lead
--                           researcher's name.
--   L1 lobbied_then_voted   a lobbyist reported communicating with an MP about a named bill (matched with high
--                           confidence), and the MP then voted on that bill within 365 days. Whether the vote
--                           favoured the lobbyist is NOT determined: the row shows the vote and whether it broke
--                           with the MP's party, for review.
-- Only current versions of agreements are used (master_current). Government recipients (type G, or a name that
-- starts with Government/Ministry/Province/Gouvernement/Ministere) are excluded from G1-G3, and "batch report"
-- rows (many small payments reported together under one line) from every G rule: they are not real recipients.

CREATE TABLE IF NOT EXISTS audit_flags (
  flag_key      varchar(120) NOT NULL PRIMARY KEY COMMENT 'rule + subject ids; stable across runs',
  rule          varchar(40)  NOT NULL,
  subject_type  enum('agreement','recipient','mp') NOT NULL,
  subject       varchar(400) NOT NULL,
  mp_person_id  int unsigned DEFAULT NULL,
  riding_number varchar(6)   DEFAULT NULL,
  amount        decimal(17,2) DEFAULT NULL,
  measure       varchar(400) NOT NULL COMMENT 'the numbers that triggered the rule',
  details       text,
  source_url    varchar(600) DEFAULT NULL,
  computed_at   datetime     NOT NULL,
  KEY idx_rule (rule), KEY idx_mp (mp_person_id), KEY idx_riding (riding_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS audit_flag_reviews (
  flag_key    varchar(120) NOT NULL PRIMARY KEY,
  verdict     enum('confirmed','false_positive','needs_more_info') NOT NULL,
  note        text,
  reviewed_by varchar(120) NOT NULL,
  reviewed_at datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELETE FROM audit_flags WHERE rule IN ('G1','G2','G3','G4','L1');
SET @now = UTC_TIMESTAMP();

-- Agreements the G rules may flag: current and not batch reports.
DROP TEMPORARY TABLE IF EXISTS t_mc;
CREATE TEMPORARY TABLE t_mc AS
SELECT m.*, (IFNULL(m.recipient_type, '') = 'G'
             OR m.recipient_legal_name REGEXP '^[[:space:]]*(the )?(government|ministry|minister|province|gouvernement|minist[eè]re)') is_gov
  FROM master_current m
 WHERE m.recipient_legal_name NOT REGEXP 'batch[[:space:]]*report|rapport[[:space:]]+en[[:space:]]+lots';

-- ── G1 program outliers ─────────────────────────────────────────────────────
DROP TEMPORARY TABLE IF EXISTS t_prog;
CREATE TEMPORARY TABLE t_prog AS
SELECT owner_org_en, prog_name_en, agreement_value v,
       ROW_NUMBER() OVER (PARTITION BY owner_org_en, prog_name_en ORDER BY agreement_value) rn,
       COUNT(*)     OVER (PARTITION BY owner_org_en, prog_name_en) n
  FROM t_mc WHERE agreement_value > 0 AND prog_name_en IS NOT NULL AND prog_name_en <> '';
DROP TEMPORARY TABLE IF EXISTS t_median;
CREATE TEMPORARY TABLE t_median (KEY (owner_org_en(100), prog_name_en(150))) AS
SELECT owner_org_en, prog_name_en, MAX(n) n, AVG(v) median
  FROM t_prog WHERE rn IN (FLOOR((n + 1) / 2), CEIL((n + 1) / 2)) GROUP BY owner_org_en, prog_name_en;

INSERT INTO audit_flags
SELECT CONCAT('G1:', m.id), 'G1', 'agreement',
       CONCAT_WS(' — ', m.recipient_legal_name, m.agreement_title_en), NULL, LPAD(m.federal_riding_number, 5, '0'),
       m.agreement_value,
       CONCAT('$', FORMAT(m.agreement_value, 0), ' = ', ROUND(m.agreement_value / t.median, 1), 'x the median ($',
              FORMAT(t.median, 0), ') of ', t.n, ' agreements in this program'),
       CONCAT(m.owner_org_en, ' · ', m.prog_name_en, ' · ref ', m.ref_number, ' · ', IFNULL(m.agreement_start_date, '')),
       (SELECT u.url FROM agreement_urls u WHERE u.record_id = m.id AND u.url_type = 'detail' LIMIT 1), @now
  FROM t_mc m JOIN t_median t USING (owner_org_en, prog_name_en)
 WHERE NOT m.is_gov AND t.n >= 20 AND m.agreement_value >= 25000000 AND m.agreement_value >= 100 * t.median;

-- ── G2 riding concentration ─────────────────────────────────────────────────
DROP TEMPORARY TABLE IF EXISTS t_riding;
CREATE TEMPORARY TABLE t_riding AS
SELECT LPAD(federal_riding_number, 5, '0') rn, MAX(federal_riding_name_en) riding, SUM(agreement_value) total
  FROM t_mc WHERE federal_riding_number IS NOT NULL GROUP BY 1 HAVING total >= 20000000;
INSERT INTO audit_flags
SELECT CONCAT('G2:', r.rn, ':', LEFT(UPPER(TRIM(m.recipient_legal_name)), 90)), 'G2', 'recipient',
       MAX(m.recipient_legal_name), NULL, r.rn, SUM(m.agreement_value),
       CONCAT(ROUND(100 * SUM(m.agreement_value) / MAX(r.total), 1), '% of $', FORMAT(MAX(r.total), 0),
              ' in ', MAX(r.riding), ' (', COUNT(*), ' agreements)'),
       CONCAT('Departments: ', LEFT(GROUP_CONCAT(DISTINCT m.owner_org_en SEPARATOR '; '), 500)),
       CONCAT('https://search.open.canada.ca/grants/?search_text=%22',
              REPLACE(TRIM(MAX(m.recipient_legal_name)), ' ', '+'), '%22'), @now
  FROM t_mc m JOIN t_riding r ON r.rn = LPAD(m.federal_riding_number, 5, '0')
 WHERE NOT m.is_gov AND m.recipient_legal_name IS NOT NULL
 GROUP BY r.rn, UPPER(TRIM(m.recipient_legal_name))
HAVING SUM(m.agreement_value) >= 0.40 * MAX(r.total);

-- ── G3 department concentration ─────────────────────────────────────────────
DROP TEMPORARY TABLE IF EXISTS t_dept;
CREATE TEMPORARY TABLE t_dept AS
SELECT owner_org_en, SUM(agreement_value) total FROM t_mc GROUP BY 1 HAVING total >= 50000000;
INSERT INTO audit_flags
SELECT CONCAT('G3:', LEFT(d.owner_org_en, 40), ':', LEFT(UPPER(TRIM(m.recipient_legal_name)), 70)), 'G3', 'recipient',
       MAX(m.recipient_legal_name), NULL, NULL, SUM(m.agreement_value),
       CONCAT(ROUND(100 * SUM(m.agreement_value) / MAX(d.total), 1), '% of all $', FORMAT(MAX(d.total), 0),
              ' from ', d.owner_org_en, ' (', COUNT(*), ' agreements)'),
       CONCAT('Recipient type ', IFNULL(MAX(m.recipient_type), 'unknown'), '; years ',
              MIN(YEAR(m.agreement_start_date)), '–', MAX(YEAR(m.agreement_start_date))),
       CONCAT('https://search.open.canada.ca/grants/?search_text=%22',
              REPLACE(TRIM(MAX(m.recipient_legal_name)), ' ', '+'), '%22'), @now
  FROM t_mc m JOIN t_dept d USING (owner_org_en)
 WHERE NOT m.is_gov AND IFNULL(m.recipient_type, '') <> 'I' AND m.recipient_legal_name IS NOT NULL
 GROUP BY d.owner_org_en, UPPER(TRIM(m.recipient_legal_name))
HAVING SUM(m.agreement_value) >= 0.25 * MAX(d.total);

-- ── G4 large payments to individuals ────────────────────────────────────────
INSERT INTO audit_flags
SELECT CONCAT('G4:', m.id), 'G4', 'agreement', CONCAT_WS(' — ', m.recipient_legal_name, m.agreement_title_en), NULL,
       LPAD(m.federal_riding_number, 5, '0'), m.agreement_value,
       CONCAT('$', FORMAT(m.agreement_value, 0), ' to an individual'),
       CONCAT(m.owner_org_en, ' · ', IFNULL(m.prog_name_en, ''), ' · ref ', m.ref_number, ' · ', IFNULL(m.agreement_start_date, '')),
       (SELECT u.url FROM agreement_urls u WHERE u.record_id = m.id AND u.url_type = 'detail' LIMIT 1), @now
  FROM t_mc m WHERE m.recipient_type = 'P' AND m.agreement_value >= 500000
   AND m.owner_org_en NOT IN ('Canadian Institutes of Health Research',
       'Natural Sciences and Engineering Research Council of Canada', 'Social Sciences and Humanities Research Council of Canada');

-- ── L1 lobbied about a bill, then voted on it ───────────────────────────────
DROP TEMPORARY TABLE IF EXISTS t_lob_bill;
CREATE TEMPORARY TABLE t_lob_bill AS
SELECT DISTINCT CAST(d.comlog_id AS CHAR(32)) COLLATE utf8mb4_0900_ai_ci comlog_id,
       CAST(UPPER(REGEXP_SUBSTR(d.description, '[CS]-[0-9]+')) AS CHAR(10)) COLLATE utf8mb4_0900_ai_ci bill_no,
       STR_TO_DATE(LEFT(p.comm_date, 10), '%Y-%m-%d') comm_date, p.en_client_org_corp_nm_an client,
       LEFT(d.description, 300) subject
  FROM lob_communication_subjectmatterdetails d
  JOIN lob_communication_primary p ON p.comlog_id = d.comlog_id
 WHERE d.description REGEXP '(Bill|projet de loi) ?[CS]-[0-9]+';
-- The bill in force at the communication date: same number, introduced on or before it, most recent.
DROP TEMPORARY TABLE IF EXISTS t_lob_bill2;
CREATE TEMPORARY TABLE t_lob_bill2 AS
SELECT l.*, (SELECT b.id FROM op_bills_bill b WHERE b.number = l.bill_no COLLATE utf8mb4_unicode_ci AND b.introduced <= l.comm_date
              ORDER BY b.introduced DESC LIMIT 1) bill_id
  FROM t_lob_bill l;

INSERT INTO audit_flags
SELECT CONCAT('L1:', x.comlog_id, ':', x.politician_id, ':', x.vq_id), 'L1', 'mp',
       CONCAT(x.mp_name, ' — ', x.client, ' — Bill ', x.bill_no), x.mp_person_id, NULL, NULL,
       CONCAT('Lobbied ', x.comm_date, '; voted ', x.vote_word, ' on ', x.vote_date, ' (', DATEDIFF(x.vote_date, x.comm_date),
              ' days later)', IF(x.dissent, ' AGAINST their party', ' with their party')),
       CONCAT('Client: ', x.client, '. Subject: ', x.subject, '. Vote: ', LEFT(x.vote_desc, 250)),
       CONCAT('https://lobbycanada.gc.ca/app/secure/ocl/lrs/do/cmmLgPblcVw?comlogId=', x.comlog_id), @now
  FROM (
    SELECT l.comlog_id, l.bill_no, l.comm_date, l.client, l.subject, mp.id mp_person_id,
           CONCAT(mp.first_name, ' ', mp.last_name) mp_name, mv.politician_id, mv.dissent,
           CASE mv.vote WHEN 'Y' THEN 'Yea' WHEN 'N' THEN 'Nay' ELSE mv.vote END vote_word,
           q.id vq_id, q.date vote_date, q.description_en vote_desc
      FROM t_lob_bill2 l
      JOIN lob_dpoh_mp_match mm ON mm.comlog_id = l.comlog_id AND mm.confidence = 'high'
      JOIN mp_persons mp ON mp.id = mm.mp_person_id
      JOIN op_bills_votequestion q ON q.bill_id = l.bill_id AND q.date >= l.comm_date
                                  AND q.date <= l.comm_date + INTERVAL 365 DAY
      JOIN op_bills_membervote mv ON mv.votequestion_id = q.id AND mv.politician_id = mp.op_politician_id
                                 AND mv.vote IN ('Y', 'N')
     WHERE l.bill_id IS NOT NULL
  ) x
ON DUPLICATE KEY UPDATE computed_at = VALUES(computed_at);

SELECT rule, COUNT(*) flags, ROUND(SUM(amount)) amount FROM audit_flags GROUP BY rule;
