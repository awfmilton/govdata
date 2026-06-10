<?php
/**
 * Fetches ad payloads from the GovData Ad Services hub REST API.
 *
 * All hub calls happen server-side so the API key never leaves this server.
 * Results are cached in a 60-second transient per zone so high-traffic sites
 * don't hammer the hub on every page load. On error, a 30-second negative-cache
 * transient prevents retry storms.
 *
 * Payloads are sanitised on ingest (defence in depth — even a compromised or
 * misconfigured hub cannot inject markup into this site).
 *
 * @package GovAdsClient
 */

namespace GovAdsClient;

defined( 'ABSPATH' ) || exit;

final class Fetcher {

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {}

	/**
	 * Fetch an ad payload for the given zone slug.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_ad( string $zone_slug ): array|null {
		$hub_url = get_option( 'gac_hub_url', '' );
		$api_key = get_option( 'gac_api_key', '' );

		if ( ! $hub_url || ! $api_key ) {
			return null;
		}

		$cache_key = 'gac_ad_' . sanitize_key( $zone_slug );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		$url      = trailingslashit( $hub_url ) . 'wp-json/govads/v1/serve?zone=' . urlencode( $zone_slug );
		$response = wp_remote_get(
			$url,
			[
				'timeout' => 3,
				'headers' => [ 'X-GovAds-Site-Key' => $api_key ],
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $cache_key, false, 30 ); // negative cache
			return null;
		}

		$raw = json_decode( wp_remote_retrieve_body( $response ), true );
		$ad  = is_array( $raw ) ? $this->sanitize_payload( $raw ) : null;
		if ( ! $ad ) {
			set_transient( $cache_key, false, 30 );
			return null;
		}

		set_transient( $cache_key, $ad, 60 );
		return $ad;
	}

	public function hub_url(): string {
		return (string) get_option( 'gac_hub_url', '' );
	}

	/**
	 * Whitelist and sanitise every field of the hub response before it is
	 * cached or rendered. Returns null when the payload is unusable.
	 *
	 * @param array<string,mixed> $raw Decoded hub response.
	 * @return array<string,mixed>|null
	 */
	private function sanitize_payload( array $raw ): array|null {
		$ad_id = (int) ( $raw['ad_id'] ?? 0 );
		if ( $ad_id <= 0 ) {
			return null;
		}

		$type = in_array( $raw['type'] ?? '', [ 'image', 'html5' ], true ) ? $raw['type'] : 'image';

		return [
			'ad_id'            => $ad_id,
			'zone_id'          => (int) ( $raw['zone_id'] ?? 0 ),
			'campaign_id'      => (int) ( $raw['campaign_id'] ?? 0 ),
			'size'             => sanitize_text_field( (string) ( $raw['size'] ?? '300x250' ) ),
			'width'            => min( 2000, max( 1, (int) ( $raw['width']  ?? 300 ) ) ),
			'height'           => min( 2000, max( 1, (int) ( $raw['height'] ?? 250 ) ) ),
			'type'             => $type,
			'image_url'        => esc_url_raw( (string) ( $raw['image_url'] ?? '' ) ),
			'html'             => 'html5' === $type ? wp_kses_post( (string) ( $raw['html'] ?? '' ) ) : null,
			'target_url'       => esc_url_raw( (string) ( $raw['target_url'] ?? '' ) ),
			'impression_token' => sanitize_text_field( (string) ( $raw['impression_token'] ?? '' ) ),
		];
	}
}
