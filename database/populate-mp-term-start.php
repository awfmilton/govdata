<?php
/**
 * One-off: populate mp_term_start post meta on every member-parliament post
 * that has an op_member_id meta, by reading start_date from op_core_electedmember.
 *
 * wp eval-file /home/dh_kf9eyv/populate-mp-term-start.php --path=/home/dh_kf9eyv/govdata.ca
 */
defined( 'ABSPATH' ) || die( "Run via wp eval-file\n" );

$pass = defined( 'PT_DEV_DB_PASSWORD' ) ? PT_DEV_DB_PASSWORD : '';
$pdo  = new PDO(
	'mysql:host=35.230.186.163;port=3306;dbname=grantconts;charset=utf8mb4',
	'pt_read', $pass,
	[ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
);

// Fetch all op_member_id values + their start_dates in one query.
$rows = $pdo->query(
	'SELECT id, start_date FROM op_core_electedmember WHERE end_date IS NULL'
)->fetchAll();
$date_map = array_column( $rows, 'start_date', 'id' );  // op_member_id → start_date

global $wpdb;
$posts = $wpdb->get_results(
	"SELECT p.ID, m.meta_value AS op_member_id
	   FROM {$wpdb->posts} p
	   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'op_member_id'
	  WHERE p.post_type = 'member-parliament' AND p.post_status = 'publish'",
	ARRAY_A
);

$updated = 0; $skipped = 0;
foreach ( $posts as $row ) {
	$start = $date_map[ (int) $row['op_member_id'] ] ?? null;
	if ( ! $start ) { $skipped++; continue; }
	$year = (int) substr( (string) $start, 0, 4 );
	update_post_meta( (int) $row['ID'], 'mp_term_start',      $start );
	update_post_meta( (int) $row['ID'], 'mp_term_start_year', $year );
	$updated++;
}

WP_CLI::success( "Updated: {$updated}  Skipped (no op_member_id match): {$skipped}" );
