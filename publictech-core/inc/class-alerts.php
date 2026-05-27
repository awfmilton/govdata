<?php
/**
 * Saved-query alerts.
 *
 * Daily cron walks every user with `pt_saved_alerts` user_meta, runs the
 * delta query (rows whose `awarded_date` / `txn_date` falls in the last
 * 24 hours and otherwise match the saved query), and emails an HTML
 * digest if any matches landed. No-match runs are silent.
 *
 * Saved-alert shape (in user_meta, keyed by alert id):
 *   {
 *     id, title, schema, table, filters, order,
 *     created, last_run_at, last_match_count
 *   }
 *
 * Sites that ingest data on a different cadence can override the lookback
 * window via the `publictech_alert_lookback_seconds` filter.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Alerts {
	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	public const HOOK = 'publictech_run_alerts';

	private function __construct() {
		add_action( 'init',       [ $this, 'schedule' ] );
		add_action( self::HOOK,   [ $this, 'run_all' ] );
	}

	public function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			// Run at the next 07:00 UTC, then every 24 hours.
			$first = strtotime( 'tomorrow 07:00 UTC' );
			wp_schedule_event( $first, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}
	}

	/**
	 * Cron entry. Iterates users with saved alerts. Designed to be safe to
	 * run repeatedly — each match-set is deduped against last_match_count so
	 * we don't email the same delta twice when the cron retries.
	 */
	public function run_all(): void {
		$users = get_users( [
			'meta_key' => 'pt_saved_alerts',
			'fields'   => [ 'ID', 'user_email', 'display_name' ],
			'number'   => 5000,
		] );

		$lookback = (int) apply_filters( 'publictech_alert_lookback_seconds', 86400 );

		foreach ( $users as $user ) {
			$alerts = (array) get_user_meta( $user->ID, 'pt_saved_alerts', true );
			$dirty  = false;
			$digest = [];

			foreach ( $alerts as $aid => $alert ) {
				if ( ! is_array( $alert ) || empty( $alert['schema'] ) || empty( $alert['table'] ) ) {
					continue;
				}
				$matches = $this->run_one( (int) $user->ID, $alert, $lookback );
				if ( $matches === null ) {
					continue;
				}
				$alerts[ $aid ]['last_run_at']      = gmdate( 'c' );
				$alerts[ $aid ]['last_match_count'] = (int) $matches['count'];
				$dirty = true;

				$prev = (int) ( $alert['last_match_count'] ?? 0 );
				if ( $matches['count'] > 0 && $matches['count'] !== $prev ) {
					$digest[] = [
						'alert'   => $alert,
						'matches' => $matches,
					];
				}
			}

			if ( $dirty ) {
				update_user_meta( $user->ID, 'pt_saved_alerts', $alerts );
			}
			if ( $digest && $user->user_email ) {
				$this->send_digest( $user, $digest );
			}
		}
	}

	/**
	 * Resolve a single saved alert. Returns null if the schema isn't
	 * configured or the user's tier disallows it (silent skip — alerts
	 * survive tier downgrades without crashing the cron).
	 *
	 * @return array{count:int,sample:array<int,array<string,mixed>>}|null
	 */
	private function run_one( int $user_id, array $alert, int $lookback ): ?array {
		$schema = sanitize_key( (string) $alert['schema'] );
		$table  = sanitize_key( (string) $alert['table'] );
		$tier   = Access_Tiers::instance()->quota( $user_id );
		if ( '*' !== $tier['schemas'] && ! in_array( $schema, (array) $tier['schemas'], true ) ) {
			return null;
		}
		$columns = apply_filters( "publictech_columns_{$schema}_{$table}", [], $schema, $table );
		if ( ! $columns ) {
			return null;
		}

		// Add a date-window filter on the first date-shaped column we know
		// about. Sites with a different ingestion clock override via the
		// publictech_alert_lookback_seconds filter.
		$filters    = is_array( $alert['filters'] ?? null ) ? $alert['filters'] : [];
		$date_col   = null;
		foreach ( [ 'awarded_date', 'txn_date', 'date', 'created_at' ] as $candidate ) {
			if ( isset( $columns[ $candidate ] ) ) {
				$date_col = $candidate;
				break;
			}
		}
		if ( $date_col ) {
			$since     = gmdate( 'Y-m-d H:i:s', time() - $lookback );
			$filters[] = [ 'col' => $date_col, 'op' => '>=', 'val' => $since ];
		}

		try {
			$stmt = publictech_db( $schema )->find( $table, $filters, $columns, (string) ( $alert['order'] ?? '' ), 50 );
			$rows = $stmt->fetchAll( \PDO::FETCH_ASSOC ) ?: [];
		} catch ( \Throwable $e ) {
			error_log( '[PublicTech] alert run failed (user ' . $user_id . '): ' . $e->getMessage() );
			return null;
		}
		return [ 'count' => count( $rows ), 'sample' => array_slice( $rows, 0, 10 ) ];
	}

	private function send_digest( object $user, array $digest ): void {
		$subject = sprintf(
			/* translators: %d: number of saved alerts that matched */
			_n( '%d alert matched on PublicTech today', '%d alerts matched on PublicTech today', count( $digest ), 'publictech-core' ),
			count( $digest )
		);

		$body  = '<html><body style="font-family:Arial,sans-serif;font-size:14px;color:#0e1726">';
		$body .= '<h2 style="color:#0072a8;border-bottom:1px solid #dbe2ec;padding-bottom:8px">' . esc_html( $subject ) . '</h2>';
		foreach ( $digest as $entry ) {
			$alert = $entry['alert'];
			$body .= '<h3 style="margin:18px 0 6px">' . esc_html( $alert['title'] ?? __( 'Untitled alert', 'publictech-core' ) ) . '</h3>';
			$body .= '<p style="margin:0 0 6px;color:#67748a">' . esc_html( sprintf( '%s · %s', $alert['schema'], $alert['table'] ) ) . '</p>';
			$body .= '<p style="margin:0 0 10px"><strong>' . (int) $entry['matches']['count'] . '</strong> matching rows in the last 24h.</p>';
			if ( $entry['matches']['sample'] ) {
				$first   = (array) $entry['matches']['sample'][0];
				$columns = array_keys( $first );
				$body   .= '<table style="border-collapse:collapse;width:100%;font-size:12px"><thead><tr>';
				foreach ( $columns as $col ) {
					$body .= '<th style="text-align:left;padding:6px 8px;background:#f7f9fc;border:1px solid #dbe2ec">' . esc_html( $col ) . '</th>';
				}
				$body .= '</tr></thead><tbody>';
				foreach ( $entry['matches']['sample'] as $row ) {
					$body .= '<tr>';
					foreach ( $columns as $col ) {
						$body .= '<td style="padding:6px 8px;border:1px solid #dbe2ec">' . esc_html( (string) ( $row[ $col ] ?? '' ) ) . '</td>';
					}
					$body .= '</tr>';
				}
				$body .= '</tbody></table>';
			}
		}
		$body .= '<p style="margin-top:20px;font-size:11px;color:#9aa6b8">' . esc_html__( 'You are receiving this because you saved a query as an alert on PublicTech. Manage alerts from your profile page.', 'publictech-core' ) . '</p>';
		$body .= '</body></html>';

		wp_mail(
			$user->user_email,
			$subject,
			$body,
			[ 'Content-Type: text/html; charset=UTF-8' ]
		);
	}
}
