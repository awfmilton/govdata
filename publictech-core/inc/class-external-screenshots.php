<?php
/**
 * Screenshot provider orchestrator.
 *
 * Routes capture calls through the configured provider chain. Provider mode is
 * stored in the `pt_screenshot_provider_mode` option and controlled via the
 * External Screenshots admin page.
 *
 *   auto (default)   — ApiFlash first; falls back to GCP on quota exhaustion
 *   apiflash_only    — ApiFlash only; errors if quota is exhausted
 *   gcp_only         — GCP Cloud Run only; skips ApiFlash entirely
 *
 * Callers pass params in ApiFlash format; the orchestrator maps them to GCP
 * format when routing to the GCP provider.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class External_Screenshots {

	/** WP option name for the provider mode. */
	public const OPT_MODE = 'pt_screenshot_provider_mode';

	public static function provider_mode(): string {
		$mode = (string) get_option( self::OPT_MODE, 'auto' );
		return in_array( $mode, [ 'auto', 'apiflash_only', 'gcp_only' ], true ) ? $mode : 'auto';
	}

	/** True if at least one provider is ready for the current mode. */
	public static function is_configured(): bool {
		$mode = self::provider_mode();
		if ( 'gcp_only' === $mode ) {
			return GCP_Screenshot::is_configured();
		}
		if ( 'apiflash_only' === $mode ) {
			return ApiFlash::is_configured();
		}
		return ApiFlash::is_configured() || GCP_Screenshot::is_configured();
	}

	/**
	 * Which provider would be used for the next capture?
	 *
	 * @return 'apiflash'|'gcp'|'none'
	 */
	public static function active_provider(): string {
		$mode = self::provider_mode();
		if ( 'gcp_only' === $mode ) {
			return GCP_Screenshot::is_configured() ? 'gcp' : 'none';
		}
		if ( 'apiflash_only' === $mode ) {
			return ApiFlash::is_configured() ? 'apiflash' : 'none';
		}
		// auto: prefer ApiFlash when it has quota remaining.
		if ( ApiFlash::is_configured() ) {
			$quota = ApiFlash::quota();
			if ( ! is_wp_error( $quota ) && (int) $quota['remaining'] > 0 ) {
				return 'apiflash';
			}
		}
		return GCP_Screenshot::is_configured() ? 'gcp' : 'none';
	}

	/**
	 * Capture a screenshot, routing through the active provider chain.
	 *
	 * Pass params in ApiFlash format (format, width, height, wait_until, delay,
	 * fresh …). The orchestrator translates them for GCP when needed.
	 *
	 * The returned array includes a `provider` key ('apiflash' or 'gcp') so
	 * callers can adapt if necessary.
	 *
	 * @param string               $url
	 * @param array<string,scalar> $params
	 *
	 * @return array{code:int,body:string,content_type:string,provider:string}|\WP_Error
	 */
	public static function capture( string $url, array $params = [] ) {
		$mode = self::provider_mode();

		if ( 'gcp_only' === $mode ) {
			return self::via_gcp( $url, $params );
		}

		if ( 'apiflash_only' === $mode ) {
			if ( ! ApiFlash::is_configured() ) {
				return new \WP_Error( 'pt_screenshot_unconfigured', __( 'ApiFlash is not configured (apiflash_only mode).', 'publictech-core' ) );
			}
			$res = ApiFlash::capture( $url, $params );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return array_merge( $res, [ 'provider' => 'apiflash' ] );
		}

		// auto: try ApiFlash first.
		if ( ApiFlash::is_configured() ) {
			$quota     = ApiFlash::quota();
			$has_quota = ! is_wp_error( $quota ) && (int) $quota['remaining'] > 0;
			if ( $has_quota ) {
				$res = ApiFlash::capture( $url, $params );
				if ( ! is_wp_error( $res ) && 402 !== (int) $res['code'] ) {
					return array_merge( $res, [ 'provider' => 'apiflash' ] );
				}
				error_log( 'PublicTech: ApiFlash quota exhausted (HTTP 402); falling back to GCP Cloud Run.' );
			} else {
				error_log( 'PublicTech: ApiFlash quota at 0; routing to GCP Cloud Run.' );
			}
		}

		if ( GCP_Screenshot::is_configured() ) {
			return self::via_gcp( $url, $params );
		}

		return new \WP_Error(
			'pt_screenshot_unavailable',
			__( 'No screenshot provider is available. Configure ApiFlash or GCP Cloud Run in External Screenshots.', 'publictech-core' )
		);
	}

	/**
	 * Map ApiFlash-style params to GCP params and run the GCP capture.
	 *
	 * @param array<string,scalar> $params
	 *
	 * @return array{code:int,body:string,content_type:string,provider:string}|\WP_Error
	 */
	private static function via_gcp( string $url, array $params ) {
		$gcp = [];

		// format → type (png / jpeg).
		if ( isset( $params['format'] ) ) {
			$gcp['type'] = (string) $params['format'];
		}

		// wait_until=network_idle → delay (default 3 s); otherwise pass delay directly.
		if ( isset( $params['wait_until'] ) && 'network_idle' === $params['wait_until'] ) {
			$gcp['delay'] = isset( $params['delay'] ) ? (int) $params['delay'] : 3;
		} elseif ( isset( $params['delay'] ) ) {
			$gcp['delay'] = (int) $params['delay'];
		}

		// Direct pass-through params.
		foreach ( [ 'width', 'height', 'scaleFactor', 'fullPage', 'timeout', 'darkMode', 'hideElements', 'plainPuppeteer' ] as $k ) {
			if ( isset( $params[ $k ] ) ) {
				$gcp[ $k ] = $params[ $k ];
			}
		}

		$res = GCP_Screenshot::capture( $url, $gcp );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array_merge( $res, [ 'provider' => 'gcp' ] );
	}
}
