<?php
/**
 * Refresh the entity-funding cache from the grantconts source database.
 *
 * Reads each registered organisation's agreements out of grantconts (read-only),
 * rolls them up into a JSON snapshot, and writes them into govdata_cache_01 by
 * CALLing sp_upsert_entity_snapshot. Run weekly (a cron does this automatically)
 * or on demand:
 *
 *   wp eval-file database/refresh-entity-cache.php
 *   wp eval-file database/refresh-entity-cache.php -- --slug=egale-canada
 *   wp eval-file database/refresh-entity-cache.php -- --source=pt-grantconts-01
 *
 * Prerequisites:
 *   1. PUBLICTECH_DB_CONNECTIONS['pt-grantconts-01'] is configured in wp-config.php
 *      (the read-only grantconts credentials — already used by the MP importer).
 *   2. The cache schema has been applied (database/cache-schema.sql) and
 *      GOVDATA_CACHE_DB is defined in wp-config.php (or the GOVDATA_CACHE_DB_*
 *      environment variables are set for this invocation).
 *
 * The script is idempotent — each run replaces the entity's cached snapshot.
 *
 * @package PublicTech\Core
 */

defined( 'ABSPATH' ) || die( "Run via: wp eval-file database/refresh-entity-cache.php\n" );

use PublicTech\Core\Cache_DB;
use PublicTech\Core\Entity_Reports;

/** Tiny logger that works under WP-CLI and plain PHP alike. */
$pt_log = static function ( string $msg, string $level = 'log' ): void {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		if ( 'error' === $level ) {
			WP_CLI::warning( $msg );
		} elseif ( 'success' === $level ) {
			WP_CLI::success( $msg );
		} else {
			WP_CLI::log( $msg );
		}
	} else {
		fwrite( STDERR, strtoupper( $level ) . ': ' . $msg . "\n" );
	}
};

if ( ! class_exists( Entity_Reports::class ) || ! class_exists( Cache_DB::class ) ) {
	$pt_log( 'PublicTech Core is not loaded — activate the theme before running this script.', 'error' );
	return;
}

if ( ! Cache_DB::instance()->available() ) {
	$pt_log( 'Cache database is not configured. Define GOVDATA_CACHE_DB in wp-config.php (or set GOVDATA_CACHE_DB_* env vars).', 'error' );
	return;
}

/** Read a --key=value flag from argv. */
$pt_arg = static function ( string $key, string $default = '' ): string {
	foreach ( (array) ( $GLOBALS['argv'] ?? [] ) as $arg ) {
		if ( is_string( $arg ) && str_starts_with( $arg, "--{$key}=" ) ) {
			return substr( $arg, strlen( "--{$key}=" ) );
		}
	}
	return $default;
};

$source = $pt_arg( 'source', Entity_Reports::SOURCE_ALIAS );
$only   = sanitize_title( $pt_arg( 'slug', '' ) );

$entities = Entity_Reports::registry();
if ( '' !== $only ) {
	$entities = array_values( array_filter( $entities, static fn( $e ) => $e['slug'] === $only ) );
	if ( ! $entities ) {
		$pt_log( "No registered entity with slug '{$only}'.", 'error' );
		return;
	}
}

$pt_log( sprintf( 'Refreshing %d entit%s from source "%s"…', count( $entities ), count( $entities ) === 1 ? 'y' : 'ies', $source ) );

$ok_count   = 0;
$fail_count = 0;

foreach ( $entities as $entity ) {
	$res = Entity_Reports::sync_entity( $entity, $source );
	if ( ! empty( $res['ok'] ) ) {
		$ok_count++;
		$pt_log( sprintf(
			'  ✓ %-52s %6s rows  net %s',
			$entity['name'],
			number_format( (int) ( $res['rows'] ?? 0 ) ),
			'$' . number_format( (float) ( $res['total'] ?? 0 ) )
		) );
	} else {
		$fail_count++;
		$pt_log( sprintf( '  ✗ %-52s %s', $entity['name'], $res['reason'] ?? 'failed' ), 'error' );
	}
}

$pt_log( sprintf( 'Done. Synced: %d  Failed: %d', $ok_count, $fail_count ), $fail_count ? 'log' : 'success' );
