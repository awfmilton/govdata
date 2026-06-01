<?php
/**
 * Riding-cache warmer.
 *
 * Reads the primary grantconts database (read-only) and precomputes, for every
 * federal riding, the spending rollups the MP pages display — all-time total,
 * spending by year, top-10 recipients, recipient-type split, top programs, and
 * the controversial-keyword scan. The result is written as one JSON payload per
 * riding into the secondary cache database so the front end never touches
 * grantconts at request time.
 *
 * Linking method: agreements are attributed to a riding via the Postal Code
 * Federal Riding File (`pcbfr`) — the recipient postal code is matched (after
 * normalisation) to the riding's postal codes, mirroring the methodology used
 * for the Thunder Bay–Rainy River sample. A postal code that spans two ridings
 * contributes to both.
 *
 * This is a batch job (cron-weekly or CLI/URL triggered), not a request-path
 * query. It opens its own PDO handles rather than the read-only Multi_DB pool
 * because it must WRITE to the cache database.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Riding_Cache_Warmer {

	/** Source (read) table name on grantconts. */
	private const SOURCE_TABLE = 'master_table';

	/**
	 * Representation-order ID used for the postal→riding mapping.
	 * 1 = 2013 RO (338 ridings, in effect 2015-2025).
	 * 2 = 2023 RO (343 ridings, in effect 2025-present).
	 *
	 * Phase 2: fixed to the current RO so postal codes are unambiguous.
	 * Phase 4: replace with a per-agreement era join keyed on agreement_start_date.
	 */
	private const PCFRF_RO_ID = 2;

	/** @var callable(string,string):void Progress logger: (message, level). */
	private $logger;

	private ?\PDO $source = null;
	private ?\PDO $cache  = null;

	/**
	 * @param callable(string,string):void|null $logger Optional progress sink.
	 */
	public function __construct( ?callable $logger = null ) {
		$this->logger = $logger ?? static function ( string $msg, string $level ): void {
			if ( 'error' === $level ) {
				error_log( '[PublicTech] riding-cache: ' . $msg );
			}
		};
	}

	/** Connection alias for the primary grantconts (read) database. */
	public static function source_alias(): string {
		return (string) apply_filters( 'publictech_riding_cache_source_alias', 'pt-grantconts-01' );
	}

	/** Connection alias for the secondary cache (read/write) database. */
	public static function cache_alias(): string {
		return (string) apply_filters( 'publictech_riding_cache_alias', 'pt-cache-02' );
	}

	private function log( string $msg, string $level = 'info' ): void {
		( $this->logger )( $msg, $level );
	}

	/**
	 * Run the full warm. Idempotent — each riding row is upserted.
	 *
	 * @param array{only_riding?:string} $opts
	 * @return array{ridings:int,agreements:int,elapsed:float,generated_at:string}
	 */
	public function run( array $opts = [] ): array {
		$t0           = microtime( true );
		$generated_at = gmdate( 'c' );
		$only_riding  = isset( $opts['only_riding'] ) ? trim( (string) $opts['only_riding'] ) : '';

		$this->connect();
		$this->ensure_schema();

		$directory       = $this->load_directory();
		$recipient_types = $this->load_recipient_types();
		$this->log( sprintf( 'Loaded %d ridings from the PCFRF directory.', count( $directory ) ) );

		// Heavy aggregate passes over (master_table ⋈ PCFRF). Each is one scan.
		$keywords  = array_values( pt_riding_keywords() );
		$main      = $this->aggregate_main( $keywords, $only_riding );
		$this->log( sprintf( 'Main rollup: %d ridings with funding.', count( $main ) ) );

		$years     = $this->aggregate_years( $only_riding );
		$rectypes  = $this->aggregate_recipient_types( $recipient_types, $only_riding );
		$recips    = $this->aggregate_recipients( $only_riding );
		$programs  = $this->aggregate_programs( $only_riding );

		// Assemble + upsert one payload per funded riding.
		$written     = 0;
		$agreements  = 0;
		foreach ( $main as $rn => $main_row ) {
			$dir   = $directory[ $rn ] ?? [];
			$riding = [
				'number'            => (string) $rn,
				'name_en'           => (string) ( $dir['name_en'] ?? '' ),
				'name_fr'           => (string) ( $dir['name_fr'] ?? '' ),
				'province'          => pt_province_from_riding_number( (string) $rn ),
				'postal_code_count' => (int) ( $dir['postal_code_count'] ?? 0 ),
				'slug'              => pt_riding_slug( (string) ( $dir['name_en'] ?? $rn ) ) ?: ( 'fed-' . $rn ),
			];

			$payload = pt_assemble_riding_payload( [
				'riding'          => $riding,
				'main'            => $main_row['main'],
				'keywords'        => $main_row['keywords'],
				'years'           => $years[ $rn ] ?? [],
				'recipient_types' => $rectypes[ $rn ] ?? [],
				'recipients'      => $recips[ $rn ] ?? [],
				'programs'        => $programs[ $rn ] ?? [],
				'generated_at'    => $generated_at,
			] );

			$this->upsert_riding( $riding, $payload );
			$written++;
			$agreements += (int) $payload['totals']['agreement_count'];
		}

		$this->write_meta( $directory, $generated_at, $written );

		$elapsed = round( microtime( true ) - $t0, 2 );
		$this->log( sprintf( 'Warm complete: %d ridings written in %ss.', $written, $elapsed ), 'success' );

		return [
			'ridings'      => $written,
			'agreements'   => $agreements,
			'elapsed'      => $elapsed,
			'generated_at' => $generated_at,
		];
	}

	/* ───────────────────────── connections ───────────────────────── */

	private function connection_def( string $alias ): array {
		$defs = Multi_DB::connections();
		if ( ! isset( $defs[ $alias ] ) ) {
			throw new \RuntimeException( "Connection alias not configured: {$alias}" );
		}
		return $defs[ $alias ];
	}

	private function build_pdo( array $cfg, bool $writable ): \PDO {
		$dsn = sprintf(
			'mysql:host=%s;port=%d;dbname=%s;charset=%s',
			$cfg['host'],
			(int) ( $cfg['port'] ?? 3306 ),
			$cfg['name'],
			$cfg['charset'] ?? 'utf8mb4'
		);
		$options = [
			\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
			\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
			\PDO::ATTR_TIMEOUT            => (int) ( $cfg['connect_timeout'] ?? 10 ),
		];
		// Stream large grouped result sets instead of buffering them in PHP.
		if ( ! $writable ) {
			$options[ \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY ] = false;
		}
		$pdo = new \PDO( $dsn, $cfg['user'], $cfg['pass'], $options );
		// Generous batch execution budget; this is not a request-path query.
		$pdo->exec( "SET SESSION time_zone = '+00:00'" );
		$pdo->exec( 'SET SESSION MAX_EXECUTION_TIME = 0' );
		return $pdo;
	}

	private function connect(): void {
		$this->source = $this->build_pdo( $this->connection_def( self::source_alias() ), false );
		$this->cache  = $this->build_pdo( $this->connection_def( self::cache_alias() ), true );
	}

	/* ───────────────────────── schema ───────────────────────── */

	private function ensure_schema(): void {
		$this->cache->exec(
			'CREATE TABLE IF NOT EXISTS `riding_cache` (
				`riding_number`   VARCHAR(8)    NOT NULL,
				`riding_slug`     VARCHAR(190)  NOT NULL,
				`riding_name_en`  VARCHAR(190)  NULL,
				`riding_name_fr`  VARCHAR(190)  NULL,
				`province`        VARCHAR(8)    NULL,
				`total_value`     DECIMAL(20,2) NULL,
				`agreement_count` BIGINT        NULL,
				`first_year`      SMALLINT      NULL,
				`last_year`       SMALLINT      NULL,
				`payload`         LONGTEXT      NOT NULL,
				`generated_at`    DATETIME      NOT NULL,
				PRIMARY KEY (`riding_number`),
				KEY `riding_slug` (`riding_slug`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
		);
		$this->cache->exec(
			'CREATE TABLE IF NOT EXISTS `cache_meta` (
				`meta_key`   VARCHAR(64) NOT NULL,
				`meta_value` LONGTEXT    NULL,
				`updated_at` DATETIME    NOT NULL,
				PRIMARY KEY (`meta_key`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
		);
	}

	/* ───────────────────────── lookups ───────────────────────── */

	/**
	 * Riding directory from the PCFRF: number → name + postal-code count.
	 *
	 * @return array<string,array{name_en:string,name_fr:string,postal_code_count:int}>
	 */
	private function load_directory(): array {
		$sql = "SELECT `riding_number` AS rn,
					MAX(`riding_name_en`) AS name_en,
					MAX(`riding_name_fr`) AS name_fr,
					COUNT(DISTINCT `postal_code`) AS pc_count
				FROM `pcbfr`
				WHERE `riding_number` IS NOT NULL AND `riding_number` <> ''
				  AND `ro_id` = " . self::PCFRF_RO_ID . "
				GROUP BY `riding_number`";
		$out = [];
		$stmt = $this->source->query( $sql );
		foreach ( $stmt as $row ) {
			$out[ (string) $row['rn'] ] = [
				'name_en'           => (string) ( $row['name_en'] ?? '' ),
				'name_fr'           => (string) ( $row['name_fr'] ?? '' ),
				'postal_code_count' => (int) ( $row['pc_count'] ?? 0 ),
			];
		}
		return $out;
	}

	/**
	 * recipient_type code → description.
	 *
	 * @return array<string,string>
	 */
	private function load_recipient_types(): array {
		$out = [];
		try {
			$stmt = $this->source->query( 'SELECT `recipient_type_code` AS c, `recipient_type_description` AS d FROM `recipient_types`' );
			foreach ( $stmt as $row ) {
				$out[ strtoupper( (string) $row['c'] ) ] = (string) $row['d'];
			}
		} catch ( \Throwable $e ) {
			$this->log( 'recipient_types unavailable, using built-in labels: ' . $e->getMessage(), 'warn' );
		}
		return $out;
	}

	/* ───────────────────────── SQL fragments ───────────────────────── */

	/** Normalised postal-code SQL (uppercase, no spaces or dashes). */
	private function pc_norm( string $expr ): string {
		return "UPPER(REPLACE(REPLACE({$expr}, ' ', ''), '-', ''))";
	}

	/** The PCFRF join clause attaching each source row to its riding(s). */
	private function pcfrf_join(): string {
		$src_pc = $this->pc_norm( '`m`.`recipient_postal_code`' );
		$dir_pc = $this->pc_norm( '`postal_code`' );
		return "JOIN (
					SELECT DISTINCT {$dir_pc} AS pc, `riding_number` AS rn
					FROM `pcbfr`
					WHERE `postal_code` IS NOT NULL AND `postal_code` <> ''
						AND `riding_number` IS NOT NULL AND `riding_number` <> ''
						AND `ro_id` = " . self::PCFRF_RO_ID . "
				) p ON p.pc = {$src_pc}";
	}

	/** Optional single-riding filter for trial runs. */
	private function only_clause( string $only_riding ): string {
		if ( '' === $only_riding ) {
			return '';
		}
		return ' AND p.rn = ' . $this->source->quote( $only_riding );
	}

	/* ───────────────────────── aggregate passes ───────────────────────── */

	/**
	 * All-time totals + 20 keyword sums/counts, grouped by riding.
	 *
	 * @param array<int,array{key:string,label:string,patterns:string[]}> $keywords
	 * @return array<string,array{main:array<string,mixed>,keywords:array<int,array<string,mixed>>}>
	 */
	private function aggregate_main( array $keywords, string $only_riding ): array {
		$join = $this->pcfrf_join();
		$only = $this->only_clause( $only_riding );

		// ── Query 1: per-riding main totals over ALL agreements (no keywords). ──
		$sql_main = "SELECT p.rn AS rn,
					COUNT(*) AS agreements,
					SUM(`m`.`agreement_value`) AS total,
					SUM(CASE WHEN `m`.`agreement_value` > 0 THEN `m`.`agreement_value` ELSE 0 END) AS positive_total,
					SUM(CASE WHEN `m`.`agreement_value` < 0 THEN `m`.`agreement_value` ELSE 0 END) AS negative_total,
					SUM(CASE WHEN `m`.`agreement_value` < 0 THEN 1 ELSE 0 END) AS negative_agreements,
					MIN(NULLIF(`m`.`year`, 0)) AS first_year,
					MAX(NULLIF(`m`.`year`, 0)) AS last_year,
					MAX(ABS(`m`.`agreement_value`)) AS max_value
				FROM `" . self::SOURCE_TABLE . "` m
				{$join}
				WHERE `m`.`recipient_postal_code` IS NOT NULL AND `m`.`recipient_postal_code` <> ''{$only}
				GROUP BY p.rn";

		$out = [];
		foreach ( $this->source->query( $sql_main ) as $row ) {
			$rn = (string) $row['rn'];
			$out[ $rn ] = [
				'main'     => [
					'agreements'          => (int) $row['agreements'],
					'total'               => (float) $row['total'],
					'positive_total'      => (float) $row['positive_total'],
					'negative_total'      => (float) $row['negative_total'],
					'negative_agreements' => (int) $row['negative_agreements'],
					'first_year'          => (int) $row['first_year'],
					'last_year'           => (int) $row['last_year'],
					'max_value'           => (float) $row['max_value'],
				],
				'keywords' => $this->empty_keyword_rows( $keywords ),
			];
		}

		// ── Query 2: per-riding keyword totals (HYBRID). ────────────────────────
		// FULLTEXT MATCH() in the WHERE fast-pre-filters to rows matching ANY
		// keyword at word level (uses index ft_riding_scan), then the exact
		// per-keyword LIKE CASE attributes spend to specific keywords. Only the
		// small candidate set is scanned, so this avoids the full-table LIKE pass.
		// ONE query PER keyword (not all keywords combined). A combined MATCH over
		// broad terms (e.g. "Indigenous" matches 200k+ rows) blows InnoDB's FTS
		// result cache (error 188: "FTS query exceeds result cache limit"), which
		// is a global limit we cannot raise on shared hosting. A single keyword's
		// match set stays well within the cache, so we loop. The $out keyword rows
		// were pre-seeded (zero-filled, in $keywords order) by empty_keyword_rows().
		if ( $keywords ) {
			$text_sql = pt_riding_keyword_text_sql( 'm' );
			$ft_cols  = pt_riding_keyword_fulltext_columns_sql( 'm' );

			foreach ( $keywords as $i => $kw ) {
				$ft_against = pt_riding_keyword_fulltext_against( [ $kw ] ); // this keyword only
				$like       = pt_riding_keyword_match_sql( $kw['patterns'], $text_sql );

				$sql_kw = "SELECT p.rn AS rn,
						SUM(`m`.`agreement_value`) AS kw_total,
						COUNT(*) AS kw_count
					FROM `" . self::SOURCE_TABLE . "` m
					{$join}
					WHERE `m`.`recipient_postal_code` IS NOT NULL AND `m`.`recipient_postal_code` <> ''{$only}
						AND {$ft_cols} AGAINST ({$ft_against} IN BOOLEAN MODE)
						AND {$like}
					GROUP BY p.rn";

				foreach ( $this->source->query( $sql_kw ) as $row ) {
					$rn = (string) $row['rn'];
					if ( ! isset( $out[ $rn ]['keywords'][ $i ] ) ) {
						continue;
					}
					$out[ $rn ]['keywords'][ $i ]['total']      = (float) $row['kw_total'];
					$out[ $rn ]['keywords'][ $i ]['agreements'] = (int) $row['kw_count'];
				}
			}
		}

		return $out;
	}

	/**
	 * Zero-filled keyword rows for ridings with no keyword matches.
	 *
	 * @param array<int,array{key:string,label:string,patterns:string[]}> $keywords
	 * @return array<int,array<string,mixed>>
	 */
	private function empty_keyword_rows( array $keywords ): array {
		$rows = [];
		foreach ( $keywords as $kw ) {
			$rows[] = [
				'key'        => $kw['key'],
				'label'      => $kw['label'],
				'total'      => 0.0,
				'agreements' => 0,
			];
		}
		return $rows;
	}

	/**
	 * Spending by year, grouped by riding then year.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function aggregate_years( string $only_riding ): array {
		$join = $this->pcfrf_join();
		$only = $this->only_clause( $only_riding );
		$sql  = "SELECT p.rn AS rn, `m`.`year` AS yr,
					SUM(`m`.`agreement_value`) AS total,
					SUM(CASE WHEN `m`.`agreement_value` > 0 THEN `m`.`agreement_value` ELSE 0 END) AS positive_total,
					SUM(CASE WHEN `m`.`agreement_value` < 0 THEN `m`.`agreement_value` ELSE 0 END) AS negative_total,
					SUM(CASE WHEN `m`.`agreement_value` > 0 THEN 1 ELSE 0 END) AS positive_agreements,
					SUM(CASE WHEN `m`.`agreement_value` < 0 THEN 1 ELSE 0 END) AS negative_agreements
				FROM `" . self::SOURCE_TABLE . "` m
				{$join}
				WHERE `m`.`year` IS NOT NULL AND `m`.`year` <> 0{$only}
				GROUP BY p.rn, `m`.`year`";

		$out  = [];
		$stmt = $this->source->query( $sql );
		foreach ( $stmt as $row ) {
			$rn = (string) $row['rn'];
			$out[ $rn ][] = [
				'year'                => (int) $row['yr'],
				'total'               => (float) $row['total'],
				'positive_total'      => (float) $row['positive_total'],
				'negative_total'      => (float) $row['negative_total'],
				'positive_agreements' => (int) $row['positive_agreements'],
				'negative_agreements' => (int) $row['negative_agreements'],
			];
		}
		return $out;
	}

	/**
	 * Spending by recipient type, grouped by riding then type code.
	 *
	 * @param array<string,string> $recipient_types
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function aggregate_recipient_types( array $recipient_types, string $only_riding ): array {
		$join = $this->pcfrf_join();
		$only = $this->only_clause( $only_riding );
		$sql  = "SELECT p.rn AS rn, `m`.`recipient_type` AS rt,
					SUM(`m`.`agreement_value`) AS total,
					COUNT(*) AS agreements
				FROM `" . self::SOURCE_TABLE . "` m
				{$join}
				WHERE 1=1{$only}
				GROUP BY p.rn, `m`.`recipient_type`";

		$out  = [];
		$stmt = $this->source->query( $sql );
		foreach ( $stmt as $row ) {
			$rn = (string) $row['rn'];
			$out[ $rn ][] = [
				'label'      => pt_recipient_type_label( $row['rt'] ?? '', $recipient_types ),
				'total'      => (float) $row['total'],
				'agreements' => (int) $row['agreements'],
			];
		}
		return $out;
	}

	/**
	 * Top 10 recipients per riding (streamed, ordered by total desc).
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function aggregate_recipients( string $only_riding ): array {
		$join      = $this->pcfrf_join();
		$only      = $this->only_clause( $only_riding );
		$recipient = "COALESCE(NULLIF(TRIM(`m`.`recipient_legal_name`), ''), NULLIF(TRIM(`m`.`recipient_operating_name`), ''))";
		$sql       = "SELECT p.rn AS rn,
					{$recipient} AS recipient,
					SUM(`m`.`agreement_value`) AS total,
					COUNT(*) AS agreements
				FROM `" . self::SOURCE_TABLE . "` m
				{$join}
				WHERE {$recipient} IS NOT NULL{$only}
				GROUP BY p.rn, recipient
				ORDER BY p.rn, total DESC";

		$stmt = $this->source->query( $sql );
		$rows = ( static function () use ( $stmt ) {
			foreach ( $stmt as $row ) {
				yield [
					'rn'         => (string) $row['rn'],
					'name'       => (string) $row['recipient'],
					'total'      => (float) $row['total'],
					'agreements' => (int) $row['agreements'],
				];
			}
		} )();
		return pt_collect_top_per_group( $rows, 'rn', 10 );
	}

	/**
	 * Top 10 programs per riding (streamed, ordered by agreement count desc).
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function aggregate_programs( string $only_riding ): array {
		$join = $this->pcfrf_join();
		$only = $this->only_clause( $only_riding );
		$sql  = "SELECT p.rn AS rn,
					`m`.`prog_name_en` AS prog,
					COUNT(*) AS agreements,
					SUM(`m`.`agreement_value`) AS total
				FROM `" . self::SOURCE_TABLE . "` m
				{$join}
				WHERE `m`.`prog_name_en` IS NOT NULL AND `m`.`prog_name_en` <> ''{$only}
				GROUP BY p.rn, `m`.`prog_name_en`
				ORDER BY p.rn, agreements DESC";

		$stmt = $this->source->query( $sql );
		$rows = ( static function () use ( $stmt ) {
			foreach ( $stmt as $row ) {
				yield [
					'rn'         => (string) $row['rn'],
					'name'       => (string) $row['prog'],
					'agreements' => (int) $row['agreements'],
					'total'      => (float) $row['total'],
				];
			}
		} )();
		return pt_collect_top_per_group( $rows, 'rn', 10 );
	}

	/* ───────────────────────── writes ───────────────────────── */

	private function upsert_riding( array $riding, array $payload ): void {
		$sql = 'INSERT INTO `riding_cache`
				(`riding_number`, `riding_slug`, `riding_name_en`, `riding_name_fr`, `province`,
				 `total_value`, `agreement_count`, `first_year`, `last_year`, `payload`, `generated_at`)
				VALUES (:rn, :slug, :name_en, :name_fr, :province, :total, :count, :first_year, :last_year, :payload, UTC_TIMESTAMP())
				ON DUPLICATE KEY UPDATE
					`riding_slug`     = VALUES(`riding_slug`),
					`riding_name_en`  = VALUES(`riding_name_en`),
					`riding_name_fr`  = VALUES(`riding_name_fr`),
					`province`        = VALUES(`province`),
					`total_value`     = VALUES(`total_value`),
					`agreement_count` = VALUES(`agreement_count`),
					`first_year`      = VALUES(`first_year`),
					`last_year`       = VALUES(`last_year`),
					`payload`         = VALUES(`payload`),
					`generated_at`    = VALUES(`generated_at`)';
		$stmt = $this->cache->prepare( $sql );
		$stmt->execute( [
			':rn'         => $riding['number'],
			':slug'       => $riding['slug'],
			':name_en'    => $riding['name_en'] ?: null,
			':name_fr'    => $riding['name_fr'] ?: null,
			':province'   => $riding['province'] ?: null,
			':total'      => $payload['totals']['total_value'],
			':count'      => $payload['totals']['agreement_count'],
			':first_year' => $payload['totals']['first_year'] ?: null,
			':last_year'  => $payload['totals']['last_year'] ?: null,
			':payload'    => wp_json_encode( $payload ),
		] );
	}

	/**
	 * Persist the riding directory + refresh metadata so the read path can
	 * resolve an MP's riding name → cache row without re-querying grantconts.
	 *
	 * @param array<string,array{name_en:string,name_fr:string,postal_code_count:int}> $directory
	 */
	private function write_meta( array $directory, string $generated_at, int $written ): void {
		$index = [];
		foreach ( $directory as $rn => $info ) {
			$name_en = (string) ( $info['name_en'] ?? '' );
			$slug    = pt_riding_slug( $name_en ?: (string) $rn ) ?: ( 'fed-' . $rn );
			$index[] = [
				'number'     => (string) $rn,
				'slug'       => $slug,
				'name_en'    => $name_en,
				'name_fr'    => (string) ( $info['name_fr'] ?? '' ),
				'normalized' => pt_normalize_riding_name( $name_en ),
				'province'   => pt_province_from_riding_number( (string) $rn ),
			];
		}
		$this->put_meta( 'riding_directory', wp_json_encode( $index ) );
		$this->put_meta( 'last_full_refresh', $generated_at );
		$this->put_meta( 'ridings_written', (string) $written );
		$this->put_meta( 'keyword_labels', wp_json_encode( array_map(
			static fn( $k ) => [ 'key' => $k['key'], 'label' => $k['label'] ],
			array_values( pt_riding_keywords() )
		) ) );
	}

	private function put_meta( string $key, string $value ): void {
		$stmt = $this->cache->prepare(
			'INSERT INTO `cache_meta` (`meta_key`, `meta_value`, `updated_at`)
				VALUES (:k, :v, UTC_TIMESTAMP())
				ON DUPLICATE KEY UPDATE `meta_value` = VALUES(`meta_value`), `updated_at` = VALUES(`updated_at`)'
		);
		$stmt->execute( [ ':k' => $key, ':v' => $value ] );
	}
}
