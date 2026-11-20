<?php
/**
 * One-off: populate mp_term_start post meta on every member-parliament post
 * that has an op_member_id meta, by reading start_date from op_core_electedmember.
 *
 * wp eval-file /home/REDACTED_SSH_USER/populate-mp-term-start.php --path=/home/REDACTED_SSH_USER/govdata.ca
 */
defined( 'ABSPATH' ) || die( "Run via wp eval-file\n" );

// The site's own grantconts connection (wp-config PUBLICTECH_DB_CONNECTIONS), never a hard-coded host: the old one
// (35.230.186.163, the lost Cloud SQL IP) could send credentials to a stranger.
$cfg = defined( 'PUBLICTECH_DB_CONNECTIONS' ) ? ( PUBLICTECH_DB_CONNECTIONS['pt-grantconts-01'] ?? null ) : null;
if ( ! $cfg || empty( $cfg['host'] ) ) {
	die( "pt-grantconts-01 is not configured in PUBLICTECH_DB_CONNECTIONS.\n" );
}
$pdo = new PDO(
	sprintf( 'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'] ?? 3306, $cfg['name'] ),
	$cfg['user'], $cfg['pass'],
	[ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
		+ ( empty( $cfg['ssl_ca'] ) ? [] : [ PDO::MYSQL_ATTR_SSL_CA => $cfg['ssl_ca'], PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true ] )
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
