-- SCH-00 (2026-11-18): the staging table came from the Feb-2025 dump and lacks 5 columns that the pre-loss
-- schema snapshot (database/grantconts-schema.json) has and load-staging.py writes. Types and order from the snapshot.
ALTER TABLE `staging`
  ADD COLUMN `agreement_value_range_en` text NULL AFTER `id`,
  ADD COLUMN `has_amendments_en` varchar(3) NULL AFTER `agreement_value_range_en`,
  ADD COLUMN `fiscal_year` varchar(9) NULL AFTER `has_amendments_en`,
  ADD COLUMN `quarter_en` varchar(2) NULL AFTER `fiscal_year`,
  ADD COLUMN `report_type` varchar(255) NULL AFTER `quarter_en`;
