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
 *  ⚠  DEVELOPMENT CREDENTIALS — ROTATE BEFORE PRODUCTION.  ⚠
 *
 *  Per the project owner, every database currently shares one development
 *  password. It is committed here so the feature works with zero setup during
 *  development. Before going live:
 *    1. Give each database its own strong password.
 *    2. Move these definitions into wp-config.php (outside the web root / repo)
 *       or a secrets manager, and delete the credentials from this file.
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
 * Shared development password. CHANGE ME before production (see header).
 */
if ( ! defined( 'PT_DEV_DB_PASSWORD' ) ) {
	define( 'PT_DEV_DB_PASSWORD', 'REDACTED_DB_PASSWORD' );
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
