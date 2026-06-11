<?php
/**
 * Pure-function coverage for the chart snapshot pipeline: rev filenames,
 * hostile-filename rejection (meta poisoning / ingest-header forgery must
 * never escape uploads/pt-charts/), the HMAC scheme shared with the Node
 * capture service, and card-description normalisation.
 *
 * @package PublicTech\Tests
 */

declare( strict_types=1 );

namespace PublicTech\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PublicTech\Core\Chart_Snapshots;

final class ChartSnapshotsTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'PUBLICTECH_CORE_REST_NS' ) ) {
			define( 'PUBLICTECH_CORE_REST_NS', 'publictech/v1' );
		}
		require_once dirname( __DIR__, 3 ) . '/publictech-core/inc/class-chart-snapshots.php';
	}

	public function test_filename_is_deterministic_and_well_formed(): void {
		$a = Chart_Snapshots::filename( 42, '2026-06-11 10:00:00|{"spec":1}' );
		$b = Chart_Snapshots::filename( 42, '2026-06-11 10:00:00|{"spec":1}' );

		$this->assertSame( $a, $b, 'Same post + seed must map to the same file.' );
		$this->assertMatchesRegularExpression( '/^chart-42-[a-f0-9]{10}\.png$/', $a );
		$this->assertTrue( Chart_Snapshots::is_valid_filename( 42, $a ), 'Generator output must pass its own validator.' );
	}

	public function test_filename_rev_changes_when_seed_changes(): void {
		$before = Chart_Snapshots::filename( 42, 'rev-a' );
		$after  = Chart_Snapshots::filename( 42, 'rev-b' );

		$this->assertNotSame( $before, $after, 'A changed seed must mint a new og:image URL (scraper cache-busting).' );
	}

	public function test_filename_validation_rejects_hostile_values(): void {
		$bad = [
			'traversal'        => '../../evil.png',
			'nested traversal' => 'chart-42-aaaaaaaaaa/../../evil.png',
			'wrong post id'    => Chart_Snapshots::filename( 43, 'seed' ),
			'uppercase hex'    => 'chart-42-AAAAAAAAAA.png',
			'short hash'       => 'chart-42-abc.png',
			'long hash'        => 'chart-42-' . str_repeat( 'a', 11 ) . '.png',
			'wrong extension'  => 'chart-42-aaaaaaaaaa.jpg',
			'trailing newline' => "chart-42-aaaaaaaaaa.png\n",
			'empty'            => '',
		];
		foreach ( $bad as $label => $name ) {
			$this->assertFalse(
				Chart_Snapshots::is_valid_filename( 42, $name ),
				"Hostile filename ({$label}) must be rejected."
			);
		}
	}

	public function test_signature_round_trip(): void {
		$fields = [ '42', 'chart-42-aaaaaaaaaa.png', '1760000000' ];
		$sig    = Chart_Snapshots::sign( $fields, 'secret-key' );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $sig );
		$this->assertTrue( Chart_Snapshots::signature_valid( $sig, $fields, 'secret-key' ) );
	}

	public function test_signature_rejects_tampering(): void {
		$fields = [ '42', 'chart-42-aaaaaaaaaa.png', '1760000000' ];
		$sig    = Chart_Snapshots::sign( $fields, 'secret-key' );

		$tampered      = $fields;
		$tampered[1]   = 'chart-42-bbbbbbbbbb.png';
		$this->assertFalse( Chart_Snapshots::signature_valid( $sig, $tampered, 'secret-key' ), 'Changed field must invalidate.' );
		$this->assertFalse( Chart_Snapshots::signature_valid( $sig, $fields, 'other-key' ), 'Wrong secret must invalidate.' );
		$this->assertFalse( Chart_Snapshots::signature_valid( $sig, $fields, '' ), 'Unprovisioned secret must never validate.' );
		$this->assertFalse( Chart_Snapshots::signature_valid( '', $fields, 'secret-key' ), 'Empty signature must never validate.' );
	}

	public function test_field_joining_is_unambiguous(): void {
		// "ab","c" and "a","bc" must not collide — the newline join exists
		// precisely to keep field boundaries inside the MAC.
		$this->assertNotSame(
			Chart_Snapshots::sign( [ 'ab', 'c' ], 'k' ),
			Chart_Snapshots::sign( [ 'a', 'bc' ], 'k' )
		);
	}

	public function test_excerpt_for_card_normalises_markup_and_whitespace(): void {
		$this->assertSame(
			'Spending by region, Q3.',
			Chart_Snapshots::excerpt_for_card( "<p>Spending   by\n region,\t<strong>Q3</strong>.</p>" )
		);
		$this->assertSame( '', Chart_Snapshots::excerpt_for_card( "  \n\t " ) );
	}

	public function test_excerpt_for_card_truncates_at_word_boundary(): void {
		$text = str_repeat( 'word ', 100 ); // 500 chars
		$out  = Chart_Snapshots::excerpt_for_card( $text, 50 );

		$this->assertLessThanOrEqual( 50, mb_strlen( $out, 'UTF-8' ) );
		$this->assertStringEndsWith( '…', $out );
		$this->assertMatchesRegularExpression( '/word…$/u', $out, 'Cut must land on a whole word.' );
	}

	public function test_excerpt_for_card_is_multibyte_safe(): void {
		$text = str_repeat( 'дépensés ', 40 );
		$out  = Chart_Snapshots::excerpt_for_card( $text, 60 );

		$this->assertLessThanOrEqual( 60, mb_strlen( $out, 'UTF-8' ) );
		$this->assertStringEndsWith( '…', $out );
		// A byte-level cut would produce an invalid UTF-8 tail.
		$this->assertTrue( (bool) preg_match( '//u', $out ), 'Output must stay valid UTF-8.' );
	}

	public function test_short_excerpt_passes_through_untouched(): void {
		$this->assertSame( 'Short and sweet.', Chart_Snapshots::excerpt_for_card( 'Short and sweet.', 200 ) );
	}
}
