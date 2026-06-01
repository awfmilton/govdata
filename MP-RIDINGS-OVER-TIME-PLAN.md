# MPs & Ridings Over Time — Implementation Plan

> Status: **Phase 3 partially complete.** Updated 2026‑06‑01.

## Why this exists

Two problems share one root cause — the MP/riding data is currently a *single
snapshot*, but the reality is *temporal* (boundaries and MPs change over time):

1. **165 of 394 MP pages show no "Riding Grants & Contributions."** Their
   ridings are 2023‑redistribution ridings (e.g. *Burlington North—Milton West*,
   *Langley Township—Fraser Heights*) that don't exist in our `pcbfr` table,
   which is the **2013 Representation Order (338 ridings)**. The current MPs are
   on the **2023 Representation Order (343 ridings)**, so the name lookup finds
   nothing. (The other MPs resolve because their riding names survived the
   redistribution — those panels populate fine after the riding-cache warm.)
2. **MP roster needs to show MPs and ridings *over time***, with the ability to
   filter by **MP and/or timeframe and/or riding**.

## Decisions (locked)

- **Spending attribution:** *temporally correct by default* — each agreement is
  attributed to the riding boundary valid **at the time the agreement was
  signed** — **plus a user‑selectable era override** so a viewer can remap all
  spending onto a single boundary set (e.g. "show everything on today's ridings").
- **Historical depth:** back to **1989**.

## Representation Orders to model

1989 spans four boundary eras. Spending data only starts in 2004, so a
postal→riding mapping (PCFRF) is only required for eras that have agreements.

| Rep. Order | In effect (federal elections) | Ridings | MP history | PCFRF / spending |
|------------|-------------------------------|--------:|:----------:|:----------------:|
| **1987 RO** | 1989–2003 | 295 | ✅ | ❌ (no agreements pre‑2004) |
| **2003 RO** | 2004–2015 | 308 | ✅ | ✅ |
| **2013 RO** | 2015–2025 | 338 | ✅ | ✅ — *this is our current `pcbfr`* |
| **2023 RO** | 2025– | 343 | ✅ | ✅ — *file still to be sourced* |

Temporally‑correct attribution maps each agreement by its `agreement_start_date`
(or `year`): **2004–2015 → 2003 RO**, **2015–2025 → 2013 RO**, **2025+ → 2023 RO**.

## Data model

Tables in `grantconts` (new and existing):

```
representation_orders  (id, code, effective_start, effective_end, riding_count)
                       -- DONE: 2013_RO and 2023_RO seeded.

mp_persons             (id AUTO_INCREMENT, op_politician_id, ourcommons_id,
                        first_name, last_name, gender, photo_url, source_url,
                        email, postal_code, const_phone, const_fax,
                        ottawa_phone, ottawa_fax)
                       -- DONE: 1,345 rows. One row per human being.
                       -- op_politician_id links to op_core_politician (OP dataset).
                       -- ourcommons_id holds legacy MP-xxx key from scraper.

mp_mandates            (id AUTO_INCREMENT, mp_id → mp_persons.id,
                        op_member_id → op_core_electedmember.id,
                        riding_id → op_core_riding.id,
                        party, parliament, start_date, end_date)
                       -- DONE: 1,892 rows. Parliaments 35–45 (1994–present).
                       -- end_date IS NULL = currently serving (365 active rows).

mps                    (mp_id VARCHAR PK, first_name, last_name, district_name,
                        party, province, email, photo_url, contact fields…)
                       -- LEGACY: flat one-row-per-snapshot table.
                       -- Was 555 rows (duplicates); cleaned to 394 published WP posts.
                       -- Source: OurCommons scraper (MP-prefixed) +
                         accidental HIST- re-imports (since removed from WP).
                       -- See OPEN DECISION below before touching this table.
```

`mp_mandates` is what makes every "over time" query work; `ro_id` (Phase 2) is
what lets spending attribute to the correct era.

### How the three UI filters become queries
- **By MP** → `mp_persons` → their `mp_mandates` → riding(s) + term dates → spending in that riding for that era.
- **By timeframe** → mandates overlapping the period → the MPs/ridings active then.
- **By riding** → its spending + every MP who represented it across eras.

---

## OPEN DECISION — old `mps` table vs `mp_persons`

Before Phase 3 can be completed, we need to decide how the WordPress import and
the riding-cache warmer source their MP data going forward.

### Option A — Keep `mps`, wire it to `mp_persons`

The old flat `mps` table stays as the primary source for the WP import.
The OurCommons scraper continues writing into `mps`. We add a column
`mp_persons_id` to `mps` so each row cross-references the new normalized table.
Historical queries use `mp_persons`/`mp_mandates`; current-MP queries use `mps`.

**Pros:** minimal disruption to `import-mps.php` and the WP sync code.
**Cons:** two overlapping tables for "current MPs" data; the 20 OurCommons MPs
that didn't match an OP politician still need to be reconciled manually.

### Option B — Retire `mps`, use `mp_persons` as the canonical source

`mp_persons` becomes the single source of truth. The OurCommons scraper is
updated to upsert into `mp_persons` (matching on name or a stable OurCommons
person ID). `import-mps.php` is rewritten to read from `mp_persons` +
`mp_mandates`. The old `mps` table is kept as `mps_backup` and frozen.

**Pros:** clean architecture; no duplication; `mp_persons.id` (integer) becomes
the stable foreign key used by WordPress (`external_mp_id` meta).
**Cons:** more work — `import-mps.php`, the WP sync, and the riding-cache
warmer all need updating; the 20 name-mismatch OurCommons MPs need resolving
before retiring `mps`.

### Known gap regardless of choice

20 OurCommons MPs (of 336) did not match any `op_core_politician` row by
first+last name during the migration. They were inserted into `mp_persons`
without an `op_politician_id`, so they have no mandate history from the OP
dataset. These need to be manually reconciled (name spelling differences, hyphens,
accents, etc.) or matched by OurCommons person URL.

---

## Build sequence

### Phase 1 — Temporal foundation ✅ DONE
- `representation_orders` created; seeded with 2013_RO and 2023_RO.
- *(Note: `ro_id` column not yet added to `pcbfr`/`ridings` — that is Phase 2 work.)*

### Phase 2 — Boundaries + postal mapping *(immediately fixes the 165 MP pages)*
- Load 2003 + 2023 FED riding lists into `ridings` (or use `op_core_riding` — see Phase 3 decision).
- Load/build PCFRF for the 2003 and 2023 ROs into `pcbfr`.
- Re‑warm the riding cache → the 165 broken MP pages resolve once 2023 is in.

### Phase 3 — MP model + import *(partially done)*

#### Done
- `mp_persons` created and populated (1,345 rows):
  - 1,323 from `op_core_politician` (politicians with at least one OP mandate).
  - 20 from OurCommons scraper records that had no name match in OP data.
  - 2 from HIST- imports not found anywhere else.
  - OurCommons contact info (email, phone, address, photo) merged in for
    316 matched MPs.
- `mp_mandates` created and populated (1,892 rows):
  - 1,870 from `op_core_electedmember`, parliament numbers back-filled from
    session start dates (parliaments 35–45, covering 1994–present).
  - 22 parliament-45 placeholder rows for MPs present in HIST- data but
    absent from the OP elected-member records.
- WordPress directory cleaned: 555 duplicate posts → 394 (one per unique MP name).
- `Ya'ara Saks` name-entity bug fixed in REST API (`html_entity_decode` on
  `get_the_title()` in `class-rest-api.php`).
- Diagnostic and migration scripts committed:
  - `database/diagnose-mp-dupes.php`
  - `database/dedup-mp-posts.php`
  - `database/migrate-mp-schema.py`
  - `database/fix-mandate-parliaments.py`

#### Still to do (pending OPEN DECISION above)
- **Resolve the OPEN DECISION** — Option A or B.
- **Reconcile the 20 name-mismatch OurCommons MPs** so they gain OP mandate
  history (check OurCommons person URLs against `op_core_politician.slug`).
- **Update WordPress posts** — change `external_mp_id` meta from `MP-xxx`
  strings to `mp_persons.id` integers (a WP-CLI script already drafted in
  `dedup-mp-posts.php`; needs the decision resolved first).
- **Rewrite `import-mps.php`** to write into `mp_persons`/`mp_mandates`
  instead of the flat `mps` table (Option B) or add `mp_persons_id` FK
  column to `mps` (Option A).
- **Historical depth before parliament 35** — the OP dataset covers parliaments
  35–45 (1994–present). Parliaments 28–34 (1974–1993) need the PARLINFO
  backfill to reach the 1989 target depth.
- **Clean up HIST- rows** from `mps` — the 219 HIST- rows (61 unique people,
  imported multiple times by a buggy run) are still in the `mps` table. They
  are no longer used by WordPress but should be removed or the table should
  be retired (ties back to the OPEN DECISION).

### Phase 4 — Era‑aware spending
- Riding‑cache warmer computes rollups **per RO** (each agreement attributed to
  its postal code's riding in each relevant era).
- Default view = temporally correct (agreement summed into its signing era);
  the era selector remaps everything onto one chosen boundary set.

### Phase 5 — UI
- Filters for **MP / timeframe / riding**, all driven by `mp_mandates`, plus an
  era dropdown (default = temporally correct).

---

## Dependencies / what unblocks what

- **Phase 2 needs the 2023 PCFRF.** Sourcing options:
  1. Request from **Elections Canada** geography services (Maps Corner) — same
     format as the current file. The PCFRF is *not* on the open-data portal
     (it shows only as a "suggested dataset"); it's distributed on request.
  2. **Build it from open data:** the 2023 FED boundary files are openly
     downloadable (open.canada.ca dataset `18bf3ea7-1940-46ec-af52-9ba3f77ed708`,
     shapefile/KMZ/GDB). Spatially join postal‑code lat/longs against those
     boundaries to produce `postal_code → 2023 FED`. Fully open; more work.
     The `build-pcbfr-2023.py` script already does this — output just needs
     importing into the DB with a `2023_RO` tag.
  3. **StatCan PCCF** has a `FED2023` field — via academic DLI access or purchase.
  - Recommendation: (2) is already built — run `build-pcbfr-2023.py` and import.
- **Phase 3 completion needs** the OPEN DECISION resolved + 20 name-mismatch MPs
  reconciled.
- **Phase 3 completion also unblocks** Phase 4 (riding cache needs stable `mp_persons.id`
  foreign keys before era-aware rollups can be attributed per MP).
- Phases 4 and 5 are code, buildable once Phase 3 schema is locked.

---

## Current state (as of 2026‑06‑01)

- WordPress directory: **394 published MP posts**, no duplicates, names display correctly.
- `mp_persons`: **1,345 rows**, `mp_mandates`: **1,892 rows** (parliaments 35–45).
- Riding cache warmed: 338 ridings / 1,071,161 agreements (2013 RO only).
- 165 MP pages still blocked pending 2023 PCFRF (Phase 2).
- `master_table` rebuilt (1,264,823 rows, id‑keyed) + normalized tables; Cloud
  SQL tuned (16 GB RAM, ~9 GB buffer pool, FTS limit 4 GB). See
  `memory/grantconts-rebuild-normalization.md`.
- Riding-cache keyword aggregation uses a **per‑keyword FULLTEXT** query
  (one MATCH per keyword) to stay under InnoDB's FTS result-cache limit; the
  combined-query version hit error 188. Runs via WP‑CLI (no web timeout).
- `build-pcbfr-2023.py` exists and produces the 2023 PCFRF output file —
  Phase 2 is a database import away.
