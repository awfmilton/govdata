<?php
/**
 * Cache-database gateway (govdata_cache_01).
 *
 * This is the "second database" in the architecture. Unlike the read-only
 * source pool in {@see Multi_DB} — which routes every statement through
 * Security::assert_read_only() and therefore forbids CALL — this gateway is
 * the single, deliberate place allowed to invoke the cache stored procedures
 * (sp_upsert_entity_snapshot / sp_get_entity_snapshot /
 * sp_search_entity_agreements / sp_list_entities). It never runs caller-supplied
 * SQL; only those four fixed procedures, always with bound parameters.
 *
 * Credentials are read from wp-config.php (or the environment) and are NEVER
 * committed to the repository:
 *
 *   define( 'GOVDATA_CACHE_DB1', [
 *       'host' => 'mysql.govdata.ca',
 *       'port' => 3306,
 *       'name' => 'govdata_cache_01',
 *       'user' => 'gdc_01',
 *       'pass' => '…',          // the shared dev password lives here, not in git
 *   ] );
 *
 * Optionally define GOVDATA_CACHE_DB1_WRITER with the same shape to give the
 * refresh/upsert path a higher-privilege account than the public search path
 * (recommended for production; in dev both default to the same user).
 *
 * Falls back to GOVDATA_CACHE_DB1_HOST / _PORT / _NAME / _USER / _PASS env vars
 * so the CLI refresh script can run without editing wp-config.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Cache_DB {
	private static ?self $instance = null;

	private ?\PDO $reader = null;
	private ?\PDO $writer = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	/**
	 * Resolve a connection config array for the given role.
	 *
	 * @param string $role 'reader' (public search/render) or 'writer' (refresh).
	 * @return array{host:string,port:int,name:string,user:string,pass:string}|null
	 */
	private function config( string $role = 'reader' ): ?array {
		// Writer role prefers its own definition, then falls back to the reader.
		if ( 'writer' === $role && defined( 'GOVDATA_CACHE_DB1_WRITER' ) && is_array( GOVDATA_CACHE_DB1_WRITER ) ) {
			$raw = GOVDATA_CACHE_DB1_WRITER;
		} elseif ( defined( 'GOVDATA_CACHE_DB1' ) && is_array( GOVDATA_CACHE_DB1 ) ) {
			$raw = GOVDATA_CACHE_DB1;
		} else {
			$raw = [
				'host' => getenv( 'GOVDATA_CACHE_DB1_HOST' ) ?: '',
				'port' => getenv( 'GOVDATA_CACHE_DB1_PORT' ) ?: 3306,
				'name' => getenv( 'GOVDATA_CACHE_DB1_NAME' ) ?: '',
				'user' => getenv( 'GOVDATA_CACHE_DB1_USER' ) ?: '',
				'pass' => getenv( 'GOVDATA_CACHE_DB1_PASS' ),
			];
		}

		if ( empty( $raw['host'] ) || empty( $raw['name'] ) || empty( $raw['user'] ) ) {
			return null;
		}

		return [
			'host' => (string) $raw['host'],
			'port' => (int) ( $raw['port'] ?? 3306 ),
			'name' => (string) $raw['name'],
			'user' => (string) $raw['user'],
			'pass' => (string) ( $raw['pass'] ?? '' ),
		];
	}

	/**
	 * True when the cache connection is configured. Templates use this to decide
	 * between rendering data and showing the "awaiting first sync" state.
	 */
	public function available(): bool {
		return null !== $this->config( 'reader' );
	}

	/**
	 * Build (and memoise) the PDO handle for a role.
	 *
	 * @throws \RuntimeException When the role is not configured.
	 */
	private function pdo( string $role = 'reader' ): \PDO {
		if ( 'writer' === $role && $this->writer instanceof \PDO ) {
			return $this->writer;
		}
		if ( 'reader' === $role && $this->reader instanceof \PDO ) {
			return $this->reader;
		}

		$cfg = $this->config( $role );
		if ( null === $cfg ) {
			throw new \RuntimeException( 'Cache database is not configured.' );
		}

		$dsn = sprintf(
			'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
			$cfg['host'],
			$cfg['port'],
			$cfg['name']
		);
		$pdo = new \PDO( $dsn, $cfg['user'], $cfg['pass'], [
			\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
			\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
			\PDO::ATTR_EMULATE_PREPARES   => false,
			// Persistent connections + CALL can leave dangling result sets between
			// requests; keep cache connections per-request to stay predictable.
			\PDO::ATTR_PERSISTENT         => false,
			\PDO::ATTR_TIMEOUT            => 5,
		] );

		// Match the collation used by entity_snapshots / entity_agreements tables so
		// stored-procedure parameter comparisons don't fail with collation mismatch errors.
		$pdo->exec( "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'" );

		if ( 'writer' === $role ) {
			$this->writer = $pdo;
		} else {
			$this->reader = $pdo;
		}
		return $pdo;
	}

	/**
	 * Stored procedures return at least one trailing empty result set. Drain
	 * them so the connection is clean for the next statement.
	 */
	private function drain( \PDOStatement $stmt ): void {
		do {
			$stmt->closeCursor();
		} while ( $stmt->nextRowset() );
	}

	/**
	 * Upsert one entity snapshot via sp_upsert_entity_snapshot. The scalar
	 * roll-ups are computed by the caller (the refresh job); the agreements
	 * array inside $snapshot is expanded server-side into entity_agreements.
	 *
	 * @param array<string,mixed> $snapshot Full snapshot payload (see Entity_Reports::build_snapshot()).
	 * @return bool True on success.
	 */
	public function upsert_snapshot( string $slug, string $name, string $keywords, string $source, array $snapshot ): bool {
		$totals = is_array( $snapshot['totals'] ?? null ) ? $snapshot['totals'] : [];
		try {
			$stmt = $this->pdo( 'writer' )->prepare(
				'CALL sp_upsert_entity_snapshot(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
			);
			$stmt->execute( [
				$slug,
				$name,
				$keywords,
				$source,
				(float) ( $totals['total_funding']    ?? 0 ),
				(float) ( $totals['positive_funding'] ?? 0 ),
				(float) ( $totals['negative_funding'] ?? 0 ),
				(int) ( $totals['agreement_count']     ?? 0 ),
				isset( $totals['first_year'] ) ? (int) $totals['first_year'] : null,
				isset( $totals['last_year'] ) ? (int) $totals['last_year'] : null,
				(string) wp_json_encode( $snapshot ),
			] );
			$this->drain( $stmt );
			return true;
		} catch ( \Throwable $e ) {
			error_log( "[PublicTech] Cache upsert failed for {$slug}: " . $e->getMessage() );
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				\WP_CLI::warning( "Upsert failed: " . $e->getMessage() );
			}
			return false;
		}
	}

	/**
	 * Fetch a cached snapshot. Returns the decoded payload plus metadata, or
	 * null when the entity has not been synced (or the DB is unreachable).
	 *
	 * @return array{name:string,total:float,count:int,updated_at:string,payload:array<string,mixed>}|null
	 */
	public function get_snapshot( string $slug ): ?array {
		try {
			$stmt = $this->pdo( 'reader' )->prepare( 'CALL sp_get_entity_snapshot(?)' );
			$stmt->execute( [ $slug ] );
			$row = $stmt->fetch( \PDO::FETCH_ASSOC );
			$this->drain( $stmt );
		} catch ( \Throwable $e ) {
			error_log( '[PublicTech] cache get_snapshot failed for ' . $slug . ': ' . $e->getMessage() );
			return null;
		}
		if ( ! $row ) {
			return null;
		}
		$payload = json_decode( (string) ( $row['snapshot_json'] ?? '' ), true );
		return [
			'name'       => (string) ( $row['entity_name'] ?? '' ),
			'total'      => (float) ( $row['total_funding'] ?? 0 ),
			'count'      => (int) ( $row['agreement_count'] ?? 0 ),
			'updated_at' => (string) ( $row['updated_at'] ?? '' ),
			'payload'    => is_array( $payload ) ? $payload : [],
		];
	}

	/**
	 * Live agreement search scoped to one entity (the in-page search box). This
	 * is the path that hits the cache database on demand with the public user.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function search_agreements( string $slug, string $query, int $limit = 50 ): array {
		$limit = max( 1, min( 500, $limit ) );
		try {
			$stmt = $this->pdo( 'reader' )->prepare( 'CALL sp_search_entity_agreements(?, ?, ?)' );
			$stmt->bindValue( 1, $slug, \PDO::PARAM_STR );
			$stmt->bindValue( 2, $query, \PDO::PARAM_STR );
			$stmt->bindValue( 3, $limit, \PDO::PARAM_INT );
			$stmt->execute();
			$rows = $stmt->fetchAll( \PDO::FETCH_ASSOC ) ?: [];
			$this->drain( $stmt );
			return $rows;
		} catch ( \Throwable $e ) {
			error_log( '[PublicTech] cache search failed for ' . $slug . ': ' . $e->getMessage() );
			return [];
		}
	}

	/**
	 * Directory listing of every cached entity, largest funding first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_entities(): array {
		try {
			$stmt = $this->pdo( 'reader' )->prepare( 'CALL sp_list_entities()' );
			$stmt->execute();
			$rows = $stmt->fetchAll( \PDO::FETCH_ASSOC ) ?: [];
			$this->drain( $stmt );
			return $rows;
		} catch ( \Throwable $e ) {
			error_log( '[PublicTech] cache list_entities failed: ' . $e->getMessage() );
			return [];
		}
	}
}
