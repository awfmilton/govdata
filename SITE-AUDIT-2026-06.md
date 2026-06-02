# PublicTech / govdata — Site Audit & Recommendations

_Date: 2026-06-02 · Scope: full stack (`publictech-core` parent theme, `publictech-terminal`
child theme, `publictech-mcp` plugin, `database/` ETL) · Method: read-only review.
No application code was changed; this document is the deliverable._

This audit covers four areas you asked about: **security**, **performance**,
**user-interface / accessibility**, and **features & architecture**. Each finding cites a
`file:line` so it is directly actionable. Items are ordered by value within each section.
Secrets are referenced by location only — the literal values are **not** reproduced here.

---

## 0. Executive summary

The engine is genuinely well-built. The SQL layer whitelists columns and operators,
parameterises values, clamps `LIMIT`/`OFFSET`, hashes bearer tokens (SHA-256, compared with
`hash_equals`), escapes template output consistently, and aggressively validates chart specs.
The expensive aggregations are cron-warmed and cached. So the problems below are **not** in the
query core — they cluster in **secrets management, IP-based rate limiting, CSV export, frontend
asset hygiene, accessibility, and several headline features that look shipped but aren't reachable.**

**Fix this week (highest risk / lowest effort):**

1. **Rotate & purge the committed database password** — it's in 11 tracked files (§S1). _Critical._
2. **Stop trusting `X-Forwarded-For` / `CF-Connecting-IP`** in all four rate limiters (§S2). _High._
3. **Neutralise CSV formula injection** in exports (§S3). _High._
4. **Minify + conditionally load CSS/JS** and fix the directory-search N+1 (§P1, §P2, §P5). _High perf._
5. **Add a skip link and visible focus rings** — pervasive keyboard-a11y blockers (§U1, §U2). _High a11y._
6. Decide the fate of three "shipped but unreachable" features: **alerts have no create path**,
   **AI console is an unwired stub that renders blank**, **MCP `mp.spending` queries a retired schema** (§F1).

**Already excellent (keep doing):** parameterised multi-DB `find()`, token hashing, chart-spec
validation, two-layer cron-warmed caches, thorough responsive CSS, clear paid-feature gating,
one-time-shown API keys.

---

## S. Security

### S1 — CRITICAL: Live DB credentials committed to the repo (11 files)

The read-only `pt_read` password for `grantconts` at the public GCP IP `35.230.186.163` (and the
**writable** cache DB at `mysql.alexmilton.ca`) is hardcoded in version control:

- `test-query.php:3`, `test-init.php:3` — at web root, **no `ABSPATH` guard**, so they execute on a
  direct HTTP request if they ship under the docroot, printing connection/query errors.
- `database/mu-plugins/00-db-connections.php:39`
- 9 Python ETL scripts: `database/{rebuild-master,load-staging,normalize,apply-staging,capture-history,compare-staging,fix-mandate-parliaments,import-pcbfr-2023,migrate-mp-schema}.py`
- `.gitleaks.toml` **explicitly allowlists** the mu-plugin, so CI secret-scanning never flags it.

**Why it matters:** anyone with repo (or, for the two root scripts, web) access gets working
credentials to the government-data network. It's the same weak password everywhere, including a
writable host.

**Fix:**
1. **Rotate the `pt_read` password now**; lock the MySQL `host` grant to the WP server IP(s).
2. Move all credentials to `wp-config.php` / a secrets manager **outside** the repo; read ETL creds
   from env vars (several scripts already support `os.environ.get('DB_PASS', …)` — just remove the
   literal fallback).
3. Delete `test-query.php` / `test-init.php`; purge all of the above from git history
   (`git filter-repo` / BFG).
4. Remove the gitleaks allowlist for the mu-plugin once the secret is gone.

### S2 — HIGH: Rate limiting & reCAPTCHA are bypassable via spoofed forwarded headers

All four limiters take the **first** non-empty of `HTTP_CF_CONNECTING_IP`, `HTTP_X_FORWARDED_FOR`,
`REMOTE_ADDR`:

- `class-throttle.php:98` · `class-analytics.php:125` · `class-captcha.php:83` · `publictech-mcp/src/class-server.php:142`

Throttle buckets are keyed on this IP (`helpers.php:98 bucket_key()`), so an **unauthenticated**
attacker who sends a random `X-Forwarded-For` per request lands in a fresh per-minute bucket every
time — defeating the anon 10 rpm / 200 rpd caps, the MCP throttle, and the analytics flood guard.
The same spoofed value is sent to Google as `remoteip`.

**Fix:** default to `REMOTE_ADDR`. Only consult forwarded headers when the immediate peer is a
**configured, trusted proxy**, and then take the right-most untrusted hop. Gate `CF-Connecting-IP`
behind a check that the request actually originated from Cloudflare's published IP ranges.

### S3 — HIGH: CSV export is vulnerable to spreadsheet formula injection

`helpers.php:84-86` (`stream_csv_rows`, reached from `REST_API::export_query_csv()`,
`class-rest-api.php:629-681`) writes each cell as `(string) $value` with no neutralisation. The
exported government grant/contract data contains free-text fields (`recipient_legal_name`,
`agreement_title_en`, `description_eng`, …). A value beginning with `=`, `+`, `-`, `@`, tab, or CR
executes when an analyst opens the file in Excel/Sheets (and the UTF-8 BOM makes Excel auto-open it).

**Fix:** before `fputcsv`, prefix any cell whose first char is in `= + - @ \t \r` with a leading
apostrophe: `if (preg_match('/^[=+\-@\t\r]/', $cell)) { $cell = "'".$cell; }`.

### S4 — HIGH/MED: reCAPTCHA logs the raw token and fails open

`class-captcha.php:70` logs the live grecaptcha token to `error_log`; `verify()` returns `true`
(allow) when the secret is unconfigured, on network error, and in test mode (`:36-38, :49-53,
:69-72`). Combined with S2, anti-bot protection is weak.

**Fix:** remove the token from logs (log score/action only); make fail-open an explicit, filterable
decision and fail **closed** for the network-error case on sensitive actions.

### S5 — MED: `assert_read_only()` is a denylist with gaps

`class-security.php:64-77` blocks write verbs but not `SELECT … INTO OUTFILE` / `INTO DUMPFILE`
(file write) or `LOAD_FILE()`. It's defense-in-depth (the grant should be `SELECT`-only and lack
`FILE`), but the gap is worth closing. Prefer the **structured-filter** path (`build_where`) over any
raw-SQL path as the primary design (see F3).

### S6 — MED: Chart delete is an irreversible hard-delete; update/delete skip throttling

`class-charts-rest.php:489-493` calls `wp_delete_post($id, true)` (force, bypasses trash); `update`
and `delete` don't call `enforce_rest`, and `read` bumps `pt_views` on every unauthenticated hit with
no de-dupe. Ownership itself is correctly enforced via `require_owner` (and CSRF via nonce/bearer).

**Fix:** use `wp_trash_post()` / `wp_delete_post($id, false)`; add `Throttle::enforce_rest()` to
`update`/`delete`; cool-down the `pt_views` increment.

### S7 — MED: Chart moderation uses the broad `edit_others_posts` capability

`class-charts-rest.php:344,403` lets any Editor edit/delete/read **any** user's private chart.
If unintended, gate on a dedicated `manage_publictech_charts` capability instead.

### S8 — MED (privacy): Raw IP + full fingerprint collection

- `class-analytics.php` stores raw IP + full user-agent per event with no retention/anonymisation
  (inconsistent with the throttle layer, which deliberately hashes IPs) — a PIPEDA consideration.
- `publictech-terminal/assets/js/data-explorer.js:69-134` collects a detailed browser fingerprint
  (UA, languages, hardware, screen, timezone, connection) and beacons it on view / session end.

**Fix:** truncate/hash stored IPs, add a retention-purge cron, document on a privacy/methodology page,
and add a consent gate (or remove) the fingerprint beacon.

### S9 — LOW: Author-enumeration hardening gaps & error-message identifier echo

`class-hardening.php:88-96` blocks `?author=N` but not user sitemaps / oEmbed author data;
`helpers.php:19` echoes the rejected identifier into the exception message (log it server-side
instead).

> **Verified safe (checked, not vulnerable):** parameterised `find()`/`build_where`, whitelisted
> ORDER BY + clamped LIMIT/OFFSET, MATCH…AGAINST bound values (`class-entity-reports.php`), SHA-256
> bearer tokens via `hash_equals` (`class-access-tiers.php`), chart-spec enum/range validation,
> alert email built from `user_email` (no injection), SSRF guard on chart source endpoints, and CSRF
> on token rotation (`check_admin_referer` + capability).

---

## P. Performance

### P1 — HIGH: JS/CSS ship unminified, no build step (~200 KB+ uncompressed)

No asset is minified; there's no minify pipeline feeding the enqueued paths. Heaviest:
`query-builder-ui.js` (~59 KB), `terminal.css` (~45 KB), `d3-charts.js` (~18 KB),
`data-explorer.js` (~18 KB), `chart-builder-ui.js` (~15 KB).

**Fix:** add esbuild/terser + cssnano producing `*.min.*`, enqueue minified (gate on `SCRIPT_DEBUG`).
~60–70 % byte reduction before gzip. Confirm gzip/brotli is on at the server.

### P2 — HIGH/MED: `terminal.css` (45 KB) is render-blocking on every page

`publictech-terminal/functions.php:26-45` enqueues terminal/overrides/responsive CSS (~55 KB)
unconditionally in `<head>` — even on non-terminal pages. The JS pipeline is conditional
(`needs_d3()`/`needs_billboard()`); the CSS isn't.

**Fix:** split into a small always-on core + a conditionally-enqueued app chunk; minify; consider
critical-CSS inlining above the fold.

### P3 — HIGH: N+1 in `mp_search` (the hottest interactive endpoint)

`class-rest-api.php:205-221` runs `WP_Query` with `'fields' => 'ids'`, which does **not** prime
meta/term caches; `shape_mp()` (`:685-700`) then does 2× `wp_get_object_terms` + 4× `get_post_meta` +
`get_permalink` **per MP** → ~72 queries at `per_page=24`, on every keystroke-debounced search, facet,
sort, and page.

**Fix:** prime once — switch to `'fields' => 'all'`, or keep ids and call `update_postmeta_cache($ids)`
+ `update_object_term_cache($ids,'member-parliament')` before mapping. ~72 → ~4 queries.

### P4 — MED/HIGH: `mp_search` default sort/filter hits unindexed `wp_postmeta`

`class-rest-api.php:190-218` orders by a `total_spend` meta clause (JOIN + filesort on a non-indexed
cast) and filters `postal` with a leading-wildcard `LIKE` (unindexable). Tolerable at ~338 MPs;
scales badly.

**Fix:** promote `total_spend` / `pt_mp_district` / `mp_term_start` to real indexed columns (a small
`wp_pt_mp_index` table synced on import), or precompute the default ordering into a transient ID list.

### P5 — MED: Standalone Query Builder still pays the ~0.3 s REST column fetch

The Data Explorer was fixed to inline columns (`template-data-explorer.php:214-218` via
`REST_API::columns_for()`), killing the ~1,152 ms blocked round-trip from the git history. The
**Query Builder** template (`template-query-builder.php:160-175`) was **not** given the same
treatment, so `query-builder-ui.js:797-839` still fetches `/schema/{schema}/{table}` after all page JS
loads (full WP bootstrap tax).

**Fix:** inline `window.PT_QB_COLUMNS = REST_API::columns_for(...)` when schema+table are preset,
exactly as the data-explorer template does; keep the fetch fallback for table changes.

### P6 — MED: `Riding_Cache::report_by_slug()` skips the object cache its sibling uses

`class-riding-cache.php:105-111` calls `fetch_payload()` directly with no caching, while
`report_by_number()` (`:83-98`) wraps it in `wp_cache_get/set` (5 min). The riding-report template
resolves via **slug**, so every render runs an uncached `SELECT` against the cache DB for a row that's
static between weekly warms.

**Fix:** mirror the number-path caching in the slug path (key `report_s_<slug>`, same TTL).

### P7 — MED: SRI not applied by default for CDN bundles (supply-chain + repeatability)

`class-asset-pipeline.php:41-54` adds `integrity`/`crossorigin` only if an operator supplies a hash
via filter, and ships none — so D3, Billboard, topojson, d3-sankey, compromise.js load from jsDelivr
with no SRI. Versions are already hard-pinned, so this is easy to fix. (See F4 — self-host.)

### P8 — MED: `Connection::find()` uses `SELECT *` + deep `OFFSET` pagination

`class-multi-db.php:174-202` selects every column (the stream only exposes ~6–7 whitelisted ones —
wasted bytes) and uses `LIMIT/OFFSET`, which degrades linearly at high offsets over 1 M+ row tables.

**Fix:** select only the whitelisted columns (the `$columns` map is already available); move the
streaming UI to keyset/seek pagination (`WHERE sort_col < :last … LIMIT n`).

> **Verified already-optimised:** dashboard stats (two-layer object cache + 25 h transient,
> cron-warmed, inlined as `PT_DASH`), entity reports (12 h transient + MATCH…AGAINST, 183 s→<1 s),
> `top_recipients` covering index (67 s→~1 s), sensible `Cache-Control` headers, `private, no-store`
> + `Vary` on tier-restricted streams, transient-cached activity/forum feeds. `health()` is
> object-cached 30 s and only runs on `/status` (not polled) — no per-page DB cost.

---

## U. User interface & accessibility

A government-data site carries a strong accessibility obligation (WCAG 2.x AA; in Canada, also the
spirit of the ACA/provincial AODA). The responsive layer is excellent, but there are pervasive
keyboard/screen-reader blockers.

### High (a11y blockers / broken UX)

- **U1 — No skip link / no main landmark before nav.** Keyboard users tab the entire rail+nav on
  every page. `header.php`, `parts/header-rail.php`. Add a visually-hidden-until-focused skip link in
  `wp_body_open`; give each `<main>` `id="pt-main" tabindex="-1"`. (`.pt-sr-only` already exists at
  `overrides.css:110`.) _WCAG 2.4.1._
- **U2 — Focus indicators removed/invisible site-wide.** `outline:0` with only a 12 %-opacity glow
  (`--accent-soft`); no `:focus-visible` anywhere. `terminal.css:152,163,266,310,803-816`,
  `bbpress.css:122`. Add global `*:focus-visible{outline:2px solid var(--accent);outline-offset:2px}`.
  _Single most pervasive issue. WCAG 2.4.7/2.4.11._
- **U3 — MP photos hidden from screen readers.** `single-member-parliament.php:61` puts
  `aria-hidden="true"` on the portrait wrapper, discarding the carefully-set `alt`. Remove it from the
  real `<img>` container. _WCAG 1.1.1._
- **U4 — Async results never announced.** Stream table, AI console (`#aiResponse`), directory search,
  data-explorer, query-builder all swap content with no live region. `template-stream.php:40-55`,
  `mcp-console.js:26-81`, `search.js:65-88`, `data-explorer.js:263-268`, `query-builder-ui.js:1078`.
  Add `role="status" aria-live="polite"` to count/foot containers; `role="alert"` for errors.
  _WCAG 4.1.3._
- **U5 — Click-only `<div>`/href-less "buttons" are keyboard-inoperable.** AI suggestion chips
  (`mcp-console.js:98`), customizer swatches (`chart-customizer-ui.js:33`, color-only, no name),
  directory sort links (`archive-member-parliament.php:95`). Make them real `<button>`s with
  `aria-pressed`/`aria-current`; label swatches. _WCAG 2.1.1, 4.1.2, 1.4.1._
- **U6 — Customizer "dialog" is a non-conformant modal.** No focus move-in, no Tab trap, no Escape,
  no focus restore, missing `aria-modal`. `customizer-window.php:16`, `chart-customizer-ui.js:12-24`.
- **U7 — AI console renders a blank chart on the "not configured" stub.** Stub returns 200 + empty
  rows (`class-rest-api.php:513-519`); UI defaults to the chart tab and shows nothing — first-run looks
  broken. Detect the notice payload and show an inline message / default to the SQL tab.

### Medium (selected — full list in agent notes)

- **U8** `<select onchange="this.form.submit()">` breaks keyboard option browsing — `template-stream.php:27`, `template-chart-gallery.php:68,76`.
- **U9** Sortable `<th>`s aren't real controls, no `aria-sort`, no `scope` — `template-stream.php:43-48`, `stream-table.js:32-39`.
- **U10** Tab/segmented widgets lack `role=tab`/`aria-selected`/`aria-pressed` across profile, AI, data-explorer, chart-builder.
- **U11** Forum filter buttons (`template-forum.php:46-49`) are dead — wired to nothing.
- **U12** `aria-busy="true"` hardcoded on static server-rendered results — `archive-member-parliament.php:102`.
- **U13** Profile save gives no success/error feedback; setting fields use `<div class="label">` not `<label for>` — `template-profile.php:26,107-125`.
- **U14** Light-theme status colors fail AA as small text: `--pos` 3.52:1, `--warn` 3.34:1 (`terminal.css:46-51`). Darken them.
- **U15** Secondary text `--ink-3` ~2.2–2.5:1 in both themes on real microcopy (counts, timestamps). Promote to ≥4.5:1.
- **U16** `prefers-reduced-motion` doesn't cover the 90 s ticker marquee, KPI count-up, or bar growth — `terminal.css:657`, `dashboard.js:27-74`. _WCAG 2.3.3/2.2.2._
- **U17** Charts have no text alternative / data-table fallback (riding-report is the lone good example). Add `role="img"`+`aria-label` and/or a hidden data table.
- **U18** Gallery "Fork" `fetch` has no `.catch` — button can hang on "forking…" forever (`template-chart-gallery.php:153-167`).

### Low (polish)

- **U19** `role="status"` wraps the whole live clock rail (announcement noise) — `header-rail.php:14`.
- **U20** Flash of dark theme before stored "light" applies (FOUC) — inline a tiny `<head>` reader of `localStorage['pt-theme']`.
- **U21** Theme/customizer toggles announced only as glyphs — add `aria-label`.
- **U22** Pagination glyphs `‹ › ◇` have no accessible text; decorative `◇`/`◈` heading prefixes read aloud.
- **U23** Per-page `<h1>` is always the site name; content pages start at `<h2>`. Give each page one descriptive `<h1>`.
- **U24** `search.js:76` re-renders MP cards **without** the `aria-hidden` the server markup uses — AJAX/SSR drift; heavy inline styles in report templates.

> **Done well:** thorough responsive CSS (1280/960/720/480 breakpoints, horizontal-scroll tables,
> collapsing facets), real empty/loading/error states in the query builder & data explorer, clear
> paid-feature gating, correct theme-toggle persistence + `aria-pressed`, one-time API-key reveal.

---

## F. Features & architecture

### F1 — "Shipped" features that aren't actually reachable (fix or hide)

- **Daily email alerts are dead.** The cron (`class-alerts.php`) only *reads* `pt_saved_alerts`;
  **nothing** writes it (saved queries use a different key, `pt_saved_queries`, never promoted). No
  REST route / template / JS creates an alert. _Either build the create path or stop advertising it._
- **AI Console / text-to-SQL is an unwired stub** in two places with different contracts
  (`REST_API::ai_query` and `nlp_query`) and renders blank on first run (U7).
- **MCP `mp.spending` is broken** — `class-tools.php:147,153` still casts `(int)` and queries the
  retired `pt-finance-01.expenditures`; the REST path was fixed to `(string)` against
  `pt-cache-02.mp_expenditure_periods`. Same stale `(int)`/`riding` pattern lingers in
  `single-member-parliament.php` and `compare-mps.php`.
- **`/pricing` and `/upgrade` CTAs 404** — rendered in 5 templates (`template-{chart-gallery,
  chart-builder,ai-console,profile}.php`, `template-data-explorer.php`) but no page or billing exists.
- **Fabricated data on MP profiles** — `single-member-parliament.php:287-290` builds the "Funding Flow
  Network" nodes/links/weights from `Math.random()`. On a transparency site this is a credibility/legal
  risk; replace with the real category series (already fetched via `/mp/{id}/spend`) or an honest empty
  state. "Votes Cast" is likewise hardcoded toward 0.
- **Anomaly engine built but never surfaced** — `Anomalies` (Benford + weekend/holiday flags) writes
  `wp_options` but `flags_for()` is called nowhere; the "Open flags" KPI reads unrelated meta.

### F2 — High-value new features (audience: journalists, analysts, researchers, citizens)

| Feature | Value | Effort | Hook |
| --- | --- | --- | --- |
| **Wire "Save query as alert" + RSS/Atom + webhook delivery** | Biggest free→paid hook; cron already works | S–M | `POST/DELETE /publictech/v1/alerts` beside `queries_*`; toggle in `template-profile.php`; capability-URL RSS route |
| **Surface anomaly/outlier flags** on MP/riding/entity pages | The "audit terminal" identity; investigative leads | M | Run `Anomalies::analyze_batch()` in the warmers; render in existing "Data Notes" panels; add `anomalies.scan` MCP tool |
| **Provenance/methodology page + per-page "last updated"** | Citeability/trust; data exists (`generated_at`) | S | `/methodology` page; surface `cache_meta.generated_at` in the header rail & reports |
| **Citation / export-to-source deep links per row** | Turns a viewer into a research tool | S | `ref_number` already kept; render `open.canada.ca` link + "Cite this" |
| **Year-over-year & cross-riding/entity comparison** | Most-requested lens; half-present in `compare-mps.php` | M | Generalise comparator over cache snapshots; build a compare-picker UI; compute YoY from `by_year` |
| **Embeddable charts + shareable deep links** | How transparency data spreads | M | `/embed/chart/{id}` + oEmbed; encode filter state in URL |
| **Bilingual EN/FR delivery** (Official Languages) | Effectively mandatory for public-sector/Institutional | L | See F5 — needs i18n plumbing first; data already carries `*_fr` columns |
| **Bulk/full-dataset download + accessible data tables** | Open-data credibility; a11y for stream table | M | Cron-built gzipped snapshot; `<table>`/`<th scope>`+`aria-live` in `stream-table.js` |
| **Structured data (schema.org), Open Graph, sitemaps** | Organic discovery (Google Dataset Search) for exactly this audience | S–M | JSON-LD `Person`/`Dataset` from MP/riding/entity templates; OG in `header.php`; CPTs in `wp_sitemaps` |

### F3 — Architecture: safely wire text-to-SQL

The guardrails already exist (`assert_read_only`, `build_where` column/op allowlist, read-only PDO,
per-tier `max_rows`). **Preferred design:** have the LLM return **structured filters**
(`col/op/val` against `publictech_columns_*` aliases) and run them through `Connection::find()` — this
never trusts model-generated SQL. If raw SQL is ever required, force a `LIMIT`, run
`assert_read_only()` (denylist — opt-in only), and execute on a `SELECT`-only grant. Consolidate the
two stub handlers into one documented contract; ship `publictech_text_to_sql` as an optional mu-plugin.

### F4 — Architecture: self-host D3/Billboard/topojson/d3-sankey with SRI

Every chart page loads four scripts from jsDelivr with no default SRI (P7). A CDN outage/compromise
breaks or poisons every visualization. The seams already exist (`publictech_{d3,billboard,topojson,
d3_sankey}_url` + `*_sri` filters in `class-asset-pipeline.php`). Vendor the pinned versions into
`publictech-core/assets/vendor/` and set the URL filters by default. _Effort: S._

### F5 — Architecture: make i18n actually loadable

~526 strings are wrapped in `__()`/`esc_html_e()`, but there is **no `languages/` dir and no
`.pot/.po/.mo`**, so `load_theme_textdomain()` loads nothing; JS bundles never call
`wp_set_script_translations()`; and the report/data-explorer templates contain large amounts of
**unwrapped English** (`◇ Data Notes`, `METHODOLOGY`, `SOURCE:`, the riding-report "Era Comparison").
**Fix (S plumbing):** create the `languages/` dirs; add a `make pot` (wp-cli i18n make-pot);
`wp_set_script_translations()` for each JS handle in `Asset_Pipeline`; sweep the unwrapped strings.
The translation *content* (F2 bilingual) is the larger effort on top.

### F6 — Architecture: test & observability gaps

- **Integration tests:** 11 PHPUnit files cover pure functions well, but `REST_API` handlers, MCP
  `Server::rpc`, `Throttle`, alerts, and the warmers have **zero** coverage (CI only curls `/status`
  and `/manifest`). The `(int)`→`(string)` finance regression that shipped to three files is exactly
  what integration tests catch. Add a WP-test-suite job exercising the request paths.
- **Observability:** failures `error_log()` and vanish. Add an admin status page reporting last-warm
  timestamps (warmers already write `cache_meta`/transients), last cron run, and last error per job;
  fire `do_action('publictech_log', …)` for routing to Sentry/Logtail. Document ETL cadence and expose
  "source data as of" alongside "cache as of".

### F7 — Monetization / tier gaps

- **No billing path** — tier is admin-set only; `add_theme_support('woocommerce')` is declared but
  unused. Add Stripe Checkout/Customer-Portal (or WooCommerce Subscriptions) writing `pt_tier` on
  `invoice.paid` / status change; build `/pricing` from `Access_Tiers::tiers()` (prices already there);
  revoke MCP token on cancel.
- **Self-serve REST API keys + OpenAPI** — README advertises an "open API" but programmatic access is
  MCP-JSON-RPC only. Reuse `Access_Tiers::resolve_token()` to bearer-auth the `publictech/v1` routes;
  expose a key panel in `template-profile.php` (the "Developer API key" UI already exists); publish an
  OpenAPI spec + `/api-docs`.
- **Make tiers legible** — Analyst ($19) vs Newsroom ($79) are near-indistinguishable. Add capability
  flags to `tiers()` (`alerts.webhook`, `export.bulk`, `embed.unbranded`, `seats`) once the underlying
  features exist; keep free genuinely useful so the story is "more/faster/automate," not "see anything."
- **Team/seat accounts** for Newsroom/Institutional — `pt_workspace` CPT already models
  `pt_collaborator_ids`; add an org dimension to the throttle bucket for a pooled budget.

---

## Appendix: quick-win cheat sheet (high value ÷ effort)

1. Rotate + purge committed DB password; delete root test scripts (§S1).
2. CSV formula-injection prefix in `stream_csv_rows` (§S3).
3. Trusted-proxy IP resolution for all four limiters (§S2).
4. Global `:focus-visible` ring + skip link (§U1, §U2).
5. Remove `aria-hidden` from MP portrait; add `aria-live` to result counts (§U3, §U4).
6. Prime meta/term caches in `mp_search` (§P3).
7. Inline `PT_QB_COLUMNS` on the query-builder template (§P5); cache `report_by_slug` (§P6).
8. Minify CSS/JS + conditional `terminal.css` (§P1, §P2).
9. Self-host D3/Billboard + ship SRI (§F4, §P7).
10. Replace `Math.random()` MP-profile network with real data or an empty state (§F1).
11. Decide alerts/AI-console/`/pricing` fate; fix MCP `mp.spending` schema drift (§F1).
12. schema.org + Open Graph + sitemap; methodology + "last updated" page (§F2).
13. i18n plumbing (`languages/` + `.pot` + `wp_set_script_translations`) (§F5).
