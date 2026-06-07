<?php
/**
 * [govads zone="zone_slug"] shortcode.
 *
 * Usage: [govads zone="mp_profile_sidebar"]
 * The zone slug must match a zone published on the connected hub site.
 *
 * @package GovAdsClient
 */

namespace GovAdsClient;

defined( 'ABSPATH' ) || exit;

final class Shortcode {

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private bool $assets_queued = false;

	private function __construct() {
		add_shortcode( 'govads', [ $this, 'render' ] );
	}

	/**
	 * @param array<string,string> $atts
	 */
	public function render( array|string $atts ): string {
		$atts = shortcode_atts( [ 'zone' => '' ], $atts, 'govads' );
		$zone = sanitize_key( (string) $atts['zone'] );
		if ( ! $zone ) {
			return '';
		}

		if ( ! $this->assets_queued ) {
			$this->enqueue_assets();
			$this->assets_queued = true;
		}

		return Renderer::instance()->render_slot( $zone );
	}

	private function enqueue_assets(): void {
		wp_enqueue_style(
			'gac-styles',
			GAC_URL . 'assets/css/gac-styles.css',
			[],
			GAC_VERSION
		);

		wp_enqueue_script(
			'gac-loader',
			GAC_URL . 'assets/js/gac-loader.js',
			[],
			GAC_VERSION,
			[ 'strategy' => 'defer', 'in_footer' => true ]
		);
	}
}
