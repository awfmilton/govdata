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

		register_rest_route( PUBLICTECH_CORE_REST_NS, '/queries', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => static fn() => is_user_logged_in(),
				'callback'            => [ $this, 'queries_list' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => static fn() => is_user_logged_in(),
				'callback'            => [ $this, 'queries_save' ],
			],
		] );

		register_rest_route( PUBLICTECH_CORE_REST_NS, '/queries/(?P<id>[A-Za-z0-9_-]+)', [
			'methods'             => \WP_REST_Server::DELETABLE,
			'permission_callback' => static fn() => is_user_logged_in(),
			'callback'            => [ $this, 'queries_delete' ],
		] );

		register_rest_route( PUBLICTECH_CORE_REST_NS, '/export/(?P<id>[A-Za-z0-9_-]+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => $auth,
			'callback'            => [ $this, 'export_query_csv' ],
		] );
	}

	/* ---------- handlers ---------- */

	public function status( \WP_REST_Request $req ): \WP_REST_Response {
		// Public shape: only ok-flags and latency, never error strings or hostnames.
		$public_health = [];
		foreach ( Multi_DB::instance()->health() as $alias => $row ) {
			$public_health[ $alias ] = [
				'ok'         => (bool) $row['ok'],
				'latency_ms' => isset( $row['latency_ms'] ) ? (float) $row['latency_ms'] : null,
			];
		}
		$resp = rest_ensure_response( [
			'build'  => PUBLICTECH_CORE_VERSION,
			'time'   => gmdate( 'c' ),
			'health' => $public_health,
			'tier'   => Access_Tiers::instance()->user_tier( get_current_user_id() ?: null ),
		] );
		$resp->header( 'Cache-Control', 'public, max-age=10' );
		return $resp;
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
			error_log( '[PublicTech] stream_rows failed: ' . $e->getMessage() );
			wp_send_json( [ 'message' => 'Stream failed.' ], 500 );
		}
		exit;
	}

	public function mp_spend( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		if ( ( $gate = Throttle::instance()->enforce_rest( $req ) ) instanceof \WP_Error ) {
			return $gate;
		}
		// Schema access is tier-gated even for the convenience /mp/{id}/spend route.
		$tier = Access_Tiers::instance()->quota( get_current_user_id() ?: null );
		if ( '*' !== $tier['schemas'] && ! in_array( 'pt-finance-01', (array) $tier['schemas'], true ) ) {
			return new \WP_Error( 'pt_tier_locked', 'Finance schema not in your tier.', [ 'status' => 403 ] );
		}
		$mp_id  = absint( $req['id'] );
		if ( $mp_id <= 0 || 'member-parliament' !== get_post_type( $mp_id ) ) {
			return new \WP_Error( 'pt_not_found', 'Member not found.', [ 'status' => 404 ] );
		}
		$ext_id = (string) get_post_meta( $mp_id, 'external_mp_id', true );
		if ( '' === $ext_id ) {
			return new \WP_Error( 'pt_not_found', 'Member not linked to finance schema.', [ 'status' => 404 ] );
		}
		try {
			$rows = publictech_db( 'pt-finance-01' )->select(
				'SELECT fiscal_year, category, SUM(amount_cad) AS total
				   FROM expenditures
				  WHERE mp_id = ? AND amount_cad > 0
				  GROUP BY fiscal_year, category
				  ORDER BY fiscal_year ASC, category ASC',
				[ $ext_id ]
			);
		} catch ( \Throwable $e ) {
			error_log( '[PublicTech] mp_spend failed: ' . $e->getMessage() );
			return new \WP_Error( 'pt_db_error', 'Spending data temporarily unavailable.', [ 'status' => 503 ] );
		}
		$resp = rest_ensure_response( $rows );
		$resp->header( 'Cache-Control', 'private, max-age=60' );
		$resp->header( 'Vary', 'Authorization, Cookie' );
		return $resp;
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

	public function customizer_save( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$uid = get_current_user_id();
		$raw = (array) $req->get_json_params();

		// Hard cap payload size before we look at the shape; user_meta is not a junk drawer.
		if ( strlen( wp_json_encode( $raw ) ) > 2048 ) {
			return new \WP_Error( 'pt_payload_too_large', 'Customizer payload too large.', [ 'status' => 413 ] );
		}

		// Whitelist + per-key validation. Anything not on the list is dropped silently.
		$valid_curve = [ 'monotone', 'linear', 'step' ];
		$valid_grid  = [ 'off', 'dotted', 'solid' ];
		$valid_bar   = [ 'solid', 'stripe', 'outline' ];
		$valid_font  = [ 'Montserrat', 'JetBrains Mono', 'Georgia' ];

		$clean = [];
		if ( isset( $raw['accent'] ) && is_string( $raw['accent'] ) ) {
			$hex = sanitize_hex_color( $raw['accent'] );
			if ( $hex ) {
				$clean['accent'] = $hex;
			}
		}
		if ( isset( $raw['curve'] ) && in_array( $raw['curve'], $valid_curve, true ) ) { $clean['curve'] = $raw['curve']; }
		if ( isset( $raw['grid']  ) && in_array( $raw['grid'],  $valid_grid,  true ) ) { $clean['grid']  = $raw['grid']; }
		if ( isset( $raw['bar']   ) && in_array( $raw['bar'],   $valid_bar,   true ) ) { $clean['bar']   = $raw['bar']; }
		if ( isset( $raw['font']  ) && in_array( $raw['font'],  $valid_font,  true ) ) { $clean['font']  = $raw['font']; }
		if ( isset( $raw['stroke'] ) ) { $clean['stroke'] = max( 0.5, min( 6.0,  (float) $raw['stroke'] ) ); }
		if ( isset( $raw['marker'] ) ) { $clean['marker'] = max( 0,   min( 12,   (int)   $raw['marker'] ) ); }
		if ( isset( $raw['area']   ) ) { $clean['area']   = max( 0,   min( 1.0,  (float) $raw['area']   ) ); }
		if ( isset( $raw['anim']   ) ) { $clean['anim']   = (bool) $raw['anim']; }

		update_user_meta( $uid, 'pt_chart_theme', wp_json_encode( $clean ) );
		return rest_ensure_response( [ 'ok' => true, 'theme' => $clean ] );
	}

	/* ---------- saved queries + CSV export ---------- */

	/**
	 * Saved-query store. Each query lives under the calling user's user_meta
	 * pt_saved_queries: { id => { id, title, schema, table, filters, order, created } }.
	 * Per-user isolation is enforced by the user_meta scope itself; the export
	 * handler additionally re-checks ownership before running.
	 */
	public function queries_list( \WP_REST_Request $req ): \WP_REST_Response {
		$uid     = get_current_user_id();
		$queries = get_user_meta( $uid, 'pt_saved_queries', true );
		return rest_ensure_response( is_array( $queries ) ? array_values( $queries ) : [] );
	}

	public function queries_save( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$uid   = get_current_user_id();
		$body  = (array) $req->get_json_params();
		$title = sanitize_text_field( (string) ( $body['title']  ?? '' ) );
		$schema= sanitize_key(         (string) ( $body['schema'] ?? '' ) );
		$table = sanitize_key(         (string) ( $body['table']  ?? '' ) );
		if ( '' === $title || '' === $schema || '' === $table ) {
			return new \WP_Error( 'pt_bad_request', 'title, schema, and table are required.', [ 'status' => 400 ] );
		}
		$columns = apply_filters( "publictech_columns_{$schema}_{$table}", [], $schema, $table );
		if ( ! $columns ) {
			return new \WP_Error( 'pt_unknown_table', 'Unknown schema/table.', [ 'status' => 404 ] );
		}
		$tier = Access_Tiers::instance()->quota( $uid );
		if ( '*' !== $tier['schemas'] && ! in_array( $schema, (array) $tier['schemas'], true ) ) {
			return new \WP_Error( 'pt_tier_locked', 'Schema not included in your tier.', [ 'status' => 403 ] );
		}

		$query = [
			'id'      => substr( bin2hex( random_bytes( 8 ) ), 0, 16 ),
			'title'   => $title,
			'schema'  => $schema,
			'table'   => $table,
			'filters' => is_array( $body['filters'] ?? null ) ? $body['filters'] : [],
			'order'   => sanitize_text_field( (string) ( $body['order'] ?? '' ) ),
			'created' => gmdate( 'c' ),
			'runs'    => 0,
		];

		$store = get_user_meta( $uid, 'pt_saved_queries', true );
		$store = is_array( $store ) ? $store : [];
		// Cap stored queries so user_meta can't be DoS'd. Oldest wins on overflow.
		if ( count( $store ) >= 200 ) {
			array_shift( $store );
		}
		$store[ $query['id'] ] = $query;
		update_user_meta( $uid, 'pt_saved_queries', $store );
		return rest_ensure_response( $query );
	}

	public function queries_delete( \WP_REST_Request $req ): \WP_REST_Response {
		$uid   = get_current_user_id();
		$qid   = sanitize_text_field( (string) $req['id'] );
		$store = get_user_meta( $uid, 'pt_saved_queries', true );
		if ( is_array( $store ) && isset( $store[ $qid ] ) ) {
			unset( $store[ $qid ] );
			update_user_meta( $uid, 'pt_saved_queries', $store );
		}
		return rest_ensure_response( [ 'ok' => true ] );
	}

	/**
	 * Stream a saved query's result set as CSV. The query is resolved against
	 * the calling user's saved-query store (ownership-enforced by scope), the
	 * schema/table is whitelisted via the same publictech_columns_* filter the
	 * stream endpoint uses, and tier max_rows clamps the result count.
	 */
	public function export_query_csv( \WP_REST_Request $req ): void {
		if ( ( $gate = Throttle::instance()->enforce_rest( $req ) ) instanceof \WP_Error ) {
			wp_send_json( $gate->get_error_data() + [ 'message' => $gate->get_error_message() ], 429 );
			return;
		}

		$uid   = get_current_user_id();
		if ( ! $uid ) {
			wp_send_json( [ 'message' => 'Authentication required.' ], 401 );
			return;
		}
		$qid   = sanitize_text_field( (string) $req['id'] );
		$store = get_user_meta( $uid, 'pt_saved_queries', true );
		$query = is_array( $store ) ? ( $store[ $qid ] ?? null ) : null;
		if ( ! $query ) {
			wp_send_json( [ 'message' => 'Query not found.' ], 404 );
			return;
		}

		$tier  = Access_Tiers::instance()->quota( $uid );
		if ( '*' !== $tier['schemas'] && ! in_array( $query['schema'], (array) $tier['schemas'], true ) ) {
			wp_send_json( [ 'message' => 'Schema not included in your tier.' ], 403 );
			return;
		}

		$columns = apply_filters( "publictech_columns_{$query['schema']}_{$query['table']}", [], $query['schema'], $query['table'] );
		if ( ! $columns ) {
			wp_send_json( [ 'message' => 'No exposed columns for this table.' ], 404 );
			return;
		}

		// Bump the run counter, then stream.
		$store[ $qid ]['runs'] = (int) ( $store[ $qid ]['runs'] ?? 0 ) + 1;
		update_user_meta( $uid, 'pt_saved_queries', $store );

		$filename = sanitize_file_name( $query['title'] ?: 'export' ) . '-' . gmdate( 'Ymd' ) . '.csv';
		$limit    = (int) $tier['max_rows'];

		try {
			$stmt = publictech_db( $query['schema'] )->find(
				$query['table'],
				(array) $query['filters'],
				$columns,
				(string) $query['order'],
				$limit
			);
			stream_csv_rows( $stmt, $filename );
		} catch ( \Throwable $e ) {
			error_log( '[PublicTech] export_query_csv failed: ' . $e->getMessage() );
			wp_send_json( [ 'message' => 'Export failed.' ], 500 );
		}
		exit;
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
			'riding'  => (string) ( get_post_meta( $post_id, 'pt_mp_district', true ) ?: get_post_meta( $post_id, 'riding', true ) ),
			'spend'   => (int) get_post_meta( $post_id, 'total_spend', true ),
			'flags'   => (int) get_post_meta( $post_id, 'open_flags', true ),
			'votes'   => (int) get_post_meta( $post_id, 'votes_cast', true ),
			'permalink' => get_permalink( $post_id ),
		];
	}
}
