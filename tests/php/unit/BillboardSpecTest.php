<?php
/**
 * Chart_Spec::validate() / validate_source() are the only validation gate
 * between an untrusted POST body and post_meta storage. These tests catch
 * any drift in the option-literal whitelist that would let a script-block
 * or oversized payload through.
 *
 * @package PublicTech\Tests
 */

declare( strict_types=1 );

namespace PublicTech\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BillboardSpecTest extends TestCase {

	public static function setUpBeforeClass(): void {
		// Charts_REST class touches WP runtime symbols inside ::register()
		// but the validator is the pure static at the top of the file —
		// loading the file class-charts-rest.php is sufficient.
		require_once dirname( __DIR__, 3 ) . '/publictech-core/inc/class-charts-rest.php';
	}

	public function test_rejects_non_array(): void {
		$err = \PublicTech\Core\Chart_Spec::validate( 'oops' );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'pt_bad_spec', $err->code );
	}

	public function test_rejects_wrong_version(): void {
		$err = \PublicTech\Core\Chart_Spec::validate( [
			'version' => 99,
			'data' => [ 'type' => 'bar' ],
		] );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'pt_bad_spec_version', $err->code );
	}

	public function test_rejects_unknown_chart_type(): void {
		$err = \PublicTech\Core\Chart_Spec::validate( [
			'version' => 1,
			'data'    => [ 'type' => 'mandala' ],
		] );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'pt_bad_spec_type', $err->code );
	}

	public function test_accepts_minimal_bar_spec(): void {
		$out = \PublicTech\Core\Chart_Spec::validate( [
			'version' => 1,
			'data'    => [ 'type' => 'bar' ],
		] );
		$this->assertIsArray( $out );
		$this->assertSame( 'bar', $out['data']['type'] );
		$this->assertSame( 'category', $out['axis']['x']['type'] );
		$this->assertSame( 'indexed',  $out['axis']['y']['type'] );
		$this->assertFalse( $out['grid']['x']['show'] );
		$this->assertFalse( $out['grid']['y']['show'] );
		$this->assertTrue( $out['theme']['useGlobalCustomizer'] );
	}

	public function test_drops_script_blocks_and_unknown_axis_types(): void {
		$out = \PublicTech\Core\Chart_Spec::validate( [
			'version' => 1,
			'data'    => [ 'type' => 'line' ],
			'axis'    => [
				'x' => [ 'label' => '<script>alert(1)</script>', 'type' => 'evil' ],
				'y' => [ 'label' => 'OK', 'type' => 'log' ],
			],
		] );
		$this->assertIsArray( $out );
		// sanitize_text_field collapses tags-with-text — we just want to be sure
		// the unknown axis type fell back to the default and no script tag survives.
		$this->assertSame( 'category', $out['axis']['x']['type'] );
		$this->assertStringNotContainsString( '<script', $out['axis']['x']['label'] );
		$this->assertSame( 'log', $out['axis']['y']['type'] );
		$this->assertSame( 'OK',  $out['axis']['y']['label'] );
	}

	public function test_clamps_label_length(): void {
		$long = str_repeat( 'a', 500 );
		$out  = \PublicTech\Core\Chart_Spec::validate( [
			'version' => 1,
			'data'    => [ 'type' => 'bar' ],
			'axis'    => [ 'x' => [ 'label' => $long, 'type' => 'category' ] ],
		] );
		$this->assertIsArray( $out );
		$this->assertSame( 64, strlen( $out['axis']['x']['label'] ) );
	}

	public function test_clamps_transition_duration(): void {
		$out = \PublicTech\Core\Chart_Spec::validate( [
			'version' => 1,
			'data'    => [ 'type' => 'bar' ],
			'transition' => [ 'duration' => 1_000_000 ],
		] );
		$this->assertSame( 2000, $out['transition']['duration'] );
	}

	public function test_groups_are_array_of_string_arrays(): void {
		$out = \PublicTech\Core\Chart_Spec::validate( [
			'version' => 1,
			'data'    => [
				'type'   => 'bar',
				'groups' => [ [ 'a', 'b' ], 'invalid', [ 1, 'c' ] ],
			],
		] );
		$this->assertCount( 2, $out['data']['groups'] );
		$this->assertSame( [ 'a', 'b' ], $out['data']['groups'][0] );
		// Numeric values dropped; 'c' is kept.
		$this->assertSame( [ 'c' ], $out['data']['groups'][1] );
	}

	public function test_static_source_caps_row_count(): void {
		$rows = array_fill( 0, 2000, [ 'k' => 1 ] );
		$err  = \PublicTech\Core\Chart_Spec::validate_source( [ 'type' => 'static', 'rows' => $rows ] );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'pt_source_too_large', $err->code );
	}

	public function test_rest_source_rejects_external_endpoint(): void {
		foreach ( [
			'https://evil.example/wp-json/publictech/v1/charts',
			'/wp-json/publictech/v1/../../../etc/passwd',
			'../publictech/v1/charts',
			'',
		] as $bad ) {
			$err = \PublicTech\Core\Chart_Spec::validate_source( [ 'type' => 'rest', 'endpoint' => $bad ] );
			$this->assertInstanceOf( \WP_Error::class, $err, "Endpoint should be rejected: $bad" );
		}
	}

	public function test_rest_source_accepts_internal_endpoint(): void {
		$ok = \PublicTech\Core\Chart_Spec::validate_source( [
			'type'     => 'rest',
			'endpoint' => '/publictech/v1/stream/pt-finance-01/expenditures',
		] );
		$this->assertIsArray( $ok );
		$this->assertSame( 'rest', $ok['type'] );
	}

	public function test_ai_source_rejects_non_hex_query_id(): void {
		$err = \PublicTech\Core\Chart_Spec::validate_source( [ 'type' => 'ai', 'query_id' => 'rm -rf /' ] );
		$this->assertInstanceOf( \WP_Error::class, $err );
	}
}
