<?php
defined( 'ABSPATH' ) || die( "Run via: wp eval-file\n" );

$posts = get_posts( [
	'post_type'      => 'member-parliament',
	'post_status'    => [ 'publish', 'draft' ],
	'posts_per_page' => -1,
	'fields'         => 'ids',
] );

$no_ext   = [];
$no_data  = [];
$has_data = 0;

foreach ( $posts as $id ) {
	$ext  = (string) get_post_meta( $id, 'external_mp_id', true );
	$from = (string) get_post_meta( $id, 'finance_data_from', true );
	$to   = (string) get_post_meta( $id, 'finance_data_to', true );
	$op   = (int)    get_post_meta( $id, 'op_politician_id', true );
	$stat = get_post_status( $id );

	if ( '' === $ext ) {
		$no_ext[] = [ 'id' => $id, 'title' => get_the_title( $id ), 'op' => $op, 'status' => $stat ];
	}
	if ( '' === $from ) {
		$no_data[] = [ 'id' => $id, 'title' => get_the_title( $id ), 'ext' => $ext, 'op' => $op, 'status' => $stat ];
	} else {
		$has_data++;
	}
}

WP_CLI::log( 'Total posts : ' . count( $posts ) );
WP_CLI::log( 'With finance data (finance_data_from set): ' . $has_data );
WP_CLI::log( 'Still missing external_mp_id: ' . count( $no_ext ) );
WP_CLI::log( 'Still missing finance_data_from: ' . count( $no_data ) );

if ( $no_ext ) {
	WP_CLI::log( "\nPosts without external_mp_id (first 20):" );
	foreach ( array_slice( $no_ext, 0, 20 ) as $r ) {
		WP_CLI::log( "  [{$r['status']}] {$r['title']}  op_pol_id={$r['op']}" );
	}
}
if ( count( $no_data ) <= 20 ) {
	WP_CLI::log( "\nPosts without finance_data_from:" );
	foreach ( $no_data as $r ) {
		WP_CLI::log( "  [{$r['status']}] {$r['title']}  ext={$r['ext']}  op_pol_id={$r['op']}" );
	}
}
