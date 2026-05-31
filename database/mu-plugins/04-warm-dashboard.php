<?php
/**
 * Dashboard stats warmer — URL-triggered (mirrors 03-warm-riding-cache.php).
 *
 * Visit:
 *   https://govdata.ca/?pt_warm_dashboard=<TOKEN>
 *
 * @package PublicTech\Core
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', static function () {

	if ( ! isset( $_GET['pt_warm_dashboard'] ) ) {
		return;
	}

	$pt_token = defined( 'PT_WARM_TOKEN' ) ? PT_WARM_TOKEN : ( defined( 'PT_IMPORT_TOKEN' ) ? PT_IMPORT_TOKEN : '' );
	if ( '' === $pt_token || ! hash_equals( (string) $pt_token, (string) wp_unslash( $_GET['pt_warm_dashboard'] ) ) ) {
		wp_die( 'Forbidden.', 403 );
	}

	if ( ! class_exists( \PublicTech\Core\Dashboard_Stats::class ) ) {
		wp_die( 'PublicTech Core is not active.', 500 );
	}

	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 0 );
	}
	@ini_set( 'memory_limit', '512M' );

	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Accel-Buffering: no' );
	echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
		. '<style>body{font-family:monospace;padding:1rem;line-height:1.5}'
		. 'p{margin:.15rem 0}.ok{color:#090}.warn{color:#c80}.err{color:#c00}</style>'
		. '</head><body><h2>PublicTech — Dashboard Stats Warmer</h2>';

	$pt_flush = static function (): void {
		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	};

	echo '<p>Flushing stale dashboard cache…</p>';
	$pt_flush();
	delete_transient( 'pt_dash_stats_v2' );
	wp_cache_delete( 'pt_dash_stats_v2', 'publictech' );
	echo '<p class="ok">✓ Cache flushed.</p>';
	$pt_flush();

	echo '<p>Running aggregate queries on master_table…</p>';
	$pt_flush();

	try {
		$t0 = microtime( true );
		\PublicTech\Core\Dashboard_Stats::run_warm();
		$elapsed = round( microtime( true ) - $t0, 1 );

		$d = \PublicTech\Core\Dashboard_Stats::get();
		$spend = (float) ( $d['total_spend'] ?? 0 );

		if ( $spend > 0 ) {
			echo '<hr><p class="ok">✓ Dashboard warmed in ' . esc_html( $elapsed ) . 's</p>';
			echo '<p>Total spend: $' . esc_html( number_format( $spend, 0 ) ) . '</p>';
			echo '<p>Agreements: ' . esc_html( number_format( (int) ( $d['total_agreements'] ?? 0 ) ) ) . '</p>';
			echo '<p>Year series: ' . count( $d['by_year'] ?? [] ) . ' entries</p>';
			echo '<p>Province series: ' . count( $d['by_province'] ?? [] ) . ' entries</p>';
		} else {
			echo '<hr><p class="warn">⚠ Warm completed in ' . esc_html( $elapsed ) . 's but total_spend is $0.</p>';
			echo '<p>Check PHP error log for details. The query may have timed out.</p>';
		}
	} catch ( \Throwable $e ) {
		echo '<p class="err">✗ Warm failed: ' . esc_html( $e->getMessage() ) . '</p>';
	}

	echo '</body></html>';
	exit;
} );
