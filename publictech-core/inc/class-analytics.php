<?php
/**
 * Analytics event storage.
 *
 * Creates and writes to {prefix}pt_analytics_events.
 * Table is created lazily on first write — no activation hook required
 * (this is a theme, not a plugin).
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Analytics {

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	/** Option key used to flag that the table has been created. */
	private const INSTALLED_KEY = 'pt_analytics_installed_v1';

	/** Cron hook that purges aged analytics rows. */
	private const PURGE_HOOK = 'publictech_purge_analytics';

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
		add_action( 'init', [ $this, 'schedule_retention' ] );
		add_action( self::PURGE_HOOK, [ $this, 'purge_old_events' ] );
	}

	// ── REST route ────────────────────────────────────────────────────────────

	public function register_route(): void {
		register_rest_route( PUBLICTECH_CORE_REST_NS, '/analytics/event', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'permission_callback' => '__return_true', // public — rate-limited below
			'callback'            => [ $this, 'handle_event' ],
		] );
	}

	public function handle_event( \WP_REST_Request $req ): \WP_REST_Response {
		// Lightweight rate-limit: max 120 events/minute per IP (prevents log flooding).
		$ip      = $this->client_ip();
		$rl_key  = 'pt_anlytcs_' . md5( $ip );
		$hits    = (int) get_transient( $rl_key );
		if ( $hits >= 120 ) {
			return new \WP_REST_Response( [ 'ok' => false ], 429 );
		}
		set_transient( $rl_key, $hits + 1, 60 );

		$body         = $req->get_json_params();
		$event_type   = sanitize_key( (string) ( $body['event_type']   ?? 'unknown' ) );
		$session_id   = sanitize_text_field( (string) ( $body['session_id']  ?? '' ) );
		$page         = esc_url_raw( (string) ( $body['page']          ?? '' ) );
		$referrer     = esc_url_raw( (string) ( $body['referrer']      ?? '' ) );
		$browser_data = wp_json_encode( $body['browser'] ?? [] );
		$event_data   = wp_json_encode( $body['data']    ?? [] );

		$uid  = get_current_user_id();
		$tier = Access_Tiers::instance()->user_tier( $uid ?: null );

		$this->maybe_create_table();
		$this->insert( [
			'session_id'   => $session_id,
			'user_id'      => $uid,
			'user_tier'    => $tier,
			'event_type'   => $event_type,
			'page'         => $page,
			'referrer'     => $referrer,
			// Store an anonymised IP (last octet / IPv6 suffix zeroed) — coarse
			// enough for geo/abuse signal, not personally identifying (PIPEDA).
			'ip_address'   => $this->anonymize_ip( $ip ),
			'user_agent'   => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 500 ),
			'browser_data' => $browser_data,
			'event_data'   => $event_data,
		] );

		return new \WP_REST_Response( [ 'ok' => true ], 201 );
	}

	// ── Storage ───────────────────────────────────────────────────────────────

	private function insert( array $row ): void {
		global $wpdb;
		$wpdb->insert( $this->table_name(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'pt_analytics_events';
	}

	private function maybe_create_table(): void {
		if ( get_option( self::INSTALLED_KEY ) ) {
			return;
		}
		global $wpdb;
		$t       = $this->table_name();
		$charset = $wpdb->get_charset_collate();

		// dbDelta is the WP-safe way to create/update tables.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$t} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id   VARCHAR(64)     NOT NULL DEFAULT '',
			user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_tier    VARCHAR(20)     NOT NULL DEFAULT 'anonymous',
			event_type   VARCHAR(50)     NOT NULL DEFAULT '',
			page         VARCHAR(500)    NOT NULL DEFAULT '',
			referrer     VARCHAR(1000)   NOT NULL DEFAULT '',
			ip_address   VARCHAR(45)     NOT NULL DEFAULT '',
			user_agent   TEXT,
			browser_data LONGTEXT,
			event_data   LONGTEXT,
			created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_session    (session_id),
			KEY idx_user       (user_id),
			KEY idx_event_type (event_type),
			KEY idx_created    (created_at)
		) {$charset};" );

		update_option( self::INSTALLED_KEY, true );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function client_ip(): string {
		// Trusted-proxy aware (see helpers.php client_ip()); not spoofable by
		// an anonymous caller, so the per-IP flood limiter above is meaningful.
		return client_ip();
	}

	/**
	 * Mask an IP for at-rest storage: zero the last octet of IPv4 and the last
	 * 80 bits of IPv6 (keep /48). Reversible to a network, not to a person.
	 */
	private function anonymize_ip( string $ip ): string {
		if ( '' === $ip || '0.0.0.0' === $ip ) {
			return '';
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts      = explode( '.', $ip );
			$parts[3]   = '0';
			return implode( '.', $parts );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = @inet_pton( $ip );
			if ( false !== $bin ) {
				$masked = substr( $bin, 0, 6 ) . str_repeat( "\0", 10 );
				$out    = @inet_ntop( $masked );
				return false !== $out ? $out : '';
			}
		}
		return '';
	}

	// ── Retention ───────────────────────────────────────────────────────────────

	/** Ensure the daily purge job is scheduled. */
	public function schedule_retention(): void {
		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	/**
	 * Delete analytics rows older than the retention window (default 90 days,
	 * filterable; 0 disables purging). Runs from cron.
	 */
	public function purge_old_events(): void {
		if ( ! get_option( self::INSTALLED_KEY ) ) {
			return;
		}
		$days = (int) apply_filters( 'publictech_analytics_retention_days', 90 );
		if ( $days <= 0 ) {
			return;
		}
		global $wpdb;
		$table = $this->table_name();
		// Identifier is theme-controlled (wpdb prefix); value is bound.
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE created_at < ( UTC_TIMESTAMP() - INTERVAL %d DAY )", $days ) ); // phpcs:ignore WordPress.DB
	}
}
