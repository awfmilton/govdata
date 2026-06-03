<?php
/**
 * Centralised security helpers — escaping wrappers, nonce verification,
 * WHERE-clause builder that whitelists columns and operators before they
 * ever reach a prepared statement.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Security {
	/** Operators we will accept from untrusted input. */
	private const ALLOWED_OPS = [ '=', '!=', '<', '<=', '>', '>=', 'LIKE', 'IN', 'BETWEEN', 'IS NULL', 'IS NOT NULL' ];

	/**
	 * Build a parameterised WHERE clause from a structured filter spec.
	 *
	 * @param array<int,array{col:string,op:string,val:mixed}> $filters
	 * @param array<string,string>                            $columns Whitelist: column-alias => fully qualified `table`.`col`.
	 *
	 * @return array{0:string,1:array<int,mixed>} [sql, bound params]
	 */
	public static function build_where( array $filters, array $columns ): array {
		$frags  = [];
		$params = [];

		foreach ( $filters as $f ) {
			$col = $f['col'] ?? '';
			$op  = strtoupper( trim( (string) ( $f['op'] ?? '=' ) ) );
			$val = $f['val'] ?? null;

			if ( ! isset( $columns[ $col ] ) || ! in_array( $op, self::ALLOWED_OPS, true ) ) {
				continue;
			}
			$sql_col = $columns[ $col ];

			if ( 'IN' === $op && is_array( $val ) && $val ) {
				$placeholders = implode( ',', array_fill( 0, count( $val ), '?' ) );
				$frags[]      = "{$sql_col} IN ({$placeholders})";
				array_push( $params, ...array_values( $val ) );
			} elseif ( 'BETWEEN' === $op && is_array( $val ) && 2 === count( $val ) ) {
				$frags[]  = "{$sql_col} BETWEEN ? AND ?";
				$params[] = $val[0];
				$params[] = $val[1];
			} elseif ( 'IS NULL' === $op || 'IS NOT NULL' === $op ) {
				$frags[] = "{$sql_col} {$op}";
			} else {
				$frags[]  = "{$sql_col} {$op} ?";
				$params[] = $val;
			}
		}

		$sql = $frags ? ( 'WHERE ' . implode( ' AND ', $frags ) ) : '';
		return [ $sql, $params ];
	}

	/**
	 * Reject any query that mutates state. The MCP server and front-end REST
	 * routes pipe arbitrary text through this before executing.
	 */
	public static function assert_read_only( string $sql ): void {
		// 1) Strip comments. 2) Strip single- and double-quoted string literals
		// so a SELECT that mentions "DELETE FROM" as a string isn't flagged.
		// MySQL backtick-quoted identifiers are left alone since they can't
		// contain SQL verbs that we'd misinterpret.
		$stripped = preg_replace(
			[ '#/\*.*?\*/|--[^\n]*#s', "#'(?:''|\\\\.|[^'\\\\])*'#s", '#"(?:""|\\\\.|[^"\\\\])*"#s' ],
			'',
			$sql
		) ?? '';
		if ( preg_match( '/\b(INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|CREATE|TRUNCATE|GRANT|REVOKE|RENAME|CALL|LOAD\s+DATA|HANDLER|LOCK\s+TABLES)\b/i', $stripped ) ) {
			throw new \RuntimeException( 'Write operations are not permitted on this connection.' );
		}
		// File-access vectors a plain SELECT can still reach (SELECT … INTO
		// OUTFILE/DUMPFILE, LOAD_FILE()). Blocked here as defence-in-depth on
		// top of the SELECT-only MySQL grant (which should also lack FILE priv).
		if ( preg_match( '/\bINTO\s+(OUTFILE|DUMPFILE)\b|\bLOAD_FILE\s*\(/i', $stripped ) ) {
			throw new \RuntimeException( 'File access is not permitted on this connection.' );
		}
	}

	/**
	 * Verify the REST nonce or fall back to the MCP bearer token. Returns true
	 * if either is valid for the active user/request context.
	 */
	public static function verify_request( \WP_REST_Request $req ): bool {
		$nonce = $req->get_header( 'x-wp-nonce' );
		if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}
		$auth = $req->get_header( 'authorization' );
		if ( $auth && stripos( $auth, 'Bearer ' ) === 0 ) {
			$token = substr( $auth, 7 );
			return (bool) Access_Tiers::instance()->resolve_token( $token );
		}
		return false;
	}
}
