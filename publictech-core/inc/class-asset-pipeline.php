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
		add_filter( 'script_loader_tag',  [ $this, 'add_sri' ], 10, 3 );
		add_action( 'wp_head',            [ $this, 'preconnect_hints' ], 1 );
		add_shortcode( 'publictech_terminal', [ $this, 'shortcode' ] );
		add_shortcode( 'publictech_chart',    [ $this, 'shortcode_chart' ] );

		// Serve minified theme assets when they exist (gated on SCRIPT_DEBUG).
		add_filter( 'script_loader_src', [ $this, 'maybe_min' ], 20, 2 );
		add_filter( 'style_loader_src',  [ $this, 'maybe_min' ], 20, 2 );

		// Self-host D3 / Billboard / topojson / d3-sankey / compromise from
		// assets/vendor/ by default — no third-party CDN, no SRI gap, and a
		// faster first paint (same origin). Registered at priority 5 so an
		// operator's own filter at the default priority (10) still wins and can
		// point these back at a CDN.
		$vendor = [
			'publictech_d3_url'            => 'assets/vendor/d3.v7.min.js',
			'publictech_topojson_url'      => 'assets/vendor/topojson-client.min.js',
			'publictech_d3_sankey_url'     => 'assets/vendor/d3-sankey.min.js',
			'publictech_billboard_url'     => 'assets/vendor/billboard.min.js',
			'publictech_billboard_css_url' => 'assets/vendor/billboard.min.css',
			'publictech_compromise_url'    => 'assets/vendor/compromise.min.js',
		];
		foreach ( $vendor as $hook => $rel ) {
			add_filter( $hook, static fn() => PUBLICTECH_CORE_URL . $rel, 5 );
		}
	}

	/**
	 * Swap a theme JS/CSS URL for its `.min` sibling when one exists on disk and
	 * SCRIPT_DEBUG is off. Covers both the parent and child theme asset trees,
	 * preserves the version query string, and silently no-ops (returns the
	 * source URL) when no minified build is present — so a raw git checkout
	 * still works, it just isn't minified until `npm run build` has run.
	 */
	public function maybe_min( string $src, string $handle = '' ): string {
		if ( ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) || '' === $src ) {
			return $src;
		}
		if ( false !== strpos( $src, '.min.' ) ) {
			return $src; // already minified (our build or a vendor lib)
		}
		[ $path, $sep, $query ] = array_pad( preg_split( '/(\?)/', $src, 2, PREG_SPLIT_DELIM_CAPTURE ), 3, '' );
		if ( ! preg_match( '/\.(js|css)$/', $path ) ) {
			return $src;
		}
		foreach ( [
			[ get_stylesheet_directory_uri(), get_stylesheet_directory() ], // child theme
			[ get_template_directory_uri(),   get_template_directory() ],   // parent theme
		] as [ $uri, $dir ] ) {
			$base = $uri . '/assets/';
			if ( 0 === strpos( $path, $base ) ) {
				$rel     = substr( $path, strlen( $uri ) );
				$min_rel = (string) preg_replace( '/\.(js|css)$/', '.min.$1', $rel );
				if ( '' !== $min_rel && is_readable( $dir . $min_rel ) ) {
					return $uri . $min_rel . $sep . $query;
				}
			}
		}
		return $src;
	}

	/**
	 * Add Subresource Integrity to the CDN-hosted D3 bundle. The hash is
	 * pinned to the 7.9.0 release; bump together with the URL above.
	 */
	/**
	 * Add SRI to the CDN-hosted D3 bundle when an operator has supplied a
	 * verified hash via the `publictech_d3_sri` filter. We don't ship a
	 * hard-coded hash because the CDN URL is itself filterable; supplying a
	 * mismatching default would break sites that override the URL.
	 */
	public function add_sri( string $tag, string $handle, string $src ): string {
		$filter = [
			'd3'        => 'publictech_d3_sri',
			'billboard' => 'publictech_billboard_sri',
		][ $handle ] ?? '';
		if ( '' === $filter ) {
			return $tag;
		}
		$sri = (string) apply_filters( $filter, '' );
		if ( '' === $sri ) {
			return $tag;
		}
		return str_replace( '<script ', '<script integrity="' . esc_attr( $sri ) . '" crossorigin="anonymous" referrerpolicy="no-referrer" ', $tag );
	}

	public function preconnect_hints(): void {
		if ( ! $this->needs_d3() ) {
			return;
		}
		// Chart libraries are self-hosted (assets/vendor/), so no CDN preconnect
		// is needed. Fonts still come from Google; preconnect to those.
		echo '<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>' . "\n";
		echo '<link rel="preconnect" href="https://fonts.gstatic.com"  crossorigin>' . "\n";
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

		// Self-hosting D3 is preferable for production; sites can drop
		// d3.v7.min.js into the parent theme's assets/vendor/ and override
		// the URL via the `publictech_d3_url` filter.
		$d3_url = apply_filters(
			'publictech_d3_url',
			'https://cdn.jsdelivr.net/npm/d3@7.9.0/dist/d3.min.js'
		);
		wp_enqueue_script(
			'd3',
			esc_url_raw( $d3_url ),
			[],
			'7.9.0',
			[ 'strategy' => 'defer' ]
		);

		// topojson-client (needed for the choropleth renderer to convert
		// TopoJSON to GeoJSON FeatureCollections). Tiny — ~7kb gzipped.
		wp_enqueue_script(
			'topojson-client',
			esc_url_raw( apply_filters( 'publictech_topojson_url', 'https://cdn.jsdelivr.net/npm/topojson-client@3.1.0/dist/topojson-client.min.js' ) ),
			[],
			'3.1.0',
			[ 'strategy' => 'defer' ]
		);

		// d3-sankey (Phase 2 — needed by the sankey renderer). Also small.
		wp_enqueue_script(
			'd3-sankey',
			esc_url_raw( apply_filters( 'publictech_d3_sankey_url', 'https://cdn.jsdelivr.net/npm/d3-sankey@0.12.3/dist/d3-sankey.min.js' ) ),
			[ 'd3' ],
			'0.12.3',
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

		if ( $this->needs_billboard() ) {
			$this->enqueue_billboard();
		}

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

	/**
	 * [publictech_chart id="123"] embeds a saved Billboard chart anywhere.
	 * The mount div is hydrated client-side via PT.billboard.hydrateMount().
	 */
	public function shortcode_chart( $atts = [], string $content = '' ): string {
		$atts    = shortcode_atts( [ 'id' => 0, 'height' => 320 ], $atts, 'publictech_chart' );
		$post_id = absint( $atts['id'] );
		if ( ! $post_id || 'user-chart' !== get_post_type( $post_id ) ) {
			return '';
		}
		$this->force_d3        = true;
		$this->force_billboard = true;
		$this->enqueue();
		$height = max( 120, min( 900, (int) $atts['height'] ) );
		return sprintf(
			'<div class="pt-billboard-mount" data-pt-chart-id="%d" style="height:%dpx"></div>',
			$post_id,
			$height
		);
	}

	private bool $force_d3        = false;
	private bool $force_billboard = false;

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
		if ( is_singular( 'user-chart' ) || is_post_type_archive( 'user-chart' ) ) {
			return true;
		}
		// bbPress surfaces (forum/topic/reply/user). Loaded conditionally so
		// non-bbPress installs ignore this branch.
		if ( function_exists( 'is_bbpress' ) && is_bbpress() ) {
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

	/**
	 * Billboard is loaded only where users actually compose, browse, or
	 * embed a saved chart. The custom D3 wrappers above are not affected —
	 * Billboard binds alongside them on its own mounts.
	 */
	private function needs_billboard(): bool {
		if ( $this->force_billboard ) {
			return true;
		}
		if ( is_singular( 'user-chart' ) || is_post_type_archive( 'user-chart' ) ) {
			return true;
		}
		if ( is_page() ) {
			$tpl = get_page_template_slug( get_queried_object_id() );
			if ( 'page-templates/template-chart-builder.php' === $tpl
				|| 'page-templates/template-chart-gallery.php' === $tpl ) {
				return true;
			}
		}
		return (bool) apply_filters( 'publictech_force_billboard', false );
	}

	private function enqueue_billboard(): void {
		$bb_js  = (string) apply_filters( 'publictech_billboard_url',     'https://cdn.jsdelivr.net/npm/billboard.js@3.13.0/dist/billboard.min.js' );
		$bb_css = (string) apply_filters( 'publictech_billboard_css_url', 'https://cdn.jsdelivr.net/npm/billboard.js@3.13.0/dist/billboard.min.css' );

		wp_enqueue_style(
			'billboard',
			esc_url_raw( $bb_css ),
			[],
			'3.13.0'
		);
		wp_enqueue_script(
			'billboard',
			esc_url_raw( $bb_js ),
			[ 'd3' ],
			'3.13.0',
			[ 'strategy' => 'defer' ]
		);

		wp_enqueue_script(
			'publictech-chart-spec',
			PUBLICTECH_CORE_URL . 'assets/js/chart-spec.js',
			[],
			PUBLICTECH_CORE_VERSION,
			true
		);
		wp_enqueue_script(
			'publictech-billboard-bridge',
			PUBLICTECH_CORE_URL . 'assets/js/billboard-bridge.js',
			[ 'billboard', 'publictech-chart-theme', 'publictech-chart-spec' ],
			PUBLICTECH_CORE_VERSION,
			true
		);
	}
}
