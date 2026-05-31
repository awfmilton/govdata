<?php
/**
 * Homepage dashboard statistics.
 *
 * Aggregates master_table into a compact payload used both by the homepage
 * template (inlined as window.PT_DASH) and the public REST endpoint
 * GET /publictech/v1/dashboard/stats.
 *
 * The DB query NEVER runs during a page request. get() returns the cached
 * value instantly and, on a cache miss, schedules an immediate background
 * cron event (run_warm) to repopulate the transient. A recurring daily cron
 * keeps the cache fresh between miss/warm cycles.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Dashboard_Stats {

	private static ?self $instance = null;

	public  const CRON_HOOK  = 'publictech_refresh_dashboard_stats';
	private const CACHE_KEY   = 'pt_dash_stats_v2';
	private const CACHE_GROUP = 'publictech';
	private const CACHE_TTL   = 90000; // 25 hours

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register' ] );
	}

	// ── REST ─────────────────────────────────────────────────────────────────

	public function register(): void {
		register_rest_route( PUBLICTECH_CORE_REST_NS, '/dashboard/stats', [
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'rest_handler' ],
		] );
	}

	public function rest_handler( \WP_REST_Request $req ): \WP_REST_Response {
		$resp = rest_ensure_response( self::get() );
		$resp->header( 'Cache-Control', 'public, max-age=3600' );
		return $resp;
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Return cached dashboard stats. Always returns immediately — never blocks
	 * on a DB query. If the cache is cold, returns the empty payload and
	 * schedules a background warm so the next page load has real data.
	 */
	public static function get(): array {
		// Layer 1: in-memory object cache (free hit when Redis/Memcached is up).
		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Layer 2: persistent transient (survives PHP restarts without Redis).
		$transient = get_transient( self::CACHE_KEY );
		if ( is_array( $transient ) ) {
			wp_cache_set( self::CACHE_KEY, $transient, self::CACHE_GROUP, self::CACHE_TTL );
			return $transient;
		}

		// Cache is cold — kick off a background refresh and return empty immediately.
		// The page never waits for the DB.
		self::schedule_immediate_warm();
		return self::empty_payload();
	}

	// ── Cron scheduling ───────────────────────────────────────────────────────

	/** Register a daily recurring warm (called on `init`). */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/** Remove all scheduled events (called on theme switch). */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/** Schedule a single immediate warm if one is not already pending. */
	private static function schedule_immediate_warm(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
	}

	// ── Background warm (runs via WP-Cron, never on a page request) ───────────

	public static function run_warm(): void {
		// Always use the primary grantconts alias — not aliases[0] which may
		// resolve to the cache database depending on definition order.
		$source_alias = (string) apply_filters( 'publictech_dashboard_source_alias', 'pt-grantconts-01' );
		try {
			$conn = Multi_DB::instance()->connection( $source_alias );
		} catch ( \Throwable $e ) {
			error_log( '[PublicTech] Dashboard_Stats::run_warm — alias not found: ' . $e->getMessage() );
			return;
		}

		try {
			$payload = self::query( $conn );
		} catch ( \Throwable $e ) {
			error_log( '[PublicTech] Dashboard_Stats::run_warm failed — ' . $e->getMessage() );
			// Preserve any stale cache rather than writing empty data.
			// Schedule a retry in 10 minutes so we try again soon.
			wp_schedule_single_event( time() + 600, self::CRON_HOOK );
			return;
		}

		set_transient( self::CACHE_KEY, $payload, self::CACHE_TTL );
		wp_cache_set( self::CACHE_KEY, $payload, self::CACHE_GROUP, self::CACHE_TTL );
	}

	// ── DB query (only called from run_warm, never from get()) ────────────────

	private static function query( Connection $db ): array {
		// 120-second MySQL statement timeout — generous budget for background cron
		// running aggregate queries against the full 1M+ row master_table.
		$db->pdo()->exec( 'SET SESSION max_execution_time=120000' );

		$head = $db->select(
			'SELECT
			    COALESCE(SUM(agreement_value), 0) AS total_spend,
			    COUNT(*)                          AS total_agreements,
			    COALESCE(AVG(agreement_value), 0) AS avg_value
			 FROM master_table
			 WHERE agreement_value > 0'
		);

		$by_year = $db->select(
			'SELECT CAST(year AS UNSIGNED) AS x, COALESCE(SUM(agreement_value), 0) AS y
			 FROM master_table
			 WHERE agreement_value > 0 AND year IS NOT NULL AND year >= 2010
			 GROUP BY year
			 ORDER BY year ASC'
		);

		$by_province = $db->select(
			"SELECT
			    recipient_province                AS label,
			    COALESCE(SUM(agreement_value), 0) AS value,
			    COUNT(*)                          AS count
			 FROM master_table
			 WHERE recipient_province IS NOT NULL
			   AND recipient_province != ''
			   AND agreement_value > 0
			 GROUP BY recipient_province
			 ORDER BY value DESC
			 LIMIT 13"
		);

		// GROUP BY the raw recipient_legal_name column (not a computed expression)
		// so the covering index idx_legalname_value (recipient_legal_name,
		// agreement_value) is used — an index-only scan instead of a full-table
		// scan + temp table. Brings this query from ~67s to ~1s on 1.26M rows.
		$top_recipients = $db->select(
			"SELECT
			    recipient_legal_name              AS label,
			    COALESCE(SUM(agreement_value), 0) AS value,
			    COUNT(*)                          AS count
			 FROM master_table
			 WHERE agreement_value > 0
			   AND recipient_legal_name IS NOT NULL
			   AND recipient_legal_name != ''
			 GROUP BY recipient_legal_name
			 ORDER BY value DESC
			 LIMIT 10"
		);

		$recent_large = $db->select(
			"SELECT
			    COALESCE(NULLIF(TRIM(recipient_operating_name), ''), TRIM(recipient_legal_name)) AS name,
			    agreement_value                                                                  AS value,
			    COALESCE(recipient_province, '')                                                 AS province,
			    COALESCE(fiscal_year, '')                                                        AS fiscal_year,
			    COALESCE(prog_name_en, '')                                                       AS program
			 FROM master_table
			 WHERE agreement_value > 0
			   AND recipient_legal_name IS NOT NULL
			   AND TRIM(recipient_legal_name) != ''
			 ORDER BY agreement_value DESC
			 LIMIT 20"
		);

		$h = $head[0] ?? [];

		return [
			'total_spend'      => (float) ( $h['total_spend']      ?? 0 ),
			'total_agreements' => (int)   ( $h['total_agreements'] ?? 0 ),
			'avg_value'        => (float) ( $h['avg_value']        ?? 0 ),
			'by_year'          => array_map(
				fn( $r ) => [ 'x' => (int) $r['x'], 'y' => (float) $r['y'] ],
				$by_year
			),
			'by_province'      => array_map(
				fn( $r ) => [
					'label' => (string) $r['label'],
					'value' => (float)  $r['value'],
					'count' => (int)    $r['count'],
				],
				$by_province
			),
			'top_recipients'   => array_map(
				fn( $r ) => [
					'label' => (string) $r['label'],
					'value' => (float)  $r['value'],
					'count' => (int)    $r['count'],
				],
				$top_recipients
			),
			'recent_large'     => array_map(
				fn( $r ) => [
					'name'        => (string) $r['name'],
					'value'       => (float)  $r['value'],
					'province'    => (string) $r['province'],
					'fiscal_year' => (string) $r['fiscal_year'],
					'program'     => (string) $r['program'],
				],
				$recent_large
			),
		];
	}

	private static function empty_payload(): array {
		return [
			'total_spend'      => 0,
			'total_agreements' => 0,
			'avg_value'        => 0,
			'by_year'          => [],
			'by_province'      => [],
			'top_recipients'   => [],
			'recent_large'     => [],
		];
	}
}
