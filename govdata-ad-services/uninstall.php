<?php
/**
 * Fires when the plugin is deleted from the WordPress admin.
 * Drops custom tables and cleans up options and roles.
 *
 * @package GovAds
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'inc/class-ads-db.php';
require_once plugin_dir_path( __FILE__ ) . 'inc/class-ads-roles.php';

GovAds\DB::uninstall();
GovAds\Roles::uninstall();

delete_option( 'govads_api_keys' );
delete_option( 'govads_db_version' );
