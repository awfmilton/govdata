<?php
/**
 * Tests for pt_finance_quarter_label() in publictech-core/inc/helpers.php.
 *
 * Covers the mapping from HoC fiscal-year + quarter-name to human-readable
 * calendar month labels used by MP_Finance_Warmer and the MP page template.
 *
 * @package PublicTech\Tests
 */

declare( strict_types=1 );

namespace PublicTech\Tests\Unit;

use PHPUnit\Framework\TestCase;
use function PublicTech\Core\pt_finance_quarter_label;

final class FinanceLabelsTest extends TestCase {

	/** @return array<string,array{0:string,1:string,2:string,3:string}> */
	public static function quarter_label_provider(): array {
		return [
			'Q1 FY2020-21 → Apr-Jun 2020' => [
				'2020-2021', 'first-quarter', 'April 2020', 'June 2020',
			],
			'Q2 FY2020-21 → Jul-Sep 2020' => [
				'2020-2021', 'second-quarter', 'July 2020', 'September 2020',
			],
			'Q3 FY2020-21 → Oct-Dec 2020' => [
				'2020-2021', 'third-quarter', 'October 2020', 'December 2020',
			],
			'Q4 FY2020-21 → Jan-Mar 2021' => [
				'2020-2021', 'fourth-quarter', 'January 2021', 'March 2021',
			],
			'Q3 FY2025-26 → Oct-Dec 2025 (last loaded quarter)' => [
				'2025-2026', 'third-quarter', 'October 2025', 'December 2025',
			],
			'Q2 FY2020-21 → oldest period in dataset' => [
				'2020-2021', 'second-quarter', 'July 2020', 'September 2020',
			],
		];
	}

	/** @dataProvider quarter_label_provider */
	public function test_quarter_label(
		string $fiscal_year,
		string $quarter_name,
		string $expected_start,
		string $expected_end
	): void {
		$label = pt_finance_quarter_label( $fiscal_year, $quarter_name );
		$this->assertSame( $expected_start, $label['start'], 'start label mismatch' );
		$this->assertSame( $expected_end,   $label['end'],   'end label mismatch' );
	}

	public function test_unknown_quarter_returns_empty_strings(): void {
		$label = pt_finance_quarter_label( '2024-2025', 'bogus-quarter' );
		$this->assertSame( '', $label['start'] );
		$this->assertSame( '', $label['end'] );
	}

	public function test_data_from_matches_oldest_loaded_period(): void {
		// The oldest period in the dataset is Q2 FY2020-2021 (id=22).
		// Verify the label matches what the warmer writes to post meta.
		$label = pt_finance_quarter_label( '2020-2021', 'second-quarter' );
		$this->assertSame( 'July 2020', $label['start'] );
	}

	public function test_data_to_matches_newest_loaded_period(): void {
		// The newest period is Q3 FY2025-2026 (id=1).
		$label = pt_finance_quarter_label( '2025-2026', 'third-quarter' );
		$this->assertSame( 'December 2025', $label['end'] );
	}

	public function test_fiscal_year_boundary_q4_increments_year(): void {
		// Q4 of any FY must use the calendar year that is FY start + 1.
		$label = pt_finance_quarter_label( '2023-2024', 'fourth-quarter' );
		$this->assertSame( 'January 2024', $label['start'] );
		$this->assertSame( 'March 2024',   $label['end'] );
	}
}
