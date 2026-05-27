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
	function sanitize_text_field( $s ): string { return trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $s ) ); }
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
if ( ! function_exists( 'connection_aborted' ) ) {
	function connection_aborted(): int { return 0; }
}

// Load only the engine files we want to unit-test. Don't trigger plugin/theme
// bootstrap so we avoid global side effects.
require_once dirname( __DIR__, 2 ) . '/publictech-core/inc/helpers.php';
require_once dirname( __DIR__, 2 ) . '/publictech-core/inc/class-security.php';
