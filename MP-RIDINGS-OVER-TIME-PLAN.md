# MPs & Ridings Over Time — Implementation Plan

> Status: **Phase 2 complete. Phase 3 substantially complete.** Updated 2026‑06‑01.

## Why this exists

The MP/riding data needs to be *temporal* (boundaries and MPs change over time) to
support accurate grant attribution and historical browsing:

1. **Riding grants data** — agreements must be attributed to the riding boundary
   valid **at the time the agreement was signed** across four representation orders.
2. **MP roster over time** — show MPs and ridings across parliaments with the
   ability to filter by MP, timeframe, or riding.

Both problems are now substantially solved (see Current State below).

## Decisions (locked)

- **Spending attribution:** *temporally correct by default* — each agreement is
  attributed to the riding boundary valid at the time it was signed — **plus a
  user‑selectable era override** (Phase 4). Currently using 2023 RO for all
  agreements (Phase 2 state; Phase 4 makes it era-aware).
- **Historical depth:** back to **1989**.
- **`mps` table strategy:** **Option A** — keep the existing `mps` table, add an
  `mp_persons_id` FK column linking it to the new `mp_persons` table. The
  OurCommons scraper continues writing into `mps`; historical queries use
  `mp_persons`/`mp_mandates`.

## Representation Orders to model

| Rep. Order | In effect (federal elections) | Ridings | PCFRF / spending |
|------------|-------------------------------|--------:|:----------------:|
| **1987 RO** | 1989–2003 | 295 | ❌ (no agreements pre-2004) |
| **2003 RO** | 2004–2015 | 308 | ✅ in `pcbfr` (ro_id=1 currently shared with 2013) |
| **2013 RO** | 2015–2025 | 338 | ✅ in `pcbfr` (ro_id=1) |
| **2023 RO** | 2025– | 343 | ✅ in `pcbfr` (ro_id=2) — **warmed and live** |

Phase 4 will split 2003 and 2013 RO data into separate `ro_id` values and make
the warmer era-aware. The hook point is `PCFRF_RO_ID` constant in
`class-riding-cache-warmer.php` (currently hardcoded to `2`).

## Data model

Tables in `grantconts`:

```
representation_orders  (id, code, effective_start, effective_end, riding_count)
                       ✅ DONE: 2013_RO (id=1) and 2023_RO (id=2) seeded.

pcbfr                  (postal_code, riding_number, riding_name_en, riding_name_fr,
                        ro_id, …)
                       ✅ DONE: ro_id=1 (883,454 rows, 338 ridings, 2013 RO)
                               ro_id=2 (899,771 rows, 343 ridings, 2023 RO)
                       ⚠  2003 RO data is mixed into ro_id=1 — Phase 4 should
                          separate into its own ro_id.

mp_persons             (id AUTO_INCREMENT, op_politician_id, ourcommons_id,
                        first_name, last_name, gender, photo_url, source_url,
                        email, postal_code, const_phone, const_fax,
                        ottawa_phone, ottawa_fax)
                       ✅ DONE: 1,345 rows. One row per human being.
                          op_politician_id → op_core_politician (OP dataset).
                          ourcommons_id holds legacy MP-xxx key from scraper.

mp_mandates            (id AUTO_INCREMENT, mp_id → mp_persons.id,
                        op_member_id → op_core_electedmember.id,
                        riding_id → op_core_riding.id,
                        party, parliament, start_date, end_date)
                       ✅ DONE: 1,892 rows. Parliaments 35–45 (1994–present).
                          end_date IS NULL = currently serving (343 active).

mps                    (mp_id VARCHAR PK, first_name, last_name, district_name,
                        party, province, email, photo_url, contact fields…)
                       LEGACY flat table. Still used by WP import.
                       To add: mp_persons_id FK column (Option A — decided).
                       219 HIST- rows still present but not used by WordPress.
```

### How the three UI filters become queries (Phase 5)
- **By MP** → `mp_persons` → their `mp_mandates` → riding(s) + term dates → spending in that riding for that era.
- **By timeframe** → mandates overlapping the period → the MPs/ridings active then.
- **By riding** → its spending + every MP who represented it across eras.

---

## Build sequence

### Phase 1 — Temporal foundation ✅ COMPLETE
- `representation_orders` created; seeded with 2013_RO and 2023_RO.

### Phase 2 — Boundaries + postal mapping ✅ COMPLETE
- 2023 PCFRF data loaded into `pcbfr` as `ro_id=2` (899,771 rows, 343 ridings).
- Riding-cache warmer updated: added `PCFRF_RO_ID = 2` constant to
  `class-riding-cache-warmer.php` filtering both `load_directory()` and
  `pcfrf_join()` — eliminates double-counting between ROs.
- Cache warm run: **343 ridings / 1,045,176 agreements**.
- All 343 MP pages now show riding grants data. The 165 broken pages are fixed.

### Phase 3 — MP model + import ✅ SUBSTANTIALLY COMPLETE

#### Done (all sessions)
- `mp_persons` populated (1,345 rows), `mp_mandates` populated (1,892 rows,
  parliaments 35–45).
- Parliament numbers back-filled from `op_core_session` start dates (the
  `op_core_electedmember_sessions` join table was empty; date-proximity used).
- **Option A decided**: keep `mps` table, add `mp_persons_id` FK column to it.
- WordPress synced to 45th Parliament roster:
  - 555 duplicate posts → cleaned to 394 → synced to **343 current MPs**.
  - 164 former MPs (did not win in 2025) moved to draft.
  - 113 new 2025 MPs created from `op_core_electedmember` (end_date IS NULL).
  - `sync-current-mps.php` is the authoritative sync script going forward.
- All MP posts now carry:
  - `op_member_id` — FK to `op_core_electedmember.id`
  - `op_politician_id` — FK to `op_core_politician.id`
  - `mp_term_start` — ISO date of current mandate start (from OP data)
  - `mp_term_start_year` — year integer (for directory sort)
- Directory sort fully wired: **Highest Spend / Name A–Z / Riding A–Z / Year Elected**.
  Previously the sort links were dead (click handler was missing from `search.js`).
- REST API `mp_search()` now correctly applies `orderby` to WP_Query for all
  four sort modes. Posts without `total_spend` meta are included and sink to the
  bottom of Highest Spend.
- Name-entity bug fixed: `Ya'ara Saks` and similar names now display correctly.
  (`html_entity_decode` applied to `get_the_title()` in `shape_mp()`).

#### Scripts committed
- `database/diagnose-mp-dupes.php` — diagnose duplicate WP posts vs source DB
- `database/dedup-mp-posts.php` — deduplicate WP posts (keep one per name)
- `database/sync-current-mps.php` — **primary sync**: WP → 45th Parliament roster from OP
- `database/migrate-mp-schema.py` — created `mp_persons` + `mp_mandates` from OP data
- `database/fix-mandate-parliaments.py` — back-filled parliament numbers via date proximity
- `database/populate-mp-term-start.php` — wrote `mp_term_start` meta on all WP posts

#### Still to do
- **Add `mp_persons_id` FK column to `mps`** (Option A) — links each OurCommons
  scraper row to its `mp_persons` row. A simple `ALTER TABLE mps ADD COLUMN
  mp_persons_id INT` + a Python script to populate it via name matching.
- **Reconcile 20 name-mismatch MPs** — 20 OurCommons MPs (of 336) did not match
  any `op_core_politician` row. They have no OP mandate history. Match by
  comparing OurCommons source URLs against `op_core_politician.slug`.
- **`warm-mp-finance-cache.php` re-run** — once the HoC publishes 45th Parliament
  office expenditure data, run this to populate `total_spend` on the 113 new MP
  posts (currently $0). The 230 existing MP posts already have accurate data.
- **Historical depth before parliament 35** — OP dataset covers parliaments 35–45
  (1994–present). Parliaments 28–34 (1974–1993) need the PARLINFO backfill to
  reach the 1989 target.
- **Clean up HIST- rows in `mps`** — 219 HIST- rows (61 unique people, imported
  multiple times by a buggy run) remain in `mps` but are no longer used by
  WordPress. Remove them once the `mp_persons_id` FK work is done.

### Phase 4 — Era‑aware spending *(next major phase)*

Currently the warmer uses `PCFRF_RO_ID = 2` (2023 RO) for all agreements,
meaning pre-2025 spending is attributed to 2023 boundaries. This is good enough
for Phase 2 but not temporally correct.

**What needs to change in `class-riding-cache-warmer.php`:**
- Replace the single `PCFRF_RO_ID` constant with a per-agreement era join.
- The `pcfrf_join()` subquery needs to become a join that selects
  `riding_number` from `pcbfr` filtered by the `ro_id` matching the
  agreement's year: `year < 2015 → ro_id=1 (2003 RO)`, `2015–2024 → ro_id=1
  (2013 RO)`, `2025+ → ro_id=2 (2023 RO)`.
- 2003 RO data currently shares `ro_id=1` with 2013 RO — these need to be
  split before Phase 4 can be fully correct.
- The riding cache schema may need a second dimension (per-RO subtotals) or the
  warmer runs once per RO and merges, depending on what the UI needs.

### Phase 5 — UI
- Filters for **MP / timeframe / riding**, driven by `mp_mandates`.
- Era dropdown (default = temporally correct; override = show on one boundary set).
- Groundwork is in place: `mp_term_start` on WP posts, `mp_mandates` in DB, sort
  by year elected already live in the directory.

---

## Dependencies / what unblocks what

- **Phase 4 needs** 2003 RO split from 2013 RO in `pcbfr`, and the `PCFRF_RO_ID`
  constant replaced with a date-based era join in `pcfrf_join()`.
- **Phase 5 needs** Phase 4 data to be accurate before the era dropdown is meaningful.
- **`mp_persons_id` FK** (Option A) unblocks the 20-MP reconciliation and lets
  `import-mps.php` be updated to reference the new schema.
- **PARLINFO backfill** is independent — can be added to `mp_mandates` any time
  without breaking anything else.

---

## Current state (as of 2026‑06‑01)

- **WordPress directory: 343 published MP posts** — all 45th Parliament (2025).
  No duplicate names, no duplicate ridings. 164 former MPs in draft.
- **Sort options live**: Highest Spend, Name A–Z, Riding A–Z, Year Elected.
- **All 343 MP pages show riding grants data** (2023 RO, 1,045,176 agreements).
- **`mp_persons`**: 1,345 rows. **`mp_mandates`**: 1,892 rows (parliaments 35–45).
- **`pcbfr`**: ro_id=1 (2013 RO, 338 ridings) + ro_id=2 (2023 RO, 343 ridings).
- **`total_spend`** (HoC office expenses): populated for 230 returning MPs; $0 for
  113 new 2025 MPs pending HoC expenditure publication.
- `master_table`: 1,264,823 rows, id-keyed + normalized tables. Cloud SQL tuned
  (16 GB RAM, ~9 GB buffer pool, FTS limit 4 GB). See
  `memory/grantconts-rebuild-normalization.md`.
- Riding-cache keyword aggregation uses per-keyword FULLTEXT queries to stay
  under InnoDB FTS result-cache limit (error 188 if combined). Runs via WP-CLI.

## Key files changed in recent sessions

| File | What changed |
|---|---|
| `publictech-core/inc/class-riding-cache-warmer.php` | Added `PCFRF_RO_ID=2` constant; `load_directory()` and `pcfrf_join()` now filter by `ro_id` |
| `publictech-core/inc/class-rest-api.php` | `mp_search()` now wires `sort` param to WP_Query `orderby`; added `riding ASC` and `year ASC` sort modes; `shape_mp()` uses `html_entity_decode` on title |
| `publictech-core/assets/js/search.js` | Added missing `[data-pt-sort]` click handler; syncs active state |
| `publictech-terminal/archive-member-parliament.php` | Added Riding A–Z and Year Elected sort links |
| `database/sync-current-mps.php` | Syncs WP posts to `op_core_electedmember WHERE end_date IS NULL` |
| `database/migrate-mp-schema.py` | Created `mp_persons` + `mp_mandates` tables |
| `database/fix-mandate-parliaments.py` | Back-filled parliament numbers |
| `database/populate-mp-term-start.php` | Wrote `mp_term_start` / `mp_term_start_year` meta |
