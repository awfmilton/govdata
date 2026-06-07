<?php
/**
 * Daily stats aggregation cron.
 *
 * Runs at 02:00 UTC daily. Aggregates the previous day's raw impression and
 * click rows into the daily_stats table, then prunes raw rows older than the
 * retention window (default 90 days).
 *
 * @package GovAds
 */

namespace GovAds;

defined( 'ABSPATH' ) || exit;

final class Rollup {

	private const HOOK = 'govads_daily_rollup';

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		add_action( self::HOOK, [ $this, 'run' ] );
		add_action( 'init', [ $this, 'maybe_schedule' ] );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 02:00:00' ), 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}
	}

	public function maybe_schedule(): void {
		self::schedule();
	}

	public function run(): void {
		global $wpdb;

		if ( ! get_option( 'govads_db_version' ) ) {
			return;
		}

		$yesterday_start = gmdate( 'Y-m-d 00:00:00', strtotime( '-1 day' ) );
		$yesterday_end   = gmdate( 'Y-m-d 23:59:59', strtotime( '-1 day' ) );
		$stat_date       = gmdate( 'Y-m-d',           strtotime( '-1 day' ) );

		$imp   = DB::table( 'impressions' );
		$clks  = DB::table( 'clicks' );
		$stats = DB::table( 'daily_stats' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

		// Aggregate impressions for yesterday.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$stats}` (stat_date, ad_id, zone_id, campaign_id, site_key, impressions, clicks, ctr)
				 SELECT %s, ad_id, zone_id, campaign_id, site_key, COUNT(*), 0, 0.0000
				 FROM `{$imp}`
				 WHERE created_at BETWEEN %s AND %s
				 GROUP BY ad_id, zone_id, campaign_id, site_key
				 ON DUPLICATE KEY UPDATE impressions = VALUES(impressions)",
				$stat_date,
				$yesterday_start,
				$yesterday_end
			)
		);

		// Merge clicks (ON DUPLICATE KEY so existing impression rows get click count added).
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$stats}` (stat_date, ad_id, zone_id, campaign_id, site_key, impressions, clicks, ctr)
				 SELECT %s, ad_id, zone_id, campaign_id, site_key, 0, COUNT(*), 0.0000
				 FROM `{$clks}`
				 WHERE created_at BETWEEN %s AND %s
				 GROUP BY ad_id, zone_id, campaign_id, site_key
				 ON DUPLICATE KEY UPDATE clicks = VALUES(clicks)",
				$stat_date,
				$yesterday_start,
				$yesterday_end
			)
		);

		// Recalculate CTR for yesterday's rows.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$stats}`
				 SET ctr = IF(impressions > 0, clicks / impressions, 0.0000)
				 WHERE stat_date = %s",
				$stat_date
			)
		);

		// Prune raw events older than retention window.
		$retention = (int) apply_filters( 'govads_raw_retention_days', 90 );
		if ( $retention > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$imp}` WHERE created_at < ( UTC_TIMESTAMP() - INTERVAL %d DAY )",
					$retention
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$clks}` WHERE created_at < ( UTC_TIMESTAMP() - INTERVAL %d DAY )",
					$retention
				)
			);
		}

		// phpcs:enable
	}
}
