-- ============================================================
-- Map riding-totals cache — govdata_cache_02
-- Run once to set up the table and stored procedure.
-- The stored procedure is called by the PHP refresh script
-- (database/refresh-map-json.php) and by the WP-Cron hook.
-- ============================================================

USE govdata_cache_02;

-- Compact denormalised table for the map page.
-- Populated from riding_cache (same DB), so no cross-server joins needed.
CREATE TABLE IF NOT EXISTS `map_riding_totals` (
    `riding_number`   VARCHAR(8)     NOT NULL,
    `name`            VARCHAR(190)   NOT NULL DEFAULT '',
    `province`        VARCHAR(8)     NOT NULL DEFAULT '',
    `spend`           DECIMAL(20,2)  NOT NULL DEFAULT 0,
    `agreement_count` BIGINT         NOT NULL DEFAULT 0,
    `updated_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`riding_number`),
    KEY `idx_spend` (`spend`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Stored procedure: read straight-column data from riding_cache and
-- upsert into map_riding_totals. No payload JSON parsing — uses the
-- denormalised columns the warmer already writes.
DROP PROCEDURE IF EXISTS `sp_refresh_map_totals`;

DELIMITER $$
CREATE PROCEDURE `sp_refresh_map_totals`()
BEGIN
    INSERT INTO `map_riding_totals`
        (`riding_number`, `name`, `province`, `spend`, `agreement_count`)
    SELECT
        `riding_number`,
        COALESCE(`riding_name_en`, ''),
        COALESCE(`province`, ''),
        COALESCE(`total_value`, 0),
        COALESCE(`agreement_count`, 0)
    FROM `riding_cache`
    ON DUPLICATE KEY UPDATE
        `name`            = VALUES(`name`),
        `province`        = VALUES(`province`),
        `spend`           = VALUES(`spend`),
        `agreement_count` = VALUES(`agreement_count`),
        `updated_at`      = NOW();
END$$
DELIMITER ;


-- ============================================================
-- Optional: view in grantconts for direct aggregate queries.
-- Requires an admin/DDL user on the grantconts Cloud SQL instance
-- (pt_read is SELECT-only and cannot CREATE VIEW).
-- Run manually with admin credentials when available.
-- ============================================================
-- USE grantconts;
-- CREATE OR REPLACE VIEW `v_riding_map_totals` AS
-- SELECT
--     p.`fed_num`                                   AS `riding_number`,
--     ROUND(SUM(GREATEST(m.`agreement_value`, 0)), 2) AS `total_spend`,
--     COUNT(*)                                      AS `agreement_count`
-- FROM `master_table` m
-- JOIN `pcbfr`        p
--   ON REPLACE(UPPER(m.`recipient_postal_code`), ' ', '') =
--      REPLACE(UPPER(p.`postal_code`),           ' ', '')
-- WHERE p.`ro_id` IN (1, 2)
-- GROUP BY p.`fed_num`;
