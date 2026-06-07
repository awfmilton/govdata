<?php
/**
 * Custom post types: Ad, Ad Zone, Ad Campaign.
 *
 * All three CPTs are non-public (no front-end archive/single) — they are
 * managed exclusively through the GovData Ads admin menu and served via
 * the /govads/v1/serve REST endpoint.
 *
 * @package GovAds
 */

namespace GovAds;

defined( 'ABSPATH' ) || exit;

final class CPT {

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		add_action( 'init', [ $this, 'register' ] );
	}

	public function register(): void {
		$this->register_ad();
		$this->register_zone();
		$this->register_campaign();
		$this->register_ad_meta();
		$this->register_zone_meta();
		$this->register_campaign_meta();
	}

	private function register_ad(): void {
		register_post_type(
			'govads_ad',
			[
				'labels'             => [
					'name'          => __( 'Ads',          'govdata-ad-services' ),
					'singular_name' => __( 'Ad',           'govdata-ad-services' ),
					'add_new_item'  => __( 'Submit New Ad','govdata-ad-services' ),
					'edit_item'     => __( 'Edit Ad',      'govdata-ad-services' ),
					'all_items'     => __( 'All Ads',      'govdata-ad-services' ),
				],
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => false, // Custom admin menu via Ads\Admin.
				'show_in_rest'       => false,
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
				'supports'           => [ 'title', 'custom-fields', 'author' ],
				'menu_icon'          => 'dashicons-megaphone',
			]
		);
	}

	private function register_zone(): void {
		register_post_type(
			'govads_zone',
			[
				'labels'          => [
					'name'          => __( 'Ad Zones', 'govdata-ad-services' ),
					'singular_name' => __( 'Ad Zone',  'govdata-ad-services' ),
					'add_new_item'  => __( 'New Zone',  'govdata-ad-services' ),
					'edit_item'     => __( 'Edit Zone', 'govdata-ad-services' ),
				],
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'supports'        => [ 'title', 'custom-fields' ],
			]
		);
	}

	private function register_campaign(): void {
		register_post_type(
			'govads_campaign',
			[
				'labels'          => [
					'name'          => __( 'Campaigns',  'govdata-ad-services' ),
					'singular_name' => __( 'Campaign',   'govdata-ad-services' ),
					'add_new_item'  => __( 'New Campaign','govdata-ad-services' ),
					'edit_item'     => __( 'Edit Campaign','govdata-ad-services' ),
				],
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'supports'        => [ 'title', 'custom-fields', 'author' ],
			]
		);
	}

	private function register_ad_meta(): void {
		$string_fields = [
			'govads_ad_size',
			'govads_ad_type',
			'govads_ad_image_url',
			'govads_ad_html',
			'govads_ad_target_url',
			'govads_ad_status',
			'govads_ad_start_date',
			'govads_ad_end_date',
		];
		foreach ( $string_fields as $key ) {
			register_post_meta(
				'govads_ad',
				$key,
				[
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static fn() => current_user_can( 'edit_posts' ),
				]
			);
		}
		foreach ( [ 'govads_ad_campaign_id', 'govads_ad_weight', 'govads_advertiser_id' ] as $key ) {
			register_post_meta(
				'govads_ad',
				$key,
				[
					'type'          => 'integer',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static fn() => current_user_can( 'edit_posts' ),
				]
			);
		}
	}

	private function register_zone_meta(): void {
		foreach ( [ 'govads_zone_slug', 'govads_zone_label', 'govads_zone_placement' ] as $key ) {
			register_post_meta(
				'govads_zone',
				$key,
				[
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static fn() => current_user_can( 'manage_options' ),
				]
			);
		}
		register_post_meta(
			'govads_zone',
			'govads_zone_sizes',
			[
				'type'          => 'array',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => static fn() => current_user_can( 'manage_options' ),
			]
		);
	}

	private function register_campaign_meta(): void {
		foreach ( [ 'govads_campaign_status', 'govads_campaign_start_date', 'govads_campaign_end_date' ] as $key ) {
			register_post_meta(
				'govads_campaign',
				$key,
				[
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static fn() => current_user_can( 'edit_posts' ),
				]
			);
		}
		register_post_meta(
			'govads_campaign',
			'govads_campaign_advertiser_id',
			[
				'type'          => 'integer',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => static fn() => current_user_can( 'edit_posts' ),
			]
		);
	}
}
