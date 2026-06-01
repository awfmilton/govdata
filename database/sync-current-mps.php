<?php
/**
 * Sync WordPress member-parliament posts to the authoritative 45th-Parliament
 * roster from op_core_electedmember (end_date IS NULL = currently serving).
 *
 * What this script does:
 *   1. Loads 343 current MPs from the OP database.
 *   2. Matches each to an existing WP post by normalised full name.
 *   3. Updates matched posts: riding, party, province, photo, op_member_id meta.
 *   4. Creates new WP posts for current MPs with no existing post.
 *   5. Sets to DRAFT any published WP posts not matched by a current OP MP
 *      (i.e. former MPs who lost their seat or retired).
 *
 * Run via WP-CLI (dry-run first):
 *   wp eval-file /home/REDACTED_SSH_USER/sync-current-mps.php --path=/home/REDACTED_SSH_USER/govdata.ca
 *
 * Set PT_SYNC_DRY_RUN = false below to commit changes.
 *
 * @package PublicTech\Core
 */

defined( 'ABSPATH' ) || die( "Run via: wp eval-file sync-current-mps.php\n" );

define( 'PT_SYNC_DRY_RUN', false );  // ← flip to false to commit

if ( PT_SYNC_DRY_RUN ) {
	WP_CLI::log( '🔍  DRY RUN — no changes will be written.' );
} else {
	WP_CLI::log( '⚠   LIVE RUN — WordPress posts will be created / updated / drafted.' );
}

// ── Connect to grantconts ─────────────────────────────────────────────────────

$pt_pass = defined( 'PT_DEV_DB_PASSWORD' ) ? PT_DEV_DB_PASSWORD : '';
if ( ! $pt_pass ) {
	WP_CLI::error( 'PT_DEV_DB_PASSWORD not defined. Is 00-db-connections.php loaded?' );
}

try {
	$pdo = new PDO(
		'mysql:host=35.230.186.163;port=3306;dbname=grantconts;charset=utf8mb4',
		'pt_read',
		$pt_pass,
		[ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
	);
} catch ( PDOException $e ) {
	WP_CLI::error( 'grantconts connect failed: ' . $e->getMessage() );
}

// ── Load 343 current MPs from OP ──────────────────────────────────────────────

$op_rows = $pdo->query( "
	SELECT
		em.id            AS op_member_id,
		p.id             AS op_politician_id,
		p.name_given     AS first_name,
		p.name_family    AS last_name,
		p.headshot,
		r.name_en        AS riding,
		r.province,
		pa.name_en       AS party,
		em.start_date
	FROM op_core_electedmember em
	JOIN op_core_politician p  ON p.id  = em.politician_id
	JOIN op_core_riding     r  ON r.id  = em.riding_id
	JOIN op_core_party      pa ON pa.id = em.party_id
	WHERE em.end_date IS NULL
	ORDER BY p.name_family, p.name_given
" )->fetchAll();

WP_CLI::log( sprintf( 'Loaded %d current MPs from op_core_electedmember.', count( $op_rows ) ) );

// Build normalised-name → OP row map.
function pt_norm( string $s ): string {
	return mb_strtolower( html_entity_decode( trim( $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'UTF-8' );
}

$op_by_name = [];
foreach ( $op_rows as $row ) {
	$key = pt_norm( $row['first_name'] . ' ' . $row['last_name'] );
	$op_by_name[ $key ] = $row;
}

// ── Load all published WP member-parliament posts ─────────────────────────────

$wp_posts = get_posts( [
	'post_type'      => 'member-parliament',
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'fields'         => 'ids',
] );

WP_CLI::log( sprintf( 'Found %d published WP member-parliament posts.', count( $wp_posts ) ) );

$matched   = [];   // op_name_key → wp post ID (matched current MP)
$to_draft  = [];   // WP post IDs of former MPs
$updated   = 0;
$created   = 0;
$drafted   = 0;

// ── Pass 1: match existing posts to current MPs ───────────────────────────────

foreach ( $wp_posts as $post_id ) {
	$raw_title = get_post_field( 'post_title', $post_id );
	$key       = pt_norm( $raw_title );

	if ( isset( $op_by_name[ $key ] ) ) {
		$matched[ $key ] = $post_id;
		$op = $op_by_name[ $key ];

		WP_CLI::log( sprintf( '  ✓ match: "%s" → post %d  (%s, %s)',
			$raw_title, $post_id, $op['riding'], $op['party'] ) );

		if ( ! PT_SYNC_DRY_RUN ) {
			// Update post title to canonical OP name.
			wp_update_post( [
				'ID'           => $post_id,
				'post_title'   => trim( $op['first_name'] . ' ' . $op['last_name'] ),
				'post_excerpt' => $op['riding'],
			] );
			// Update / add meta.
			update_post_meta( $post_id, 'pt_mp_district',     $op['riding'] );
			update_post_meta( $post_id, 'op_member_id',       $op['op_member_id'] );
			update_post_meta( $post_id, 'op_politician_id',   $op['op_politician_id'] );
			if ( $op['headshot'] ) {
				update_post_meta( $post_id, 'pt_mp_photo_url',
					'https://cdn.openparliament.ca/thumbnails/' . $op['headshot'] );
			}
			// Taxonomy: party + region.
			$party_term = get_term_by( 'name', $op['party'], 'mp_party' )
				?: wp_insert_term( $op['party'], 'mp_party' );
			if ( ! is_wp_error( $party_term ) ) {
				wp_set_object_terms( $post_id,
					[ is_array( $party_term ) ? $party_term['term_id'] : $party_term->term_id ],
					'mp_party' );
			}
			$region_term = get_term_by( 'name', $op['province'], 'mp_region' )
				?: wp_insert_term( $op['province'], 'mp_region' );
			if ( ! is_wp_error( $region_term ) ) {
				wp_set_object_terms( $post_id,
					[ is_array( $region_term ) ? $region_term['term_id'] : $region_term->term_id ],
					'mp_region' );
			}
		}
		$updated++;
	} else {
		$to_draft[] = $post_id;
	}
}

// ── Pass 2: create posts for current MPs with no existing post ────────────────

$new_keys = array_diff( array_keys( $op_by_name ), array_keys( $matched ) );
WP_CLI::log( '' );
WP_CLI::log( sprintf( '%d current MPs need a new WP post.', count( $new_keys ) ) );

foreach ( $new_keys as $key ) {
	$op    = $op_by_name[ $key ];
	$title = trim( $op['first_name'] . ' ' . $op['last_name'] );

	WP_CLI::log( sprintf( '  + create: "%s"  (%s, %s)', $title, $op['riding'], $op['party'] ) );

	if ( ! PT_SYNC_DRY_RUN ) {
		$post_id = wp_insert_post( [
			'post_type'    => 'member-parliament',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_excerpt' => $op['riding'],
		], true );

		if ( is_wp_error( $post_id ) ) {
			WP_CLI::warning( "  Failed to create post for {$title}: " . $post_id->get_error_message() );
			continue;
		}

		update_post_meta( $post_id, 'pt_mp_district',   $op['riding'] );
		update_post_meta( $post_id, 'op_member_id',     $op['op_member_id'] );
		update_post_meta( $post_id, 'op_politician_id', $op['op_politician_id'] );
		if ( $op['headshot'] ) {
			update_post_meta( $post_id, 'pt_mp_photo_url',
				'https://cdn.openparliament.ca/thumbnails/' . $op['headshot'] );
		}
		$party_term = get_term_by( 'name', $op['party'], 'mp_party' )
			?: wp_insert_term( $op['party'], 'mp_party' );
		if ( ! is_wp_error( $party_term ) ) {
			wp_set_object_terms( $post_id,
				[ is_array( $party_term ) ? $party_term['term_id'] : $party_term->term_id ],
				'mp_party' );
		}
		$region_term = get_term_by( 'name', $op['province'], 'mp_region' )
			?: wp_insert_term( $op['province'], 'mp_region' );
		if ( ! is_wp_error( $region_term ) ) {
			wp_set_object_terms( $post_id,
				[ is_array( $region_term ) ? $region_term['term_id'] : $region_term->term_id ],
				'mp_region' );
		}
	}
	$created++;
}

// ── Pass 3: draft former MPs ──────────────────────────────────────────────────

WP_CLI::log( '' );
WP_CLI::log( sprintf( '%d WP posts are for former MPs and will be set to draft.', count( $to_draft ) ) );

foreach ( $to_draft as $post_id ) {
	$title = get_post_field( 'post_title', $post_id );
	WP_CLI::log( sprintf( '  → draft: "%s" (post %d)', $title, $post_id ) );

	if ( ! PT_SYNC_DRY_RUN ) {
		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
	}
	$drafted++;
}

// ── Summary ───────────────────────────────────────────────────────────────────

WP_CLI::log( '' );
WP_CLI::success( sprintf(
	'%s  Updated: %d  |  Created: %d  |  Drafted (former MPs): %d  |  Expected live total: %d',
	PT_SYNC_DRY_RUN ? '[DRY RUN]' : '[DONE]',
	$updated,
	$created,
	$drafted,
	$updated + $created
) );

if ( PT_SYNC_DRY_RUN ) {
	WP_CLI::log( 'Set PT_SYNC_DRY_RUN = false at the top of the script and re-run to apply.' );
}
