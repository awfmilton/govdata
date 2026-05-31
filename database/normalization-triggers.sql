-- =============================================================================
-- GovData normalization triggers and backfill procedure
--
-- Run on the grantconts database with a write-capable user.
--
-- What this does:
--   TRIGGER after_master_table_insert fires on every INSERT into master_table
--   and propagates the new row into the normalized relational tables:
--
--     recipients  — 1:1 with every master_table row
--     programs    — 1:1 with every master_table row
--     locations   — 1:1 with every master_table row
--     naics       — only for current rows (amendment_number IS NULL)
--     agreements  — only for current rows (amendment_number IS NULL)
--     amendments  — only for amendment rows (amendment_number IS NOT NULL),
--                   linking back to the base agreement by ref_number
--
-- Backfill:
--   sp_backfill_normalization fills in normalized rows for master_table rows
--   that pre-date this trigger. Run once after deploying the trigger.
--   Uses batch_size to limit memory; call repeatedly until it returns 0.
--
--   CALL sp_backfill_normalization(500, @done);
--   SELECT @done;  -- 0 = more rows to process, 1 = finished
-- =============================================================================

DELIMITER $$

-- =============================================================================
-- DROP existing trigger and procedure if re-deploying
-- =============================================================================
DROP TRIGGER IF EXISTS after_master_table_insert$$
DROP PROCEDURE IF EXISTS sp_backfill_normalization$$


-- =============================================================================
-- TRIGGER: after_master_table_insert
-- =============================================================================
CREATE TRIGGER after_master_table_insert
AFTER INSERT ON master_table
FOR EACH ROW
BEGIN
    DECLARE v_recipient_id  INT UNSIGNED DEFAULT NULL;
    DECLARE v_program_id    INT UNSIGNED DEFAULT NULL;
    DECLARE v_location_id   INT UNSIGNED DEFAULT NULL;
    DECLARE v_naics_id      INT UNSIGNED DEFAULT NULL;
    DECLARE v_agreement_id  INT UNSIGNED DEFAULT NULL;

    -- Skip rows with no ref_number (corrupt/empty rows).
    IF NEW.ref_number IS NULL OR NEW.ref_number = '' THEN
        LEAVE after_master_table_insert;
    END IF;

    -- -----------------------------------------------------------------
    -- 1. Recipient (every row)
    -- -----------------------------------------------------------------
    INSERT INTO recipients (
        source_row_id,
        recipient_business_number,
        recipient_legal_name,
        recipient_operating_name,
        recipient_type_code,
        research_organization_name
    ) VALUES (
        NEW.primary_key,
        NULLIF(NEW.recipient_business_number, ''),
        NULLIF(NEW.recipient_legal_name, ''),
        NULLIF(NEW.recipient_operating_name, ''),
        NULLIF(NEW.recipient_type, ''),
        NULLIF(NEW.research_organization_name, '')
    );
    SET v_recipient_id = LAST_INSERT_ID();

    -- -----------------------------------------------------------------
    -- 2. Program (every row)
    -- -----------------------------------------------------------------
    INSERT INTO programs (
        source_row_id,
        prog_name_en,
        prog_name_fr,
        prog_purpose_en,
        prog_purpose_fr
    ) VALUES (
        NEW.primary_key,
        NULLIF(NEW.prog_name_en, ''),
        NULLIF(NEW.prog_name_fr, ''),
        NULLIF(NEW.prog_purpose_en, ''),
        NULLIF(NEW.prog_purpose_fr, '')
    );
    SET v_program_id = LAST_INSERT_ID();

    -- -----------------------------------------------------------------
    -- 3. Location (every row)
    -- riding_number = 0 after LOAD DATA on empty field → treat as NULL
    -- -----------------------------------------------------------------
    INSERT INTO locations (
        source_row_id,
        recipient_country,
        recipient_province,
        recipient_city,
        recipient_postal_code,
        federal_riding_name_en,
        federal_riding_name_fr,
        federal_riding_number,
        mp_id
    ) VALUES (
        NEW.primary_key,
        NULLIF(NEW.recipient_country, ''),
        NULLIF(NEW.recipient_province, ''),
        NULLIF(NEW.recipient_city, ''),
        NULLIF(NEW.recipient_postal_code, ''),
        NULLIF(NEW.federal_riding_name_en, ''),
        NULLIF(NEW.federal_riding_name_fr, ''),
        NULLIF(NEW.federal_riding_number, 0),
        NULL
    );
    SET v_location_id = LAST_INSERT_ID();

    -- -----------------------------------------------------------------
    -- 4a. Current row (amendment_number IS NULL) → naics + agreement
    -- -----------------------------------------------------------------
    IF NEW.amendment_number IS NULL THEN

        INSERT INTO naics (source_row_id, naics_identifier)
        VALUES (
            NEW.primary_key,
            NULLIF(NEW.naics_identifier, 0)
        );
        SET v_naics_id = LAST_INSERT_ID();

        INSERT INTO agreements (
            source_row_id,
            ref_number,
            agreement_number,
            agreement_type,
            agreement_value,
            foreign_currency_type,
            foreign_currency_value,
            agreement_start_date,
            year,
            agreement_end_date,
            coverage,
            description_eng,
            description_fr,
            expected_results_en,
            expected_results_fr,
            additional_information_en,
            additional_information_fr,
            agreement_title_en,
            agreement_title_fr,
            recipient_id,
            program_id,
            location_id,
            naics_id
        ) VALUES (
            NEW.primary_key,
            NULLIF(NEW.ref_number, ''),
            NULLIF(NEW.agreement_number, ''),
            NULLIF(NEW.agreement_type, ''),
            NEW.agreement_value,
            NULLIF(NEW.foreign_currency_type, ''),
            NEW.foreign_currency_value,
            -- Handle both NULL and '0000-00-00' (LOAD DATA INFILE artefact)
            NULLIF(NULLIF(NEW.agreement_start_date, '0000-00-00'), ''),
            NEW.year,
            NULLIF(NULLIF(NEW.agreement_end_date, '0000-00-00'), ''),
            NULLIF(NEW.coverage, ''),
            NULLIF(NEW.description_eng, ''),
            NULLIF(NEW.description_fr, ''),
            NULLIF(NEW.expected_results_en, ''),
            NULLIF(NEW.expected_results_fr, ''),
            NULLIF(NEW.additional_information_en, ''),
            NULLIF(NEW.additional_information_fr, ''),
            NULLIF(NEW.agreement_title_en, ''),
            NULLIF(NEW.agreement_title_fr, ''),
            v_recipient_id,
            v_program_id,
            v_location_id,
            v_naics_id
        );

    -- -----------------------------------------------------------------
    -- 4b. Amendment row → amendments table
    -- Looks up the base agreement by ref_number; skips if not found yet
    -- (can happen if amendments arrive before the current row).
    -- -----------------------------------------------------------------
    ELSE

        SELECT agreement_id
        INTO   v_agreement_id
        FROM   agreements
        WHERE  ref_number = NEW.ref_number
        LIMIT  1;

        IF v_agreement_id IS NOT NULL THEN
            INSERT INTO amendments (
                source_row_id,
                agreement_id,
                amendment_number,
                amendment_date,
                additional_information_en
            ) VALUES (
                NEW.primary_key,
                v_agreement_id,
                NEW.amendment_number,
                NULLIF(NULLIF(NEW.amendment_date, '0000-00-00'), ''),
                NULLIF(NEW.additional_information_en, '')
            );
        END IF;

    END IF;

END$$


-- =============================================================================
-- STORED PROCEDURE: sp_backfill_normalization
--
-- Fills in normalized rows for existing master_table rows that pre-date the
-- trigger. Processes rows in source_row_id order starting from the lowest
-- primary_key that does NOT yet have a recipient entry.
--
-- Parameters:
--   p_batch_size  INT  — rows to process per call (suggested: 200–500)
--   p_done        OUT TINYINT — 1 when no rows remain, 0 if more exist
--
-- Usage:
--   CALL sp_backfill_normalization(500, @done);
--   SELECT @done;
-- =============================================================================
CREATE PROCEDURE sp_backfill_normalization(
    IN  p_batch_size INT,
    OUT p_done       TINYINT
)
BEGIN
    DECLARE v_pk            INT UNSIGNED;
    DECLARE v_ref           VARCHAR(50);
    DECLARE v_amend         INT UNSIGNED;
    DECLARE v_amend_date    DATE;
    DECLARE v_atype         CHAR(1);
    DECLARE v_rtype         CHAR(1);
    DECLARE v_bn            VARCHAR(255);
    DECLARE v_rname         VARCHAR(350);
    DECLARE v_rop           VARCHAR(350);
    DECLARE v_research      VARCHAR(255);
    DECLARE v_country       CHAR(2);
    DECLARE v_province      CHAR(2);
    DECLARE v_city          VARCHAR(255);
    DECLARE v_postal        CHAR(7);
    DECLARE v_riding_en     VARCHAR(100);
    DECLARE v_riding_fr     VARCHAR(100);
    DECLARE v_riding_num    INT UNSIGNED;
    DECLARE v_prog_en       VARCHAR(255);
    DECLARE v_prog_fr       VARCHAR(255);
    DECLARE v_purpose_en    TEXT;
    DECLARE v_purpose_fr    TEXT;
    DECLARE v_title_en      VARCHAR(800);
    DECLARE v_title_fr      VARCHAR(800);
    DECLARE v_agr_num       VARCHAR(100);
    DECLARE v_agr_val       DECIMAL(15,2);
    DECLARE v_curr_type     CHAR(3);
    DECLARE v_curr_val      DECIMAL(15,2);
    DECLARE v_start         DATE;
    DECLARE v_yr            YEAR;
    DECLARE v_end           DATE;
    DECLARE v_coverage      TEXT;
    DECLARE v_desc_en       TEXT;
    DECLARE v_desc_fr       TEXT;
    DECLARE v_naics         INT UNSIGNED;
    DECLARE v_results_en    TEXT;
    DECLARE v_results_fr    TEXT;
    DECLARE v_add_en        TEXT;
    DECLARE v_add_fr        TEXT;

    DECLARE v_recipient_id  INT UNSIGNED DEFAULT NULL;
    DECLARE v_program_id    INT UNSIGNED DEFAULT NULL;
    DECLARE v_location_id   INT UNSIGNED DEFAULT NULL;
    DECLARE v_naics_id      INT UNSIGNED DEFAULT NULL;
    DECLARE v_agreement_id  INT UNSIGNED DEFAULT NULL;
    DECLARE v_processed     INT DEFAULT 0;
    DECLARE v_done_loop     TINYINT DEFAULT 0;

    DECLARE cur CURSOR FOR
        SELECT m.primary_key,
               m.ref_number,          m.amendment_number,   m.amendment_date,
               m.agreement_type,      m.recipient_type,
               m.recipient_business_number, m.recipient_legal_name, m.recipient_operating_name,
               m.research_organization_name,
               m.recipient_country,   m.recipient_province,  m.recipient_city,
               m.recipient_postal_code,
               m.federal_riding_name_en, m.federal_riding_name_fr, m.federal_riding_number,
               m.prog_name_en,        m.prog_name_fr,
               m.prog_purpose_en,     m.prog_purpose_fr,
               m.agreement_title_en,  m.agreement_title_fr,
               m.agreement_number,    m.agreement_value,
               m.foreign_currency_type, m.foreign_currency_value,
               m.agreement_start_date, m.year, m.agreement_end_date,
               m.coverage,            m.description_eng,     m.description_fr,
               m.naics_identifier,
               m.expected_results_en, m.expected_results_fr,
               m.additional_information_en, m.additional_information_fr
        FROM   master_table m
        LEFT JOIN recipients r ON r.source_row_id = m.primary_key
        WHERE  r.recipient_id IS NULL            -- not yet normalized
          AND  m.ref_number IS NOT NULL
          AND  m.ref_number <> ''
        ORDER BY m.primary_key
        LIMIT p_batch_size;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done_loop = 1;

    OPEN cur;

    row_loop: LOOP
        FETCH cur INTO
            v_pk,
            v_ref,          v_amend,        v_amend_date,
            v_atype,        v_rtype,
            v_bn,           v_rname,        v_rop,
            v_research,
            v_country,      v_province,     v_city,
            v_postal,
            v_riding_en,    v_riding_fr,    v_riding_num,
            v_prog_en,      v_prog_fr,
            v_purpose_en,   v_purpose_fr,
            v_title_en,     v_title_fr,
            v_agr_num,      v_agr_val,
            v_curr_type,    v_curr_val,
            v_start,        v_yr,           v_end,
            v_coverage,     v_desc_en,      v_desc_fr,
            v_naics,
            v_results_en,   v_results_fr,
            v_add_en,       v_add_fr;

        IF v_done_loop THEN
            LEAVE row_loop;
        END IF;

        -- Recipient
        INSERT INTO recipients (
            source_row_id, recipient_business_number, recipient_legal_name,
            recipient_operating_name, recipient_type_code, research_organization_name
        ) VALUES (v_pk, v_bn, v_rname, v_rop, v_rtype, v_research);
        SET v_recipient_id = LAST_INSERT_ID();

        -- Program
        INSERT INTO programs (
            source_row_id, prog_name_en, prog_name_fr, prog_purpose_en, prog_purpose_fr
        ) VALUES (v_pk, v_prog_en, v_prog_fr, v_purpose_en, v_purpose_fr);
        SET v_program_id = LAST_INSERT_ID();

        -- Location
        INSERT INTO locations (
            source_row_id, recipient_country, recipient_province, recipient_city,
            recipient_postal_code, federal_riding_name_en, federal_riding_name_fr,
            federal_riding_number, mp_id
        ) VALUES (v_pk, v_country, v_province, v_city, v_postal,
                  v_riding_en, v_riding_fr, v_riding_num, NULL);
        SET v_location_id = LAST_INSERT_ID();

        -- NAICS + agreement for current rows only
        IF v_amend IS NULL THEN

            INSERT INTO naics (source_row_id, naics_identifier)
            VALUES (v_pk, v_naics);
            SET v_naics_id = LAST_INSERT_ID();

            INSERT INTO agreements (
                source_row_id, ref_number, agreement_number, agreement_type,
                agreement_value, foreign_currency_type, foreign_currency_value,
                agreement_start_date, year, agreement_end_date, coverage,
                description_eng, description_fr, expected_results_en, expected_results_fr,
                additional_information_en, additional_information_fr,
                agreement_title_en, agreement_title_fr,
                recipient_id, program_id, location_id, naics_id
            ) VALUES (
                v_pk, v_ref, v_agr_num, v_atype,
                v_agr_val, v_curr_type, v_curr_val,
                v_start, v_yr, v_end, v_coverage,
                v_desc_en, v_desc_fr, v_results_en, v_results_fr,
                v_add_en, v_add_fr,
                v_title_en, v_title_fr,
                v_recipient_id, v_program_id, v_location_id, v_naics_id
            );

        -- Amendment rows
        ELSE

            SET v_agreement_id = NULL;
            SELECT agreement_id INTO v_agreement_id
            FROM   agreements
            WHERE  ref_number = v_ref
            LIMIT  1;

            IF v_agreement_id IS NOT NULL THEN
                INSERT INTO amendments (
                    source_row_id, agreement_id, amendment_number,
                    amendment_date, additional_information_en
                ) VALUES (
                    v_pk, v_agreement_id, v_amend, v_amend_date, v_add_en
                );
            END IF;

        END IF;

        SET v_processed = v_processed + 1;
    END LOOP;

    CLOSE cur;

    -- Return 1 if nothing was processed (all rows already normalized)
    SET p_done = IF(v_processed = 0, 1, 0);
END$$

DELIMITER ;

-- =============================================================================
-- VERIFICATION QUERIES (run after deploying)
-- =============================================================================

-- Check trigger exists:
-- SHOW TRIGGERS FROM grantconts LIKE 'master_table'\G

-- Run backfill (repeat until @done = 1):
-- CALL sp_backfill_normalization(500, @done); SELECT @done;

-- Sanity check row counts after backfill:
-- SELECT 'master_table' tbl, COUNT(*) n FROM master_table
-- UNION ALL SELECT 'recipients',  COUNT(*) FROM recipients
-- UNION ALL SELECT 'programs',    COUNT(*) FROM programs
-- UNION ALL SELECT 'locations',   COUNT(*) FROM locations
-- UNION ALL SELECT 'naics',       COUNT(*) FROM naics
-- UNION ALL SELECT 'agreements',  COUNT(*) FROM agreements
-- UNION ALL SELECT 'amendments',  COUNT(*) FROM amendments;
