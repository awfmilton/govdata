# pt-chart-snapshot — static social cards for saved charts

Social crawlers (Facebook's og:image scraper, X's card bot) don't execute
JavaScript, so a client-hydrated Billboard.js mount is invisible to them.
This toolkit renders each saved chart headlessly and stores a static
1200x630 PNG that `Chart_Snapshots` (publictech-core) then advertises via
`og:image` / `twitter:image` on the single chart view.

## End-to-end flow

```
Charts_REST::create()/update()            (REST response returns immediately)
        └─ Chart_Snapshots::queue()       single WP-Cron event per chart + spawn_cron()
              └─ run_capture()            picks new rev filename chart-{id}-{hash}.png,
                 │                        retires the old file, dispatches:
                 │
                 ├─ WEBHOOK MODE          non-blocking signed POST → server.mjs
                 │    └─ Puppeteer opens  {permalink}?pt_raw_render=1
                 │       (chrome-free 1200x630 template, transitions frozen),
                 │       waits for [data-pt-rendered="1"], screenshots, then
                 │       POSTs the PNG → /publictech/v1/charts/{id}/snapshot
                 │       (HMAC headers; WP validates + writes uploads/pt-charts/)
                 │
                 └─ LOCAL MODE            detached `node capture.mjs` writes the
                                          PNG straight into uploads/pt-charts/
```

The rev hash covers the chart's modified date + spec + source, so every
visual change produces a **new public URL** — scraper caches bust themselves
without manual purging. `wp_head` output only references the image once the
file actually exists; until then cards degrade to a text summary.

## Install

```sh
cd tools/chart-snapshot
npm install            # pulls Puppeteer + a pinned Chromium
```

On headless Debian/Ubuntu you may need Chromium's shared libraries:
`npx puppeteer browsers install chrome` prints what's missing, or install
`chromium` from apt and point `PUPPETEER_EXECUTABLE_PATH` at it.

## Mode A — local one-shot (same host as WordPress)

WP backgrounds a short-lived Node process per capture. Zero daemons; fine
for small/medium sites.

```php
// wp-config.php
define( 'PUBLICTECH_SNAPSHOT_NODE_BIN', '/usr/bin/node' );
define( 'PUBLICTECH_SNAPSHOT_SCRIPT',   '/opt/pt-chart-snapshot/capture.mjs' );
```

Requirements: PHP `exec()` not disabled, and the PHP-FPM user able to run
Node and write to `wp-content/uploads/pt-charts/`. Test by hand first:

```sh
node capture.mjs \
  --url="https://example.org/chart/spending-by-region/?pt_raw_render=1" \
  --out=/tmp/test.png
```

## Mode B — webhook microservice (recommended for busy sites)

A resident service owns one warm browser, applies a queue (newest job per
chart wins), and delivers PNGs back over a signed ingest call — WP never
blocks and needs no `exec()`.

```php
// wp-config.php
define( 'PUBLICTECH_SNAPSHOT_SECRET',  'use-a-long-random-string' );
define( 'PUBLICTECH_SNAPSHOT_WEBHOOK', 'http://127.0.0.1:8787/render' );
```

```sh
PT_SNAPSHOT_SECRET='use-a-long-random-string' \
PT_SNAPSHOT_ALLOWED_ORIGINS='https://example.org' \
node server.mjs
```

Co-located with WP? Skip the HTTP delivery hop and write directly:

```sh
PT_SNAPSHOT_OUTPUT_DIR=/var/www/wp-content/uploads/pt-charts node server.mjs
```

### systemd unit

```ini
[Unit]
Description=PublicTech chart snapshot service
After=network.target

[Service]
User=ptsnapshot
WorkingDirectory=/opt/pt-chart-snapshot
Environment=PT_SNAPSHOT_SECRET=use-a-long-random-string
Environment=PT_SNAPSHOT_ALLOWED_ORIGINS=https://example.org
ExecStart=/usr/bin/node server.mjs
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

## Security model

- **Shared-secret HMAC, both directions.** WP signs every job
  (`chart_id`, `filename`, `render_url`, `ingest_url`, `issued_at`); the
  service signs every delivery (`chart_id`, `filename`, timestamp,
  SHA-256 of the PNG bytes). Signatures are newline-joined HMAC-SHA256,
  checked in constant time, with a ±10 minute freshness window.
- **Origin allowlist.** The service refuses jobs whose render/ingest URLs
  point outside `PT_SNAPSHOT_ALLOWED_ORIGINS`, so a leaked port can't be
  used as an SSRF proxy.
- **WP-side ingest hardening.** Filename must match the rev WP currently
  expects (stale captures get 409), payload must parse as a real PNG of
  card-like dimensions, 4 MB cap, atomic tmp+rename write, and the
  uploads/pt-charts/ dir carries an index.html listing guard.
- **Private charts never get cards.** queue()/ingest both require
  `publish` status, and unpublishing/trashing/deleting deletes the file.
- Bind the service to localhost or a private network; it has no TLS of its
  own. Keep the Chromium sandbox on — `PT_SNAPSHOT_NO_SANDBOX=1` is for
  containers that can't provide a user namespace, and such containers
  should be isolated accordingly.

## Operational notes

- **WP-Cron latency:** queue() spawns cron immediately, but on
  `DISABLE_WP_CRON` sites the capture waits for the system crontab tick —
  a `*/1` wp-cron.php cron keeps cards fresh within a minute.
- **Scraper refresh:** after big changes you can force a re-scrape with the
  [Facebook Sharing Debugger](https://developers.facebook.com/tools/debug/)
  and [X Card Validator](https://cards-dev.twitter.com/validator); normally
  the rev-hashed URL makes this unnecessary.
- **Retina cards:** `--scale=2` (CLI) / `PT_SNAPSHOT_SCALE=2` (service)
  renders 2400x1260 at the same 1.91:1 ratio. The emitted
  `og:image:width/height` hints stay 1200x630, which scrapers treat as
  layout hints only.
- **Fonts:** captures wait for `document.fonts.ready` plus a settle delay
  (`--settle`, default 400 ms) so JetBrains Mono/Montserrat land before the
  screenshot.
