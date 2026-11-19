-- Indexes the site code REQUIRES on grantconts.master_table. Their DDL was never committed; it is
-- reconstructed here from the code that depends on them (2026-11 rebuild). Re-run after any rebuild
-- that recreates master_table.
--   ft_riding_scan      : publictech-core/inc/riding-cache-lib.php pt_riding_keyword_fulltext_columns_sql()
--                         (column set MUST equal pt_riding_keyword_text_columns(), in this order)
--   ft_recipient        : publictech-core/inc/class-entity-reports.php MATCH(recipient_legal_name, recipient_operating_name)
--   idx_legalname_value : publictech-core/inc/class-dashboard-stats.php top recipients (covering index)
ALTER TABLE master_table
  ADD FULLTEXT INDEX ft_riding_scan (recipient_legal_name, recipient_operating_name, agreement_title_en,
                                     prog_name_en, prog_purpose_en, owner_org_en, description_eng);
ALTER TABLE master_table ADD FULLTEXT INDEX ft_recipient (recipient_legal_name, recipient_operating_name);
ALTER TABLE master_table ADD INDEX idx_legalname_value (recipient_legal_name, agreement_value);
