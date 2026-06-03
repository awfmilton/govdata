<?php
/**
 * Multi-database PDO connection manager.
 *
 * Reads connection definitions from the PUBLICTECH_DB_CONNECTIONS constant
 * (defined in wp-config.php) and lazily instantiates PDO handles per alias.
 * Connections are persistent, use UTF-8, and are restricted to read-only
 * users at the MySQL grant level — the Security helper enforces a second
 * layer of write-statement rejection at the PHP boundary.
 *
 * Example wp-config.php:
 *
 *   define( 'PUBLICTECH_DB_CONNECTIONS', [
 *     'pt-finance-01'  => [ 'host' => 'finance.db.internal',  'port' => 3306, 'name' => 'finance',  'user' => 'pt_read', 'pass' => '…' ],
 *     'pt-grants-02'   => [ 'host' => 'grants.db.internal',   'port' => 3306, 'name' => 'grants',   'user' => 'pt_read', 'pass' => '…' ],
 *     'pt-contracts-03'=> [ 'host' => 'contracts.db.internal','port' => 3306, 'name' => 'contracts','user' => 'pt_read', 'pass' => '…' ],
 *     'pt-registry-04' => [ 'host' => 'registry.db.internal', 'port' => 3306, 'name' => 'registry', 'user' => 'pt_read', 'pass' => '…' ],
 *   ] );
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Multi_DB {
	private static ?self $instance = null;
	/** @var array<string,Connection> */
	private array $pool = [];

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	/**
	 * Resolve the full connection map: the PUBLICTECH_DB_CONNECTIONS constant
	 * (when defined) merged through the `publictech_db_connections` filter so
	 * mu-plugins can register additional aliases (e.g. the riding cache DB)
	 * without having to be the sole definer of the constant.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function connections(): array {
		$defs = defined( 'PUBLICTECH_DB_CONNECTIONS' ) ? PUBLICTECH_DB_CONNECTIONS : [];
		$defs = apply_filters( 'publictech_db_connections', $defs );
		return is_array( $defs ) ? $defs : [];
	}

	/**
	 * Return (and lazily build) a Connection for the given alias.
	 */
	public function connection( string $alias ): Connection {
		if ( isset( $this->pool[ $alias ] ) ) {
			return $this->pool[ $alias ];
		}
		$defs = self::connections();
		if ( ! isset( $defs[ $alias ] ) ) {
			throw new \RuntimeException( "Unknown PublicTech DB alias: {$alias}" );
		}
		$this->pool[ $alias ] = new Connection( $alias, $defs[ $alias ] );
		return $this->pool[ $alias ];
	}

	/** @return string[] */
	public function aliases(): array {
		return array_keys( self::connections() );
	}

	/**
	 * Lightweight per-alias health check used by the status rail.
	 *
	 * Cached for 30 s in the object cache to avoid running 4+ round-trip
	 * SELECTs on every page render. The cache is keyed by the alias list
	 * hash so re-configuring connections invalidates it immediately.
	 *
	 * @return array<string,array{ok:bool,latency_ms:?float,error:?string}>
	 */
	public function health(): array {
		$cache_key = 'pt_health_' . substr( md5( implode( '|', $this->aliases() ) ), 0, 12 );
		$cached    = wp_cache_get( $cache_key, 'publictech' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$out = [];
		foreach ( $this->aliases() as $alias ) {
			$t0 = microtime( true );
			try {
				$this->connection( $alias )->pdo()->query( 'SELECT 1' )->fetchColumn();
				$out[ $alias ] = [ 'ok' => true, 'latency_ms' => round( ( microtime( true ) - $t0 ) * 1000, 1 ), 'error' => null ];
			} catch ( \Throwable $e ) {
				// Don't leak DB error messages or DSN fragments to callers.
				$out[ $alias ] = [ 'ok' => false, 'latency_ms' => null, 'error' => 'connection_failed' ];
			}
		}
		wp_cache_set( $cache_key, $out, 'publictech', 30 );
		return $out;
	}
}

/**
 * Thin wrapper around PDO that exposes only the verbs we want callers using.
 */
final class Connection {
	private ?\PDO $pdo = null;

	/**
	 * @param array{host:string,port?:int,name:string,user:string,pass:string,charset?:string} $cfg
	 */
	public function __construct( private readonly string $alias, private readonly array $cfg ) {}

	public function alias(): string {
		return $this->alias;
	}

	public function pdo(): \PDO {
		if ( $this->pdo ) {
			return $this->pdo;
		}
		$dsn = sprintf(
			'mysql:host=%s;port=%d;dbname=%s;charset=%s',
			$this->cfg['host'],
			(int) ( $this->cfg['port'] ?? 3306 ),
			$this->cfg['name'],
			$this->cfg['charset'] ?? 'utf8mb4'
		);
		$connect_timeout = (int) ( $this->cfg['connect_timeout'] ?? 5 );
		$query_timeout   = (int) ( $this->cfg['query_timeout']   ?? 8000 ); // milliseconds for MAX_EXECUTION_TIME
		$this->pdo = new \PDO( $dsn, $this->cfg['user'], $this->cfg['pass'], [
			\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
			\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
			\PDO::ATTR_EMULATE_PREPARES   => false,
			\PDO::ATTR_PERSISTENT         => true,
			\PDO::ATTR_TIMEOUT            => $connect_timeout,
			\PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone='+00:00', SESSION MAX_EXECUTION_TIME={$query_timeout}",
		] );
		return $this->pdo;
	}

	/**
	 * Run a SELECT and return all rows. For very large result sets, use stream().
	 *
	 * @param array<int|string,mixed> $params
	 * @return array<int,array<string,mixed>>
	 */
	public function select( string $sql, array $params = [] ): array {
		Security::assert_read_only( $sql );
		$stmt = $this->pdo()->prepare( $sql );
		$stmt->execute( $params );
		return $stmt->fetchAll();
	}

	/**
	 * Execute a SELECT and return the underlying PDOStatement for streaming.
	 *
	 * @param array<int|string,mixed> $params
	 */
	public function stream( string $sql, array $params = [] ): \PDOStatement {
		Security::assert_read_only( $sql );
		$stmt = $this->pdo()->prepare( $sql );
		$stmt->execute( $params );
		return $stmt;
	}

	/**
	 * Convenience: SELECT … WHERE … built from a structured filter array.
	 *
	 * @param array<int,array{col:string,op:string,val:mixed}> $filters
	 * @param array<string,string>                            $columns Whitelist alias=>qualified column.
	 */
	public function find(
		string $table,
		array $filters,
		array $columns,
		?string $order_by = null,
		int $limit = 100,
		int $offset = 0
	): \PDOStatement {
		[ $where, $params ] = Security::build_where( $filters, $columns );

		$order = '';
		if ( $order_by ) {
			[ $col, $dir ] = array_pad( explode( ' ', $order_by, 2 ), 2, 'ASC' );
			if ( isset( $columns[ $col ] ) ) {
				$dir   = strtoupper( $dir ) === 'DESC' ? 'DESC' : 'ASC';
				$order = "ORDER BY {$columns[$col]} {$dir}";
			}
		}

		// Select only the allowlisted columns (by their qualified `tbl`.`col`
		// expressions, which are operator-defined — not user input). This keeps
		// the row keys identical to the old SELECT * for exposed columns while
		// no longer shipping or exposing non-allowlisted columns, and trims the
		// row width over the wire on million-row streams.
		$select = $columns ? implode( ', ', array_values( $columns ) ) : '*';

		$sql = sprintf(
			'SELECT %s FROM %s %s %s LIMIT %d OFFSET %d',
			$select,
			safe_ident( $table ),
			$where,
			$order,
			max( 1, min( 10000, $limit ) ),
			max( 0, $offset )
		);
		return $this->stream( $sql, $params );
	}
}
