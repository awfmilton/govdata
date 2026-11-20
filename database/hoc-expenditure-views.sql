-- Views over the House of Commons expenditure tables loaded by load-hoc-expenditures.py.
--
-- 1. hoc_reporting_periods / hoc_member_expenditures: the names and columns the site's MP finance warmer
--    (publictech-core/inc/class-mp-finance-warmer.php) reads. Two of the warmer's assumptions are kept:
--      - period ids run newest-first (MIN(period_id) = newest), so period_id here = 1000 - hoc_exp_periods id;
--      - mp_id is the old mps.mp_id ("MP017") where one exists, else 'OP<op_politician_id>', else 'PP<mp_persons.id>'.
-- 2. mp_expenditure_transactions: every published line (contract, hospitality, travel claim) in one shape,
--    for the MP finance pages and flags.
-- 3. mp_expenditure_supplier_totals: per MP, per supplier, contract + hospitality totals (aggregate).

CREATE OR REPLACE SQL SECURITY INVOKER VIEW hoc_reporting_periods AS
SELECT 1000 - p.period_id AS period_id, p.fiscal_year, CONCAT('Q', p.quarter) AS quarter,
       ELT(p.quarter, 'first-quarter', 'second-quarter', 'third-quarter', 'fourth-quarter') AS quarter_name,
       CONCAT('Members expenditures report, ', p.start_date, ' to ', p.end_date) AS reporting_period_desc,
       p.start_date, p.end_date
  FROM hoc_exp_periods p;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW hoc_member_expenditures AS
SELECT s.id AS expenditure_id,
       COALESCE((SELECT m.mp_id FROM mps m WHERE m.mp_persons_id = s.mp_person_id AND m.mp_id LIKE 'MP%' LIMIT 1),
                CONCAT('OP', pp.op_politician_id), CONCAT('PP', s.mp_person_id)) AS mp_id,
       1000 - s.period_id AS period_id, s.salaries, s.travel, s.hospitality, s.contracts,
       s.mp_person_id, pp.op_politician_id, s.member_name, s.constituency, s.caucus
  FROM hoc_exp_summary s
  JOIN mp_persons pp ON pp.id = s.mp_person_id;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW mp_expenditure_transactions AS
SELECT 'contract' AS category, c.id AS line_id, c.period_id, c.mp_person_id, c.expense_date AS line_date,
       c.supplier, c.description AS detail, NULL AS location, c.total AS amount, c.source_file
  FROM hoc_exp_contracts c
UNION ALL
SELECT 'hospitality', h.id, h.period_id, h.mp_person_id, h.event_date, h.supplier,
       CONCAT_WS(' · ', h.event_type, h.purpose, CONCAT(h.attendees, ' attendees')), h.location, h.total, h.source_file
  FROM hoc_exp_hospitality h
UNION ALL
SELECT 'travel', t.id, t.period_id, t.mp_person_id, t.start_date, NULL,
       CONCAT('Claim ', t.claim, ': transportation ', IFNULL(t.transportation, 0), ', accommodations ',
              IFNULL(t.accommodations, 0), ', meals ', IFNULL(t.meals, 0)),
       (SELECT GROUP_CONCAT(DISTINCT CONCAT(l.departure, ' → ', l.destination) SEPARATOR '; ')
          FROM hoc_exp_travel_legs l WHERE l.member_uuid = t.member_uuid AND l.period_id = t.period_id AND l.claim = t.claim),
       t.total, t.source_file
  FROM hoc_exp_travel_claims t;

DROP TABLE IF EXISTS mp_expenditure_supplier_totals;
CREATE TABLE mp_expenditure_supplier_totals (
  mp_person_id int unsigned NOT NULL, supplier varchar(400) NOT NULL, category varchar(20) NOT NULL,
  lines_n int NOT NULL, total decimal(14,2) NOT NULL, first_date date, last_date date,
  KEY (mp_person_id), KEY (supplier(80))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
INSERT INTO mp_expenditure_supplier_totals
SELECT mp_person_id, TRIM(supplier), 'contract', COUNT(*), SUM(total), MIN(expense_date), MAX(expense_date)
  FROM hoc_exp_contracts WHERE mp_person_id IS NOT NULL AND supplier <> '' GROUP BY mp_person_id, TRIM(supplier)
UNION ALL
SELECT mp_person_id, TRIM(supplier), 'hospitality', COUNT(*), SUM(total), MIN(event_date), MAX(event_date)
  FROM hoc_exp_hospitality WHERE mp_person_id IS NOT NULL AND supplier <> '' GROUP BY mp_person_id, TRIM(supplier);

