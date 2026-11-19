-- Postal codes whose 2023 riding is questionable: the 175 codes Elections Canada's lookup could not resolve,
-- each checked by Argon (Gemini 4) on 2026-11-19. High-confidence answers are applied to pcbfr (weight_information
-- 'M'); medium and low ones are NOT applied and the code keeps its computed riding. Shown publicly on affected
-- riding pages and on /postal-code-review/ so readers can help correct them.
CREATE TABLE IF NOT EXISTS pcbfr_review (
  postal_code     varchar(7)   NOT NULL PRIMARY KEY,
  city            varchar(120) DEFAULT NULL,
  original_riding varchar(6)   DEFAULT NULL COMMENT 'riding from the GeoNames + 2023 polygon build',
  checked_riding  varchar(6)   DEFAULT NULL COMMENT 'riding Argon found (blank = could not tell)',
  confidence      enum('high','medium','low') NOT NULL,
  applied         tinyint(1)   NOT NULL DEFAULT 0 COMMENT '1 = pcbfr now uses checked_riding',
  evidence        varchar(600) DEFAULT NULL,
  checked_by      varchar(60)  NOT NULL DEFAULT 'Argon (Gemini 4), 2026-11-19',
  KEY idx_original (original_riding),
  KEY idx_checked (checked_riding)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
