<?php
/**
 * Tests for the pure funding roll-up in PublicTech\Core\Entity_Reports::build_snapshot().
 *
 * No database is required — build_snapshot() transforms an array of raw
 * master_table rows into the cached snapshot payload the page renders.
 *
 * @package PublicTech\Tests
 */

declare( strict_types=1 );

namespace PublicTech\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PublicTech\Core\Entity_Reports;

final class EntityReportTest extends TestCase {

	public static function setUpBeforeClass(): void {
		// The class constant CACHE_TTL references this WP global.
		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 3600 );
		}
		require_once dirname( __DIR__, 3 ) . '/publictech-core/inc/class-entity-reports.php';
	}

	/** @return array<int,array<string,mixed>> */
	private function sample_rows(): array {
		return [
			[ 'ref_number' => 'A1', 'agreement_value' => 1000000, 'year' => 2020, 'fiscal_year' => '2020-2021', 'agreement_title_en' => 'Pride event support', 'description_eng' => 'youth programming', 'prog_name_en' => 'Community Fund', 'prog_purpose_en' => '', 'owner_org_en' => 'Canadian Heritage', 'recipient_legal_name' => 'Test Org', 'recipient_operating_name' => '', 'agreement_start_date' => '2020-04-01' ],
			[ 'ref_number' => 'A2', 'agreement_value' => 500000,  'year' => 2020, 'fiscal_year' => '2020-2021', 'agreement_title_en' => 'General operating', 'description_eng' => '', 'prog_name_en' => 'Community Fund', 'prog_purpose_en' => '', 'owner_org_en' => 'Canadian Heritage', 'recipient_legal_name' => 'Test Org', 'recipient_operating_name' => '', 'agreement_start_date' => '2020-06-15' ],
			[ 'ref_number' => 'A3', 'agreement_value' => -100000, 'year' => 2021, 'fiscal_year' => '2021-2022', 'agreement_title_en' => 'Amendment reduction', 'description_eng' => '', 'prog_name_en' => 'Other', 'prog_purpose_en' => '', 'owner_org_en' => 'Women and Gender Equality', 'recipient_legal_name' => 'Test Org Operating', 'recipient_operating_name' => '', 'agreement_start_date' => '' ],
			[ 'ref_number' => 'A4', 'agreement_value' => 2000000, 'year' => 2022, 'fiscal_year' => '2022-2023', 'agreement_title_en' => 'Pride festival', 'description_eng' => '', 'prog_name_en' => 'Capacity', 'prog_purpose_en' => '', 'owner_org_en' => 'Canadian Heritage', 'recipient_legal_name' => 'Test Org', 'recipient_operating_name' => '', 'agreement_start_date' => '2022-03-30' ],
		];
	}

	private function entity(): array {
		return [ 'slug' => 'test-org', 'name' => 'Test Org', 'keywords' => [ 'pride', 'youth' ], 'blurb' => 'A test organisation.' ];
	}

	public function test_totals_net_and_split(): void {
		$snap   = Entity_Reports::build_snapshot( $this->sample_rows(), $this->entity() );
		$totals = $snap['totals'];

		$this->assertSame( 3400000.0, $totals['total_funding'], 'Net total sums all rows incl. the negative amendment.' );
		$this->assertSame( 3500000.0, $totals['positive_funding'] );
		$this->assertSame( -100000.0, $totals['negative_funding'] );
		$this->assertSame( 4, $totals['agreement_count'] );
		$this->assertSame( 2020, $totals['first_year'] );
		$this->assertSame( 2022, $totals['last_year'] );
		$this->assertSame( 2000000.0, $totals['max_agreement'] );
	}

	public function test_peak_year_is_highest_net(): void {
		$snap = Entity_Reports::build_snapshot( $this->sample_rows(), $this->entity() );
		$this->assertSame( 2022, $snap['totals']['peak_year']['year'] );
		$this->assertSame( 2000000.0, $snap['totals']['peak_year']['total'] );
	}

	public function test_by_year_groups_and_nets(): void {
		$snap   = Entity_Reports::build_snapshot( $this->sample_rows(), $this->entity() );
		$by_year = [];
		foreach ( $snap['by_year'] as $row ) {
			$by_year[ $row['year'] ] = $row;
		}
		$this->assertSame( 1500000.0, $by_year[2020]['total'] );
		$this->assertSame( 2, $by_year[2020]['pos_count'] );
		$this->assertSame( -100000.0, $by_year[2021]['total'] );
		$this->assertSame( 1, $by_year[2021]['neg_count'] );
		// Ascending year order is required for the cumulative chart.
		$this->assertSame( [ 2020, 2021, 2022 ], array_column( $snap['by_year'], 'year' ) );
	}

	public function test_programs_sorted_by_funding(): void {
		$snap = Entity_Reports::build_snapshot( $this->sample_rows(), $this->entity() );
		$this->assertSame( 'Capacity', $snap['by_program'][0]['program'] );
		$this->assertSame( 2000000.0, $snap['by_program'][0]['total'] );
		// Community Fund aggregates its two rows.
		$cf = array_values( array_filter( $snap['by_program'], static fn( $p ) => 'Community Fund' === $p['program'] ) )[0];
		$this->assertSame( 1500000.0, $cf['total'] );
		$this->assertSame( 2, $cf['count'] );
	}

	public function test_keyword_hits(): void {
		$snap = Entity_Reports::build_snapshot( $this->sample_rows(), $this->entity() );
		$hits = [];
		foreach ( $snap['keyword_hits'] as $h ) {
			$hits[ $h['keyword'] ] = $h;
		}
		// "pride" appears in two agreement titles.
		$this->assertSame( 3000000.0, $hits['pride']['total'] );
		$this->assertSame( 2, $hits['pride']['count'] );
		// "youth" appears once, in a description.
		$this->assertSame( 1000000.0, $hits['youth']['total'] );
		$this->assertSame( 1, $hits['youth']['count'] );
	}

	public function test_top_and_searchable_agreements(): void {
		$snap = Entity_Reports::build_snapshot( $this->sample_rows(), $this->entity() );
		$this->assertSame( 2000000.0, $snap['top_agreements'][0]['value'], 'Top agreement is the largest by value.' );
		$this->assertCount( 4, $snap['agreements'], 'All rows seed the searchable set (under the 2500 cap).' );
		$this->assertSame( '', $snap['agreements'][ array_search( 'A3', array_column( $snap['agreements'], 'ref_number' ), true ) ]['start_date'], 'Invalid dates normalise to empty.' );
	}

	public function test_source_query_builds_fulltext_match(): void {
		[ $sql, $params ] = Entity_Reports::source_query( [ 'legal_names' => [ 'Egale Canada' ] ] );
		// Recipient names are matched through the ft_recipient FULLTEXT index
		// (MATCH … AGAINST in BOOLEAN MODE), not leading-wildcard LIKE.
		$this->assertStringContainsString( 'MATCH(`recipient_legal_name`, `recipient_operating_name`)', $sql );
		$this->assertStringContainsString( 'AGAINST (? IN BOOLEAN MODE)', $sql );
		// The bound param is the registry name as a quoted boolean-mode phrase,
		// with no SQL wildcards.
		$this->assertContains( '"Egale Canada"', $params );
		// No match patterns → empty query (caller skips the entity).
		$this->assertSame( '', Entity_Reports::source_query( [ 'legal_names' => [] ] )[0] );
	}
}
