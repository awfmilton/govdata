<?php
/**
 * WP-CLI: set `votes_cast` post meta on every member-parliament post.
 *
 * "Votes cast" = House of Commons recorded divisions in which the member voted Yea or Nay
 * (OpenParliament op_bills_membervote.vote IN ('Y','N'); absent 'A' and paired 'P' are not votes cast).
 * Link chain: post meta external_mp_id → grantconts.mps.mp_id → mps.mp_persons_id →
 * mp_persons.op_politician_id → op_bills_membervote.politician_id.
 * Members with no linked OpenParliament politician get 0 and are listed so they can be fixed.
 *
 * Usage:  wp eval-file database/sync-mp-votes.php            (writes)
 *         wp eval-file database/sync-mp-votes.php dry-run    (reports only)
 *
 * @package PublicTech
 */

defined( 'ABSPATH' ) || exit;

$dry = in_array( 'dry-run', (array) ( $args ?? [] ), true );
$cfg = PUBLICTECH_DB_CONNECTIONS['pt-grantconts-01'] ?? null;
if ( ! $cfg ) {
	WP_CLI::error( 'pt-grantconts-01 is not defined in PUBLICTECH_DB_CONNECTIONS.' );
}
$opts = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ];
if ( ! empty( $cfg['ssl_ca'] ) ) {
	$opts[ PDO::MYSQL_ATTR_SSL_CA ]                 = $cfg['ssl_ca'];
	$opts[ PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT ] = true;
}
$db = new PDO( sprintf( 'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'] ?? 3306, $cfg['name'] ),
	$cfg['user'], $cfg['pass'], $opts );

// One query: votes cast per external MP id.
$rows = $db->query(
	"SELECT m.mp_id, p.op_politician_id, COUNT(v.id) AS votes
	   FROM mps m
	   LEFT JOIN mp_persons p ON p.id = m.mp_persons_id
	   LEFT JOIN op_bills_membervote v ON v.politician_id = p.op_politician_id AND v.vote IN ('Y','N')
	  GROUP BY m.mp_id, p.op_politician_id"
)->fetchAll( PDO::FETCH_ASSOC );
$by_mp = [];
foreach ( $rows as $r ) {
	$by_mp[ (string) $r['mp_id'] ] = [ 'votes' => (int) $r['votes'], 'linked' => null !== $r['op_politician_id'] ];
}

$posts    = get_posts( [ 'post_type' => 'member-parliament', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] );
$set      = 0;
$unlinked = [];
foreach ( $posts as $pid ) {
	$ext = (string) get_post_meta( $pid, 'external_mp_id', true );
	$hit = $by_mp[ $ext ] ?? null;
	if ( ! $hit || ! $hit['linked'] ) {
		$unlinked[] = $pid . ' ' . get_the_title( $pid ) . ' (' . ( $ext ?: 'no external_mp_id' ) . ')';
	}
	$votes = $hit['votes'] ?? 0;
	if ( ! $dry ) {
		update_post_meta( $pid, 'votes_cast', $votes );
	}
	++$set;
}
WP_CLI::log( sprintf( '%s votes_cast on %d member posts; %d not linked to an OpenParliament politician.',
	$dry ? 'Would set' : 'Set', $set, count( $unlinked ) ) );
foreach ( array_slice( $unlinked, 0, 40 ) as $u ) {
	WP_CLI::log( '  unlinked: ' . $u );
}
