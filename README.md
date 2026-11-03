# PublicTech — Government Data Audit Suite

A WordPress stack that turns Canadian government spending, grants, and
contracts data into a fast, accessible web terminal. Visitors can search
Members of Parliament, stream raw transactions, build their own Billboard.js
charts, and ask natural-language questions through an AI console. Paid tiers
also expose a Model Context Protocol (MCP) endpoint so any MCP-compatible AI
client (Claude Desktop, Cursor, custom agents) can query the data directly.

> **Setting up a new install?** Skip to [DEPLOYMENT.md](DEPLOYMENT.md) for
> step-by-step server, database, and AI-wiring instructions.

> **MP profile charts showing zeros?** Run
> `wp eval-file database/warm-mp-finance-cache.php` to populate the cache
> from the HoC expenditure tables in `grantconts`. See
> [MP-FINANCE-DATA.md](MP-FINANCE-DATA.md) for full details and the
> verification checklist.

---

## Components

| Path                     | Type         | Role                                                                                                                                                                                                                                                                                                       |
| ------------------------ | ------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `publictech-core/`       | Parent theme | Multi-DB PDO layer, custom REST/JSON endpoints, D3 + Billboard.js asset pipeline, custom post types (`member-parliament`, `audit-flag`, `forum-thread`, `pt_workspace`, `user-chart`), tier-based throttling, chart-appearance customizer, security headers + site-wide hardening, plugin-compat shims. |
| `publictech-terminal/`   | Child theme  | Cyberpunk dark/light data-terminal UI: home dashboard, MP directory, MP profile, streaming JSON table, AI console, chart builder + gallery, forum, user-settings, in-page chart customizer drawer, light/dark toggle, fully responsive at 480/720/960/1280 px breakpoints.                                |
| `publictech-mcp/`        | Plugin       | JSON-RPC 2.0 MCP server at `/wp-json/publictech-mcp/v1/rpc`. Bearer-token authenticated, tier-throttled, strictly read-only. Exposes `mp.search`, `mp.spending`, `transactions.stream`, `grants.search`, `schemas.health` tools so any MCP client can query the data.                                       |
| `govdata-ad-services/`   | Plugin       | The ad network **hub**: ad/zone/campaign post types, self-serve advertiser role + registration, moderation queue, HMAC-verified impression/click tracking with IAB viewability, nightly stats rollups, wp-admin pages **and** a front-end portal, plus a keyed REST API that serves ads to partner sites.   |
| `govdata-ads-client/`    | Plugin       | The ad network **client** for any partner WordPress site. Fetches ads from the hub server-side (the API key never reaches browsers), renders them via the `[govads zone="…"]` shortcode with lazy loading and zero layout shift, and reports viewability/clicks back to the hub.                            |

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

## GovData Ad Services (self-serve ad network)

A first-party ad network with the site as the central management console.
Two plugins: `govdata-ad-services/` runs the hub on this site;
`govdata-ads-client/` is installed on partner WordPress sites that want to
display network ads. Deployment steps live in
[DEPLOYMENT.md §9](DEPLOYMENT.md).

### For advertisers

- **Self-serve registration** at `/advertiser-register/` — account creation,
  auto-login, and (by default) immediate advertiser access. Flip
  `govads_auto_approve_advertisers` to `false` for manual vetting.
- **Front-end portal** at `/ad-services/` (no wp-admin needed): overview KPIs
  (impressions, clicks, CTR, active ads), ad submission/editing, pause/resume,
  campaign management, and per-ad stats with trend sparklines over 7–90 days.
- **Two creative types** — image banners and `wp_kses`-sanitised HTML5 rich
  media — in 9 standard IAB sizes (300×250, 728×90, 160×600, 300×600, 970×90,
  320×50, 336×280, 250×250, 120×600).
- **Campaigns** group ads and carry flight dates; per-ad start/end dates are
  enforced at serve time.
- **Email notifications** when an ad is approved or rejected (with reason).
- Marketing pages: `/ad-intelligence/` (analytics-engine landing page) and
  `/advertise/` (pricing + placements, rate card filterable via
  `govads_rate_card`).

### For ad operators / moderators

- **Review queue** — every new or creative-edited ad re-enters `pending` and
  must be approved before serving. Available in wp-admin (*GovData Ads → All
  Ads*) and in the portal's Admin tab (overview KPIs, pending queue,
  advertiser leaderboard ranked by 30-day impressions).
- **Zones** are CPT entries mapped to `do_action( 'govads_zone', '…' )` calls
  in the theme — 9 ship wired (MP profile sidebar + mid-content, directory
  banner/sidebar/footer, riding & entity report sidebars, dashboard
  leaderboard, global footer). Ads are matched to zones **by IAB size**, and
  weighted-random rotation respects per-ad weights.
- **Partner API keys** (*GovData Ads → API Keys*) — generated once, stored
  only as SHA-256 hashes, revocable, with last-used tracking.
- **Roles & caps** — the `govdata_advertiser` role plus
  `govads_submit_ads` / `govads_view_own_stats` / `govads_manage_campaigns`
  capabilities (also granted to administrators and editors).

### Measurement pipeline

- **IAB-standard viewability**: an impression fires only after ≥50 % of the
  creative is visible for 1 s (IntersectionObserver).
- **HMAC-signed events**: `/serve` issues a time-limited token binding
  ad + zone + partner site; `/track` rejects events without a valid token, so
  tracking cannot be fabricated. TTL is filterable (`govads_token_ttl`,
  default 1 h).
- **Dedup + rate limits**: one impression per ad/session/hour, a 10 s click
  dedup window, and a 300 events/min/IP cap.
- **Privacy**: IPs are anonymised before storage (IPv4 last octet zeroed,
  IPv6 masked to /48 — PIPEDA-aligned), sessions are first-party cookies, and
  no third-party trackers exist anywhere in the pipeline.
- **Nightly rollup cron** (02:00 UTC) aggregates raw events into
  `daily_stats` (per ad/zone/campaign/site) and prunes raw rows after 90 days
  (`govads_raw_retention_days`).
- Custom tables: `wp_govads_impressions`, `wp_govads_clicks`,
  `wp_govads_daily_stats` — all dropped on uninstall.

### Performance guarantees

- PHP renders only a fixed-size placeholder div — **zero layout shift**, no
  render-blocking work, ~3 KB deferred loader.
- Slots fill lazily (200 px before entering the viewport); ad decisions are
  served from a 60-second pool cache and 5-minute zone cache.
- Assets enqueue only on pages that can contain zones
  (`govads_force_assets` filter to override).
- On partner sites the ad payload is embedded server-side (60 s transient,
  30 s negative cache), so browsers make **no cross-origin ad calls at all** —
  only fire-and-forget tracking beacons.

### Partner network security model

- The raw API key (`gads_…`) is shown once at generation; the hub stores only
  its SHA-256 hash and compares with `hash_equals()`.
- The key lives **server-side only** on partner sites (sent via
  `X-GovAds-Site-Key` on the server-to-server `/serve` call). Browsers never
  see it; per-site attribution rides inside the signed impression token.
- Partner sites sanitise every hub payload field on ingest (URL escaping,
  `wp_kses_post` on HTML creatives) — defence in depth against a compromised
  hub.

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

### Ad network REST (`govads/v1`)

| Method | Path                                          | Auth                                | Notes                                                |
| ------ | --------------------------------------------- | ----------------------------------- | ---------------------------------------------------- |
| GET    | `/wp-json/govads/v1/serve?zone={slug}`        | public (+ optional partner key)     | ad payload + HMAC token; rate-limited; 204 = no fill |
| POST   | `/wp-json/govads/v1/track`                    | HMAC token (required)               | impression/click events; JSON body or query string   |
| GET    | `/wp-json/govads/v1/stats?days=N`             | advertiser cap / admin              | own daily stats (admin may pass `advertiser_id`)     |
| GET    | `/wp-json/govads/v1/me/ads`                   | advertiser cap                      | list own ads (admins see all)                        |
| POST   | `/wp-json/govads/v1/me/ads`                   | advertiser cap                      | submit an ad (always lands `pending`)                |
| POST   | `/wp-json/govads/v1/me/ads/{id}`              | owner / admin                       | edit fields, `pause`, `resume`; creative edits re-queue review |
| GET    | `/wp-json/govads/v1/me/campaigns`             | advertiser cap                      | list own campaigns                                   |
| POST   | `/wp-json/govads/v1/me/campaigns`             | advertiser cap                      | create campaign                                      |
| POST   | `/wp-json/govads/v1/me/request-access`        | logged-in                           | emails the admin an access request                   |
| POST   | `/wp-json/govads/v1/register`                 | public (rate-limited 5/h/IP)        | self-serve advertiser signup + auto-login            |
| GET    | `/wp-json/govads/v1/admin/overview`           | `edit_others_posts`                 | network KPIs (30 d)                                  |
| GET    | `/wp-json/govads/v1/admin/pending`            | `edit_others_posts`                 | moderation queue                                     |
| GET    | `/wp-json/govads/v1/admin/advertisers`        | `edit_others_posts`                 | advertiser leaderboard                               |
| POST   | `/wp-json/govads/v1/admin/approve` / `reject` | `edit_others_posts`                 | moderate an ad (reject takes a `reason`)             |
| POST   | `/wp-json/govads/v1/admin/keys` / `keys/revoke` | `manage_options`                  | partner API key lifecycle                            |

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
| `govads_rate_card`                              | Replace the `/advertise` pricing plans without code edits     |
| `govads_token_ttl`                              | Impression-token validity window (default 3600 s)             |
| `govads_raw_retention_days`                     | Raw impression/click retention before pruning (default 90)    |
| `govads_auto_approve_advertisers`               | `false` = new signups become subscribers pending review       |
| `govads_allow_self_register`                    | `false` (with `users_can_register` off) closes signup         |
| `govads_noscript_url`                           | House-ad link rendered inside `<noscript>` fallbacks          |
| `govads_force_assets`                           | Force the ad loader onto templates the heuristics miss        |

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
| WordPress (partner sites, `govdata-ads-client`) | 6.2 or newer        |
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

Mozilla Public License 2.0 (MPL-2.0). Copyright (c) Alexander Milton. See [LICENSE](LICENSE).