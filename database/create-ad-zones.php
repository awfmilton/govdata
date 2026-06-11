<?php
/**
 * Scaffold the nine GovData Ad Services zones the theme templates reference.
 *
 * Each zone is a govads_zone post whose govads_zone_slug meta matches a
 * do_action( 'govads_zone', '…' ) call in publictech-terminal. Idempotent —
 * existing zones are updated, not duplicated (matched on govads_zone_slug).
 *
 *   wp eval-file database/create-ad-zones.php
 *
 * Run once after activating the govdata-ad-services plugin; re-run safely
 * any time. Zones the operator has customised keep their extra meta; only
 * label/sizes/placement are normalised to the values below.
 *
 * @package GovAds
 */

defined( 'ABSPATH' ) || die( "Run via: wp eval-file database/create-ad-zones.php\n" );

$gov_log = static function ( string $msg, string $level = 'log' ): void {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		'success' === $level ? WP_CLI::success( $msg ) : WP_CLI::log( $msg );
	} else {
		fwrite( STDERR, strtoupper( $level ) . ': ' . $msg . "\n" );
	}
};

if ( ! post_type_exists( 'govads_zone' ) ) {
	$gov_log( 'govads_zone post type is not registered — activate the govdata-ad-services plugin first.', 'error' );
	return;
}

/*
 * slug => [ label, placement, sizes ]. The first size is the primary one the
 * slot reserves space for, so single-size zones guarantee zero layout shift.
 */
$gov_zones = [
	'mp_profile_sidebar'        => [ 'MP Profile Sidebar',      'sidebar', [ '300x250' ] ],
	'mp_profile_mid_content'    => [ 'MP Profile Mid-Content',  'content', [ '728x90' ] ],
	'archive_after_search'      => [ 'Directory Banner',        'content', [ '728x90' ] ],
	'archive_sidebar'           => [ 'Directory Sidebar',       'sidebar', [ '300x250' ] ],
	'archive_before_pagination' => [ 'Directory Footer Banner', 'content', [ '728x90' ] ],
	'riding_report_sidebar'     => [ 'Riding Report Sidebar',   'sidebar', [ '300x250' ] ],
	'entity_report_sidebar'     => [ 'Entity Report Sidebar',   'sidebar', [ '300x250' ] ],
	'dashboard_below_hero'      => [ 'Dashboard Leaderboard',   'content', [ '970x90' ] ],
	'global_footer'             => [ 'Global Footer',           'footer',  [ '728x90' ] ],
];

$gov_created = 0;
$gov_updated = 0;

foreach ( $gov_zones as $gov_slug => [ $gov_label, $gov_placement, $gov_sizes ] ) {
	$gov_existing = get_posts(
		[
			'post_type'      => 'govads_zone',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:disable WordPress.DB.SlowDBQuery
			'meta_key'       => 'govads_zone_slug',
			'meta_value'     => $gov_slug,
			// phpcs:enable WordPress.DB.SlowDBQuery
		]
	);

	if ( $gov_existing ) {
		$gov_zone_id = (int) $gov_existing[0];
		wp_update_post( [ 'ID' => $gov_zone_id, 'post_title' => $gov_label, 'post_status' => 'publish' ] );
		$gov_updated++;
	} else {
		$gov_zone_id = wp_insert_post(
			[
				'post_title'  => $gov_label,
				'post_type'   => 'govads_zone',
				'post_status' => 'publish',
			]
		);
		if ( ! $gov_zone_id || is_wp_error( $gov_zone_id ) ) {
			$gov_log( sprintf( 'Failed to create zone %s.', $gov_slug ), 'error' );
			continue;
		}
		$gov_created++;
	}

	update_post_meta( $gov_zone_id, 'govads_zone_slug',      $gov_slug );
	update_post_meta( $gov_zone_id, 'govads_zone_label',     $gov_label );
	update_post_meta( $gov_zone_id, 'govads_zone_placement', $gov_placement );
	update_post_meta( $gov_zone_id, 'govads_zone_sizes',     $gov_sizes );

	delete_transient( 'govads_zone_' . $gov_slug );
	delete_transient( 'govads_pool_' . $gov_zone_id );

	$gov_log( sprintf( '  ✓ %-28s %-9s %s', $gov_slug, implode( ',', $gov_sizes ), $gov_label ) );
}

$gov_log( sprintf( 'Done. %d zone(s) created, %d updated.', $gov_created, $gov_updated ), 'success' );
