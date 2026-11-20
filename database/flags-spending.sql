-- Automated audit flags: MP office spending (House of Commons Members' Expenditures Reports, loaded by
-- load-hoc-expenditures.py). Same table and notice policy as flags-phase1.sql; rows are rebuilt on each run,
-- verdicts in audit_flag_reviews are kept.
--
-- Rules (fiscal years run April to March; comparisons are among MPs in the same fiscal year)
--   S1 hospitality_outlier  an MP's hospitality for a fiscal year is >= 3x the median MP's that year, and >= $15,000.
--   S2 costly_hospitality   one hospitality event costing >= $150 per attendee, and >= $750 in total.
--   S3 travel_outlier       an MP's travel for a fiscal year is >= 2.5x the median of MPs from the same province
--                           that year, and >= $100,000 (distance from Ottawa drives travel cost, hence province).
--   S4 large_purchase       one contract line >= $25,000 that is not office rent or a lease.
--   S5 supplier_concentration one supplier was paid >= $100,000 by one MP's office, excluding rent/leases,
--                           telecom, postage and the House's own services.

DELETE FROM audit_flags WHERE rule IN ('S1','S2','S3','S4','S5');
SET @now = UTC_TIMESTAMP();

-- MP province: the riding of their latest mandate.
DROP TEMPORARY TABLE IF EXISTS t_mp_prov;
CREATE TEMPORARY TABLE t_mp_prov (PRIMARY KEY (mp_person_id)) AS
SELECT p.id mp_person_id,
       (SELECT r.province FROM op_core_electedmember e JOIN op_core_riding r ON r.id = e.riding_id
         WHERE e.politician_id = p.op_politician_id ORDER BY e.start_date DESC LIMIT 1) province
  FROM mp_persons p;

-- Fiscal-year totals per MP.
DROP TEMPORARY TABLE IF EXISTS t_fy;
CREATE TEMPORARY TABLE t_fy AS
SELECT s.mp_person_id, p.fiscal_year, MAX(s.member_name) member_name, MAX(s.constituency) constituency,
       SUM(s.hospitality) hosp, SUM(s.travel) travel, mp.province
  FROM hoc_exp_summary s JOIN hoc_exp_periods p USING (period_id) LEFT JOIN t_mp_prov mp USING (mp_person_id)
 WHERE s.mp_person_id IS NOT NULL GROUP BY s.mp_person_id, p.fiscal_year;
-- Only fiscal years with all four quarters published are compared.
DROP TEMPORARY TABLE IF EXISTS t_fy_full;
CREATE TEMPORARY TABLE t_fy_full AS SELECT fiscal_year FROM hoc_exp_periods GROUP BY fiscal_year HAVING COUNT(*) = 4;

-- Medians (window functions over a copy, since a temporary table can be opened only once per query).
DROP TEMPORARY TABLE IF EXISTS t_fy2;
CREATE TEMPORARY TABLE t_fy2 AS SELECT * FROM t_fy WHERE fiscal_year IN (SELECT fiscal_year FROM t_fy_full);
DROP TEMPORARY TABLE IF EXISTS t_med_h;
CREATE TEMPORARY TABLE t_med_h AS
SELECT fiscal_year, AVG(hosp) med FROM (
  SELECT fiscal_year, hosp, ROW_NUMBER() OVER (PARTITION BY fiscal_year ORDER BY hosp) rn, COUNT(*) OVER (PARTITION BY fiscal_year) n FROM t_fy2
) x WHERE rn IN (FLOOR((n + 1) / 2), CEIL((n + 1) / 2)) GROUP BY fiscal_year;
DROP TEMPORARY TABLE IF EXISTS t_fy3;
CREATE TEMPORARY TABLE t_fy3 AS SELECT * FROM t_fy2;
DROP TEMPORARY TABLE IF EXISTS t_med_t;
CREATE TEMPORARY TABLE t_med_t AS
SELECT fiscal_year, province, AVG(travel) med, MAX(n) n FROM (
  SELECT fiscal_year, province, travel, ROW_NUMBER() OVER (PARTITION BY fiscal_year, province ORDER BY travel) rn,
         COUNT(*) OVER (PARTITION BY fiscal_year, province) n FROM t_fy3 WHERE province IS NOT NULL
) x WHERE rn IN (FLOOR((n + 1) / 2), CEIL((n + 1) / 2)) GROUP BY fiscal_year, province;

-- ── S1 hospitality outliers ─────────────────────────────────────────────────
INSERT INTO audit_flags
SELECT CONCAT('S1:', f.mp_person_id, ':', f.fiscal_year), 'S1', 'mp',
       CONCAT(f.member_name, ' — hospitality ', f.fiscal_year), f.mp_person_id, NULL, f.hosp,
       CONCAT('$', FORMAT(f.hosp, 0), ' in hospitality in ', f.fiscal_year, ' = ', ROUND(f.hosp / m.med, 1),
              'x the median MP ($', FORMAT(m.med, 0), ')'),
       CONCAT(f.constituency, '. Top events: ', IFNULL((SELECT GROUP_CONCAT(CONCAT(h.event_date, ' ', h.location, ' $', FORMAT(h.total, 0), ' (', h.attendees, ' people)')
                ORDER BY h.total DESC SEPARATOR '; ') FROM (SELECT * FROM hoc_exp_hospitality h0 WHERE h0.mp_person_id = f.mp_person_id
                AND h0.period_id IN (SELECT period_id FROM hoc_exp_periods WHERE fiscal_year = f.fiscal_year) ORDER BY h0.total DESC LIMIT 3) h), '')),
       'https://www.ourcommons.ca/ProactiveDisclosure/en/members', @now
  FROM t_fy2 f JOIN t_med_h m USING (fiscal_year)
 WHERE f.hosp >= 15000 AND f.hosp >= 3 * m.med;

-- ── S2 costly hospitality events ────────────────────────────────────────────
INSERT INTO audit_flags
SELECT CONCAT('S2:', h.id), 'S2', 'mp', CONCAT(IFNULL(CONCAT(p.first_name, ' ', p.last_name), x.member_name), ' — ',
       IFNULL(NULLIF(h.supplier, ''), 'event'), ', ', h.location, ' ', h.event_date), h.mp_person_id, NULL, h.total,
       CONCAT('$', FORMAT(h.total, 2), ' for ', h.attendees, ' attendees = $', FORMAT(h.total / h.attendees, 2), ' per person'),
       CONCAT_WS('. ', NULLIF(h.event_type, ''), h.purpose, CONCAT('Claim ', NULLIF(h.claim, ''))),
       CONCAT('https://www.ourcommons.ca/proactivedisclosure/en/members/hospitality/', SUBSTRING_INDEX(SUBSTRING_INDEX(h.source_file, '/', 1), '-Q', 1),
              '/', RIGHT(SUBSTRING_INDEX(h.source_file, '/', 1), 1), '/', h.member_uuid), @now
  FROM hoc_exp_hospitality h
  LEFT JOIN mp_persons p ON p.id = h.mp_person_id
  LEFT JOIN hoc_exp_members x ON x.member_uuid = h.member_uuid AND x.period_id = h.period_id
 WHERE h.attendees > 0 AND h.total >= 750 AND h.total / h.attendees >= 150;

-- ── S3 travel outliers (within province) ────────────────────────────────────
INSERT INTO audit_flags
SELECT CONCAT('S3:', f.mp_person_id, ':', f.fiscal_year), 'S3', 'mp',
       CONCAT(f.member_name, ' — travel ', f.fiscal_year), f.mp_person_id, NULL, f.travel,
       CONCAT('$', FORMAT(f.travel, 0), ' in travel in ', f.fiscal_year, ' = ', ROUND(f.travel / m.med, 1),
              'x the median MP from ', f.province, ' ($', FORMAT(m.med, 0), ', ', m.n, ' MPs)'),
       f.constituency, 'https://www.ourcommons.ca/ProactiveDisclosure/en/members', @now
  FROM t_fy3 f JOIN t_med_t m USING (fiscal_year, province)
 WHERE m.n >= 3 AND f.travel >= 100000 AND f.travel >= 2.5 * m.med;

-- ── S4 large single purchases ───────────────────────────────────────────────
INSERT INTO audit_flags
SELECT CONCAT('S4:', c.id), 'S4', 'mp', CONCAT(IFNULL(CONCAT(p.first_name, ' ', p.last_name), x.member_name), ' — ', c.supplier),
       c.mp_person_id, NULL, c.total,
       CONCAT('$', FORMAT(c.total, 2), ' on one line: ', c.description), CONCAT('Date ', c.expense_date, '. Supplier: ', c.supplier),
       CONCAT('https://www.ourcommons.ca/proactivedisclosure/en/members/contract/', SUBSTRING_INDEX(SUBSTRING_INDEX(c.source_file, '/', 1), '-Q', 1),
              '/', RIGHT(SUBSTRING_INDEX(c.source_file, '/', 1), 1), '/', c.member_uuid), @now
  FROM hoc_exp_contracts c
  LEFT JOIN mp_persons p ON p.id = c.mp_person_id
  LEFT JOIN hoc_exp_members x ON x.member_uuid = c.member_uuid AND x.period_id = c.period_id
 WHERE c.total >= 25000 AND c.description NOT REGEXP 'rent|lease|loyer|bail|office space';

-- ── S5 supplier concentration ───────────────────────────────────────────────
INSERT INTO audit_flags
SELECT CONCAT('S5:', s.mp_person_id, ':', LEFT(UPPER(s.supplier), 80)), 'S5', 'mp',
       CONCAT(p.first_name, ' ', p.last_name, ' — ', s.supplier), s.mp_person_id, NULL, s.total,
       CONCAT('$', FORMAT(s.total, 0), ' paid to one supplier over ', s.lines_n, ' lines, ', s.first_date, ' to ', s.last_date),
       CONCAT('Category: ', s.category, '. Descriptions: ', IFNULL((SELECT GROUP_CONCAT(DISTINCT c.description SEPARATOR '; ')
              FROM hoc_exp_contracts c WHERE c.mp_person_id = s.mp_person_id AND TRIM(c.supplier) = s.supplier), '')),
       'https://www.ourcommons.ca/ProactiveDisclosure/en/members', @now
  FROM mp_expenditure_supplier_totals s JOIN mp_persons p ON p.id = s.mp_person_id
 WHERE s.total >= 100000
   AND s.supplier NOT REGEXP 'bell|rogers|telus|videotron|vidéotron|sasktel|eastlink|canada post|postes canada|house of commons|chambre des communes|receiver general|receveur'
   AND NOT EXISTS (SELECT 1 FROM hoc_exp_contracts c WHERE c.mp_person_id = s.mp_person_id AND TRIM(c.supplier) = s.supplier
                    AND c.description REGEXP 'rent|lease|loyer|bail|office space');

SELECT rule, COUNT(*) flags, ROUND(SUM(amount)) amount FROM audit_flags WHERE rule LIKE 'S%' GROUP BY rule;
