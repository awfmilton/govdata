<?php
/**
 * Builds the static HTML slot for a client-side ad zone.
 *
 * The ad payload is fetched server-side (API key never reaches the browser)
 * and embedded in the slot as a JSON data attribute. The JS loader
 * (gac-loader.js) builds the creative lazily as the slot nears the viewport
 * and reports viewability/clicks to the hub's public /track endpoint using
 * the HMAC token inside the payload — no credentials in the page, no
 * cross-origin preflight.
 *
 * Dimensions set inline reserve the space up front, so ads cause zero
 * layout shift. When no ad is available the shortcode renders nothing.
 *
 * @package GovAdsClient
 */

namespace GovAdsClient;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {}

	/**
	 * Build the slot HTML for a zone. Returns empty string if no ad is available.
	 */
	public function render_slot( string $zone_slug ): string {
		$ad = Fetcher::instance()->get_ad( $zone_slug );
		if ( ! $ad ) {
			return '';
		}

		$hub_url = esc_url( Fetcher::instance()->hub_url() );
		$payload = wp_json_encode( $ad );
		if ( false === $payload ) {
			return '';
		}

		return sprintf(
			'<div class="gac-slot" '
				. 'data-zone="%s" '
				. 'data-hub="%s" '
				. 'data-ad="%s" '
				. 'style="width:%dpx;height:%dpx;min-height:%dpx;" '
				. 'aria-label="%s">'
				. '%s'
				. '</div>',
			esc_attr( $zone_slug ),
			esc_attr( $hub_url ),
			esc_attr( $payload ),
			(int) $ad['width'],
			(int) $ad['height'],
			(int) $ad['height'],
			esc_attr__( 'Advertisement', 'govdata-ads-client' ),
			$this->noscript( $ad )
		);
	}

	private function noscript( ?array $ad ): string {
		if ( ! $ad || empty( $ad['target_url'] ) || empty( $ad['image_url'] ) ) {
			return '';
		}
		return '<noscript><a href="' . esc_url( $ad['target_url'] ) . '" target="_blank" rel="noopener sponsored">'
			. '<img src="' . esc_url( $ad['image_url'] ) . '" alt="' . esc_attr__( 'Advertisement', 'govdata-ads-client' ) . '" loading="lazy">'
			. '</a></noscript>';
	}
}
