<?php
/**
 * GCP Cloud Run screenshot provider.
 *
 * Self-hosted Puppeteer screenshot API deployed to Google Cloud Run.
 * Authentication is a static API key passed as the `api_key` query parameter;
 * no GCP identity tokens or service accounts are required.
 *
 * wp-config.php constants (optional — can also be set via the admin UI):
 *   define( 'PUBLICTECH_GCP_SCREENSHOT_KEY', 'N7GMaojRTzWIueCl0yf56rnZdiP8SAFV' );
 *   define( 'PUBLICTECH_GCP_SCREENSHOT_URL', 'https://screenshot-api-531209098595.us-central1.run.app' );
 *
 * Example request:
 *   GET {base_url}/capture?url=https://example.com&api_key=KEY&width=1200&height=630&type=png
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class GCP_Screenshot {

	/** WP option for the API key when no constant is defined. */
	public const OPT_KEY = 'pt_gcp_screenshot_key';

	/** WP option for the base URL when no constant is defined. */
	public const OPT_URL = 'pt_gcp_screenshot_url';

	private const DEFAULT_URL = 'https://screenshot-api-531209098595.us-central1.run.app';

	public static function access_key(): string {
		if ( defined( 'PUBLICTECH_GCP_SCREENSHOT_KEY' ) && '' !== (string) PUBLICTECH_GCP_SCREENSHOT_KEY ) {
			return (string) PUBLICTECH_GCP_SCREENSHOT_KEY;
		}
		return (string) get_option( self::OPT_KEY, '' );
	}

	public static function key_is_constant(): bool {
		return defined( 'PUBLICTECH_GCP_SCREENSHOT_KEY' ) && '' !== (string) PUBLICTECH_GCP_SCREENSHOT_KEY;
	}

	public static function is_configured(): bool {
		return '' !== self::access_key();
	}

	/** Last 4 characters of the key for safe display (e.g. "••••SAFV"). */
	public static function key_hint(): string {
		$key = self::access_key();
		return '' === $key ? '' : str_repeat( '•', 4 ) . substr( $key, -4 );
	}

	public static function base_url(): string {
		if ( defined( 'PUBLICTECH_GCP_SCREENSHOT_URL' ) && '' !== (string) PUBLICTECH_GCP_SCREENSHOT_URL ) {
			return rtrim( (string) PUBLICTECH_GCP_SCREENSHOT_URL, '/' );
		}
		$opt = (string) get_option( self::OPT_URL, '' );
		return '' !== $opt ? rtrim( $opt, '/' ) : self::DEFAULT_URL;
	}

	public static function url_is_constant(): bool {
		return defined( 'PUBLICTECH_GCP_SCREENSHOT_URL' ) && '' !== (string) PUBLICTECH_GCP_SCREENSHOT_URL;
	}

	/**
	 * Capture a screenshot via the GCP Cloud Run API.
	 *
	 * GCP always returns raw binary image bytes (no hosted-URL JSON mode).
	 *
	 * Supported params: type (png/jpeg), width, height, scaleFactor, fullPage,
	 * delay (seconds after page load), timeout, darkMode, hideElements (CSS selector),
	 * plainPuppeteer (boolean — fallback render mode for difficult pages).
	 *
	 * Error codes: 401 (bad key), 403 (URL blocked), 429 (queue full — retry later),
	 * 500 (capture failed — try plainPuppeteer=true).
	 *
	 * @param string               $url
	 * @param array<string,scalar> $params
	 *
	 * @return array{code:int,body:string,content_type:string}|\WP_Error
	 */
	public static function capture( string $url, array $params = [] ) {
		if ( ! self::is_configured() ) {
			return new \WP_Error( 'pt_gcp_unconfigured', __( 'No GCP screenshot API key is configured.', 'publictech-core' ) );
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return new \WP_Error( 'pt_gcp_bad_url', __( 'Capture URL must be http(s).', 'publictech-core' ) );
		}

		$query = array_merge(
			[ 'url' => $url, 'api_key' => self::access_key(), 'type' => 'png' ],
			$params
		);

		$resp = wp_remote_get(
			self::base_url() . '/capture?' . http_build_query( $query ),
			[ 'timeout' => 90 ]
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		return [
			'code'         => (int) wp_remote_retrieve_response_code( $resp ),
			'body'         => (string) wp_remote_retrieve_body( $resp ),
			'content_type' => (string) wp_remote_retrieve_header( $resp, 'content-type' ),
		];
	}

	/**
	 * Quick health check — confirms the service is reachable and the key works.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function health_check(): array {
		if ( ! self::is_configured() ) {
			return [ 'ok' => false, 'message' => __( 'API key not configured.', 'publictech-core' ) ];
		}
		$resp = wp_remote_get( self::base_url() . '/', [ 'timeout' => 15 ] );
		if ( is_wp_error( $resp ) ) {
			return [ 'ok' => false, 'message' => $resp->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		return $code < 500
			/* translators: %d: HTTP status code */
			? [ 'ok' => true, 'message' => sprintf( __( 'Service responded HTTP %d.', 'publictech-core' ), $code ) ]
			/* translators: %d: HTTP status code */
			: [ 'ok' => false, 'message' => sprintf( __( 'Service returned HTTP %d.', 'publictech-core' ), $code ) ];
	}

	/** Best-effort human message from a GCP error body. */
	public static function error_message( string $body ): string {
		$data = json_decode( $body, true );
		if ( is_array( $data ) && isset( $data['error'] ) ) {
			return (string) $data['error'];
		}
		$body = trim( wp_strip_all_tags( $body ) );
		return '' === $body ? __( '(no detail provided)', 'publictech-core' ) : $body;
	}
}
