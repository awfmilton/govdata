# Tests

| Suite | Runner | What it proves |
| --- | --- | --- |
| `tests/php/unit/` | PHPUnit 10 + Yoast polyfills | Pure-PHP unit tests over the engine helpers (SQL safety, throttle math, theme metadata, tier catalog shape). Run without WordPress. |
| `.github/workflows/ci.yml:wp-integration` | wp-cli + ad-hoc PHP server | Boots a real WordPress, activates the parent + child + plugin, hits `/publictech/v1/status` and `/publictech-mcp/v1/manifest`. |
| `.github/workflows/ci.yml:plugin-check` | `wordpress/plugin-check-action` | Runs WordPress.org's own plugin linter against the MCP plugin. |
| `.github/workflows/ci.yml:php` (`phpcs`) | WordPress-Extra + PHPCompatibilityWP | WordPress Coding Standards + PHP 8.1+ compatibility. |
| `.github/workflows/ci.yml:php` (`phpstan`) | szepeviktor/phpstan-wordpress | Static analysis at level 5 with WP stubs. |
| `.github/workflows/ci.yml:frontend` | ESLint + Stylelint + Prettier | JS/CSS lint and format-check. |
| `.github/workflows/ci.yml:secrets` | gitleaks | Catches accidentally committed credentials. |

## Running locally

```bash
make install        # composer + npm
make ci             # lint + phpcs + phpstan + phpunit + eslint + stylelint
make test           # phpunit only
make phpcbf         # auto-fix PHPCS-fixable issues
```

The unit tests deliberately do **not** require a WordPress install. They run
against a thin shim in `tests/php/bootstrap.php` so they're fast and don't
need MySQL. Anything that needs a live WP loop (REST round-trips,
`WP_Query`-shaped data, cron) goes in the integration job.
