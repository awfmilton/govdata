<?php
/**
 * Plugin Name:       GovData Ad Services
 * Plugin URI:        https://govdata.ca
 * Description:       Centralized ad network hub for GovData. Manages ads, zones, campaigns, impression/click tracking, self-serve advertiser dashboard, and serves ads to partner sites via REST API. Network-ready: partner sites install the govdata-ads-client plugin and connect via API key.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Tested up to:      6.8
 * Requires PHP:      8.1
 * Author:            Alexander Milton
 * License:           MPL-2.0
 * License URI:       https://mozilla.org/MPL/2.0/
 * Text Domain:       govdata-ad-services
 *
 * @package GovAds
 */

defined( 'ABSPATH' ) || exit;

define( 'GOVADS_VERSION',  '1.1.0' );
define( 'GOVADS_DIR',      plugin_dir_path( __FILE__ ) );
define( 'GOVADS_URL',      plugin_dir_url( __FILE__ ) );
define( 'GOVADS_REST_NS',  'govads/v1' );
// HMAC key for impression tokens — derived from WP secret so it rotates with key rotation.
define( 'GOVADS_HMAC_KEY', defined( 'AUTH_KEY' ) ? AUTH_KEY : 'govads-insecure-fallback' );

require_once GOVADS_DIR . 'inc/class-ads-db.php';
require_once GOVADS_DIR . 'inc/class-ads-cpt.php';
require_once GOVADS_DIR . 'inc/class-ads-roles.php';
require_once GOVADS_DIR . 'inc/class-ads-auth.php';
require_once GOVADS_DIR . 'inc/class-ads-renderer.php';
require_once GOVADS_DIR . 'inc/class-ads-tracker.php';
require_once GOVADS_DIR . 'inc/class-ads-rest.php';
require_once GOVADS_DIR . 'inc/class-ads-rollup.php';
require_once GOVADS_DIR . 'inc/class-ads-zones.php';
require_once GOVADS_DIR . 'inc/class-ads-admin.php';
require_once GOVADS_DIR . 'inc/class-ads-frontend-rest.php';

GovAds\CPT::instance();
GovAds\Roles::instance();
GovAds\Rollup::instance();
GovAds\Zones::instance();
GovAds\Admin::instance();
GovAds\REST::instance();
GovAds\Frontend_REST::instance();

register_activation_hook(
	__FILE__,
	static function () {
		GovAds\DB::install();
		GovAds\Roles::install();
		GovAds\Rollup::schedule();
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		GovAds\Rollup::unschedule();
		flush_rewrite_rules();
	}
);
