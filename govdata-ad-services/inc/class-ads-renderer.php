<?php
/**
 * Ad selection, token generation, and HTML rendering.
 *
 * Handles zone lookup (5-minute transient), ad pool selection (60-second
 * transient for weighted-random pool), and HMAC impression token generation.
 * The PHP layer only renders the placeholder div; the JS loader fills it
 * asynchronously after page load, preventing any render-blocking.
 *
 * @package GovAds
 */

namespace GovAds;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	/** Standard IAB sizes [width, height]. */
	public const SIZES = [
		'300x250' => [ 300, 250 ],
		'728x90'  => [ 728,  90 ],
		'160x600' => [ 160, 600 ],
		'300x600' => [ 300, 600 ],
		'970x90'  => [ 970,  90 ],
		'320x50'  => [ 320,  50 ],
		'336x280' => [ 336, 280 ],
		'250x250' => [ 250, 250 ],
		'120x600' => [ 120, 600 ],
	];

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {}

	/**
	 * Echo the layout-reserved placeholder div for a zone.
	 * Called by do_action('govads_zone', $slug). No-op if zone doesn't exist.
	 */
	public function render_zone( string $zone_slug ): void {
		$zone = $this->get_zone( $zone_slug );
		if ( ! $zone ) {
			return;
		}

		$primary_size = $zone['sizes'][0] ?? '300x250';
		$dims         = self::SIZES[ $primary_size ] ?? [ 300, 250 ];

		printf(
			'<div class="govads-slot" data-zone="%s" data-zone-id="%d" style="width:%dpx;height:%dpx;min-height:%dpx;" aria-label="%s">%s</div>' . "\n",
			esc_attr( $zone_slug ),
			(int) $zone['id'],
			(int) $dims[0],
			(int) $dims[1],
			(int) $dims[1],
			esc_attr__( 'Advertisement', 'govdata-ad-services' ),
			$this->noscript_fallback( $zone_slug )
		);
	}

	/**
	 * Select an ad for the given zone and return its REST payload.
	 * Returns null if the zone is unknown or no eligible ads exist.
	 *
	 * @return array<string,mixed>|null
	 */
	public function select_for_zone( string $zone_slug, string $site_key = '' ): array|null {
		$zone = $this->get_zone( $zone_slug );
		if ( ! $zone ) {
			return null;
		}

		$pool = $this->get_ad_pool( $zone['id'], $zone['sizes'] );
		if ( empty( $pool ) ) {
			return null;
		}

		$ad_id = $this->weighted_random( $pool );
		$ad    = get_post( $ad_id );
		if ( ! $ad ) {
			return null;
		}

		$meta        = get_post_meta( $ad_id );
		$size        = (string) ( $meta['govads_ad_size'][0] ?? '300x250' );
		$dims        = self::SIZES[ $size ] ?? [ 300, 250 ];
		$ad_type     = (string) ( $meta['govads_ad_type'][0] ?? 'image' );
		$campaign_id = (int) ( $meta['govads_ad_campaign_id'][0] ?? 0 );

		return [
			'ad_id'            => $ad_id,
			'zone_id'          => $zone['id'],
			'campaign_id'      => $campaign_id,
			'size'             => $size,
			'width'            => $dims[0],
			'height'           => $dims[1],
			'type'             => $ad_type,
			'image_url'        => esc_url( (string) ( $meta['govads_ad_image_url'][0] ?? '' ) ),
			'html'             => 'html5' === $ad_type
				? wp_kses_post( (string) ( $meta['govads_ad_html'][0] ?? '' ) )
				: null,
			'target_url'       => esc_url( (string) ( $meta['govads_ad_target_url'][0] ?? '' ) ),
			'impression_token' => $this->generate_token( $ad_id, $zone['id'], $site_key ),
		];
	}

	/**
	 * Generate a time-limited HMAC impression token.
	 * Payload: base64(ad_id:zone_id:timestamp:site_key:hmac).
	 *
	 * The site key hash is baked into the token so /track can attribute the
	 * event cryptographically — partner pages never see the raw API key.
	 */
	public function generate_token( int $ad_id, int $zone_id, string $site_key = '' ): string {
		$ts      = time();
		$payload = "$ad_id:$zone_id:$ts:$site_key";
		$sig     = hash_hmac( 'sha256', $payload, GOVADS_HMAC_KEY );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( "$payload:$sig" );
	}

	/**
	 * Verify an impression token and return the site key hash it was issued
	 * for ('' = our own site), or false when the token is invalid/expired.
	 *
	 * The validity window is filterable: partner sites embed the token at
	 * page-render time, so it must survive lazy slots scrolled into view
	 * long after load. Replay is bounded by session dedup + IP rate limits.
	 */
	public function verify_token( string $token, int $ad_id, int $zone_id ): string|false {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded = base64_decode( $token, true );
		if ( false === $decoded ) {
			return false;
		}
		$parts = explode( ':', $decoded, 5 );
		if ( 5 !== count( $parts ) ) {
			return false;
		}
		[ $t_ad, $t_zone, $ts, $site_key, $sig ] = $parts;
		if ( (int) $t_ad !== $ad_id || (int) $t_zone !== $zone_id ) {
			return false;
		}
		$ttl = (int) apply_filters( 'govads_token_ttl', HOUR_IN_SECONDS );
		if ( abs( time() - (int) $ts ) > $ttl ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', "$t_ad:$t_zone:$ts:$site_key", GOVADS_HMAC_KEY );
		return hash_equals( $expected, $sig ) ? $site_key : false;
	}

	/** Delete the ad-pool transients for all published zones (status changes). */
	public function flush_pools(): void {
		$zone_ids = get_posts(
			[
				'post_type'      => 'govads_zone',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		foreach ( $zone_ids as $zid ) {
			delete_transient( 'govads_pool_' . $zid );
		}
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/** Look up zone by slug. Cached 5 minutes. */
	private function get_zone( string $slug ): array|null {
		$cache_key = 'govads_zone_' . sanitize_key( $slug );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		$posts = get_posts(
			[
				'post_type'      => 'govads_zone',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				// phpcs:disable WordPress.DB.SlowDBQuery
				'meta_key'       => 'govads_zone_slug',
				'meta_value'     => $slug,
				// phpcs:enable WordPress.DB.SlowDBQuery
			]
		);

		if ( empty( $posts ) ) {
			set_transient( $cache_key, false, 5 * MINUTE_IN_SECONDS );
			return null;
		}

		$zone_id = $posts[0]->ID;
		$sizes   = get_post_meta( $zone_id, 'govads_zone_sizes', true );
		$result  = [
			'id'        => $zone_id,
			'slug'      => $slug,
			'label'     => (string) ( get_post_meta( $zone_id, 'govads_zone_label', true ) ?: $slug ),
			'sizes'     => is_array( $sizes ) && ! empty( $sizes ) ? $sizes : [ '300x250' ],
			'placement' => (string) ( get_post_meta( $zone_id, 'govads_zone_placement', true ) ?: 'sidebar' ),
		];

		set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Build a weighted pool of ad IDs for a zone.
	 * Only ads whose IAB size is allowed by the zone are eligible, so a
	 * leaderboard creative can never be squeezed into a sidebar slot.
	 * Each ad appears in the array once per unit of weight.
	 * Cached 60 seconds so active ad sets rotate quickly but DB isn't hit every request.
	 *
	 * @param string[] $sizes Allowed IAB sizes for the zone, e.g. ['300x250'].
	 * @return int[]
	 */
	private function get_ad_pool( int $zone_id, array $sizes ): array {
		$cache_key = 'govads_pool_' . $zone_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		$today = gmdate( 'Y-m-d' );

		$ads = get_posts(
			[
				'post_type'      => 'govads_ad',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
				// phpcs:disable WordPress.DB.SlowDBQuery
				'meta_query'     => [
					'relation' => 'AND',
					[
						'key'     => 'govads_ad_status',
						'value'   => 'approved',
						'compare' => '=',
					],
					[
						'key'     => 'govads_ad_size',
						'value'   => array_values( array_filter( array_map( 'strval', $sizes ) ) ) ?: [ '300x250' ],
						'compare' => 'IN',
					],
					[
						'relation' => 'OR',
						[ 'key' => 'govads_ad_start_date', 'compare' => 'NOT EXISTS' ],
						[ 'key' => 'govads_ad_start_date', 'value' => '', 'compare' => '=' ],
						[ 'key' => 'govads_ad_start_date', 'value' => $today, 'compare' => '<=', 'type' => 'DATE' ],
					],
					[
						'relation' => 'OR',
						[ 'key' => 'govads_ad_end_date', 'compare' => 'NOT EXISTS' ],
						[ 'key' => 'govads_ad_end_date', 'value' => '', 'compare' => '=' ],
						[ 'key' => 'govads_ad_end_date', 'value' => $today, 'compare' => '>=', 'type' => 'DATE' ],
					],
				],
				// phpcs:enable WordPress.DB.SlowDBQuery
			]
		);

		$pool = [];
		foreach ( $ads as $ad ) {
			$weight = max( 1, (int) get_post_meta( $ad->ID, 'govads_ad_weight', true ) );
			for ( $i = 0; $i < $weight; $i++ ) {
				$pool[] = $ad->ID;
			}
		}

		set_transient( $cache_key, $pool, 60 );
		return $pool;
	}

	/** Pick one ad ID from the weighted pool. */
	private function weighted_random( array $pool ): int {
		return $pool[ array_rand( $pool ) ];
	}

	/** <noscript> fallback — filterable house-ad link. */
	private function noscript_fallback( string $zone_slug ): string {
		$url = (string) apply_filters( 'govads_noscript_url', '', $zone_slug );
		if ( ! $url ) {
			return '';
		}
		return '<noscript><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener sponsored">'
			. esc_html__( 'Advertisement', 'govdata-ad-services' )
			. '</a></noscript>';
	}
}
