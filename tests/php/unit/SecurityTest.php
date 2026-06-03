<?php
/**
 * Tests for PublicTech\Core\Security.
 *
 * Covers:
 *   - assert_read_only() rejects every mutating verb (including comment tricks).
 *   - build_where() rejects unwhitelisted columns and unsupported operators.
 *   - build_where() handles IN / BETWEEN / IS NULL idioms.
 *
 * @package PublicTech\Tests
 */

declare( strict_types=1 );

namespace PublicTech\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PublicTech\Core\Security;

final class SecurityTest extends TestCase {

	public static function setUpBeforeClass(): void {
		// Loading the class lazily here means PHPStan's bootstrap doesn't double-include.
		require_once dirname( __DIR__, 3 ) . '/publictech-core/inc/class-security.php';
	}

	/** @dataProvider mutatingStatements */
	public function test_assert_read_only_rejects_writes( string $sql ): void {
		$this->expectException( \RuntimeException::class );
		Security::assert_read_only( $sql );
	}

	public static function mutatingStatements(): array {
		return [
			'plain insert'   => [ 'INSERT INTO x (a) VALUES (1)' ],
			'update'         => [ 'UPDATE x SET a=1 WHERE b=2' ],
			'delete'         => [ 'DELETE FROM x WHERE b=2' ],
			'drop'           => [ 'DROP TABLE x' ],
			'truncate'       => [ 'TRUNCATE TABLE x' ],
			'replace'        => [ 'REPLACE INTO x (a) VALUES (1)' ],
			'multistatement' => [ "SELECT 1; DELETE FROM x" ],
			'block comment'  => [ "/* SELECT */ DELETE FROM x" ],
			'line comment'   => [ "SELECT 1 -- harmless\nUPDATE x SET a=1" ],
			'lowercase'      => [ 'insert into x values (1)' ],
			'into outfile'   => [ "SELECT * FROM x INTO OUTFILE '/tmp/x'" ],
			'into dumpfile'  => [ "SELECT * FROM x INTO DUMPFILE '/tmp/x'" ],
			'load_file'      => [ "SELECT LOAD_FILE('/etc/passwd') FROM x" ],
		];
	}

	/** @dataProvider readOnlyStatements */
	public function test_assert_read_only_accepts_reads( string $sql ): void {
		Security::assert_read_only( $sql );
		$this->assertTrue( true );
	}

	public static function readOnlyStatements(): array {
		return [
			[ 'SELECT * FROM x' ],
			[ 'SELECT a, b FROM x WHERE c = ? AND d IN (?,?)' ],
			[ "SELECT 'inserted by' AS note FROM x" ],
			[ "SELECT 'DELETE FROM' as label, 1 FROM x" ],
			[ "SELECT 'INTO OUTFILE' AS note FROM x" ],
			[ 'WITH t AS (SELECT 1) SELECT * FROM t' ],
		];
	}

	public function test_build_where_drops_unwhitelisted_columns(): void {
		$columns = [ 'amount' => '`x`.`amount`' ];
		[ $sql, $params ] = Security::build_where(
			[
				[ 'col' => 'amount',    'op' => '>',  'val' => 100 ],
				[ 'col' => 'evil_col',  'op' => '=',  'val' => "1 OR 1=1" ],
				[ 'col' => 'amount',    'op' => 'OR', 'val' => 1 ], // bad op
			],
			$columns
		);
		$this->assertSame( 'WHERE `x`.`amount` > ?', $sql );
		$this->assertSame( [ 100 ], $params );
	}

	public function test_build_where_handles_in_clauses(): void {
		$columns = [ 'region' => '`x`.`region`' ];
		[ $sql, $params ] = Security::build_where(
			[ [ 'col' => 'region', 'op' => 'IN', 'val' => [ 'ON', 'QC', 'BC' ] ] ],
			$columns
		);
		$this->assertSame( 'WHERE `x`.`region` IN (?,?,?)', $sql );
		$this->assertSame( [ 'ON', 'QC', 'BC' ], $params );
	}

	public function test_build_where_handles_between(): void {
		$columns = [ 'd' => '`x`.`d`' ];
		[ $sql, $params ] = Security::build_where(
			[ [ 'col' => 'd', 'op' => 'BETWEEN', 'val' => [ '2024-01-01', '2024-12-31' ] ] ],
			$columns
		);
		$this->assertSame( 'WHERE `x`.`d` BETWEEN ? AND ?', $sql );
		$this->assertSame( [ '2024-01-01', '2024-12-31' ], $params );
	}

	public function test_build_where_handles_null_predicates(): void {
		$columns = [ 'flagged' => '`x`.`flagged`' ];
		[ $sql, $params ] = Security::build_where(
			[ [ 'col' => 'flagged', 'op' => 'IS NULL', 'val' => null ] ],
			$columns
		);
		$this->assertSame( 'WHERE `x`.`flagged` IS NULL', $sql );
		$this->assertSame( [], $params );
	}

	public function test_build_where_empty_returns_empty(): void {
		[ $sql, $params ] = Security::build_where( [], [ 'a' => '`x`.`a`' ] );
		$this->assertSame( '', $sql );
		$this->assertSame( [], $params );
	}
}
