<?php
/**
 * REST endpoints + spec validator for user-authored Billboard.js charts.
 *
 * The chart spec is a strictly whitelisted JSON document. Every accepted key
 * is validated against an enum or a numeric range, and unknown keys are
 * dropped silently — same defensive shape as REST_API::customizer_save().
 * Because Billboard.js is driven entirely by static option-literals, this is
 * the only validation surface we need: the front-end cannot inject executable
 * content through the spec, and the data payload travels separately under
 * pt_source.
 *
 *  GET    /charts                 public gallery feed
 *  GET    /charts/{id}            single chart resolve
 *  POST   /charts                 create (tier-gated)
 *  PUT    /charts/{id}            update (owner)
 *  DELETE /charts/{id}            delete (owner)
 *  POST   /charts/{id}/fork       clone (tier-gated)
 *  POST   /charts/preview         render-test (tier-gated, no persistence)
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Pure-function spec validator. Lives at the top of the file so it can be
 * unit-tested without booting WP.
 */
final class Chart_Spec {

	public const VERSION = 1;

	/** Billboard chart `data.type` values we recognise. */
	public const CHART_TYPES = [
		'bar', 'line', 'area', 'area-line-range', 'area-spline',
		'spline', 'step', 'area-step', 'scatter',
		'pie', 'donut', 'gauge',
	];

	/** Allowed `axis.<axis>.type` values. */
	public const AXIS_TYPES = [ 'category', 'indexed', 'timeseries', 'log' ];

	/** Data-source kinds. */
	public const SOURCE_TYPES = [ 'static', 'rest', 'ai' ];

	/** Hard cap on `pt_source.rows` count to keep user_meta sane. */
	public const STATIC_ROWS_MAX = 1000;

	/** Hard cap on label/title string length. */
	public const LABEL_MAX = 64;

	/**
	 * Validate + normalise a posted spec. Returns the sanitised array on
	 * success or a WP_Error on the first hard failure. Soft failures
	 * (unknown enum, oversize label) are dropped/clamped silently.
	 *
	 * @param mixed $raw The decoded JSON body.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function validate( $raw ) {
		if ( ! is_array( $raw ) ) {
			return new \WP_Error( 'pt_bad_spec', 'Spec must be an object.', [ 'status' => 400 ] );
		}

		$version = (int) ( $raw['version'] ?? 0 );
		if ( self::VERSION !== $version ) {
			return new \WP_Error( 'pt_bad_spec_version', 'Unsupported spec version.', [ 'status' => 400 ] );
		}

		$data_type = isset( $raw['data']['type'] ) ? (string) $raw['data']['type'] : '';
		if ( ! in_array( $data_type, self::CHART_TYPES, true ) ) {
			return new \WP_Error( 'pt_bad_spec_type', 'Unknown chart type.', [ 'status' => 400 ] );
		}

		$clean = [
			'version' => self::VERSION,
			'data'    => [
				'type'   => $data_type,
				'groups' => self::clean_groups( $raw['data']['groups'] ?? [] ),
			],
			'axis'    => [
				'x' => self::clean_axis( $raw['axis']['x'] ?? [], 'category' ),
				'y' => self::clean_axis( $raw['axis']['y'] ?? [], 'indexed' ),
			],
			'grid'    => [
				'x' => [ 'show' => ! empty( $raw['grid']['x']['show'] ) ],
				'y' => [ 'show' => ! empty( $raw['grid']['y']['show'] ) ],
			],
			'theme'   => [
				'useGlobalCustomizer' => ! isset( $raw['theme']['useGlobalCustomizer'] )
					? true
					: (bool) $raw['theme']['useGlobalCustomizer'],
			],
		];

		// Optional but bounded: legend.show, point.show, transition.duration.
		if ( isset( $raw['legend']['show'] ) ) {
			$clean['legend'] = [ 'show' => (bool) $raw['legend']['show'] ];
		}
		if ( isset( $raw['point']['show'] ) ) {
			$clean['point'] = [ 'show' => (bool) $raw['point']['show'] ];
		}
		if ( isset( $raw['transition']['duration'] ) ) {
			$d = (int) $raw['transition']['duration'];
			$clean['transition'] = [ 'duration' => max( 0, min( 2000, $d ) ) ];
		}

		// Hard cap the encoded size — a malformed but valid-shape spec
		// shouldn't be allowed to balloon post_meta.
		if ( strlen( (string) wp_json_encode( $clean ) ) > 8192 ) {
			return new \WP_Error( 'pt_spec_too_large', 'Spec exceeds 8KB.', [ 'status' => 413 ] );
		}
		return $clean;
	}

	/**
	 * Validate + normalise a posted data source.
	 *
	 * @param mixed $raw
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function validate_source( $raw ) {
		if ( ! is_array( $raw ) ) {
			return new \WP_Error( 'pt_bad_source', 'Source must be an object.', [ 'status' => 400 ] );
		}
		$type = (string) ( $raw['type'] ?? '' );
		if ( ! in_array( $type, self::SOURCE_TYPES, true ) ) {
			return new \WP_Error( 'pt_bad_source_type', 'Unknown source type.', [ 'status' => 400 ] );
		}
		$clean = [ 'type' => $type ];

		if ( 'static' === $type ) {
			$rows = $raw['rows'] ?? [];
			if ( ! is_array( $rows ) ) {
				return new \WP_Error( 'pt_bad_source_rows', 'rows must be an array.', [ 'status' => 400 ] );
			}
			if ( count( $rows ) > self::STATIC_ROWS_MAX ) {
				return new \WP_Error( 'pt_source_too_large', 'Static row count exceeds limit.', [ 'status' => 413 ] );
			}
			$clean['rows'] = array_values( array_map( [ self::class, 'flatten_row' ], array_slice( $rows, 0, self::STATIC_ROWS_MAX ) ) );
		} elseif ( 'rest' === $type ) {
			$endpoint = isset( $raw['endpoint'] ) ? (string) $raw['endpoint'] : '';
			if ( ! self::is_safe_relative_endpoint( $endpoint ) ) {
				return new \WP_Error( 'pt_bad_endpoint', 'Endpoint must be a /publictech/v1/* path.', [ 'status' => 400 ] );
			}
			$clean['endpoint'] = $endpoint;
		} elseif ( 'ai' === $type ) {
			$qid = isset( $raw['query_id'] ) ? sanitize_text_field( (string) $raw['query_id'] ) : '';
			if ( ! preg_match( '/^[A-Fa-f0-9]{8,32}$/', $qid ) ) {
				return new \WP_Error( 'pt_bad_query_id', 'query_id must be a hex hash.', [ 'status' => 400 ] );
			}
			$clean['query_id'] = $qid;
		}

		return $clean;
	}

	/**
	 * Tier gate: does this quota array permit chart creation?
	 *
	 * Exposed as a static so unit tests can exercise it without a WP boot.
	 */
	public static function user_can_create( array $quota ): bool {
		return ! empty( $quota['charts.create'] );
	}

	/* ---------- private ---------- */

	private static function clean_axis( $raw, string $default_type ): array {
		$raw   = is_array( $raw ) ? $raw : [];
		$label = isset( $raw['label'] ) && is_string( $raw['label'] ) ? sanitize_text_field( $raw['label'] ) : '';
		if ( strlen( $label ) > self::LABEL_MAX ) {
			$label = substr( $label, 0, self::LABEL_MAX );
		}
		$type = isset( $raw['type'] ) && in_array( $raw['type'], self::AXIS_TYPES, true ) ? $raw['type'] : $default_type;
		return [ 'label' => $label, 'type' => $type ];
	}

	private static function clean_groups( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $g ) {
			if ( ! is_array( $g ) ) {
				continue;
			}
			$row = [];
			foreach ( $g as $name ) {
				if ( is_string( $name ) && '' !== $name ) {
					$row[] = sanitize_text_field( $name );
				}
				if ( count( $row ) >= 32 ) {
					break;
				}
			}
			if ( $row ) {
				$out[] = $row;
			}
			if ( count( $out ) >= 8 ) {
				break;
			}
		}
		return $out;
	}

	private static function flatten_row( $row ): array {
		if ( ! is_array( $row ) ) {
			return [];
		}
		$out = [];
		foreach ( $row as $k => $v ) {
			if ( ! is_string( $k ) ) {
				continue;
			}
			$key = sanitize_key( $k );
			if ( '' === $key ) {
				continue;
			}
			if ( is_scalar( $v ) || null === $v ) {
				$out[ $key ] = is_string( $v ) ? sanitize_text_field( $v ) : $v;
			}
			if ( count( $out ) >= 32 ) {
				break;
			}
		}
		return $out;
	}

	private static function is_safe_relative_endpoint( string $endpoint ): bool {
		if ( '' === $endpoint || $endpoint[0] !== '/' ) {
			return false;
		}
		// Disallow scheme, host, or directory traversal sneaking in.
		if ( false !== strpos( $endpoint, '://' ) || false !== strpos( $endpoint, '..' ) ) {
			return false;
		}
		return 1 === preg_match( '#^/' . preg_quote( PUBLICTECH_CORE_REST_NS, '#' ) . '/[A-Za-z0-9_/\-]+$#', $endpoint );
	}
}

/**
 * REST routes around the user-chart CPT. Reuses the throttle + tier gating
 * pattern from REST_API::ai_query() (class-rest-api.php:229) so we don't
 * stack a second validation layer on top of the engine.
 */
final class Charts_REST {
	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register' ] );
	}

	public function register(): void {
		$ns = PUBLICTECH_CORE_REST_NS;

		register_rest_route( $ns, '/charts', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'list' ],
				'args'                => [
					'kind'     => [ 'type' => 'string',  'default' => '' ],
					'author'   => [ 'type' => 'integer', 'default' => 0 ],
					'sort'     => [ 'type' => 'string',  'default' => 'recent' ],
					'page'     => [ 'type' => 'integer', 'default' => 1,  'minimum' => 1 ],
					'per_page' => [ 'type' => 'integer', 'default' => 24, 'minimum' => 1, 'maximum' => 100 ],
				],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => [ $this, 'require_tier' ],
				'callback'            => [ $this, 'create' ],
			],
		] );

		register_rest_route( $ns, '/charts/preview', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'permission_callback' => [ $this, 'require_tier' ],
			'callback'            => [ $this, 'preview' ],
		] );

		register_rest_route( $ns, '/charts/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'read' ],
			],
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'permission_callback' => [ $this, 'require_owner' ],
				'callback'            => [ $this, 'update' ],
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'permission_callback' => [ $this, 'require_owner' ],
				'callback'            => [ $this, 'delete' ],
			],
		] );

		register_rest_route( $ns, '/charts/(?P<id>\d+)/fork', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'permission_callback' => [ $this, 'require_tier' ],
			'callback'            => [ $this, 'fork' ],
		] );
	}

	/* ---------- permission gates ---------- */

	public function require_tier( \WP_REST_Request $req ): bool|\WP_Error {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'pt_auth_required', 'Sign-in required.', [ 'status' => 401 ] );
		}
		// Nonce/bearer-token both accepted (mirrors $auth in REST_API::register()).
		if ( ! Security::verify_request( $req ) ) {
			return new \WP_Error( 'pt_auth_failed', 'Invalid credentials.', [ 'status' => 403 ] );
		}
		$quota = Access_Tiers::instance()->quota( get_current_user_id() ?: null );
		if ( ! Chart_Spec::user_can_create( $quota ) ) {
			return new \WP_Error( 'pt_tier_locked', 'Chart builder requires a paid tier.', [ 'status' => 402 ] );
		}
		return true;
	}

	public function require_owner( \WP_REST_Request $req ): bool|\WP_Error {
		$id  = absint( $req['id'] );
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return new \WP_Error( 'pt_auth_required', 'Sign-in required.', [ 'status' => 401 ] );
		}
		if ( ! Security::verify_request( $req ) ) {
			return new \WP_Error( 'pt_auth_failed', 'Invalid credentials.', [ 'status' => 403 ] );
		}
		$post = get_post( $id );
		if ( ! $post || 'user-chart' !== $post->post_type ) {
			return new \WP_Error( 'pt_not_found', 'Chart not found.', [ 'status' => 404 ] );
		}
		if ( (int) $post->post_author !== $uid && ! current_user_can( 'edit_others_posts' ) ) {
			return new \WP_Error( 'pt_forbidden', 'Not your chart.', [ 'status' => 403 ] );
		}
		return true;
	}

	/* ---------- handlers ---------- */

	public function list( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		if ( ( $gate = Throttle::instance()->enforce_rest( $req ) ) instanceof \WP_Error ) {
			return $gate;
		}

		$sort  = (string) $req['sort'];
		$order = [ 'recent' => [ 'orderby' => 'date',         'order' => 'DESC' ],
		           'forks'  => [ 'orderby' => 'meta_value_num', 'order' => 'DESC', 'meta_key' => 'pt_forks' ],
		           'views'  => [ 'orderby' => 'meta_value_num', 'order' => 'DESC', 'meta_key' => 'pt_views' ] ][ $sort ]
			?? [ 'orderby' => 'date', 'order' => 'DESC' ];

		$args = array_merge( [
			'post_type'      => 'user-chart',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $req['per_page'],
			'paged'          => (int) $req['page'],
			'no_found_rows'  => false,
		], $order );

		$kind = sanitize_title( (string) $req['kind'] );
		if ( $kind ) {
			$args['tax_query'] = [ [ 'taxonomy' => 'chart_kind', 'field' => 'slug', 'terms' => $kind ] ];
		}
		$author = (int) $req['author'];
		if ( $author > 0 ) {
			$args['author'] = $author;
		}

		$q    = new \WP_Query( $args );
		$rows = array_map( [ $this, 'shape' ], $q->posts );

		return rest_ensure_response( [
			'total'    => (int) $q->found_posts,
			'page'     => (int) $req['page'],
			'per_page' => (int) $req['per_page'],
			'rows'     => $rows,
		] );
	}

	public function read( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		if ( ( $gate = Throttle::instance()->enforce_rest( $req ) ) instanceof \WP_Error ) {
			return $gate;
		}
		$id   = absint( $req['id'] );
		$post = get_post( $id );
		if ( ! $post || 'user-chart' !== $post->post_type ) {
			return new \WP_Error( 'pt_not_found', 'Chart not found.', [ 'status' => 404 ] );
		}
		// Private charts: only the author (or editor) can resolve.
		if ( 'publish' !== $post->post_status ) {
			$uid = get_current_user_id();
			if ( ! $uid || ( (int) $post->post_author !== $uid && ! current_user_can( 'edit_others_posts' ) ) ) {
				return new \WP_Error( 'pt_forbidden', 'Not visible.', [ 'status' => 403 ] );
			}
		}
		// Hot-counter bump for analytics, fire-and-forget.
		update_post_meta( $id, 'pt_views', (int) get_post_meta( $id, 'pt_views', true ) + 1 );
		return rest_ensure_response( $this->shape( $post ) );
	}

	public function create( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		if ( ( $gate = Throttle::instance()->enforce_rest( $req ) ) instanceof \WP_Error ) {
			return $gate;
		}
		$body = (array) $req->get_json_params();
		$spec = Chart_Spec::validate( $body['spec'] ?? null );
		if ( $spec instanceof \WP_Error ) {
			return $spec;
		}
		$source = Chart_Spec::validate_source( $body['source'] ?? null );
		if ( $source instanceof \WP_Error ) {
			return $source;
		}

		$title       = sanitize_text_field( (string) ( $body['title']       ?? __( 'Untitled chart', 'publictech-core' ) ) );
		$description = sanitize_textarea_field( (string) ( $body['description'] ?? '' ) );
		$visibility  = ( 'private' === ( $body['visibility'] ?? '' ) ) ? 'private' : 'publish';

		$id = wp_insert_post( [
			'post_type'    => 'user-chart',
			'post_status'  => $visibility,
			'post_title'   => $title,
			'post_excerpt' => $description,
			'post_author'  => get_current_user_id(),
		], true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( $id, 'pt_spec',   wp_json_encode( $spec ) );
		update_post_meta( $id, 'pt_source', wp_json_encode( $source ) );
		update_post_meta( $id, 'pt_views',  0 );
		update_post_meta( $id, 'pt_forks',  0 );

		// chart_kind taxonomy mirrors data.type so the gallery can facet on it.
		wp_set_object_terms( $id, [ $spec['data']['type'] ], 'chart_kind', false );

		return rest_ensure_response( $this->shape( get_post( $id ) ) );
	}

	public function update( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$id   = absint( $req['id'] );
		$body = (array) $req->get_json_params();
		$post = get_post( $id );

		$patch = [ 'ID' => $id ];
		if ( isset( $body['title'] ) ) {
			$patch['post_title'] = sanitize_text_field( (string) $body['title'] );
		}
		if ( isset( $body['description'] ) ) {
			$patch['post_excerpt'] = sanitize_textarea_field( (string) $body['description'] );
		}
		if ( isset( $body['visibility'] ) ) {
			$patch['post_status'] = ( 'private' === $body['visibility'] ) ? 'private' : 'publish';
		}
		if ( count( $patch ) > 1 ) {
			wp_update_post( $patch );
		}

		if ( isset( $body['spec'] ) ) {
			$spec = Chart_Spec::validate( $body['spec'] );
			if ( $spec instanceof \WP_Error ) {
				return $spec;
			}
			update_post_meta( $id, 'pt_spec', wp_json_encode( $spec ) );
			wp_set_object_terms( $id, [ $spec['data']['type'] ], 'chart_kind', false );
		}
		if ( isset( $body['source'] ) ) {
			$source = Chart_Spec::validate_source( $body['source'] );
			if ( $source instanceof \WP_Error ) {
				return $source;
			}
			update_post_meta( $id, 'pt_source', wp_json_encode( $source ) );
		}
		return rest_ensure_response( $this->shape( get_post( $id ) ) );
	}

	public function delete( \WP_REST_Request $req ): \WP_REST_Response {
		$id = absint( $req['id'] );
		wp_delete_post( $id, true );
		return rest_ensure_response( [ 'ok' => true, 'id' => $id ] );
	}

	public function fork( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		if ( ( $gate = Throttle::instance()->enforce_rest( $req ) ) instanceof \WP_Error ) {
			return $gate;
		}
		$src_id = absint( $req['id'] );
		$src    = get_post( $src_id );
		if ( ! $src || 'user-chart' !== $src->post_type || 'publish' !== $src->post_status ) {
			return new \WP_Error( 'pt_not_found', 'Chart not found or not public.', [ 'status' => 404 ] );
		}
		$uid = get_current_user_id();
		/* translators: %s: original chart title */
		$title = sprintf( __( 'Fork of: %s', 'publictech-core' ), get_the_title( $src ) );

		$new_id = wp_insert_post( [
			'post_type'    => 'user-chart',
			'post_status'  => 'private',
			'post_title'   => $title,
			'post_excerpt' => $src->post_excerpt,
			'post_author'  => $uid,
		], true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// Re-validate the cloned spec/source so any drift in the validator
		// catalog catches stale data on the way out.
		$spec   = Chart_Spec::validate( json_decode( (string) get_post_meta( $src_id, 'pt_spec', true ), true ) );
		$source = Chart_Spec::validate_source( json_decode( (string) get_post_meta( $src_id, 'pt_source', true ), true ) );
		if ( ! $spec instanceof \WP_Error ) {
			update_post_meta( $new_id, 'pt_spec', wp_json_encode( $spec ) );
			wp_set_object_terms( $new_id, [ $spec['data']['type'] ], 'chart_kind', false );
		}
		if ( ! $source instanceof \WP_Error ) {
			update_post_meta( $new_id, 'pt_source', wp_json_encode( $source ) );
		}
		update_post_meta( $new_id, 'pt_views', 0 );
		update_post_meta( $new_id, 'pt_forks', 0 );

		update_post_meta( $src_id, 'pt_forks', (int) get_post_meta( $src_id, 'pt_forks', true ) + 1 );

		return rest_ensure_response( $this->shape( get_post( $new_id ) ) );
	}

	public function preview( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		if ( ( $gate = Throttle::instance()->enforce_rest( $req ) ) instanceof \WP_Error ) {
			return $gate;
		}
		$body   = (array) $req->get_json_params();
		$spec   = Chart_Spec::validate( $body['spec'] ?? null );
		if ( $spec instanceof \WP_Error ) {
			return $spec;
		}
		$source = Chart_Spec::validate_source( $body['source'] ?? null );
		if ( $source instanceof \WP_Error ) {
			return $source;
		}
		return rest_ensure_response( [
			'spec'   => $spec,
			'source' => $source,
		] );
	}

	/* ---------- shaping ---------- */

	private function shape( \WP_Post $post ): array {
		$author = get_userdata( (int) $post->post_author );
		$kinds  = wp_get_object_terms( $post->ID, 'chart_kind', [ 'fields' => 'slugs' ] );
		return [
			'id'          => $post->ID,
			'title'       => get_the_title( $post ),
			'description' => $post->post_excerpt,
			'visibility'  => 'publish' === $post->post_status ? 'public' : 'private',
			'kind'        => $kinds[0] ?? '',
			'spec'        => json_decode( (string) get_post_meta( $post->ID, 'pt_spec',   true ), true ),
			'source'      => json_decode( (string) get_post_meta( $post->ID, 'pt_source', true ), true ),
			'views'       => (int) get_post_meta( $post->ID, 'pt_views', true ),
			'forks'       => (int) get_post_meta( $post->ID, 'pt_forks', true ),
			'author'      => [
				'id'   => (int) $post->post_author,
				'name' => $author ? $author->display_name : '',
			],
			'permalink'   => get_permalink( $post ),
			'created'     => get_post_time( 'c', true, $post ),
		];
	}
}
