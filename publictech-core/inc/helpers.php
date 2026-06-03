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
			fputcsv( $out, array_keys( $row ), ',', '"', '\\' );
			$header_written = true;
		}
		// Coerce scalars; JSON-encode anything non-scalar so the cell stays
		// printable without breaking the line/field structure.
		$line = [];
		foreach ( $row as $value ) {
			$line[] = is_scalar( $value ) || null === $value ? (string) $value : wp_json_encode( $value );
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
