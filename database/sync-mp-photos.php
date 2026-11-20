<?php
/**
 * WP-CLI: store every MP's portrait in the WordPress media library (self-hosted, WebP, resized)
 * and set it as the member-parliament post's featured image.
 *
 * Why: portraits were hot-linked from openparliament.ca and ourcommons.ca, which slows every directory
 * page and broke when cdn.openparliament.ca stopped resolving (2026-11). Templates already prefer the
 * featured image (archive-member-parliament.php, search.js via /mp/search, single-member-parliament.php).
 *
 * Source per MP, best first: the official House of Commons photo (grantconts.mps.photo_url, via the
 * post's external_mp_id), then the post's pt_mp_photo_url. The original URL and source are kept on the
 * attachment (meta pt_photo_source_url / pt_photo_source) for attribution and re-sync.
 * Output is WebP (uploads + every generated size); sizes: thumbnail 150, pt-mp-card 96, pt-mp-portrait 400.
 *
 * Usage:  wp eval-file database/sync-mp-photos.php            (only MPs without a featured image)
 *         wp eval-file database/sync-mp-photos.php force      (re-download all)
 *         wp eval-file database/sync-mp-photos.php dry-run
 *
 * @package PublicTech
 */

defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$argv_  = (array) ( $args ?? [] );
$force  = in_array( 'force', $argv_, true );
$dry    = in_array( 'dry-run', $argv_, true );
$allow  = [ 'www.ourcommons.ca', 'ourcommons.ca', 'openparliament.ca', 'www.openparliament.ca' ];

// WebP for the uploaded file and every intermediate size.
add_filter( 'image_editor_output_format', static fn( $f ) => array_merge( (array) $f, [ 'image/jpeg' => 'image/webp', 'image/png' => 'image/webp' ] ) );
add_filter( 'wp_editor_set_quality', static fn( $q, $mime = '' ) => 'image/webp' === $mime ? 82 : $q, 10, 2 );
add_image_size( 'pt-mp-card', 96, 96, true );
add_image_size( 'pt-mp-portrait', 400, 400, true );

// Official House of Commons photo per external MP id.
$official = [];
$cfg = PUBLICTECH_DB_CONNECTIONS['pt-grantconts-01'] ?? null;
if ( $cfg ) {
	try {
		$db = new PDO( sprintf( 'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'] ?? 3306, $cfg['name'] ),
			$cfg['user'], $cfg['pass'], [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ] + ( empty( $cfg['ssl_ca'] ) ? [] : [
				PDO::MYSQL_ATTR_SSL_CA => $cfg['ssl_ca'], PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true ] ) );
		foreach ( $db->query( "SELECT mp_id, photo_url FROM mps WHERE photo_url LIKE 'https://www.ourcommons.ca/%'" ) as $r ) {
			$official[ (string) $r['mp_id'] ] = (string) $r['photo_url'];
		}
	} catch ( Throwable $e ) {
		WP_CLI::warning( 'grantconts unavailable; using pt_mp_photo_url only: ' . $e->getMessage() );
	}
}

$posts = get_posts( [ 'post_type' => 'member-parliament', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] );
$done = $skip = $fail = 0;
foreach ( $posts as $pid ) {
	if ( ! $force && has_post_thumbnail( $pid ) ) { ++$skip; continue; }
	$ext  = (string) get_post_meta( $pid, 'external_mp_id', true );
	$url  = $official[ $ext ] ?? (string) get_post_meta( $pid, 'pt_mp_photo_url', true );
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	// Fallback: the official portrait on the member's House of Commons profile (pt_hoc_person_id, set by
	// sync-current-mps.php) when the stored URL is missing or on a host that no longer serves (cdn.openparliament.ca).
	$hoc_id = (string) get_post_meta( $pid, 'pt_hoc_person_id', true );
	if ( ( ! $url || ! in_array( $host, $allow, true ) ) && $hoc_id ) {
		$page = wp_remote_get( 'https://www.ourcommons.ca/members/en/' . rawurlencode( $hoc_id ), [ 'timeout' => 30 ] );
		if ( ! is_wp_error( $page ) && preg_match( '#/Content/Parliamentarians/Images/OfficialMPPhotos/[^"\']+\.(?:jpg|jpeg|png)#i', wp_remote_retrieve_body( $page ), $m ) ) {
			// Encode each path segment: file names carry accents ("DeschênesAlexis_BQ.jpg").
			$url  = 'https://www.ourcommons.ca' . implode( '/', array_map( 'rawurlencode', explode( '/', html_entity_decode( $m[0] ) ) ) );
			$host = 'www.ourcommons.ca';
		}
	}
	if ( ! $url || ! in_array( $host, $allow, true ) ) { ++$skip; continue; }
	$source = str_contains( $host, 'ourcommons' ) ? 'House of Commons' : 'openparliament.ca';
	if ( $dry ) { WP_CLI::log( "would fetch {$pid} {$source} {$url}" ); ++$done; continue; }

	$tmp = download_url( $url, 30 );
	if ( is_wp_error( $tmp ) ) { WP_CLI::warning( "{$pid} " . get_the_title( $pid ) . ": " . $tmp->get_error_message() ); ++$fail; continue; }
	$slug = sanitize_title( get_post_field( 'post_name', $pid ) ?: get_the_title( $pid ) );
	$ext_ = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ) ?: 'jpg';
	$file = [ 'name' => "mp-{$slug}.{$ext_}", 'tmp_name' => $tmp ];
	$att  = media_handle_sideload( $file, $pid, get_the_title( $pid ) );
	if ( is_wp_error( $att ) ) { @unlink( $tmp ); WP_CLI::warning( "{$pid}: " . $att->get_error_message() ); ++$fail; continue; }
	update_post_meta( $att, '_wp_attachment_image_alt', get_the_title( $pid ) );
	update_post_meta( $att, 'pt_photo_source_url', esc_url_raw( $url ) );
	update_post_meta( $att, 'pt_photo_source', $source );
	wp_update_post( [ 'ID' => $att, 'post_excerpt' => sprintf( 'Photo: %s', $source ) ] );   // caption = credit
	$old = (int) get_post_thumbnail_id( $pid );
	set_post_thumbnail( $pid, $att );
	if ( $force && $old && $old !== $att && get_post_meta( $old, 'pt_photo_source', true ) ) {
		wp_delete_attachment( $old, true );   // replace our own previous import only
	}
	++$done;
}
WP_CLI::success( sprintf( '%s %d portraits; %d skipped (already set or no allowed source); %d failed.', $dry ? 'Would import' : 'Imported', $done, $skip, $fail ) );
