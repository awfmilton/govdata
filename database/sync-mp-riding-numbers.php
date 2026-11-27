<?php
/**
 * Stamp every sitting MP with the federal riding NUMBER they represent (post meta pt_riding_number).
 *
 * Why: Riding_Cache::resolve_for_post() tries pt_riding_number, then pt_riding_slug, then the riding NAME. No MP had
 * a number, so every lookup fell through to the name, and Parliament renamed 16 ridings in 2024 (e.g. "York Centre"
 * is now "North York"). The 2023 Representation Order names in pcbfr / the riding directory are the old ones, so
 * those 16 MP pages showed no riding grants panel and the directory's postal-code search could not find them.
 * Numbers do not change when a name does.
 *
 * Source: op_core_electedmember (current mandate, end_date IS NULL) -> op_core_riding.edid, keyed by the post's
 * op_politician_id. Each number is checked against the riding directory (the 2023 RO ridings the caches are built
 * for); a number the directory does not know is reported and NOT written. Idempotent; safe to re-run after
 * sync-current-mps.php.
 *
 *   wp eval-file database/sync-mp-riding-numbers.php            # write
 *   wp eval-file database/sync-mp-riding-numbers.php dry      # report only (WP-CLI passes positional args only)
 *
 * @package PublicTech\Core
 */

defined( 'ABSPATH' ) || die( "Run via: wp eval-file sync-mp-riding-numbers.php\n" );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	die( "CLI only.\n" );
}
if ( ! class_exists( \PublicTech\Core\Riding_Cache::class ) ) {
	WP_CLI::error( 'PublicTech Core is not active.' );
}

$pt_dry  = in_array( 'dry', (array) ( $args ?? [] ), true );
$pt_rc   = \PublicTech\Core\Riding_Cache::instance();
$pt_db   = publictech_db( 'pt-grantconts-01' );
$pt_ids  = get_posts( [ 'post_type' => 'member-parliament', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ] );
$pt_stat = [ 'set' => 0, 'unchanged' => 0, 'renamed' => 0, 'no_politician' => 0, 'no_mandate' => 0, 'unknown_number' => 0 ];

foreach ( $pt_ids as $pt_id ) {
	$pt_title = get_the_title( $pt_id );
	$pt_pol   = (int) get_post_meta( $pt_id, 'op_politician_id', true );
	if ( ! $pt_pol ) {
		$pt_stat['no_politician']++;
		WP_CLI::warning( "{$pt_title} (#{$pt_id}): no op_politician_id" );
		continue;
	}
	$pt_rows = $pt_db->select(
		'SELECT r.`edid` FROM `op_core_electedmember` e JOIN `op_core_riding` r ON r.`id` = e.`riding_id`
		 WHERE e.`politician_id` = ? AND e.`end_date` IS NULL AND r.`edid` IS NOT NULL
		 ORDER BY e.`start_date` DESC LIMIT 1',
		[ $pt_pol ]
	);
	$pt_num = isset( $pt_rows[0]['edid'] ) ? (string) $pt_rows[0]['edid'] : '';
	if ( '' === $pt_num ) {
		$pt_stat['no_mandate']++;
		WP_CLI::warning( "{$pt_title} (#{$pt_id}): no current mandate with a riding number" );
		continue;
	}
	$pt_entry = $pt_rc->directory_by_number( $pt_num );
	if ( ! $pt_entry ) {
		$pt_stat['unknown_number']++;
		WP_CLI::warning( "{$pt_title} (#{$pt_id}): riding {$pt_num} is not in the riding directory; not written" );
		continue;
	}
	$pt_district = (string) get_post_meta( $pt_id, 'pt_mp_district', true );
	$pt_by_name  = $pt_rc->directory_by_name( $pt_district );
	if ( ! $pt_by_name || (string) $pt_by_name['number'] !== $pt_num ) {
		$pt_stat['renamed']++;
		WP_CLI::log( sprintf( '%s (#%d): "%s" -> %s %s', $pt_title, $pt_id, $pt_district, $pt_num, (string) ( $pt_entry['name_en'] ?? '' ) ) );
	}
	if ( (string) get_post_meta( $pt_id, 'pt_riding_number', true ) === $pt_num ) {
		$pt_stat['unchanged']++;
		continue;
	}
	if ( ! $pt_dry ) {
		update_post_meta( $pt_id, 'pt_riding_number', $pt_num );
	}
	$pt_stat['set']++;
}

WP_CLI::success( ( $pt_dry ? '[dry run] ' : '' ) . wp_json_encode( $pt_stat + [ 'mps' => count( $pt_ids ) ] ) );
