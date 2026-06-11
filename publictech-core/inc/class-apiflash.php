<?php
/**
 * ApiFlash REST screenshot helper.
 *
 * Thin server-side wrapper around the ApiFlash urltoimage API
 * (https://apiflash.com). Reads the access key from a wp-config constant
 * (PUBLICTECH_APIFLASH_KEY) when present, otherwise from the WP option set on
 * the ApiFlash admin page. All calls are made server-side so the access key is
 * never exposed to the browser.
 *
 * Used by:
 *   - ApiFlash_Admin            quota viewer + connection/capture test
 *   - Chart_Snapshots (future)  off-host rendering of social cards
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class ApiFlash {

	/** Option holding the access key when no constant is defined. */
	public const OPT_KEY = 'pt_apiflash_key';

	/** Transient caching the quota response (epoch-bounded by ApiFlash anyway). */
	public const QUOTA_TRANSIENT = 'pt_apiflash_quota';

	/** How long to cache a quota lookup before refetching (seconds). */
	public const QUOTA_TTL = 300;

	private const BASE_URL  = 'https://api.apiflash.com/v1/urltoimage';
	private const QUOTA_URL = 'https://api.apiflash.com/v1/urltoimage/quota';

	/**
	 * The active access key: constant wins over option so production secrets can
	 * live outside the database. Returns '' when nothing is configured.
	 */
	public static function access_key(): string {
		if ( defined( 'PUBLICTECH_APIFLASH_KEY' ) && '' !== (string) PUBLICTECH_APIFLASH_KEY ) {
			return (string) PUBLICTECH_APIFLASH_KEY;
		}
		return (string) get_option( self::OPT_KEY, '' );
	}

	/** Is the key pinned by a wp-config constant (and therefore not editable in admin)? */
	public static function key_is_constant(): bool {
		return defined( 'PUBLICTECH_APIFLASH_KEY' ) && '' !== (string) PUBLICTECH_APIFLASH_KEY;
	}

	public static function is_configured(): bool {
		return '' !== self::access_key();
	}

	/** Last 4 characters of the key for safe display (e.g. "••••75cc"). */
	public static function key_hint(): string {
		$key = self::access_key();
		if ( '' === $key ) {
			return '';
		}
		return str_repeat( '•', 4 ) . substr( $key, -4 );
	}

	/**
	 * Fetch quota usage: { limit, remaining, reset }. Cached for QUOTA_TTL
	 * seconds unless $force. On the wire ApiFlash returns epoch `reset`; we add
	 * a local `fetched` timestamp so the UI can show how fresh the figure is.
	 *
	 * @return array{limit:int,remaining:int,reset:int,fetched:int}|\WP_Error
	 */
	public static function quota( bool $force = false ) {
		if ( ! self::is_configured() ) {
			return new \WP_Error( 'pt_apiflash_unconfigured', __( 'No ApiFlash access key is configured.', 'publictech-core' ) );
		}
		if ( ! $force ) {
			$cached = get_transient( self::QUOTA_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$resp = wp_remote_get(
			self::QUOTA_URL . '?access_key=' . rawurlencode( self::access_key() ),
			[ 'timeout' => 15 ]
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );
		if ( 200 !== $code ) {
			return new \WP_Error(
				'pt_apiflash_quota_http',
				/* translators: 1: HTTP status code, 2: response body */
				sprintf( __( 'ApiFlash quota call failed (HTTP %1$d): %2$s', 'publictech-core' ), $code, self::error_message( $body ) ),
				[ 'status' => $code ]
			);
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || ! isset( $data['limit'], $data['remaining'], $data['reset'] ) ) {
			return new \WP_Error( 'pt_apiflash_quota_parse', __( 'Unexpected quota response from ApiFlash.', 'publictech-core' ) );
		}

		$quota = [
			'limit'     => (int) $data['limit'],
			'remaining' => (int) $data['remaining'],
			'reset'     => (int) $data['reset'],
			'fetched'   => time(),
		];
		set_transient( self::QUOTA_TRANSIENT, $quota, self::QUOTA_TTL );
		return $quota;
	}

	/** Drop the cached quota so the next quota() hits the API. */
	public static function flush_quota_cache(): void {
		delete_transient( self::QUOTA_TRANSIENT );
	}

	/**
	 * Capture a screenshot of $url.
	 *
	 * Returns the raw response so callers can use it differently: the admin
	 * test tool requests `response_type=json` (gets a hosted image URL + error
	 * messages), while the snapshot pipeline will request the default binary
	 * image and persist the bytes.
	 *
	 * @param string               $url    Absolute http(s) URL to capture.
	 * @param array<string,scalar> $params Extra ApiFlash params (width, height, format, …).
	 *
	 * @return array{code:int,body:string,content_type:string,quota:array<string,int>}|\WP_Error
	 */
	public static function capture( string $url, array $params = [] ) {
		if ( ! self::is_configured() ) {
			return new \WP_Error( 'pt_apiflash_unconfigured', __( 'No ApiFlash access key is configured.', 'publictech-core' ) );
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return new \WP_Error( 'pt_apiflash_bad_url', __( 'Capture URL must be http(s).', 'publictech-core' ) );
		}

		$query = array_merge(
			[
				'access_key' => self::access_key(),
				'url'        => $url,
				'wait_until' => 'page_loaded',
			],
			$params
		);

		$resp = wp_remote_get(
			self::BASE_URL . '?' . http_build_query( $query ),
			[ 'timeout' => 60 ]
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		// ApiFlash surfaces usage in response headers on a successful capture;
		// fold them into the cached quota so the dashboard stays current without
		// a second API round-trip.
		$quota = self::quota_from_headers( $resp );
		if ( $quota ) {
			$quota['fetched'] = time();
			set_transient( self::QUOTA_TRANSIENT, $quota, self::QUOTA_TTL );
		}

		return [
			'code'         => (int) wp_remote_retrieve_response_code( $resp ),
			'body'         => (string) wp_remote_retrieve_body( $resp ),
			'content_type' => (string) wp_remote_retrieve_header( $resp, 'content-type' ),
			'quota'        => $quota,
		];
	}

	/**
	 * Pull X-Quota-* headers off a response into a quota array, or [] if absent.
	 *
	 * @param array|\WP_Error $resp
	 *
	 * @return array{limit:int,remaining:int,reset:int}|array{}
	 */
	private static function quota_from_headers( $resp ): array {
		$limit     = wp_remote_retrieve_header( $resp, 'x-quota-limit' );
		$remaining = wp_remote_retrieve_header( $resp, 'x-quota-remaining' );
		$reset     = wp_remote_retrieve_header( $resp, 'x-quota-reset' );
		if ( '' === $limit || '' === $remaining ) {
			return [];
		}
		return [
			'limit'     => (int) $limit,
			'remaining' => (int) $remaining,
			'reset'     => (int) $reset,
		];
	}

	/**
	 * Best-effort human message out of an ApiFlash error body (JSON with a
	 * `message` field, or the raw string).
	 */
	public static function error_message( string $body ): string {
		$data = json_decode( $body, true );
		if ( is_array( $data ) && isset( $data['message'] ) ) {
			return (string) $data['message'];
		}
		$body = trim( wp_strip_all_tags( $body ) );
		return '' === $body ? __( '(no detail provided)', 'publictech-core' ) : $body;
	}
}
