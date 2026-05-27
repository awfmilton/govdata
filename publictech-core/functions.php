<?php
/**
 * PublicTech Core — Parent theme bootstrap.
 *
 * Wires the multi-database PDO layer, custom REST endpoints, the chart-appearance
 * customizer, asset pipeline, security helpers and plugin-compatibility shims.
 * The child theme (PublicTech Terminal) consumes everything registered here.
 *
 * @package PublicTech\Core
 */

defined( 'ABSPATH' ) || exit;

define( 'PUBLICTECH_CORE_VERSION', '1.0.0' );
define( 'PUBLICTECH_CORE_DIR', trailingslashit( get_template_directory() ) );
define( 'PUBLICTECH_CORE_URL', trailingslashit( get_template_directory_uri() ) );
define( 'PUBLICTECH_CORE_REST_NS', 'publictech/v1' );

require_once PUBLICTECH_CORE_DIR . 'inc/helpers.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-security.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-multi-db.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-access-tiers.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-throttle.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-cpt.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-rest-api.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-asset-pipeline.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-customizer.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-compat.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-alerts.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-anomalies.php';

add_action( 'after_setup_theme', static function () {
	load_theme_textdomain( 'publictech-core', PUBLICTECH_CORE_DIR . 'languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', [ 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ] );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'custom-spacing' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'woocommerce' );

	register_nav_menus( [
		'primary'   => __( 'Primary Terminal Tabs', 'publictech-core' ),
		'utility'   => __( 'Utility / Footer', 'publictech-core' ),
		'forum'     => __( 'Forum Categories', 'publictech-core' ),
	] );
} );

add_action( 'plugins_loaded', static function () {
	\PublicTech\Core\Multi_DB::instance();
	\PublicTech\Core\Access_Tiers::instance();
	\PublicTech\Core\Throttle::instance();
	\PublicTech\Core\CPT::instance();
	\PublicTech\Core\REST_API::instance();
	\PublicTech\Core\Asset_Pipeline::instance();
	\PublicTech\Core\Chart_Customizer::instance();
	\PublicTech\Core\Compat::instance();
	\PublicTech\Core\Alerts::instance();
	\PublicTech\Core\Anomalies::instance();
} );

// Clean up the cron schedule on theme switch.
add_action( 'switch_theme', [ \PublicTech\Core\Alerts::class, 'unschedule' ] );

/**
 * Template fallbacks. The child theme is free to ship its own
 * `single-member-parliament.php` / `archive-member-parliament.php`; WP picks
 * those up via the standard hierarchy and the parent's filter only kicks in
 * when no child template exists.
 */
add_filter( 'single_template', static function ( string $template ): string {
	if ( is_singular( 'member-parliament' )
		&& basename( $template ) !== 'single-member-parliament.php' ) {
		$candidate = PUBLICTECH_CORE_DIR . 'templates/single-mp.php';
		if ( file_exists( $candidate ) ) {
			return $candidate;
		}
	}
	return $template;
} );

add_filter( 'archive_template', static function ( string $template ): string {
	if ( is_post_type_archive( 'member-parliament' )
		&& basename( $template ) !== 'archive-member-parliament.php' ) {
		$candidate = PUBLICTECH_CORE_DIR . 'templates/archive-mp.php';
		if ( file_exists( $candidate ) ) {
			return $candidate;
		}
	}
	return $template;
} );

/**
 * Public helper: fetch a configured external connection by alias.
 *
 * @param string $alias Connection alias declared in wp-config.php via PUBLICTECH_DB_CONNECTIONS.
 */
function publictech_db( string $alias ): \PublicTech\Core\Connection {
	return \PublicTech\Core\Multi_DB::instance()->connection( $alias );
}
