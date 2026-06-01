# MPs & Ridings Over Time — Implementation Plan

> Status: **planned, not started.** The site works well as-is. This document
> captures the design so we can pick it up later. Authored 2026‑05‑31.

## Why this exists

Two problems share one root cause — the MP/riding data is currently a *single
snapshot*, but the reality is *temporal* (boundaries and MPs change over time):

1. **165 of 555 MP pages show no "Riding Grants & Contributions."** Their
   ridings are 2023‑redistribution ridings (e.g. *Burlington North—Milton West*,
   *Langley Township—Fraser Heights*) that don't exist in our `pcbfr` table,
   which is the **2013 Representation Order (338 ridings)**. The current MPs are
   on the **2023 Representation Order (343 ridings)**, so the name lookup finds
   nothing. (The other 390 MPs resolve because their riding names survived the
   redistribution — those panels populate fine after the riding-cache warm.)
2. **MP roster is stale** (not updated since ~winter 2025) and we want to show
   MPs and ridings *over time*, with the ability to filter by **MP and/or
   timeframe and/or riding**.

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

New / changed tables (in `grantconts`, alongside the existing schema):

```
representation_orders (id, code, effective_start, effective_end, riding_count)
ridings               (id, ro_id, fed_number, name_en, name_fr)
                      -- "Milton" (2013) and "Burlington North—Milton West" (2023)
                      -- are DIFFERENT riding rows, each tied to its ro_id.
pcbfr                 (postal_code, ro_id, riding_number, …)
                      -- add ro_id; tag all existing rows as the 2013 RO.
mps                   (id, full_name, photo_url, …)        -- one row per PERSON
mp_mandates           (mp_id, riding_id, parliament, party, start_date, end_date)
                      -- the join entity; a person can hold a seat multiple
                      -- times, in differently-named ridings across eras.
```

`mp_mandates` is what makes every "over time" query work; `ro_id` is what lets
spending attribute to the correct era.

### How the three UI filters become queries
- **By MP** → their mandate(s) → riding(s) + term dates → spending in that riding for that era.
- **By timeframe** → mandates overlapping the period → the MPs/ridings active then.
- **By riding** → its spending + every MP who represented it across eras.

## Build sequence

### Phase 1 — Temporal foundation *(prerequisite; non‑destructive)*
- Create `representation_orders`; add `ro_id` to `ridings` and `pcbfr`; tag all
  existing `pcbfr`/`ridings` rows as the 2013 RO.

### Phase 2 — Boundaries + postal mapping *(immediately fixes the 165 MP pages)*
- Load 2003 + 2023 FED riding lists into `ridings`.
- Load/build PCFRF for the 2003 and 2023 ROs into `pcbfr` (1987 RO needs none —
  no spending that era).
- Re‑warm the riding cache → the 165 broken MP pages resolve once 2023 is in.

### Phase 3 — MP model + import
- Create `mps` + `mp_mandates`.
- **Current MPs:** extend the existing OurCommons scraper
  (`C:\Users\user\Documents\Web Scraper OUR COMMONS`) + `database/import-mps.php`
  to capture term start/end + parliament.
- **Historical MPs (1989+):** backfill from the **Library of Parliament
  PARLINFO** open dataset (every MP with riding, party, and date ranges).

### Phase 4 — Era‑aware spending
- Riding‑cache warmer computes rollups **per RO** (each agreement attributed to
  its postal code's riding in each relevant era).
- Default view = temporally correct (agreement summed into its signing era);
  the era selector remaps everything onto one chosen boundary set.

### Phase 5 — UI
- Filters for **MP / timeframe / riding**, all driven by `mp_mandates`, plus an
  era dropdown (default = temporally correct).

## Dependencies / what unblocks what
- **Phase 2 needs the 2023 PCFRF.** Sourcing options:
  1. Request from **Elections Canada** geography services (Maps Corner) — same
     format as the current file. The PCFRF is *not* on the open-data portal
     (it shows only as a "suggested dataset"); it's distributed on request.
  2. **Build it from open data:** the 2023 FED boundary files are openly
     downloadable (open.canada.ca dataset `18bf3ea7-1940-46ec-af52-9ba3f77ed708`,
     shapefile/KMZ/GDB). Spatially join postal‑code lat/longs against those
     boundaries to produce `postal_code → 2023 FED`. Fully open; more work.
  3. **StatCan PCCF** has a `FED2023` field — via academic DLI access or purchase.
  - Recommendation: try (1); fall back to (2). The boundary-file importer can be
    written if we go that route.
- **Phase 3 needs** the PARLINFO export + the OurCommons scraper extended for dates.
- Phases 1, 4, 5 are code, buildable against the model.

## Suggested starting point
Phases **1 + 2** are self‑contained: they fix the 165 broken MP pages now and
lay the temporal foundation without touching anything else. Everything else can
follow incrementally.

## Current state (as of this plan)
- Riding cache warmed: 338 ridings / 1,071,161 agreements (2013 RO).
- 390/555 MP pages resolve; **165 blocked** pending the 2023 PCFRF (Phase 2).
- `master_table` rebuilt (1,264,823 rows, id‑keyed) + normalized tables; Cloud
  SQL tuned (16 GB RAM, ~9 GB buffer pool, FTS limit 4 GB). See
  `memory/grantconts-rebuild-normalization.md`.
- Riding-cache keyword aggregation uses a **per‑keyword FULLTEXT** query
  (one MATCH per keyword) to stay under InnoDB's FTS result-cache limit; the
  combined-query version hit error 188. Runs via WP‑CLI (no web timeout).
