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
		// Log the offending value server-side; keep the thrown/returned message
		// generic so a rejected identifier never echoes back to a caller.
		error_log( '[PublicTech] Rejected unsafe SQL identifier: ' . $ident );
		throw new \InvalidArgumentException( 'Unsafe identifier.' );
	}
	return '`' . $ident . '`';
}

/**
 * Neutralise spreadsheet formula injection in a CSV cell. Excel/Sheets treat a
 * leading =, +, -, @, tab, or CR as the start of a formula, so a government
 * recipient/agreement name like `=HYPERLINK(...)` would execute on open. We
 * prefix such values with a single quote (the OWASP-recommended escape), which
 * spreadsheet apps strip on display but which keeps the value text-only.
 */
function csv_safe_cell( string $value ): string {
	if ( '' === $value ) {
		return $value;
	}
	// Leave well-formed numbers (including negative/decimal amounts) untouched
	// so spending values export as numbers, not text — `-480.00` is data, not
	// a formula. Anything else with a formula-trigger lead char gets quoted.
	if ( preg_match( '/^-?\d+(?:\.\d+)?$/', $value ) ) {
		return $value;
	}
	if ( preg_match( '/^[=+\-@\t\r]/', $value ) ) {
		return "'" . $value;
	}
	return $value;
}

/**
 * Stream a (possibly very large) PDOStatement to the client as a JSON array
 * without buffering the entire result set in memory.
 */
function stream_json_rows( \PDOStatement $stmt ): void {
	if ( ! headers_sent() ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Accel-Buffering: no' );
		// Per-user tier-restricted data must never end up in a shared cache.
		header( 'Cache-Control: private, no-store' );
		header( 'Vary: Authorization, Cookie' );
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
 * Stream a PDOStatement to the client as RFC 4180 CSV (UTF-8 with a BOM so
 * Excel opens it sanely). Writes the header row from the first row's keys,
 * then one row per fetch. Memory footprint is bounded — never buffers more
 * than one row + the output stream's internal buffer.
 *
 * @param string|null $filename Optional filename for the Content-Disposition header.
 */
function stream_csv_rows( \PDOStatement $stmt, ?string $filename = null ): void {
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'X-Accel-Buffering: no' );
		header( 'Cache-Control: private, no-store' );
		header( 'Vary: Authorization, Cookie' );
		if ( $filename ) {
			$safe = preg_replace( '/[^A-Za-z0-9._-]+/', '_', $filename ) ?: 'export.csv';
			header( 'Content-Disposition: attachment; filename="' . $safe . '"' );
		}
	}
	$out = fopen( 'php://output', 'wb' );
	if ( false === $out ) {
		return;
	}
	// UTF-8 BOM so Excel auto-detects encoding.
	fwrite( $out, "\xEF\xBB\xBF" );

	$header_written = false;
	while ( $row = $stmt->fetch( \PDO::FETCH_ASSOC ) ) {
		if ( ! $header_written ) {
			fputcsv( $out, array_map( __NAMESPACE__ . '\\csv_safe_cell', array_map( 'strval', array_keys( $row ) ) ), ',', '"', '\\' );
			$header_written = true;
		}
		// Coerce scalars; JSON-encode anything non-scalar so the cell stays
		// printable without breaking the line/field structure. Every cell is
		// run through csv_safe_cell() to defuse spreadsheet formula injection.
		$line = [];
		foreach ( $row as $value ) {
			$cell   = is_scalar( $value ) || null === $value ? (string) $value : (string) wp_json_encode( $value );
			$line[] = csv_safe_cell( $cell );
		}
		fputcsv( $out, $line, ',', '"', '\\' );
		if ( connection_aborted() ) {
			break;
		}
	}
	fclose( $out );
}

/**
 * Hash an IP + user-id pair for rate-limit bucket keys. Avoids storing raw IPs.
 */
function bucket_key( ?int $user_id, string $ip, string $tier ): string {
	return hash( 'sha256', ( $user_id ?? 0 ) . '|' . $ip . '|' . $tier . '|' . wp_salt( 'auth' ) );
}

/**
 * Map a HoC fiscal-year string + quarter name to calendar start/end month labels.
 *
 * The HoC fiscal year runs April–March.  Quarter names as stored in the DB:
 *   first-quarter  = April–June     (start year of FY)
 *   second-quarter = July–September (start year of FY)
 *   third-quarter  = October–December (start year of FY)
 *   fourth-quarter = January–March  (end year of FY)
 *
 * @param  string $fiscal_year  e.g. '2025-2026'
 * @param  string $quarter_name e.g. 'third-quarter'
 * @return array{start:string, end:string} e.g. ['start'=>'October 2025','end'=>'December 2025']
 */
function pt_finance_quarter_label( string $fiscal_year, string $quarter_name ): array {
	$fy_start = (int) substr( $fiscal_year, 0, 4 );
	switch ( $quarter_name ) {
		case 'first-quarter':  return [ 'start' => "April {$fy_start}",              'end' => "June {$fy_start}" ];
		case 'second-quarter': return [ 'start' => "July {$fy_start}",               'end' => "September {$fy_start}" ];
		case 'third-quarter':  return [ 'start' => "October {$fy_start}",            'end' => "December {$fy_start}" ];
		case 'fourth-quarter': return [ 'start' => 'January ' . ( $fy_start + 1 ),   'end' => 'March ' . ( $fy_start + 1 ) ];
		default:               return [ 'start' => '', 'end' => '' ];
	}
}

/**
 * The configured list of trusted reverse-proxy IPs / CIDR ranges. Empty by
 * default — meaning forwarded headers are NOT trusted and client_ip() returns
 * REMOTE_ADDR. Operators behind Cloudflare/an ALB/Nginx add their proxy ranges
 * here (constant or filter) so the real client IP can be recovered safely.
 *
 *   define( 'PUBLICTECH_TRUSTED_PROXIES', '173.245.48.0/20, 2400:cb00::/32' );
 *   add_filter( 'publictech_trusted_proxies', fn() => [ '10.0.0.0/8' ] );
 *
 * @return string[]
 */
function trusted_proxies(): array {
	$list = defined( 'PUBLICTECH_TRUSTED_PROXIES' ) ? PUBLICTECH_TRUSTED_PROXIES : [];
	if ( is_string( $list ) ) {
		$list = array_filter( array_map( 'trim', explode( ',', $list ) ) );
	}
	$list = apply_filters( 'publictech_trusted_proxies', (array) $list );
	return array_values( array_filter( array_map( 'strval', (array) $list ) ) );
}

/**
 * Test whether $ip falls inside a single IPv4/IPv6 CIDR (or exact address).
 * Binary-safe for both families; returns false on malformed input.
 */
function ip_in_cidr( string $ip, string $cidr ): bool {
	$ip_bin = @inet_pton( $ip );
	if ( false === $ip_bin ) {
		return false;
	}
	if ( false === strpos( $cidr, '/' ) ) {
		$net_bin = @inet_pton( $cidr );
		return false !== $net_bin && hash_equals( $net_bin, $ip_bin );
	}
	[ $subnet, $bits ] = explode( '/', $cidr, 2 );
	$net_bin = @inet_pton( $subnet );
	$bits    = (int) $bits;
	if ( false === $net_bin || strlen( $net_bin ) !== strlen( $ip_bin ) || $bits < 0 || $bits > strlen( $ip_bin ) * 8 ) {
		return false;
	}
	$whole = intdiv( $bits, 8 );
	$rem   = $bits % 8;
	if ( $whole > 0 && 0 !== strncmp( $ip_bin, $net_bin, $whole ) ) {
		return false;
	}
	if ( 0 === $rem ) {
		return true;
	}
	$mask = chr( 0xFF << ( 8 - $rem ) & 0xFF );
	return ( ord( $ip_bin[ $whole ] ) & ord( $mask ) ) === ( ord( $net_bin[ $whole ] ) & ord( $mask ) );
}

/** Is the given IP one of our configured trusted proxies? */
function is_trusted_proxy( string $ip ): bool {
	foreach ( trusted_proxies() as $cidr ) {
		if ( '' !== $cidr && ip_in_cidr( $ip, $cidr ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Resolve the real client IP without trusting spoofable headers.
 *
 * Returns REMOTE_ADDR unless the immediate peer is a configured trusted proxy,
 * in which case we honour CF-Connecting-IP (single value) or the right-most
 * non-trusted hop of X-Forwarded-For. With no trusted proxies configured (the
 * default) forwarded headers are ignored entirely, so an anonymous caller can
 * no longer mint a fresh rate-limit bucket per request by spoofing XFF.
 */
function client_ip(): string {
	$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
		$remote = '0.0.0.0';
	}

	// Only consult forwarded headers when the connecting peer is trusted.
	if ( '0.0.0.0' === $remote || ! is_trusted_proxy( $remote ) ) {
		return $remote;
	}

	// Cloudflare passes a single, already-resolved client IP.
	if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$cf = trim( (string) wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
			return $cf;
		}
	}

	// X-Forwarded-For: walk right→left and return the first hop that is NOT
	// itself a trusted proxy (the closest untrusted client).
	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$parts = array_map( 'trim', explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
		for ( $i = count( $parts ) - 1; $i >= 0; $i-- ) {
			$cand = $parts[ $i ];
			if ( filter_var( $cand, FILTER_VALIDATE_IP ) && ! is_trusted_proxy( $cand ) ) {
				return $cand;
			}
		}
	}

	return $remote;
}
