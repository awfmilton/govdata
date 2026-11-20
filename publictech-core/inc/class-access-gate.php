<?php
/**
 * Access gate: anonymous visitors may view a few DATA pages per day before signing in.
 *
 * Policy (owner decision, 2026-11-18):
 *   - Anonymous visitors: 5 data pages per rolling 24 hours, then a free sign-up / sign-in prompt.
 *     Non-data pages (home, about, method, help, sign-up, forum index) never count.
 *   - Signed-in users (free tier and up): no page limit. Data API access follows their tier.
 *   - Verified search-engine crawlers: not gated, so pages are indexed with full content. A crawler is
 *     verified by reverse DNS of the connecting IP plus forward confirmation — a User-Agent alone is
 *     never trusted, because scrapers routinely claim to be Googlebot.
 *   - Page widgets (the MP Activity / Spending tabs, which load data after the page) are part of the
 *     page: each gated page carries a short-lived signed "page pass" that its own data requests present.
 *     Calling those endpoints without having loaded (and counted) the page is refused.
 *   - Capture surfaces (?pt_raw_render=1 for social cards and chart snapshots) are exempt only when
 *     the URL carries a valid short-lived signature minted by this class.
 *
 * Counting uses a signed first-party cookie AND the client IP, so clearing cookies does not reset the
 * allowance. The IP bucket is larger (shared offices, campuses and mobile carriers sit behind one IP).
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Access_Gate {

	public const FREE_PAGES      = 5;
	public const IP_FREE_PAGES   = 30;
	public const WINDOW          = DAY_IN_SECONDS;
	public const COOKIE          = 'pt_vid';
	public const PASS_HEADER     = 'x-pt-page-pass';
	public const PASS_TTL        = 30 * MINUTE_IN_SECONDS;
	public const SNAP_QUERY      = 'pt_snap_sig';
	public const SNAP_TTL        = 6 * HOUR_IN_SECONDS;   // capture jobs can wait in a queue

	/** Page templates whose content is data (counted for anonymous visitors). */
	private const DATA_TEMPLATES = [
		'page-templates/template-entity-report.php',
		'page-templates/template-riding-report.php',
		'page-templates/template-map.php',
		'page-templates/template-data-explorer.php',
		'page-templates/template-query-builder.php',
		'page-templates/template-stream.php',
		'page-templates/template-dashboard.php',
		'page-templates/template-data-store.php',
		'page-templates/template-deleted-records.php',
	];

	/** Post types whose single views are data pages. */
	private const DATA_POST_TYPES = [ 'member-parliament', 'user-chart' ];

	/** Reverse-DNS suffixes of verifiable search crawlers. */
	private const CRAWLER_HOSTS = [
		'.googlebot.com', '.google.com', '.googleusercontent.com', '.search.msn.com', '.crawl.yahoo.net',
		'.yandex.ru', '.yandex.net', '.yandex.com', '.applebot.apple.com', '.baidu.com', '.baidu.jp',
	];
	private const CRAWLER_UA = '/googlebot|google-inspectiontool|bingbot|msnbot|slurp|yandex(bot|images)|applebot|baiduspider/i';

	private static ?self $instance = null;
	private ?string $vid = null;
	private ?bool $crawler = null;
	private bool $gated = false;

	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		add_action( 'template_redirect', [ $this, 'gate' ], 1 );
		add_action( 'wp_head', [ $this, 'print_pass_injector' ], 1 );
		add_filter( 'robots_txt', [ $this, 'robots_txt' ], 10, 2 );
	}

	/* ---------- classification ---------- */

	public function is_data_page(): bool {
		$is = is_singular( self::DATA_POST_TYPES );
		if ( ! $is && is_page() ) {
			foreach ( self::DATA_TEMPLATES as $tpl ) {
				if ( is_page_template( $tpl ) ) { $is = true; break; }
			}
		}
		return (bool) apply_filters( 'publictech_gate_is_data_page', $is );
	}

	public function is_exempt(): bool {
		if ( is_user_logged_in() || is_admin() || wp_doing_ajax() || wp_doing_cron()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return true;
		}
		if ( $this->is_verified_crawler() || $this->has_valid_snapshot_signature() ) {
			return true;
		}
		return (bool) apply_filters( 'publictech_gate_exempt', false );
	}

	/** Reverse + forward DNS verification of a claimed search crawler; cached per IP for a day. */
	public function is_verified_crawler(): bool {
		if ( null !== $this->crawler ) {
			return $this->crawler;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( ! preg_match( self::CRAWLER_UA, $ua ) ) {
			return $this->crawler = false;
		}
		$ip  = client_ip();
		$key = 'pt_crawl_' . md5( $ip );
		$hit = get_transient( $key );
		if ( false !== $hit ) {
			return $this->crawler = ( 'y' === $hit );
		}
		$ok   = false;
		$host = filter_var( $ip, FILTER_VALIDATE_IP ) ? strtolower( (string) gethostbyaddr( $ip ) ) : '';
		if ( $host && $host !== $ip ) {
			foreach ( self::CRAWLER_HOSTS as $suffix ) {
				if ( str_ends_with( $host, $suffix ) ) {
					$fwd = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 )
						? array_column( (array) dns_get_record( $host, DNS_AAAA ), 'ipv6' )
						: (array) gethostbynamel( $host );
					$ok = in_array( $ip, $fwd, true );
					break;
				}
			}
		}
		set_transient( $key, $ok ? 'y' : 'n', DAY_IN_SECONDS );
		return $this->crawler = $ok;
	}

	/* ---------- visitor identity ---------- */

	private function sign( string $data ): string {
		return substr( hash_hmac( 'sha256', $data, wp_salt( 'auth' ) . '|pt-gate' ), 0, 32 );
	}

	/** Signed random visitor id from the first-party cookie (issued if absent or forged). */
	public function visitor_id(): string {
		if ( null !== $this->vid ) {
			return $this->vid;
		}
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		[ $id, $mac ] = array_pad( explode( '.', $raw, 2 ), 2, '' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $id ) || ! hash_equals( $this->sign( $id ), $mac ) ) {
			$id = bin2hex( random_bytes( 16 ) );
			if ( ! headers_sent() ) {
				setcookie( self::COOKIE, $id . '.' . $this->sign( $id ), [
					'expires' => time() + 30 * DAY_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN ?: '',
					'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax',
				] );
			}
		}
		return $this->vid = $id;
	}

	/* ---------- counting ---------- */

	/** Record a view of $page_key in a bucket; return distinct pages seen in the window (this one included). */
	private function record( string $bucket, string $page_key ): int {
		$key  = 'pt_gate_' . md5( $bucket );
		$seen = get_transient( $key );
		$seen = is_array( $seen ) ? $seen : [];
		$now  = time();
		$seen = array_filter( $seen, static fn( $ts ) => $ts > $now - self::WINDOW );
		if ( ! isset( $seen[ $page_key ] ) ) {
			$seen[ $page_key ] = $now;
		}
		if ( count( $seen ) > 200 ) {                 // bound the stored set
			asort( $seen );
			$seen = array_slice( $seen, -200, null, true );
		}
		set_transient( $key, $seen, self::WINDOW );
		return count( $seen );
	}

	public function gate(): void {
		// D6: the front page is the site's front door and never counts toward the allowance (owner decision pending;
		// filter publictech_gate_count_front_page to change).
		if ( is_front_page() && ! apply_filters( 'publictech_gate_count_front_page', false ) ) {
			return;
		}
		if ( ! $this->is_data_page() || $this->is_exempt() ) {
			return;
		}
		$page = md5( strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), '?' ) );
		$by_v = $this->record( 'v:' . $this->visitor_id(), $page );
		$by_i = $this->record( 'i:' . client_ip(), $page );
		if ( $by_v <= self::FREE_PAGES && $by_i <= self::IP_FREE_PAGES ) {
			return;
		}
		$this->render_prompt();
		exit;
	}

	private function render_prompt(): void {
		$this->gated = true;   // D5: the prompt page must not carry a page pass
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		status_header( 200 );
		$here     = home_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) );
		$login    = wp_login_url( $here );
		$register = get_option( 'users_can_register' ) ? wp_registration_url() : $login;
		$title    = __( 'Sign in to keep exploring — it’s free', 'publictech-core' );
		$body     = __( 'You’ve viewed %d data pages today. Signing up is free. We ask everyone to sign in after a few pages to prevent automated scraping and abuse of this public-interest data — not to charge you.', 'publictech-core' );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		add_filter( 'pre_get_document_title', static fn() => $title );
		get_header();
		echo '<main class="pt-gate panel corner" style="max-width:640px;margin:48px auto;padding:32px;">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<p>' . esc_html( sprintf( $body, self::FREE_PAGES ) ) . '</p>';
		echo '<p class="pt-gate-actions"><a class="btn btn-primary" href="' . esc_url( $register ) . '">' . esc_html__( 'Sign up free', 'publictech-core' ) . '</a> ';
		echo '<a class="btn" href="' . esc_url( $login ) . '">' . esc_html__( 'Sign in', 'publictech-core' ) . '</a></p>';
		echo '<p class="pt-gate-note">' . esc_html__( 'Free accounts have no page limit. Paid tiers add bulk data access and exports, and come with a free trial.', 'publictech-core' ) . '</p>';
		echo '</main>';
		get_footer();
	}

	/* ---------- page passes for the page's own data requests ---------- */

	/**
	 * Pass for the current page. Scope:
	 *   "<id>"        MP profile: that member only.
	 *   "c<id>-<id>"  dashboard compare view (?compare=): only the compared members (max 4) — D4.
	 *   "x<id>"       Data Explorer / Query Builder page: may run the stream query endpoint within the visitor's tier
	 *                 (anonymous: 100 rows a query, 10 a minute, 200 a day), never an MP's data.
	 *   "p<id>"       any other data page: the page itself, never an MP's data.
	 * There is deliberately NO wildcard scope: a pass from one page must not unlock every member (matrix D4).
	 */
	public function current_pass(): string {
		$scope = 'p' . (int) get_queried_object_id();
		if ( is_page() && ( is_page_template( 'page-templates/template-data-explorer.php' ) || is_page_template( 'page-templates/template-query-builder.php' ) ) ) {
			$scope = 'x' . (int) get_queried_object_id();
		} elseif ( is_singular( 'member-parliament' ) ) {
			$scope = (string) (int) get_queried_object_id();
		} elseif ( isset( $_GET['compare'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only scope
			$ids = array_slice( array_values( array_filter( array_map( 'absint', explode( ',', (string) wp_unslash( $_GET['compare'] ) ) ),
				static fn( $id ) => $id && 'member-parliament' === get_post_type( $id ) ) ), 0, 4 );
			if ( $ids ) {
				sort( $ids );
				$scope = 'c' . implode( '-', $ids );
			}
		}
		$exp = time() + self::PASS_TTL;
		$who = is_user_logged_in() ? 'u' . get_current_user_id() : 'v' . $this->visitor_id();
		return $scope . '.' . $exp . '.' . $this->sign( $scope . '|' . $exp . '|' . $who );
	}

	/** Valid pass presented by this visitor; when $id >= 0 it must cover that member post id. */
	public function has_valid_pass( \WP_REST_Request $req, int $id = -1 ): bool {
		$pass = (string) $req->get_header( self::PASS_HEADER );
		if ( ! preg_match( '/^(\d+|c\d+(?:-\d+){0,3}|[px]\d+)\.(\d+)\.([a-f0-9]{32})$/', $pass, $m ) ) {
			return false;
		}
		[ , $p_scope, $p_exp, $p_mac ] = $m;
		if ( (int) $p_exp < time() ) {
			return false;
		}
		$who = is_user_logged_in() ? 'u' . get_current_user_id() : 'v' . $this->visitor_id();
		if ( ! hash_equals( $this->sign( $p_scope . '|' . $p_exp . '|' . $who ), $p_mac ) ) {
			return false;
		}
		if ( $id < 0 ) {
			return true;                                    // e.g. throttle: any genuine page pass
		}
		if ( ctype_digit( $p_scope ) ) {
			return (int) $p_scope === $id;
		}
		if ( 'c' === $p_scope[0] ) {
			return in_array( $id, array_map( 'intval', explode( '-', substr( $p_scope, 1 ) ) ), true );
		}
		return false;                                       // page-scoped passes never unlock member data
	}

	/** permission_callback for page-widget endpoints (/mp/{id}/spend, /mp/{id}/parliament, ...). */
	public static function widget_permission( \WP_REST_Request $req ): bool|\WP_Error {
		$gate = self::instance();
		if ( Security::verify_request( $req ) || $gate->is_verified_crawler() ) {
			return true;
		}
		$id = isset( $req['id'] ) ? (int) $req['id'] : -1;
		if ( $gate->has_valid_pass( $req, $id ) ) {
			return true;
		}
		return new \WP_Error( 'pt_page_pass_required',
			__( 'This data loads with its page. Open the page on govdata.ca, or sign in to use the data API.', 'publictech-core' ),
			[ 'status' => 401 ] );
	}

	/**
	 * Permission for the stream query endpoint: signed in (the data API follows the tier), or an anonymous visitor
	 * running queries from the Data Explorer / Query Builder page itself (an "x" page pass). The page view already
	 * counted toward the anonymous allowance; Throttle still applies the anonymous tier limits per request.
	 * Crawlers get no exemption here: they never need to run queries.
	 */
	public static function stream_permission( \WP_REST_Request $req ): bool|\WP_Error {
		if ( Security::verify_request( $req ) ) {
			return true;
		}
		$gate = self::instance();
		if ( preg_match( '/^x\d+\./', (string) $req->get_header( self::PASS_HEADER ) ) && $gate->has_valid_pass( $req ) ) {
			return true;
		}
		return new \WP_Error( 'pt_sign_in_required',
			__( 'Open the Data Explorer on govdata.ca to run queries, or sign in (free) to use the data API.', 'publictech-core' ),
			[ 'status' => 401 ] );
	}

	/** Make the page's own fetch() calls to the PublicTech REST namespace carry the pass. */
	public function print_pass_injector(): void {
		if ( $this->gated || ! $this->is_data_page() ) {
			return;
		}
		$pass = $this->current_pass();
		$ns   = esc_url_raw( rest_url( PUBLICTECH_CORE_REST_NS ) );
		printf(
			"<script>(function(){var P=%s,N=%s,f=window.fetch;window.PT_PAGE_PASS=P;window.fetch=function(u,o){try{var s=typeof u==='string'?u:(u&&u.url)||'';if(s.indexOf(N)===0||s.indexOf('/wp-json/publictech/')!==-1){o=o||{};var h=new Headers(o.headers||(u&&u.headers)||{});h.set('X-PT-Page-Pass',P);o.headers=h;}}catch(e){}return f.call(this,u,o);};})();</script>\n",
			wp_json_encode( $pass ), wp_json_encode( $ns )
		);
	}

	/* ---------- signed capture URLs (social cards, chart snapshots) ---------- */

	public function sign_capture_url( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$exp  = time() + self::SNAP_TTL;
		return add_query_arg( self::SNAP_QUERY, $exp . '.' . $this->sign( 'snap|' . $path . '|' . $exp ), $url );
	}

	public function has_valid_snapshot_signature(): bool {
		$sig = isset( $_GET[ self::SNAP_QUERY ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::SNAP_QUERY ] ) ) : '';
		if ( ! preg_match( '/^(\d+)\.([a-f0-9]{32})$/', $sig, $m ) || (int) $m[1] < time() ) {
			return false;
		}
		$path = strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), '?' );
		return hash_equals( $this->sign( 'snap|' . $path . '|' . $m[1] ), $m[2] );
	}

	/* ---------- crawlers: index pages, not the data API ---------- */

	public function robots_txt( string $out, bool $public ): string {
		if ( ! $public ) {
			return $out;
		}
		$ns  = '/wp-json/' . PUBLICTECH_CORE_REST_NS;
		$out .= "\n# PublicTech data API: pages are indexable, bulk data endpoints are not.\nUser-agent: *\n";
		foreach ( [ '/stream/', '/export/', '/queries', '/nlp/', '/ai/', '/schema/' ] as $p ) {
			$out .= 'Disallow: ' . $ns . $p . "\n";
		}
		return $out;
	}
}
