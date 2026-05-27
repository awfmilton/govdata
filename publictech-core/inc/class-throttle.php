<?php
/**
 * Tiered request throttle.
 *
 * Uses two sliding-window counters (per-minute and per-day) keyed by a salted
 * hash of user-id + IP + tier. Counters live in the object cache when one is
 * configured (Redis/Memcache); otherwise they fall back to transients. The
 * caller is responsible for invoking enforce() at the boundary — REST_API and
 * the MCP server both do so before dispatching work to the multi-DB layer.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Throttle {
	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }
	private function __construct() {}

	/**
	 * @return array{allowed:bool,retry_after:int,minute_used:int,day_used:int,quota:array<string,mixed>}
	 */
	public function check( ?int $user_id, string $ip ): array {
		$tiers = Access_Tiers::instance();
		$tier  = $tiers->user_tier( $user_id );
		$quota = $tiers->quota( $user_id );

		$key_min = 'pt_tm_' . substr( bucket_key( $user_id, $ip, $tier ), 0, 32 ) . '_' . ( time() - ( time() % 60 ) );
		$key_day = 'pt_td_' . substr( bucket_key( $user_id, $ip, $tier ), 0, 32 ) . '_' . gmdate( 'Ymd' );

		$min_used = (int) $this->incr( $key_min, 60 );
		$day_used = (int) $this->incr( $key_day, 86400 );

		$retry = 0;
		$ok    = true;
		if ( $min_used > $quota['rpm'] ) {
			$ok    = false;
			$retry = 60 - ( time() % 60 );
		} elseif ( $day_used > $quota['rpd'] ) {
			$ok    = false;
			$retry = ( ( gmmktime( 0, 0, 0 ) + 86400 ) - time() );
		}

		return [
			'allowed'     => $ok,
			'retry_after' => $retry,
			'minute_used' => $min_used,
			'day_used'    => $day_used,
			'quota'       => $quota,
		];
	}

	/**
	 * Enforce in a REST context. Throws a WP_Error wrapped exception via
	 * rest_ensure_response semantics — call from the route handler.
	 */
	public function enforce_rest( \WP_REST_Request $req ): true|\WP_Error {
		$state = $this->check( get_current_user_id() ?: null, $this->ip() );
		if ( $state['allowed'] ) {
			return true;
		}
		return new \WP_Error( 'pt_throttled', __( 'Rate limit exceeded for your tier.', 'publictech-core' ), [
			'status'      => 429,
			'retry_after' => $state['retry_after'],
			'tier'        => $state['quota']['label'],
		] );
	}

	private function incr( string $key, int $ttl ): int {
		if ( wp_using_ext_object_cache() ) {
			$val = wp_cache_get( $key, 'publictech' );
			if ( false === $val ) {
				wp_cache_add( $key, 0, 'publictech', $ttl );
				$val = 0;
			}
			wp_cache_incr( $key, 1, 'publictech' );
			return (int) $val + 1;
		}
		$val = (int) get_transient( $key );
		set_transient( $key, $val + 1, $ttl );
		return $val + 1;
	}

	private function ip(): string {
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$raw = sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) );
				$ip  = trim( explode( ',', $raw )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}
}
