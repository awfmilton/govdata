<?php
/**
 * Riding-cache read path + scheduler.
 *
 * Serves the precomputed per-riding spending JSON to the MP profile and
 * riding-report templates (and to the REST endpoints the front end calls when
 * a visitor "plays with" the data). Every read here goes to the SECONDARY
 * cache database via the read-only Multi_DB pool — never to the primary
 * grantconts instance.
 *
 * Also owns the weekly WP-Cron event that re-runs {@see Riding_Cache_Warmer}.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Riding_Cache {
	private static ?self $instance = null;
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public const HOOK      = 'publictech_warm_riding_cache';
	private const LOCK_KEY  = 'pt_riding_cache_warming';
	private const CACHE_GRP = 'publictech_riding';

	/** @var array<string,mixed>|null Per-request memo of the riding directory. */
	private ?array $directory_memo = null;

	private function __construct() {
		add_action( 'init',        [ $this, 'schedule' ] );
		add_action( self::HOOK,     [ $this, 'run_warm' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/* ───────────────────────── scheduling ───────────────────────── */

	public function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			// First run next Sunday 04:00 UTC, then weekly (the keyword report's
			// "weekly refresh logic").
			$first = strtotime( 'next sunday 04:00 UTC' ) ?: ( time() + DAY_IN_SECONDS );
			wp_schedule_event( $first, 'weekly', self::HOOK );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}
	}

	/**
	 * Cron entry. A short lock guards against an overlapping run (the warm is a
	 * long batch; for very large datasets prefer the WP-CLI trigger).
	 */
	public function run_warm(): void {
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}
		set_transient( self::LOCK_KEY, time(), HOUR_IN_SECONDS );
		try {
			( new Riding_Cache_Warmer() )->run();
			$this->flush_memo();
		} catch ( \Throwable $e ) {
			error_log( '[PublicTech] riding-cache warm failed: ' . $e->getMessage() );
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	/* ───────────────────────── reads ───────────────────────── */

	/**
	 * Decoded report payload for a riding number, or null on miss / unconfigured cache.
	 *
	 * @return array<string,mixed>|null
	 */
	public function report_by_number( string $riding_number ): ?array {
		$riding_number = trim( $riding_number );
		if ( '' === $riding_number ) {
			return null;
		}
		$cache_key = 'report_n_' . $riding_number;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GRP );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$payload = $this->fetch_payload( '`riding_number`', $riding_number );
		if ( null !== $payload ) {
			wp_cache_set( $cache_key, $payload, self::CACHE_GRP, 5 * MINUTE_IN_SECONDS );
		}
		return $payload;
	}

	/**
	 * Decoded report payload for a riding slug, or null on miss.
	 *
	 * @return array<string,mixed>|null
	 */
	public function report_by_slug( string $slug ): ?array {
		$slug = pt_riding_slug( $slug );
		if ( '' === $slug ) {
			return null;
		}
		return $this->fetch_payload( '`riding_slug`', $slug );
	}

	/**
	 * Resolve a member-parliament post to a riding directory entry, then return
	 * its report payload. Resolution order: explicit riding-number meta, then
	 * slug meta, then a normalised match of the MP's district name.
	 *
	 * @return array<string,mixed>|null
	 */
	public function report_for_post( int $post_id ): ?array {
		$entry = $this->resolve_for_post( $post_id );
		if ( $entry && ! empty( $entry['number'] ) ) {
			return $this->report_by_number( (string) $entry['number'] );
		}
		return null;
	}

	/**
	 * Riding directory entry an MP post maps to, or null when unresolved.
	 *
	 * @return array<string,mixed>|null
	 */
	public function resolve_for_post( int $post_id ): ?array {
		$number = (string) get_post_meta( $post_id, 'pt_riding_number', true );
		if ( '' !== $number ) {
			return $this->directory_by_number( $number ) ?? [ 'number' => $number ];
		}
		$slug = (string) get_post_meta( $post_id, 'pt_riding_slug', true );
		if ( '' !== $slug ) {
			$entry = $this->directory_by_slug( $slug );
			if ( $entry ) {
				return $entry;
			}
		}
		$district = (string) ( get_post_meta( $post_id, 'pt_mp_district', true )
			?: get_post_meta( $post_id, 'riding', true ) );
		if ( '' !== $district ) {
			return $this->directory_by_name( $district );
		}
		return null;
	}

	/* ───────────────────────── directory ───────────────────────── */

	/**
	 * The riding directory (number/slug/name/normalized) written by the warmer.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function directory(): array {
		if ( null !== $this->directory_memo ) {
			return $this->directory_memo;
		}
		$cached = wp_cache_get( 'directory', self::CACHE_GRP );
		if ( is_array( $cached ) ) {
			return $this->directory_memo = $cached;
		}
		$json = $this->meta( 'riding_directory' );
		$list = $json ? json_decode( $json, true ) : [];
		$list = is_array( $list ) ? $list : [];
		wp_cache_set( 'directory', $list, self::CACHE_GRP, 10 * MINUTE_IN_SECONDS );
		return $this->directory_memo = $list;
	}

	/** @return array<string,mixed>|null */
	public function directory_by_number( string $number ): ?array {
		foreach ( $this->directory() as $entry ) {
			if ( (string) ( $entry['number'] ?? '' ) === $number ) {
				return $entry;
			}
		}
		return null;
	}

	/** @return array<string,mixed>|null */
	public function directory_by_slug( string $slug ): ?array {
		$slug = pt_riding_slug( $slug );
		foreach ( $this->directory() as $entry ) {
			if ( (string) ( $entry['slug'] ?? '' ) === $slug ) {
				return $entry;
			}
		}
		return null;
	}

	/** @return array<string,mixed>|null */
	public function directory_by_name( string $name ): ?array {
		$normal = pt_normalize_riding_name( $name );
		if ( '' === $normal ) {
			return null;
		}
		foreach ( $this->directory() as $entry ) {
			if ( (string) ( $entry['normalized'] ?? '' ) === $normal ) {
				return $entry;
			}
		}
		return null;
	}

	/** Read a single cache_meta value. */
	public function meta( string $key ): ?string {
		try {
			$rows = publictech_db( Riding_Cache_Warmer::cache_alias() )->select(
				'SELECT `meta_value` FROM `cache_meta` WHERE `meta_key` = ? LIMIT 1',
				[ $key ]
			);
		} catch ( \Throwable $e ) {
			return null;
		}
		return isset( $rows[0]['meta_value'] ) ? (string) $rows[0]['meta_value'] : null;
	}

	/* ───────────────────────── internals ───────────────────────── */

	/**
	 * @return array<string,mixed>|null
	 */
	private function fetch_payload( string $where_col, string $value ): ?array {
		try {
			$rows = publictech_db( Riding_Cache_Warmer::cache_alias() )->select(
				"SELECT `payload` FROM `riding_cache` WHERE {$where_col} = ? LIMIT 1",
				[ $value ]
			);
		} catch ( \Throwable $e ) {
			// Cache DB not configured/reachable — the template renders an empty state.
			return null;
		}
		if ( empty( $rows[0]['payload'] ) ) {
			return null;
		}
		$decoded = json_decode( (string) $rows[0]['payload'], true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private function flush_memo(): void {
		$this->directory_memo = null;
		wp_cache_delete( 'directory', self::CACHE_GRP );
	}

	/* ───────────────────────── REST ───────────────────────── */

	public function register_routes(): void {
		register_rest_route( PUBLICTECH_CORE_REST_NS, '/riding/(?P<key>[A-Za-z0-9\-]+)/report', [
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'rest_riding_report' ],
		] );
		register_rest_route( PUBLICTECH_CORE_REST_NS, '/mp/(?P<id>\d+)/riding', [
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'rest_mp_riding' ],
		] );
	}

	/**
	 * GET /riding/{key}/report — {key} is a riding number or slug. Reads only
	 * the secondary cache DB.
	 */
	public function rest_riding_report( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		if ( ( $gate = Throttle::instance()->enforce_rest( $req ) ) instanceof \WP_Error ) {
			return $gate;
		}
		$key     = (string) $req['key'];
		$payload = ctype_digit( $key ) ? $this->report_by_number( $key ) : $this->report_by_slug( $key );
		if ( null === $payload ) {
			return new \WP_Error( 'pt_not_found', 'No cached report for this riding.', [ 'status' => 404 ] );
		}
		$resp = rest_ensure_response( $payload );
		$resp->header( 'Cache-Control', 'public, max-age=300' );
		return $resp;
	}

	/**
	 * GET /mp/{id}/riding — resolve an MP to their riding's cached report.
	 */
	public function rest_mp_riding( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		if ( ( $gate = Throttle::instance()->enforce_rest( $req ) ) instanceof \WP_Error ) {
			return $gate;
		}
		$post_id = absint( $req['id'] );
		if ( $post_id <= 0 || 'member-parliament' !== get_post_type( $post_id ) ) {
			return new \WP_Error( 'pt_not_found', 'Member not found.', [ 'status' => 404 ] );
		}
		$payload = $this->report_for_post( $post_id );
		if ( null === $payload ) {
			return new \WP_Error( 'pt_not_found', 'No cached riding report for this member.', [ 'status' => 404 ] );
		}
		$resp = rest_ensure_response( $payload );
		$resp->header( 'Cache-Control', 'public, max-age=300' );
		return $resp;
	}
}
