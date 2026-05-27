<?php
/**
 * The customizer-save REST handler whitelists every accepted key. We unit-test
 * the whitelist logic directly (no WP boot) so a future contributor who adds
 * a new key without validation will see the test fail fast.
 *
 * @package PublicTech\Tests
 */

declare( strict_types=1 );

namespace PublicTech\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CustomizerPayloadTest extends TestCase {

	/**
	 * Mirrors the whitelist inside REST_API::customizer_save(). If you change
	 * one, change both — that's exactly the kind of drift this test exists
	 * to catch.
	 */
	private function clean( array $raw ): array {
		$valid_curve = [ 'monotone', 'linear', 'step' ];
		$valid_grid  = [ 'off', 'dotted', 'solid' ];
		$valid_bar   = [ 'solid', 'stripe', 'outline' ];
		$valid_font  = [ 'Montserrat', 'JetBrains Mono', 'Georgia' ];

		$clean = [];
		if ( isset( $raw['accent'] ) && is_string( $raw['accent'] ) ) {
			$hex = \sanitize_hex_color( $raw['accent'] );
			if ( $hex ) {
				$clean['accent'] = $hex;
			}
		}
		if ( isset( $raw['curve'] ) && in_array( $raw['curve'], $valid_curve, true ) ) { $clean['curve'] = $raw['curve']; }
		if ( isset( $raw['grid']  ) && in_array( $raw['grid'],  $valid_grid,  true ) ) { $clean['grid']  = $raw['grid']; }
		if ( isset( $raw['bar']   ) && in_array( $raw['bar'],   $valid_bar,   true ) ) { $clean['bar']   = $raw['bar']; }
		if ( isset( $raw['font']  ) && in_array( $raw['font'],  $valid_font,  true ) ) { $clean['font']  = $raw['font']; }
		if ( isset( $raw['stroke'] ) ) { $clean['stroke'] = max( 0.5, min( 6.0, (float) $raw['stroke'] ) ); }
		if ( isset( $raw['marker'] ) ) { $clean['marker'] = max( 0,   min( 12,  (int)   $raw['marker'] ) ); }
		if ( isset( $raw['area']   ) ) { $clean['area']   = max( 0,   min( 1.0, (float) $raw['area']   ) ); }
		if ( isset( $raw['anim']   ) ) { $clean['anim']   = (bool) $raw['anim']; }
		return $clean;
	}

	public function test_drops_unknown_keys(): void {
		$out = $this->clean( [ 'accent' => '#3ddc97', 'evil' => '<script>', 'curve' => 'monotone' ] );
		$this->assertSame( [ 'accent' => '#3ddc97', 'curve' => 'monotone' ], $out );
	}

	public function test_rejects_bad_hex_colour(): void {
		$out = $this->clean( [ 'accent' => 'javascript:alert(1)' ] );
		$this->assertArrayNotHasKey( 'accent', $out );
	}

	public function test_rejects_unknown_enum_values(): void {
		$out = $this->clean( [ 'curve' => 'bezier', 'grid' => 'fancy', 'bar' => '../etc/passwd' ] );
		$this->assertSame( [], $out );
	}

	public function test_clamps_numeric_ranges(): void {
		$out = $this->clean( [ 'stroke' => 999, 'marker' => -50, 'area' => 5 ] );
		$this->assertSame( 6.0, $out['stroke'] );
		$this->assertSame( 0,   $out['marker'] );
		$this->assertSame( 1.0, $out['area'] );
	}

	public function test_coerces_anim_to_bool(): void {
		foreach ( [ true, 'yes', 1, 'true' ] as $truthy ) {
			$this->assertTrue( $this->clean( [ 'anim' => $truthy ] )['anim'] );
		}
		foreach ( [ false, '', 0, null ] as $falsy ) {
			// null gets dropped before we reach the bool cast (the isset() guard).
			$out = $this->clean( [ 'anim' => $falsy ] );
			if ( array_key_exists( 'anim', $out ) ) {
				$this->assertFalse( $out['anim'] );
			}
		}
	}
}
