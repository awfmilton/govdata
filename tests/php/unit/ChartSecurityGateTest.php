<?php
/**
 * Tier gate on the chart builder. Anonymous + free must be rejected with a
 * "needs paid tier" code; Analyst/Newsroom/Institutional must pass.
 *
 * @package PublicTech\Tests
 */

declare( strict_types=1 );

namespace PublicTech\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ChartSecurityGateTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'PUBLICTECH_CORE_REST_NS' ) ) {
			define( 'PUBLICTECH_CORE_REST_NS', 'publictech/v1' );
		}
		require_once dirname( __DIR__, 3 ) . '/publictech-core/inc/class-access-tiers.php';
		require_once dirname( __DIR__, 3 ) . '/publictech-core/inc/class-charts-rest.php';
	}

	private function tiers(): array {
		$reflect = new \ReflectionMethod( '\PublicTech\Core\Access_Tiers', 'tiers' );
		$reflect->setAccessible( true );
		return $reflect->invoke( \PublicTech\Core\Access_Tiers::instance() );
	}

	public function test_every_tier_declares_charts_create_flag(): void {
		foreach ( $this->tiers() as $slug => $def ) {
			$this->assertArrayHasKey( 'charts.create', $def, "Tier '{$slug}' missing charts.create flag." );
			$this->assertIsBool( $def['charts.create'], "Tier '{$slug}' charts.create must be bool." );
		}
	}

	public function test_anonymous_and_free_cannot_create_charts(): void {
		$tiers = $this->tiers();
		$this->assertFalse(
			\PublicTech\Core\Chart_Spec::user_can_create( $tiers['anonymous'] ),
			'Anonymous tier must not pass the chart-builder gate.'
		);
		$this->assertFalse(
			\PublicTech\Core\Chart_Spec::user_can_create( $tiers['free'] ),
			'Free tier must not pass the chart-builder gate.'
		);
	}

	public function test_paid_tiers_can_create_charts(): void {
		$tiers = $this->tiers();
		foreach ( [ 'analyst', 'newsroom', 'institutional' ] as $paid ) {
			$this->assertTrue(
				\PublicTech\Core\Chart_Spec::user_can_create( $tiers[ $paid ] ),
				"Paid tier '{$paid}' must pass the chart-builder gate."
			);
		}
	}

	public function test_missing_flag_defaults_to_locked(): void {
		// Belt-and-braces: an out-of-bounds quota with no flag must NOT
		// accidentally unlock the builder.
		$this->assertFalse( \PublicTech\Core\Chart_Spec::user_can_create( [] ) );
		$this->assertFalse( \PublicTech\Core\Chart_Spec::user_can_create( [ 'charts.create' => 0 ] ) );
		$this->assertFalse( \PublicTech\Core\Chart_Spec::user_can_create( [ 'charts.create' => null ] ) );
		$this->assertTrue(  \PublicTech\Core\Chart_Spec::user_can_create( [ 'charts.create' => true ] ) );
	}
}
