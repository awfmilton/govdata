<?php
/**
 * Map riding-totals static-file cache.
 *
 * Reads the compact `map_riding_totals` table in the secondary cache DB and
 * writes a single JSON file to wp-content/uploads/pt-map/riding-totals.json.
 * The map page JavaScript fetches that file directly — zero PHP execution on
 * every page view.
 *
 * Refresh happens:
 *   1. Automatically after the riding-cache warmer finishes (weekly cron).
 *   2. Via the WP-Cron hook `publictech_refresh_map_cache` (own weekly schedule).
 *   3. Manually via WP-CLI: wp eval '\PublicTech\Core\Map_Cache::refresh();'
 *   4. Via database/refresh-map-json.php (standalone CLI script for server cron).
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Map_Cache {

	public const HOOK     = 'publictech_refresh_map_cache';
	public const FILENAME = 'riding-totals.json';
	public const SUBDIR   = 'pt-map';

	public static function instance(): void {
		static $init = false;
		if ( $init ) return;
		$init = true;
		add_action( 'init',        [ __CLASS__, 'schedule' ] );
		add_action( self::HOOK,    [ __CLASS__, 'refresh'  ] );
		// Also refresh after the riding-cache warmer completes.
		add_action( Riding_Cache::HOOK, [ __CLASS__, 'refresh' ], 20 );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$first = strtotime( 'next sunday 05:00 UTC' ) ?: ( time() + DAY_IN_SECONDS );
			wp_schedule_event( $first, 'weekly', self::HOOK );
		}
	}

	/** Absolute filesystem path to the JSON file. */
	public static function file_path(): string {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . self::SUBDIR . '/' . self::FILENAME;
	}

	/** Public URL of the JSON file, or empty string if the file doesn't exist. */
	public static function file_url(): string {
		if ( ! file_exists( self::file_path() ) ) {
			return '';
		}
		$upload = wp_upload_dir();
		return trailingslashit( $upload['baseurl'] ) . self::SUBDIR . '/' . self::FILENAME;
	}

	/**
	 * Regenerate the JSON file from the map_riding_totals cache table.
	 * Safe to call from cron, WP-CLI, or the warmer hook.
	 *
	 * @return array{written:int,bytes:int,elapsed:float}|null  Null on error.
	 */
	public static function refresh(): ?array {
		$t0 = microtime( true );

		try {
			$rows = publictech_db( Riding_Cache_Warmer::cache_alias() )->select(
				'SELECT `riding_number`, `name`, `province`, `spend`, `agreement_count`
				 FROM `map_riding_totals`'
			);
		} catch ( \Throwable $e ) {
			/* map_riding_totals doesn't exist yet — fall back to riding_cache columns */
			try {
				$rows = publictech_db( Riding_Cache_Warmer::cache_alias() )->select(
					'SELECT `riding_number`,
					        `riding_name_en`   AS `name`,
					        `province`,
					        `total_value`      AS `spend`,
					        `agreement_count`
					 FROM `riding_cache`'
				);
			} catch ( \Throwable $e2 ) {
				error_log( '[PublicTech] map-cache refresh failed: ' . $e2->getMessage() );
				return null;
			}
		}

		if ( empty( $rows ) ) {
			return null;
		}

		/* Build compact associative array keyed by riding number */
		$out = [];
		foreach ( $rows as $row ) {
			$out[ (string) $row['riding_number'] ] = [
				'spend'    => (float) $row['spend'],
				'count'    => (int)   $row['agreement_count'],
				'name'     => (string) $row['name'],
				'province' => (string) $row['province'],
			];
		}

		$json = wp_json_encode( $out, JSON_UNESCAPED_UNICODE );
		if ( ! $json ) {
			return null;
		}

		/* Ensure target directory exists */
		$path = self::file_path();
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		/* Write atomically via a temp file */
		$tmp = $path . '.tmp';
		if ( file_put_contents( $tmp, $json, LOCK_EX ) === false ) {
			error_log( '[PublicTech] map-cache: could not write to ' . $tmp );
			return null;
		}
		rename( $tmp, $path );

		/* Add a simple .htaccess so Apache serves it with correct headers */
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess,
				"<FilesMatch \"\\.json$\">\n" .
				"    Header set Content-Type \"application/json; charset=UTF-8\"\n" .
				"    Header set Cache-Control \"public, max-age=3600\"\n" .
				"    Header set Access-Control-Allow-Origin \"*\"\n" .
				"</FilesMatch>\n"
			);
		}

		return [
			'written' => count( $out ),
			'bytes'   => strlen( $json ),
			'elapsed' => round( microtime( true ) - $t0, 3 ),
		];
	}
}

Map_Cache::instance();
