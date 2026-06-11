<?php
/**
 * Custom WP role for self-serve advertisers + govads capabilities for staff.
 *
 * Administrators and editors receive the govads_* capabilities too, so the
 * full Ads menu (Submit Ad, My Campaigns, Ad Stats) works for staff without
 * assigning them the Advertiser role.
 *
 * @package GovAds
 */

namespace GovAds;

defined( 'ABSPATH' ) || exit;

final class Roles {

	private const CAPS = [
		'govads_submit_ads',
		'govads_view_own_stats',
		'govads_manage_campaigns',
	];

	private const STAFF_ROLES = [ 'administrator', 'editor' ];

	private const VERSION_KEY = 'govads_roles_version';
	private const VERSION     = 2;

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		add_action( 'init', [ $this, 'maybe_upgrade' ], 5 );
	}

	/** Re-run install when the role schema changes, without requiring re-activation. */
	public function maybe_upgrade(): void {
		if ( (int) get_option( self::VERSION_KEY ) < self::VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		remove_role( 'govdata_advertiser' );
		add_role(
			'govdata_advertiser',
			__( 'Advertiser', 'govdata-ad-services' ),
			array_merge( [ 'read' => true ], array_fill_keys( self::CAPS, true ) )
		);

		foreach ( self::STAFF_ROLES as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::CAPS as $cap ) {
				$role->add_cap( $cap );
			}
		}

		update_option( self::VERSION_KEY, self::VERSION );
	}

	public static function uninstall(): void {
		remove_role( 'govdata_advertiser' );

		foreach ( self::STAFF_ROLES as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::CAPS as $cap ) {
				$role->remove_cap( $cap );
			}
		}

		delete_option( self::VERSION_KEY );
	}
}
