<?php
/**
 * Remove duplicate member-parliament WordPress posts caused by HIST- prefixed
 * historical records being merged into grantconts.mps alongside current MP-
 * prefixed records before the temporal data model (Phase 3) was built.
 *
 * Strategy (per unique normalised name):
 *   1. If any post has an external_mp_id starting with "MP" → keep that one.
 *   2. Otherwise (only HIST- records exist for this person) → keep the post
 *      with the LOWEST post ID (earliest import).
 *   3. Trash everything else.
 *
 * Expected result: 555 posts → 394 (one per unique name in grantconts.mps).
 *
 * Run via WP-CLI from the WordPress root:
 *
 *   # Preview — no changes:
 *   wp eval-file /path/to/database/dedup-mp-posts.php --dry-run --path=/…
 *
 *   # Commit:
 *   wp eval-file /path/to/database/dedup-mp-posts.php --path=/…
 *
 * Posts are moved to TRASH, not permanently deleted.
 * Verify in wp-admin → Posts → Trash, then empty trash when satisfied.
 */

defined( 'ABSPATH' ) || die( "Run via: wp eval-file dedup-mp-posts.php\n" );

/**
 * Set to true to preview without making any changes.
 * Set to false to commit the deletions.
 */
define( 'PT_DEDUP_DRY_RUN', false );

$dry_run = PT_DEDUP_DRY_RUN;

WP_CLI::log( $dry_run
	? '🔍  DRY RUN — no posts will be changed.'
	: '⚠   LIVE RUN — duplicates will be moved to trash.'
);
WP_CLI::log( '' );

global $wpdb;

// ── Fetch all published member-parliament posts with their external_mp_id ────

$all = $wpdb->get_results(
	"SELECT p.ID          AS post_id,
	        p.post_title  AS title,
	        m.meta_value  AS ext_id
	   FROM {$wpdb->posts} p
	   LEFT JOIN {$wpdb->postmeta} m
	             ON m.post_id = p.ID AND m.meta_key = 'external_mp_id'
	  WHERE p.post_type   = 'member-parliament'
	    AND p.post_status = 'publish'
	  ORDER BY p.ID ASC",
	ARRAY_A
);

WP_CLI::log( sprintf( 'Loaded %d published member-parliament posts.', count( $all ) ) );

// ── Group by normalised title ─────────────────────────────────────────────────
// Decode HTML entities so "Ya&#8217;ara Saks" and "Ya'ara Saks" collapse into
// the same group.

$groups = [];   // normalised_name → [ ['post_id'=>int, 'ext_id'=>string], … ]

foreach ( $all as $row ) {
	$norm = mb_strtolower( html_entity_decode( (string) $row['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'UTF-8' );
	$groups[ $norm ][] = [
		'post_id' => (int) $row['post_id'],
		'ext_id'  => (string) ( $row['ext_id'] ?? '' ),
	];
}

$unique_names = count( $groups );
WP_CLI::log( sprintf( 'Unique names (normalised): %d', $unique_names ) );
WP_CLI::log( '' );

// ── Decide what to keep and what to trash ────────────────────────────────────

$to_trash  = [];
$kept_info = [];
$dup_groups = 0;

foreach ( $groups as $name => $entries ) {
	if ( count( $entries ) === 1 ) {
		// No duplicates — nothing to do.
		continue;
	}

	$dup_groups++;

	// Prefer the entry whose ext_id starts with "MP" (current Parliament record).
	$mp_record = null;
	foreach ( $entries as $e ) {
		if ( str_starts_with( $e['ext_id'], 'MP' ) ) {
			$mp_record = $e;
			break;
		}
	}

	$keep = $mp_record ?? $entries[0];   // entries[0] has the lowest post ID (ORDER BY p.ID ASC)

	$kept_info[] = [
		'name'    => $name,
		'keep_id' => $keep['post_id'],
		'ext_id'  => $keep['ext_id'],
		'trashed' => array_column(
			array_filter( $entries, fn( $e ) => $e['post_id'] !== $keep['post_id'] ),
			'post_id'
		),
	];

	foreach ( $entries as $e ) {
		if ( $e['post_id'] !== $keep['post_id'] ) {
			$to_trash[] = $e['post_id'];
		}
	}
}

WP_CLI::log( sprintf( 'Duplicate groups found: %d', $dup_groups ) );
WP_CLI::log( sprintf( 'Posts queued for trash:  %d', count( $to_trash ) ) );
WP_CLI::log( sprintf( 'Posts to remain:         %d', $unique_names ) );
WP_CLI::log( '' );

// Print the dedup plan (always shown, even in live mode).
WP_CLI::log( sprintf( '%-40s  %7s  %-12s  %s', 'NAME', 'KEEP_ID', 'EXT_ID', 'TRASH_IDS' ) );
WP_CLI::log( str_repeat( '-', 110 ) );
foreach ( $kept_info as $info ) {
	WP_CLI::log( sprintf( '%-40s  %7d  %-12s  %s',
		substr( $info['name'],    0, 40 ),
		$info['keep_id'],
		substr( $info['ext_id'], 0, 12 ),
		implode( ', ', $info['trashed'] )
	) );
}

WP_CLI::log( '' );

if ( $dry_run ) {
	WP_CLI::success( 'Dry run complete — rerun without --dry-run to apply.' );
	return;
}

if ( count( $to_trash ) === 0 ) {
	WP_CLI::success( 'Nothing to do.' );
	return;
}

// ── Execute ───────────────────────────────────────────────────────────────────

$progress = WP_CLI\Utils\make_progress_bar( 'Trashing duplicates', count( $to_trash ) );
$trashed  = 0;
$failed   = 0;

foreach ( $to_trash as $post_id ) {
	// false = move to trash (not permanent delete).
	if ( wp_delete_post( $post_id, false ) ) {
		$trashed++;
	} else {
		WP_CLI::warning( "Could not trash post ID {$post_id}" );
		$failed++;
	}
	$progress->tick();
}

$progress->finish();

WP_CLI::log( '' );
WP_CLI::success( sprintf(
	'Done. Trashed: %d  Failed: %d  Remaining: %d',
	$trashed,
	$failed,
	$unique_names
) );

WP_CLI::log( '' );
WP_CLI::log( 'Next steps:' );
WP_CLI::log( '  1. Verify the directory at /directory/ — should show ' . $unique_names . ' MPs.' );
WP_CLI::log( '  2. wp cache flush   (if object cache is active)' );
WP_CLI::log( '  3. Empty the trash when satisfied: wp post delete $(wp post list --post_type=member-parliament --post_status=trash --format=ids) --force' );
