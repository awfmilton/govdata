<?php
/**
 * Site-wide WordPress hardening. Complements class-security.php (which deals
 * with SQL/REST request-level safety) by tightening front-end signals,
 * disabling rarely-used legacy surfaces (XML-RPC, RSD, WLW, user enumeration),
 * and emitting modern security response headers on every page load.
 *
 * Anything that can break a site if misconfigured (HSTS, CSP) is opt-in
 * via filter so site owners stay in control.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Hardening {
	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		// --- Suppress identifying / legacy metadata in <head> -------------
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		add_filter( 'the_generator', '__return_empty_string' );
		add_filter( 'style_loader_src',  [ $this, 'strip_version_arg' ], 999, 1 );
		add_filter( 'script_loader_src', [ $this, 'strip_version_arg' ], 999, 1 );

		// --- Kill XML-RPC and pingbacks (CVE history, brute-force vector) -
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'wp_xmlrpc_server_class', '__return_false' );
		add_filter( 'xmlrpc_methods', static function ( array $methods ): array {
			unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
			return $methods;
		} );
		// Strip the X-Pingback response header.
		add_filter( 'wp_headers', static function ( array $h ): array {
			unset( $h['X-Pingback'] );
			return $h;
		} );

		// --- Block author enumeration via ?author=N and the users REST API.
		add_action( 'template_redirect',  [ $this, 'block_author_enumeration' ] );
		add_filter( 'rest_endpoints',     [ $this, 'restrict_users_endpoint' ] );
		add_filter( 'rest_authentication_errors', [ $this, 'gate_anonymous_rest' ] );

		// --- Don't leak whether a login failed because of user vs. password.
		add_filter( 'login_errors', static function (): string {
			return __( 'Invalid credentials.', 'publictech-core' );
		} );

		// --- Refuse the admin file editor (also enforce via constant).
		if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}

		// --- Hardened cookie flags when over HTTPS.
		add_action( 'set_auth_cookie',   [ $this, 'note_cookie_set' ] );
		add_action( 'auth_cookie_valid', [ $this, 'note_cookie_set' ] );

		// --- Response headers (front-end + REST). The send_headers hook
		// covers front-end views; rest_post_dispatch covers JSON responses.
		add_action( 'send_headers',      [ $this, 'send_security_headers' ] );
		add_filter( 'rest_post_dispatch', [ $this, 'send_security_headers_rest' ], 10, 3 );
	}

	/**
	 * Strip ?ver= from enqueued assets so WordPress and plugin versions
	 * aren't fingerprintable from the public asset URLs.
	 */
	public function strip_version_arg( $src ): string {
		if ( ! is_string( $src ) || '' === $src ) {
			return (string) $src;
		}
		if ( str_contains( $src, 'ver=' ) ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	}

	/**
	 * Refuse `?author=<n>` URLs from unauthenticated visitors — they 302 to
	 * the author archive and disclose login names.
	 */
	public function block_author_enumeration(): void {
		if ( is_admin() || is_user_logged_in() ) {
			return;
		}
		if ( isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	/**
	 * Hide /wp/v2/users from anonymous callers. Logged-in editors+ can still
	 * use it (the page editor needs it).
	 */
	public function restrict_users_endpoint( array $endpoints ): array {
		if ( is_user_logged_in() && current_user_can( 'list_users' ) ) {
			return $endpoints;
		}
		foreach ( [ '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' ] as $route ) {
			unset( $endpoints[ $route ] );
		}
		return $endpoints;
	}

	/**
	 * The publictech/v1 + publictech-mcp/v1 + wp/v2 namespaces gate their own
	 * sensitive routes; this filter only blocks unauthenticated access to the
	 * legacy site-meta dump at /wp-json/ root when filtered on by the owner.
	 */
	public function gate_anonymous_rest( $result ) {
		if ( ! empty( $result ) ) {
			return $result;
		}
		if ( ! apply_filters( 'publictech_require_rest_auth', false ) ) {
			return $result;
		}
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_not_logged_in',
				__( 'Authentication required.', 'publictech-core' ),
				[ 'status' => 401 ]
			);
		}
		return $result;
	}

	/**
	 * Front-end response headers. Conservative defaults; HSTS / CSP are
	 * filter-gated because they can break a site if the wrong value lands.
	 */
	public function send_security_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		$headers = $this->default_headers();
		foreach ( $headers as $k => $v ) {
			if ( '' !== $v ) {
				header( "$k: $v" );
			}
		}
	}

	/**
	 * Same header set, applied to REST responses (where send_headers doesn't
	 * fire reliably).
	 */
	public function send_security_headers_rest( \WP_HTTP_Response $response, $server, $request ): \WP_HTTP_Response {
		foreach ( $this->default_headers() as $k => $v ) {
			if ( '' !== $v ) {
				$response->header( $k, $v, true );
			}
		}
		return $response;
	}

	/**
	 * @return array<string,string>
	 */
	private function default_headers(): array {
		$defaults = [
			'X-Content-Type-Options' => 'nosniff',
			'X-Frame-Options'        => 'SAMEORIGIN',
			'Referrer-Policy'        => 'strict-origin-when-cross-origin',
			'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
			'Cross-Origin-Opener-Policy' => 'same-origin',
			'Cross-Origin-Resource-Policy' => 'same-origin',
		];

		// HSTS — only when the request is HTTPS; off by default but easy to
		// turn on once a site has verified its certs and subdomains.
		if ( is_ssl() && apply_filters( 'publictech_enable_hsts', false ) ) {
			$defaults['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
		}

		// Optional Content-Security-Policy — empty by default so the owner
		// opts in with the right allow-list for their site / CDN.
		$csp = (string) apply_filters( 'publictech_content_security_policy', '' );
		if ( '' !== $csp ) {
			$defaults['Content-Security-Policy'] = $csp;
		}

		/**
		 * Final escape hatch — let sites add/remove/override any header.
		 *
		 * @param array<string,string> $defaults
		 */
		return (array) apply_filters( 'publictech_security_headers', $defaults );
	}

	/** No-op marker hook — placeholder for future cookie audits. */
	public function note_cookie_set(): void {}
}
