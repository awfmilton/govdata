<?php
/**
 * Riding-cache warmer — WP-CLI entry point.
 *
 * Recomputes the per-riding spending rollups from the primary grantconts
 * database and writes them as JSON into the secondary cache database. This is
 * the recommended way to run the (potentially long) full warm; the weekly
 * WP-Cron event runs the same engine.
 *
 * Run from the WordPress root:
 *
 *   wp eval-file wp-content/themes/publictech-core/../../../database/warm-riding-cache.php
 *
 * or, more simply, copy it somewhere convenient and:
 *
 *   wp eval-file warm-riding-cache.php                 # warm every riding
 *   wp eval-file warm-riding-cache.php --riding=35055  # warm a single riding (trial)
 *
 * Requires the pt-grantconts-01 and pt-cache-02 connections to be configured
 * (see database/mu-plugins/00-db-connections.php).
 *
 * @package PublicTech\Core
 */

defined( 'ABSPATH' ) || die( "Run via: wp eval-file warm-riding-cache.php\n" );

if ( ! class_exists( \PublicTech\Core\Riding_Cache_Warmer::class ) ) {
	WP_CLI::error( 'PublicTech Core is not active — activate the theme before warming the cache.' );
}

// Parse an optional --riding= argument from argv.
$pt_only_riding = '';
foreach ( $GLOBALS['argv'] ?? [] as $pt_arg ) {
	if ( str_starts_with( (string) $pt_arg, '--riding=' ) ) {
		$pt_only_riding = substr( (string) $pt_arg, strlen( '--riding=' ) );
	}
}

$pt_logger = static function ( string $msg, string $level ): void {
	switch ( $level ) {
		case 'error':
			WP_CLI::warning( $msg );
			break;
		case 'success':
			WP_CLI::success( $msg );
			break;
		case 'warn':
			WP_CLI::warning( $msg );
			break;
		default:
			WP_CLI::log( $msg );
	}
};

WP_CLI::log( 'Starting riding-cache warm…' );

try {
	$pt_warmer  = new \PublicTech\Core\Riding_Cache_Warmer( $pt_logger );
	$pt_summary = $pt_warmer->run( $pt_only_riding ? [ 'only_riding' => $pt_only_riding ] : [] );
} catch ( \Throwable $e ) {
	WP_CLI::error( 'Warm failed: ' . $e->getMessage() );
}

WP_CLI::success( sprintf(
	'Wrote %d ridings (%s agreements) in %ss.',
	$pt_summary['ridings'],
	number_format_i18n( $pt_summary['agreements'] ),
	$pt_summary['elapsed']
) );
