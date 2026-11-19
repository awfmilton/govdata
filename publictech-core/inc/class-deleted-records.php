<?php
/**
 * Grant records the Government of Canada deleted from its published data.
 *
 * Reads grantconts.grants_removed_records (full copy of each record as last published) joined to
 * grants_record_notices (the public notice text). Built by database/rebuild-master-from-official.sql,
 * database/fix-amendment-ids-position.sql and database/grants-record-notices.sql.
 *
 * Deleted records are never part of site totals (they are not in master_table); pages show them in their
 * own panel with the notice, plus their own subtotal.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Deleted_Records {

	private const SOURCE_ALIAS = 'pt-grantconts-01';

	private const COLUMNS = 'r.`id` AS record_id, r.`ref_number`, r.`agreement_value`, r.`agreement_start_date`, '
		. 'r.`agreement_end_date`, r.`agreement_title_en`, r.`prog_name_en`, r.`owner_org_en`, r.`recipient_legal_name`, '
		. 'r.`recipient_operating_name`, r.`recipient_city`, r.`recipient_province`, r.`federal_riding_name_en`, '
		. 'r.`federal_riding_number`, r.`description_eng`, n.`title` AS notice_title, n.`body` AS notice_body, '
		. 'n.`first_seen`, n.`portal_checked_at`';

	private const FROM = 'FROM `grants_removed_records` r JOIN `grants_record_notices` n '
		. 'ON n.`record_id` = r.`id` AND n.`notice_type` = "deleted_by_government" ';

	/** Every deleted record, largest first. */
	public static function all(): array {
		return self::query( 'SELECT ' . self::COLUMNS . ' ' . self::FROM . 'ORDER BY r.`agreement_value` DESC', [] );
	}

	/** Deleted records for a recipient entity, matched exactly like its current agreements. */
	public static function for_entity( array $entity ): array {
		[ $sql, $params ] = Entity_Reports::source_query( $entity, 1 );
		if ( '' === $sql || empty( $params ) ) {
			return [];
		}
		return self::query( 'SELECT ' . self::COLUMNS . ' ' . self::FROM
			. 'WHERE MATCH(r.`recipient_legal_name`, r.`recipient_operating_name`) AGAINST (? IN BOOLEAN MODE) '
			. 'ORDER BY r.`agreement_value` DESC', [ $params[0] ] );
	}

	/** Deleted records in a riding (federal electoral district number). */
	public static function for_riding( string $riding_number ): array {
		if ( ! preg_match( '/^\d{5}$/', $riding_number ) ) {
			return [];
		}
		return self::query( 'SELECT ' . self::COLUMNS . ' ' . self::FROM
			. 'WHERE r.`federal_riding_number` = ? ORDER BY r.`agreement_value` DESC', [ (int) $riding_number ] );
	}

	/** Sum of agreement values (deleted records are reported separately from site totals). */
	public static function total( array $rows ): float {
		return (float) array_sum( array_map( static fn( $r ) => (float) ( $r['agreement_value'] ?? 0 ), $rows ) );
	}

	private static function query( string $sql, array $params ): array {
		try {
			return publictech_db( self::SOURCE_ALIAS )->select( $sql, $params );
		} catch ( \Throwable $e ) {
			error_log( '[PublicTech] Deleted_Records query failed: ' . $e->getMessage() );
			return [];
		}
	}
}
