<?php
/**
 * PHPUnit bootstrap.
 *
 * Provides just enough WordPress shimming for the unit-level tests in
 * tests/php/unit/ to load the production classes without requiring a full WP
 * install. Integration tests that need a live WP environment live in
 * tests/php/integration/ and are driven by wp-env in CI (separate config).
 *
 * @package PublicTech\Tests
 */

declare( strict_types=1 );

define( 'ABSPATH',       __DIR__ . '/' );
define( 'WP_DEBUG',      true );
define( 'WPINC',         'wp-includes' );
define( 'AUTH_KEY',      'pt-test-key' );
define( 'NONCE_SALT',    'pt-test-salt' );

// Minimal WP function shims. Each shim mirrors the contract of the real WP
// function closely enough for the units under test; extend as new tests need
// more surface area.

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ): string { return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ): string { return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $s ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ): string {
		$s = (string) $s;
		$s = strip_tags( $s );
		return trim( preg_replace( '/[\r\n\t]+/', ' ', $s ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; }
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $_scheme = 'auth' ): string { return AUTH_KEY . NONCE_SALT; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, $value ) { return $value; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ): bool { return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ): bool { return true; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options | JSON_UNESCAPED_SLASHES, $depth );
	}
}
if ( ! function_exists( 'sanitize_hex_color' ) ) {
	function sanitize_hex_color( string $color ): ?string {
		return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $color ) ? $color : null;
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $s ): string {
		return trim( strip_tags( (string) $s ) );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $n ): int { return abs( (int) $n ); }
}
if ( ! function_exists( 'connection_aborted' ) ) {
	function connection_aborted(): int { return 0; }
}

// Minimal WP_Error stand-in. Real WP_Error has a richer API but Chart_Spec
// only uses get_error_code() / public props.
if ( ! class_exists( '\WP_Error' ) ) {
	class WP_Error {
		public string $code;
		public string $message;
		public array  $data;
		public function __construct( $code = '', $message = '', $data = [] ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
			$this->data    = is_array( $data ) ? $data : [];
		}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}
}

if ( ! defined( 'PUBLICTECH_CORE_REST_NS' ) ) {
	define( 'PUBLICTECH_CORE_REST_NS', 'publictech/v1' );
}

// Load only the engine files we want to unit-test. Don't trigger plugin/theme
// bootstrap so we avoid global side effects.
require_once dirname( __DIR__, 2 ) . '/publictech-core/inc/helpers.php';
// finance-lib helpers (quarter labels, etc.) live in helpers.php — no extra require.
require_once dirname( __DIR__, 2 ) . '/publictech-core/inc/riding-cache-lib.php';
require_once dirname( __DIR__, 2 ) . '/publictech-core/inc/class-security.php';
