<?php
/**
 * Full cache rebuild — URL-triggered.
 *
 * Place this file in:
 *   wp-content/mu-plugins/04-rebuild-all-caches.php
 *
 * Then visit:
 *   https://govdata.ca/?pt_rebuild_caches=<PT_IMPORT_TOKEN>
 *
 * Runs all cache warmers in sequence: Dashboard Stats → Riding Cache →
 * Entity Reports → MP Finance. Streams progress to the browser.
 *
 * DELETE this file from mu-plugins once caches are rebuilt.
 *
 * @package PublicTech\Core
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', static function () {

	if ( ! isset( $_GET['pt_rebuild_caches'] ) ) {
		return;
	}

	$pt_token = defined( 'PT_WARM_TOKEN' ) ? PT_WARM_TOKEN : ( defined( 'PT_IMPORT_TOKEN' ) ? PT_IMPORT_TOKEN : '' );
	if ( '' === $pt_token || ! hash_equals( (string) $pt_token, (string) wp_unslash( $_GET['pt_rebuild_caches'] ) ) ) {
		wp_die( 'Forbidden.', 403 );
	}

	if ( ! class_exists( \PublicTech\Core\Multi_DB::class ) ) {
		wp_die( 'PublicTech Core is not active.', 500 );
	}

	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 0 );
	}
	@ini_set( 'memory_limit', '512M' );

	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Accel-Buffering: no' );

	echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
		. '<style>'
		. 'body{font-family:"JetBrains Mono",monospace;padding:2rem;background:#0d0d0d;color:#ccc;line-height:1.6}'
		. 'h2{color:#0ff;margin:0 0 .5rem}h3{color:#0ff;margin:1.5rem 0 .5rem;border-top:1px solid #222;padding-top:1rem}'
		. 'p{margin:.15rem 0}.ok{color:#0c6}.warn{color:#fa0}.err{color:#f44}'
		. '.dim{color:#666}.val{color:#0ff}code{background:#1a1a1a;padding:2px 6px;border-radius:3px}'
		. '</style></head><body>';
	echo '<h2>PublicTech — Full Cache Rebuild</h2>';
	echo '<p class="dim">Started: ' . esc_html( gmdate( 'Y-m-d H:i:s' ) ) . ' UTC</p>';
	pt_flush();

	$t_total = microtime( true );

	// ── Phase 1: DB Connectivity ──

	echo '<h3>Phase 1 · Database Connectivity</h3>';

	$aliases = \PublicTech\Core\Multi_DB::instance()->aliases();
	echo '<p>Configured aliases: <span class="val">' . esc_html( implode( ', ', $aliases ) ) . '</span></p>';
	pt_flush();

	$all_ok = true;
	foreach ( $aliases as $alias ) {
		try {
			$conn = \PublicTech\Core\Multi_DB::instance()->connection( $alias );
			$pdo  = $conn->pdo();
			$pdo->query( 'SELECT 1' )->fetch();
			echo '<p class="ok">✓ ' . esc_html( $alias ) . ' — connected</p>';
		} catch ( \Throwable $e ) {
			echo '<p class="err">✗ ' . esc_html( $alias ) . ' — FAILED: ' . esc_html( $e->getMessage() ) . '</p>';
			$all_ok = false;
		}
		pt_flush();
	}

	// Entity cache DB (govdata_cache_01).
	try {
		$cache_ok = \PublicTech\Core\Cache_DB::instance()->available();
		echo '<p class="' . ( $cache_ok ? 'ok' : 'warn' ) . '">' . ( $cache_ok ? '✓' : '⚠' )
			. ' Entity cache DB (govdata_cache_01): ' . ( $cache_ok ? 'configured' : 'NOT configured' ) . '</p>';
	} catch ( \Throwable $e ) {
		echo '<p class="warn">⚠ Entity cache DB check: ' . esc_html( $e->getMessage() ) . '</p>';
	}
	pt_flush();

	if ( ! $all_ok ) {
		echo '<p class="err">✗ Fix DB connections before proceeding. Check wp-content/mu-plugins/00-db-connections.php</p>';
		echo '</body></html>';
		exit;
	}

	// ── Phase 2: Validate master_table ──

	echo '<h3>Phase 2 · Schema Validation</h3>';

	try {
		$pdo = \PublicTech\Core\Multi_DB::instance()->connection( 'pt-grantconts-01' )->pdo();

		$tables = $pdo->query( "SHOW TABLES LIKE 'master_table'" )->fetchAll();
		if ( empty( $tables ) ) {
			echo '<p class="err">✗ master_table DOES NOT EXIST — was it renamed?</p>';
			echo '</body></html>';
			exit;
		}
		echo '<p class="ok">✓ master_table exists</p>';

		$count = $pdo->query( 'SELECT COUNT(*) AS cnt FROM `master_table`' )->fetch();
		echo '<p class="ok">✓ master_table rows: <span class="val">' . esc_html( number_format( (int) $count['cnt'] ) ) . '</span></p>';

		// Check critical columns.
		$cols  = $pdo->query( 'SHOW COLUMNS FROM `master_table`' )->fetchAll( PDO::FETCH_COLUMN, 0 );
		$needed = [ 'agreement_value', 'year', 'fiscal_year', 'recipient_legal_name', 'recipient_province', 'owner_org_en', 'prog_name_en' ];
		$missing = array_diff( $needed, $cols );
		if ( $missing ) {
			echo '<p class="err">✗ Missing columns: ' . esc_html( implode( ', ', $missing ) ) . '</p>';
			echo '<p class="warn">⚠ Cache warmers will fail. Update column maps first.</p>';
			echo '</body></html>';
			exit;
		}
		echo '<p class="ok">✓ All critical columns present (' . count( $needed ) . ' checked)</p>';

		// List all tables.
		$all_tables = $pdo->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_COLUMN, 0 );
		echo '<p class="dim">All tables in grantconts: ' . esc_html( implode( ', ', $all_tables ) ) . '</p>';
	} catch ( \Throwable $e ) {
		echo '<p class="err">✗ Schema validation failed: ' . esc_html( $e->getMessage() ) . '</p>';
		echo '</body></html>';
		exit;
	}
	pt_flush();

	// ── Phase 3: Flush Caches ──

	echo '<h3>Phase 3 · Flushing Stale Caches</h3>';

	delete_transient( 'pt_dash_stats_v2' );
	delete_transient( 'pt_activity_feed' );
	delete_transient( 'pt_forum_threads' );
	delete_transient( 'pt_riding_cache_warming' );
	delete_transient( 'pt_mp_finance_warming' );
	echo '<p class="ok">✓ Dashboard + activity transients cleared</p>';

	$entities = \PublicTech\Core\Entity_Reports::registry();
	foreach ( $entities as $e ) {
		delete_transient( 'pt_entity_snap_' . $e['slug'] );
	}
	echo '<p class="ok">✓ ' . count( $entities ) . ' entity snapshot transients cleared</p>';

	wp_cache_delete( 'pt_count_mp', 'publictech' );
	wp_cache_delete( 'pt_count_flags', 'publictech' );
	wp_cache_flush();
	echo '<p class="ok">✓ WP object cache flushed</p>';
	pt_flush();

	// ── Phase 4: Dashboard Stats ──

	echo '<h3>Phase 4 · Warming Dashboard Stats</h3>';
	try {
		$t0 = microtime( true );
		\PublicTech\Core\Dashboard_Stats::run_warm();
		$el = round( microtime( true ) - $t0, 1 );
		$d  = \PublicTech\Core\Dashboard_Stats::get();
		echo '<p class="ok">✓ Dashboard warmed in ' . esc_html( $el ) . 's</p>';
		echo '<p>  total_spend: <span class="val">$' . esc_html( number_format( (float) ( $d['total_spend'] ?? 0 ), 0 ) ) . '</span>'
			. ' · agreements: <span class="val">' . esc_html( number_format( (int) ( $d['total_agreements'] ?? 0 ) ) ) . '</span>'
			. ' · by_year: <span class="val">' . count( $d['by_year'] ?? [] ) . '</span>'
			. ' · by_province: <span class="val">' . count( $d['by_province'] ?? [] ) . '</span>'
			. ' · top_recipients: <span class="val">' . count( $d['top_recipients'] ?? [] ) . '</span></p>';
		if ( empty( $d['total_spend'] ) ) {
			echo '<p class="warn">⚠ total_spend is 0 — check that master_table has rows with agreement_value > 0</p>';
		}
	} catch ( \Throwable $e ) {
		echo '<p class="err">✗ Dashboard warm failed: ' . esc_html( $e->getMessage() ) . '</p>';
	}
	pt_flush();

	// ── Phase 5: Riding Cache ──

	echo '<h3>Phase 5 · Warming Riding Cache</h3>';
	echo '<p class="dim">This may take several minutes for large datasets…</p>';
	pt_flush();
	try {
		$t0     = microtime( true );
		$logger = static function ( string $msg, string $level ) {
			$cls = [ 'error' => 'err', 'warn' => 'warn', 'success' => 'ok' ][ $level ] ?? 'dim';
			echo '<p class="' . $cls . '">' . esc_html( $msg ) . '</p>';
			pt_flush();
		};
		$warmer = new \PublicTech\Core\Riding_Cache_Warmer( $logger );
		$result = $warmer->run();
		$el     = round( microtime( true ) - $t0, 1 );
		echo '<p class="ok">✓ Riding cache warmed in ' . esc_html( $el ) . 's — '
			. (int) ( $result['ridings'] ?? 0 ) . ' ridings, '
			. esc_html( number_format( (int) ( $result['agreements'] ?? 0 ) ) ) . ' agreements</p>';
	} catch ( \Throwable $e ) {
		echo '<p class="err">✗ Riding cache warm failed: ' . esc_html( $e->getMessage() ) . '</p>';
	}
	pt_flush();

	// ── Phase 6: Entity Reports ──

	echo '<h3>Phase 6 · Syncing Entity Reports</h3>';
	try {
		$t0      = microtime( true );
		$results = \PublicTech\Core\Entity_Reports::sync_all();
		$el      = round( microtime( true ) - $t0, 1 );
		$ok_n = 0;
		$fail_n = 0;
		foreach ( $results as $slug => $r ) {
			if ( $r['ok'] ) {
				$ok_n++;
				echo '<p class="ok">✓ ' . esc_html( $slug ) . ' — '
					. (int) $r['rows'] . ' rows, $' . esc_html( number_format( (float) ( $r['total'] ?? 0 ), 0 ) ) . '</p>';
			} else {
				$fail_n++;
				echo '<p class="err">✗ ' . esc_html( $slug ) . ' — ' . esc_html( $r['reason'] ?? 'unknown' ) . '</p>';
			}
		}
		echo '<p class="' . ( $fail_n ? 'warn' : 'ok' ) . '">' . ( $fail_n ? '⚠' : '✓' )
			. ' Entity sync in ' . esc_html( $el ) . 's — ' . $ok_n . ' OK, ' . $fail_n . ' failed</p>';
	} catch ( \Throwable $e ) {
		echo '<p class="err">✗ Entity sync failed: ' . esc_html( $e->getMessage() ) . '</p>';
	}
	pt_flush();

	// ── Phase 7: MP Finance ──

	echo '<h3>Phase 7 · Warming MP Finance Cache</h3>';
	try {
		$t0 = microtime( true );
		\PublicTech\Core\MP_Finance_Warmer::run_warm();
		$el = round( microtime( true ) - $t0, 1 );
		echo '<p class="ok">✓ MP finance cache warmed in ' . esc_html( $el ) . 's</p>';
	} catch ( \Throwable $e ) {
		echo '<p class="err">✗ MP finance warm failed: ' . esc_html( $e->getMessage() ) . '</p>';
	}
	pt_flush();

	// ── Phase 8: Verify ──

	echo '<h3>Phase 8 · Verification</h3>';

	$dash = \PublicTech\Core\Dashboard_Stats::get();
	$dash_ok = ( $dash['total_spend'] ?? 0 ) > 0;
	echo '<p class="' . ( $dash_ok ? 'ok' : 'warn' ) . '">'
		. ( $dash_ok ? '✓' : '⚠' ) . ' Dashboard: total_spend=$'
		. esc_html( number_format( (float) ( $dash['total_spend'] ?? 0 ), 0 ) ) . '</p>';

	$rc  = \PublicTech\Core\Riding_Cache::instance();
	$dir = $rc->directory();
	echo '<p class="' . ( count( $dir ) > 0 ? 'ok' : 'warn' ) . '">'
		. ( count( $dir ) > 0 ? '✓' : '⚠' ) . ' Riding directory: '
		. count( $dir ) . ' entries</p>';

	if ( ! empty( $entities[0]['slug'] ) ) {
		$snap = \PublicTech\Core\Entity_Reports::cached_snapshot( $entities[0]['slug'] );
		echo '<p class="' . ( $snap ? 'ok' : 'warn' ) . '">'
			. ( $snap ? '✓' : '⚠' ) . ' Entity (' . esc_html( $entities[0]['name'] ) . '): '
			. ( $snap ? '$' . esc_html( number_format( (float) ( $snap['total'] ?? 0 ), 0 ) ) : 'no data' ) . '</p>';
	}

	$mp_posts = get_posts( [ 'post_type' => 'member-parliament', 'posts_per_page' => 1, 'fields' => 'ids' ] );
	if ( ! empty( $mp_posts[0] ) ) {
		$spend = (float) get_post_meta( $mp_posts[0], 'total_spend', true );
		echo '<p class="' . ( $spend > 0 ? 'ok' : 'warn' ) . '">'
			. ( $spend > 0 ? '✓' : '⚠' ) . ' Sample MP (post #' . $mp_posts[0] . '): total_spend=$'
			. esc_html( number_format( $spend, 0 ) ) . '</p>';
	}

	$elapsed_total = round( microtime( true ) - $t_total, 1 );

	echo '<h3>✓ Complete</h3>';
	echo '<p class="ok">All cache layers rebuilt in ' . esc_html( $elapsed_total ) . 's. Reload frontend pages to verify.</p>';
	echo '<p class="warn">⚠ Delete <code>wp-content/mu-plugins/04-rebuild-all-caches.php</code> when done.</p>';
	echo '</body></html>';
	exit;
} );

function pt_flush(): void {
	if ( ob_get_level() ) {
		ob_flush();
	}
	flush();
}
