<?php
/**
 * REST routes for the front-end advertiser portal (/ad-services page).
 *
 * All routes use nonce auth (X-WP-Nonce: wp_rest) so they work for
 * logged-in WP users without bearer tokens or API keys.
 *
 * Routes added here:
 *   GET  /govads/v1/me/campaigns       list user's campaigns
 *   POST /govads/v1/me/campaigns       create campaign
 *   GET  /govads/v1/me/ads             list user's ads + meta
 *   POST /govads/v1/me/ads             create ad (returns pending)
 *   POST /govads/v1/me/ads/{id}        update ad fields or pause/resume
 *   POST /govads/v1/me/request-access  notify admin + flag user meta
 *
 * @package GovAds
 */

namespace GovAds;

defined( 'ABSPATH' ) || exit;

final class Frontend_REST {

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register' ] );
	}

	public function register(): void {
		$adv = [ $this, 'advertiser_permission' ];

		register_rest_route( GOVADS_REST_NS, '/me/campaigns', [
			[ 'methods' => \WP_REST_Server::READABLE,  'permission_callback' => $adv, 'callback' => [ $this, 'get_campaigns' ] ],
			[ 'methods' => \WP_REST_Server::CREATABLE, 'permission_callback' => $adv, 'callback' => [ $this, 'create_campaign' ],
			  'args' => [
				  'title'      => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
				  'start_date' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				  'end_date'   => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			  ] ],
		] );

		register_rest_route( GOVADS_REST_NS, '/me/ads', [
			[ 'methods' => \WP_REST_Server::READABLE,  'permission_callback' => $adv, 'callback' => [ $this, 'get_ads' ] ],
			[ 'methods' => \WP_REST_Server::CREATABLE, 'permission_callback' => $adv, 'callback' => [ $this, 'create_ad' ],
			  'args' => [
				  'title'       => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
				  'type'        => [ 'type' => 'string', 'default' => 'image', 'sanitize_callback' => 'sanitize_key' ],
				  'image_url'   => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'esc_url_raw' ],
				  'html'        => [ 'type' => 'string', 'default' => '' ],
				  'target_url'  => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw' ],
				  'size'        => [ 'type' => 'string', 'default' => '300x250', 'sanitize_callback' => 'sanitize_text_field' ],
				  'campaign_id' => [ 'type' => 'integer', 'default' => 0 ],
				  'start_date'  => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				  'end_date'    => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			  ] ],
		] );

		register_rest_route( GOVADS_REST_NS, '/me/ads/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'permission_callback' => $adv,
			'callback'            => [ $this, 'update_ad' ],
			'args'                => [ 'id' => [ 'type' => 'integer' ] ],
		] );

		register_rest_route( GOVADS_REST_NS, '/me/request-access', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'permission_callback' => 'is_user_logged_in',
			'callback'            => [ $this, 'request_access' ],
		] );

		// Public self-serve registration (nonce-gated, rate-limited inside handler).
		register_rest_route( GOVADS_REST_NS, '/register', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'register_advertiser' ],
			'args'                => [
				'email'    => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email' ],
				'name'     => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
				'company'  => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				'password' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		// ── Admin-only portal endpoints ───────────────────────────────────────
		$admin = static fn() => current_user_can( 'edit_others_posts' );

		register_rest_route( GOVADS_REST_NS, '/admin/overview', [
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => $admin,
			'callback'            => [ $this, 'admin_overview' ],
		] );

		register_rest_route( GOVADS_REST_NS, '/admin/advertisers', [
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => $admin,
			'callback'            => [ $this, 'admin_advertisers' ],
		] );

		register_rest_route( GOVADS_REST_NS, '/admin/pending', [
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => $admin,
			'callback'            => [ $this, 'admin_pending_ads' ],
		] );
	}

	// ── Permission ────────────────────────────────────────────────────────────

	public function advertiser_permission(): bool {
		return current_user_can( 'govads_submit_ads' ) || current_user_can( 'edit_others_posts' );
	}

	// ── Campaigns ─────────────────────────────────────────────────────────────

	public function get_campaigns(): \WP_REST_Response {
		$uid      = get_current_user_id();
		$is_admin = current_user_can( 'edit_others_posts' );

		$rows = get_posts( [
			'post_type'      => 'govads_campaign',
			'post_status'    => 'any',
			'author'         => $is_admin ? 0 : $uid,
			'posts_per_page' => 100,
			'no_found_rows'  => true,
		] );

		$result = [];
		foreach ( $rows as $c ) {
			$result[] = [
				'id'         => $c->ID,
				'title'      => $c->post_title,
				'start_date' => (string) get_post_meta( $c->ID, 'govads_campaign_start_date', true ),
				'end_date'   => (string) get_post_meta( $c->ID, 'govads_campaign_end_date', true ),
				'status'     => (string) ( get_post_meta( $c->ID, 'govads_campaign_status', true ) ?: 'active' ),
				'created'    => $c->post_date,
			];
		}
		return new \WP_REST_Response( $result, 200 );
	}

	public function create_campaign( \WP_REST_Request $req ): \WP_REST_Response {
		$id = wp_insert_post( [
			'post_title'  => $req->get_param( 'title' ),
			'post_type'   => 'govads_campaign',
			'post_status' => 'publish',
			'post_author' => get_current_user_id(),
		] );

		if ( is_wp_error( $id ) ) {
			return new \WP_REST_Response( [ 'error' => 'create_failed' ], 500 );
		}

		update_post_meta( $id, 'govads_campaign_start_date',    $req->get_param( 'start_date' ) );
		update_post_meta( $id, 'govads_campaign_end_date',      $req->get_param( 'end_date' ) );
		update_post_meta( $id, 'govads_campaign_status',        'active' );
		update_post_meta( $id, 'govads_campaign_advertiser_id', get_current_user_id() );

		return new \WP_REST_Response( [
			'id'     => $id,
			'title'  => $req->get_param( 'title' ),
			'status' => 'active',
		], 201 );
	}

	// ── Ads ───────────────────────────────────────────────────────────────────

	public function get_ads(): \WP_REST_Response {
		$uid      = get_current_user_id();
		$is_admin = current_user_can( 'edit_others_posts' );

		$rows = get_posts( [
			'post_type'      => 'govads_ad',
			'post_status'    => 'any',
			'author'         => $is_admin ? 0 : $uid,
			'posts_per_page' => 100,
			'no_found_rows'  => true,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		$result = [];
		foreach ( $rows as $ad ) {
			$result[] = [
				'id'          => $ad->ID,
				'title'       => $ad->post_title,
				'type'        => (string) ( get_post_meta( $ad->ID, 'govads_ad_type',       true ) ?: 'image' ),
				'size'        => (string) ( get_post_meta( $ad->ID, 'govads_ad_size',       true ) ?: '300x250' ),
				'status'      => (string) ( get_post_meta( $ad->ID, 'govads_ad_status',     true ) ?: 'pending' ),
				'image_url'   => (string) get_post_meta( $ad->ID, 'govads_ad_image_url',    true ),
				'target_url'  => (string) get_post_meta( $ad->ID, 'govads_ad_target_url',   true ),
				'campaign_id' => (int)    get_post_meta( $ad->ID, 'govads_ad_campaign_id',  true ),
				'start_date'  => (string) get_post_meta( $ad->ID, 'govads_ad_start_date',   true ),
				'end_date'    => (string) get_post_meta( $ad->ID, 'govads_ad_end_date',     true ),
				'created'     => $ad->post_date,
				'mine'        => (int) $ad->post_author === $uid,
			];
		}
		return new \WP_REST_Response( $result, 200 );
	}

	public function create_ad( \WP_REST_Request $req ): \WP_REST_Response {
		$size = $req->get_param( 'size' );
		if ( ! array_key_exists( $size, Renderer::SIZES ) ) {
			$size = '300x250';
		}

		$id = wp_insert_post( [
			'post_title'  => $req->get_param( 'title' ),
			'post_type'   => 'govads_ad',
			'post_status' => 'pending',
			'post_author' => get_current_user_id(),
		] );

		if ( is_wp_error( $id ) ) {
			return new \WP_REST_Response( [ 'error' => 'create_failed' ], 500 );
		}

		update_post_meta( $id, 'govads_ad_type',        $req->get_param( 'type' ) );
		update_post_meta( $id, 'govads_ad_image_url',   $req->get_param( 'image_url' ) );
		update_post_meta( $id, 'govads_ad_html',        wp_kses_post( (string) $req->get_param( 'html' ) ) );
		update_post_meta( $id, 'govads_ad_target_url',  $req->get_param( 'target_url' ) );
		update_post_meta( $id, 'govads_ad_size',        $size );
		update_post_meta( $id, 'govads_ad_campaign_id', (int) $req->get_param( 'campaign_id' ) );
		update_post_meta( $id, 'govads_ad_start_date',  $req->get_param( 'start_date' ) );
		update_post_meta( $id, 'govads_ad_end_date',    $req->get_param( 'end_date' ) );
		update_post_meta( $id, 'govads_ad_status',      'pending' );
		update_post_meta( $id, 'govads_advertiser_id',  get_current_user_id() );
		update_post_meta( $id, 'govads_ad_weight',      10 );

		return new \WP_REST_Response( [
			'id'     => $id,
			'title'  => $req->get_param( 'title' ),
			'size'   => $size,
			'status' => 'pending',
		], 201 );
	}

	public function update_ad( \WP_REST_Request $req ): \WP_REST_Response {
		$ad_id = (int) $req->get_param( 'id' );
		$uid   = get_current_user_id();
		$ad    = get_post( $ad_id );

		if ( ! $ad || 'govads_ad' !== $ad->post_type ) {
			return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
		}
		if ( ! current_user_can( 'edit_others_posts' ) && (int) $ad->post_author !== $uid ) {
			return new \WP_REST_Response( [ 'error' => 'forbidden' ], 403 );
		}

		$action = sanitize_key( (string) ( $req->get_param( 'action' ) ?? '' ) );

		if ( 'pause' === $action ) {
			update_post_meta( $ad_id, 'govads_ad_status', 'paused' );
			wp_update_post( [ 'ID' => $ad_id, 'post_status' => 'draft' ] );
			return new \WP_REST_Response( [ 'ok' => true, 'status' => 'paused' ], 200 );
		}

		if ( 'resume' === $action ) {
			$current = (string) get_post_meta( $ad_id, 'govads_ad_status', true );
			if ( in_array( $current, [ 'approved', 'paused' ], true ) ) {
				update_post_meta( $ad_id, 'govads_ad_status', 'approved' );
				wp_update_post( [ 'ID' => $ad_id, 'post_status' => 'publish' ] );
			}
			return new \WP_REST_Response( [ 'ok' => true, 'status' => 'approved' ], 200 );
		}

		// Field update.
		$string_map = [
			'govads_ad_image_url'  => 'image_url',
			'govads_ad_target_url' => 'target_url',
			'govads_ad_start_date' => 'start_date',
			'govads_ad_end_date'   => 'end_date',
		];
		foreach ( $string_map as $meta_key => $param_key ) {
			$val = $req->get_param( $param_key );
			if ( null !== $val ) {
				update_post_meta( $ad_id, $meta_key, sanitize_text_field( (string) $val ) );
			}
		}
		$title = $req->get_param( 'title' );
		if ( $title ) {
			wp_update_post( [ 'ID' => $ad_id, 'post_title' => sanitize_text_field( (string) $title ) ] );
		}

		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	// ── Access request ────────────────────────────────────────────────────────

	public function request_access(): \WP_REST_Response {
		$uid  = get_current_user_id();
		$user = get_user_by( 'id', $uid );
		if ( ! $user ) {
			return new \WP_REST_Response( [ 'error' => 'not_logged_in' ], 401 );
		}

		update_user_meta( $uid, 'govads_access_requested', current_time( 'mysql' ) );

		wp_mail(
			(string) get_option( 'admin_email' ),
			/* translators: %s: user display name */
			sprintf( __( 'Advertiser Access Request — %s', 'govdata-ad-services' ), $user->display_name ),
			sprintf(
				/* translators: 1: user name 2: user email 3: admin URL */
				__( "%1\$s (%2\$s) requested advertiser access on GovData Ad Services.\n\nApprove: %3\$s\n\nTo approve, edit the user and assign the 'Advertiser' role.", 'govdata-ad-services' ),
				$user->display_name,
				$user->user_email,
				admin_url( 'user-edit.php?user_id=' . $uid )
			)
		);

		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	// ── Self-serve registration ─────────────────────────────────────────────────

	public function register_advertiser( \WP_REST_Request $req ): \WP_REST_Response {
		// Rate-limit: max 5 registration attempts per IP per hour.
		$ip     = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$rl_key = 'govads_reg_' . md5( $ip );
		$hits   = (int) get_transient( $rl_key );
		if ( $hits >= 5 ) {
			return new \WP_REST_Response( [ 'error' => 'rate_limited', 'message' => __( 'Too many attempts. Please try again later.', 'govdata-ad-services' ) ], 429 );
		}
		set_transient( $rl_key, $hits + 1, HOUR_IN_SECONDS );

		if ( ! get_option( 'users_can_register' ) && ! (bool) apply_filters( 'govads_allow_self_register', true ) ) {
			return new \WP_REST_Response( [ 'error' => 'registration_closed' ], 403 );
		}

		$email    = sanitize_email( (string) $req->get_param( 'email' ) );
		$name     = sanitize_text_field( (string) $req->get_param( 'name' ) );
		$company  = sanitize_text_field( (string) $req->get_param( 'company' ) );
		$password = (string) $req->get_param( 'password' );

		if ( ! is_email( $email ) ) {
			return new \WP_REST_Response( [ 'error' => 'invalid_email', 'message' => __( 'Please enter a valid email address.', 'govdata-ad-services' ) ], 400 );
		}
		if ( strlen( $password ) < 8 ) {
			return new \WP_REST_Response( [ 'error' => 'weak_password', 'message' => __( 'Password must be at least 8 characters.', 'govdata-ad-services' ) ], 400 );
		}
		if ( email_exists( $email ) ) {
			return new \WP_REST_Response( [ 'error' => 'email_exists', 'message' => __( 'An account with this email already exists. Please sign in instead.', 'govdata-ad-services' ) ], 409 );
		}

		// Derive a unique username from the email local-part.
		$base     = sanitize_user( current( explode( '@', $email ) ), true );
		$username = $base ?: 'advertiser';
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $base . $i;
			$i++;
		}

		$uid = wp_insert_user( [
			'user_login'   => $username,
			'user_email'   => $email,
			'user_pass'    => $password,
			'display_name' => $name,
			'first_name'   => $name,
			'role'         => 'govdata_advertiser',
		] );

		if ( is_wp_error( $uid ) ) {
			return new \WP_REST_Response( [ 'error' => 'create_failed', 'message' => $uid->get_error_message() ], 500 );
		}

		if ( $company ) {
			update_user_meta( $uid, 'govads_company', $company );
		}
		update_user_meta( $uid, 'govads_registered_at', current_time( 'mysql' ) );

		// Auto-approve self-serve advertisers by default; sites that want manual
		// review can return false from this filter to downgrade to subscriber.
		$auto_approve = (bool) apply_filters( 'govads_auto_approve_advertisers', true );
		if ( ! $auto_approve ) {
			$user = new \WP_User( $uid );
			$user->set_role( 'subscriber' );
			update_user_meta( $uid, 'govads_access_requested', current_time( 'mysql' ) );
		}

		// Log the user in immediately for a smooth first-run.
		wp_set_current_user( $uid );
		wp_set_auth_cookie( $uid, true );

		// Notify admin.
		wp_mail(
			(string) get_option( 'admin_email' ),
			/* translators: %s: advertiser name */
			sprintf( __( 'New advertiser registered — %s', 'govdata-ad-services' ), $name ),
			sprintf(
				/* translators: 1: name 2: email 3: company 4: profile URL */
				__( "%1\$s (%2\$s)%3\$s registered as an advertiser.\n\nProfile: %4\$s", 'govdata-ad-services' ),
				$name,
				$email,
				$company ? ' from ' . $company : '',
				admin_url( 'user-edit.php?user_id=' . $uid )
			)
		);

		return new \WP_REST_Response( [
			'ok'           => true,
			'auto_approved'=> $auto_approve,
			'redirect'     => esc_url_raw( home_url( '/ad-services/' ) ),
		], 201 );
	}

	// ── Admin portal endpoints ──────────────────────────────────────────────────

	public function admin_overview(): \WP_REST_Response {
		global $wpdb;
		$stats_table = DB::table( 'daily_stats' );
		$date_from   = gmdate( 'Y-m-d', strtotime( '-30 days' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$agg = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(impressions) AS imp, SUM(clicks) AS clk FROM `{$stats_table}` WHERE stat_date >= %s",
				$date_from
			),
			ARRAY_A
		);
		// phpcs:enable

		$imp = (int) ( $agg['imp'] ?? 0 );
		$clk = (int) ( $agg['clk'] ?? 0 );

		$ad_counts   = wp_count_posts( 'govads_ad' );
		$pending     = is_object( $ad_counts ) ? (int) ( $ad_counts->pending ?? 0 ) : 0;
		$approved    = is_object( $ad_counts ) ? (int) ( $ad_counts->publish ?? 0 ) : 0;

		$advertisers = count( get_users( [ 'role' => 'govdata_advertiser', 'fields' => 'ID' ] ) );
		$campaigns   = (int) ( wp_count_posts( 'govads_campaign' )->publish ?? 0 );

		return new \WP_REST_Response( [
			'impressions'   => $imp,
			'clicks'        => $clk,
			'ctr'           => $imp ? round( $clk / $imp * 100, 2 ) : 0,
			'pending_ads'   => $pending,
			'active_ads'    => $approved,
			'advertisers'   => $advertisers,
			'campaigns'     => $campaigns,
		], 200 );
	}

	public function admin_advertisers(): \WP_REST_Response {
		global $wpdb;
		$users       = get_users( [ 'role' => 'govdata_advertiser', 'number' => 200 ] );
		$stats_table = DB::table( 'daily_stats' );
		$date_from   = gmdate( 'Y-m-d', strtotime( '-30 days' ) );

		$result = [];
		foreach ( $users as $u ) {
			$ad_ids = get_posts( [
				'post_type'      => 'govads_ad',
				'post_status'    => 'any',
				'author'         => $u->ID,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			] );

			$imp = 0;
			$clk = 0;
			if ( ! empty( $ad_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $ad_ids ), '%d' ) );
				// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
				$agg = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT SUM(impressions) AS imp, SUM(clicks) AS clk FROM `{$stats_table}` WHERE ad_id IN ({$placeholders}) AND stat_date >= %s",
						...array_merge( $ad_ids, [ $date_from ] )
					),
					ARRAY_A
				);
				// phpcs:enable
				$imp = (int) ( $agg['imp'] ?? 0 );
				$clk = (int) ( $agg['clk'] ?? 0 );
			}

			$result[] = [
				'id'          => $u->ID,
				'name'        => $u->display_name,
				'email'       => $u->user_email,
				'company'     => (string) get_user_meta( $u->ID, 'govads_company', true ),
				'ad_count'    => count( $ad_ids ),
				'impressions' => $imp,
				'clicks'      => $clk,
				'registered'  => (string) get_user_meta( $u->ID, 'govads_registered_at', true ),
			];
		}

		// Sort by impressions desc.
		usort( $result, static fn( $a, $b ) => $b['impressions'] <=> $a['impressions'] );

		return new \WP_REST_Response( $result, 200 );
	}

	public function admin_pending_ads(): \WP_REST_Response {
		$rows = get_posts( [
			'post_type'      => 'govads_ad',
			'post_status'    => [ 'pending', 'draft' ],
			'posts_per_page' => 100,
			'no_found_rows'  => true,
			// phpcs:disable WordPress.DB.SlowDBQuery
			'meta_query'     => [
				[ 'key' => 'govads_ad_status', 'value' => 'pending', 'compare' => '=' ],
			],
			// phpcs:enable
		] );

		$result = [];
		foreach ( $rows as $ad ) {
			$author = get_user_by( 'id', (int) $ad->post_author );
			$result[] = [
				'id'          => $ad->ID,
				'title'       => $ad->post_title,
				'size'        => (string) get_post_meta( $ad->ID, 'govads_ad_size', true ),
				'type'        => (string) get_post_meta( $ad->ID, 'govads_ad_type', true ),
				'image_url'   => (string) get_post_meta( $ad->ID, 'govads_ad_image_url', true ),
				'target_url'  => (string) get_post_meta( $ad->ID, 'govads_ad_target_url', true ),
				'advertiser'  => $author ? $author->display_name : '—',
				'created'     => $ad->post_date,
			];
		}

		return new \WP_REST_Response( $result, 200 );
	}
}
