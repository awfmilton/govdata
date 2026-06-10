<?php
/**
 * WordPress admin menus and page handlers for GovData Ad Services.
 *
 * Menu structure:
 *   GovData Ads (top-level)
 *     ├ Dashboard        — impression / click / CTR overview
 *     ├ My Campaigns     — campaign list + create (advertisers + admin)
 *     ├ Submit Ad        — ad submission form (advertisers + admin)
 *     ├ Ad Stats         — per-ad stats table (advertisers + admin)
 *     ├ Zones            — zone CPT list (admin only)
 *     ├ All Ads          — pending approval queue (admin only)
 *     └ API Keys         — partner site key management (admin only)
 *
 * @package GovAds
 */

namespace GovAds;

defined( 'ABSPATH' ) || exit;

final class Admin {

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		add_action( 'admin_menu',        [ $this, 'register_menus' ] );
		add_action( 'admin_post_govads_save_campaign', [ $this, 'handle_save_campaign' ] );
		add_action( 'admin_post_govads_save_ad',       [ $this, 'handle_save_ad' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	// ── Menu registration ─────────────────────────────────────────────────────

	public function register_menus(): void {
		$can_advertise = current_user_can( 'govads_submit_ads' ) || current_user_can( 'edit_others_posts' );
		if ( ! $can_advertise ) {
			return;
		}

		$pending = $this->pending_ad_count();
		$badge   = $pending ? ' <span class="awaiting-mod">' . $pending . '</span>' : '';

		add_menu_page(
			__( 'GovData Ads', 'govdata-ad-services' ),
			__( 'GovData Ads', 'govdata-ad-services' ) . $badge,
			'read',
			'govads',
			[ $this, 'page_dashboard' ],
			'dashicons-megaphone',
			58
		);

		add_submenu_page( 'govads', __( 'Dashboard',    'govdata-ad-services' ), __( 'Dashboard',    'govdata-ad-services' ), 'read', 'govads', [ $this, 'page_dashboard' ] );
		add_submenu_page( 'govads', __( 'My Campaigns', 'govdata-ad-services' ), __( 'My Campaigns', 'govdata-ad-services' ), 'govads_manage_campaigns', 'govads-campaigns', [ $this, 'page_campaigns' ] );
		add_submenu_page( 'govads', __( 'Submit Ad',    'govdata-ad-services' ), __( 'Submit Ad',    'govdata-ad-services' ), 'govads_submit_ads', 'govads-submit', [ $this, 'page_submit_ad' ] );
		add_submenu_page( 'govads', __( 'Ad Stats',     'govdata-ad-services' ), __( 'Ad Stats',     'govdata-ad-services' ), 'govads_view_own_stats', 'govads-stats', [ $this, 'page_stats' ] );

		if ( current_user_can( 'edit_others_posts' ) ) {
			add_submenu_page( 'govads', __( 'Zones', 'govdata-ad-services' ), __( 'Zones', 'govdata-ad-services' ), 'edit_others_posts', 'edit.php?post_type=govads_zone', null );
			add_submenu_page( 'govads', __( 'All Ads', 'govdata-ad-services' ) . $badge, __( 'All Ads', 'govdata-ad-services' ) . $badge, 'edit_others_posts', 'govads-all-ads', [ $this, 'page_all_ads' ] );
		}

		if ( current_user_can( 'manage_options' ) ) {
			add_submenu_page( 'govads', __( 'API Keys', 'govdata-ad-services' ), __( 'API Keys', 'govdata-ad-services' ), 'manage_options', 'govads-keys', [ $this, 'page_api_keys' ] );
		}
	}

	// ── Page callbacks ────────────────────────────────────────────────────────

	public function page_dashboard(): void {
		require GOVADS_DIR . 'templates/admin-dashboard.php';
	}

	public function page_campaigns(): void {
		require GOVADS_DIR . 'templates/admin-campaigns.php';
	}

	public function page_submit_ad(): void {
		require GOVADS_DIR . 'templates/admin-ad-edit.php';
	}

	public function page_stats(): void {
		require GOVADS_DIR . 'templates/admin-stats.php';
	}

	public function page_all_ads(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'govdata-ad-services' ) );
		}
		require GOVADS_DIR . 'templates/admin-all-ads.php';
	}

	public function page_api_keys(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'govdata-ad-services' ) );
		}
		require GOVADS_DIR . 'templates/admin-api-keys.php';
	}

	// ── Form handlers ─────────────────────────────────────────────────────────

	public function handle_save_campaign(): void {
		check_admin_referer( 'govads_campaign_save' );
		if ( ! current_user_can( 'govads_manage_campaigns' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'govdata-ad-services' ) );
		}

		$campaign_id = (int) ( $_POST['campaign_id'] ?? 0 ); // phpcs:ignore WordPress.Security
		$title       = sanitize_text_field( (string) ( $_POST['campaign_title']      ?? '' ) ); // phpcs:ignore WordPress.Security
		$start       = sanitize_text_field( (string) ( $_POST['campaign_start_date'] ?? '' ) ); // phpcs:ignore WordPress.Security
		$end         = sanitize_text_field( (string) ( $_POST['campaign_end_date']   ?? '' ) ); // phpcs:ignore WordPress.Security

		if ( ! $title ) {
			wp_redirect( admin_url( 'admin.php?page=govads-campaigns&error=missing_title' ) );
			exit;
		}

		$data = [
			'post_title'   => $title,
			'post_type'    => 'govads_campaign',
			'post_status'  => 'publish',
		];

		if ( $campaign_id && 'govads_campaign' === get_post_type( $campaign_id ) ) {
			// Ownership check — an advertiser must not be able to overwrite
			// someone else's campaign by posting an arbitrary ID.
			$owner = (int) get_post_field( 'post_author', $campaign_id );
			if ( $owner !== get_current_user_id() && ! current_user_can( 'edit_others_posts' ) ) {
				wp_die( esc_html__( 'Not allowed.', 'govdata-ad-services' ) );
			}
			$data['ID'] = $campaign_id;
			wp_update_post( $data );
		} else {
			$data['post_author'] = get_current_user_id();
			$campaign_id         = wp_insert_post( $data );
			if ( $campaign_id && ! is_wp_error( $campaign_id ) ) {
				update_post_meta( $campaign_id, 'govads_campaign_advertiser_id', get_current_user_id() );
			}
		}

		if ( $campaign_id && ! is_wp_error( $campaign_id ) ) {
			update_post_meta( $campaign_id, 'govads_campaign_start_date', $start );
			update_post_meta( $campaign_id, 'govads_campaign_end_date', $end );
			update_post_meta( $campaign_id, 'govads_campaign_status', 'active' );
		}

		wp_redirect( admin_url( 'admin.php?page=govads-campaigns&saved=1' ) );
		exit;
	}

	public function handle_save_ad(): void {
		check_admin_referer( 'govads_ad_save' );
		if ( ! current_user_can( 'govads_submit_ads' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'govdata-ad-services' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$ad_id       = (int) ( $_POST['ad_id'] ?? 0 );
		$title       = sanitize_text_field( (string) ( $_POST['ad_title']       ?? '' ) );
		$type        = sanitize_key( (string) ( $_POST['ad_type']               ?? 'image' ) );
		$image_url   = esc_url_raw( (string) ( $_POST['ad_image_url']           ?? '' ) );
		$html        = wp_kses_post( (string) ( $_POST['ad_html']               ?? '' ) );
		$target_url  = esc_url_raw( (string) ( $_POST['ad_target_url']          ?? '' ) );
		$size        = sanitize_text_field( (string) ( $_POST['ad_size']        ?? '300x250' ) );
		$campaign_id = (int) ( $_POST['ad_campaign_id']                         ?? 0 );
		$start_date  = sanitize_text_field( (string) ( $_POST['ad_start_date']  ?? '' ) );
		$end_date    = sanitize_text_field( (string) ( $_POST['ad_end_date']    ?? '' ) );
		// phpcs:enable

		if ( ! $title || ! $target_url ) {
			wp_redirect( admin_url( 'admin.php?page=govads-submit&error=missing_fields' ) );
			exit;
		}

		$valid_sizes = array_keys( Renderer::SIZES );
		if ( ! in_array( $size, $valid_sizes, true ) ) {
			$size = '300x250';
		}

		$data = [
			'post_title'  => $title,
			'post_type'   => 'govads_ad',
			'post_status' => 'pending',
		];

		$is_edit = $ad_id && 'govads_ad' === get_post_type( $ad_id )
			&& ( (int) get_post_field( 'post_author', $ad_id ) === get_current_user_id()
				|| current_user_can( 'edit_others_posts' ) );

		if ( $is_edit ) {
			$data['ID'] = $ad_id;
			wp_update_post( $data );
		} else {
			$data['post_author'] = get_current_user_id();
			$ad_id               = wp_insert_post( $data );
			if ( $ad_id && ! is_wp_error( $ad_id ) ) {
				update_post_meta( $ad_id, 'govads_advertiser_id', get_current_user_id() );
			}
		}

		if ( $ad_id && ! is_wp_error( $ad_id ) ) {
			update_post_meta( $ad_id, 'govads_ad_type',        $type );
			update_post_meta( $ad_id, 'govads_ad_image_url',   $image_url );
			update_post_meta( $ad_id, 'govads_ad_html',        $html );
			update_post_meta( $ad_id, 'govads_ad_target_url',  $target_url );
			update_post_meta( $ad_id, 'govads_ad_size',        $size );
			update_post_meta( $ad_id, 'govads_ad_campaign_id', $campaign_id );
			update_post_meta( $ad_id, 'govads_ad_start_date',  $start_date );
			update_post_meta( $ad_id, 'govads_ad_end_date',    $end_date );
			update_post_meta( $ad_id, 'govads_ad_status',      'pending' );
			if ( ! get_post_meta( $ad_id, 'govads_ad_weight', true ) ) {
				update_post_meta( $ad_id, 'govads_ad_weight', 10 );
			}
			// An edited ad goes back to pending — make sure it stops serving now.
			if ( $is_edit ) {
				Renderer::instance()->flush_pools();
			}
		}

		wp_redirect( admin_url( 'admin.php?page=govads-submit&saved=1' ) );
		exit;
	}

	public function enqueue_admin_assets( string $hook ): void {
		if ( false === strpos( $hook, 'govads' ) ) {
			return;
		}
		wp_enqueue_style(
			'govads-admin',
			GOVADS_URL . 'assets/css/govads-admin.css',
			[],
			GOVADS_VERSION
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function pending_ad_count(): int {
		$counts = wp_count_posts( 'govads_ad' );
		return is_object( $counts ) ? (int) ( $counts->pending ?? 0 ) : 0;
	}
}
