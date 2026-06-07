<?php
/**
 * Standalone CLI script: regenerate the map riding-totals JSON file.
 *
 * Usage (DreamHost server cron, weekly):
 *   php /home/REDACTED_SSH_USER/govdata.ca/wp-content/themes/govdata/database/refresh-map-json.php
 *
 * Or via WP-CLI:
 *   wp eval '\PublicTech\Core\Map_Cache::refresh();'
 *
 * The script bootstraps WordPress (so it can use publictech_db() and
 * wp_upload_dir()), runs the stored procedure to refresh map_riding_totals
 * from riding_cache, then writes the static JSON file.
 *
 * Exit codes:
 *   0 — success
 *   1 — WordPress bootstrap failed
 *   2 — stored procedure / DB error
 *   3 — JSON file write failed
 */

define( 'PT_MAP_REFRESH_CLI', true );

/* ── Bootstrap WordPress ─────────────────────────────────────────────────── */
// Walk up from this file's location until we find wp-load.php.
$wp_load = null;
$dir     = __DIR__;
for ( $i = 0; $i < 8; $i++ ) {
	$candidate = $dir . '/wp-load.php';
	if ( file_exists( $candidate ) ) { $wp_load = $candidate; break; }
	$dir = dirname( $dir );
}
if ( ! $wp_load ) {
	fwrite( STDERR, "[map-refresh] ERROR: could not find wp-load.php\n" );
	exit( 1 );
}
require_once $wp_load;

if ( ! function_exists( 'publictech_db' ) ) {
	fwrite( STDERR, "[map-refresh] ERROR: publictech_db() not available — is PublicTech Core active?\n" );
	exit( 1 );
}

/* ── Run the stored procedure (refreshes map_riding_totals from riding_cache) */
$alias = \PublicTech\Core\Riding_Cache_Warmer::cache_alias();
try {
	// Create table + SP if not yet set up (first-run bootstrapping).
	$sql_setup = file_get_contents( __DIR__ . '/setup-map-cache.sql' );
	if ( $sql_setup ) {
		$statements = array_filter( array_map( 'trim', explode( ';', $sql_setup ) ) );
		foreach ( $statements as $stmt ) {
			if ( $stmt && ! str_starts_with( ltrim( $stmt ), '--' ) ) {
				try {
					publictech_db( $alias )->query( $stmt );
				} catch ( \Throwable $ignored ) {
					// Idempotent — CREATE IF NOT EXISTS, DROP+CREATE ok
				}
			}
		}
	}
	publictech_db( $alias )->pdo()->exec( 'CALL `sp_refresh_map_totals`()' );
	echo "[map-refresh] Stored procedure ran OK.\n";
} catch ( \Throwable $e ) {
	fwrite( STDERR, "[map-refresh] SP error: " . $e->getMessage() . " — falling back to direct query.\n" );
}

/* ── Write JSON file ─────────────────────────────────────────────────────── */
$result = \PublicTech\Core\Map_Cache::refresh();
if ( null === $result ) {
	fwrite( STDERR, "[map-refresh] ERROR: JSON file write failed.\n" );
	exit( 3 );
}

printf(
	"[map-refresh] Done: %d ridings, %s bytes, %.3fs\n",
	$result['written'],
	number_format( $result['bytes'] ),
	$result['elapsed']
);
echo "[map-refresh] File: " . \PublicTech\Core\Map_Cache::file_path() . "\n";
exit( 0 );
