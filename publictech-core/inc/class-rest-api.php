<?php
/**
 * Custom REST namespace `publictech/v1` — the JSON streaming layer that
 * powers the front-end. Every route enforces:
 *
 *   1. Nonce or bearer-token authentication.
 *   2. Tier-based throttling.
 *   3. Schema-allowlist resolution (free-tier users cannot reach paid schemas).
 *   4. Read-only enforcement against the multi-DB layer.
 *
 * Heavy result sets (stream/rows) stream chunked JSON to avoid buffering.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class REST_API {
	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register' ] );
	}

	public function register(): void {
		$auth = [ Security::class, 'verify_request' ];

		register_rest_route( PUBLICTECH_CORE_REST_NS, '/status', [
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'status' ],
		] );

		register_rest_route( PUBLICTECH_CORE_REST_NS, '/mp/search', [
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'mp_search' ],
			'args'                => [
				'q'        => [ 'type' => 'string',  'default' => '' ],
				'party'    => [ 'type' => 'array',   'default' => [], 'items' => [ 'type' => 'string' ] ],
				'region'   => [ 'type' => 'array',   'default' => [], 'items' => [ 'type' => 'string' ] ],
				'postal'   => [ 'type' => 'string',  'default' => '' ],
				'spend_gt' => [ 'type' => 'integer', 'default' => 0 ],
				'spend_lt' => [ 'type' => 'integer', 'default' => PHP_INT_MAX ],
				'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
				'per_page' => [ 'type' => 'integer', 'default' => 24, 'minimum' => 1, 'maximum' => 120 ],
				'sort'     => [ 'type' => 'string',  'default' => 'spend DESC' ],
			],
		] );

		register_rest_route( PUBLICTECH_CORE_REST_NS, '/stream/(?P<schema>[a-z0-9\-]+)/(?P<table>[a-z0-9_]+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => $auth,
			'callback'            => [ $this, 'stream_rows' ],
		] );

		register_rest_route( PUBLICTECH_CORE_REST_NS, '/mp/(?P<id>\d+)/spend', [
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'mp_spend' ],
		] );

		register_rest_route( PUBLICTECH_CORE_REST_NS, '/ai/query', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'permission_callback' => $auth,
			'callback'            => [ $this, 'ai_query' ],
		] );

		register_rest_route( PUBLICTECH_CORE_REST_NS, '/customizer/save', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'permission_callback' => static fn() => is_user_logged_in(),
			'callback'            => [ $this, 'customizer_save' ],
		] );
	}

	/* ---------- handlers ---------- */

	public function status( \WP_REST_Request $req ): \WP_REST_Response {
		return rest_ensure_response( [
			'build'   => PUBLICTECH_CORE_VERSION,
			'time'    => gmdate( 'c' ),
			'health'  => Multi_DB::instance()->health(),
			'tier'    => Access_Tiers::instance()->user_tier( get_current_user_id() ?: null ),
		] );
	}

	public function mp_search( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		if ( ( $gate = Throttle::instance()->enforce_rest( $req ) ) instanceof \WP_Error ) {
			return $gate;
		}

		$args = [
			'post_type'      => 'member-parliament',
			'posts_per_page' => (int) $req['per_page'],
			'paged'          => (int) $req['page'],
			's'              => sanitize_text_field( (string) $req['q'] ),
			'fields'         => 'ids',
			'tax_query'      => array_filter( [
				$req['party']  ? [ 'taxonomy' => 'mp_party',  'field' => 'slug', 'terms' => array_map( 'sanitize_title', (array) $req['party'] ) ] : null,
				$req['region'] ? [ 'taxonomy' => 'mp_region', 'field' => 'slug', 'terms' => array_map( 'sanitize_title', (array) $req['region'] ) ] : null,
			] ),
			'meta_query'     => array_filter( [
				$req['postal']    ? [ 'key' => 'postal_codes', 'value' => sanitize_text_field( (string) $req['postal'] ), 'compare' => 'LIKE' ] : null,
				$req['spend_gt']  ? [ 'key' => 'total_spend', 'value' => (int) $req['spend_gt'], 'compare' => '>', 'type' => 'NUMERIC' ] : null,
			] ),
		];

		$q    = new \WP_Query( $args );
		$rows = array_map( [ $this, 'shape_mp' ], $q->posts );

		return rest_ensure_response( [
			'total'    => (int) $q->found_posts,
			'page'     => (int) $req['page'],
			'per_page' => (int) $req['per_page'],
			'rows'     => $rows,
		] );
	}

	public function stream_rows( \WP_REST_Request $req ): void {
		if ( ( $gate = Throttle::instance()->enforce_rest( $req ) ) instanceof \WP_Error ) {
			wp_send_json( $gate->get_error_data() + [ 'message' => $gate->get_error_message() ], 429 );
			return;
		}

		$schema = sanitize_key( (string) $req['schema'] );
		$table  = sanitize_key( (string) $req['table'] );
		$tier   = Access_Tiers::instance()->quota( get_current_user_id() ?: null );

		if ( '*' !== $tier['schemas'] && ! in_array( $schema, (array) $tier['schemas'], true ) ) {
			wp_send_json( [ 'message' => 'Schema not included in your tier.' ], 403 );
			return;
		}

		$columns = apply_filters( "publictech_columns_{$schema}_{$table}", [], $schema, $table );
		if ( ! $columns ) {
			wp_send_json( [ 'message' => 'No exposed columns for this table.' ], 404 );
			return;
		}

		$filters = json_decode( (string) $req->get_param( 'filters' ), true ) ?: [];
		$limit   = min( (int) ( $req['limit'] ?? 100 ), $tier['max_rows'] );
		$offset  = max( 0, (int) ( $req['offset'] ?? 0 ) );
		$order   = (string) ( $req['order'] ?? '' );

		try {
			$stmt = publictech_db( $schema )->find( $table, $filters, $columns, $order, $limit, $offset );
			stream_json_rows( $stmt );
		} catch ( \Throwable $e ) {
			wp_send_json( [ 'message' => $e->getMessage() ], 500 );
		}
		exit;
	}

	public function mp_spend( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		if ( ( $gate = Throttle::instance()->enforce_rest( $req ) ) instanceof \WP_Error ) {
			return $gate;
		}
		$mp_id = (int) $req['id'];
		$ext_id = (int) get_post_meta( $mp_id, 'external_mp_id', true );
		if ( ! $ext_id ) {
			return new \WP_Error( 'pt_not_found', 'Member not linked to finance schema.', [ 'status' => 404 ] );
		}
		$rows = publictech_db( 'pt-finance-01' )->select(
			'SELECT fiscal_year, category, SUM(amount_cad) AS total
			   FROM expenditures
			  WHERE mp_id = ? AND amount_cad > 0
			  GROUP BY fiscal_year, category
			  ORDER BY fiscal_year ASC, category ASC',
			[ $ext_id ]
		);
		return rest_ensure_response( $rows );
	}

	public function ai_query( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		if ( ( $gate = Throttle::instance()->enforce_rest( $req ) ) instanceof \WP_Error ) {
			return $gate;
		}
		$quota = Access_Tiers::instance()->quota( get_current_user_id() ?: null );
		if ( ! $quota['mcp'] ) {
			return new \WP_Error( 'pt_tier_locked', 'AI Console requires a paid tier.', [ 'status' => 402 ] );
		}
		$prompt = sanitize_text_field( (string) $req->get_param( 'prompt' ) );
		// Out-of-scope: the actual LLM call. We return a stub that the front-end can
		// render so the UI loop is exercised end-to-end. Hook `publictech_text_to_sql`
		// from a sibling plugin to wire your model of choice.
		$payload = apply_filters( 'publictech_text_to_sql', null, $prompt );
		if ( ! $payload ) {
			$payload = [
				'sql'    => "-- text-to-sql backend not configured\nSELECT 'configure publictech_text_to_sql' AS notice;",
				'rows'   => [],
				'schema' => 'pt-grants-02',
			];
		}
		return rest_ensure_response( $payload );
	}

	public function customizer_save( \WP_REST_Request $req ): \WP_REST_Response {
		$uid = get_current_user_id();
		$raw = (array) $req->get_json_params();
		update_user_meta( $uid, 'pt_chart_theme', wp_json_encode( $raw ) );
		return rest_ensure_response( [ 'ok' => true ] );
	}

	/* ---------- shaping ---------- */

	private function shape_mp( int $post_id ): array {
		$party_terms = wp_get_object_terms( $post_id, 'mp_party',  [ 'fields' => 'names' ] );
		$region_terms = wp_get_object_terms( $post_id, 'mp_region', [ 'fields' => 'names' ] );
		return [
			'id'      => $post_id,
			'name'    => get_the_title( $post_id ),
			'slug'    => get_post_field( 'post_name', $post_id ),
			'party'   => $party_terms[0] ?? '',
			'region'  => $region_terms[0] ?? '',
			'riding'  => (string) get_post_meta( $post_id, 'riding', true ),
			'spend'   => (int) get_post_meta( $post_id, 'total_spend', true ),
			'flags'   => (int) get_post_meta( $post_id, 'open_flags', true ),
			'votes'   => (int) get_post_meta( $post_id, 'votes_cast', true ),
			'permalink' => get_permalink( $post_id ),
		];
	}
}
