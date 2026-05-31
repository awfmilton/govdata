<?php
/**
 * Entity reports sync — URL-triggered (mirrors 03-warm-riding-cache.php).
 *
 * Visit:
 *   https://govdata.ca/?pt_sync_entities=<TOKEN>
 *
 * @package PublicTech\Core
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', static function () {

	if ( ! isset( $_GET['pt_sync_entities'] ) ) {
		return;
	}

	$pt_token = defined( 'PT_WARM_TOKEN' ) ? PT_WARM_TOKEN : ( defined( 'PT_IMPORT_TOKEN' ) ? PT_IMPORT_TOKEN : '' );
	if ( '' === $pt_token || ! hash_equals( (string) $pt_token, (string) wp_unslash( $_GET['pt_sync_entities'] ) ) ) {
		wp_die( 'Forbidden.', 403 );
	}

	if ( ! class_exists( \PublicTech\Core\Entity_Reports::class ) ) {
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
		. '</head><body><h2>PublicTech — Entity Reports Sync</h2>';

	$pt_flush = static function (): void {
		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	};

	try {
		$t0      = microtime( true );
		$results = \PublicTech\Core\Entity_Reports::sync_all();
		$elapsed = round( microtime( true ) - $t0, 1 );
		$ok = 0;
		$fail = 0;
		foreach ( $results as $slug => $r ) {
			if ( $r['ok'] ) {
				$ok++;
				echo '<p class="ok">✓ ' . esc_html( $slug ) . ' — '
					. (int) $r['rows'] . ' rows, $'
					. esc_html( number_format( (float) ( $r['total'] ?? 0 ), 0 ) ) . '</p>';
			} else {
				$fail++;
				echo '<p class="err">✗ ' . esc_html( $slug ) . ' — '
					. esc_html( $r['reason'] ?? 'unknown' ) . '</p>';
			}
			$pt_flush();
		}
		echo '<hr><p class="' . ( $fail ? 'warn' : 'ok' ) . '">'
			. ( $fail ? '⚠' : '✓' ) . ' Sync complete in ' . esc_html( $elapsed ) . 's — '
			. $ok . ' OK, ' . $fail . ' failed</p>';
	} catch ( \Throwable $e ) {
		echo '<p class="err">✗ Sync failed: ' . esc_html( $e->getMessage() ) . '</p>';
	}

	echo '</body></html>';
	exit;
} );
