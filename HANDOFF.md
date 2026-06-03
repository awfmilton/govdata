# GovData — Handoff for Next Session

> Written 2026-06-01. Read this before touching anything.

## What this project is

GovData (govdata.ca) is a public transparency site showing Canadian federal grants/contributions
and MP office expenditures. It runs on WordPress + DreamHost shared hosting, with a read-only
Cloud SQL MySQL database (`grantconts`) that holds 1.26M grant agreements and the Open Parliament
historical MP dataset.

The full implementation plan is in `MP-RIDINGS-OVER-TIME-PLAN.md`. This file is the shortcut.

---

## Server access

| What | Value |
|---|---|
| SSH/SFTP host | `iad1-shared-b7-37.dreamhost.com` |
| Username | `dh_kf9eyv` |
| Password | `Y!AHUFC7od` |
| WordPress root | `/home/dh_kf9eyv/govdata.ca` |
| Theme/plugin root | `/home/dh_kf9eyv/govdata.ca/wp-content/themes/govdata` |
| WP-CLI | `wp --path=/home/dh_kf9eyv/govdata.ca` |

**SSH tip**: password contains `!` which bash history-expands. Use plink (PuTTY):
```
& "C:\Program Files\PuTTY\plink.exe" -ssh -pw "Y!AHUFC7od" -batch dh_kf9eyv@iad1-shared-b7-37.dreamhost.com "command"
& "C:\Program Files\PuTTY\pscp.exe" -pw "Y!AHUFC7od" -batch local_file dh_kf9eyv@iad1-shared-b7-37.dreamhost.com:/remote/path
```

For MySQL queries upload a `.sql` or `.sh` file first (the `!` in the password breaks inline
quoting). Use a MySQL credentials file at `/tmp/my_pt.cnf` on the server:
```
[client]
host=35.230.186.163
user=pt_read
password=123456789!!!!!!!!!
database=grantconts
```
Then: `mysql --defaults-file=/tmp/my_pt.cnf < /tmp/query.sql`

**`pt_read` has CREATE/INSERT but not DROP TABLE.**

## Database connections

| Alias | Host | DB | User | Password |
|---|---|---|---|---|
| `pt-grantconts-01` | `35.230.186.163` | `grantconts` | `pt_read` | `123456789!!!!!!!!!` |
| `pt-cache-02` | `mysql.alexmilton.ca` | `govdata_cache_02` | `gdc_02` | `123456789!!!!!!!!!` |

The `PT_DEV_DB_PASSWORD` constant (`123456789!!!!!!!!!`) is defined in
`database/mu-plugins/00-db-connections.php` and loaded by WordPress, so WP-CLI
scripts can use `defined('PT_DEV_DB_PASSWORD') ? PT_DEV_DB_PASSWORD : ''`.

---

## Current live state (as of 2026-06-01)

- **WordPress**: 343 published `member-parliament` posts — all 45th Parliament (2025).
  164 former MPs are draft. 113 new 2025 MPs have no office spend data yet (HoC hasn't
  published it); all have riding grants data.
- **Directory sorts working**: Highest Spend, Name A-Z, Riding A-Z, Year Elected.
- **All 343 MP pages** show riding grants data (2023 RO, 1,045,176 agreements).
- **`pcbfr` table**: ro_id=1 (2013 RO, 338 ridings) + ro_id=2 (2023 RO, 343 ridings).
- **`mp_persons`**: 1,345 rows (one per politician). **`mp_mandates`**: 1,892 rows
  (parliaments 35–45, 1994–present). Both tables are in `grantconts`.
- **Riding cache warmer** (`class-riding-cache-warmer.php`) uses `PCFRF_RO_ID = 2`
  (2023 RO). This is the Phase 4 hook point.

---

## Decisions already made — do not re-litigate

1. **Option A for `mps` table**: Keep the existing flat `mps` table. Add an
   `mp_persons_id` FK column to link it to `mp_persons`. Do NOT retire `mps` yet.
2. **WP post source of truth**: `op_core_electedmember WHERE end_date IS NULL`
   = 343 rows = the authoritative 45th Parliament roster. `sync-current-mps.php`
   is the sync script.
3. **Riding cache uses 2023 RO** (`ro_id=2`) for all agreements now. Phase 4
   makes it era-aware.
4. **`external_mp_id`** on WP posts still uses the old `MP-xxx` strings for the
   230 returning MPs. New 2025 MPs have `op_member_id` and `op_politician_id` meta
   but no `external_mp_id`. Do not change this until `mp_persons_id` FK is added
   to `mps` (task 1 below).

---

## Tasks — in order

### Task 1 — Add `mp_persons_id` FK to `mps` table *(30 min)*

**Why**: Option A (decided). Links OurCommons scraper data to the normalized
`mp_persons` table. Prerequisite for Task 2.

**What to do**:
```sql
-- Run on grantconts (pt_read has CREATE/ALTER privileges):
ALTER TABLE mps ADD COLUMN mp_persons_id INT DEFAULT NULL;
ALTER TABLE mps ADD INDEX idx_mp_persons_id (mp_persons_id);
```

Then write a Python script (following the pattern of `database/migrate-mp-schema.py`)
that matches `mps.mp_id` → `mp_persons.ourcommons_id` and sets `mp_persons_id`:
```python
UPDATE mps m
JOIN mp_persons p ON p.ourcommons_id = m.mp_id
SET m.mp_persons_id = p.id
WHERE m.mp_id LIKE 'MP%'
```

For the HIST- rows in `mps` (219 rows, 61 unique people): match by
`LOWER(first_name) + LOWER(last_name)` against `mp_persons` and set
`mp_persons_id` where found, then DELETE the HIST- rows once linked (or
mark them with a `is_historical` column if you want to keep the data).

---

### Task 2 — Reconcile 20 name-mismatch OurCommons MPs *(1-2 hours)*

**Why**: 20 MPs from the OurCommons scraper didn't match any `op_core_politician`
row by first+last name during migration. They have `mp_persons` rows but no
`op_politician_id`, so they have no mandate history.

**How to find them**:
```sql
SELECT ourcommons_id, first_name, last_name
FROM mp_persons
WHERE op_politician_id IS NULL AND ourcommons_id IS NOT NULL
ORDER BY last_name;
```

For each: check `op_core_politician.slug` against the OurCommons source URL in
`mps.source_url`. The slug is typically a hyphenated lowercase name. Manually
set `op_politician_id` where the match is found, then populate their mandates
from `op_core_electedmember` (same logic as `migrate-mp-schema.py` step 5).

---

### Task 3 — Era-aware spending (Phase 4) *(half day)*

**Why**: Currently ALL spending (including pre-2025 agreements) is attributed
using 2023 RO postal codes. Agreements from 2015-2024 should use 2013 RO.
Agreements from 2004-2014 should use 2003 RO.

**The one file to change**: `publictech-core/inc/class-riding-cache-warmer.php`

**Current code** (Phase 2 state):
```php
private const PCFRF_RO_ID = 2;  // line ~35

// load_directory() has: AND `ro_id` = " . self::PCFRF_RO_ID
// pcfrf_join() has:     AND `ro_id` = " . self::PCFRF_RO_ID
```

**What Phase 4 needs**:
Replace the static `PCFRF_RO_ID` filter in `pcfrf_join()` with a date-based
subselect. The `master_table` has a `year` column. The join should pick the
right `ro_id` per agreement:

```sql
-- Concept for the new pcfrf_join():
JOIN (
    SELECT DISTINCT UPPER(REPLACE(REPLACE(postal_code,' ',''),'-','')) AS pc,
           riding_number AS rn,
           ro_id
    FROM pcbfr
    WHERE postal_code IS NOT NULL AND postal_code <> ''
      AND riding_number IS NOT NULL AND riding_number <> ''
) p ON p.pc = UPPER(REPLACE(REPLACE(m.recipient_postal_code,' ',''),'-',''))
   AND p.ro_id = CASE
       WHEN m.year >= 2025 THEN 2   -- 2023 RO
       WHEN m.year >= 2015 THEN 1   -- 2013 RO  ← but see note below
       WHEN m.year >= 2004 THEN 1   -- 2003 RO  ← same ro_id=1 currently!
       ELSE 1
   END
```

**Blocker**: 2003 RO and 2013 RO currently both use `ro_id=1`. Before Phase 4
is fully correct, the 2003 RO rows need their own `ro_id`. Check:
```sql
SELECT COUNT(*), MIN(riding_name_en), MAX(riding_name_en)
FROM pcbfr WHERE ro_id=1 AND riding_number LIKE '24%'
LIMIT 5;
```
If you see both 308-riding and 338-riding names mixed in `ro_id=1`, you need to
re-import 2003 RO data with a new `ro_id=3` before the CASE logic above works.

**After changing the warmer**: run the warm via WP-CLI:
```
wp eval-file database/warm-riding-cache.php --path=/home/dh_kf9eyv/govdata.ca
```
Expect ~300-400s and ~1M agreements. The `load_directory()` call also needs
updating to return ridings across ALL active ROs (union), not just one.

---

### Task 4 — Re-run MP finance warmer when HoC data is available

**Why**: 113 new 2025 MPs show $0 office spend in the directory. The HoC publishes
quarterly expense reports; once Q1 2026 data is out, run:
```
wp eval-file database/warm-mp-finance-cache.php --path=/home/dh_kf9eyv/govdata.ca
```

The warmer matches on `external_mp_id` post meta. The new 113 MP posts don't
have `external_mp_id` set — check `class-mp-finance-warmer.php` to see if it
also matches on `op_politician_id` or needs to be updated to do so.

---

### Task 5 — Phase 5 UI: MP / timeframe / riding filters

**Why**: The temporal data model is built but no UI exposes it yet.

**What exists**:
- `mp_term_start` and `mp_term_start_year` post meta on all 343 WP posts
- `mp_mandates` table with parliament, start_date, end_date, riding_id
- Directory sort by Year Elected already live
- `op_core_riding`, `op_core_session` tables available in `grantconts`

**What to build**:
1. A "historical MPs" toggle on the directory that queries `mp_mandates` to show
   MPs from a specific parliament (not just the current 343 live posts).
2. A riding detail page that shows every MP who represented it across eras.
3. An era dropdown on MP pages to remap spending attribution.

This is new UI work — design first, then build.

---

## Key files reference

| File | Purpose |
|---|---|
| `publictech-core/inc/class-riding-cache-warmer.php` | Riding grants cache warm; `PCFRF_RO_ID` constant is Phase 4 hook |
| `publictech-core/inc/class-rest-api.php` | MP search REST endpoint; `mp_search()` and `shape_mp()` |
| `publictech-core/assets/js/search.js` | Directory AJAX: sort handler, facets, search |
| `publictech-terminal/archive-member-parliament.php` | Directory page template |
| `database/sync-current-mps.php` | Sync WP posts → 45th Parliament OP roster |
| `database/migrate-mp-schema.py` | Created `mp_persons` + `mp_mandates` |
| `database/fix-mandate-parliaments.py` | Back-filled parliament numbers |
| `database/warm-riding-cache.php` | WP-CLI entry point for the cache warm |
| `database/mu-plugins/00-db-connections.php` | DB credentials (shared dev password) |

---

## Common gotchas

- **`!` in DB password** breaks bash inline quoting. Always use plink + a
  credentials file or a Python script.
- **`pt_read` can CREATE and INSERT but not DROP TABLE.** If a table needs
  recreating, rename the old one (you can't drop it).
- **WP-CLI rejects unknown `--flags`**. Custom args must be declared at the top
  of the script as a constant (e.g. `PT_SYNC_DRY_RUN`) rather than parsed from argv.
- **`op_core_electedmember_sessions` is empty** — parliament numbers must be
  derived from `op_core_session` start/end dates, not from this join table.
- **MySQL `only_full_group_by` mode** is enabled on Cloud SQL. Any GROUP BY query
  must aggregate or use `ANY_VALUE()` for non-grouped columns.
- **Object cache** — after any WP data change run:
  `wp cache flush --path=/home/dh_kf9eyv/govdata.ca`
- **The `mps` table still has 219 HIST- rows** (61 unique people imported
  multiple times by a buggy run). They are harmless but should be cleaned up as
  part of Task 1.
- **`total_spend` meta** = HoC office expenses (salary, travel, contracts),
  NOT grants/contributions. Grants data lives in `riding_cache` (pt-cache-02)
  and is shown on individual MP pages, not the directory cards.
