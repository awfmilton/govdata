# MP Profile — Finance Data Setup Guide

This document covers wiring the HoC expenditure tables (already in `grantconts`)
into the MP profile pages. The warmer reads from `pt-grantconts-01` and writes
precomputed rows into `pt-cache-02` (govdata_cache_02), following the same
pattern as the riding-cache system.

**What works after running the warmer:**
- KPI cards: Total Office Spend, Travel Expenses, Contract Spend, Votes Cast*
- Spending Breakdown line chart (salaries / travel / hospitality / contracts by fiscal year)
- Category Comparison bar chart (latest year)
- Funding Flow Network diagram (derived from the category series)

*Votes Cast will remain 0 until a parliamentary votes data source is added.

---

## Source schema (in `grantconts`)

Three tables were created by the Python ETL pipeline:

### `hoc_expenditures_raw` — staging only, not queried by PHP

### `hoc_reporting_periods`

| Column                | Type         | Notes                                    |
| --------------------- | ------------ | ---------------------------------------- |
| `period_id`           | INT PK       | Auto-increment                           |
| `fiscal_year`         | VARCHAR(20)  | e.g. `"2024-2025"`                       |
| `quarter`             | VARCHAR(10)  | e.g. `"Q3"`                              |
| `quarter_name`        | VARCHAR(50)  | e.g. `"third-quarter"`                   |
| `reporting_period_desc` | VARCHAR(255) | Original long-form description          |
| **UNIQUE** `idx_period` | (fiscal_year, quarter) |                              |

### `hoc_member_expenditures`

| Column          | Type           | Notes                                                 |
| --------------- | -------------- | ----------------------------------------------------- |
| `expenditure_id` | INT PK        | Auto-increment                                        |
| `mp_id`         | VARCHAR(10) FK | References `mps(mp_id)` — varchar e.g. `"P0001"`, `"HIST-001"` |
| `period_id`     | INT FK         | References `hoc_reporting_periods(period_id)`         |
| `salaries`      | DECIMAL(15,2)  |                                                       |
| `travel`        | DECIMAL(15,2)  |                                                       |
| `hospitality`   | DECIMAL(15,2)  |                                                       |
| `contracts`     | DECIMAL(15,2)  |                                                       |

**Important:** `mp_id` is a VARCHAR matching the `external_mp_id` post meta on each
`member-parliament` CPT post (e.g. `"P0001"`). Historical/retired MPs not in the
current `mps` snapshot were assigned stable `HIST-NNN` IDs during the ETL import.
Those will not match any WP post and are silently skipped during post meta sync.

---

## Cache schema (auto-created in `govdata_cache_02`)

The warmer (`class-mp-finance-warmer.php`) calls `ensure_schema()` on every run,
so these tables are created automatically on first use. For manual provisioning:

### `mp_expenditure_periods`

```sql
CREATE TABLE IF NOT EXISTS `mp_expenditure_periods` (
    `id`          INT           NOT NULL AUTO_INCREMENT,
    `mp_id`       VARCHAR(10)   NOT NULL,
    `fiscal_year` SMALLINT      NOT NULL,   -- start year, e.g. 2024 for "2024-2025"
    `category`    VARCHAR(20)   NOT NULL,   -- 'Salaries' | 'Travel' | 'Hospitality' | 'Contracts'
    `total`       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_mp_year_cat` (`mp_id`, `fiscal_year`, `category`),
    KEY `idx_mp` (`mp_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Queried by `GET /wp-json/publictech/v1/mp/{id}/spend`. Returns
`[{fiscal_year: INT, category: STRING, total: DECIMAL}]` — the exact shape
the profile page JS expects for D3 chart rendering.

### `mp_kpi_cache`

```sql
CREATE TABLE IF NOT EXISTS `mp_kpi_cache` (
    `mp_id`           VARCHAR(10)   NOT NULL,
    `total_spend`     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `travel_spend`    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `contracts_spend` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `updated_at`      DATETIME      NOT NULL,
    PRIMARY KEY (`mp_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Used only by the warmer to write WP post meta. Not queried at request time.

---

## Running the warmer

### First run (manual, via WP-CLI)

```bash
wp eval-file database/warm-mp-finance-cache.php
```

Expected output:

```
Starting MP finance cache warm…
Loading expenditure rows from source…
Loaded 3549 fiscal-year rows for 592 MPs.
Period rows written to cache.
KPI rows written for 592 MPs.
Post meta updated for 336 WP posts.
Warm complete in 4.2s.
Success: Done. 592 unique MPs cached, 336 WP posts updated with KPI meta, completed in 4.2s.
```

The warmer is idempotent — safe to re-run after a data refresh.

### Ongoing — weekly WP-Cron

The cron event (`publictech_warm_mp_finance`) is registered in
`publictech-core/functions.php` and schedules itself for Sundays at 05:00 UTC
on first activation. It runs one week after the riding-cache warm (Sundays 04:00)
so the two batch jobs don't overlap.

Verify the schedule:

```bash
wp cron event list | grep mp_finance
```

Ensure real cron is wired (DEPLOYMENT.md §8.5) so it fires reliably.

---

## Verification checklist

- [ ] Both connections show `"ok": true` in `/wp-json/publictech/v1/status`
- [ ] Warmer runs without errors: `wp eval-file database/warm-mp-finance-cache.php`
- [ ] Rows in cache: `SELECT COUNT(*) FROM mp_expenditure_periods` (expect ~14 000)
- [ ] KPIs in cache: `SELECT COUNT(*) FROM mp_kpi_cache` (expect ~592)
- [ ] Spend endpoint returns rows: `curl .../wp-json/publictech/v1/mp/{id}/spend`
- [ ] Line chart renders on an MP profile page (Spending Breakdown)
- [ ] Bar chart renders (Category Comparison)
- [ ] KPI cards show non-zero dollar values (Total Office Spend, Travel Expenses, Contract Spend)
- [ ] WP-Cron event is scheduled: `wp cron event list | grep mp_finance`

---

## Data notes

| Field         | Source                  | Notes                                                     |
| ------------- | ----------------------- | --------------------------------------------------------- |
| Total Spend   | salaries+travel+hosp+contracts | All four HoC expense categories summed          |
| Travel Spend  | travel column           | Matches HoC "Members' Travel" line                        |
| Contract Spend | contracts column       | HoC "Contracts" line — dollar amount, not vendor count    |
| Votes Cast    | *no source yet*         | Remains 0 until a parliamentary votes feed is added       |
| Fiscal year (chart x-axis) | LEFT(fiscal_year,4) | Stored as start year INT, e.g. 2024 for "2024-2025" |

**Why `pt-cache-02` and not a new `pt-finance-01`?** The original plan assumed
a separate finance DB. Since the data already lives in `grantconts` and the
cache infrastructure (`pt-cache-02`) is already wired, pre-computing into the
cache avoids an extra DB connection and keeps all the precomputed data in one
place alongside the riding rollups.

---

## Bugs fixed during investigation (2026-05-28)

These are committed on `main` and require no further action.

| File | Bug | Fix |
| ---- | --- | --- |
| `publictech-terminal/single-member-parliament.php` | Inline `<script>` ran before `get_footer()`, so `window.PT` and `window.PT_BOOT` were undefined — the guard silently exited, no charts rendered | Wrapped in `document.addEventListener('DOMContentLoaded', …)` |
| `publictech-core/inc/class-rest-api.php` | `external_mp_id` was cast `(int)` — varchar `"P0001"` became `0`, returning 404 "Member not linked" | Changed to `(string)` cast with `'' === $ext_id` guard |
| `publictech-core/inc/class-rest-api.php` + `publictech-terminal/archive-member-parliament.php` | Both read `riding` post meta; the importer writes `pt_mp_district` — riding names empty on directory cards | Updated to use `pt_mp_district` with `riding` as fallback |
