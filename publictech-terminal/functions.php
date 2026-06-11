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

define( 'PUBLICTECH_TERMINAL_VERSION', '1.1.0' );
define( 'PUBLICTECH_TERMINAL_URL',     trailingslashit( get_stylesheet_directory_uri() ) );
define( 'PUBLICTECH_TERMINAL_DIR',     trailingslashit( get_stylesheet_directory() ) );

add_action( 'after_setup_theme', static function () {
	// The child theme has its own text domain (~400 strings); load it so they
	// can be translated. The parent theme loads 'publictech-core' separately —
	// without this, none of the terminal UI strings could ever resolve.
	load_theme_textdomain( 'publictech-terminal', PUBLICTECH_TERMINAL_DIR . 'languages' );
} );

/**
 * Raw render mode: ?pt_raw_render=1 on a single saved chart serves a
 * chrome-free 1200x630 capture surface for the snapshot service (no header
 * rail, no footer, no admin bar) so og:image screenshots contain only the
 * chart card. Detection lives in core next to the rest of the pipeline.
 */
function pt_is_raw_render(): bool {
	return class_exists( '\PublicTech\Core\Chart_Snapshots' )
		&& \PublicTech\Core\Chart_Snapshots::is_raw_render();
}

add_filter( 'template_include', static function ( string $template ): string {
	if ( pt_is_raw_render() ) {
		$raw = PUBLICTECH_TERMINAL_DIR . 'single-user-chart-raw.php';
		if ( file_exists( $raw ) ) {
			return $raw;
		}
	}
	return $template;
} );

add_filter( 'show_admin_bar', static function ( bool $show ): bool {
	return pt_is_raw_render() ? false : $show;
} );

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

	// Informative hover/focus tooltips (any [data-pt-tip] element). Tiny,
	// dependency-free, loaded site-wide so tooltips work on every template.
	wp_enqueue_script(
		'publictech-tooltips',
		PUBLICTECH_TERMINAL_URL . 'assets/js/tooltips.js',
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
	if ( ( is_front_page() || is_page_template( 'page-templates/template-dashboard.php' ) ) && wp_script_is( 'publictech-charts', 'registered' ) ) {
		wp_enqueue_script(
			'publictech-dashboard',
			PUBLICTECH_TERMINAL_URL . 'assets/js/dashboard.js',
			[ 'publictech-charts' ],
			PUBLICTECH_TERMINAL_VERSION,
			[ 'strategy' => 'defer', 'in_footer' => true ]
		);
	}

	// Social share actions (popup intents + copy-link) on the single saved
	// chart view. Skipped on the raw capture surface — no humans there.
	if ( is_singular( 'user-chart' ) && ! pt_is_raw_render() ) {
		wp_enqueue_script(
			'publictech-chart-share',
			PUBLICTECH_TERMINAL_URL . 'assets/js/chart-share.js',
			[],
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

	// Data Explorer: query builder + NL search + charts + analytics + reCAPTCHA.
	if ( is_page_template( 'page-templates/template-data-explorer.php' ) ) {
		$recaptcha_key = \PublicTech\Core\Captcha::site_key();

		// reCAPTCHA v3 (invisible) — only load when a site key is configured.
		if ( $recaptcha_key ) {
			wp_enqueue_script(
				'google-recaptcha-v3',
				esc_url_raw( 'https://www.google.com/recaptcha/api.js?render=' . urlencode( $recaptcha_key ) ),
				[],
				null,
				[ 'strategy' => 'defer', 'in_footer' => true ]
			);
		}

		// Compromise.js NLP router.
		wp_enqueue_script(
			'compromise',
			esc_url_raw( apply_filters(
				'publictech_compromise_url',
				'https://cdn.jsdelivr.net/npm/compromise@14.14.4/builds/compromise.min.js'
			) ),
			[],
			'14.14.4',
			[ 'strategy' => 'defer', 'in_footer' => true ]
		);

		// Query builder module (provides PT.QueryBuilder + PT.NLP).
		wp_enqueue_script(
			'publictech-query-builder-ui',
			PUBLICTECH_TERMINAL_URL . 'assets/js/query-builder-ui.js',
			[ 'compromise', 'wp-i18n' ],
			PUBLICTECH_TERMINAL_VERSION,
			[ 'strategy' => 'defer', 'in_footer' => true ]
		);

		// Data explorer orchestration (charts + tier notify + analytics + captcha).
		wp_enqueue_script(
			'publictech-data-explorer',
			PUBLICTECH_TERMINAL_URL . 'assets/js/data-explorer.js',
			[ 'publictech-query-builder-ui', 'publictech-billboard-bridge' ],
			PUBLICTECH_TERMINAL_VERSION,
			[ 'strategy' => 'defer', 'in_footer' => true ]
		);
	}

	// Query builder + Compromise.js NLP router: only on the query-builder template.
	if ( is_page_template( 'page-templates/template-query-builder.php' ) ) {
		wp_enqueue_script(
			'compromise',
			esc_url_raw( apply_filters(
				'publictech_compromise_url',
				'https://cdn.jsdelivr.net/npm/compromise@14.14.4/builds/compromise.min.js'
			) ),
			[],
			'14.14.4',
			[ 'strategy' => 'defer', 'in_footer' => true ]
		);
		wp_enqueue_script(
			'publictech-query-builder-ui',
			PUBLICTECH_TERMINAL_URL . 'assets/js/query-builder-ui.js',
			[ 'compromise', 'wp-i18n' ],
			PUBLICTECH_TERMINAL_VERSION,
			[ 'strategy' => 'defer', 'in_footer' => true ]
		);
	}

	wp_localize_script( 'publictech-theme-switch', 'PT_THEME_DEFAULT', [
		'mode' => get_theme_mod( 'pt_default_theme', 'dark' ),
	] );
}, 25 );

// Google Fonts is loaded on every page; add preconnect unconditionally so the
// browser can resolve the CDN before the stylesheet request fires.
add_action( 'wp_head', static function () {
	echo '<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>' . "\n";
	echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
}, 1 );

add_action( 'wp_body_open', static function () {
	if ( pt_is_raw_render() ) {
		return; // Capture surface: no skip-link, no header rail.
	}
	echo '<a class="pt-skip" href="#pt-main">' . esc_html__( 'Skip to content', 'publictech-terminal' ) . '</a>' . "\n";
	require PUBLICTECH_TERMINAL_DIR . 'parts/header-rail.php';
} );

add_action( 'wp_footer', static function () {
	if ( pt_is_raw_render() ) {
		return; // Capture surface: the customizer window would pollute screenshots.
	}
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
		'page-templates/template-entity-report.php'  => __( 'PT — Entity Funding Report', 'publictech-terminal' ),
		'page-templates/template-query-builder.php'  => __( 'PT — Query Builder',         'publictech-terminal' ),
		'page-templates/template-data-explorer.php'  => __( 'PT — Data Explorer',         'publictech-terminal' ),
		'page-templates/template-data-store.php'     => __( 'PT — Data Store',            'publictech-terminal' ),
		'page-templates/template-about.php'          => __( 'PT — About the Project',     'publictech-terminal' ),
	];
} );

/* ----- Force Billboard on the data-explorer template (chart auto-render needs it). */
add_filter( 'publictech_force_billboard', static function ( bool $force ): bool {
	if ( ! is_page() ) return $force;
	return $force || is_page_template( 'page-templates/template-data-explorer.php' );
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
