<?php
/**
 * Homepage dashboard statistics.
 *
 * Aggregates master_table into a compact payload used both by the homepage
 * template (inlined as window.PT_DASH) and the public REST endpoint
 * GET /publictech/v1/dashboard/stats. Result is cached 15 minutes in the
 * WP object cache to avoid hitting the DB on every page load.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Dashboard_Stats {

	private static ?self $instance = null;

	private const CACHE_KEY   = 'pt_dash_stats_v2';
	private const CACHE_GROUP = 'publictech';
	private const CACHE_TTL   = 90000; // 25 hours — refreshed by nightly cron at 02:00

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register' ] );
	}

	public function register(): void {
		register_rest_route( PUBLICTECH_CORE_REST_NS, '/dashboard/stats', [
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'rest_handler' ],
		] );
	}

	public function rest_handler( \WP_REST_Request $req ): \WP_REST_Response {
		$resp = rest_ensure_response( self::get() );
		$resp->header( 'Cache-Control', 'public, max-age=' . self::CACHE_TTL );
		return $resp;
	}

	/**
	 * Return aggregated dashboard stats. Safe to call from templates — uses a
	 * two-layer cache (in-memory object cache + persistent DB transient) and
	 * never throws (returns empty payload on DB failure).
	 *
	 * On hosts without a persistent object cache (Memcached/Redis), wp_cache
	 * resets every request. The transient layer ensures the expensive DB
	 * aggregation only runs once per CACHE_TTL period regardless of host config.
	 */
	public static function get(): array {
		// Layer 1: in-memory object cache (free hit if Redis/Memcached is configured).
		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Layer 2: persistent DB transient — survives across PHP processes.
		$transient = get_transient( self::CACHE_KEY );
		if ( is_array( $transient ) ) {
			wp_cache_set( self::CACHE_KEY, $transient, self::CACHE_GROUP, self::CACHE_TTL );
			return $transient;
		}

		$aliases = Multi_DB::instance()->aliases();
		if ( empty( $aliases ) ) {
			return self::empty_payload();
		}

		try {
			$payload = self::query( Multi_DB::instance()->connection( $aliases[0] ) );
		} catch ( \Throwable $e ) {
			error_log( '[PublicTech] Dashboard_Stats::query failed — ' . $e->getMessage() );
			$payload = self::empty_payload();
		}

		set_transient( self::CACHE_KEY, $payload, self::CACHE_TTL );
		wp_cache_set( self::CACHE_KEY, $payload, self::CACHE_GROUP, self::CACHE_TTL );
		return $payload;
	}

	private static function query( Connection $db ): array {
		// Aggregate queries over master_table take 30-120 s on shared MySQL —
		// remove the per-statement limit for this cached batch job.
		$db->pdo()->exec( 'SET SESSION max_execution_time=0' );

		// Headline totals.
		$head = $db->select(
			'SELECT
			    COALESCE(SUM(agreement_value), 0) AS total_spend,
			    COUNT(*)                          AS total_agreements,
			    COALESCE(AVG(agreement_value), 0) AS avg_value
			 FROM master_table
			 WHERE agreement_value > 0'
		);

		// Annual spend (line chart — year column is MySQL YEAR type, cast to unsigned).
		$by_year = $db->select(
			'SELECT CAST(year AS UNSIGNED) AS x, COALESCE(SUM(agreement_value), 0) AS y
			 FROM master_table
			 WHERE agreement_value > 0 AND year IS NOT NULL AND year >= 2010
			 GROUP BY year
			 ORDER BY year ASC'
		);

		// Spend by Canadian province (horizontal bar chart).
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

		// Top 10 recipients by total received (horizontal bar chart).
		$top_recipients = $db->select(
			"SELECT
			    COALESCE(NULLIF(TRIM(recipient_operating_name), ''), TRIM(recipient_legal_name)) AS label,
			    COALESCE(SUM(agreement_value), 0) AS value,
			    COUNT(*)                          AS count
			 FROM master_table
			 WHERE agreement_value > 0
			   AND recipient_legal_name IS NOT NULL
			   AND TRIM(recipient_legal_name) != ''
			 GROUP BY label
			 ORDER BY value DESC
			 LIMIT 10"
		);

		// 20 largest agreements for the scrolling ticker.
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
