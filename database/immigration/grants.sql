-- Loader account for the immigration tables. Run as root on govdata-serving AFTER schema.sql.
-- The password is generated on the server (see README) and never written to the repository.
--   mysql -e "CREATE USER IF NOT EXISTS 'imm_load'@'localhost' IDENTIFIED BY '<generated>'"
-- Data rights only, table by table: no DDL, and nothing outside the imm_* tables.
GRANT SELECT, INSERT, UPDATE         ON grantconts.imm_sources        TO 'imm_load'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON grantconts.imm_editions       TO 'imm_load'@'localhost';
GRANT SELECT, INSERT                 ON grantconts.imm_refresh_log    TO 'imm_load'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON grantconts.imm_country        TO 'imm_load'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON grantconts.imm_country_alias  TO 'imm_load'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON grantconts.imm_fact           TO 'imm_load'@'localhost';
GRANT SELECT, INSERT                 ON grantconts.imm_fact_revisions TO 'imm_load'@'localhost';
GRANT SELECT, INSERT                 ON grantconts.imm_fact_removed   TO 'imm_load'@'localhost';
-- House / bills / laws (schema_parl.sql)
GRANT SELECT, INSERT, UPDATE         ON grantconts.parl_bills          TO 'imm_load'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON grantconts.parl_divisions      TO 'imm_load'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON grantconts.parl_division_votes TO 'imm_load'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON grantconts.law_instruments     TO 'imm_load'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON grantconts.law_amendments      TO 'imm_load'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON grantconts.imm_bill_tags       TO 'imm_load'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON grantconts.imm_ee_draws        TO 'imm_load'@'localhost';
-- Country pages (schema_country.sql)
GRANT SELECT, INSERT, UPDATE, DELETE ON grantconts.imm_country_iso   TO 'imm_load'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON grantconts.cty_oda           TO 'imm_load'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON grantconts.parl_person_birth TO 'imm_load'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON grantconts.cty_law_tags      TO 'imm_load'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON grantconts.cty_report        TO 'imm_load'@'localhost';
-- The report builder reads the grants table and the House roster.
GRANT SELECT ON grantconts.master_table       TO 'imm_load'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON grantconts.cty_dac1          TO 'imm_load'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON grantconts.aid_law_tags      TO 'imm_load'@'localhost';
