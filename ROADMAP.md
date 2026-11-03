# PublicTech — Government Data Audit Suite Roadmap

This document tracks the planned engineering roadmap across infrastructure migration, monetization, core audit features, localization, security, and CI/CD test automation (**~74 hours of planned engineering work**).

---

## Phase 1: DigitalOcean Infrastructure & Database Remigration (~14 hours)

Following the decommission of the legacy Google Cloud instance, the primary WordPress database and the four external read-only MySQL audit clusters (`pt-finance-01`, `pt-grants-02`, `pt-contracts-03`, `pt-registry-04`) must be redeployed onto DigitalOcean infrastructure, restoring the August 2025 baseline snapshot and reapplying all subsequent schema migrations and cache warmers.

- [ ] **DigitalOcean Droplet & Managed MySQL Provisioning:** Provision hardened DigitalOcean infrastructure with strict private VPC networking between the web tier and external read-only MySQL clusters (`SELECT`-only grants enforced at the MySQL user level).
- [ ] **August 2025 Snapshot Restore & Incremental Schema Migrations:** Restore the August 2025 `grantconts` and House of Commons expenditure snapshots and reapply all migrations from `database/` (`database/mp-ridings-migration.sql`, `database/hoc-expenditures-migration.sql`, `database/grants-cache-migration.sql`, `database/org-funding-migration.sql`).
- [ ] **Era-Aware Riding Redistribution & Cache Re-Warming (`MP-RIDINGS-OVER-TIME-PLAN.md`):**
  - Replace hardcoded `PCFRF_RO_ID = 2` (2023 representation order) in `wp-content/plugins/publictech-core/inc/class-riding-cache-warmer.php` with fiscal-year-aware representation order resolution (`2003` vs `2013` vs `2023` boundaries).
  - Deduplicate the 219 `HIST-` rows in `mps`, populate `mp_persons_id` foreign keys, and reconcile the 20 unmatched `op_politician_id` records.
  - Execute `database/warm-mp-finance-cache.php`, `database/warm-riding-grants-cache.php`, and `database/warm-org-funding-cache.php` across all representation orders.
- [ ] **Redis Object Cache & Nightly Backup Automation:** Configure DigitalOcean Redis for `PublicTech\Core\Throttler` rate-limit counters and automated Spaces backups.

---

## Phase 2: Subscriptions, Supporter Donations & Billing Integration (~10 hours)

PublicTech is sustained by paid tiers (`Analyst`, `Newsroom`, `Institutional`) alongside one-time and recurring civic-data supporter donations. Currently, 5 page templates (`template-chart-gallery.php`, `template-chart-builder.php`, `template-ai-console.php`, `template-profile.php`, `template-data-explorer.php`) link to `/pricing` and `/upgrade`, which return `404`.

- [ ] **`/pricing`, `/upgrade` & `/donate` Page Templates:** Build `page-templates/template-pricing.php` and `page-templates/template-donate.php` in `publictech-terminal` with side-by-side tier comparison (`Free`, `Analyst`, `Newsroom`, `Institutional`) and a civic-journalism supporter donation widget (one-time and monthly recurring contributions).
- [ ] **Stripe Checkout, Customer Portal & Webhook Handler:**
  - Implement `wp-content/plugins/publictech-core/inc/class-billing.php` handling Stripe Checkout sessions for both recurring tier subscriptions and one-time/recurring donations.
  - Register a raw-body HMAC-verified webhook endpoint (`POST /wp-json/publictech/v1/stripe/webhook`) handling `checkout.session.completed`, `customer.subscription.updated`, `customer.subscription.deleted`, and `invoice.payment_failed`.
  - Automatically provision and downgrade `pt_access_tier` user-meta and auto-mint MCP bearer tokens upon subscription activation.

---

## Phase 3: Wire Inert Audit Features & UI Controls (~10 hours)

Several backend engines exist in `publictech-core` that are not yet wired to REST endpoints or frontend UI controls:

- [ ] **Saved Query Daily Email Alerts (`class-alerts.php`):**
  - Currently `Alerts::run_daily()` reads `pt_saved_alerts` user-meta, which is never written anywhere in the codebase (`class-rest-api.php` writes to `pt_saved_queries`).
  - Add `POST /wp-json/publictech/v1/alerts` and `DELETE /wp-json/publictech/v1/alerts/{id}` endpoints, plus an "Enable Daily Email Alert" toggle on saved queries in `template-profile.php` and `template-data-explorer.php`.
- [ ] **Surface Spending Anomaly Flags (`class-anomalies.php`):**
  - `Anomalies::flags_for()` computes Benford's Law first-digit chi-square deviations and fiscal-year-end (March) spending spikes into `wp_options`, but is never called in templates or REST responses.
  - Surface anomaly badges on `single-member-parliament.php`, `template-riding-report.php`, and `/wp-json/publictech/v1/mp/{id}/spend`.
- [ ] **Forum Category Filtering & Gallery Fork Error Handling:**
  - Wire the static category filter pills in `page-templates/template-forum.php:46-49` to filter threads by category slug.
  - Add `.catch()` error handling and login redirect to the chart "Fork" button in `page-templates/template-chart-gallery.php:153-167`.

---

## Phase 4: Bilingual (English / French) & Multilingual Expansion (~10 hours)

As a Canadian federal government data platform, full bilingual parity (`en_CA` / `fr_CA`) across UI templates, riding names, and department classifications is essential:

- [ ] **WordPress i18n Audit & `.pot` / `fr_CA.po` Translation Catalogs:** Wrap all remaining hardcoded English strings in `publictech-core`, `publictech-terminal`, `publictech-mcp`, and `govdata-ad-services` in `__() / _e() / esc_html_e()` under their respective textdomains and compile complete `fr_CA` `.po`/`.mo` files.
- [ ] **Bilingual Dataset Column & Riding Name Resolution:** Map bilingual Elections Canada riding names (e.g., `Ottawa—Vanier`) and Treasury Board department titles (`en` / `fr`) based on active locale, with a header language switcher (`EN / FR`) in `publictech-terminal/header.php`.

---

## Phase 5: Security & Privacy Review (PIPEDA & Hardening) (~10 hours)

- [ ] **Opt-In Consent Gate for Analytics (`data-explorer.js`):** Remove consent-free browser canvas/hardware/screen fingerprinting in `publictech-terminal/assets/js/data-explorer.js:69-134` and gate all behavioural telemetry behind an explicit PIPEDA-compliant opt-in banner.
- [ ] **Read-Only SQL Guard & PDO Audit (`class-db-pool.php`):** Audit `DB_Pool::assert_readonly_sql()` against multi-statement, comment-obfuscated, and sleep/benchmark DoS queries; enforce per-query `MAX_EXECUTION_TIME` hints on MySQL 8.
- [ ] **CSP, SRI & REST Nonce Hardening (`class-hardening.php`):** Pin Subresource Integrity (`integrity`) hashes for D3/Billboard/TopoJSON assets and tighten `Content-Security-Policy` headers across all templates.

---

## Phase 6: Infrastructure Review (~8 hours)

- [ ] **Connection Pooling & Slow-Query Observability:** Review PDO persistent connection sizing, MySQL `wait_timeout`, and PHP-FPM worker pools under concurrent streaming (`/stream/{schema}/{table}` and CSV exports).
- [ ] **Zero-Downtime Cache Swap:** Refactor the riding and organization cache warmers to write to staging shadow tables and atomically `RENAME TABLE` on completion so cache rebuilds never serve partial zeros.

---

## Phase 7: GitHub Actions & Automated Testing Review (~12 hours)

- [ ] **GitHub Actions CI/CD Optimization (`.github/workflows/ci.yml` & `release.yml`):**
  - Add `concurrency` with `cancel-in-progress: true` and strict job `timeout-minutes` across all workflows.
  - Add Composer and npm dependency caching, automated `govdata-ad-services` + `govdata-ads-client` zip packaging in `release.yml`, and DigitalOcean deployment smoke checks.
- [ ] **Automated PHPUnit & End-to-End Integration Suite:**
  - Expand PHPUnit coverage to test `Billing` Stripe webhook signature verification, `Alerts` diff generation, `Anomalies` Benford calculations, and era-aware `Riding_Cache_Warmer` SQL queries against a seeded MySQL service container in GitHub Actions.
