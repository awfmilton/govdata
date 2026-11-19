<?php
/**
 * WP-CLI: sync member-parliament posts with the sitting House of Commons.
 *
 * Authoritative roster: the House of Commons member list (https://www.ourcommons.ca/members/en/search/xml),
 * cross-checked with op_core_electedmember (end_date IS NULL) in grantconts. The two must agree on the count;
 * the script stops otherwise.
 *
 * Matching is by openparliament politician id (post meta op_politician_id), never by name.
 *   - Sitting MP with a published post: refresh riding, party, region and IDs. The photo is NOT touched
 *     (portraits are self-hosted by sync-mp-photos.php).
 *   - Sitting MP with no post: create it, with the official House of Commons photo URL as pt_mp_photo_url
 *     (run sync-mp-photos.php afterwards to self-host it).
 *   - Published post for someone no longer sitting: set to draft (the site's "former MP" state, see
 *     single-member-parliament.php) and record mp_term_end. Nothing is deleted.
 * Every change is written to the post's pt_mp_sync_log meta so it can be audited.
 *
 * Usage (WP-CLI, from the WordPress root):
 *   wp eval-file database/sync-current-mps.php            dry run (default)
 *   wp eval-file database/sync-current-mps.php apply      write changes
 * DB connection: GRANTCONTS_HOST / _USER / _PASS environment variables, else the pt-grantconts-01 entry of
 * PUBLICTECH_DB_CONNECTIONS in wp-config.php.
 *
 * @package PublicTech
 */

defined( 'ABSPATH' ) || die( "Run via: wp eval-file sync-current-mps.php\n" );

$apply = in_array( 'apply', (array) ( $args ?? [] ), true );
WP_CLI::log( $apply ? 'APPLY: posts will be created / updated / drafted.' : 'DRY RUN: no changes are written (pass "apply" to write).' );

// ── grantconts ────────────────────────────────────────────────────────────────
$cfg = defined( 'PUBLICTECH_DB_CONNECTIONS' ) ? ( PUBLICTECH_DB_CONNECTIONS['pt-grantconts-01'] ?? [] ) : [];
$dsn = sprintf( 'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
	getenv( 'GRANTCONTS_HOST' ) ?: ( $cfg['host'] ?? '127.0.0.1' ), (int) ( $cfg['port'] ?? 3306 ), $cfg['name'] ?? 'grantconts' );
try {
	$pdo = new PDO( $dsn, getenv( 'GRANTCONTS_USER' ) ?: ( $cfg['user'] ?? 'pt_read' ), getenv( 'GRANTCONTS_PASS' ) ?: ( $cfg['pass'] ?? '' ),
		[ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ] );
} catch ( PDOException $e ) {
	WP_CLI::error( 'grantconts connect failed: ' . $e->getMessage() );
}

$op_rows = $pdo->query( "
	SELECT em.politician_id AS pol, em.id AS op_member_id, p.name_given AS first_name, p.name_family AS last_name,
	       r.name_en AS riding, r.province, pa.name_en AS party, em.start_date
	  FROM op_core_electedmember em
	  JOIN op_core_politician p  ON p.id  = em.politician_id
	  JOIN op_core_riding     r  ON r.id  = em.riding_id
	  JOIN op_core_party      pa ON pa.id = em.party_id
	 WHERE em.end_date IS NULL" )->fetchAll();
$current = array_column( $op_rows, null, 'pol' );

// ── House of Commons member list (official) ────────────────────────────────────
$res = wp_remote_get( 'https://www.ourcommons.ca/members/en/search/xml', [ 'timeout' => 60 ] );
if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
	WP_CLI::error( 'Could not fetch the House of Commons member list.' );
}
$xml = simplexml_load_string( wp_remote_retrieve_body( $res ) );
$hoc = [];
foreach ( $xml->MemberOfParliament as $m ) {
	$hoc[] = [
		'person_id' => (string) $m->PersonId,
		'first'     => (string) $m->PersonOfficialFirstName,
		'last'      => (string) $m->PersonOfficialLastName,
		'riding'    => (string) $m->ConstituencyName,
	];
}
if ( count( $hoc ) !== count( $current ) ) {
	WP_CLI::error( sprintf( 'Roster mismatch: House of Commons lists %d sitting MPs, op_core_electedmember %d. Refresh the parliament data first.', count( $hoc ), count( $current ) ) );
}
WP_CLI::log( sprintf( 'Sitting MPs: %d (House of Commons and openparliament agree).', count( $hoc ) ) );

$norm = static fn( string $s ): string => strtolower( remove_accents( preg_replace( [ '/[\s\x{2014}\x{2013}-]+/u', '/[\x{2018}\x{2019}]/u' ], [ ' ', "'" ], html_entity_decode( $s, ENT_QUOTES, 'UTF-8' ) ) ) );
$hoc_by_riding = [];
foreach ( $hoc as $h ) {
	$hoc_by_riding[ $norm( $h['riding'] ) ] = $h;
}

/** Official portrait URL from the member's House of Commons profile page. */
$hoc_photo = static function ( string $person_id ): string {
	$r = wp_remote_get( 'https://www.ourcommons.ca/members/en/' . rawurlencode( $person_id ), [ 'timeout' => 30 ] );
	if ( is_wp_error( $r ) || ! preg_match( '#/Content/Parliamentarians/Images/OfficialMPPhotos/[^"\']+\.(?:jpg|jpeg|png)#i', wp_remote_retrieve_body( $r ), $m ) ) {
		return '';
	}
	return 'https://www.ourcommons.ca' . $m[0];
};

$log = static function ( int $post_id, string $what ) use ( $apply ): void {
	if ( $apply ) {
		add_post_meta( $post_id, 'pt_mp_sync_log', gmdate( 'Y-m-d' ) . ' sync-current-mps: ' . $what );
	}
};

$set_terms = static function ( int $post_id, array $op ): void {
	foreach ( [ 'mp_party' => $op['party'], 'mp_region' => $op['province'] ] as $tax => $name ) {
		$term = get_term_by( 'name', $name, $tax ) ?: wp_insert_term( $name, $tax );
		if ( ! is_wp_error( $term ) ) {
			wp_set_object_terms( $post_id, [ (int) ( is_array( $term ) ? $term['term_id'] : $term->term_id ) ], $tax );
		}
	}
};

// ── Existing posts by politician id ───────────────────────────────────────────
$posts = get_posts( [ 'post_type' => 'member-parliament', 'post_status' => [ 'publish', 'draft' ], 'numberposts' => -1, 'fields' => 'ids' ] );
$by_pol = [];
foreach ( $posts as $id ) {
	$pol = (int) get_post_meta( $id, 'op_politician_id', true );
	if ( $pol ) {
		$by_pol[ $pol ][] = $id;
	}
}

$updated = $created = $drafted = 0;

// Sitting MPs: refresh or create.
foreach ( $current as $pol => $op ) {
	$hoc_m = $hoc_by_riding[ $norm( $op['riding'] ) ] ?? null;
	if ( ! $hoc_m ) {
		WP_CLI::warning( sprintf( 'No House of Commons member for riding "%s" (%s %s); skipped.', $op['riding'], $op['first_name'], $op['last_name'] ) );
		continue;
	}
	$ids = $by_pol[ $pol ] ?? [];
	if ( count( $ids ) > 1 ) {
		WP_CLI::warning( sprintf( 'Politician %d has %d posts (%s); using the published one.', $pol, count( $ids ), implode( ',', $ids ) ) );
	}
	$pub = array_values( array_filter( $ids, static fn( $i ) => 'publish' === get_post_status( $i ) ) );
	$id  = $pub[0] ?? ( $ids[0] ?? 0 );
	$title = trim( $op['first_name'] . ' ' . $op['last_name'] );

	if ( $id ) {
		$changes = [];
		if ( get_post_meta( $id, 'pt_mp_district', true ) !== $op['riding'] ) {
			$changes[] = 'riding ' . get_post_meta( $id, 'pt_mp_district', true ) . ' -> ' . $op['riding'];
		}
		if ( 'publish' !== get_post_status( $id ) ) {
			$changes[] = 'republished (sitting again)';
		}
		if ( $apply ) {
			if ( 'publish' !== get_post_status( $id ) ) {
				wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
			}
			update_post_meta( $id, 'pt_mp_district', $op['riding'] );
			update_post_meta( $id, 'op_member_id', $op['op_member_id'] );
			update_post_meta( $id, 'pt_hoc_person_id', $hoc_m['person_id'] );
			delete_post_meta( $id, 'mp_term_end' );
			$set_terms( $id, $op );
		}
		if ( $changes ) {
			WP_CLI::log( sprintf( '  ~ %s (post %d): %s', $title, $id, implode( '; ', $changes ) ) );
			$log( $id, implode( '; ', $changes ) );
		}
		$updated++;
		continue;
	}

	$photo = $hoc_photo( $hoc_m['person_id'] );
	WP_CLI::log( sprintf( '  + create: %s (%s, %s, since %s) photo: %s', $title, $op['riding'], $op['party'], $op['start_date'], $photo ?: 'none found' ) );
	if ( $apply ) {
		$id = wp_insert_post( [ 'post_type' => 'member-parliament', 'post_status' => 'publish', 'post_title' => $title, 'post_excerpt' => $op['riding'] ], true );
		if ( is_wp_error( $id ) ) {
			WP_CLI::warning( "  Failed to create {$title}: " . $id->get_error_message() );
			continue;
		}
		update_post_meta( $id, 'pt_mp_district', $op['riding'] );
		update_post_meta( $id, 'op_member_id', $op['op_member_id'] );
		update_post_meta( $id, 'op_politician_id', $pol );
		update_post_meta( $id, 'pt_hoc_person_id', $hoc_m['person_id'] );
		update_post_meta( $id, 'mp_term_start', $op['start_date'] );
		update_post_meta( $id, 'mp_term_start_year', substr( (string) $op['start_date'], 0, 4 ) );
		update_post_meta( $id, 'votes_cast', 0 );
		if ( $photo ) {
			update_post_meta( $id, 'pt_mp_photo_url', $photo );
		}
		$set_terms( $id, $op );
		$log( $id, 'created (sitting since ' . $op['start_date'] . ')' );
	}
	$created++;
}

// Published posts for people no longer sitting: draft (former MP), record the end date.
foreach ( $posts as $id ) {
	if ( 'publish' !== get_post_status( $id ) ) {
		continue;
	}
	$pol = (int) get_post_meta( $id, 'op_politician_id', true );
	if ( $pol && isset( $current[ $pol ] ) ) {
		continue;
	}
	$end = $pol ? (string) $pdo->query( 'SELECT MAX(end_date) FROM op_core_electedmember WHERE politician_id = ' . $pol )->fetchColumn() : '';
	WP_CLI::log( sprintf( '  -> former: %s (post %d), seat ended %s', get_the_title( $id ), $id, $end ?: 'unknown' ) );
	if ( $apply ) {
		wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] );
		if ( $end ) {
			update_post_meta( $id, 'mp_term_end', $end );
		}
		$log( $id, 'no longer sitting (seat ended ' . ( $end ?: 'unknown' ) . '); set to former' );
	}
	$drafted++;
}

WP_CLI::success( sprintf( '%s refreshed %d | created %d | former %d | sitting MPs %d',
	$apply ? '[APPLIED]' : '[DRY RUN]', $updated, $created, $drafted, count( $current ) ) );
