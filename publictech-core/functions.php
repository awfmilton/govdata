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

define( 'PUBLICTECH_CORE_VERSION', '1.2.0' );
define( 'PUBLICTECH_CORE_DIR', trailingslashit( get_template_directory() ) );
define( 'PUBLICTECH_CORE_URL', trailingslashit( get_template_directory_uri() ) );
define( 'PUBLICTECH_CORE_REST_NS', 'publictech/v1' );

require_once PUBLICTECH_CORE_DIR . 'inc/helpers.php';
require_once PUBLICTECH_CORE_DIR . 'inc/riding-cache-lib.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-security.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-hardening.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-multi-db.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-cache-db.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-access-tiers.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-throttle.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-access-gate.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-cpt.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-rest-api.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-riding-cache-warmer.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-riding-cache.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-map-cache.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-mp-finance-warmer.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-entity-reports.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-deleted-records.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-postal-review.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-charts-rest.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-chart-snapshots.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-mp-social-cards.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-asset-pipeline.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-customizer.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-compat.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-alerts.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-anomalies.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-dashboard-stats.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-analytics.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-captcha.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-captcha-admin.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-apiflash.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-gcp-screenshot.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-external-screenshots.php';
require_once PUBLICTECH_CORE_DIR . 'inc/class-external-screenshots-admin.php';

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
	add_theme_support( 'bbpress' );

	register_nav_menus( [
		'primary'   => __( 'Primary Terminal Tabs', 'publictech-core' ),
		'utility'   => __( 'Utility / Footer', 'publictech-core' ),
		'forum'     => __( 'Forum Categories', 'publictech-core' ),
	] );
} );

// `plugins_loaded` fires before theme functions.php loads, so registering on
// that hook here would never fire. `after_setup_theme` is the first hook the
// theme can register on that runs after both plugins and themes are loaded,
// and still well before `init` / `wp_enqueue_scripts` / `rest_api_init`.
add_action( 'after_setup_theme', static function () {
	\PublicTech\Core\Hardening::instance();
	\PublicTech\Core\Multi_DB::instance();
	\PublicTech\Core\Access_Tiers::instance();
	\PublicTech\Core\Throttle::instance();
	\PublicTech\Core\Access_Gate::instance();
	\PublicTech\Core\CPT::instance();
	\PublicTech\Core\REST_API::instance();
	\PublicTech\Core\Entity_Reports::instance();
	\PublicTech\Core\Charts_REST::instance();
	\PublicTech\Core\Chart_Snapshots::instance();
	\PublicTech\Core\MP_Social_Cards::instance();
	\PublicTech\Core\Asset_Pipeline::instance();
	\PublicTech\Core\Chart_Customizer::instance();
	\PublicTech\Core\Compat::instance();
	\PublicTech\Core\Alerts::instance();
	\PublicTech\Core\Anomalies::instance();
	\PublicTech\Core\Dashboard_Stats::instance();
	\PublicTech\Core\Riding_Cache::instance();
	\PublicTech\Core\Analytics::instance();
	\PublicTech\Core\Captcha_Admin::instance();
	\PublicTech\Core\External_Screenshots_Admin::instance();
}, 5 );

// Flush rewrite rules once when the mp-social-card endpoint is first registered.
add_action( 'init', static function () {
	if ( '3' !== get_option( 'pt_rewrite_v' ) ) {
		flush_rewrite_rules( false );
		update_option( 'pt_rewrite_v', '3', true );
	}
}, 20 );

add_action( 'init', [ \PublicTech\Core\MP_Finance_Warmer::class, 'schedule' ] );
add_action( \PublicTech\Core\MP_Finance_Warmer::HOOK, [ \PublicTech\Core\MP_Finance_Warmer::class, 'run_warm' ] );

add_action( 'init', [ \PublicTech\Core\Dashboard_Stats::class, 'schedule' ] );
add_action( \PublicTech\Core\Dashboard_Stats::CRON_HOOK, [ \PublicTech\Core\Dashboard_Stats::class, 'run_warm' ] );

/**
 * Heavy cache jobs on the server's own cron instead of WP-Cron (owner decision 2026-11-20: heavy refreshes run at
 * night). WP-Cron fires inside a visitor's request at whatever hour; the riding warm once ran the remote database
 * out of memory. With PT_SYSTEM_CRON true in wp-config.php, these hooks are never scheduled in WP-Cron (the class
 * schedule() calls are refused before any database write), and the server crontab runs them at night instead.
 */
function pt_system_cron_hooks(): array {
	return [
		\PublicTech\Core\Riding_Cache::HOOK,
		\PublicTech\Core\MP_Finance_Warmer::HOOK,
		\PublicTech\Core\Map_Cache::HOOK,
		\PublicTech\Core\Entity_Reports::CRON_HOOK,
		\PublicTech\Core\Dashboard_Stats::CRON_HOOK,
	];
}
if ( defined( 'PT_SYSTEM_CRON' ) && PT_SYSTEM_CRON ) {
	add_filter( 'pre_schedule_event', static function ( $pre, $event ) {
		return in_array( $event->hook, pt_system_cron_hooks(), true ) ? false : $pre;
	}, 10, 2 );
}

// Clean up the cron schedules on theme switch.
add_action( 'switch_theme', [ \PublicTech\Core\Alerts::class, 'unschedule' ] );
add_action( 'switch_theme', [ \PublicTech\Core\Riding_Cache::class, 'unschedule' ] );
add_action( 'switch_theme', [ \PublicTech\Core\MP_Finance_Warmer::class, 'unschedule' ] );
add_action( 'switch_theme', [ \PublicTech\Core\Entity_Reports::class, 'unschedule' ] );
add_action( 'switch_theme', [ \PublicTech\Core\Dashboard_Stats::class, 'unschedule' ] );

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

/* Self-hosted MP portraits (database/sync-mp-photos.php): WebP output and the two portrait sizes. */
add_action( 'after_setup_theme', static function () {
	add_image_size( 'pt-mp-card', 96, 96, true );
	add_image_size( 'pt-mp-portrait', 400, 400, true );
} );
add_filter( 'image_editor_output_format', static function ( $formats ) {
	$formats = (array) $formats;
	$formats['image/jpeg'] = 'image/webp';
	$formats['image/png']  = 'image/webp';
	return $formats;
} );

/*
 * Re-encode generated WebP files without metadata. House of Commons portraits carry large EXIF/IPTC/XMP
 * blocks (camera, keywords, captions): a 96x96 card was ~22 KB with them and ~1.5 KB without. The credit
 * and copyright are already stored in the attachment's image_meta, which is where the site reads them.
 */
add_filter( 'wp_generate_attachment_metadata', static function ( $meta, $attachment_id ) {
	if ( ! class_exists( 'Imagick' ) || empty( $meta['file'] ) ) {
		return $meta;
	}
	$dir   = trailingslashit( dirname( get_attached_file( $attachment_id ) ) );
	$files = [ basename( (string) $meta['file'] ) ];
	foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
		$files[] = (string) ( $size['file'] ?? '' );
	}
	foreach ( array_unique( array_filter( $files ) ) as $name ) {
		$path = $dir . $name;
		if ( 'webp' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) || ! is_file( $path ) ) {
			continue;
		}
		try {
			$im = new Imagick( $path );
			$im->stripImage();
			$im->setImageCompressionQuality( 80 );
			$im->writeImage( $path );
			$im->clear();
		} catch ( \Throwable $e ) {
			// Leave the WordPress-generated file in place.
		}
	}
	return $meta;
}, 20, 2 );
