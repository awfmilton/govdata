<?php
/**
 * Conditional asset pipeline.
 *
 * D3 + chart wrappers are heavy (~80kb gzipped). Only enqueue them on pages
 * that actually render audit views. Detection is conservative: it triggers on
 *  - the member-parliament archive/single,
 *  - any page using a publictech-* page template (child theme),
 *  - any page that requests the asset via the [publictech_terminal] shortcode,
 *  - any admin context that explicitly opts in (block editor preview).
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Asset_Pipeline {
	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ], 20 );
		add_shortcode( 'publictech_terminal', [ $this, 'shortcode' ] );
	}

	public function enqueue(): void {
		// Always: the lightweight bootloader (theme switch, customizer button).
		wp_enqueue_style(
			'publictech-core',
			PUBLICTECH_CORE_URL . 'assets/css/core.css',
			[],
			PUBLICTECH_CORE_VERSION
		);

		if ( ! $this->needs_d3() ) {
			return;
		}

		wp_enqueue_script(
			'd3',
			'https://d3js.org/d3.v7.min.js',
			[],
			'7.9.0',
			[ 'strategy' => 'defer' ]
		);

		wp_register_script(
			'publictech-chart-theme',
			PUBLICTECH_CORE_URL . 'assets/js/chart-theme.js',
			[ 'd3' ],
			PUBLICTECH_CORE_VERSION,
			true
		);

		wp_register_script(
			'publictech-charts',
			PUBLICTECH_CORE_URL . 'assets/js/d3-charts.js',
			[ 'd3', 'publictech-chart-theme' ],
			PUBLICTECH_CORE_VERSION,
			true
		);

		wp_register_script(
			'publictech-search',
			PUBLICTECH_CORE_URL . 'assets/js/search.js',
			[ 'wp-i18n' ],
			PUBLICTECH_CORE_VERSION,
			true
		);

		wp_register_script(
			'publictech-stream',
			PUBLICTECH_CORE_URL . 'assets/js/stream-table.js',
			[ 'wp-i18n' ],
			PUBLICTECH_CORE_VERSION,
			true
		);

		wp_register_script(
			'publictech-mcp-console',
			PUBLICTECH_CORE_URL . 'assets/js/mcp-console.js',
			[ 'wp-i18n', 'publictech-charts' ],
			PUBLICTECH_CORE_VERSION,
			true
		);

		wp_enqueue_script( 'publictech-charts' );
		wp_enqueue_script( 'publictech-search' );
		wp_enqueue_script( 'publictech-stream' );
		wp_enqueue_script( 'publictech-mcp-console' );

		$uid     = get_current_user_id() ?: null;
		$tiers   = Access_Tiers::instance();
		$saved   = $uid ? json_decode( (string) get_user_meta( $uid, 'pt_chart_theme', true ), true ) : null;

		wp_localize_script( 'publictech-charts', 'PT_BOOT', [
			'rest'       => esc_url_raw( rest_url( PUBLICTECH_CORE_REST_NS ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'tier'       => $tiers->user_tier( $uid ),
			'quota'      => $tiers->quota( $uid ),
			'chartTheme' => is_array( $saved ) ? $saved : null,
			'schemas'    => Multi_DB::instance()->aliases(),
			'i18n'       => [
				'noResults'    => __( 'No matching records.', 'publictech-core' ),
				'queryError'   => __( 'Query failed.', 'publictech-core' ),
				'rateLimit'    => __( 'Rate limit reached — slow down or upgrade your tier.', 'publictech-core' ),
				'tierLocked'   => __( 'This view requires a paid tier.', 'publictech-core' ),
			],
		] );
	}

	public function shortcode( $atts = [], string $content = '' ): string {
		// Loading the shortcode forces the asset pipeline on its render pass.
		$this->force_d3 = true;
		$this->enqueue();
		return '<div id="pt-terminal" class="pt-terminal-mount" data-content="' . esc_attr( $content ) . '"></div>';
	}

	private bool $force_d3 = false;

	private function needs_d3(): bool {
		if ( $this->force_d3 ) {
			return true;
		}
		if ( is_singular( 'member-parliament' ) || is_post_type_archive( 'member-parliament' ) ) {
			return true;
		}
		if ( is_singular( 'forum-thread' ) || is_post_type_archive( 'forum-thread' ) ) {
			return true;
		}
		if ( is_page() ) {
			$tpl = get_page_template_slug( get_queried_object_id() );
			if ( $tpl && str_starts_with( $tpl, 'page-templates/template-' ) ) {
				return true;
			}
		}
		return (bool) apply_filters( 'publictech_force_assets', false );
	}
}
