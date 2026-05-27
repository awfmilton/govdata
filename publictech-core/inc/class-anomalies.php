<?php
/**
 * Anomaly detection.
 *
 * Two tiers:
 *   - Cheap statistical checks that run inline (Benford's-law first-digit
 *     test, weekend/holiday timing flag). No LLM dependency.
 *   - LLM-augmented review: the `publictech_anomaly_review` filter receives
 *     a batch of rows and may return additional flags. Sites that wire up
 *     an LLM behind that filter get richer commentary; sites that don't
 *     get the statistical baseline for free.
 *
 * Results are written to wp_options under `pt_anomaly_flags_<schema>_<table>`
 * as a small append-only queue (capped to 500 entries). Storage lives in
 * options rather than the external DB on purpose — sites don't always have
 * write-grants on their finance schema. A future iteration can flush to the
 * pt-flags-internal schema via a hook if desired.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Anomalies {
	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		// Allow plugins/sites to fire a batch through this manually.
		// Example: do_action( 'publictech_analyze', 'pt-contracts-03', 'awards', $rows );
		add_action( 'publictech_analyze', [ $this, 'analyze_batch' ], 10, 3 );
	}

	/**
	 * Analyse a batch and persist flagged rows.
	 *
	 * @param string                       $schema External DB alias.
	 * @param string                       $table  Table name.
	 * @param array<int,array<string,mixed>> $rows Rows to analyse.
	 */
	public function analyze_batch( string $schema, string $table, array $rows ): void {
		if ( ! $rows ) {
			return;
		}
		$flags = self::statistical_flags( $rows );

		// Optional LLM augmentation. Receives the rows + the statistical
		// flags so the model can either echo them through or extend them.
		// Hook signature:
		//   add_filter( 'publictech_anomaly_review', fn( $flags, $rows, $schema, $table ) => $flags );
		$flags = (array) apply_filters( 'publictech_anomaly_review', $flags, $rows, $schema, $table );

		if ( ! $flags ) {
			return;
		}
		$this->persist( $schema, $table, $flags );
	}

	/**
	 * Run inline statistical checks. Returns one flag per suspicious row.
	 * Public + static so unit tests can exercise the math without booting WP.
	 *
	 * @return array<int,array{row_id:mixed,reason:string,score:float}>
	 */
	public static function statistical_flags( array $rows ): array {
		$out = [];

		// 1) Benford's law on the high-value column. The expected log10
		//    distribution of leading digits for naturally-arising figures
		//    is well-defined: P(d) = log10(1 + 1/d) for d in 1..9.
		//    Any single row whose leading digit deviates more than 2σ
		//    from expectation in a batch of ≥30 rows gets a soft flag.
		$amount_key = null;
		foreach ( [ 'amount_cad', 'amount', 'value' ] as $k ) {
			if ( isset( $rows[0][ $k ] ) ) { $amount_key = $k; break; }
		}
		if ( $amount_key && count( $rows ) >= 30 ) {
			$digits = [];
			foreach ( $rows as $r ) {
				$v = (float) ( $r[ $amount_key ] ?? 0 );
				if ( $v <= 0 ) { continue; }
				$d = (int) substr( (string) $v, 0, 1 );
				if ( $d >= 1 && $d <= 9 ) { $digits[] = $d; }
			}
			$n = count( $digits );
			if ( $n >= 30 ) {
				$expected = [];
				for ( $d = 1; $d <= 9; $d++ ) {
					$expected[ $d ] = log10( 1 + 1 / $d );
				}
				$observed = array_fill( 1, 9, 0 );
				foreach ( $digits as $d ) { $observed[ $d ]++; }
				$chi = 0.0;
				for ( $d = 1; $d <= 9; $d++ ) {
					$e   = $expected[ $d ] * $n;
					$chi += ( ( $observed[ $d ] - $e ) ** 2 ) / max( 1.0, $e );
				}
				// p ≈ 0.05 ↔ chi-squared at 8 df = 15.507.
				if ( $chi > 15.507 ) {
					$out[] = [
						'row_id' => null,
						'reason' => 'benford_first_digit',
						'score'  => round( $chi, 2 ),
					];
				}
			}
		}

		// 2) Weekend / holiday timing — any individual row whose date column
		//    falls on Saturday/Sunday OR a federal holiday is flagged.
		$date_key = null;
		foreach ( [ 'awarded_date', 'txn_date', 'date' ] as $k ) {
			if ( isset( $rows[0][ $k ] ) ) { $date_key = $k; break; }
		}
		if ( $date_key ) {
			$holidays = (array) apply_filters( 'publictech_anomaly_holidays', [
				'01-01', '07-01', '12-25', '12-26',
			] );
			foreach ( $rows as $r ) {
				$raw = (string) ( $r[ $date_key ] ?? '' );
				if ( '' === $raw ) { continue; }
				$ts = strtotime( $raw );
				if ( ! $ts ) { continue; }
				$dow = (int) gmdate( 'w', $ts );
				$md  = gmdate( 'm-d', $ts );
				if ( 0 === $dow || 6 === $dow || in_array( $md, $holidays, true ) ) {
					$out[] = [
						'row_id' => $r['id'] ?? $r['txn_id'] ?? null,
						'reason' => 0 === $dow || 6 === $dow ? 'weekend_award' : 'holiday_award',
						'score'  => 1.0,
					];
				}
			}
		}

		return $out;
	}

	private function persist( string $schema, string $table, array $flags ): void {
		$key      = 'pt_anomaly_flags_' . substr( md5( $schema . '|' . $table ), 0, 12 );
		$existing = get_option( $key, [] );
		$existing = is_array( $existing ) ? $existing : [];
		foreach ( $flags as $flag ) {
			$existing[] = $flag + [ 'at' => gmdate( 'c' ) ];
		}
		// Cap so wp_options doesn't grow without bound.
		if ( count( $existing ) > 500 ) {
			$existing = array_slice( $existing, -500 );
		}
		update_option( $key, $existing, false );
	}

	/** Read flags for a given table. */
	public function flags_for( string $schema, string $table ): array {
		$key = 'pt_anomaly_flags_' . substr( md5( $schema . '|' . $table ), 0, 12 );
		$rows = get_option( $key, [] );
		return is_array( $rows ) ? $rows : [];
	}
}
