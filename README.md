# PublicTech — Government Data Audit Suite

A high-performance WordPress stack that turns Canadian government spending,
grants, and contributions data into a fast, accessible web terminal.

| Component                | Role                                                                                            |
| ------------------------ | ----------------------------------------------------------------------------------------------- |
| `publictech-core/`       | **Parent theme.** Multi-DB PDO layer, custom REST/JSON endpoints, D3 asset pipeline, custom post types (member-parliament, audit-flag, forum-thread), tiered access throttling, chart-appearance customizer, security & plugin compatibility. |
| `publictech-terminal/`   | **Child theme.** Cyberpunk dark/light data-terminal UI extracted from `Audit Terminal v2.html`: home dashboard, MP directory, MP profile, streaming JSON table, AI console, forum, user-settings, in-page chart customizer drawer, light/dark toggle. |
| `publictech-mcp/`        | **MCP server plugin.** JSON-RPC 2.0 over `/wp-json/publictech-mcp/v1/rpc`. Bearer-token authenticated, tier-throttled, strictly read-only. Exposes `mp.search`, `mp.spending`, `transactions.stream`, `grants.search`, `schemas.health` tools so any MCP-compatible AI client can query the data. |

## Installation

1. Copy `publictech-core/` and `publictech-terminal/` into
   `wp-content/themes/`. Activate **PublicTech Terminal** (the child theme).
2. Copy `publictech-mcp/` into `wp-content/plugins/` and activate it.
3. In `wp-config.php` declare your external databases:

```php
define( 'PUBLICTECH_DB_CONNECTIONS', [
    'pt-finance-01'   => [ 'host' => 'finance.db.internal',   'name' => 'finance',   'user' => 'pt_read', 'pass' => '...' ],
    'pt-grants-02'    => [ 'host' => 'grants.db.internal',    'name' => 'grants',    'user' => 'pt_read', 'pass' => '...' ],
    'pt-contracts-03' => [ 'host' => 'contracts.db.internal', 'name' => 'contracts', 'user' => 'pt_read', 'pass' => '...' ],
    'pt-registry-04'  => [ 'host' => 'registry.db.internal',  'name' => 'registry',  'user' => 'pt_read', 'pass' => '...' ],
] );
```

   The PDO layer enforces `SESSION TRANSACTION READ ONLY` so the right grant
   for these users is `SELECT` only; the PHP security layer rejects any
   write-shaped SQL as a second line of defence.

4. Create five pages, each assigned a `PT —` page template:
   `home → PT — Home Dashboard`, `directory → (uses MP archive)`,
   `profile → PT — User Profile`, `forum → PT — Forum`,
   `ai → PT — AI Console`, `stream → PT — Stream Terminal`.

5. Optional: install FacetWP — the directory faceting hooks into it
   automatically. Without FacetWP a vanilla AJAX search loop runs.

## Access tiers

Defined in `publictech-core/inc/class-access-tiers.php` (override via the
`publictech_tiers` filter). The defaults:

| Tier            | Price | Req/min | Req/day   | Max rows/call | MCP | Schemas |
| --------------- | ----- | ------- | --------- | ------------- | --- | ------- |
| Free            | $0    | 20      | 500       | 500           | ✗   | finance + grants only |
| Analyst         | $19   | 120     | 10 000    | 5 000         | ✓   | all |
| Newsroom        | $79   | 600     | 100 000   | 50 000        | ✓   | all |
| Institutional   | $499  | 3 000   | 1 000 000 | 250 000       | ✓   | all |

Higher tiers receive larger row caps **and** more concurrent connections, so
expensive queries finish without queueing behind cheaper traffic.

Admins assign a tier from the WordPress user-edit screen and can mint a
one-time-visible MCP bearer token from the same page.

## MCP usage

```bash
curl -X POST https://YOUR-SITE/wp-json/publictech-mcp/v1/rpc \
     -H 'Authorization: Bearer pt_xxxxxxxxxxxxxxxx' \
     -H 'Content-Type: application/json' \
     -d '{"jsonrpc":"2.0","id":1,"method":"tools/call",
          "params":{"name":"grants.search","arguments":{"region":"ON","category":"green_tech","min_cad":50000}}}'
```

Response headers report your live tier usage (`X-PT-Tier-RPM-Used`,
`X-PT-Tier-Daily-Used`).

## Plugin compatibility

The compat shims live in `publictech-core/inc/class-compat.php`:

- **CSS Hero** — our public selectors and CSS variables are registered with
  CSS Hero's editor so they appear in its inspector.
- **WP Hide & Security Enhancer Pro** — the `publictech/v1` and
  `publictech-mcp/v1` namespaces are added to its allow-list so AI clients
  can still reach the rewritten REST root.
- **Ninja Forms** — registers a `publictech_flag` action so any Ninja Form
  can file an `audit-flag` CPT entry with `txn_id` + `schema` metadata.

## Customizing the look of charts

Two paths:

1. **Site-wide defaults** — *Appearance → Customize → PublicTech — Charts &
   Theme*. Accent colour, curve style, grid, font, animation toggle.
2. **Per-user** — the **◈** button in the header opens the in-page customizer
   drawer. Changes apply live; **Save** persists them to user-meta so they
   travel between sessions.

Both surfaces write the same JSON shape consumed by `chart-theme.js`, which
patches CSS variables and re-emits a `pt:theme` event that every D3 chart
listens for.
