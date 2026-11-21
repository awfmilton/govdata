# Owner to-do: govdata.ca

Decisions and actions waiting on the owner. Raised during the November 2026 rebuild and cutover; each one says who
does what once it is approved. Tick items off here when done.

## Security (DreamHost)

- [ ] **Rotate the WordPress database password.** `govdataca1` on `mysql.govdata.ca/govdata_ca_1` is only
      8 characters.
- [ ] **Give each cache database its own password.** `gdc_01` (govdata_cache_01) and `gdc_02` (govdata_cache_02)
      currently share one.
- [ ] **Limit DreamHost MySQL to the web server.** `mysql.govdata.ca` (208.113.245.211, the same server as
      `mysql.tbay.tk`) accepts connections on 3306 from the whole internet. Set its allowable hosts to the DreamHost
      web server.

How the three above get done: the owner changes the passwords and allowable hosts in the DreamHost panel, then the
droplet desk (mcp-manager) writes the new values into `wp-config.php` without displaying them and re-checks the site.

## Production database server (govdata-serving)

- [ ] **Memory headroom.** The 4 GB droplet (s-2vcpu-4gb-amd, $28/mo) was out-of-memory killed once, during a
      riding-cache warm on 2026-11-20. It was tuned (see the README / commit history) and is stable, with about 1 GB
      free. If it runs out again, the fix is the 8 GB size (higher monthly cost, owner's call).
- [x] **Weekly encrypted backups of govdata-serving to Google Drive** (2026-11-21). Sundays 08:30 UTC:
      a consistent dump of grantconts (about 370 MB zstd) and the server config, via rclone crypt to
      `kodexcrypt:servers/govdata-serving/{current,history}` (history kept 60 days). Restore test passed (cryptcheck).
      DigitalOcean weekly droplet backups are also on. Watch: `/var/lib/kodex-backup/last-ok` on the droplet.
- [ ] **Back up the DreamHost side too** (WordPress database `govdata_ca_1`, the two cache databases, and
      `wp-content/uploads`). The serving backup above covers only grantconts and the droplet.
- [ ] **Take a DigitalOcean snapshot** of govdata-serving as a "day one of production" restore point (manual;
      about $2/month while kept). Weekly backups keep only the last 4.
- [ ] **Decide when to retire the build droplet** `govdata-build-r1` (s-4vcpu-16gb). It still holds the full build,
      the Hansard text and the raw source files that production leaves out. Archive first.

## Data and content

- [ ] **Review the automated audit flags** (`govdata-flags-v3.csv`, 1,559 flags). Fill in `your_verdict` and keep
      `flag_key`; verdicts can then be loaded into `audit_flag_reviews`.
- [ ] **175 uncertain postal codes.** 127 were fixed with high confidence; 45 medium and 3 low still use their
      original riding. Readers can report corrections from `/postal-code-review/`.
- [ ] **Postal-code accuracy.** About 4% of codes Elections Canada has not confirmed may be in the wrong riding
      (spot check of 50). Fixing that properly needs a full Elections Canada check (a few days of polite lookups).
- [ ] **Contact page.** The "send us a message" links on the flag and postal-code notices email the site's admin
      address. Point them at a contact form when there is one (filter `publictech_postal_feedback_url`).
- [ ] **Batch reports.** $34.9B of grants (Veterans Affairs alone $30.5B) are published only as "batch report │
      rapport en lots" lines with no recipient. Worth raising as a transparency issue.

## Features still to build

- [ ] Make former MPs and abolished ridings public and searchable as history, defaulting to current ridings, with
      the notice banners.
- [ ] Committees section.
- [ ] "Start free trial" button for the paid tiers.
- [ ] Move to PHP 8.4 before PHP 8.2 reaches end of life on 2026-12-31 (test on staging first).
- [ ] Phase 8 (roadmap): investigations subsite.
