<?php
/**
 * Scaffold a WordPress Page for every registered funding-report entity.
 *
 * Each page is wired to the "PT — Entity Funding Report" template and tagged
 * with the pt_entity_slug meta the template reads. Pages are created under a
 * shared /groups parent, giving URLs like /groups/egale-canada. Idempotent —
 * existing pages are updated, not duplicated (matched on pt_entity_slug).
 *
 *   wp eval-file database/create-entity-pages.php
 *
 * Run once after activating the theme; re-run after editing the entity registry
 * (the publictech_funding_entities filter) to add pages for new entries.
 *
 * @package PublicTech\Core
 */

defined( 'ABSPATH' ) || die( "Run via: wp eval-file database/create-entity-pages.php\n" );

use PublicTech\Core\Entity_Reports;

$pt_log = static function ( string $msg, string $level = 'log' ): void {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		'success' === $level ? WP_CLI::success( $msg ) : WP_CLI::log( $msg );
	} else {
		fwrite( STDERR, strtoupper( $level ) . ': ' . $msg . "\n" );
	}
};

if ( ! class_exists( Entity_Reports::class ) ) {
	$pt_log( 'PublicTech Core is not loaded — activate the theme before running this script.', 'error' );
	return;
}

$pages = Entity_Reports::scaffold_pages();

foreach ( $pages as $slug => $post_id ) {
	$pt_log( sprintf( '  ✓ %-52s → %s', $slug, get_permalink( (int) $post_id ) ?: ( 'post ' . $post_id ) ) );
}

flush_rewrite_rules();
$pt_log( sprintf( 'Done. %d entity page%s ready.', count( $pages ), count( $pages ) === 1 ? '' : 's' ), 'success' );
