<?php
/**
 * Wires the govads_zone action hook to the Renderer and conditionally enqueues
 * the JS loader and CSS on pages that contain ad zones.
 *
 * Templates call: do_action( 'govads_zone', 'zone_slug' )
 * This class listens for that action and renders the placeholder div.
 * If the plugin is deactivated the do_action calls are no-ops — no broken layout.
 *
 * @package GovAds
 */

namespace GovAds;

defined( 'ABSPATH' ) || exit;

final class Zones {

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		add_action( 'govads_zone',        [ $this, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ], 25 );
	}

	public function render( string $zone_slug ): void {
		Renderer::instance()->render_zone( $zone_slug );
	}

	public function enqueue(): void {
		if ( ! $this->should_load() ) {
			return;
		}

		wp_enqueue_style(
			'govads-zones',
			GOVADS_URL . 'assets/css/govads-zones.css',
			[],
			GOVADS_VERSION
		);

		wp_enqueue_script(
			'govads-loader',
			GOVADS_URL . 'assets/js/govads-loader.js',
			[],
			GOVADS_VERSION,
			[ 'strategy' => 'defer', 'in_footer' => true ]
		);

		wp_localize_script(
			'govads-loader',
			'GOVADS',
			[
				'rest'  => esc_url_raw( rest_url( GOVADS_REST_NS ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	private function should_load(): bool {
		return is_singular( 'member-parliament' )
			|| is_post_type_archive( 'member-parliament' )
			|| is_page()
			|| (bool) apply_filters( 'govads_force_assets', false );
	}
}
