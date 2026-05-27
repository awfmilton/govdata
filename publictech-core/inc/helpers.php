<?php
/**
 * Stateless helpers used across the engine.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Convert an arbitrary identifier into a safe MySQL identifier fragment.
 * Used when callers pass schema/table/column names that must be interpolated
 * into a prepared statement (PDO cannot bind identifiers).
 */
function safe_ident( string $ident ): string {
	if ( ! preg_match( '/^[A-Za-z0-9_]{1,64}$/', $ident ) ) {
		throw new \InvalidArgumentException( 'Unsafe identifier: ' . esc_html( $ident ) );
	}
	return '`' . $ident . '`';
}

/**
 * Stream a (possibly very large) PDOStatement to the client as a JSON array
 * without buffering the entire result set in memory.
 */
function stream_json_rows( \PDOStatement $stmt ): void {
	if ( ! headers_sent() ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Accel-Buffering: no' );
	}
	echo '[';
	$first = true;
	while ( $row = $stmt->fetch( \PDO::FETCH_ASSOC ) ) {
		echo $first ? '' : ',';
		echo wp_json_encode( $row );
		$first = false;
		if ( connection_aborted() ) {
			break;
		}
	}
	echo ']';
}

/**
 * Hash an IP + user-id pair for rate-limit bucket keys. Avoids storing raw IPs.
 */
function bucket_key( ?int $user_id, string $ip, string $tier ): string {
	return hash( 'sha256', ( $user_id ?? 0 ) . '|' . $ip . '|' . $tier . '|' . wp_salt( 'auth' ) );
}
