<?php
/**
 * MP Finance Cache warmer.
 *
 * Reads the three HoC expenditure tables from the primary grantconts database
 * (hoc_member_expenditures + hoc_reporting_periods) and writes precomputed
 * per-MP spend rows into two new tables in the secondary cache database
 * (govdata_cache_02 / pt-cache-02):
 *
 *   mp_expenditure_periods  — fiscal_year × category totals per MP.
 *                             Read by GET /publictech/v1/mp/{id}/spend.
 *   mp_kpi_cache            — all-time KPI totals per MP.
 *                             Written into WP post meta by this warmer.
 *
 * This is a batch job (cron-weekly or WP-CLI triggered). It opens its own
 * PDO handles rather than the read-only Multi_DB pool because it must WRITE
 * to the cache database.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class MP_Finance_Warmer {

	public const  HOOK      = 'publictech_warm_mp_finance';
	private const LOCK_KEY  = 'pt_mp_finance_warming';

	/** @var callable(string,string):void */
	private $logger;

	private ?\PDO $source = null;
	private ?\PDO $cache  = null;

	/**
	 * @param callable(string,string):void|null $logger Optional progress sink.
	 */
	public function __construct( ?callable $logger = null ) {
		$this->logger = $logger ?? static function ( string $msg, string $level ): void {
			if ( 'error' === $level || 'warn' === $level ) {
				error_log( '[PublicTech] mp-finance: ' . $msg );
			}
		};
	}

	/** Connection alias for the primary grantconts (read) database. */
	public static function source_alias(): string {
		return (string) apply_filters( 'publictech_mp_finance_source_alias', 'pt-grantconts-01' );
	}

	/** Connection alias for the secondary cache (read/write) database. */
	public static function cache_alias(): string {
		return (string) apply_filters( 'publictech_mp_finance_cache_alias', 'pt-cache-02' );
	}

	/* ─────────────────────────── scheduling ─────────────────────────── */

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$first = strtotime( 'next sunday 05:00 UTC' ) ?: ( time() + DAY_IN_SECONDS );
			wp_schedule_event( $first, 'weekly', self::HOOK );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}
	}

	/** WP-Cron entry point. */
	public static function run_warm(): void {
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}
		set_transient( self::LOCK_KEY, time(), HOUR_IN_SECONDS );
		try {
			( new self() )->run();
		} catch ( \Throwable $e ) {
			error_log( '[PublicTech] mp-finance warm failed: ' . $e->getMessage() );
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	/* ─────────────────────────── main run ─────────────────────────── */

	/**
	 * Run the full warm. Idempotent — all rows are REPLACE INTO (upsert).
	 *
	 * @return array{mps:int,wp_updated:int,elapsed:float}
	 */
	public function run(): array {
		$t0 = microtime( true );

		$this->connect();
		$this->ensure_schema();

		$this->log( 'Loading expenditure rows from source…' );
		$period_rows = $this->load_expenditures();
		$mp_count    = count( array_unique( array_column( $period_rows, 'mp_id' ) ) );
		$this->log( sprintf( 'Loaded %d fiscal-year rows for %d MPs.', count( $period_rows ), $mp_count ) );

		$this->write_periods( $period_rows );
		$this->log( 'Period rows written to cache.' );

		$kpi_rows = $this->load_kpis();
		$this->write_kpis( $kpi_rows );
		$this->log( sprintf( 'KPI rows written for %d MPs.', count( $kpi_rows ) ) );

		$kpi_map    = array_column( $kpi_rows, null, 'mp_id' );
		$wp_updated = $this->sync_post_meta( $kpi_map );
		$this->log( sprintf( 'Post meta updated for %d WP posts.', $wp_updated ), 'success' );

		$elapsed = round( microtime( true ) - $t0, 2 );
		$this->log( sprintf( 'Warm complete in %ss.', $elapsed ), 'success' );

		return [
			'mps'        => $mp_count,
			'wp_updated' => $wp_updated,
			'elapsed'    => $elapsed,
		];
	}

	/* ─────────────────────────── source reads ─────────────────────────── */

	/**
	 * Read and aggregate expenditures by MP and fiscal year.
	 *
	 * Returns rows: [ mp_id, fiscal_year (INT, start year), salaries,
	 *                 travel, hospitality, contracts ]
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function load_expenditures(): array {
		$stmt = $this->source->query(
			'SELECT
			     e.mp_id,
			     CAST(LEFT(p.fiscal_year, 4) AS UNSIGNED) AS fiscal_year,
			     SUM(e.salaries)    AS salaries,
			     SUM(e.travel)      AS travel,
			     SUM(e.hospitality) AS hospitality,
			     SUM(e.contracts)   AS contracts
			   FROM hoc_member_expenditures  e
			   JOIN hoc_reporting_periods    p ON e.period_id = p.period_id
			  GROUP BY e.mp_id, p.fiscal_year
			  ORDER BY e.mp_id, p.fiscal_year'
		);
		return $stmt->fetchAll( \PDO::FETCH_ASSOC );
	}

	/**
	 * Read all-time totals per MP.
	 *
	 * Returns rows: [ mp_id, total_spend, travel_spend, contracts_spend ]
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function load_kpis(): array {
		$stmt = $this->source->query(
			'SELECT
			     mp_id,
			     SUM(salaries + travel + hospitality + contracts) AS total_spend,
			     SUM(travel)    AS travel_spend,
			     SUM(contracts) AS contracts_spend
			   FROM hoc_member_expenditures
			  GROUP BY mp_id'
		);
		return $stmt->fetchAll( \PDO::FETCH_ASSOC );
	}

	/* ─────────────────────────── cache writes ─────────────────────────── */

	/**
	 * Pivot each aggregate row into 4 category rows and REPLACE INTO cache.
	 * Committed in batches of 500 source rows to avoid long-running transactions.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function write_periods( array $rows ): void {
		if ( empty( $rows ) ) {
			return;
		}

		$categories = [
			'Salaries'    => 'salaries',
			'Travel'      => 'travel',
			'Hospitality' => 'hospitality',
			'Contracts'   => 'contracts',
		];

		$stmt = $this->cache->prepare(
			'REPLACE INTO `mp_expenditure_periods`
			     (`mp_id`, `fiscal_year`, `category`, `total`)
			 VALUES (?, ?, ?, ?)'
		);

		$batch = 0;
		$this->cache->beginTransaction();
		foreach ( $rows as $row ) {
			foreach ( $categories as $label => $col ) {
				$stmt->execute( [
					$row['mp_id'],
					(int) $row['fiscal_year'],
					$label,
					$row[ $col ] ?? '0',
				] );
			}
			if ( ++$batch % 500 === 0 ) {
				$this->cache->commit();
				$this->cache->beginTransaction();
			}
		}
		$this->cache->commit();
	}

	/**
	 * REPLACE INTO mp_kpi_cache for all MPs.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function write_kpis( array $rows ): void {
		if ( empty( $rows ) ) {
			return;
		}

		$stmt = $this->cache->prepare(
			'REPLACE INTO `mp_kpi_cache`
			     (`mp_id`, `total_spend`, `travel_spend`, `contracts_spend`, `updated_at`)
			 VALUES (?, ?, ?, ?, UTC_TIMESTAMP())'
		);

		$this->cache->beginTransaction();
		$batch = 0;
		foreach ( $rows as $row ) {
			$stmt->execute( [
				$row['mp_id'],
				$row['total_spend']    ?? '0',
				$row['travel_spend']   ?? '0',
				$row['contracts_spend'] ?? '0',
			] );
			if ( ++$batch % 500 === 0 ) {
				$this->cache->commit();
				$this->cache->beginTransaction();
			}
		}
		$this->cache->commit();
	}

	/* ─────────────────────────── post meta ─────────────────────────── */

	/**
	 * Update WP post meta for every member-parliament post whose external_mp_id
	 * has a matching row in the KPI cache. Returns the count of updated posts.
	 *
	 * @param array<string,array<string,mixed>> $kpi_map Keyed by mp_id.
	 */
	private function sync_post_meta( array $kpi_map ): int {
		if ( empty( $kpi_map ) ) {
			return 0;
		}

		$posts = get_posts( [
			'post_type'      => 'member-parliament',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		$updated = 0;
		foreach ( $posts as $post_id ) {
			$ext_id = (string) get_post_meta( $post_id, 'external_mp_id', true );
			if ( '' === $ext_id || ! isset( $kpi_map[ $ext_id ] ) ) {
				continue;
			}
			$kpi = $kpi_map[ $ext_id ];
			update_post_meta( $post_id, 'total_spend',  (int) $kpi['total_spend'] );
			update_post_meta( $post_id, 'travel_spend', (int) $kpi['travel_spend'] );
			update_post_meta( $post_id, 'contracts_n',  (int) $kpi['contracts_spend'] );
			$updated++;
		}

		return $updated;
	}

	/* ─────────────────────────── schema ─────────────────────────── */

	private function ensure_schema(): void {
		$this->cache->exec(
			'CREATE TABLE IF NOT EXISTS `mp_expenditure_periods` (
				`id`          INT           NOT NULL AUTO_INCREMENT,
				`mp_id`       VARCHAR(10)   NOT NULL,
				`fiscal_year` SMALLINT      NOT NULL,
				`category`    VARCHAR(20)   NOT NULL,
				`total`       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
				PRIMARY KEY (`id`),
				UNIQUE KEY `idx_mp_year_cat` (`mp_id`, `fiscal_year`, `category`),
				KEY `idx_mp` (`mp_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
		);

		$this->cache->exec(
			'CREATE TABLE IF NOT EXISTS `mp_kpi_cache` (
				`mp_id`           VARCHAR(10)   NOT NULL,
				`total_spend`     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
				`travel_spend`    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
				`contracts_spend` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
				`updated_at`      DATETIME      NOT NULL,
				PRIMARY KEY (`mp_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
		);
	}

	/* ─────────────────────────── connections ─────────────────────────── */

	private function connection_def( string $alias ): array {
		$defs = Multi_DB::connections();
		if ( ! isset( $defs[ $alias ] ) ) {
			throw new \RuntimeException( "Connection alias not configured: {$alias}" );
		}
		return $defs[ $alias ];
	}

	private function build_pdo( array $cfg, bool $writable ): \PDO {
		$dsn = sprintf(
			'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
			$cfg['host'],
			(int) ( $cfg['port'] ?? 3306 ),
			$cfg['name']
		);
		$options = [
			\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
			\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
			\PDO::ATTR_TIMEOUT            => (int) ( $cfg['connect_timeout'] ?? 10 ),
		];
		if ( ! $writable ) {
			$options[ \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY ] = false;
		}
		$pdo = new \PDO( $dsn, $cfg['user'], $cfg['pass'], $options );
		$pdo->exec( "SET SESSION time_zone = '+00:00'" );
		$pdo->exec( 'SET SESSION MAX_EXECUTION_TIME = 0' );
		return $pdo;
	}

	private function connect(): void {
		$this->source = $this->build_pdo( $this->connection_def( self::source_alias() ), false );
		$this->cache  = $this->build_pdo( $this->connection_def( self::cache_alias() ), true );
	}

	/* ─────────────────────────── logging ─────────────────────────── */

	private function log( string $msg, string $level = 'info' ): void {
		( $this->logger )( $msg, $level );
	}
}
