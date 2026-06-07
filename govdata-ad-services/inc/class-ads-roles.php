<?php
/**
 * Custom WP role for self-serve advertisers.
 *
 * @package GovAds
 */

namespace GovAds;

defined( 'ABSPATH' ) || exit;

final class Roles {

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {}

	public static function install(): void {
		remove_role( 'govdata_advertiser' );
		add_role(
			'govdata_advertiser',
			__( 'Advertiser', 'govdata-ad-services' ),
			[
				'read'                    => true,
				'govads_submit_ads'       => true,
				'govads_view_own_stats'   => true,
				'govads_manage_campaigns' => true,
			]
		);
	}

	public static function uninstall(): void {
		remove_role( 'govdata_advertiser' );
	}
}
