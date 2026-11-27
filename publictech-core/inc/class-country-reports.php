<?php
/**
 * Country pages: /countries/ (index and cross-country analysis), /country/{slug}/ (one country) and /foreign-aid/.
 *
 * Every figure is precomputed on govdata-serving by database/immigration (run.py countries / report) into
 * grantconts.cty_report; this class only reads those JSON payloads (read-only connection, cached) and routes the
 * two URLs to the theme templates country-index.php and country-report.php.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Country_Reports {

	public const QV_COUNTRY = 'pt_country';
	public const QV_INDEX   = 'pt_countries';
	public const QV_AID     = 'pt_foreign_aid';

	/** Bump when the rewrite rules change; the rules are flushed once per value. */
	private const REWRITE_VERSION = '2';

	/** Payloads are rebuilt daily on the database host; a page reads at most a 6-hour-old copy. */
	private const TTL = 6 * HOUR_IN_SECONDS;

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	/** @var array<string,?array> per-request memo */
	private array $memo = [];

	private function __construct() {
		add_action( 'init', [ $this, 'register_rewrites' ] );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'resolve' ], 5 );
		add_filter( 'template_include', [ $this, 'template' ] );
		add_filter( 'document_title_parts', [ $this, 'title' ] );
		add_filter( 'publictech_force_assets', [ $this, 'force_assets' ] );
		add_filter( 'publictech_gate_is_data_page', [ $this, 'is_data_page' ] );
		add_filter( 'body_class', [ $this, 'body_class' ] );
	}

	public function add_query_vars( array $vars ): array {
		$vars[] = self::QV_COUNTRY;
		$vars[] = self::QV_INDEX;
		$vars[] = self::QV_AID;
		return $vars;
	}

	public function register_rewrites(): void {
		add_rewrite_rule( '^countries/?$', 'index.php?' . self::QV_INDEX . '=1', 'top' );
		add_rewrite_rule( '^foreign-aid/?$', 'index.php?' . self::QV_AID . '=1', 'top' );
		add_rewrite_rule( '^country/([a-z0-9-]+)/?$', 'index.php?' . self::QV_COUNTRY . '=$matches[1]', 'top' );
		if ( get_option( 'pt_country_rewrite_v' ) !== self::REWRITE_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'pt_country_rewrite_v', self::REWRITE_VERSION, false );
		}
	}

	/* ---------- request ---------- */

	public static function is_index(): bool {
		return '1' === (string) get_query_var( self::QV_INDEX );
	}

	public static function is_aid(): bool {
		return '1' === (string) get_query_var( self::QV_AID );
	}

	public static function slug(): string {
		return sanitize_title( (string) get_query_var( self::QV_COUNTRY ) );
	}

	public static function is_country_page(): bool {
		return self::is_index() || self::is_aid() || '' !== self::slug();
	}

	/** Unknown slug → a real 404; a missing index (loader not run) still renders its "being compiled" state. */
	public function resolve(): void {
		if ( '' === self::slug() ) {
			return;
		}
		if ( null === $this->country( self::slug() ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	public function template( string $template ): string {
		if ( self::is_index() ) {
			return locate_template( 'country-index.php' ) ?: $template;
		}
		if ( self::is_aid() ) {
			return locate_template( 'foreign-aid.php' ) ?: $template;
		}
		if ( '' !== self::slug() && null !== $this->country( self::slug() ) ) {
			return locate_template( 'country-report.php' ) ?: $template;
		}
		return $template;
	}

	public function title( array $parts ): array {
		if ( self::is_aid() ) {
			$parts['title'] = __( 'Canada’s foreign aid: how much, where, through whom', 'publictech-core' );
		} elseif ( self::is_index() ) {
			$parts['title'] = __( 'Countries: immigration, aid, laws and Parliament', 'publictech-core' );
		} elseif ( '' !== self::slug() && ( $c = $this->country( self::slug() ) ) ) {
			/* translators: %s: country name */
			$parts['title'] = sprintf( __( '%s and Canada: immigration, aid, laws and Parliament', 'publictech-core' ), (string) ( $c['country']['name'] ?? '' ) );
		}
		return $parts;
	}

	public function force_assets( bool $force ): bool {
		return $force || self::is_country_page();
	}

	public function is_data_page( bool $is ): bool {
		return $is || self::is_country_page();
	}

	public function body_class( array $classes ): array {
		if ( self::is_country_page() ) {
			$classes[] = 'pt-country-page';
		}
		return $classes;
	}

	/* ---------- data ---------- */

	public function index(): ?array {
		return $this->payload( 'index' );
	}

	public function analysis(): ?array {
		return $this->payload( 'analysis' );
	}

	public function aid(): ?array {
		return $this->payload( 'aid' );
	}

	public function country( string $slug ): ?array {
		return '' === $slug ? null : $this->payload( 'country:' . $slug );
	}

	/** One cty_report payload, decoded; null when absent or the database is unreachable. */
	private function payload( string $key ): ?array {
		if ( array_key_exists( $key, $this->memo ) ) {
			return $this->memo[ $key ];
		}
		$cache_key = 'pt_cty_' . md5( $key );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $this->memo[ $key ] = $cached ?: null;
		}
		$data = null;
		try {
			$db   = publictech_db( Riding_Cache_Warmer::source_alias() );
			$rows = $db->select( 'SELECT `payload` FROM `cty_report` WHERE `report_key` = ?', [ $key ] );
			if ( ! empty( $rows[0]['payload'] ) ) {
				$decoded = json_decode( (string) $rows[0]['payload'], true );
				$data    = is_array( $decoded ) ? $decoded : null;
			}
			// Cache misses too (as []), so a bad slug cannot be used to hammer the database.
			set_transient( $cache_key, $data ?? [], $data ? self::TTL : 15 * MINUTE_IN_SECONDS );
		} catch ( \Throwable $e ) {
			error_log( '[publictech] country payload ' . $key . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
		return $this->memo[ $key ] = $data;
	}

	/** URL of a country page. */
	public static function url( string $slug ): string {
		return home_url( '/country/' . rawurlencode( $slug ) . '/' );
	}
}
