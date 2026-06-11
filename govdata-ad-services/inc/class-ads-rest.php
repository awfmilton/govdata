<?php
/**
 * REST API routes under govads/v1.
 *
 * Public endpoints (/serve, /track) are rate-limited but require no auth.
 * /serve returns ad payload + HMAC token. /track verifies that token to
 * prevent replay attacks. External partner sites authenticate via
 * X-GovAds-Site-Key header (raw key verified by hash_equals against stored hash).
 *
 * @package GovAds
 */

namespace GovAds;

defined( 'ABSPATH' ) || exit;

final class REST {

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register' ] );
	}

	public function register(): void {
		register_rest_route(
			GOVADS_REST_NS,
			'/serve',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'serve' ],
				'args'                => [
					'zone' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);

		register_rest_route(
			GOVADS_REST_NS,
			'/track',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'track' ],
			]
		);

		register_rest_route(
			GOVADS_REST_NS,
			'/stats',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => [ $this, 'stats_permission' ],
				'callback'            => [ $this, 'stats' ],
				'args'                => [
					'days'           => [ 'type' => 'integer', 'default' => 30, 'minimum' => 7,  'maximum' => 90 ],
					'advertiser_id'  => [ 'type' => 'integer', 'default' => 0 ],
				],
			]
		);

		register_rest_route(
			GOVADS_REST_NS,
			'/admin/approve',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => static fn() => current_user_can( 'edit_others_posts' ),
				'callback'            => [ $this, 'approve_ad' ],
				'args'                => [ 'ad_id' => [ 'type' => 'integer', 'required' => true ] ],
			]
		);

		register_rest_route(
			GOVADS_REST_NS,
			'/admin/reject',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => static fn() => current_user_can( 'edit_others_posts' ),
				'callback'            => [ $this, 'reject_ad' ],
				'args'                => [
					'ad_id'  => [ 'type' => 'integer', 'required' => true ],
					'reason' => [ 'type' => 'string', 'default' => '' ],
				],
			]
		);

		register_rest_route(
			GOVADS_REST_NS,
			'/admin/keys',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'callback'            => [ $this, 'generate_key' ],
				'args'                => [
					'label'    => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
					'site_url' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'esc_url_raw' ],
				],
			]
		);

		register_rest_route(
			GOVADS_REST_NS,
			'/admin/keys/revoke',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'callback'            => [ $this, 'revoke_key' ],
				'args'                => [ 'hash' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ] ],
			]
		);
	}

	// ── Route handlers ────────────────────────────────────────────────────────

	public function serve( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! Tracker::instance()->rate_limit_ok( $this->client_ip() ) ) {
			return new \WP_REST_Response( [ 'error' => 'rate_limited' ], 429 );
		}

		$zone_slug = (string) $req->get_param( 'zone' );
		$site_key  = $this->extract_site_key( $req );
		$ad        = Renderer::instance()->select_for_zone( $zone_slug, $site_key );

		if ( ! $ad ) {
			return new \WP_REST_Response( null, 204 );
		}

		return new \WP_REST_Response( $ad, 200 );
	}

	public function track( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! Tracker::instance()->rate_limit_ok( $this->client_ip() ) ) {
			return new \WP_REST_Response( [ 'error' => 'rate_limited' ], 429 );
		}

		// get_param() reads the JSON body (same-origin loader) and the query
		// string (partner-site sendBeacon, which cannot set headers or a JSON
		// content type cross-origin without tripping CORS preflight).
		$type    = sanitize_key( (string) $req->get_param( 'type' ) );
		$ad_id   = (int) $req->get_param( 'ad_id' );
		$zone_id = (int) $req->get_param( 'zone_id' );
		$token   = (string) $req->get_param( 'token' );

		if ( ! in_array( $type, [ 'impression', 'click' ], true ) || $ad_id <= 0 ) {
			return new \WP_REST_Response( [ 'error' => 'invalid_params' ], 400 );
		}

		// The HMAC token is mandatory: it proves /serve issued this ad+zone
		// pair recently and carries the partner-site attribution. Without it
		// anyone could fabricate tracking events.
		$site_key = Renderer::instance()->verify_token( $token, $ad_id, $zone_id );
		if ( false === $site_key ) {
			return new \WP_REST_Response( [ 'error' => 'invalid_token' ], 403 );
		}

		$ctx = [
			'site_key'   => $site_key,
			'session_id' => sanitize_text_field( (string) $req->get_param( 'session_id' ) ),
			'page_url'   => esc_url_raw( (string) $req->get_param( 'page_url' ) ),
			'referrer'   => esc_url_raw( (string) $req->get_param( 'referrer' ) ),
			'target_url' => esc_url_raw( (string) $req->get_param( 'target_url' ) ),
		];

		$tracker = Tracker::instance();
		if ( 'impression' === $type ) {
			$tracker->record_impression( $ad_id, $zone_id, $ctx );
		} else {
			$tracker->record_click( $ad_id, $zone_id, $ctx );
		}

		return new \WP_REST_Response( [ 'ok' => true ], 201 );
	}

	public function stats( \WP_REST_Request $req ): \WP_REST_Response {
		global $wpdb;

		$uid        = get_current_user_id();
		$is_admin   = current_user_can( 'edit_others_posts' );
		$author_id  = $is_admin && $req->get_param( 'advertiser_id' )
			? (int) $req->get_param( 'advertiser_id' )
			: $uid;
		$days       = (int) $req->get_param( 'days' );
		$date_from  = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$ad_ids = get_posts(
			[
				'post_type'      => 'govads_ad',
				'post_status'    => 'any',
				'author'         => $author_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		if ( empty( $ad_ids ) ) {
			return new \WP_REST_Response( [], 200 );
		}

		$table        = DB::table( 'daily_stats' );
		$placeholders = implode( ',', array_fill( 0, count( $ad_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stat_date, ad_id, zone_id, site_key, impressions, clicks, ctr
				 FROM `{$table}`
				 WHERE ad_id IN ({$placeholders}) AND stat_date >= %s
				 ORDER BY stat_date DESC
				 LIMIT 500",
				...array_merge( $ad_ids, [ $date_from ] )
			),
			ARRAY_A
		);
		// phpcs:enable

		return new \WP_REST_Response( $rows ?: [], 200 );
	}

	public function approve_ad( \WP_REST_Request $req ): \WP_REST_Response {
		$ad_id = (int) $req->get_param( 'ad_id' );
		if ( ! $ad_id || 'govads_ad' !== get_post_type( $ad_id ) ) {
			return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
		}
		update_post_meta( $ad_id, 'govads_ad_status', 'approved' );
		wp_update_post( [ 'ID' => $ad_id, 'post_status' => 'publish' ] );
		$this->notify_advertiser( $ad_id, 'approved' );
		Renderer::instance()->flush_pools();
		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public function reject_ad( \WP_REST_Request $req ): \WP_REST_Response {
		$ad_id  = (int) $req->get_param( 'ad_id' );
		$reason = sanitize_text_field( (string) $req->get_param( 'reason' ) );
		if ( ! $ad_id || 'govads_ad' !== get_post_type( $ad_id ) ) {
			return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
		}
		update_post_meta( $ad_id, 'govads_ad_status', 'rejected' );
		wp_update_post( [ 'ID' => $ad_id, 'post_status' => 'draft' ] );
		$this->notify_advertiser( $ad_id, 'rejected', $reason );
		Renderer::instance()->flush_pools();
		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public function generate_key( \WP_REST_Request $req ): \WP_REST_Response {
		$result = Auth::instance()->generate_key(
			(string) $req->get_param( 'label' ),
			(string) $req->get_param( 'site_url' )
		);
		return new \WP_REST_Response(
			[
				'raw'  => $result['raw'],
				'hash' => $result['hash'],
				'note' => __( 'Copy the key now — it will not be shown again.', 'govdata-ad-services' ),
			],
			201
		);
	}

	public function revoke_key( \WP_REST_Request $req ): \WP_REST_Response {
		Auth::instance()->revoke_key( (string) $req->get_param( 'hash' ) );
		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public function stats_permission(): bool {
		return current_user_can( 'govads_view_own_stats' ) || current_user_can( 'edit_others_posts' );
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/** Read and verify X-GovAds-Site-Key header. Returns hash or empty string. */
	private function extract_site_key( \WP_REST_Request $req ): string {
		$raw  = (string) $req->get_header( 'X-GovAds-Site-Key' );
		$hash = $raw ? Auth::instance()->verify_site_key( $raw ) : false;
		return $hash ?: '';
	}

	private function client_ip(): string {
		return (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	private function notify_advertiser( int $ad_id, string $status, string $reason = '' ): void {
		$ad      = get_post( $ad_id );
		$owner   = $ad ? get_user_by( 'id', (int) $ad->post_author ) : null;
		if ( ! $owner ) {
			return;
		}
		$title   = get_the_title( $ad_id );
		if ( 'approved' === $status ) {
			$subject = __( 'Your ad has been approved — GovData Ad Services', 'govdata-ad-services' );
			/* translators: %s: ad title */
			$message = sprintf( __( 'Your ad "%s" has been approved and is now live.', 'govdata-ad-services' ), $title );
		} else {
			$subject = __( 'Your ad requires changes — GovData Ad Services', 'govdata-ad-services' );
			/* translators: 1: ad title 2: rejection reason */
			$message = sprintf( __( 'Your ad "%1$s" was not approved. Reason: %2$s', 'govdata-ad-services' ), $title, $reason ?: __( 'No reason given.', 'govdata-ad-services' ) );
		}
		wp_mail( $owner->user_email, $subject, $message );
	}
}
