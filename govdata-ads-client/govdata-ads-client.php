<?php
/**
 * Plugin Name:       GovData Ads Client
 * Plugin URI:        https://govdata.ca
 * Description:       Connects any WordPress site to the GovData Ad Services network. Fetches ads from the govdata.ca hub via REST API and renders them with lazy loading and impression/click tracking. Use [govads zone="zone_slug"] to place ads anywhere.
 * Version:           1.1.0
 * Requires at least: 6.2
 * Tested up to:      6.8
 * Requires PHP:      8.1
 * Author:            Alexander Milton
 * License:           MPL-2.0
 * License URI:       https://mozilla.org/MPL/2.0/
 * Text Domain:       govdata-ads-client
 *
 * Configuration: Settings → GovData Ads Client
 *   Hub URL  — e.g. https://govdata.ca
 *   API Key  — generated in GovData Ads → API Keys on the hub site
 *
 * @package GovAdsClient
 */

defined( 'ABSPATH' ) || exit;

define( 'GAC_VERSION', '1.1.0' );
define( 'GAC_DIR',     plugin_dir_path( __FILE__ ) );
define( 'GAC_URL',     plugin_dir_url( __FILE__ ) );

require_once GAC_DIR . 'inc/class-gac-fetcher.php';
require_once GAC_DIR . 'inc/class-gac-renderer.php';
require_once GAC_DIR . 'inc/class-gac-shortcode.php';
require_once GAC_DIR . 'inc/class-gac-settings.php';

GovAdsClient\Shortcode::instance();
GovAdsClient\Settings::instance();
