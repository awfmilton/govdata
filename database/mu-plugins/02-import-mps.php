<?php
/**
 * One-off MP importer — URL-triggered.
 *
 * Place this file in:
 *   wp-content/mu-plugins/02-import-mps.php
 *
 * Add this constant to wp-config.php (pick any long random string):
 *   define( 'PT_IMPORT_TOKEN', 'replace-with-a-long-random-string' );
 *
 * Then visit:
 *   https://govdata.ca/?pt_import_mps=replace-with-a-long-random-string
 *
 * The import is idempotent — safe to run multiple times. Delete this file
 * from mu-plugins once the import is complete.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', static function () {

	// ── Gate ─────────────────────────────────────────────────────────────────

	if ( ! isset( $_GET['pt_import_mps'] ) ) {
		return;
	}

	if ( ! defined( 'PT_IMPORT_TOKEN' ) || $_GET['pt_import_mps'] !== PT_IMPORT_TOKEN ) {
		wp_die( 'Forbidden.', 403 );
	}

	// ── Bootstrap output ─────────────────────────────────────────────────────

	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Accel-Buffering: no' ); // disable nginx output buffering
	echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
		. '<style>body{font-family:monospace;padding:1rem}'
		. 'p{margin:.2rem 0}.ok{color:#090}.warn{color:#c80}.err{color:#c00}'
		. '</style></head><body>';
	echo '<h2>PublicTech — MP Importer</h2>';
	flush_output();

	// ── DB connection ─────────────────────────────────────────────────────────

	$conn = defined( 'PUBLICTECH_DB_CONNECTIONS' )
		? ( PUBLICTECH_DB_CONNECTIONS['pt-grantconts-01'] ?? null )
		: null;

	if ( ! $conn ) {
		out_err( 'PUBLICTECH_DB_CONNECTIONS[pt-grantconts-01] is not defined in wp-config.php.' );
		die( '</body></html>' );
	}

	try {
		$port = $conn['port'] ?? 3306;
		$dsn  = "mysql:host={$conn['host']};port={$port};dbname={$conn['name']};charset=utf8mb4";
		$pdo  = new PDO( $dsn, $conn['user'], $conn['pass'], [
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY',
		] );
	} catch ( PDOException $e ) {
		out_err( 'DB connection failed: ' . esc_html( $e->getMessage() ) );
		die( '</body></html>' );
	}

	out_ok( 'Connected to ' . esc_html( $conn['name'] ) . ' on ' . esc_html( $conn['host'] ) );

	// ── Fetch MPs ─────────────────────────────────────────────────────────────

	$rows  = $pdo->query( 'SELECT * FROM `mps` ORDER BY `last_name`, `first_name`' )->fetchAll();
	$total = count( $rows );
	out_info( "Found {$total} MPs in grantconts.mps — starting import…" );
	flush_output();

	// ── Import ────────────────────────────────────────────────────────────────

	$created = 0;
	$updated = 0;
	$failed  = 0;

	foreach ( $rows as $i => $row ) {
		$mp_id    = trim( (string) ( $row['mp_id']      ?? '' ) );
		$first    = trim( (string) ( $row['first_name'] ?? '' ) );
		$last     = trim( (string) ( $row['last_name']  ?? '' ) );
		$title    = trim( "{$first} {$last}" ) ?: $mp_id;
		$district = trim( (string) ( $row['district_name'] ?? '' ) );
		$party    = trim( (string) ( $row['party']         ?? '' ) );
		$province = strtoupper( trim( (string) ( $row['province'] ?? '' ) ) );

		// Find existing post by external_mp_id.
		$existing = get_posts( [
			'post_type'      => 'member-parliament',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_key'       => 'external_mp_id',
			'meta_value'     => $mp_id,
			'fields'         => 'ids',
		] );
		$existing_id = $existing[0] ?? 0;

		$post_data = [
			'post_type'    => 'member-parliament',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_excerpt' => $district,
		];

		if ( $existing_id ) {
			$post_data['ID'] = $existing_id;
			$post_id = wp_update_post( $post_data, true );
		} else {
			$post_id = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $post_id ) ) {
			out_err( "#{$mp_id} {$title}: " . esc_html( $post_id->get_error_message() ) );
			$failed++;
			continue;
		}

		// Meta.
		$meta = [
			'external_mp_id'      => $mp_id,
			'pt_mp_district'      => $district,
			'pt_mp_gender'        => trim( (string) ( $row['gender']        ?? '' ) ),
			'pt_mp_email'         => trim( (string) ( $row['email']         ?? '' ) ),
			'pt_mp_photo_url'     => trim( (string) ( $row['photo_url']     ?? '' ) ),
			'pt_mp_source_url'    => trim( (string) ( $row['source_url']    ?? '' ) ),
			'pt_mp_postal_code'   => trim( (string) ( $row['postal_code']   ?? '' ) ),
			'pt_mp_phone'         => trim( (string) ( $row['const_phone']   ?? '' ) ),
			'pt_mp_fax'           => trim( (string) ( $row['const_fax']     ?? '' ) ),
			'pt_mp_ottawa_office' => trim( (string) ( $row['ottawa_office'] ?? '' ) ),
			'pt_mp_ottawa_phone'  => trim( (string) ( $row['ottawa_phone']  ?? '' ) ),
		];
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		// Taxonomies.
		if ( $party ) {
			$term = get_term_by( 'name', $party, 'mp_party' )
				?: wp_insert_term( $party, 'mp_party' );
			$tid = is_array( $term ) ? $term['term_id'] : ( $term->term_id ?? 0 );
			if ( $tid ) {
				wp_set_object_terms( $post_id, [ (int) $tid ], 'mp_party' );
			}
		}

		if ( $province ) {
			$term = get_term_by( 'name', $province, 'mp_region' )
				?: wp_insert_term( $province, 'mp_region' );
			$tid = is_array( $term ) ? $term['term_id'] : ( $term->term_id ?? 0 );
			if ( $tid ) {
				wp_set_object_terms( $post_id, [ (int) $tid ], 'mp_region' );
			}
		}

		if ( $existing_id ) {
			$updated++;
		} else {
			$created++;
		}

		// Print a line every 10 MPs so the browser shows progress.
		if ( ( $i + 1 ) % 10 === 0 || ( $i + 1 ) === $total ) {
			out_info( sprintf( '%d / %d done…', $i + 1, $total ) );
			flush_output();
		}
	}

	// ── Done ──────────────────────────────────────────────────────────────────

	flush_rewrite_rules();

	echo '<hr>';
	out_ok( "Import complete — Created: {$created} &nbsp; Updated: {$updated} &nbsp; Failed: {$failed}" );
	echo '<p>You can now delete <code>wp-content/mu-plugins/02-import-mps.php</code> from the server.</p>';
	echo '</body></html>';
	exit;

} );

// ── Output helpers ────────────────────────────────────────────────────────────

function out_ok( string $msg ): void {
	echo '<p class="ok">✓ ' . $msg . '</p>';
}

function out_info( string $msg ): void {
	echo '<p>' . $msg . '</p>';
}

function out_err( string $msg ): void {
	echo '<p class="err">✗ ' . $msg . '</p>';
}

function flush_output(): void {
	if ( ob_get_level() ) {
		ob_flush();
	}
	flush();
}
