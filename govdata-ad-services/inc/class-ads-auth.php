<?php
/**
 * API key generation, storage, and verification for external site authentication.
 *
 * Keys are shown exactly once (after generation); only the SHA-256 hash is
 * stored. Comparison always uses hash_equals() to prevent timing attacks.
 *
 * @package GovAds
 */

namespace GovAds;

defined( 'ABSPATH' ) || exit;

final class Auth {

	private const OPTION_KEY = 'govads_api_keys';

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {}

	/**
	 * Generate a new API key for a partner site. Returns the raw key (shown once)
	 * and its hash (used as site_key in tracking rows).
	 *
	 * @return array{ raw: string, hash: string }
	 */
	public function generate_key( string $label, string $site_url = '' ): array {
		$raw  = 'gads_' . bin2hex( random_bytes( 32 ) );
		$hash = hash( 'sha256', $raw );
		$keys = $this->all_keys();

		$keys[] = [
			'hash'       => $hash,
			'label'      => sanitize_text_field( $label ),
			'site_url'   => esc_url_raw( $site_url ),
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
			'last_used'  => null,
			'active'     => true,
		];

		update_option( self::OPTION_KEY, $keys );

		return [ 'raw' => $raw, 'hash' => $hash ];
	}

	/**
	 * Verify an incoming raw API key from X-GovAds-Site-Key header.
	 * Returns the hash string (used as site_key for attribution) or false.
	 */
	public function verify_site_key( string $raw ): string|false {
		if ( '' === $raw || 0 !== strpos( $raw, 'gads_' ) ) {
			return false;
		}
		$hash = hash( 'sha256', $raw );
		foreach ( $this->all_keys() as $k ) {
			if ( ! empty( $k['active'] ) && isset( $k['hash'] ) && hash_equals( $k['hash'], $hash ) ) {
				// Defer last-used bump to shutdown so it doesn't add latency to every ad request.
				add_action( 'shutdown', fn() => $this->bump_last_used( $hash ) );
				return $hash;
			}
		}
		return false;
	}

	public function revoke_key( string $hash ): void {
		$keys = array_map(
			static function ( array $k ) use ( $hash ): array {
				if ( isset( $k['hash'] ) && hash_equals( $k['hash'], $hash ) ) {
					$k['active'] = false;
				}
				return $k;
			},
			$this->all_keys()
		);
		update_option( self::OPTION_KEY, $keys );
	}

	public function all_keys(): array {
		return (array) get_option( self::OPTION_KEY, [] );
	}

	private function bump_last_used( string $hash ): void {
		$keys = array_map(
			static function ( array $k ) use ( $hash ): array {
				if ( isset( $k['hash'] ) && hash_equals( $k['hash'], $hash ) ) {
					$k['last_used'] = gmdate( 'Y-m-d H:i:s' );
				}
				return $k;
			},
			$this->all_keys()
		);
		update_option( self::OPTION_KEY, $keys );
	}
}
