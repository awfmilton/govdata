<?php
/**
 * Postal codes whose 2023 riding is questionable.
 *
 * Reads grantconts.pcbfr_review (database/pcbfr-review.sql): the codes Elections Canada's lookup could not
 * resolve to one riding, each checked by Argon. High-confidence answers are applied to pcbfr; medium and low
 * ones are not, and the code keeps its computed riding. Riding pages show a notice when any of these codes
 * was or may be placed in them, and /postal-code-review/ lists them all, so readers can help correct them.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Postal_Review {

	private const SOURCE_ALIAS = 'pt-grantconts-01';
	private const CACHE_KEY    = 'pt_postal_review_v1';

	/** Every questionable code, lowest confidence first. */
	public static function all(): array {
		$rows = wp_cache_get( self::CACHE_KEY, 'publictech' );
		if ( false === $rows ) {
			$rows = get_transient( self::CACHE_KEY );
		}
		if ( false === $rows ) {
			$rows = self::query(
				'SELECT `postal_code`, `city`, `original_riding`, `checked_riding`, `confidence`, `applied`, `evidence`, `checked_by` '
				. 'FROM `pcbfr_review` ORDER BY FIELD(`confidence`, "low", "medium", "high"), `postal_code`'
			);
			if ( $rows ) {
				set_transient( self::CACHE_KEY, $rows, DAY_IN_SECONDS );
			}
		}
		wp_cache_set( self::CACHE_KEY, $rows, 'publictech', HOUR_IN_SECONDS );
		return (array) $rows;
	}

	/** Codes that are, were, or may be placed in a riding (by either the original or the checked answer). */
	public static function for_riding( string $riding_number ): array {
		if ( ! preg_match( '/^\d{5}$/', $riding_number ) ) {
			return [];
		}
		return array_values( array_filter( self::all(), static fn( $r ) =>
			$riding_number === (string) $r['original_riding'] || $riding_number === (string) $r['checked_riding'] ) );
	}

	/** ['total' => n, 'high' => n, 'medium' => n, 'low' => n] */
	public static function counts( ?array $rows = null ): array {
		$out = [ 'total' => 0, 'high' => 0, 'medium' => 0, 'low' => 0 ];
		foreach ( $rows ?? self::all() as $r ) {
			$out['total']++;
			$out[ (string) $r['confidence'] ] = ( $out[ (string) $r['confidence'] ] ?? 0 ) + 1;
		}
		return $out;
	}

	/** Number of postal codes mapped to 2023 ridings. */
	public static function mapped_total(): int {
		$n = get_transient( 'pt_postal_mapped_total' );
		if ( false === $n ) {
			$r = self::query( 'SELECT COUNT(*) AS n FROM `pcbfr` p JOIN `representation_orders` o ON o.`id` = p.`ro_id` AND o.`code` = "2023_RO"' );
			$n = (int) ( $r[0]['n'] ?? 0 );
			if ( $n ) {
				set_transient( 'pt_postal_mapped_total', $n, DAY_IN_SECONDS );
			}
		}
		return (int) $n;
	}

	/** Where readers send corrections. Filter `publictech_postal_feedback_url` to point at a contact page. */
	public static function feedback_url( string $postal_code = '' ): string {
		$subject = 'Postal code riding correction' . ( $postal_code ? ': ' . $postal_code : '' );
		$default = 'mailto:' . antispambot( (string) get_option( 'admin_email' ) ) . '?subject=' . rawurlencode( $subject );
		return (string) apply_filters( 'publictech_postal_feedback_url', $default, $postal_code );
	}

	private static function query( string $sql ): array {
		try {
			return publictech_db( self::SOURCE_ALIAS )->select( $sql, [] );
		} catch ( \Throwable $e ) {
			error_log( '[PublicTech] Postal_Review query failed: ' . $e->getMessage() );
			return [];
		}
	}
}
