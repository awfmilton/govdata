# MP Profile — Finance Data Setup Guide

This document covers the two remaining steps needed to make MP profile pages
display live financial data. The code is already written and bug-free; only
data and infrastructure are missing.

**What works today (post-bugfix):**
- MP directory with name, party, region, and riding data ✓
- Profile sidebar (photo, postal codes, committees, region) ✓
- Riding grant & contribution panels (served from `pt-cache-02`) ✓

**What still shows zeros until this guide is completed:**
- KPI cards: Total Office Spend, Travel Expenses, Contracts Awarded, Votes Cast
- Spending Breakdown line chart (by fiscal year and category)
- Category Comparison bar chart (latest year vs peer average)
- Funding Flow Network diagram (vendors → categories)

---

## Part 1 — Wire the `pt-finance-01` database connection

The `/wp-json/publictech/v1/mp/{id}/spend` REST endpoint and the MCP
`mp.spending` tool both query a database aliased **`pt-finance-01`**.
This alias is referenced throughout the code but is not yet declared in
`wp-config.php` because the data source wasn't ready at deployment time.

### 1.1 Required schema

The alias must point to a MySQL database that contains an `expenditures`
table with at least these columns:

```sql
CREATE TABLE expenditures (
    txn_id       VARCHAR(64)    NOT NULL,
    txn_date     DATE           NOT NULL,
    vendor_name  VARCHAR(255)   NOT NULL,
    category     VARCHAR(128)   NOT NULL,
    region       VARCHAR(64)    NOT NULL,
    amount_cad   DECIMAL(14, 2) NOT NULL DEFAULT 0,
    mp_id        VARCHAR(32)    NOT NULL,   -- matches external_mp_id post meta, e.g. "P0001"
    PRIMARY KEY  (txn_id),
    KEY idx_mp   (mp_id),
    KEY idx_year (txn_date, category)
);
```

> **Critical**: `mp_id` must be VARCHAR and must contain the same values
> stored in the `external_mp_id` post meta on each `member-parliament` CPT
> post (e.g. `"P0001"`, not a numeric integer). The importer sets this from
> `grantconts.mps.mp_id` — the two columns must match exactly.

The column-level filter exposing `expenditures` to the stream/export
endpoints is already registered in
`publictech-terminal/functions.php`:

```php
add_filter( 'publictech_columns_pt-finance-01_expenditures', function () {
    return [
        'txn_id'     => '`expenditures`.`txn_id`',
        'date'       => '`expenditures`.`txn_date`',
        'vendor'     => '`expenditures`.`vendor_name`',
        'category'   => '`expenditures`.`category`',
        'region'     => '`expenditures`.`region`',
        'amount_cad' => '`expenditures`.`amount_cad`',
        'mp_id'      => '`expenditures`.`mp_id`',
    ];
} );
```

No code changes are needed here.

### 1.2 Provision the read-only MySQL user

```sql
CREATE USER 'pt_finance_read'@'%' IDENTIFIED BY 'strong-password-here';
GRANT SELECT ON finance_db.expenditures TO 'pt_finance_read'@'%';
FLUSH PRIVILEGES;
```

Restrict the `'%'` host pattern to your WordPress server's IP in production.

### 1.3 Declare the connection in `wp-config.php`

Add `pt-finance-01` alongside the existing connections in `PUBLICTECH_DB_CONNECTIONS`
**above** the `require_once ABSPATH . 'wp-settings.php';` line:

```php
define( 'PUBLICTECH_DB_CONNECTIONS', [
    'pt-grantconts-01' => [
        'host' => '35.230.186.163',
        'port' => 3306,
        'name' => 'grantconts',
        'user' => 'pt_read',
        'pass' => 'YOUR_PT_READ_PASSWORD',
    ],
    'pt-finance-01' => [                // ← add this block
        'host' => 'YOUR_FINANCE_DB_HOST',
        'port' => 3306,
        'name' => 'YOUR_FINANCE_DB_NAME',
        'user' => 'pt_finance_read',
        'pass' => 'YOUR_FINANCE_DB_PASSWORD',
    ],
] );
```

> If the finance data lives in the same MySQL instance as `grantconts`,
> just point `pt-finance-01` at the same host with a different `name`. The
> connection pool opens one PDO handle per alias.

### 1.4 Verify

```bash
curl -s https://your-site.example/wp-json/publictech/v1/status | jq '.health'
```

Expected — both aliases report `"ok": true`:

```json
{
  "pt-grantconts-01": { "ok": true, "latency_ms": 2.1 },
  "pt-finance-01":    { "ok": true, "latency_ms": 1.8 }
}
```

Then test the spend endpoint directly for any MP whose `external_mp_id` is set:

```bash
# Replace 316 with the WP post ID of an MP you know has expenditure rows.
curl -s "https://your-site.example/wp-json/publictech/v1/mp/316/spend" | jq '.[0]'
```

Expected shape:

```json
{ "fiscal_year": "2022", "category": "Travel", "total": "48320.00" }
```

A `503` means the connection failed — check credentials and firewall.
A `404 pt_not_found` with "Member not linked" means `external_mp_id` is
not set on that post — re-run the MP importer (DEPLOYMENT.md §4.5).

---

## Part 2 — Populate MP KPI post meta

The profile page KPI cards read their numbers from WordPress post meta at
render time. The importer (`database/import-mps.php`) only writes
identity/contact fields; the aggregated spend metrics must be written in a
separate step after `pt-finance-01` has data.

### 2.1 Meta keys used by the profile template

| Meta key          | Type    | Description                                                              |
| ----------------- | ------- | ------------------------------------------------------------------------ |
| `total_spend`     | integer | All-time total `amount_cad` from `expenditures` for this MP (CAD cents or whole dollars — be consistent) |
| `travel_spend`    | integer | Total `amount_cad` where `category` = `'Travel'` (adjust to match your category labels) |
| `contracts_n`     | integer | Count of distinct `vendor_name` values (proxy for contracts awarded)     |
| `votes_cast`      | integer | Parliamentary votes attendance count — source depends on your data pipeline (see note below) |
| `integrity_score` | integer | 0–100 audit score; rendered as `—` when 0; shown in amber when < 80     |
| `open_flags`      | integer | Count of `audit-flag` CPT posts linked to this MP, or a computed value   |

> **`votes_cast` note**: This field is not in `grantconts` or `pt-finance-01`.
> If you have a parliamentary votes data source, populate it during that
> import. If not, leave it at 0 — the KPI card will show `0 attendance`
> but won't error.

> **`integrity_score` / `open_flags` note**: These are intentionally
> pluggable — the `publictech_mp_affiliations` filter and the `audit-flag`
> CPT are the canonical hooks. Populate from your own scoring logic or leave
> at 0 until that pipeline exists.

### 2.2 Aggregation script

Create `database/aggregate-mp-kpis.php`. Run it via WP-CLI after
`pt-finance-01` is wired up and populated:

```bash
wp eval-file database/aggregate-mp-kpis.php
```

Skeleton — adapt `category` values to match your actual data:

```php
<?php
/**
 * Populate MP KPI post meta from pt-finance-01.expenditures.
 *
 * Run via: wp eval-file database/aggregate-mp-kpis.php
 *
 * Safe to re-run — uses update_post_meta (upsert). Skips MPs with no
 * external_mp_id or no rows in the expenditures table.
 */

defined( 'ABSPATH' ) || die( "Run via: wp eval-file\n" );

$mps = get_posts( [
    'post_type'      => 'member-parliament',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'fields'         => 'ids',
] );

$db       = publictech_db( 'pt-finance-01' );
$progress = WP_CLI\Utils\make_progress_bar( 'Aggregating MP KPIs', count( $mps ) );
$updated  = 0;
$skipped  = 0;

foreach ( $mps as $post_id ) {
    $ext_id = (string) get_post_meta( $post_id, 'external_mp_id', true );
    if ( '' === $ext_id ) {
        $skipped++;
        $progress->tick();
        continue;
    }

    try {
        $rows = $db->select(
            'SELECT
                 COALESCE(SUM(amount_cad), 0)                                    AS total_spend,
                 COALESCE(SUM(CASE WHEN category = ? THEN amount_cad END), 0)    AS travel_spend,
                 COUNT(DISTINCT vendor_name)                                      AS contracts_n
               FROM expenditures
              WHERE mp_id = ? AND amount_cad > 0',
            [ 'Travel', $ext_id ]   // adjust 'Travel' to match your category label
        );
    } catch ( \Throwable $e ) {
        WP_CLI::warning( "DB error for ext_id={$ext_id}: " . $e->getMessage() );
        $skipped++;
        $progress->tick();
        continue;
    }

    if ( empty( $rows ) ) {
        $skipped++;
        $progress->tick();
        continue;
    }

    $row = $rows[0];
    update_post_meta( $post_id, 'total_spend',  (int) $row['total_spend'] );
    update_post_meta( $post_id, 'travel_spend', (int) $row['travel_spend'] );
    update_post_meta( $post_id, 'contracts_n',  (int) $row['contracts_n'] );
    $updated++;
    $progress->tick();
}

$progress->finish();
WP_CLI::success( "Done. Updated: {$updated}  Skipped (no ext_id or no rows): {$skipped}" );
```

### 2.3 Keep it fresh with WP-Cron

Once the initial run completes, schedule weekly re-aggregation alongside the
riding cache warmer. Add to an mu-plugin or `wp-config.php`:

```php
// Schedule MP KPI aggregation weekly (Sundays 04:00 UTC).
add_action( 'publictech_aggregate_mp_kpis', function () {
    $mps = get_posts( [
        'post_type'      => 'member-parliament',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );
    $db = publictech_db( 'pt-finance-01' );
    foreach ( $mps as $post_id ) {
        $ext_id = (string) get_post_meta( $post_id, 'external_mp_id', true );
        if ( '' === $ext_id ) {
            continue;
        }
        try {
            $rows = $db->select(
                'SELECT
                     COALESCE(SUM(amount_cad), 0)                                 AS total_spend,
                     COALESCE(SUM(CASE WHEN category = ? THEN amount_cad END), 0) AS travel_spend,
                     COUNT(DISTINCT vendor_name)                                   AS contracts_n
                   FROM expenditures
                  WHERE mp_id = ? AND amount_cad > 0',
                [ 'Travel', $ext_id ]
            );
            if ( ! empty( $rows ) ) {
                update_post_meta( $post_id, 'total_spend',  (int) $rows[0]['total_spend'] );
                update_post_meta( $post_id, 'travel_spend', (int) $rows[0]['travel_spend'] );
                update_post_meta( $post_id, 'contracts_n',  (int) $rows[0]['contracts_n'] );
            }
        } catch ( \Throwable $e ) {
            error_log( '[PublicTech] KPI aggregation failed for post ' . $post_id . ': ' . $e->getMessage() );
        }
    }
} );

if ( ! wp_next_scheduled( 'publictech_aggregate_mp_kpis' ) ) {
    wp_schedule_event( strtotime( 'next Sunday 04:00:00' ), 'weekly', 'publictech_aggregate_mp_kpis' );
}
```

Ensure real cron is wired (DEPLOYMENT.md §8.5) so this event actually fires.

---

## Part 3 — Completion checklist

Work through these in order. Each step is independently verifiable before
moving on.

- [ ] `pt-finance-01` MySQL user provisioned with `SELECT` on `expenditures`
- [ ] `PUBLICTECH_DB_CONNECTIONS` updated in `wp-config.php` with `pt-finance-01` block
- [ ] `/wp-json/publictech/v1/status` shows `"pt-finance-01": { "ok": true }` 
- [ ] `/wp-json/publictech/v1/mp/{id}/spend` returns rows for at least one MP
- [ ] Charts (Spending Breakdown, Category Comparison, Funding Flow Network) render on the MP profile page
- [ ] `database/aggregate-mp-kpis.php` script created and run via WP-CLI
- [ ] KPI cards on the MP profile page show non-zero values
- [ ] Weekly WP-Cron event registered for ongoing re-aggregation
- [ ] Cron event verified with `wp cron event list | grep publictech_aggregate`

---

## Background — bugs fixed during investigation (2026-05-28)

These three code bugs were found and fixed while diagnosing the empty
profile pages. They are committed on `main` and require no further action.

| File | Bug | Fix |
| ---- | --- | --- |
| `publictech-terminal/single-member-parliament.php` | Inline `<script>` ran before `get_footer()`, so `window.PT` (deferred `d3-charts.js`) and `window.PT_BOOT` (`wp_localize_script` output) were both undefined — the `!window.PT` guard silently exited, no API calls were made, no charts rendered | Wrapped the block in `document.addEventListener('DOMContentLoaded', …)` so it runs after all deferred scripts |
| `publictech-core/inc/class-rest-api.php` | `external_mp_id` was cast `(int)` — a varchar like `"P0001"` became `0`, failing the `<= 0` guard and returning 404 "Member not linked" | Changed to string comparison: `(string)` cast, check `'' === $ext_id` |
| `publictech-core/inc/class-rest-api.php` + `publictech-terminal/archive-member-parliament.php` | Both read `riding` post meta; the importer writes `pt_mp_district` — riding names appeared empty on directory cards | Updated both to use `pt_mp_district` with `riding` as fallback (matching the single-MP template's existing pattern) |
