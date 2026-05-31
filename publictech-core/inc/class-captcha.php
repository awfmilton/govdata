<?php
/**
 * reCAPTCHA v3 server-side verification helper.
 *
 * Reads site key / secret key from WP options set by Captcha_Admin.
 * Call Captcha::verify( $token ) in any REST handler that receives a token
 * from the frontend's grecaptcha.execute() call.
 *
 * If no secret key is configured, verify() returns true (graceful no-op)
 * so existing endpoints keep working before captcha is set up.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Captcha {

	public const OPT_SITE_KEY   = 'pt_recaptcha_v3_site_key';
	public const OPT_SECRET_KEY = 'pt_recaptcha_v3_secret_key';
	public const OPT_THRESHOLD  = 'pt_recaptcha_v3_threshold';
	public const OPT_TEST_MODE  = 'pt_recaptcha_v3_test_mode';

	private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

	/**
	 * Verify a reCAPTCHA v3 token. Returns true when:
	 *   - No secret key is configured (captcha not yet set up — allow through).
	 *   - Token verifies and score >= threshold.
	 * Returns false when token is invalid or score is too low.
	 */
	public static function verify( string $token, string $action = '' ): bool {
		$secret = (string) get_option( self::OPT_SECRET_KEY, '' );
		if ( '' === $secret || '' === $token ) {
			return true; // Not configured — pass through.
		}

		$response = wp_remote_post( self::VERIFY_URL, [
			'timeout' => 5,
			'body'    => [
				'secret'   => $secret,
				'response' => $token,
				'remoteip' => self::client_ip(),
			],
		] );

		if ( is_wp_error( $response ) ) {
			// Network failure — don't block user, log quietly.
			error_log( '[PublicTech] reCAPTCHA verify failed: ' . $response->get_error_message() );
			return true;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['success'] ) ) {
			return false;
		}

		// Optional action check (prevents token reuse across actions).
		if ( $action && isset( $data['action'] ) && $data['action'] !== $action ) {
			return false;
		}

		$threshold = (float) get_option( self::OPT_THRESHOLD, 0.5 );
		$score     = (float) ( $data['score'] ?? 0.0 );

		// Test mode: log but never block.
		if ( get_option( self::OPT_TEST_MODE, false ) ) {
			error_log( sprintf( '[PublicTech] reCAPTCHA score=%0.2f action=%s token=%s', $score, $action, $token ) );
			return true;
		}

		return $score >= $threshold;
	}

	/** Site key for embedding in the page via wp_localize_script / PT_BOOT. */
	public static function site_key(): string {
		return (string) get_option( self::OPT_SITE_KEY, '' );
	}

	private static function client_ip(): string {
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $h ) {
			$val = $_SERVER[ $h ] ?? '';
			if ( $val ) return trim( explode( ',', $val )[0] );
		}
		return '';
	}
}
