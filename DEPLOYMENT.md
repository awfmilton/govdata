# PublicTech — Deployment Guide

End-to-end instructions for standing up a PublicTech site, connecting it to
the external audit databases so search and visualisations work, and wiring
an AI backend so visitors can query the data conversationally and build
charts.

The setup is broken into eight stages. Each stage is independently
testable — finish one, verify it works, then move on.

```
1. Server prerequisites
2. Install WordPress core
3. Install the themes + the MCP plugin
4. Configure external database connections
5. Create the front-end pages
6. Assign user tiers and mint MCP tokens
7. Wire the AI backend (text-to-SQL + MCP clients)
8. Production hardening
```

A short **Troubleshooting** section follows.

---

## 1. Server prerequisites

| Component        | Minimum                                     | Recommended for production            |
| ---------------- | ------------------------------------------- | ------------------------------------- |
| OS               | any Linux with systemd                      | Ubuntu 24.04 LTS / Debian 12          |
| Web server       | nginx 1.24+ or Apache 2.4+                  | nginx with FastCGI cache              |
| PHP              | 8.1                                         | 8.3 (FPM, opcache enabled)            |
| PHP extensions   | `pdo_mysql`, `mbstring`, `intl`, `json`, `curl`, `gd` | + `redis` or `memcached`     |
| MySQL / MariaDB  | 5.7 / 10.4                                  | 8.0 / 10.11                           |
| Object cache     | optional                                    | Redis 7 with `redis-cli ping` reachable from PHP |
| TLS              | optional                                    | required (Let's Encrypt is fine)      |
| Outbound HTTPS   | required to `fonts.googleapis.com`, `cdn.jsdelivr.net`, your AI provider |
| Cron             | required (real cron, not WP's pseudo-cron)  | `wp cron event run --due-now` every 5 min |

### Database topology

You'll run **two kinds** of database:

1. **WordPress's own MySQL** — holds `wp_options`, `wp_users`, `wp_posts`
   (MP CPTs, forum threads, saved chart specs), etc. Sits next to PHP.
2. **One or more "audit" MySQL instances** — hold the actual spending,
   grants, and contracts rows. PublicTech connects to these read-only over
   the network using PDO; they are never written to.

You can use one MySQL server for both, but the recommended pattern is to
keep audit data on **separate** instances with **separate users** so the
read-only grant is enforced at the database itself, not just in PHP.

---

## 2. Install WordPress core

Standard WordPress install — pick whichever method matches your platform:

```bash
# Example: a vanilla Ubuntu install via WP-CLI.
sudo -u www-data wp core download           --path=/var/www/publictech
sudo -u www-data wp config create           --path=/var/www/publictech \
    --dbhost=127.0.0.1 --dbname=publictech_wp \
    --dbuser=wp --dbpass='…'
sudo -u www-data wp core install            --path=/var/www/publictech \
    --url=https://your-site.example          \
    --title='PublicTech'                     \
    --admin_user=ops --admin_email=ops@example
```

If you use a managed host (Kinsta, WP Engine, Pressable, etc.) just follow
their normal "install WordPress" flow.

Verify the WP admin loads at `https://your-site.example/wp-admin/` before
moving on.

---

## 3. Install the themes + the MCP plugin

You have two options.

### Option A — Upload zips through WP admin

1. Visit the latest release on GitHub and download the three zip artifacts:
   `publictech-core-vX.Y.Z.zip`, `publictech-terminal-vX.Y.Z.zip`,
   `publictech-mcp-vX.Y.Z.zip`.
2. **Appearance → Themes → Add New → Upload Theme** — upload
   `publictech-core` first, then `publictech-terminal`.
3. **Appearance → Themes** — activate **PublicTech Terminal** (the child
   theme). WordPress automatically uses the parent because the child's
   `style.css` declares `Template: publictech-core`.
4. **Plugins → Add New → Upload Plugin** — upload
   `publictech-mcp`. Activate it. *(The MCP plugin refuses to activate
   without the Core theme present and reports why.)*

### Option B — Drop in via SSH / Git

```bash
cd /var/www/publictech/wp-content/themes
git clone --branch main https://github.com/awfmilton/govdata.git tmp
mv tmp/publictech-core tmp/publictech-terminal .
mv tmp/publictech-mcp ../plugins/
rm -rf tmp

# Activate
sudo -u www-data wp theme activate publictech-terminal \
    --path=/var/www/publictech
sudo -u www-data wp plugin activate publictech-mcp \
    --path=/var/www/publictech
```

### Verify

- **Appearance → Themes** shows *PublicTech Terminal* as Active and
  *PublicTech Core* as its parent.
- **Plugins** shows *PublicTech MCP Server* as Active.
- Visit the homepage — even with no pages built yet, the status rail
  should render at the top with `SYSTEM ONLINE`.

---

## 4. Configure external database connections

This is the step that makes search and visualisations actually return data.

### 4.1 Provision the read-only MySQL users

The `pt_read` user is already provisioned on the `grantconts` Cloud SQL
instance. If you ever need to recreate it:

```sql
CREATE USER 'pt_read'@'%' IDENTIFIED BY 'strong-password-here';
GRANT SELECT ON grantconts.* TO 'pt_read'@'%';
FLUSH PRIVILEGES;
```

Restrict the `'%'` host pattern to your WordPress server's IPs in production.

### 4.2 Database inventory

| Alias              | Host             | Database     | Key tables PublicTech reads from |
| ------------------ | ---------------- | ------------ | -------------------------------- |
| `pt-grantconts-01` | `35.230.186.163` | `grantconts` | `master_table`, `agreements`, `recipients`, `programs`, `locations`, `mps`, `tbayrainy_view` |

The full column-level schema is in `database/grantconts-schema.json`.
Column maps that expose those columns to the front end live in
`database/mu-plugins/01-publictech-schemas.php` — see §4.4.

### 4.3 Declare the connection in `wp-config.php`

Edit `/path/to/wordpress/wp-config.php` and add the constant **above** the
`require_once ABSPATH . 'wp-settings.php';` line:

```php
define( 'PUBLICTECH_DB_CONNECTIONS', [
    'pt-grantconts-01' => [
        'host' => '35.230.186.163',
        'port' => 3306,
        'name' => 'grantconts',
        'user' => 'pt_read',
        'pass' => 'YOUR_PT_READ_PASSWORD',
    ],
] );
```

Notes:

- The PDO connection initialises with `SET SESSION TRANSACTION READ ONLY`
  so even if the grant were too permissive, write attempts fail at the
  session boundary. A PHP layer (`Security::assert_read_only()`) is the
  second line of defence.
- Connections are lazy — PublicTech only opens a PDO handle for an alias
  the first time something queries it.

### 4.4 Expose external tables to the front end

Column maps are already written for `grantconts` in
`database/mu-plugins/01-publictech-schemas.php`. Copy that file to
`wp-content/mu-plugins/` on your WordPress server — no edits needed.

For reference, maps are declared via the `publictech_columns_{alias}_{table}`
filter — alias columns on the left, fully qualified MySQL column on the right:

```php
<?php
add_filter( 'publictech_columns_pt-grantconts-01_master_table', function () {
    return [
        'id'                   => '`master_table`.`primary_key`',
        'recipient_legal_name' => '`master_table`.`recipient_legal_name`',
        'agreement_value'      => '`master_table`.`agreement_value`',
        // ... see database/mu-plugins/01-publictech-schemas.php for full list
    ];
} );
```

Columns you don't list are not reachable by the front end or MCP clients.

### 4.5 Linking MPs to grant rows

The `member-parliament` CPT has an `external_mp_id` custom field that joins
WP post IDs to the `mp_id` string in the `grantconts.mps` table (e.g.
`"P0001"`).

**Step 1** — add a secret import token to `wp-config.php`:

```php
define( 'PT_IMPORT_TOKEN', 'replace-with-a-long-random-string' );
```

**Step 2** — upload `database/mu-plugins/02-import-mps.php` to:

```
wp-content/mu-plugins/02-import-mps.php
```

**Step 3** — visit this URL in a browser (logged in or not — the token is
the only gate):

```
https://govdata.ca/?pt_import_mps=replace-with-a-long-random-string
```

The page streams progress as it runs and prints a final summary of created /
updated / failed posts. The import reads from `PUBLICTECH_DB_CONNECTIONS`
so no separate credentials are needed.

The script is idempotent — safe to re-run after a data refresh. Once done,
delete `02-import-mps.php` from `mu-plugins` (the page will remind you).

`/mp/{id}/spend` and `mp.spending` (MCP) both refuse to run on an MP that
doesn't have `external_mp_id` set, so you'll see a clean 404 until the
import has run.

### 4.6 Verify

```bash
curl -s https://your-site.example/wp-json/publictech/v1/status | jq
```

Expected response:

```json
{
  "build": "1.0.0",
  "time":  "2026-05-27T18:00:00+00:00",
  "health": {
    "pt-grantconts-01": { "ok": true, "latency_ms": 2.1 }
  },
  "tier": "anonymous"
}
```

Any `"ok": false` here means PHP can't reach that alias — re-check the
host/credentials and the MySQL user's grants. The `error` field is
suppressed in the public response on purpose; tail the WordPress
`debug.log` for the actual exception.

### 4.7 Configure the entity-funding cache (`govdata_cache_01`)

The organisation funding pages (§5) never query `grantconts` on a page load.
A refresh job rolls each organisation up into a JSON snapshot and writes it to a
**second** database, `govdata_cache_01`. Pages serve the cached snapshot; the
in-page search box queries the cache live. All writes flow through stored
procedures — **no triggers** are used (they are disallowed on the shared host).

**Step 1 — provision the database + user.** On the cache host
(`mysql.govdata.ca`), the `gdc_01` user needs privileges on `govdata_cache_01`.
In production, give the public/search path a read-only user and the refresh job
a writer (see `GOVDATA_CACHE_DB_WRITER` below); in dev a single user is fine:

```sql
GRANT SELECT, INSERT, DELETE, EXECUTE ON govdata_cache_01.* TO 'gdc_01'@'%';
```

**Step 2 — apply the schema** (creates the tables + stored procedures; requires
MySQL 8.0+ for `JSON_TABLE`; idempotent):

```bash
mysql -u gdc_01 -p -h mysql.govdata.ca govdata_cache_01 < database/cache-schema.sql
```

**Step 3 — declare the connection in `wp-config.php`** (same place as
`PUBLICTECH_DB_CONNECTIONS`). The password lives here, never in the repo:

```php
define( 'GOVDATA_CACHE_DB', [
    'host' => 'mysql.govdata.ca',
    'port' => 3306,
    'name' => 'govdata_cache_01',
    'user' => 'gdc_01',
    'pass' => 'YOUR_CACHE_DB_PASSWORD',
] );

// Optional: a higher-privilege account used only by the refresh/upsert path,
// so the public search path can run as a read-only user. Same array shape.
// define( 'GOVDATA_CACHE_DB_WRITER', [ /* … */ ] );
```

The CLI refresh script also honours `GOVDATA_CACHE_DB_HOST` / `_PORT` / `_NAME` /
`_USER` / `_PASS` environment variables, so you can sync without editing
`wp-config.php`.

**Step 4 — run the first sync** (reads `grantconts` via `pt-grantconts-01`,
writes snapshots into the cache). Idempotent; safe to re-run:

```bash
wp eval-file database/refresh-entity-cache.php                       # all entities
wp eval-file database/refresh-entity-cache.php -- --slug=egale-canada # just one
```

A **weekly** WP-cron event (`publictech_sync_entities`, Mondays 06:00 UTC) keeps
the cache fresh automatically — make sure real cron is wired (§1) so it fires.

#### Verify

```bash
curl -s 'https://your-site.example/wp-json/publictech/v1/entity/egale-canada/summary' | jq '.total, .count, .updated_at'
```

A `404 pt_not_synced` means the snapshot hasn't been written yet — re-run the
sync and check `debug.log` (connection errors are logged, never exposed).

---

## 5. Create the front-end pages

The Terminal child theme ships seven page templates. Create one WordPress
page per template at the slugs the navigation expects.

| Page slug    | Page template (in the editor)        | Purpose                                      |
| ------------ | ------------------------------------ | -------------------------------------------- |
| `home`       | **PT — Home Dashboard**              | Hero + activity feed + watchlist + chart     |
| `directory`  | *(none — uses the MP archive)*       | `/mp/` is auto-registered by the CPT         |
| `profile`    | **PT — User Profile**                | Account settings, MCP token, saved queries   |
| `forum`      | **PT — Forum**                       | bbPress-aware                                |
| `ai`         | **PT — AI Console**                  | Natural-language query interface             |
| `stream`     | **PT — Stream Terminal**             | Streaming transactions table                 |
| `charts`     | **PT — Chart Gallery**               | Public gallery of user-built charts          |
| `chart-builder` | **PT — Chart Builder**            | Three-pane Billboard.js authoring surface    |

**Create each one** in *Pages → Add New*:

1. Set the **Title** (e.g. `Home`).
2. Set the **Permalink/slug** to match the table above (e.g. `home`).
3. In the right-side **Template** select, pick the matching `PT — …` template.
4. Publish.

Optionally, build a **Primary Terminal Tabs** menu under
*Appearance → Menus* and assign it to the **Primary Terminal Tabs**
location. The header tab strip uses this menu when present; without it,
the theme renders a sensible default tab strip (Home / Directory / Profile
/ Stream / AI Console / Forum).

### Verify

- Visit `/home` — you should see the Home Dashboard rendered with the hero,
  KPIs, and a rollup chart skeleton.
- Visit `/mp/` — empty if you haven't imported MPs yet, but the page
  template should render without errors.
- Visit `/stream` — should render the toolbar; choosing a schema/table
  should pull rows from your external DB.

### 5.1 Organisation funding pages (`/groups/…`)

The **PT — Entity Funding Report** template powers one page per organisation in
the registry (see `Entity_Reports::registry()`). Rather than create these by
hand, scaffold them all at once (idempotent — re-run after editing the registry):

```bash
wp eval-file database/create-entity-pages.php
```

This creates a `/groups` parent page and a child page per organisation
(e.g. `/groups/egale-canada`), each wired to the template via the
`pt_entity_slug` meta. Pages render the cached snapshot from §4.7; until the
first sync runs they show an "awaiting first data sync" notice.

To change which organisations get pages (add/remove, or tune the recipient-name
match fragments and keywords), hook the `publictech_funding_entities` filter
from an mu-plugin, then re-run both the scaffold and the sync. Presentation is
deliberately neutral and identical for every entity; all figures are public
Government of Canada open data and each page links back to the official portal.

---

## 6. Assign user tiers and mint MCP tokens

PublicTech's tier system reads `pt_tier` from `wp_usermeta`. Assignment
happens from the **WordPress user-edit screen**.

### 6.1 Assign a tier

1. *Users → All Users → (pick a user) → Edit*.
2. Scroll to **PublicTech Access**.
3. Choose a tier from the dropdown (`Free`, `Analyst`, `Newsroom`,
   `Institutional`).
4. *Update User*.

(You can automate this from a billing webhook — set the `pt_tier` user-meta
key directly when a subscription activates. WooCommerce subscription
products are the canonical integration, but any system that calls
`update_user_meta( $uid, 'pt_tier', 'analyst' )` works.)

### 6.2 Mint a one-time MCP token

On the same user-edit screen:

1. Tick **Generate token** (or **Rotate token** if one already exists).
2. *Update User*.
3. A green admin notice appears once with a token like
   `pt_3f9c1b4ad1d3e9a5...`. **Copy it now** — it will never be shown again.

Internally the token is stored as a SHA-256 hash plus a reverse-lookup row,
so even DB access doesn't disclose live tokens.

### 6.3 Customise the tier catalog (optional)

If the defaults don't fit your business, override them in a mu-plugin:

```php
add_filter( 'publictech_tiers', function ( $tiers ) {
    $tiers['newsroom']['rpm'] = 1000;
    $tiers['newsroom']['max_rows'] = 100000;
    $tiers['institutional']['schemas'] = '*';
    return $tiers;
} );
```

---

## 7. Wire the AI backend

There are **two independent AI surfaces**: the in-site **AI Console** and the
external **MCP server**. They can be enabled separately.

### 7.1 In-site AI Console (text-to-SQL)

The console at `/ai` posts the visitor's prompt to
`/wp-json/publictech/v1/ai/query`. PublicTech does **not** ship an LLM
itself — it exposes the `publictech_text_to_sql` filter so you wire in
your provider of choice.

Drop a small mu-plugin at
`wp-content/mu-plugins/02-publictech-text-to-sql.php`:

```php
<?php
add_filter( 'publictech_text_to_sql', function ( $unused, string $prompt ) {
    // Define your model + endpoint. Anthropic example:
    $client = wp_safe_remote_post( 'https://api.anthropic.com/v1/messages', [
        'headers' => [
            'x-api-key'         => getenv( 'ANTHROPIC_API_KEY' ),
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ],
        'timeout' => 15,
        'body'    => wp_json_encode( [
            'model'      => 'claude-opus-4-7',
            'max_tokens' => 1024,
            'system'     => 'You translate analyst questions to read-only MySQL. '
                          . 'Only the schemas pt-finance-01 (table expenditures) and '
                          . 'pt-grants-02 (table disbursements) are available. '
                          . 'Return JSON: {"sql": "...", "schema": "pt-finance-01"}.',
            'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
        ] ),
    ] );

    if ( is_wp_error( $client ) ) {
        return null;
    }
    $body = json_decode( wp_remote_retrieve_body( $client ), true );
    $text = $body['content'][0]['text'] ?? '';
    $shape = json_decode( $text, true );
    if ( ! is_array( $shape ) || empty( $shape['sql'] ) ) {
        return null;
    }

    // Defense in depth — run the SQL through the read-only enforcer.
    \PublicTech\Core\Security::assert_read_only( $shape['sql'] );

    // Execute against the requested schema and return rows so the UI can chart them.
    $schema = $shape['schema'] ?? 'pt-finance-01';
    $rows   = publictech_db( $schema )->select( $shape['sql'] );

    return [
        'sql'    => $shape['sql'],
        'rows'   => $rows,
        'schema' => $schema,
    ];
}, 10, 2 );
```

**Things to check** before pushing this live:

- `ANTHROPIC_API_KEY` (or your provider's equivalent) is in a secrets file
  outside the web root and loaded via `getenv()` or `define()`.
- Your system prompt explicitly enumerates the allowed schemas/tables;
  letting the model invent table names is the most common foot-gun.
- The handler returns `null` on any failure — PublicTech then falls back
  to a stub response so the UI doesn't break.
- `Security::assert_read_only()` still gets the final say; if it rejects
  the generated SQL, PHP throws and the request errors out cleanly.

The AI Console is **paid-tier only** by design. Anonymous and Free users
see an upgrade gate. Once you've added the filter, log in as a paid-tier
user, visit `/ai`, type "Top 5 vendors by spend in 2024", and you should
get a table + chart back.

### 7.2 External MCP clients (Claude Desktop, Cursor, agents)

The MCP plugin's JSON-RPC endpoint is **already live** at
`/wp-json/publictech-mcp/v1/rpc` as soon as the plugin is active. Each
client just needs the URL + a bearer token from §6.2.

Quick `curl` smoke test:

```bash
TOKEN=pt_3f9c1b4ad1d3e9a5...
curl -sX POST https://your-site.example/wp-json/publictech-mcp/v1/rpc \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | jq
```

You should see five tools listed (`mp.search`, `mp.spending`,
`transactions.stream`, `grants.search`, `schemas.health`). Try a real
query:

```bash
curl -sX POST https://your-site.example/wp-json/publictech-mcp/v1/rpc \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call",
       "params":{"name":"grants.search",
                 "arguments":{"region":"ON","category":"green_tech","min_cad":50000}}}'
```

#### Claude Desktop

Edit `~/Library/Application Support/Claude/claude_desktop_config.json`
(macOS) or the equivalent on Windows/Linux and add:

```json
{
  "mcpServers": {
    "publictech": {
      "url": "https://your-site.example/wp-json/publictech-mcp/v1/rpc",
      "headers": {
        "Authorization": "Bearer pt_3f9c1b4ad1d3e9a5..."
      }
    }
  }
}
```

Restart Claude Desktop. You should see *publictech* listed as an available
MCP server, with the five tools selectable inside a conversation.

#### Cursor

*Settings → MCP → Add server*, point it at the same URL with the same
bearer header.

#### Custom agents

The official MCP SDK in Python or TypeScript will work out of the box —
this server speaks protocol version `2024-11-05`.

### 7.3 Letting the AI build charts

Once you have either AI surface returning rows, users can save those rows
as Billboard.js charts:

- **From the AI Console** — the response card has a *Save as chart* button
  that pre-fills the chart builder with the result set.
- **From the chart builder** (`/chart-builder`) — analysts pick a saved
  query as the data source, choose a chart type, then save. The result
  appears in the public gallery at `/charts` and is embeddable anywhere
  via `[publictech_chart id="…"]`.

Chart creation is paid-tier only (`charts.create` in the tier catalog).

---

## 8. Production hardening

The defaults are safe to ship; everything below is one extra step of
defence each.

### 8.1 Enable HSTS once you're confident about TLS

```php
add_filter( 'publictech_enable_hsts', '__return_true' );
```

Only do this once **every subdomain** of your site serves HTTPS — once a
browser caches HSTS, you can't roll back without rebuilding it.

### 8.2 Set a real Content-Security-Policy

The hardening layer accepts one via filter:

```php
add_filter( 'publictech_content_security_policy', function () {
    return implode( '; ', [
        "default-src 'self'",
        "script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'",
        "style-src 'self' https://fonts.googleapis.com 'unsafe-inline'",
        "font-src 'self' https://fonts.gstatic.com",
        "img-src 'self' data: blob:",
        "connect-src 'self' https://your-ai-provider.example",
        "frame-ancestors 'self'",
    ] );
} );
```

Start with `Content-Security-Policy-Report-Only` and only switch to
enforcing once your console is clean.

### 8.3 Self-host chart libraries (drop CDN dependency)

```bash
mkdir -p wp-content/themes/publictech-core/assets/vendor
curl -o wp-content/themes/publictech-core/assets/vendor/d3.v7.min.js \
    https://cdn.jsdelivr.net/npm/d3@7.9.0/dist/d3.min.js
```

```php
add_filter( 'publictech_d3_url', fn() =>
    get_template_directory_uri() . '/assets/vendor/d3.v7.min.js' );
```

Repeat for Billboard.js, topojson-client, and d3-sankey.

### 8.4 Pin SRI hashes for the CDN bundles you keep

```php
add_filter( 'publictech_d3_sri', fn() =>
    'sha384-…' );
```

### 8.5 Make WP-Cron deterministic

```bash
# In your crontab:
*/5 * * * * cd /var/www/publictech && wp cron event run --due-now --quiet
```

…and in `wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

The daily alert digest (`Alerts::run_all()`) needs this to fire reliably.

### 8.6 Add a real object cache

Install Redis (or Memcached) and a drop-in plugin
(`object-cache.php`). Throttle counters then become atomic increments
instead of best-effort transient writes — important once concurrent users
start sharing the rate-limit window.

### 8.7 Lock down the `?author=` enumeration block

The hardening layer redirects `?author=N` for anonymous callers. If you
expose an MP profile via `?author=`, you'd want to whitelist that. Most
sites are fine with the default.

### 8.8 Restrict the WP login URL

A login-URL rewriter (e.g. WPS Hide Login or WP Hide & Security Enhancer
Pro) cuts brute-force noise. The Compat class already cooperates with WP
Hide & Security Enhancer Pro.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
| ------- | ------------ | --- |
| `/wp-json/publictech/v1/status` returns `"ok": false` for every alias | `PUBLICTECH_DB_CONNECTIONS` not defined, or PHP can't open a network connection to the audit DBs | Re-check `wp-config.php`, run `mysql -h 35.230.186.163 -upt_read -p` from the WP host, open Cloud SQL firewall rules for the WP server's IP. |
| `/wp-json/publictech/v1/status` returns `404` | REST not enabled / rewritten by a security plugin | If using *WP Hide & Security Enhancer Pro* the Compat shim already allow-lists PublicTech; otherwise add the namespace manually. |
| Stream table loads, then "Stream failed." | Schema/table not registered via `publictech_columns_{alias}_{table}` | See §4.4. |
| 403 on a stream endpoint | Caller's tier doesn't include that schema | See `Access_Tiers::tiers()` and `publictech_tiers` filter (§6.3). |
| `mp.spending` returns "Member not linked to grant row" | `external_mp_id` user-meta missing on the MP post | See §4.5. |
| AI Console returns the stub SQL `-- text-to-sql backend not configured` | `publictech_text_to_sql` filter not wired | See §7.1. |
| MCP `curl` returns `-32001 Unauthorized` | Token wrong, expired/rotated, or not sent with `Bearer ` prefix | Rotate the token (§6.2) and use the freshly displayed value. |
| MCP `curl` returns `-32003 MCP requires a paid tier.` | User is on Free or Anonymous | Promote the user (§6.1) to Analyst or higher. |
| MCP `-32002 Rate limit exceeded` | Tier RPM exceeded | Inspect `X-PT-Tier-RPM-Used` header; back off or upgrade tier. |
| Chart builder shows "upgrade gate" for Analyst+ | Tier was reassigned in WP but the visitor still has the old session | Log out / log in once. |
| Daily alert emails not arriving | WP-Cron not running | See §8.5. |
| Pages render unstyled on mobile | `publictech-terminal-responsive` stylesheet not enqueued (theme update missed) | Confirm `responsive.css` is loaded — check the page source for the handle, or re-deploy the child theme. |

For deeper diagnostics, enable WP debug logging in `wp-config.php`:

```php
define( 'WP_DEBUG',         true );
define( 'WP_DEBUG_LOG',     true );
define( 'WP_DEBUG_DISPLAY', false );
```

…and `tail -f wp-content/debug.log`. PublicTech's DB failures, MCP errors,
and cron exceptions all emit `[PublicTech]` prefixed lines.

---

## Upgrading

```bash
cd wp-content/themes/publictech-core    && git pull
cd ../publictech-terminal                && git pull
cd ../../plugins/publictech-mcp          && git pull
sudo -u www-data wp cache flush --path=/var/www/publictech
```

Or upload the new release zips through *Appearance → Themes → Add New →
Upload Theme* (the **Replace existing** flow added in WP 5.5).

`Requires at least: 6.4` is enforced in every header; older WordPress
installs simply refuse to activate the theme rather than booting in a
half-supported state.
