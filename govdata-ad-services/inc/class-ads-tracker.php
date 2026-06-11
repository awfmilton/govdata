<?php
/**
 * Impression and click event recording.
 *
 * Each event type has a per-session deduplication guard (transient, 1 hour)
 * and a per-IP rate limit (300 events/minute) to prevent log flooding.
 * IPs are anonymised before storage (PIPEDA-compliant).
 *
 * @package GovAds
 */

namespace GovAds;

defined( 'ABSPATH' ) || exit;

final class Tracker {

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {}

	public function record_impression( int $ad_id, int $zone_id, array $ctx ): bool {
		// Deduplicate: one impression per ad per session per hour.
		if ( ! $this->dedup_ok( 'imp', $ad_id, (string) ( $ctx['session_id'] ?? '' ) ) ) {
			return false;
		}
		global $wpdb;
		return (bool) $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			DB::table( 'impressions' ),
			$this->build_row( $ad_id, $zone_id, $ctx ),
			$this->row_formats()
		);
	}

	public function record_click( int $ad_id, int $zone_id, array $ctx ): bool {
		// Short dedup window: absorbs double-clicks and beacon retries.
		if ( ! $this->dedup_ok( 'clk', $ad_id, (string) ( $ctx['session_id'] ?? '' ), 10 ) ) {
			return false;
		}
		global $wpdb;
		$row               = $this->build_row( $ad_id, $zone_id, $ctx );
		$row['target_url'] = esc_url_raw( substr( (string) ( $ctx['target_url'] ?? '' ), 0, 1000 ) );
		return (bool) $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			DB::table( 'clicks' ),
			$row,
			array_merge( $this->row_formats(), [ '%s' ] )
		);
	}

	public function rate_limit_ok( string $ip ): bool {
		$key  = 'govads_rl_' . md5( $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= 300 ) {
			return false;
		}
		set_transient( $key, $hits + 1, MINUTE_IN_SECONDS );
		return true;
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	private function build_row( int $ad_id, int $zone_id, array $ctx ): array {
		$campaign_id = (int) get_post_meta( $ad_id, 'govads_ad_campaign_id', true );
		return [
			'ad_id'      => $ad_id,
			'zone_id'    => $zone_id,
			'campaign_id'=> $campaign_id,
			'site_key'   => sanitize_text_field( substr( (string) ( $ctx['site_key']   ?? '' ), 0, 64 ) ),
			'session_id' => sanitize_text_field( substr( (string) ( $ctx['session_id'] ?? '' ), 0, 64 ) ),
			'user_id'    => get_current_user_id(),
			'ip_address' => $this->anonymize_ip( $this->client_ip() ),
			'user_agent' => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 500 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'page_url'   => esc_url_raw( substr( (string) ( $ctx['page_url']   ?? '' ), 0, 1000 ) ),
			'referrer'   => esc_url_raw( substr( (string) ( $ctx['referrer']   ?? '' ), 0, 1000 ) ),
		];
	}

	private function row_formats(): array {
		return [ '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ];
	}

	/** Session-based dedup: one event per ad per session inside the TTL window. */
	private function dedup_ok( string $type, int $ad_id, string $session_id, int $ttl = HOUR_IN_SECONDS ): bool {
		if ( '' === $session_id ) {
			return true; // Can't dedup without a session.
		}
		$key = 'govads_' . $type . '_' . md5( $ad_id . ':' . $session_id );
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, $ttl );
		return true;
	}

	private function client_ip(): string {
		return (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	private function anonymize_ip( string $ip ): string {
		if ( '' === $ip ) {
			return '';
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts    = explode( '.', $ip );
			$parts[3] = '0';
			return implode( '.', $parts );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencingOperator
			if ( false !== $bin ) {
				$masked = substr( $bin, 0, 6 ) . str_repeat( "\0", 10 );
				$out    = @inet_ntop( $masked ); // phpcs:ignore WordPress.PHP.NoSilencingOperator
				return false !== $out ? (string) $out : '';
			}
		}
		return '';
	}
}
