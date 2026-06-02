<?php
/**
 * PublicTech database connections — DEVELOPMENT credentials.
 *
 * Drop this file into wp-content/mu-plugins/ on the WordPress server. It wires
 * the two databases the riding-cache feature needs:
 *
 *   pt-grantconts-01  — PRIMARY, read-only. The Cloud SQL grantconts instance
 *                       the cache warmer reads from (master_table, pcbfr, …).
 *   pt-cache-02       — SECONDARY, read/write. The MySQL instance that stores
 *                       the precomputed per-riding JSON the MP pages read.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  ⚠  DATABASE PASSWORD IS NOT STORED IN THIS FILE.  ⚠
 *
 *  The shared password is read from the PT_DB_PASSWORD environment variable
 *  (or a PT_DEV_DB_PASSWORD / PUBLICTECH_DB_CONNECTIONS definition in
 *  wp-config.php, which wins). If neither is set the aliases resolve with an
 *  empty password and DB auth fails by design — so a secret is never committed.
 *
 *  Production hardening checklist:
 *    1. Give each database its own strong password (set PT_DB_PASSWORD, or
 *       define per-alias creds in wp-config.php / a secrets manager).
 *    2. Keep credentials outside the web root and out of version control.
 *    3. Restrict the grantconts SQL user to SELECT-only and lock the host
 *       allow-list to the WordPress server IPs.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Both a constant (read directly by the one-off importer / CLI scripts) and a
 * filter (merged by the Multi_DB layer) are provided, so the aliases resolve
 * whether or not wp-config.php also defines PUBLICTECH_DB_CONNECTIONS.
 *
 * @package PublicTech\Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared password, sourced from the environment (never committed). A
 * wp-config.php definition of PT_DEV_DB_PASSWORD, if present, wins.
 */
if ( ! defined( 'PT_DEV_DB_PASSWORD' ) ) {
	$pt_db_pass = getenv( 'PT_DB_PASSWORD' );
	define( 'PT_DEV_DB_PASSWORD', is_string( $pt_db_pass ) ? $pt_db_pass : '' );
}

/**
 * The development connection map for this feature.
 *
 * @return array<string,array<string,mixed>>
 */
function pt_dev_db_connections(): array {
	return [
		// PRIMARY — grantconts (read-only). Source of truth for the warmer.
		'pt-grantconts-01' => [
			'host' => '35.230.186.163',
			'port' => 3306,
			'name' => 'grantconts',
			'user' => 'pt_read',
			'pass' => PT_DEV_DB_PASSWORD,
		],
		// SECONDARY — govdata_cache_02 (read/write). Holds the riding JSON.
		'pt-cache-02' => [
			'host' => 'mysql.alexmilton.ca',
			'port' => 3306,
			'name' => 'govdata_cache_02',
			'user' => 'gdc_02',
			'pass' => PT_DEV_DB_PASSWORD,
		],
	];
}

// Define the constant for scripts that read it directly (e.g. the MP importer).
// A wp-config.php definition, if present, wins — the filter below still fills
// in any aliases that definition is missing.
if ( ! defined( 'PUBLICTECH_DB_CONNECTIONS' ) ) {
	define( 'PUBLICTECH_DB_CONNECTIONS', pt_dev_db_connections() );
}

// Merge any missing aliases into whatever the constant/other plugins provide.
add_filter( 'publictech_db_connections', static function ( $defs ): array {
	$defs = is_array( $defs ) ? $defs : [];
	foreach ( pt_dev_db_connections() as $alias => $conn ) {
		if ( ! isset( $defs[ $alias ] ) ) {
			$defs[ $alias ] = $conn;
		}
	}
	return $defs;
} );
