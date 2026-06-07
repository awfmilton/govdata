<?php
/**
 * Builds the static HTML slot for a client-side ad zone.
 *
 * The slot is a placeholder div sized to the ad's IAB dimensions. The JS
 * loader (gac-loader.js) fills it asynchronously after the page loads,
 * so ads never block rendering. Dimensions set inline prevent layout shift.
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

		$width  = $ad['width']  ?? 300;
		$height = $ad['height'] ?? 250;

		$hub_url = esc_url( Fetcher::instance()->hub_url() );
		$api_key = esc_attr( Fetcher::instance()->api_key() );

		return sprintf(
			'<div class="gac-slot" '
				. 'data-zone="%s" '
				. 'data-hub="%s" '
				. 'data-key="%s" '
				. 'style="width:%dpx;height:%dpx;min-height:%dpx;" '
				. 'aria-label="%s">'
				. '%s'
				. '</div>',
			esc_attr( $zone_slug ),
			$hub_url,
			$api_key,
			(int) $width,
			(int) $height,
			(int) $height,
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
