<?php
/** Optional online Analyst access. No persistent plan or subscription writes. */
namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Longplay_Access {

	public static function configured(): bool {
		return (bool) getenv( 'LONGPLAY_ANALYST_FEATURE_ID' );
	}

	public static function allows( int $user_id ): bool {
		// Request-local only: throttle and quota reads must agree on one observation.
		static $observed = [];
		if ( ! array_key_exists( $user_id, $observed ) ) {
			$config = [];
			foreach ( [ 'BASE_URL', 'APP_ID', 'SECRET_KEY', 'ENVIRONMENT', 'ANALYST_FEATURE_ID' ] as $key ) {
				$config[ $key ] = (string) getenv( 'LONGPLAY_' . $key );
			}
			$observed[ $user_id ] = self::check( $user_id, $config );
		}
		return $observed[ $user_id ];
	}

	/** Use the trusted WordPress user ID, never an email or browser Customer selector. */
	public static function check( int $user_id, array $config, ?callable $post = null ): bool {
		if ( $user_id < 1 || empty( $config['ANALYST_FEATURE_ID'] ) ) {
			return false;
		}
		try {
			$base = parse_url( $config['BASE_URL'] ?? '' );
			if ( ! $base || ( $base['scheme'] ?? '' ) !== 'https' || empty( $base['host'] ) ||
				isset( $base['user'] ) || isset( $base['pass'] ) || isset( $base['query'] ) || isset( $base['fragment'] ) ||
				! in_array( $base['path'] ?? '/', [ '', '/' ], true ) || empty( $config['APP_ID'] ) ||
				! preg_match( '/^gg_sk_[A-Za-z0-9_-]{32,128}$/', $config['SECRET_KEY'] ?? '' ) ||
				! in_array( $config['ENVIRONMENT'] ?? '', [ 'sandbox', 'live' ], true ) ||
				! self::uuid( $config['ANALYST_FEATURE_ID'] ) ) {
				return false;
			}
			$post ??= 'wp_remote_post';
			$request = static function ( string $path, array $body ) use ( $config, $post ): array {
				$response = $post( rtrim( $config['BASE_URL'], '/' ) . '/api/v1/' . $path, [
					'timeout' => 3, 'redirection' => 0, 'reject_unsafe_urls' => true, 'limit_response_size' => 262144,
					'headers' => [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $config['SECRET_KEY'],
						'X-Indiana-Protocol' => '0.10', 'X-Indiana-Project' => $config['APP_ID'], 'X-Indiana-Environment' => $config['ENVIRONMENT'] ],
					'body' => wp_json_encode( $body ),
				] );
				if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 300 ||
					wp_remote_retrieve_header( $response, 'x-indiana-protocol' ) !== '0.10' ) {
					throw new \RuntimeException( 'Unavailable' );
				}
				$data = json_decode( wp_remote_retrieve_body( $response ), true, 32, JSON_THROW_ON_ERROR );
				if ( ! self::uuid( $data['requestId'] ?? '' ) || ( $data['scope']['projectId'] ?? '' ) !== $config['APP_ID'] ||
					( $data['scope']['environment'] ?? '' ) !== $config['ENVIRONMENT'] ) {
					throw new \RuntimeException( 'Scope mismatch' );
				}
				return $data;
			};
			$customer = $request( 'customers', [ 'externalUserId' => 'publictech:user:' . $user_id ] );
			if ( ! self::uuid( $customer['customer']['id'] ?? '' ) ) {
				return false;
			}
			$result = $request( 'entitlements/check', [ 'customerId' => $customer['customer']['id'], 'requirement' => [
				'internal_feature_id' => $config['ANALYST_FEATURE_ID'], 'quantity' => '1', 'properties' => (object) [],
			] ] );
			return ( $result['check']['kind'] ?? '' ) === 'access' &&
				( $result['check']['internal_feature_id'] ?? '' ) === $config['ANALYST_FEATURE_ID'] &&
				( $result['check']['allowed'] ?? false ) === true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	private static function uuid( string $value ): bool {
		return (bool) preg_match( '/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $value );
	}
}
