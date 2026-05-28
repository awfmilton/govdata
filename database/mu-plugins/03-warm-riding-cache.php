<?php
/**
 * Riding-cache warmer — URL-triggered (mirrors 02-import-mps.php).
 *
 * Place this file in:
 *   wp-content/mu-plugins/03-warm-riding-cache.php
 *
 * Add a secret token to wp-config.php (reuse PT_IMPORT_TOKEN or set your own):
 *   define( 'PT_WARM_TOKEN', 'replace-with-a-long-random-string' );
 *
 * Then visit (logged in or not — the token is the only gate):
 *   https://govdata.ca/?pt_warm_riding_cache=replace-with-a-long-random-string
 *   https://govdata.ca/?pt_warm_riding_cache=TOKEN&riding=35055   (single riding)
 *
 * The page streams progress and prints a final summary. The warm is idempotent
 * — safe to re-run after a data refresh. For very large datasets prefer the
 * WP-CLI script (database/warm-riding-cache.php) so it isn't bound by the web
 * server's max execution time. Delete this file from mu-plugins when done.
 *
 * @package PublicTech\Core
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', static function () {

	if ( ! isset( $_GET['pt_warm_riding_cache'] ) ) {
		return;
	}

	$pt_token = defined( 'PT_WARM_TOKEN' ) ? PT_WARM_TOKEN : ( defined( 'PT_IMPORT_TOKEN' ) ? PT_IMPORT_TOKEN : '' );
	if ( '' === $pt_token || ! hash_equals( (string) $pt_token, (string) wp_unslash( $_GET['pt_warm_riding_cache'] ) ) ) {
		wp_die( 'Forbidden.', 403 );
	}

	if ( ! class_exists( \PublicTech\Core\Riding_Cache_Warmer::class ) ) {
		wp_die( 'PublicTech Core is not active.', 500 );
	}

	// Long batch: lift limits where the host allows it.
	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 0 );
	}
	@ini_set( 'memory_limit', '512M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Accel-Buffering: no' );
	echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
		. '<style>body{font-family:monospace;padding:1rem;line-height:1.5}'
		. 'p{margin:.15rem 0}.ok{color:#090}.warn{color:#c80}.err{color:#c00}</style>'
		. '</head><body><h2>PublicTech — Riding Cache Warmer</h2>';

	$pt_flush = static function (): void {
		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	};

	$pt_logger = static function ( string $msg, string $level ) use ( $pt_flush ): void {
		$class = in_array( $level, [ 'error', 'warn' ], true ) ? ( 'error' === $level ? 'err' : 'warn' )
			: ( 'success' === $level ? 'ok' : '' );
		echo '<p' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . esc_html( $msg ) . '</p>';
		$pt_flush();
	};

	$pt_only = isset( $_GET['riding'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['riding'] ) ) : '';

	try {
		$pt_warmer  = new \PublicTech\Core\Riding_Cache_Warmer( $pt_logger );
		$pt_summary = $pt_warmer->run( $pt_only ? [ 'only_riding' => $pt_only ] : [] );
		echo '<hr><p class="ok">✓ Wrote ' . (int) $pt_summary['ridings'] . ' ridings ('
			. esc_html( number_format_i18n( $pt_summary['agreements'] ) ) . ' agreements) in '
			. esc_html( (string) $pt_summary['elapsed'] ) . 's.</p>';
		echo '<p>You can now delete <code>wp-content/mu-plugins/03-warm-riding-cache.php</code>.</p>';
	} catch ( \Throwable $e ) {
		echo '<p class="err">✗ Warm failed: ' . esc_html( $e->getMessage() ) . '</p>';
	}

	echo '</body></html>';
	exit;
} );
