<?php
/**
 * Database table creation and management.
 *
 * Three custom tables hold raw events and pre-aggregated daily stats.
 * Raw rows are pruned after a configurable retention window; daily_stats
 * are kept indefinitely for dashboard reporting.
 *
 * @package GovAds
 */

namespace GovAds;

defined( 'ABSPATH' ) || exit;

final class DB {

	private const VERSION_KEY = 'govads_db_version';
	private const VERSION     = 1;

	public static function install(): void {
		if ( (int) get_option( self::VERSION_KEY ) >= self::VERSION ) {
			return;
		}
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::impressions_schema( $wpdb->prefix, $charset ) );
		dbDelta( self::clicks_schema( $wpdb->prefix, $charset ) );
		dbDelta( self::daily_stats_schema( $wpdb->prefix, $charset ) );

		update_option( self::VERSION_KEY, self::VERSION );
	}

	public static function uninstall(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}govads_daily_stats`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}govads_clicks`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}govads_impressions`" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		delete_option( self::VERSION_KEY );
	}

	/** Prefixed table name helper. */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'govads_' . $name;
	}

	private static function impressions_schema( string $prefix, string $charset ): string {
		$t = $prefix . 'govads_impressions';
		return "CREATE TABLE {$t} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ad_id        BIGINT UNSIGNED NOT NULL,
			zone_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			campaign_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			site_key     VARCHAR(64)     NOT NULL DEFAULT '',
			session_id   VARCHAR(64)     NOT NULL DEFAULT '',
			user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ip_address   VARCHAR(45)     NOT NULL DEFAULT '',
			user_agent   VARCHAR(500)    NOT NULL DEFAULT '',
			page_url     VARCHAR(1000)   NOT NULL DEFAULT '',
			referrer     VARCHAR(1000)   NOT NULL DEFAULT '',
			created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_ad       (ad_id),
			KEY idx_zone     (zone_id),
			KEY idx_campaign (campaign_id),
			KEY idx_site     (site_key),
			KEY idx_created  (created_at)
		) {$charset};";
	}

	private static function clicks_schema( string $prefix, string $charset ): string {
		$t = $prefix . 'govads_clicks';
		return "CREATE TABLE {$t} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ad_id        BIGINT UNSIGNED NOT NULL,
			zone_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			campaign_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			site_key     VARCHAR(64)     NOT NULL DEFAULT '',
			session_id   VARCHAR(64)     NOT NULL DEFAULT '',
			user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ip_address   VARCHAR(45)     NOT NULL DEFAULT '',
			page_url     VARCHAR(1000)   NOT NULL DEFAULT '',
			target_url   VARCHAR(1000)   NOT NULL DEFAULT '',
			created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_ad       (ad_id),
			KEY idx_zone     (zone_id),
			KEY idx_campaign (campaign_id),
			KEY idx_site     (site_key),
			KEY idx_created  (created_at)
		) {$charset};";
	}

	private static function daily_stats_schema( string $prefix, string $charset ): string {
		$t = $prefix . 'govads_daily_stats';
		return "CREATE TABLE {$t} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			stat_date    DATE            NOT NULL,
			ad_id        BIGINT UNSIGNED NOT NULL,
			zone_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			campaign_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			site_key     VARCHAR(64)     NOT NULL DEFAULT '',
			impressions  INT UNSIGNED    NOT NULL DEFAULT 0,
			clicks       INT UNSIGNED    NOT NULL DEFAULT 0,
			ctr          DECIMAL(6,4)    NOT NULL DEFAULT 0.0000,
			PRIMARY KEY  (id),
			UNIQUE KEY   uniq_day (stat_date, ad_id, zone_id, site_key),
			KEY idx_date     (stat_date),
			KEY idx_ad       (ad_id),
			KEY idx_campaign (campaign_id)
		) {$charset};";
	}
}
