<?php
/**
 * Diagnose MP duplicates in both the grantconts.mps source table and WordPress.
 *
 * Run via WP-CLI from the WordPress root:
 *
 *   wp eval-file /path/to/database/diagnose-mp-dupes.php \
 *       --pass=YOUR_PT_READ_PASSWORD
 *
 * Outputs:
 *   1. Source DB stats: total rows / unique IDs / unique names / dupes
 *   2. WordPress stats: total posts / with external_mp_id / without / slug-duped names
 *   3. Cross-check: names that have multiple WP posts, grouped with their mp_ids
 */

defined( 'ABSPATH' ) || die( "Run via: wp eval-file diagnose-mp-dupes.php\n" );

// ── Credentials ───────────────────────────────────────────────────────────────

function get_arg( string $key, string $default = '' ): string {
	foreach ( $GLOBALS['argv'] ?? [] as $arg ) {
		if ( str_starts_with( $arg, "--{$key}=" ) ) {
			return substr( $arg, strlen( "--{$key}=" ) );
		}
	}
	return $default;
}

$db_host = get_arg( 'host',   '35.230.186.163' );
$db_name = get_arg( 'dbname', 'grantconts' );
$db_user = get_arg( 'user',   'pt_read' );
$db_pass = get_arg( 'pass',   defined( 'PT_DEV_DB_PASSWORD' ) ? PT_DEV_DB_PASSWORD : '' );
$db_port = (int) get_arg( 'port', '3306' );

if ( ! $db_pass ) {
	WP_CLI::error( 'Pass the database password via --pass=YOUR_PASSWORD' );
}

// ── Connect to grantconts ─────────────────────────────────────────────────────

try {
	$dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
	$pdo = new PDO( $dsn, $db_user, $db_pass, [
		PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	] );
} catch ( PDOException $e ) {
	WP_CLI::error( 'Source DB connection failed: ' . $e->getMessage() );
}

// ═══════════════════════════════════════════════════════════════════════════════
// 1. SOURCE DB — grantconts.mps
// ═══════════════════════════════════════════════════════════════════════════════

WP_CLI::log( '' );
WP_CLI::log( '══ 1. SOURCE: grantconts.mps ══════════════════════════════════════' );

$totals = $pdo->query( '
	SELECT
		COUNT(*)                                            AS total_rows,
		COUNT(DISTINCT mp_id)                               AS unique_ids,
		COUNT(DISTINCT CONCAT(first_name, " ", last_name)) AS unique_names
	FROM `mps`
' )->fetch();

WP_CLI::log( sprintf( '  Total rows    : %d', $totals['total_rows'] ) );
WP_CLI::log( sprintf( '  Unique mp_ids : %d', $totals['unique_ids'] ) );
WP_CLI::log( sprintf( '  Unique names  : %d', $totals['unique_names'] ) );

$name_dupes = $pdo->query( '
	SELECT
		CONCAT(first_name, " ", last_name) AS name,
		COUNT(*)                           AS cnt,
		GROUP_CONCAT(mp_id ORDER BY mp_id SEPARATOR ", ") AS mp_ids,
		GROUP_CONCAT(DISTINCT province ORDER BY province SEPARATOR ", ")  AS provinces
	FROM `mps`
	GROUP BY first_name, last_name
	HAVING cnt > 1
	ORDER BY cnt DESC
	LIMIT 30
' )->fetchAll();

if ( $name_dupes ) {
	WP_CLI::log( '' );
	WP_CLI::log( sprintf( '  Names with > 1 row (%d shown, max 30):', count( $name_dupes ) ) );
	WP_CLI::log( sprintf( '  %-35s %5s  %-40s  %s', 'NAME', 'CNT', 'mp_ids', 'provinces' ) );
	WP_CLI::log( '  ' . str_repeat( '-', 100 ) );
	foreach ( $name_dupes as $row ) {
		WP_CLI::log( sprintf( '  %-35s %5d  %-40s  %s',
			substr( $row['name'], 0, 35 ),
			$row['cnt'],
			substr( $row['mp_ids'], 0, 40 ),
			$row['provinces']
		) );
	}
} else {
	WP_CLI::success( '  No duplicate names in grantconts.mps — source is clean.' );
}

// ═══════════════════════════════════════════════════════════════════════════════
// 2. WORDPRESS — member-parliament posts
// ═══════════════════════════════════════════════════════════════════════════════

WP_CLI::log( '' );
WP_CLI::log( '══ 2. WORDPRESS: member-parliament posts ══════════════════════════' );

global $wpdb;

$wp_total = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='member-parliament' AND post_status='publish'"
);
$wp_with_ext_id = (int) $wpdb->get_var(
	"SELECT COUNT(DISTINCT p.ID)
	   FROM {$wpdb->posts} p
	   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'external_mp_id' AND m.meta_value != ''
	  WHERE p.post_type = 'member-parliament' AND p.post_status = 'publish'"
);
$wp_without_ext_id = $wp_total - $wp_with_ext_id;

WP_CLI::log( sprintf( '  Total published posts    : %d', $wp_total ) );
WP_CLI::log( sprintf( '  Posts WITH external_mp_id: %d', $wp_with_ext_id ) );
WP_CLI::log( sprintf( '  Posts WITHOUT            : %d', $wp_without_ext_id ) );

// Names with more than one post
$wp_name_dupes = $wpdb->get_results(
	"SELECT p.post_title AS name,
	        COUNT(*) AS cnt,
	        GROUP_CONCAT(p.ID ORDER BY p.ID SEPARATOR ', ') AS post_ids,
	        GROUP_CONCAT(m.meta_value ORDER BY p.ID SEPARATOR ', ') AS ext_ids
	   FROM {$wpdb->posts} p
	   LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'external_mp_id'
	  WHERE p.post_type = 'member-parliament' AND p.post_status = 'publish'
	  GROUP BY p.post_title
	  HAVING cnt > 1
	  ORDER BY cnt DESC
	  LIMIT 30",
	ARRAY_A
);

if ( $wp_name_dupes ) {
	WP_CLI::log( '' );
	WP_CLI::log( sprintf( '  WP names with > 1 post (%d shown, max 30):', count( $wp_name_dupes ) ) );
	WP_CLI::log( sprintf( '  %-35s %5s  %-30s  %s', 'NAME', 'CNT', 'post_ids', 'ext_mp_ids' ) );
	WP_CLI::log( '  ' . str_repeat( '-', 110 ) );
	foreach ( $wp_name_dupes as $row ) {
		WP_CLI::log( sprintf( '  %-35s %5d  %-30s  %s',
			substr( $row['name'], 0, 35 ),
			$row['cnt'],
			substr( $row['post_ids'], 0, 30 ),
			substr( $row['ext_ids'] ?? '', 0, 40 )
		) );
	}
}

// external_mp_id values that appear on more than one post
$ext_id_dupes = $wpdb->get_results(
	"SELECT m.meta_value AS ext_mp_id,
	        COUNT(*) AS cnt,
	        GROUP_CONCAT(m.post_id ORDER BY m.post_id SEPARATOR ', ') AS post_ids
	   FROM {$wpdb->postmeta} m
	   JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'member-parliament' AND p.post_status = 'publish'
	  WHERE m.meta_key = 'external_mp_id' AND m.meta_value != ''
	  GROUP BY m.meta_value
	  HAVING cnt > 1
	  ORDER BY cnt DESC
	  LIMIT 20",
	ARRAY_A
);

if ( $ext_id_dupes ) {
	WP_CLI::log( '' );
	WP_CLI::log( sprintf( '  external_mp_id values shared by multiple WP posts (%d):', count( $ext_id_dupes ) ) );
	foreach ( $ext_id_dupes as $row ) {
		WP_CLI::log( sprintf( '    ext_mp_id=%-10s  cnt=%d  post_ids=%s',
			$row['ext_mp_id'], $row['cnt'], $row['post_ids'] ) );
	}
} else {
	WP_CLI::log( '' );
	WP_CLI::log( '  No external_mp_id value is shared by multiple posts.' );
}

// ═══════════════════════════════════════════════════════════════════════════════
// 3. CROSS-CHECK: which source mp_ids map to more than one WP post
// ═══════════════════════════════════════════════════════════════════════════════

WP_CLI::log( '' );
WP_CLI::log( '══ 3. CROSS-CHECK: source mp_ids not in WP (or over-represented) ═' );

$source_ids = array_column( $pdo->query( 'SELECT mp_id FROM `mps`' )->fetchAll(), 'mp_id' );
$source_count = count( $source_ids );

// Build a map of ext_id → WP post count
$placeholders = implode( ',', array_fill( 0, $source_count, '%s' ) );
$wp_id_counts = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT m.meta_value AS ext_mp_id, COUNT(*) AS cnt
		   FROM {$wpdb->postmeta} m
		   JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'member-parliament' AND p.post_status = 'publish'
		  WHERE m.meta_key = 'external_mp_id'
		  GROUP BY m.meta_value",
	),
	ARRAY_A
);
$ext_map = array_column( $wp_id_counts, 'cnt', 'ext_mp_id' );

$missing  = 0;
$over     = 0;
$over_ids = [];
foreach ( $source_ids as $sid ) {
	$cnt = (int) ( $ext_map[ $sid ] ?? 0 );
	if ( $cnt === 0 ) {
		$missing++;
	} elseif ( $cnt > 1 ) {
		$over++;
		$over_ids[] = $sid;
	}
}

WP_CLI::log( sprintf( '  Source mp_ids with NO matching WP post : %d', $missing ) );
WP_CLI::log( sprintf( '  Source mp_ids with >1 matching WP post : %d', $over ) );
if ( $over_ids ) {
	WP_CLI::log( '  Over-represented mp_ids: ' . implode( ', ', array_slice( $over_ids, 0, 20 ) ) );
}

// Total WP posts that have NO external_mp_id at all
$wp_posts_no_meta = $wpdb->get_col(
	"SELECT p.ID
	   FROM {$wpdb->posts} p
	   LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'external_mp_id'
	  WHERE p.post_type = 'member-parliament' AND p.post_status = 'publish'
	    AND m.meta_id IS NULL
	  ORDER BY p.ID"
);

if ( $wp_posts_no_meta ) {
	WP_CLI::warning( sprintf(
		'  %d WP posts have NO external_mp_id meta at all (orphans — cannot be deduped by ID).',
		count( $wp_posts_no_meta )
	) );
	WP_CLI::log( '  First 10 orphan post IDs: ' . implode( ', ', array_slice( $wp_posts_no_meta, 0, 10 ) ) );
}

WP_CLI::log( '' );
WP_CLI::success( 'Diagnosis complete. Run dedup-mp-posts.php to clean up.' );
