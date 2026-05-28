# PublicTech — Government Data Audit Suite

A WordPress stack that turns Canadian government spending, grants, and
contracts data into a fast, accessible web terminal. Visitors can search
Members of Parliament, stream raw transactions, build their own Billboard.js
charts, and ask natural-language questions through an AI console. Paid tiers
also expose a Model Context Protocol (MCP) endpoint so any MCP-compatible AI
client (Claude Desktop, Cursor, custom agents) can query the data directly.

> **Setting up a new install?** Skip to [DEPLOYMENT.md](DEPLOYMENT.md) for
> step-by-step server, database, and AI-wiring instructions.

---

## Components

| Path                     | Type         | Role                                                                                                                                                                                                                                                                                                       |
| ------------------------ | ------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `publictech-core/`       | Parent theme | Multi-DB PDO layer, custom REST/JSON endpoints, D3 + Billboard.js asset pipeline, custom post types (`member-parliament`, `audit-flag`, `forum-thread`, `pt_workspace`, `user-chart`), tier-based throttling, chart-appearance customizer, security headers + site-wide hardening, plugin-compat shims. |
| `publictech-terminal/`   | Child theme  | Cyberpunk dark/light data-terminal UI: home dashboard, MP directory, MP profile, streaming JSON table, AI console, chart builder + gallery, forum, user-settings, in-page chart customizer drawer, light/dark toggle, fully responsive at 480/720/960/1280 px breakpoints.                                |
| `publictech-mcp/`        | Plugin       | JSON-RPC 2.0 MCP server at `/wp-json/publictech-mcp/v1/rpc`. Bearer-token authenticated, tier-throttled, strictly read-only. Exposes `mp.search`, `mp.spending`, `transactions.stream`, `grants.search`, `schemas.health` tools so any MCP client can query the data.                                       |

---

## Architecture at a glance

```
                      ┌───────────────────────────┐
  Visitor / browser ──┤   PublicTech Terminal     │── light/dark, customizer
                      │   (child theme — UI)      │
                      └────────────┬──────────────┘
                                   │ REST + nonce
                      ┌────────────▼──────────────┐
                      │   PublicTech Core         │  CPTs, throttling,
                      │   (parent theme)          │  security, asset pipeline
                      └────────────┬──────────────┘
                                   │ PDO (read-only)
   AI client ──────────────────┐   │
  (Claude Desktop, Cursor,     │   ▼
   bespoke agents) ─ Bearer ──▶│  ┌─────────────────────────┐
                               │  │ External MySQL clusters │
                               │  │  pt-finance-01          │
                      ┌────────┘  │  pt-grants-02           │
                      │           │  pt-contracts-03        │
                      ▼           │  pt-registry-04         │
              PublicTech MCP      └─────────────────────────┘
              (plugin / JSON-RPC)
```

WordPress's own database stores **only** site metadata (MP custom posts, user
accounts, tier assignments, saved queries, chart specs, forum threads).
Spending/grants/contracts rows live in **separate** MySQL instances accessed
through a read-only PDO pool defined in `wp-config.php`. The PHP layer
enforces a second line of defence by rejecting any write-shaped SQL before
it leaves the process.

---

## Features

### For visitors

- **MP directory** with party/region/postal-code facets (FacetWP-aware).
- **MP profile** pages with KPI cards and D3 spend-by-year charts, plus the
  federal grant & contribution spending for the member's riding — all-time
  total, spending by year, top-10 recipients, recipient-type split, top
  programs, and a 20-keyword scrutiny scan. Served from a precomputed
  secondary cache DB (never querying the primary data at request time); see
  [DEPLOYMENT.md §4.7](DEPLOYMENT.md).
- **Riding report** pages (one template, every riding) with the same rollups.
- **Organisation funding pages** (`/groups/…`) — per-recipient grant &
  contribution totals, by-year / by-program / by-department charts, and in-page
  search, served from a cached JSON snapshot in a second database so the source
  data is never queried on page load.
- **Streaming transactions table** that scrolls through millions of rows
  without buffering the whole result set.
- **AI Console** — natural-language → SQL → table/chart, gated to paid tiers.
- **Chart builder** — Billboard.js authoring surface with live preview,
  saveable to a public gallery.
- **Forum** — bbPress-aware, or falls back to a native `forum-thread` CPT.
- **Light / dark toggle** + per-user chart appearance overrides that persist
  across devices via user-meta.

### For analysts and journalists (paid tiers)

- Save and re-run queries; export them to CSV at any time.
- Daily email alerts on saved queries (rows that match in the last 24 h).
- MCP bearer token so external AI tools can query the data conversationally.
- Higher row caps + concurrent-connection budgets so big rollups finish fast.

### For operators

- Hard isolation between WordPress and audit data (separate MySQL grants).
- Tier-aware request throttling (`rpm` + `rpd` + concurrent budgets) baked
  into every REST/MCP entry point.
- Security headers, XML-RPC + pingbacks disabled, version fingerprints
  stripped, `?author=` enumeration blocked, `/wp/v2/users` hidden from
  anonymous callers (see `publictech-core/inc/class-hardening.php`).
- One-time-visible MCP tokens minted from the WP user-edit screen.
- Conditional asset loading — D3 / Billboard / topojson / d3-sankey are
  only enqueued on pages that actually render charts.

---

## Access tiers (defaults)

Defined in `publictech-core/inc/class-access-tiers.php`. Override at runtime
via the `publictech_tiers` filter.

| Tier              | Price | Req/min | Req/day   | Max rows / call | MCP | Charts | Schemas                     |
| ----------------- | ----- | ------- | --------- | --------------- | --- | ------ | --------------------------- |
| **Anonymous**     | —     | 10      | 200       | 100             | ✗   | ✗      | `pt-finance-01` only        |
| **Free**          | $0    | 20      | 500       | 500             | ✗   | ✗      | `pt-finance-01`, `pt-grants-02` |
| **Analyst**       | $19   | 120     | 10 000    | 5 000           | ✓   | ✓      | all                         |
| **Newsroom**      | $79   | 600     | 100 000   | 50 000          | ✓   | ✓      | all                         |
| **Institutional** | $499  | 3 000   | 1 000 000 | 250 000         | ✓   | ✓      | all                         |

Higher tiers also receive larger `concurrent` connection budgets, so
expensive queries finish without queueing behind cheaper traffic.

---

## Endpoint cheatsheet

### Front-end REST (cookie nonce or Bearer token)

| Method | Path                                                    | Notes                                   |
| ------ | ------------------------------------------------------- | --------------------------------------- |
| GET    | `/wp-json/publictech/v1/status`                          | public health summary                    |
| GET    | `/wp-json/publictech/v1/mp/search`                       | facets-driven MP search                  |
| GET    | `/wp-json/publictech/v1/mp/{id}/spend`                   | per-MP rollup (Finance schema)           |
| GET    | `/wp-json/publictech/v1/stream/{schema}/{table}`         | streaming JSON rows                      |
| POST   | `/wp-json/publictech/v1/ai/query`                        | text-to-SQL (paid tier)                  |
| POST   | `/wp-json/publictech/v1/customizer/save`                 | per-user chart theme                     |
| GET    | `/wp-json/publictech/v1/queries`                         | list saved queries                       |
| POST   | `/wp-json/publictech/v1/queries`                         | save a query                             |
| DELETE | `/wp-json/publictech/v1/queries/{id}`                    | delete a saved query                     |
| GET    | `/wp-json/publictech/v1/export/{id}`                     | CSV-stream a saved query                 |
| GET    | `/wp-json/publictech/v1/charts` & friends                | chart create / fork / preview            |

### MCP (Bearer token)

| Method | Path                                                     | Notes                                   |
| ------ | -------------------------------------------------------- | --------------------------------------- |
| POST   | `/wp-json/publictech-mcp/v1/rpc`                          | JSON-RPC 2.0 endpoint                    |
| GET    | `/wp-json/publictech-mcp/v1/manifest`                     | server manifest for client discovery    |

Supported JSON-RPC methods: `initialize`, `tools/list`, `tools/call`,
`resources/list`, `resources/read`, `ping`. Tools: `mp.search`,
`mp.spending`, `transactions.stream`, `grants.search`, `schemas.health`.

Response headers report your live tier usage:

```
X-PT-Tier:            Analyst
X-PT-Tier-RPM-Used:   17
X-PT-Tier-Daily-Used: 412
```

---

## Customising the look of charts

Two surfaces, same JSON shape (consumed by `chart-theme.js`):

1. **Site-wide defaults** — *Appearance → Customize → PublicTech — Charts &
   Theme*. Accent colour, curve style, grid, font, animation toggle, default
   theme (dark/light).
2. **Per-user** — the **◈** button in the header opens the in-page customizer
   drawer. Changes apply live; **Save** persists to user-meta so they travel
   between sessions and devices.

Charts re-render on the fly via a `pt:theme` DOM event that every D3 + Billboard
mount listens for.

---

## Extension hooks (most useful filters)

| Filter                                          | Purpose                                                       |
| ----------------------------------------------- | ------------------------------------------------------------- |
| `publictech_tiers`                              | Override the tier catalog (prices, limits, schema allow-list) |
| `publictech_columns_{schema}_{table}`           | Expose a new external table to the stream/export endpoints    |
| `publictech_text_to_sql`                        | Wire your LLM into the AI Console                             |
| `publictech_d3_url` / `publictech_billboard_url`| Self-host chart libraries instead of using jsDelivr           |
| `publictech_d3_sri` / `publictech_billboard_sri`| Pin SRI hashes for CDN scripts                                |
| `publictech_security_headers`                   | Add/remove/override response headers                          |
| `publictech_enable_hsts`                        | Turn on `Strict-Transport-Security` (off by default)          |
| `publictech_content_security_policy`            | Set a site-appropriate CSP value                              |
| `publictech_require_rest_auth`                  | Force authentication on the WP REST root                      |
| `publictech_alert_lookback_seconds`             | Change the daily-alert window (default 86 400 s)              |
| `publictech_category_glossary`                  | Replace the MCP glossary returned to AI clients               |
| `publictech_force_assets` / `publictech_force_billboard` | Force-load chart bundles on a non-PT page            |

---

## Plugin compatibility

The compat shims live in `publictech-core/inc/class-compat.php`:

- **CSS Hero** — public selectors and CSS variables register with CSS Hero's
  editor so they appear in its inspector.
- **WP Hide & Security Enhancer Pro** — `publictech/v1` and
  `publictech-mcp/v1` are added to its allow-list so AI clients still reach
  the rewritten REST root.
- **Ninja Forms** — registers a `publictech_flag` action so any Ninja Form
  can file an `audit-flag` CPT entry with `txn_id` + `schema` metadata.
- **FacetWP** — the directory facets the MP archive automatically; with no
  FacetWP, a vanilla AJAX search loop runs.
- **bbPress** — child theme ships matching styles and is auto-detected.

---

## Requirements

| Component        | Version                                            |
| ---------------- | -------------------------------------------------- |
| WordPress        | 6.4 or newer (tested up to 6.8)                    |
| PHP              | 8.1 or newer (CI matrix: 8.1, 8.2, 8.3)            |
| MySQL / MariaDB  | 5.7+ / 10.4+ on the WP host and on every audit DB |
| External DB user | `SELECT`-only grants on the relevant schemas       |
| PHP extensions   | `pdo_mysql`, `mbstring`, `intl`, `json`            |
| Object cache     | Strongly recommended (Redis/Memcached) for throttling counters |

---

## Development

```bash
composer install
npm install            # only needed for ESLint / Stylelint / Prettier
make lint              # PHP lint + PHPCS + JS/CSS lint
make test              # PHPUnit suite
```

CI (`.github/workflows/ci.yml`) runs PHP lint on 8.1/8.2/8.3, PHPUnit on the
same matrix, JS syntax check, theme-metadata sanity, ESLint, and Stylelint.

A release tag (`v*`) triggers `release.yml`, which produces three zip
artifacts (parent theme, child theme, MCP plugin) shaped exactly how
WordPress expects them via *Appearance → Themes* / *Plugins*.

---

## License

GPL-2.0-or-later. See individual file headers.