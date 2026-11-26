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
