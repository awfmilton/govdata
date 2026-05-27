<?php
/**
 * Sanity tests on the theme/plugin headers so a malformed style.css doesn't
 * silently break theme detection or theme-check tooling in CI.
 *
 * @package PublicTech\Tests
 */

declare( strict_types=1 );

namespace PublicTech\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeMetadataTest extends TestCase {

	public function test_parent_theme_header_declares_required_fields(): void {
		$header = file_get_contents( dirname( __DIR__, 3 ) . '/publictech-core/style.css' );
		$this->assertNotFalse( $header );
		$this->assertMatchesRegularExpression( '/Theme Name:\s+PublicTech Core/i',        $header );
		$this->assertMatchesRegularExpression( '/Version:\s+\d+\.\d+\.\d+/',              $header );
		$this->assertMatchesRegularExpression( '/Requires PHP:\s+8\.\d+/',                $header );
		$this->assertMatchesRegularExpression( '/Requires at least:\s+6\.\d+/',           $header );
		$this->assertMatchesRegularExpression( '/License:\s+GPL-2\.0-or-later/',          $header );
		$this->assertMatchesRegularExpression( '/Text Domain:\s+publictech-core/i',       $header );
	}

	public function test_child_theme_header_references_parent(): void {
		$header = file_get_contents( dirname( __DIR__, 3 ) . '/publictech-terminal/style.css' );
		$this->assertNotFalse( $header );
		$this->assertMatchesRegularExpression( '/Theme Name:\s+PublicTech Terminal/i', $header );
		$this->assertMatchesRegularExpression( '/Template:\s+publictech-core/',         $header );
		$this->assertMatchesRegularExpression( '/Text Domain:\s+publictech-terminal/i', $header );
	}

	public function test_mcp_plugin_header_is_well_formed(): void {
		$header = file_get_contents( dirname( __DIR__, 3 ) . '/publictech-mcp/publictech-mcp.php' );
		$this->assertNotFalse( $header );
		$this->assertMatchesRegularExpression( '/Plugin Name:\s+PublicTech MCP Server/', $header );
		$this->assertMatchesRegularExpression( '/Requires PHP:\s+8\.\d+/',               $header );
		$this->assertMatchesRegularExpression( '/Text Domain:\s+publictech-mcp/i',       $header );
	}

	public function test_php_files_have_abspath_guard(): void {
		// Anything in inc/, src/, page-templates/, parts/ should refuse direct access.
		$dirs = [
			dirname( __DIR__, 3 ) . '/publictech-core/inc',
			dirname( __DIR__, 3 ) . '/publictech-mcp/src',
			dirname( __DIR__, 3 ) . '/publictech-terminal/page-templates',
			dirname( __DIR__, 3 ) . '/publictech-terminal/parts',
		];
		foreach ( $dirs as $dir ) {
			foreach ( glob( $dir . '/*.php' ) as $path ) {
				$contents = file_get_contents( $path );
				$this->assertStringContainsString(
					"defined( 'ABSPATH' ) || exit;",
					$contents,
					basename( $path ) . ' is missing the ABSPATH guard.'
				);
			}
		}
	}
}
