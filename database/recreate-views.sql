-- Recreated 2026-11-14: the original view definitions were not in any surviving dump.
-- Column lists match database/grantconts-schema.json.
USE grantconts;
-- Current version of each agreement only (owner decision 2026-11-18, option 1): master_table keeps every
-- version for the audit history; every TOTAL on the site counts each agreement once, via this view or an
-- `amendment_number IS NULL` filter.
CREATE OR REPLACE SQL SECURITY INVOKER VIEW master_current AS
  SELECT * FROM master_table WHERE amendment_number IS NULL;
CREATE OR REPLACE SQL SECURITY INVOKER VIEW tbayrainy_view AS
  SELECT * FROM master_current
  WHERE federal_riding_number = 35105
     OR (federal_riding_number IN (35107) AND federal_riding_name_en LIKE "Thunder Bay%Rainy%")
     OR (federal_riding_number IS NULL AND federal_riding_name_en LIKE "Thunder Bay%Rainy%");
CREATE OR REPLACE SQL SECURITY INVOKER VIEW tbay_rr_view AS SELECT * FROM tbayrainy_view;
CREATE OR REPLACE SQL SECURITY INVOKER VIEW thunder_bay_rainy_river_agreements AS SELECT * FROM tbayrainy_view;
CREATE OR REPLACE SQL SECURITY INVOKER VIEW view_total_funding   AS SELECT SUM(agreement_value) AS total_funding   FROM master_current;
CREATE OR REPLACE SQL SECURITY INVOKER VIEW view_agreement_count AS SELECT COUNT(*)             AS agreement_count FROM master_current;
CREATE OR REPLACE SQL SECURITY INVOKER VIEW view_average_funding AS SELECT AVG(agreement_value) AS average_funding FROM master_current;
CREATE OR REPLACE SQL SECURITY INVOKER VIEW view_max_funding     AS SELECT MAX(agreement_value) AS max_funding     FROM master_current;
CREATE OR REPLACE SQL SECURITY INVOKER VIEW view_min_funding     AS SELECT MIN(agreement_value) AS min_funding     FROM master_current;
CREATE OR REPLACE SQL SECURITY INVOKER VIEW view_amendment_count AS SELECT COUNT(*)             AS amendment_count FROM amendments;
-- Needs group_concat_max_len >= 64K on the server (a riding has up to ~3,000 postal codes).
CREATE OR REPLACE SQL SECURITY INVOKER VIEW mp_postal_codes AS
  SELECT m.mp_id, m.first_name, m.last_name, m.district_name,
         GROUP_CONCAT(p.postal_code ORDER BY p.postal_code SEPARATOR ",") AS postal_codes
  FROM mps m
  LEFT JOIN pcbfr p ON p.ro_id = 2 AND p.riding_name_en = m.district_name
  GROUP BY m.mp_id, m.first_name, m.last_name, m.district_name;
