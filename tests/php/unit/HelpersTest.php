<?php
/**
 * Tests for the small helpers in publictech-core/inc/helpers.php.
 *
 * @package PublicTech\Tests
 */

declare( strict_types=1 );

namespace PublicTech\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function PublicTech\Core\bucket_key;
use function PublicTech\Core\safe_ident;
use function PublicTech\Core\stream_json_rows;

final class HelpersTest extends TestCase {

	public function test_safe_ident_quotes_valid_identifiers(): void {
		$this->assertSame( '`vendor_name`',  safe_ident( 'vendor_name' ) );
		$this->assertSame( '`amount_cad_2`', safe_ident( 'amount_cad_2' ) );
	}

	/** @dataProvider unsafeIdentifiers */
	public function test_safe_ident_rejects_dangerous_input( string $bad ): void {
		$this->expectException( \InvalidArgumentException::class );
		safe_ident( $bad );
	}

	public static function unsafeIdentifiers(): array {
		return [
			'space'    => [ 'vendor name' ],
			'sql tail' => [ "vendor`; DROP TABLE x;--" ],
			'dot'      => [ 'finance.vendor_name' ],
			'empty'    => [ '' ],
			'too long' => [ str_repeat( 'a', 65 ) ],
			'utf'      => [ 'idéntifier' ],
		];
	}

	public function test_bucket_key_is_stable_and_distinct(): void {
		$a = bucket_key( 42, '203.0.113.4', 'free' );
		$b = bucket_key( 42, '203.0.113.4', 'free' );
		$c = bucket_key( 42, '203.0.113.4', 'analyst' );
		$d = bucket_key( null, '203.0.113.4', 'free' );

		$this->assertSame( $a, $b, 'Same inputs must hash identically.' );
		$this->assertNotSame( $a, $c, 'Different tier must change bucket.' );
		$this->assertNotSame( $a, $d, 'Different user must change bucket.' );
		$this->assertSame( 64, strlen( $a ), 'sha256 hex is 64 chars.' );
	}

	public function test_stream_json_rows_emits_well_formed_array(): void {
		$pdo  = new \PDO( 'sqlite::memory:' );
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$pdo->exec( 'CREATE TABLE t (id INTEGER, name TEXT)' );
		$pdo->exec( "INSERT INTO t VALUES (1,'alpha'),(2,'beta'),(3,'gamma')" );

		ob_start();
		stream_json_rows( $pdo->query( 'SELECT * FROM t ORDER BY id' ) );
		$out = ob_get_clean();

		$this->assertJson( $out );
		$decoded = json_decode( $out, true );
		$this->assertCount( 3, $decoded );
		$this->assertSame( 'alpha', $decoded[0]['name'] );
	}

	public function test_stream_json_rows_empty_set_is_empty_array(): void {
		$pdo = new \PDO( 'sqlite::memory:' );
		$pdo->exec( 'CREATE TABLE t (id INTEGER)' );

		ob_start();
		stream_json_rows( $pdo->query( 'SELECT * FROM t' ) );
		$out = ob_get_clean();

		$this->assertSame( '[]', $out );
	}
}
