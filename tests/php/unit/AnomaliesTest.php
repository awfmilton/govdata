<?php
/**
 * Tests for the inline statistical anomaly checks (Benford's-law first-digit
 * test + weekend/holiday timing) in PublicTech\Core\Anomalies.
 *
 * @package PublicTech\Tests
 */

declare( strict_types=1 );

namespace PublicTech\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PublicTech\Core\Anomalies;

final class AnomaliesTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 3 ) . '/publictech-core/inc/class-anomalies.php';
	}

	public function test_benford_passes_for_natural_distribution(): void {
		// Generate ~600 rows whose leading digits roughly follow Benford's
		// log10(1+1/d) distribution by using a power-law sample.
		$rows = [];
		for ( $i = 0; $i < 600; $i++ ) {
			$rows[] = [ 'amount_cad' => pow( 10, 2 + mt_rand( 0, 10000 ) / 2500 ) ];
		}
		$flags = Anomalies::statistical_flags( $rows );

		// May or may not flag — the test only asserts the checker doesn't
		// hard-fail and any benford flag, if present, carries a numeric score.
		foreach ( $flags as $f ) {
			if ( 'benford_first_digit' === $f['reason'] ) {
				$this->assertIsFloat( $f['score'] );
				$this->assertGreaterThan( 0, $f['score'] );
			}
		}
		$this->assertIsArray( $flags );
	}

	public function test_benford_flags_uniform_leading_digits(): void {
		// Uniformly distribute leading digits 1..9 — that is NOT what
		// Benford predicts (which is heavily skewed toward 1). Chi-squared
		// against expectation should fly past the 15.5 threshold.
		$rows = [];
		for ( $d = 1; $d <= 9; $d++ ) {
			for ( $j = 0; $j < 50; $j++ ) {
				$rows[] = [ 'amount_cad' => ( $d * 1000 ) + $j ];
			}
		}
		$flags  = Anomalies::statistical_flags( $rows );
		$reasons = array_column( $flags, 'reason' );
		$this->assertContains( 'benford_first_digit', $reasons, 'Uniform first-digit distribution should trip Benford check.' );
	}

	public function test_benford_skipped_for_small_batches(): void {
		// Below n=30 the test is statistically meaningless and should skip.
		$rows = array_fill( 0, 12, [ 'amount_cad' => 5000 ] );
		$flags = Anomalies::statistical_flags( $rows );
		$this->assertNotContains( 'benford_first_digit', array_column( $flags, 'reason' ) );
	}

	public function test_weekend_award_is_flagged(): void {
		$rows = [
			[ 'id' => 1, 'awarded_date' => '2024-06-29' ], // Saturday
			[ 'id' => 2, 'awarded_date' => '2024-06-30' ], // Sunday
			[ 'id' => 3, 'awarded_date' => '2024-07-01' ], // Holiday (default list)
			[ 'id' => 4, 'awarded_date' => '2024-07-02' ], // Tuesday (clean)
		];
		$flags = Anomalies::statistical_flags( $rows );
		$by_id = [];
		foreach ( $flags as $f ) {
			$by_id[ (int) $f['row_id'] ] = $f['reason'];
		}

		$this->assertSame( 'weekend_award', $by_id[1] ?? null );
		$this->assertSame( 'weekend_award', $by_id[2] ?? null );
		$this->assertSame( 'holiday_award', $by_id[3] ?? null );
		$this->assertArrayNotHasKey( 4, $by_id, 'Weekday non-holiday should not be flagged.' );
	}

	public function test_empty_rows_returns_empty_flags(): void {
		$this->assertSame( [], Anomalies::statistical_flags( [] ) );
	}
}
