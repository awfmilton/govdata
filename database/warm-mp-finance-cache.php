<?php
/**
 * MP Finance Cache warmer — WP-CLI entry point.
 *
 * Reads hoc_member_expenditures + hoc_reporting_periods from the primary
 * grantconts database, pivots the four spend columns (salaries, travel,
 * hospitality, contracts) into per-fiscal-year category rows, and writes
 * them into the secondary cache database (govdata_cache_02). Also updates
 * total_spend, travel_spend, and contracts_n post meta on every
 * member-parliament post whose external_mp_id has a matching row.
 *
 * Run from the WordPress root:
 *
 *   wp eval-file database/warm-mp-finance-cache.php
 *
 * Requires the pt-grantconts-01 and pt-cache-02 connections to be configured
 * (see database/mu-plugins/00-db-connections.php).
 *
 * @package PublicTech\Core
 */

defined( 'ABSPATH' ) || die( "Run via: wp eval-file warm-mp-finance-cache.php\n" );

if ( ! class_exists( \PublicTech\Core\MP_Finance_Warmer::class ) ) {
	WP_CLI::error( 'PublicTech Core is not active — activate the theme before warming the cache.' );
}

$pt_logger = static function ( string $msg, string $level ): void {
	switch ( $level ) {
		case 'error':
		case 'warn':
			WP_CLI::warning( $msg );
			break;
		case 'success':
			WP_CLI::success( $msg );
			break;
		default:
			WP_CLI::log( $msg );
	}
};

WP_CLI::log( 'Starting MP finance cache warm…' );

try {
	$pt_warmer  = new \PublicTech\Core\MP_Finance_Warmer( $pt_logger );
	$pt_summary = $pt_warmer->run();
} catch ( \Throwable $e ) {
	WP_CLI::error( 'Warm failed: ' . $e->getMessage() );
}

WP_CLI::success( sprintf(
	'Done. %d unique MPs cached, %d WP posts updated with KPI meta, completed in %ss.',
	$pt_summary['mps'],
	$pt_summary['wp_updated'],
	$pt_summary['elapsed']
) );
