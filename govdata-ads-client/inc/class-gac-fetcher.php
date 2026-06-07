<?php
/**
 * Fetches ad payloads from the GovData Ad Services hub REST API.
 *
 * Results are cached in a 60-second transient per zone so high-traffic sites
 * don't hammer the hub on every page load. On error, a 30-second negative-cache
 * transient prevents retry storms.
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

		$ad = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $ad ) || empty( $ad['ad_id'] ) ) {
			set_transient( $cache_key, false, 30 );
			return null;
		}

		set_transient( $cache_key, $ad, 60 );
		return $ad;
	}

	public function hub_url(): string {
		return (string) get_option( 'gac_hub_url', '' );
	}

	public function api_key(): string {
		return (string) get_option( 'gac_api_key', '' );
	}
}
