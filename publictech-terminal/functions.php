<?php
/**
 * PublicTech Terminal child-theme bootstrap.
 *
 * Adds the visual layer on top of PublicTech Core: terminal CSS, the in-page
 * chart customizer window, the light/dark theme switcher, and page templates
 * for Home, Profile, Forum, AI Console, Stream, and User Settings.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

define( 'PUBLICTECH_TERMINAL_VERSION', '1.0.0' );
define( 'PUBLICTECH_TERMINAL_URL',     trailingslashit( get_stylesheet_directory_uri() ) );
define( 'PUBLICTECH_TERMINAL_DIR',     trailingslashit( get_stylesheet_directory() ) );

add_action( 'wp_enqueue_scripts', static function () {
	wp_enqueue_style(
		'publictech-fonts',
		'https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap',
		[],
		null
	);

	wp_enqueue_style(
		'publictech-terminal',
		PUBLICTECH_TERMINAL_URL . 'assets/css/terminal.css',
		[ 'publictech-core' ],
		PUBLICTECH_TERMINAL_VERSION
	);

	wp_enqueue_style(
		'publictech-terminal-overrides',
		PUBLICTECH_TERMINAL_URL . 'assets/css/overrides.css',
		[ 'publictech-terminal' ],
		PUBLICTECH_TERMINAL_VERSION
	);

	wp_enqueue_style(
		'publictech-terminal-responsive',
		PUBLICTECH_TERMINAL_URL . 'assets/css/responsive.css',
		[ 'publictech-terminal-overrides' ],
		PUBLICTECH_TERMINAL_VERSION
	);

	// bbPress bridge stylesheet — only loaded on bbPress-rendered surfaces so
	// it doesn't bloat unrelated pages. is_bbpress() covers every bbPress view
	// (single forum/topic/reply, archives, search, user profile).
	if ( function_exists( 'is_bbpress' ) && is_bbpress() ) {
		wp_enqueue_style(
			'publictech-bbpress',
			PUBLICTECH_TERMINAL_URL . 'assets/css/bbpress.css',
			[ 'publictech-terminal-overrides' ],
			PUBLICTECH_TERMINAL_VERSION
		);
	}

	wp_enqueue_script(
		'publictech-terminal-app',
		PUBLICTECH_TERMINAL_URL . 'assets/js/app.js',
		[],
		PUBLICTECH_TERMINAL_VERSION,
		true
	);

	wp_enqueue_script(
		'publictech-theme-switch',
		PUBLICTECH_TERMINAL_URL . 'assets/js/theme-switch.js',
		[],
		PUBLICTECH_TERMINAL_VERSION,
		true
	);

	if ( wp_script_is( 'publictech-charts', 'registered' ) ) {
		wp_enqueue_script(
			'publictech-chart-customizer-ui',
			PUBLICTECH_TERMINAL_URL . 'assets/js/chart-customizer-ui.js',
			[ 'publictech-charts' ],
			PUBLICTECH_TERMINAL_VERSION,
			true
		);
	}

	// Homepage dashboard: KPI counter animations + D3 horizontal bar charts.
	if ( is_front_page() && wp_script_is( 'publictech-charts', 'registered' ) ) {
		wp_enqueue_script(
			'publictech-dashboard',
			PUBLICTECH_TERMINAL_URL . 'assets/js/dashboard.js',
			[ 'publictech-charts' ],
			PUBLICTECH_TERMINAL_VERSION,
			true
		);
	}

	// Builder UI and Billboard-terminal skin: only on the chart-builder /
	// chart-gallery / single-user-chart surfaces (the core layer decided
	// whether to enqueue Billboard itself; we just bolt the UI on).
	if ( wp_script_is( 'publictech-billboard-bridge', 'enqueued' ) ) {
		wp_enqueue_style(
			'publictech-billboard-terminal',
			PUBLICTECH_TERMINAL_URL . 'assets/css/billboard-terminal.css',
			[ 'billboard', 'publictech-terminal-overrides' ],
			PUBLICTECH_TERMINAL_VERSION
		);
		wp_enqueue_script(
			'publictech-chart-builder-ui',
			PUBLICTECH_TERMINAL_URL . 'assets/js/chart-builder-ui.js',
			[ 'publictech-billboard-bridge', 'wp-i18n' ],
			PUBLICTECH_TERMINAL_VERSION,
			true
		);
	}

	wp_localize_script( 'publictech-theme-switch', 'PT_THEME_DEFAULT', [
		'mode' => get_theme_mod( 'pt_default_theme', 'dark' ),
	] );
}, 25 );

add_action( 'wp_body_open', static function () {
	require PUBLICTECH_TERMINAL_DIR . 'parts/header-rail.php';
} );

add_action( 'wp_footer', static function () {
	require PUBLICTECH_TERMINAL_DIR . 'parts/customizer-window.php';
}, 5 );

/* ----- Template registration so users can pick our layouts in the page editor. */
add_filter( 'theme_page_templates', static function ( array $tpls ): array {
	return $tpls + [
		'page-templates/template-dashboard.php'      => __( 'PT — Home Dashboard',  'publictech-terminal' ),
		'page-templates/template-profile.php'        => __( 'PT — User Profile',    'publictech-terminal' ),
		'page-templates/template-forum.php'          => __( 'PT — Forum',            'publictech-terminal' ),
		'page-templates/template-ai-console.php'     => __( 'PT — AI Console',       'publictech-terminal' ),
		'page-templates/template-stream.php'         => __( 'PT — Stream Terminal',  'publictech-terminal' ),
		'page-templates/template-chart-builder.php'  => __( 'PT — Chart Builder',    'publictech-terminal' ),
		'page-templates/template-chart-gallery.php'  => __( 'PT — Chart Gallery',    'publictech-terminal' ),
		'page-templates/template-riding-report.php'  => __( 'PT — Riding Report',    'publictech-terminal' ),
	];
} );

/* ----- Frontend opt-in to the D3 asset pipeline whenever a PT page template is used. */
add_filter( 'publictech_force_assets', static function ( bool $force ): bool {
	if ( ! is_page() ) {
		return $force;
	}
	$tpl = get_page_template_slug( get_queried_object_id() );
	return $force || str_starts_with( (string) $tpl, 'page-templates/' );
} );

/* ----- Default schema columns exposed via the stream endpoint. Sites with
 *       custom tables override these with their own filter. */
add_filter( 'publictech_columns_pt-finance-01_expenditures', static function (): array {
	return [
		'txn_id'      => '`expenditures`.`txn_id`',
		'date'        => '`expenditures`.`txn_date`',
		'vendor'      => '`expenditures`.`vendor_name`',
		'category'    => '`expenditures`.`category`',
		'region'      => '`expenditures`.`region`',
		'amount_cad'  => '`expenditures`.`amount_cad`',
		'mp_id'       => '`expenditures`.`mp_id`',
	];
} );

add_filter( 'publictech_columns_pt-grants-02_disbursements', static function (): array {
	return [
		'id'             => '`disbursements`.`id`',
		'recipient_name' => '`disbursements`.`recipient_name`',
		'category'       => '`disbursements`.`category`',
		'region'         => '`disbursements`.`region`',
		'amount_cad'     => '`disbursements`.`amount_cad`',
		'awarded_date'   => '`disbursements`.`awarded_date`',
	];
} );
