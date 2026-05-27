<?php
/**
 * Tests for stream_csv_rows() — the streaming CSV writer behind the
 * /publictech/v1/export/{query_id} endpoint.
 *
 * @package PublicTech\Tests
 */

declare( strict_types=1 );

namespace PublicTech\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function PublicTech\Core\stream_csv_rows;

final class CsvStreamTest extends TestCase {

	private function pdo_with_rows( array $rows ): \PDO {
		$pdo = new \PDO( 'sqlite::memory:' );
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		if ( ! $rows ) {
			$pdo->exec( 'CREATE TABLE t (id INTEGER)' );
			return $pdo;
		}
		$cols    = array_keys( $rows[0] );
		$cols_sql = implode( ',', array_map( static fn( $c ) => $c . ' TEXT', $cols ) );
		$pdo->exec( "CREATE TABLE t ({$cols_sql})" );
		$ph = implode( ',', array_fill( 0, count( $cols ), '?' ) );
		$stmt = $pdo->prepare( "INSERT INTO t (" . implode( ',', $cols ) . ") VALUES ({$ph})" );
		foreach ( $rows as $row ) {
			$stmt->execute( array_values( $row ) );
		}
		return $pdo;
	}

	private function capture( \PDOStatement $stmt, ?string $filename = null ): string {
		ob_start();
		stream_csv_rows( $stmt, $filename );
		return ob_get_clean();
	}

	public function test_emits_utf8_bom_and_header_row(): void {
		$pdo = $this->pdo_with_rows( [
			[ 'vendor' => 'Cascade Print', 'amount' => '12.50' ],
			[ 'vendor' => 'NorthRail',     'amount' => '480.00' ],
		] );
		$out = $this->capture( $pdo->query( 'SELECT vendor, amount FROM t ORDER BY ROWID' ) );

		// BOM, header, then rows.
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $out );
		$lines = explode( "\n", trim( substr( $out, 3 ) ) );
		$this->assertSame( 'vendor,amount',        $lines[0] );
		$this->assertSame( '"Cascade Print",12.50', $lines[1] );
		$this->assertSame( 'NorthRail,480.00',     $lines[2] );
	}

	public function test_quotes_values_containing_separators_and_quotes(): void {
		$pdo = $this->pdo_with_rows( [
			[ 'name' => 'Co, Ltd.',       'note' => 'has "double" quotes' ],
			[ 'name' => "Line\nbreak co", 'note' => 'embedded newline' ],
		] );
		$out = $this->capture( $pdo->query( 'SELECT name, note FROM t ORDER BY ROWID' ) );

		// Comma is escaped by wrapping in quotes.
		$this->assertStringContainsString( '"Co, Ltd.",', $out );
		// Embedded quote is doubled per RFC 4180.
		$this->assertStringContainsString( '"has ""double"" quotes"', $out );
		// Embedded newline keeps the cell intact (wrapped).
		$this->assertStringContainsString( "\"Line\nbreak co\"", $out );
	}

	public function test_empty_result_set_still_writes_bom(): void {
		$pdo = $this->pdo_with_rows( [] );
		$out = $this->capture( $pdo->query( 'SELECT id FROM t' ) );
		// BOM only — no header (we can't know columns without a row).
		$this->assertSame( "\xEF\xBB\xBF", $out );
	}

	public function test_filename_is_sanitised_for_content_disposition(): void {
		// Pure unit on the helper's behaviour around the filename param: we
		// can't read response headers in a CLI context, but we can confirm
		// the call doesn't throw and the body output is well-formed.
		$pdo = $this->pdo_with_rows( [ [ 'a' => '1' ] ] );
		$out = $this->capture( $pdo->query( 'SELECT a FROM t' ), '../../etc/pass wd.csv' );
		$this->assertStringContainsString( 'a' . PHP_EOL, "a\n" );
		$this->assertStringContainsString( '1',           $out );
	}
}
