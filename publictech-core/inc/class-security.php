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
			} elseif ( 'LIKE' === $op && self::is_fulltext_column( $sql_col ) && is_string( $val )
				&& preg_match( '/^%([\p{L}\p{N}]{3,40})%$/u', $val, $w ) ) {
				// A one-word "%word%" search on a column with its own FULLTEXT index uses the index (milliseconds)
				// instead of scanning every row (about 7 s on master_table, over the 8 s query limit). Matches whole
				// words and word beginnings rather than any substring.
				$frags[]  = "MATCH({$sql_col}) AGAINST (? IN BOOLEAN MODE)";
				$params[] = '+' . $w[1] . '*';
			} else {
				$frags[]  = "{$sql_col} {$op} ?";
				$params[] = $val;
			}
		}

		$sql = $frags ? ( 'WHERE ' . implode( ' AND ', $frags ) ) : '';
		return [ $sql, $params ];
	}

	/**
	 * master_table columns that each have their own single-column FULLTEXT index (grantconts; see SHOW INDEX).
	 * Filterable: `publictech_fulltext_columns` receives bare column names.
	 */
	private static function is_fulltext_column( string $sql_col ): bool {
		$cols = (array) apply_filters( 'publictech_fulltext_columns', [
			'description_eng', 'description_fr', 'expected_results_en', 'expected_results_fr',
			'additional_information_en', 'additional_information_fr', 'coverage',
		] );
		// Only master_table carries these indexes; agreements and the views have the same column names without them.
		if ( ! preg_match( '/^`?master_table`?\.`?([a-z_]+)`?$/', $sql_col, $m ) ) {
			return false;
		}
		return in_array( $m[1], $cols, true );
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
		// Anonymous visitors also receive a wp_rest nonce, so it only authenticates a signed-in user:
		// the data API (stream, export, query, AI) requires an account or an API token.
		if ( $nonce && is_user_logged_in() && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
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
