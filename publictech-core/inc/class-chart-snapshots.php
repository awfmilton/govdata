<?php
/**
 * Static social-card snapshots for user-authored Billboard.js charts.
 *
 * Social crawlers (Facebook's og:image scraper, X's card bot) don't execute
 * JavaScript, so the client-hydrated Billboard mounts are invisible to them.
 * This class runs the server side of a capture pipeline:
 *
 *   1. Charts_REST::create()/update() call queue(), which schedules a single
 *      WP-Cron event (debounced per chart) and spawns cron immediately — the
 *      REST response is never blocked on a browser render.
 *   2. run_capture() computes a content-addressed filename
 *      (chart-{id}-{rev}.png — the rev hash covers modified date + spec +
 *      source, so every visual change produces a NEW og:image URL and
 *      scraper caches bust themselves), then dispatches the render to either:
 *        - a webhook microservice (tools/chart-snapshot/server.mjs) via a
 *          non-blocking signed POST, which captures the page headlessly and
 *          POSTs the PNG back to the signed ingest endpoint below; or
 *        - a local one-shot Node/Puppeteer process
 *          (tools/chart-snapshot/capture.mjs) started in the background.
 *   3. POST /charts/{id}/snapshot ingests the PNG (HMAC-authenticated,
 *      size/type/dimension checked) into wp-content/uploads/pt-charts/.
 *   4. render_social_meta() injects og:* / twitter:* tags on the single
 *      chart view whenever the snapshot file actually exists on disk.
 *
 * The capture surface is the chart permalink with ?pt_raw_render=1, which the
 * child theme swaps to a chrome-free 1200x630 template (no header rail, no
 * footer). Configuration lives in wp-config.php:
 *
 *   define( 'PUBLICTECH_SNAPSHOT_SECRET',  'long-random-string' );   // shared HMAC secret
 *   define( 'PUBLICTECH_SNAPSHOT_WEBHOOK', 'http://127.0.0.1:8787/render' ); // webhook mode
 *   define( 'PUBLICTECH_SNAPSHOT_NODE_BIN', '/usr/bin/node' );       // or: local mode
 *   define( 'PUBLICTECH_SNAPSHOT_SCRIPT',  '/opt/pt-chart-snapshot/capture.mjs' );
 *
 * Each constant has a same-named lowercase filter (publictech_snapshot_*) so
 * multisite/operators can override per-site. With nothing configured every
 * entry point degrades to a no-op and the single template simply renders
 * without og:image, so the feature is safe to ship dormant.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Chart_Snapshots {

	/** Social-card canvas. 1200x630 is the og:image sweet spot (1.91:1). */
	public const WIDTH  = 1200;
	public const HEIGHT = 630;

	/** Subdirectory of wp-content/uploads/ that holds the rendered cards. */
	public const DIR = 'pt-charts';

	/** Post-meta key holding the current snapshot filename for a chart. */
	public const META_FILE = 'pt_snapshot_file';

	/** Single-event cron hook; args = [ $post_id ]. */
	public const CRON_HOOK = 'publictech_chart_snapshot';

	/** Query flag that switches the single template to the capture surface. */
	public const RAW_QUERY = 'pt_raw_render';

	/** Accept signed requests up to this many seconds of clock skew. */
	public const SIGNATURE_WINDOW = 600;

	/** Hard cap on an ingested PNG (4 MB is generous for a 1200x630 chart). */
	public const MAX_BYTES = 4194304;

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		add_action( self::CRON_HOOK, [ $this, 'run_capture' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'wp_head', [ $this, 'render_social_meta' ], 4 );

		// Retire the card when its chart leaves the public web — REST delete()
		// trashes (fires trashed_post), admin can also hard-delete.
		add_action( 'trashed_post', [ $this, 'cleanup_post' ] );
		add_action( 'before_delete_post', [ $this, 'cleanup_post' ] );
	}

	/* ---------- pure helpers (unit-tested without WP) ---------- */

	/**
	 * Content-addressed snapshot filename. The rev hash doubles as scraper
	 * cache-busting: a changed seed yields a new public URL, so Facebook/X
	 * re-fetch instead of serving their cached copy of the old image.
	 *
	 * @param int    $post_id Chart post ID.
	 * @param string $seed    Any string that changes when the rendered card should.
	 */
	public static function filename( int $post_id, string $seed ): string {
		return sprintf( 'chart-%d-%s.png', $post_id, substr( hash( 'sha256', $post_id . '|' . $seed ), 0, 10 ) );
	}

	/**
	 * Strict shape check for filenames round-tripped through post meta or an
	 * ingest header. Anchored to the owning post ID so a poisoned meta row or
	 * forged header can never point at another chart's file — or traverse out
	 * of the snapshot directory.
	 */
	public static function is_valid_filename( int $post_id, string $name ): bool {
		return 1 === preg_match( '/^chart-' . $post_id . '-[a-f0-9]{10}\.png$/D', $name );
	}

	/**
	 * HMAC over an ordered field list. Newline-joined so no field combination
	 * is ambiguous ("ab","c" vs "a","bc"). Both Node sides (server.mjs /
	 * capture callbacks) implement the identical scheme.
	 *
	 * @param string[] $fields Ordered fields to bind into the signature.
	 * @param string   $secret Shared secret.
	 */
	public static function sign( array $fields, string $secret ): string {
		return hash_hmac( 'sha256', implode( "\n", $fields ), $secret );
	}

	/**
	 * Constant-time signature check. An empty secret always fails — the
	 * pipeline must be explicitly provisioned before any ingest is accepted.
	 *
	 * @param string   $signature Hex signature presented by the caller.
	 * @param string[] $fields    Ordered fields the signature must cover.
	 * @param string   $secret    Shared secret.
	 */
	public static function signature_valid( string $signature, array $fields, string $secret ): bool {
		if ( '' === $secret || '' === $signature ) {
			return false;
		}
		return hash_equals( self::sign( $fields, $secret ), $signature );
	}

	/**
	 * Squash arbitrary post content into a single-line card description:
	 * tags stripped, whitespace collapsed, cut at a word boundary under
	 * $max_length characters with a trailing ellipsis.
	 */
	public static function excerpt_for_card( string $text, int $max_length = 200 ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $text ) ) );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
		if ( '' === $text || $len <= $max_length ) {
			return $text;
		}
		$cut = function_exists( 'mb_substr' )
			? mb_substr( $text, 0, $max_length - 1, 'UTF-8' )
			: substr( $text, 0, $max_length - 1 );
		// Back off to the previous word boundary; a space byte never occurs
		// inside a UTF-8 multibyte sequence, so strrpos is encoding-safe here.
		$space = strrpos( $cut, ' ' );
		if ( false !== $space && $space > 0 ) {
			$cut = substr( $cut, 0, $space );
		}
		// Unicode-aware trailing-punctuation strip (rtrim's charlist would
		// chew single bytes out of a multibyte char).
		$cut = (string) preg_replace( '/[\s.,;:!?…]+$/u', '', $cut );
		return $cut . '…';
	}

	/* ---------- render mode ---------- */

	/**
	 * Is this request the chrome-free capture surface (?pt_raw_render=1 on a
	 * single chart)? Used by the child theme to swap templates and drop the
	 * header rail / footer, and by render_social_meta() to emit noindex
	 * instead of social tags. Only meaningful after the main query is parsed.
	 */
	public static function is_raw_render(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render-mode flag, no state change.
		$flag = isset( $_GET[ self::RAW_QUERY ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::RAW_QUERY ] ) ) : '';
		return '1' === $flag && is_singular( 'user-chart' );
	}

	/** Absolute URL the headless browser should screenshot for a chart. */
	public function render_url( int $post_id ): string {
		return add_query_arg( self::RAW_QUERY, '1', (string) get_permalink( $post_id ) );
	}

	/* ---------- queueing ---------- */

	/**
	 * Schedule a background capture for a chart. Called from
	 * Charts_REST::create()/update(); deliberately cheap (one cron row +
	 * a non-blocking loopback ping) so the REST handlers never wait on a
	 * browser. Private/trashed charts and unconfigured installs are no-ops.
	 */
	public function queue( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'user-chart' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}
		if ( '' === $this->mode() ) {
			return;
		}
		// One pending event per chart: bursts of edits collapse into a single
		// capture that reads the freshest spec when it actually runs.
		if ( ! wp_next_scheduled( self::CRON_HOOK, [ $post_id ] ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK, [ $post_id ] );
		}
		spawn_cron();
	}

	/**
	 * Remove a chart's snapshot file + meta. Used when a chart is unpublished,
	 * trashed, or deleted so a private chart can't keep leaking its last
	 * public rendering through a guessable upload URL.
	 */
	public function invalidate( int $post_id ): void {
		$file = (string) get_post_meta( $post_id, self::META_FILE, true );
		if ( '' !== $file && self::is_valid_filename( $post_id, $file ) ) {
			$path = $this->dir_path() . $file;
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
		delete_post_meta( $post_id, self::META_FILE );
	}

	/** trashed_post / before_delete_post bridge — charts only. */
	public function cleanup_post( $post_id ): void {
		$post_id = (int) $post_id;
		if ( 'user-chart' === get_post_type( $post_id ) ) {
			$this->invalidate( $post_id );
		}
	}

	/* ---------- capture (cron context) ---------- */

	/**
	 * Cron worker: decide the new rev filename, retire the previous file, and
	 * hand the actual rendering to the configured backend. Meta is updated
	 * optimistically — render_social_meta() gates on file_exists(), so the
	 * card simply has no image until the PNG lands.
	 */
	public function run_capture( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'user-chart' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! $this->ensure_dir() ) {
			return;
		}

		$seed = $post->post_modified_gmt
			. '|' . (string) get_post_meta( $post_id, 'pt_spec', true )
			. '|' . (string) get_post_meta( $post_id, 'pt_source', true );
		$file = self::filename( $post_id, $seed );
		$prev = (string) get_post_meta( $post_id, self::META_FILE, true );

		if ( $prev === $file && file_exists( $this->dir_path() . $file ) ) {
			return; // Current rev already rendered — nothing to do.
		}
		if ( '' !== $prev && $prev !== $file && self::is_valid_filename( $post_id, $prev ) ) {
			$stale = $this->dir_path() . $prev;
			if ( file_exists( $stale ) ) {
				wp_delete_file( $stale );
			}
		}
		update_post_meta( $post_id, self::META_FILE, $file );

		if ( 'webhook' === $this->mode() ) {
			$this->dispatch_webhook( $post_id, $file );
		} elseif ( 'local' === $this->mode() ) {
			$this->dispatch_local( $post_id, $file );
		}
	}

	/**
	 * Webhook mode: signed, fire-and-forget POST to the capture microservice.
	 * The service renders ?pt_raw_render=1 headlessly and POSTs the PNG back
	 * to the /snapshot ingest route — WP never blocks on the browser.
	 */
	private function dispatch_webhook( int $post_id, string $file ): void {
		$secret = self::secret();
		if ( '' === $secret ) {
			return; // Refuse to emit unsigned jobs.
		}
		$render = $this->render_url( $post_id );
		$ingest = rest_url( PUBLICTECH_CORE_REST_NS . '/charts/' . $post_id . '/snapshot' );
		$issued = time();

		wp_remote_post( $this->webhook_url(), [
			'timeout'  => 2,
			'blocking' => false,
			'headers'  => [ 'Content-Type' => 'application/json' ],
			'body'     => wp_json_encode( [
				'chart_id'   => $post_id,
				'filename'   => $file,
				'render_url' => $render,
				'ingest_url' => $ingest,
				'width'      => self::WIDTH,
				'height'     => self::HEIGHT,
				'issued_at'  => $issued,
				// Signature binds every routable field, so a forged job can't
				// redirect the service's browser or its PNG delivery.
				'signature'  => self::sign( [ (string) $post_id, $file, $render, $ingest, (string) $issued ], $secret ),
			] ),
		] );
	}

	/**
	 * Local mode: background a one-shot Node/Puppeteer process that writes the
	 * PNG straight into the uploads directory. The trailing `&` plus the
	 * redirect detaches it from PHP, so the cron request returns immediately.
	 */
	private function dispatch_local( int $post_id, string $file ): void {
		if ( ! function_exists( 'exec' ) ) {
			return;
		}
		$cmd = escapeshellarg( $this->node_bin() )
			. ' ' . escapeshellarg( $this->node_script() )
			. ' --url=' . escapeshellarg( $this->render_url( $post_id ) )
			. ' --out=' . escapeshellarg( $this->dir_path() . $file )
			. ' --width=' . self::WIDTH
			. ' --height=' . self::HEIGHT
			. ' > /dev/null 2>&1 &';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- intentional detached capture; binary+script are operator constants and every variable argument is escapeshellarg()-quoted.
		exec( $cmd );
	}

	/* ---------- REST ingest ---------- */

	public function register_routes(): void {
		register_rest_route( PUBLICTECH_CORE_REST_NS, '/charts/(?P<id>\d+)/snapshot', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'permission_callback' => [ $this, 'verify_ingest' ],
			'callback'            => [ $this, 'ingest' ],
		] );
	}

	/**
	 * Machine-to-machine auth for the capture service: HMAC headers instead of
	 * cookies/nonces. The signature covers chart id, filename, a freshness
	 * timestamp and the SHA-256 of the raw body, so a captured request can't
	 * be replayed later or re-used with different bytes.
	 */
	public function verify_ingest( \WP_REST_Request $req ): bool|\WP_Error {
		$secret = self::secret();
		if ( '' === $secret ) {
			return new \WP_Error( 'pt_snapshot_disabled', 'Snapshot pipeline is not configured.', [ 'status' => 403 ] );
		}
		$sig  = (string) $req->get_header( 'x-pt-snapshot-signature' );
		$file = (string) $req->get_header( 'x-pt-snapshot-filename' );
		$ts   = (int) $req->get_header( 'x-pt-snapshot-timestamp' );
		$id   = absint( $req['id'] );

		if ( '' === $sig || '' === $file || abs( time() - $ts ) > self::SIGNATURE_WINDOW ) {
			return new \WP_Error( 'pt_snapshot_auth', 'Missing or stale snapshot credentials.', [ 'status' => 403 ] );
		}
		$body_hash = hash( 'sha256', (string) $req->get_body() );
		if ( ! self::signature_valid( $sig, [ (string) $id, $file, (string) $ts, $body_hash ], $secret ) ) {
			return new \WP_Error( 'pt_snapshot_auth', 'Invalid snapshot signature.', [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * Store a delivered PNG. Defense in depth on top of the HMAC gate: the
	 * filename must match the rev this site currently expects (stale captures
	 * racing a newer edit get a 409), the payload must decode as a real PNG of
	 * card-like dimensions, and the write is tmp+rename so a half-received
	 * body is never visible at the public URL.
	 */
	public function ingest( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$id   = absint( $req['id'] );
		$post = get_post( $id );
		if ( ! $post || 'user-chart' !== $post->post_type ) {
			return new \WP_Error( 'pt_not_found', 'Chart not found.', [ 'status' => 404 ] );
		}
		if ( 'publish' !== $post->post_status ) {
			return new \WP_Error( 'pt_snapshot_private', 'Chart is no longer public.', [ 'status' => 409 ] );
		}

		$file = (string) $req->get_header( 'x-pt-snapshot-filename' );
		if ( ! self::is_valid_filename( $id, $file ) ) {
			return new \WP_Error( 'pt_snapshot_filename', 'Malformed snapshot filename.', [ 'status' => 400 ] );
		}
		if ( $file !== (string) get_post_meta( $id, self::META_FILE, true ) ) {
			return new \WP_Error( 'pt_snapshot_stale', 'Snapshot rev superseded.', [ 'status' => 409 ] );
		}

		$png = (string) $req->get_body();
		if ( strlen( $png ) > self::MAX_BYTES ) {
			return new \WP_Error( 'pt_snapshot_size', 'Snapshot exceeds size limit.', [ 'status' => 413 ] );
		}
		if ( "\x89PNG\r\n\x1a\n" !== substr( $png, 0, 8 ) ) {
			return new \WP_Error( 'pt_snapshot_type', 'Payload is not a PNG.', [ 'status' => 415 ] );
		}
		if ( ! $this->ensure_dir() ) {
			return new \WP_Error( 'pt_snapshot_fs', 'Snapshot directory unavailable.', [ 'status' => 500 ] );
		}

		$dest = $this->dir_path() . $file;
		$tmp  = $dest . '.part-' . wp_generate_password( 8, false );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- raw binary write into a dir we created; WP_Filesystem adds no value for same-host uploads.
		if ( false === file_put_contents( $tmp, $png ) ) {
			return new \WP_Error( 'pt_snapshot_fs', 'Could not persist snapshot.', [ 'status' => 500 ] );
		}
		$info = getimagesize( $tmp );
		if ( false === $info || 'image/png' !== $info['mime'] || $info[0] < 600 || $info[1] < 315 ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'pt_snapshot_type', 'Payload failed image validation.', [ 'status' => 415 ] );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic same-directory publish of the validated file.
		if ( ! rename( $tmp, $dest ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'pt_snapshot_fs', 'Could not publish snapshot.', [ 'status' => 500 ] );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- normalise perms for web serving.
		@chmod( $dest, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );

		return rest_ensure_response( [
			'ok'     => true,
			'id'     => $id,
			'url'    => $this->dir_url() . $file,
			'width'  => $info[0],
			'height' => $info[1],
		] );
	}

	/* ---------- public lookups ---------- */

	/** Public URL of the chart's snapshot, or null when none exists on disk. */
	public function snapshot_url( int $post_id ): ?string {
		$file = (string) get_post_meta( $post_id, self::META_FILE, true );
		if ( '' === $file || ! self::is_valid_filename( $post_id, $file ) ) {
			return null;
		}
		if ( ! file_exists( $this->dir_path() . $file ) ) {
			return null;
		}
		return $this->dir_url() . $file;
	}

	/* ---------- head output ---------- */

	/**
	 * Open Graph / Twitter Card tags for the single chart view. og:image is
	 * only emitted once the static snapshot actually exists; until then the
	 * card degrades to a text summary instead of a broken image. The raw
	 * capture surface gets noindex instead — it's an internal rendering
	 * target, not a page for humans or crawlers.
	 */
	public function render_social_meta(): void {
		if ( ! is_singular( 'user-chart' ) ) {
			return;
		}
		if ( self::is_raw_render() ) {
			echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
			return;
		}
		// Sites running an SEO plugin that owns og:* can switch this emitter off.
		if ( ! apply_filters( 'publictech_chart_social_meta', true ) ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return; // Never advertise private charts to scrapers.
		}

		$title = wp_strip_all_tags( get_the_title( $post ) );
		$desc  = self::excerpt_for_card(
			'' !== $post->post_excerpt
				? $post->post_excerpt
				: __( 'Interactive chart built with the PublicTech chart builder.', 'publictech-core' )
		);
		$url   = (string) get_permalink( $post );
		$image = $this->snapshot_url( $post->ID );

		echo '<meta property="og:type" content="article" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( $desc ) . '" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '" />' . "\n";

		if ( null !== $image ) {
			echo '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
			echo '<meta property="og:image:width" content="' . (int) self::WIDTH . '" />' . "\n";
			echo '<meta property="og:image:height" content="' . (int) self::HEIGHT . '" />' . "\n";
			echo '<meta property="og:image:type" content="image/png" />' . "\n";
			echo '<meta property="og:image:alt" content="' . esc_attr( $title ) . '" />' . "\n";
			echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
			echo '<meta name="twitter:image" content="' . esc_url( $image ) . '" />' . "\n";
			echo '<meta name="twitter:image:alt" content="' . esc_attr( $title ) . '" />' . "\n";
		} else {
			echo '<meta name="twitter:card" content="summary" />' . "\n";
		}
	}

	/* ---------- configuration ---------- */

	/** Which capture backend is provisioned: 'webhook', 'local', or '' (off). */
	private function mode(): string {
		if ( '' !== $this->webhook_url() ) {
			return 'webhook';
		}
		if ( '' !== $this->node_bin() && '' !== $this->node_script() ) {
			return 'local';
		}
		return '';
	}

	private static function secret(): string {
		$secret = defined( 'PUBLICTECH_SNAPSHOT_SECRET' ) ? (string) PUBLICTECH_SNAPSHOT_SECRET : '';
		return (string) apply_filters( 'publictech_snapshot_secret', $secret );
	}

	private function webhook_url(): string {
		$url = defined( 'PUBLICTECH_SNAPSHOT_WEBHOOK' ) ? (string) PUBLICTECH_SNAPSHOT_WEBHOOK : '';
		return (string) apply_filters( 'publictech_snapshot_webhook', $url );
	}

	private function node_bin(): string {
		$bin = defined( 'PUBLICTECH_SNAPSHOT_NODE_BIN' ) ? (string) PUBLICTECH_SNAPSHOT_NODE_BIN : '';
		return (string) apply_filters( 'publictech_snapshot_node_bin', $bin );
	}

	private function node_script(): string {
		$script = defined( 'PUBLICTECH_SNAPSHOT_SCRIPT' ) ? (string) PUBLICTECH_SNAPSHOT_SCRIPT : '';
		return (string) apply_filters( 'publictech_snapshot_script', $script );
	}

	/* ---------- filesystem ---------- */

	private function dir_path(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( (string) $uploads['basedir'] ) . self::DIR . '/';
	}

	private function dir_url(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( (string) $uploads['baseurl'] ) . self::DIR . '/';
	}

	/** Create uploads/pt-charts/ (with a listing guard) on first use. */
	private function ensure_dir(): bool {
		$dir = $this->dir_path();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$guard = $dir . 'index.html';
		if ( ! file_exists( $guard ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- empty directory-listing guard, mirrors WP core behaviour.
			file_put_contents( $guard, '' );
		}
		return true;
	}
}
