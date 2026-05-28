<?php
/**
 * One-off MP importer for the grantconts database.
 *
 * Run from the WordPress root with WP-CLI:
 *
 *   wp eval-file /path/to/database/import-mps.php \
 *       --host=35.230.186.163 \
 *       --dbname=grantconts \
 *       --user=pt_read \
 *       --pass=YOUR_PT_READ_PASSWORD
 *
 * The script is idempotent — existing posts are updated, not duplicated.
 * It matches on the `external_mp_id` meta key (the `mp_id` from the DB).
 *
 * What gets created / updated per MP:
 *   post_type   member-parliament
 *   post_title  First Last
 *   post_status publish
 *   post_excerpt district_name
 *   meta        external_mp_id, pt_mp_email, pt_mp_photo_url,
 *               pt_mp_postal_code, pt_mp_phone, pt_mp_fax,
 *               pt_mp_gender, pt_mp_district, pt_mp_ottawa_office,
 *               pt_mp_ottawa_phone, pt_mp_source_url
 *   taxonomy    mp_party  (party name)
 *   taxonomy    mp_region (province code)
 */

defined( 'ABSPATH' ) || die( "Run via: wp eval-file import-mps.php\n" );

// ── Parse CLI args ────────────────────────────────────────────────────────────

$args = WP_CLI::get_runner()->arguments ?? [];

function get_arg( string $key, string $default = '' ): string {
	global $args;
	foreach ( $GLOBALS['argv'] ?? [] as $arg ) {
		if ( str_starts_with( $arg, "--{$key}=" ) ) {
			return substr( $arg, strlen( "--{$key}=" ) );
		}
	}
	return $default;
}

$db_host  = get_arg( 'host',   '35.230.186.163' );
$db_name  = get_arg( 'dbname', 'grantconts' );
$db_user  = get_arg( 'user',   'pt_read' );
$db_pass  = get_arg( 'pass',   '' );
$db_port  = (int) get_arg( 'port', '3306' );

if ( ! $db_pass ) {
	WP_CLI::error( 'Pass the database password via --pass=YOUR_PASSWORD' );
}

// ── Connect ───────────────────────────────────────────────────────────────────

try {
	$dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
	$pdo = new PDO( $dsn, $db_user, $db_pass, [
		PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY',
	] );
} catch ( PDOException $e ) {
	WP_CLI::error( 'DB connection failed: ' . $e->getMessage() );
}

// ── Fetch MPs ─────────────────────────────────────────────────────────────────

$rows = $pdo->query( 'SELECT * FROM `mps` ORDER BY `last_name`, `first_name`' )->fetchAll();
$total = count( $rows );
WP_CLI::log( "Found {$total} MPs in grantconts.mps" );

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Find an existing member-parliament post by external_mp_id.
 */
function find_mp_post( string $mp_id ): int {
	$posts = get_posts( [
		'post_type'      => 'member-parliament',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'meta_key'       => 'external_mp_id',
		'meta_value'     => $mp_id,
		'fields'         => 'ids',
	] );
	return $posts[0] ?? 0;
}

/**
 * Ensure a term exists in a taxonomy and return its ID.
 */
function ensure_term( string $name, string $taxonomy ): int {
	if ( ! $name ) {
		return 0;
	}
	$term = get_term_by( 'name', $name, $taxonomy );
	if ( $term ) {
		return $term->term_id;
	}
	$result = wp_insert_term( $name, $taxonomy );
	return is_wp_error( $result ) ? 0 : $result['term_id'];
}

// ── Import loop ───────────────────────────────────────────────────────────────

$progress = WP_CLI\Utils\make_progress_bar( 'Importing MPs', $total );
$created  = 0;
$updated  = 0;
$failed   = 0;

foreach ( $rows as $row ) {
	$mp_id      = trim( (string) $row['mp_id'] );
	$first      = trim( (string) ( $row['first_name'] ?? '' ) );
	$last       = trim( (string) ( $row['last_name']  ?? '' ) );
	$title      = trim( "{$first} {$last}" ) ?: $mp_id;
	$district   = trim( (string) ( $row['district_name'] ?? '' ) );
	$party      = trim( (string) ( $row['party']         ?? '' ) );
	$province   = strtoupper( trim( (string) ( $row['province'] ?? '' ) ) );

	$post_data = [
		'post_type'    => 'member-parliament',
		'post_status'  => 'publish',
		'post_title'   => $title,
		'post_excerpt' => $district,
	];

	$existing_id = find_mp_post( $mp_id );

	if ( $existing_id ) {
		$post_data['ID'] = $existing_id;
		$post_id = wp_update_post( $post_data, true );
		$updated++;
	} else {
		$post_id = wp_insert_post( $post_data, true );
		$created++;
	}

	if ( is_wp_error( $post_id ) ) {
		WP_CLI::warning( "Failed for {$mp_id} ({$title}): " . $post_id->get_error_message() );
		$failed++;
		$progress->tick();
		continue;
	}

	// ── Meta ─────────────────────────────────────────────────────────────────

	$meta = [
		'external_mp_id'       => $mp_id,
		'pt_mp_district'       => $district,
		'pt_mp_gender'         => trim( (string) ( $row['gender']        ?? '' ) ),
		'pt_mp_email'          => trim( (string) ( $row['email']         ?? '' ) ),
		'pt_mp_photo_url'      => trim( (string) ( $row['photo_url']     ?? '' ) ),
		'pt_mp_source_url'     => trim( (string) ( $row['source_url']    ?? '' ) ),
		'pt_mp_postal_code'    => trim( (string) ( $row['postal_code']   ?? '' ) ),
		'pt_mp_phone'          => trim( (string) ( $row['const_phone']   ?? '' ) ),
		'pt_mp_fax'            => trim( (string) ( $row['const_fax']     ?? '' ) ),
		'pt_mp_ottawa_office'  => trim( (string) ( $row['ottawa_office'] ?? '' ) ),
		'pt_mp_ottawa_phone'   => trim( (string) ( $row['ottawa_phone']  ?? '' ) ),
	];

	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}

	// ── Taxonomies ────────────────────────────────────────────────────────────

	if ( $party ) {
		$party_id = ensure_term( $party, 'mp_party' );
		if ( $party_id ) {
			wp_set_object_terms( $post_id, [ $party_id ], 'mp_party' );
		}
	}

	if ( $province ) {
		$region_id = ensure_term( $province, 'mp_region' );
		if ( $region_id ) {
			wp_set_object_terms( $post_id, [ $region_id ], 'mp_region' );
		}
	}

	$progress->tick();
}

$progress->finish();

// ── Summary ───────────────────────────────────────────────────────────────────

WP_CLI::success( sprintf(
	'Done. Created: %d  Updated: %d  Failed: %d',
	$created,
	$updated,
	$failed
) );

if ( $created || $updated ) {
	WP_CLI::log( 'Flushing rewrite rules…' );
	flush_rewrite_rules();
	WP_CLI::log( 'Done. Visit /directory to verify the MP archive.' );
}
