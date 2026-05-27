<?php
/**
 * Smoke tests on the tier catalog shape. We mostly want to catch a tier
 * definition that breaks the throttle math (missing rpm/rpd) or accidentally
 * grants MCP access to free.
 *
 * @package PublicTech\Tests
 */

declare( strict_types=1 );

namespace PublicTech\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AccessTiersTest extends TestCase {

	private function load_tiers(): array {
		// Replay just the array literal from class-access-tiers.php so we can
		// validate it without needing the full WP/Singleton bootstrap.
		require_once dirname( __DIR__, 3 ) . '/publictech-core/inc/class-access-tiers.php';
		$tiers = new \ReflectionMethod( '\PublicTech\Core\Access_Tiers', 'tiers' );
		$tiers->setAccessible( true );
		return $tiers->invoke( \PublicTech\Core\Access_Tiers::instance() );
	}

	public function test_every_tier_declares_required_keys(): void {
		foreach ( $this->load_tiers() as $slug => $def ) {
			foreach ( [ 'label', 'rpm', 'rpd', 'max_rows', 'schemas', 'mcp', 'concurrent' ] as $k ) {
				$this->assertArrayHasKey( $k, $def, "Tier '{$slug}' is missing '{$k}'." );
			}
			$this->assertIsInt( $def['rpm'],         "Tier '{$slug}' rpm must be int." );
			$this->assertIsInt( $def['rpd'],         "Tier '{$slug}' rpd must be int." );
			$this->assertIsInt( $def['max_rows'],    "Tier '{$slug}' max_rows must be int." );
			$this->assertGreaterThanOrEqual( 0, $def['rpm'] );
			$this->assertGreaterThanOrEqual( $def['rpm'], $def['rpd'], "Tier '{$slug}' rpd cannot be below rpm." );
			$this->assertGreaterThan( 0, $def['max_rows'] );
		}
	}

	public function test_anonymous_and_free_cannot_use_mcp(): void {
		$tiers = $this->load_tiers();
		$this->assertFalse( $tiers['anonymous']['mcp'] ?? true, 'Anonymous must not have MCP.' );
		$this->assertFalse( $tiers['free']['mcp']      ?? true, 'Free must not have MCP.' );
	}

	public function test_paid_tiers_strictly_dominate_free(): void {
		$tiers = $this->load_tiers();
		$free  = $tiers['free'];
		foreach ( [ 'analyst', 'newsroom', 'institutional' ] as $paid ) {
			$this->assertGreaterThan( $free['rpm'],      $tiers[ $paid ]['rpm'],      "{$paid} rpm > free." );
			$this->assertGreaterThan( $free['rpd'],      $tiers[ $paid ]['rpd'],      "{$paid} rpd > free." );
			$this->assertGreaterThan( $free['max_rows'], $tiers[ $paid ]['max_rows'], "{$paid} max_rows > free." );
		}
	}
}
