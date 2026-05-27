<?php
/**
 * Chart-appearance customizer.
 *
 * Two surfaces:
 *
 *  1. WP Customizer panel (`publictech_charts`) — site-wide defaults for
 *     accent colour, grid style, curve style, etc. Stored in theme mods.
 *  2. Per-user overrides written by the in-page customizer window (rendered
 *     by the child theme) and persisted via REST → user_meta `pt_chart_theme`.
 *
 * Both surfaces emit the same shape of JSON, consumed by `chart-theme.js`
 * which patches CSS variables and re-renders D3.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Chart_Customizer {
	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	public const PANEL    = 'publictech_charts';
	public const SECTION  = 'publictech_chart_appearance';
	public const PALETTES = [ '#00a0d2', '#3ddc97', '#ffb454', '#ff6b8a', '#a78bfa', '#f87171', '#fbbf24', '#34d399', '#60a5fa', '#f472b6', '#22d3ee', '#c084fc' ];

	private function __construct() {
		add_action( 'customize_register', [ $this, 'register' ] );
		add_action( 'wp_head',            [ $this, 'emit_css_vars' ], 5 );
	}

	public function register( \WP_Customize_Manager $wp ): void {
		$wp->add_panel( self::PANEL, [
			'title'       => __( 'PublicTech — Charts & Theme', 'publictech-core' ),
			'description' => __( 'Defaults for the audit terminal. Users can override these from the in-page customizer.', 'publictech-core' ),
			'priority'    => 20,
		] );
		$wp->add_section( self::SECTION, [ 'title' => __( 'Chart Appearance', 'publictech-core' ), 'panel' => self::PANEL ] );

		$fields = [
			[ 'pt_accent',      '#00a0d2',   __( 'Accent Color',    'publictech-core' ), 'color' ],
			[ 'pt_curve',       'monotone',  __( 'Curve Style',     'publictech-core' ), [ 'monotone' => 'Smooth', 'linear' => 'Sharp', 'step' => 'Stepped' ] ],
			[ 'pt_stroke',      1.8,         __( 'Line Weight (px)', 'publictech-core' ), 'number' ],
			[ 'pt_marker',      3,           __( 'Marker Size (px)','publictech-core' ), 'number' ],
			[ 'pt_grid',        'dotted',    __( 'Grid Style',      'publictech-core' ), [ 'off' => 'Off', 'dotted' => 'Dotted', 'solid' => 'Solid' ] ],
			[ 'pt_area',        0.35,        __( 'Area Fill (0-1)', 'publictech-core' ), 'number' ],
			[ 'pt_bar',         'solid',     __( 'Bar Style',       'publictech-core' ), [ 'solid' => 'Solid', 'stripe' => 'Striped', 'outline' => 'Outline' ] ],
			[ 'pt_font',        'Montserrat',__( 'Typography',      'publictech-core' ), [ 'Montserrat' => 'Montserrat', 'JetBrains Mono' => 'Mono', 'Georgia' => 'Serif' ] ],
			[ 'pt_anim',        'on',        __( 'Animations',      'publictech-core' ), [ 'on' => 'On', 'off' => 'Off' ] ],
			[ 'pt_default_theme', 'dark',    __( 'Default Theme',   'publictech-core' ), [ 'dark' => 'Dark (terminal)', 'light' => 'Light (paper)' ] ],
		];

		foreach ( $fields as [ $id, $default, $label, $kind ] ) {
			$wp->add_setting( $id, [
				'default'           => $default,
				'sanitize_callback' => is_array( $kind ) ? 'sanitize_key' : ( 'color' === $kind ? 'sanitize_hex_color' : 'sanitize_text_field' ),
				'transport'         => 'postMessage',
			] );

			if ( 'color' === $kind ) {
				$wp->add_control( new \WP_Customize_Color_Control( $wp, $id, [ 'label' => $label, 'section' => self::SECTION ] ) );
			} elseif ( is_array( $kind ) ) {
				$wp->add_control( $id, [ 'type' => 'select',  'label' => $label, 'section' => self::SECTION, 'choices' => $kind ] );
			} else {
				$wp->add_control( $id, [ 'type' => 'number', 'label' => $label, 'section' => self::SECTION ] );
			}
		}
	}

	public function emit_css_vars(): void {
		$accent = get_theme_mod( 'pt_accent', '#00a0d2' );
		$font   = get_theme_mod( 'pt_font',   'Montserrat' );
		$rgb    = sscanf( ltrim( $accent, '#' ), '%2x%2x%2x' );
		if ( ! $rgb || count( $rgb ) !== 3 ) {
			$rgb = [ 0, 160, 210 ];
		}
		printf(
			"<style id=\"publictech-vars\">:root{--accent:%s;--accent-soft:rgba(%d,%d,%d,0.12);--accent-glow:rgba(%d,%d,%d,0.45);--chart-font:%s;--chart-stroke:%s;--chart-marker:%s;--chart-area:%s;}</style>\n",
			esc_attr( $accent ),
			$rgb[0], $rgb[1], $rgb[2],
			$rgb[0], $rgb[1], $rgb[2],
			esc_attr( $font ),
			esc_attr( get_theme_mod( 'pt_stroke', 1.8 ) ),
			esc_attr( get_theme_mod( 'pt_marker', 3 ) ),
			esc_attr( get_theme_mod( 'pt_area',   0.35 ) )
		);
	}
}
