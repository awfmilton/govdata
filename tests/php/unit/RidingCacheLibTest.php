<?php
/**
 * Tests for the pure riding-cache helpers in
 * publictech-core/inc/riding-cache-lib.php — the logic the warmer and the
 * read path rely on, exercised without a database or a WordPress runtime.
 *
 * @package PublicTech\Tests
 */

declare( strict_types=1 );

namespace PublicTech\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function PublicTech\Core\pt_assemble_riding_payload;
use function PublicTech\Core\pt_collect_top_per_group;
use function PublicTech\Core\pt_format_money;
use function PublicTech\Core\pt_format_money_short;
use function PublicTech\Core\pt_governing_party_for_year;
use function PublicTech\Core\pt_normalize_riding_name;
use function PublicTech\Core\pt_province_from_riding_number;
use function PublicTech\Core\pt_recipient_type_label;
use function PublicTech\Core\pt_riding_keyword_match_sql;
use function PublicTech\Core\pt_riding_keyword_text_sql;
use function PublicTech\Core\pt_riding_keywords;
use function PublicTech\Core\pt_riding_slug;
use function PublicTech\Core\pt_sql_like_contains;

final class RidingCacheLibTest extends TestCase {

	public function test_riding_name_normalization_folds_dashes_and_accents(): void {
		$em     = pt_normalize_riding_name( 'Thunder Bay—Rainy River' );
		$hyphen = pt_normalize_riding_name( 'Thunder Bay-Rainy River' );
		$en     = pt_normalize_riding_name( 'thunder bay–rainy river' );
		$this->assertSame( 'thunder bay rainy river', $em );
		$this->assertSame( $em, $hyphen );
		$this->assertSame( $em, $en );

		$this->assertSame( 'quebec', pt_normalize_riding_name( 'Québec' ) );
		$this->assertSame( 'beauport limoilou', pt_normalize_riding_name( 'Beauport—Limoilou' ) );
		$this->assertSame( '', pt_normalize_riding_name( '   ' ) );
	}

	public function test_riding_slug(): void {
		$this->assertSame( 'thunder-bay-rainy-river', pt_riding_slug( 'Thunder Bay–Rainy River' ) );
		$this->assertSame( 'cote-de-beaupre', pt_riding_slug( 'Côte-de-Beaupré' ) );
	}

	public function test_province_from_riding_number(): void {
		$this->assertSame( 'ON', pt_province_from_riding_number( '35055' ) );
		$this->assertSame( 'QC', pt_province_from_riding_number( '24001' ) );
		$this->assertSame( 'BC', pt_province_from_riding_number( '59001' ) );
		$this->assertSame( '', pt_province_from_riding_number( '99999' ) );
		$this->assertSame( '', pt_province_from_riding_number( 'abc' ) );
	}

	public function test_governing_party_eras(): void {
		$this->assertSame( 'Liberal', pt_governing_party_for_year( 2003 ) );
		$this->assertSame( 'Conservative', pt_governing_party_for_year( 2006 ) );
		$this->assertSame( 'Conservative', pt_governing_party_for_year( 2014 ) );
		$this->assertSame( 'Liberal', pt_governing_party_for_year( 2015 ) );
		$this->assertSame( 'Liberal', pt_governing_party_for_year( 2024 ) );
	}

	public function test_sql_like_contains_escapes(): void {
		$this->assertSame( "'%Egale%'", pt_sql_like_contains( 'Egale' ) );
		$this->assertSame( "'%O''Brien%'", pt_sql_like_contains( "O'Brien" ) );
		// LIKE wildcards in the needle are neutralised.
		$this->assertSame( "'%a\\%b%'", pt_sql_like_contains( 'a%b' ) );
		$this->assertSame( "'%a\\_b%'", pt_sql_like_contains( 'a_b' ) );
	}

	public function test_keyword_match_sql(): void {
		$text = 'TXT';
		$this->assertSame( "(TXT LIKE '%Egale%')", pt_riding_keyword_match_sql( [ 'Egale' ], $text ) );
		$this->assertSame(
			"(TXT LIKE '%far-right%' OR TXT LIKE '%far right%')",
			pt_riding_keyword_match_sql( [ 'far-right', 'far right' ], $text )
		);
		$this->assertSame( '0', pt_riding_keyword_match_sql( [], $text ) );
		$this->assertSame( '0', pt_riding_keyword_match_sql( [ '' ], $text ) );
	}

	public function test_keyword_text_sql_lists_columns(): void {
		$sql = pt_riding_keyword_text_sql( 'm' );
		$this->assertStringStartsWith( "CONCAT_WS(' ', ", $sql );
		$this->assertStringContainsString( '`m`.`recipient_legal_name`', $sql );
		$this->assertStringContainsString( '`m`.`description_eng`', $sql );
	}

	public function test_keyword_catalogue_shape(): void {
		$keywords = pt_riding_keywords();
		$this->assertCount( 20, $keywords );
		foreach ( $keywords as $kw ) {
			$this->assertArrayHasKey( 'key', $kw );
			$this->assertArrayHasKey( 'label', $kw );
			$this->assertNotEmpty( $kw['patterns'] );
		}
		$keys = array_column( $keywords, 'key' );
		$this->assertSame( $keys, array_unique( $keys ), 'Keyword keys must be unique.' );
	}

	public function test_collect_top_per_group_is_bounded_and_ordered(): void {
		$rows = [];
		for ( $i = 1; $i <= 15; $i++ ) {
			$rows[] = [ 'rn' => 'A', 'v' => 100 - $i ]; // already descending
		}
		$rows[] = [ 'rn' => 'B', 'v' => 5 ];
		$rows[] = [ 'rn' => '',  'v' => 1 ]; // empty group is dropped

		$top = pt_collect_top_per_group( $rows, 'rn', 10 );
		$this->assertCount( 10, $top['A'] );
		$this->assertSame( 99, $top['A'][0]['v'] );
		$this->assertSame( 90, $top['A'][9]['v'] );
		$this->assertCount( 1, $top['B'] );
		$this->assertArrayNotHasKey( '', $top );
	}

	public function test_recipient_type_label(): void {
		$this->assertSame( 'Aboriginal / Indigenous', pt_recipient_type_label( 'A', [] ) );
		$this->assertSame( 'From DB', pt_recipient_type_label( 'A', [ 'A' => 'From DB' ] ) );
		$this->assertSame( 'Unknown / Unspecified', pt_recipient_type_label( '', [] ) );
		$this->assertSame( 'Type Z', pt_recipient_type_label( 'z', [] ) );
	}

	public function test_money_formatters(): void {
		$this->assertSame( '$1.50B', pt_format_money_short( 1_500_000_000 ) );
		$this->assertSame( '$2.3M', pt_format_money_short( 2_300_000 ) );
		$this->assertSame( '$12K', pt_format_money_short( 12_000 ) );
		$this->assertSame( '$340', pt_format_money_short( 340 ) );
		$this->assertSame( '-$5K', pt_format_money_short( -5_000 ) );

		$this->assertSame( '$1,234,567', pt_format_money( 1_234_567 ) );
		$this->assertSame( '-$5,000', pt_format_money( -5_000 ) );
	}

	public function test_assemble_payload_sorts_and_rolls_up(): void {
		$payload = pt_assemble_riding_payload( [
			'riding' => [ 'number' => '35055', 'name_en' => 'Test Riding', 'slug' => 'test-riding' ],
			'main'   => [
				'agreements'          => 100,
				'total'               => 5_000_000.0,
				'positive_total'      => 5_200_000.0,
				'negative_total'      => -200_000.0,
				'negative_agreements' => 3,
				'first_year'          => 2008,
				'last_year'           => 2024,
				'max_value'           => 900_000.0,
			],
			'years' => [
				[ 'year' => 2016, 'total' => 3_000_000, 'positive_total' => 3_000_000, 'negative_total' => 0, 'positive_agreements' => 40, 'negative_agreements' => 0 ],
				[ 'year' => 2008, 'total' => 1_000_000, 'positive_total' => 1_000_000, 'negative_total' => 0, 'positive_agreements' => 10, 'negative_agreements' => 0 ],
				[ 'year' => 2024, 'total' => 1_000_000, 'positive_total' => 1_000_000, 'negative_total' => 0, 'positive_agreements' => 12, 'negative_agreements' => 0 ],
			],
			'recipients' => [
				[ 'name' => 'B Corp', 'total' => 1_000_000, 'agreements' => 5 ],
				[ 'name' => 'A Corp', 'total' => 4_000_000, 'agreements' => 10 ],
			],
			'recipient_types' => [
				[ 'label' => 'Other', 'total' => 1_000_000, 'agreements' => 20 ],
				[ 'label' => 'Aboriginal / Indigenous', 'total' => 4_000_000, 'agreements' => 30 ],
			],
			'programs' => [
				[ 'name' => 'P1', 'agreements' => 30, 'total' => 1_000_000 ],
				[ 'name' => 'P2', 'agreements' => 50, 'total' => 2_000_000 ],
			],
			'keywords' => [
				[ 'key' => 'indigenous', 'label' => 'Indigenous', 'total' => 4_000_000, 'agreements' => 30 ],
				[ 'key' => 'pride', 'label' => 'Pride', 'total' => 100_000, 'agreements' => 2 ],
			],
			'generated_at' => '2026-05-28T00:00:00+00:00',
		] );

		$this->assertSame( 1, $payload['schema_version'] );

		// Totals taken straight from the main rollup.
		$this->assertSame( 5_000_000.0, $payload['totals']['total_value'] );
		$this->assertSame( 100, $payload['totals']['agreement_count'] );
		$this->assertSame( 2016, $payload['totals']['peak_year'] );
		$this->assertSame( 3_000_000.0, $payload['totals']['peak_year_total'] );
		$this->assertSame( 'Aboriginal / Indigenous', $payload['totals']['top_recipient_type'] );

		// Years ascending, with governing-party era tags.
		$this->assertSame( [ 2008, 2016, 2024 ], array_column( $payload['by_year'], 'year' ) );
		$this->assertSame( 'Conservative', $payload['by_year'][0]['party'] );
		$this->assertSame( 'Liberal', $payload['by_year'][1]['party'] );

		// Recipients / programs / keywords sorted by their headline metric.
		$this->assertSame( 'A Corp', $payload['top_recipients'][0]['name'] );
		$this->assertSame( 'P2', $payload['top_programs'][0]['name'] );
		$this->assertSame( 'indigenous', $payload['by_keyword'][0]['key'] );
		$this->assertSame( 'Aboriginal / Indigenous', $payload['by_recipient_type'][0]['label'] );
	}
}
