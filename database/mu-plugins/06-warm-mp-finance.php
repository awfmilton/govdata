<?php
/**
 * MP finance warmer — URL-triggered (mirrors 03-warm-riding-cache.php).
 *
 * Visit:
 *   https://govdata.ca/?pt_warm_mp_finance=<TOKEN>
 *
 * @package PublicTech\Core
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', static function () {

	if ( ! isset( $_GET['pt_warm_mp_finance'] ) ) {
		return;
	}

	$pt_token = defined( 'PT_WARM_TOKEN' ) ? PT_WARM_TOKEN : ( defined( 'PT_IMPORT_TOKEN' ) ? PT_IMPORT_TOKEN : '' );
	if ( '' === $pt_token || ! hash_equals( (string) $pt_token, (string) wp_unslash( $_GET['pt_warm_mp_finance'] ) ) ) {
		wp_die( 'Forbidden.', 403 );
	}

	if ( ! class_exists( \PublicTech\Core\MP_Finance_Warmer::class ) ) {
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
		. '</head><body><h2>PublicTech — MP Finance Warmer</h2>';

	$pt_flush = static function (): void {
		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	};

	echo '<p>Warming MP finance cache…</p>';
	$pt_flush();

	try {
		$t0 = microtime( true );
		\PublicTech\Core\MP_Finance_Warmer::run_warm();
		$elapsed = round( microtime( true ) - $t0, 1 );
		echo '<hr><p class="ok">✓ MP finance cache warmed in ' . esc_html( $elapsed ) . 's.</p>';
	} catch ( \Throwable $e ) {
		echo '<p class="err">✗ Warm failed: ' . esc_html( $e->getMessage() ) . '</p>';
	}

	echo '</body></html>';
	exit;
} );
