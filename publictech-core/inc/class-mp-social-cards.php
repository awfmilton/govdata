<?php
/**
 * Social cards for Member of Parliament profile pages, rendered via ApiFlash.
 *
 * On-host headless rendering is impossible on this hosting plan (a 25-process
 * cgroup cap), so cards are produced by the ApiFlash screenshot API: it loads
 * a chrome-free 1200x630 variant of the MP profile (?pt_raw_render=1), waits
 * for the office-spend chart to draw, and returns a PNG that we persist under
 * wp-content/uploads/pt-charts/mp/ and advertise as og:image / twitter:image.
 *
 * Quota discipline: the ApiFlash free tier allows 100 captures/month. Captures
 * therefore happen ONLY through the explicit, idempotent batch (the
 * `wp publictech mp-cards` CLI command) — never automatically on save or page
 * view — and the batch refuses to exceed either its --limit or the remaining
 * ApiFlash quota. render_social_meta() only ever reads an existing file; it
 * never triggers a capture.
 *
 *   wp publictech mp-cards --limit=50          # generate up to 50 missing cards
 *   wp publictech mp-cards --limit=10 --force   # re-render even if a card exists
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class MP_Social_Cards {

	public const WIDTH  = 1200;
	public const HEIGHT = 630;

	/** Uploads subdirectory for MP cards. */
	public const DIR = 'pt-charts/mp';

	/** Post-meta key holding the current card filename for an MP. */
	public const META_FILE = 'pt_mp_card_file';

	/** Query flag selecting the chrome-free capture surface. */
	public const RAW_QUERY = 'pt_raw_render';

	/** Don't start a capture unless at least this much quota remains. */
	public const QUOTA_FLOOR = 1;

	/** Hard cap on an accepted PNG. */
	public const MAX_BYTES = 5242880;

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		add_action( 'wp_head', [ $this, 'render_social_meta' ], 4 );
		add_action( 'before_delete_post', [ $this, 'cleanup_post' ] );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'publictech mp-cards', [ $this, 'cli_batch' ] );
		}
	}

	/* ---------- render-surface detection ---------- */

	/** Is this the chrome-free MP card capture surface? */
	public static function is_card_render(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render-mode flag, no state change.
		$flag = isset( $_GET[ self::RAW_QUERY ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::RAW_QUERY ] ) ) : '';
		return '1' === $flag && is_singular( 'member-parliament' );
	}

	/** URL ApiFlash should screenshot for a given MP. */
	public function card_render_url( int $post_id ): string {
		return add_query_arg( self::RAW_QUERY, '1', (string) get_permalink( $post_id ) );
	}

	/* ---------- filenames ---------- */

	/**
	 * Content-addressed filename. The seed folds in the modified date and the
	 * headline spend figures, so a data refresh yields a new og:image URL and
	 * scraper caches bust themselves.
	 */
	public static function filename( int $post_id, string $seed ): string {
		return sprintf( 'mp-%d-%s.png', $post_id, substr( hash( 'sha256', $post_id . '|' . $seed ), 0, 10 ) );
	}

	public static function is_valid_filename( int $post_id, string $name ): bool {
		return 1 === preg_match( '/^mp-' . $post_id . '-[a-f0-9]{10}\.png$/D', $name );
	}

	/** Stable per-MP seed: modified time + the figures shown on the card. */
	private function seed_for( int $post_id ): string {
		return implode( '|', [
			(string) get_post_field( 'post_modified_gmt', $post_id ),
			(string) get_post_meta( $post_id, 'total_spend', true ),
			(string) get_post_meta( $post_id, 'travel_spend', true ),
			(string) get_post_meta( $post_id, 'contracts_n', true ),
			(string) get_post_meta( $post_id, 'votes_cast', true ),
		] );
	}

	/* ---------- capture (explicit batch only) ---------- */

	/**
	 * Capture one MP's card through ApiFlash and persist it. Idempotent: if the
	 * file for the current seed already exists, returns 'skipped' without
	 * spending quota (unless $force).
	 *
	 * @return array{status:string,id:int,file?:string,url?:string,message?:string}
	 */
	public function capture( int $post_id, bool $force = false ): array {
		$post = get_post( $post_id );
		if ( ! $post || 'member-parliament' !== $post->post_type || 'publish' !== $post->post_status ) {
			return [ 'status' => 'invalid', 'id' => $post_id, 'message' => 'Not a published MP.' ];
		}
		if ( ! ApiFlash::is_configured() ) {
			return [ 'status' => 'unconfigured', 'id' => $post_id, 'message' => 'ApiFlash key not set.' ];
		}
		if ( ! $this->ensure_dir() ) {
			return [ 'status' => 'failed', 'id' => $post_id, 'message' => 'Uploads directory unavailable.' ];
		}

		$file = self::filename( $post_id, $this->seed_for( $post_id ) );
		$dest = $this->dir_path() . $file;
		if ( ! $force && file_exists( $dest ) ) {
			return [ 'status' => 'skipped', 'id' => $post_id, 'file' => $file, 'url' => $this->dir_url() . $file ];
		}

		$res = ApiFlash::capture( $this->card_render_url( $post_id ), [
			'format'     => 'png',
			'width'      => self::WIDTH,
			'height'     => self::HEIGHT,
			'wait_until' => 'network_idle',
			'delay'      => 3,
			'fresh'      => 'true',
		] );
		if ( is_wp_error( $res ) ) {
			return [ 'status' => 'failed', 'id' => $post_id, 'message' => $res->get_error_message() ];
		}
		if ( 200 !== $res['code'] ) {
			return [ 'status' => 'failed', 'id' => $post_id, 'message' => sprintf( 'HTTP %d: %s', $res['code'], ApiFlash::error_message( $res['body'] ) ) ];
		}

		$png = (string) $res['body'];
		if ( strlen( $png ) > self::MAX_BYTES || "\x89PNG\r\n\x1a\n" !== substr( $png, 0, 8 ) ) {
			return [ 'status' => 'failed', 'id' => $post_id, 'message' => 'Response was not a valid PNG.' ];
		}

		// Retire the previous card (different seed) before publishing the new one.
		$prev = (string) get_post_meta( $post_id, self::META_FILE, true );
		if ( '' !== $prev && $prev !== $file && self::is_valid_filename( $post_id, $prev ) && file_exists( $this->dir_path() . $prev ) ) {
			wp_delete_file( $this->dir_path() . $prev );
		}

		$tmp = $dest . '.part-' . wp_generate_password( 8, false );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- binary write into a dir we own; WP_Filesystem adds nothing for same-host uploads.
		if ( false === file_put_contents( $tmp, $png ) ) {
			return [ 'status' => 'failed', 'id' => $post_id, 'message' => 'Could not write file.' ];
		}
		$info = getimagesize( $tmp );
		if ( false === $info || 'image/png' !== $info['mime'] || $info[0] < 600 || $info[1] < 315 ) {
			wp_delete_file( $tmp );
			return [ 'status' => 'failed', 'id' => $post_id, 'message' => 'Image failed validation.' ];
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic same-dir publish.
		rename( $tmp, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- normalise perms for web serving.
		@chmod( $dest, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );

		update_post_meta( $post_id, self::META_FILE, $file );
		return [ 'status' => 'captured', 'id' => $post_id, 'file' => $file, 'url' => $this->dir_url() . $file ];
	}

	/**
	 * Generate cards for the first N published MPs that lack one.
	 *
	 * @return array{captured:int,skipped:int,failed:int,quota_remaining:int|null,details:array<int,array>}
	 */
	public function batch( int $limit = 50, bool $force = false ): array {
		$limit = max( 0, $limit );
		$out   = [ 'captured' => 0, 'skipped' => 0, 'failed' => 0, 'quota_remaining' => null, 'details' => [] ];

		$quota     = ApiFlash::quota();
		$remaining = is_array( $quota ) ? (int) $quota['remaining'] : null;
		$out['quota_remaining'] = $remaining;

		$ids = get_posts( [
			'post_type'      => 'member-parliament',
			'post_status'    => 'publish',
			'posts_per_page' => $limit > 0 ? $limit : -1,
			'orderby'        => apply_filters( 'publictech_mp_card_orderby', 'ID' ),
			'order'          => apply_filters( 'publictech_mp_card_order', 'ASC' ),
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		foreach ( $ids as $id ) {
			$id = (int) $id;

			// Stop spending once quota is at/below the floor (a known-good number).
			if ( null !== $remaining && ! $force ) {
				$file_exists = file_exists( $this->dir_path() . self::filename( $id, $this->seed_for( $id ) ) );
				if ( ! $file_exists && $remaining <= self::QUOTA_FLOOR - 1 ) {
					$out['details'][] = [ 'status' => 'quota_exhausted', 'id' => $id ];
					break;
				}
			}

			$res = $this->capture( $id, $force );
			$out['details'][] = $res;

			if ( 'captured' === $res['status'] ) {
				$out['captured']++;
				if ( null !== $remaining ) {
					$remaining--;
				}
			} elseif ( 'skipped' === $res['status'] ) {
				$out['skipped']++;
			} else {
				$out['failed']++;
				// A 402 from ApiFlash means the quota is gone — stop the run.
				if ( isset( $res['message'] ) && false !== strpos( (string) $res['message'], 'HTTP 402' ) ) {
					break;
				}
			}
		}

		$fresh = ApiFlash::quota( true );
		$out['quota_remaining'] = is_array( $fresh ) ? (int) $fresh['remaining'] : $remaining;
		return $out;
	}

	/**
	 * WP-CLI: wp publictech mp-cards [--limit=<n>] [--force]
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function cli_batch( $args, $assoc ): void {
		$limit = isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 50;
		$force = isset( $assoc['force'] );

		\WP_CLI::log( sprintf( 'Generating up to %d MP card(s)%s …', $limit, $force ? ' (force)' : '' ) );
		$r = $this->batch( $limit, $force );

		foreach ( $r['details'] as $d ) {
			$line = sprintf( '  [%s] MP #%d%s', $d['status'], $d['id'], isset( $d['message'] ) ? ' — ' . $d['message'] : '' );
			if ( in_array( $d['status'], [ 'failed', 'invalid', 'unconfigured', 'quota_exhausted' ], true ) ) {
				\WP_CLI::warning( $line );
			} else {
				\WP_CLI::log( $line );
			}
		}
		\WP_CLI::success( sprintf(
			'Captured %d, skipped %d, failed %d. ApiFlash quota remaining: %s',
			$r['captured'], $r['skipped'], $r['failed'],
			null === $r['quota_remaining'] ? 'unknown' : (string) $r['quota_remaining']
		) );
	}

	/* ---------- public lookup ---------- */

	/** Public URL of an MP's card, or null if none exists on disk. */
	public function card_url( int $post_id ): ?string {
		$file = (string) get_post_meta( $post_id, self::META_FILE, true );
		if ( '' === $file || ! self::is_valid_filename( $post_id, $file ) ) {
			return null;
		}
		return file_exists( $this->dir_path() . $file ) ? $this->dir_url() . $file : null;
	}

	/* ---------- head output ---------- */

	public function render_social_meta(): void {
		if ( ! is_singular( 'member-parliament' ) ) {
			return;
		}
		if ( self::is_card_render() ) {
			echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
			return;
		}
		if ( ! apply_filters( 'publictech_mp_social_meta', true ) ) {
			return; // Let an SEO plugin own og:* if one is configured to.
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		$name    = wp_strip_all_tags( get_the_title( $post ) );
		$riding  = (string) ( get_post_meta( $post->ID, 'pt_mp_district', true ) ?: get_post_meta( $post->ID, 'riding', true ) );
		$spend   = (int) get_post_meta( $post->ID, 'total_spend', true );
		$party   = get_the_terms( $post->ID, 'mp_party' );
		$party_n = $party && ! is_wp_error( $party ) ? $party[0]->name : '';

		$bits = [];
		if ( '' !== $riding ) {
			/* translators: %s: riding name */
			$bits[] = sprintf( __( 'MP for %s', 'publictech-core' ), $riding );
		}
		if ( '' !== $party_n ) {
			$bits[] = $party_n;
		}
		if ( $spend > 0 ) {
			/* translators: %s: formatted dollar amount */
			$bits[] = sprintf( __( '%s office spend', 'publictech-core' ), '$' . number_format_i18n( $spend ) );
		}
		$desc  = $bits ? implode( ' · ', $bits ) : __( 'House of Commons expenditure and parliamentary record.', 'publictech-core' );
		$url   = (string) get_permalink( $post );
		$image = $this->card_url( $post->ID );

		echo '<meta property="og:type" content="profile" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $name ) . '" />' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( $desc ) . '" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '" />' . "\n";

		if ( null !== $image ) {
			echo '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
			echo '<meta property="og:image:width" content="' . (int) self::WIDTH . '" />' . "\n";
			echo '<meta property="og:image:height" content="' . (int) self::HEIGHT . '" />' . "\n";
			echo '<meta property="og:image:type" content="image/png" />' . "\n";
			echo '<meta property="og:image:alt" content="' . esc_attr( $name ) . '" />' . "\n";
			echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
			echo '<meta name="twitter:image" content="' . esc_url( $image ) . '" />' . "\n";
		} else {
			echo '<meta name="twitter:card" content="summary" />' . "\n";
		}
	}

	/* ---------- cleanup ---------- */

	public function cleanup_post( $post_id ): void {
		$post_id = (int) $post_id;
		if ( 'member-parliament' !== get_post_type( $post_id ) ) {
			return;
		}
		$file = (string) get_post_meta( $post_id, self::META_FILE, true );
		if ( '' !== $file && self::is_valid_filename( $post_id, $file ) && file_exists( $this->dir_path() . $file ) ) {
			wp_delete_file( $this->dir_path() . $file );
		}
		delete_post_meta( $post_id, self::META_FILE );
	}

	/* ---------- filesystem ---------- */

	private function dir_path(): string {
		$u = wp_upload_dir();
		return trailingslashit( (string) $u['basedir'] ) . self::DIR . '/';
	}

	private function dir_url(): string {
		$u = wp_upload_dir();
		return trailingslashit( (string) $u['baseurl'] ) . self::DIR . '/';
	}

	private function ensure_dir(): bool {
		$dir = $this->dir_path();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$guard = $dir . 'index.html';
		if ( ! file_exists( $guard ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- directory-listing guard, mirrors WP core.
			file_put_contents( $guard, '' );
		}
		return true;
	}
}
