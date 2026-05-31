<?php
/**
 * Rebuild all PublicTech caches after a database update.
 *
 * Run via WP-CLI:
 *   wp eval-file database/rebuild-all-caches.php
 *
 * This script:
 *   1. Diagnoses DB connectivity for all configured aliases
 *   2. Validates that expected tables/columns still exist
 *   3. Flushes all stale transients and object caches
 *   4. Re-warms all 4 cache layers (Dashboard, Riding, Entity, MP Finance)
 *
 * @package PublicTech\Core
 */

defined( 'ABSPATH' ) || exit;

use PublicTech\Core\Multi_DB;
use PublicTech\Core\Dashboard_Stats;
use PublicTech\Core\Riding_Cache_Warmer;
use PublicTech\Core\Entity_Reports;
use PublicTech\Core\MP_Finance_Warmer;
use PublicTech\Core\Cache_DB;

// ── Helpers ────────────────────────────────────────────────────────────────

function pt_log( string $msg, string $type = 'info' ): void {
	$icons = [ 'info' => '  ℹ', 'ok' => '  ✓', 'warn' => '  ⚠', 'err' => '  ✗', 'head' => "\n══" ];
	$prefix = $icons[ $type ] ?? '  ';
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::log( $prefix . ' ' . $msg );
	} else {
		echo $prefix . ' ' . $msg . "\n";
	}
}

function pt_section( string $title ): void {
	pt_log( ' ' . strtoupper( $title ) . ' ', 'head' );
	pt_log( str_repeat( '─', 60 ), 'info' );
}

// ── Phase 1: Diagnose DB Connectivity ──────────────────────────────────────

pt_section( 'Phase 1: Database Connectivity' );

$aliases = Multi_DB::instance()->aliases();
if ( empty( $aliases ) ) {
	pt_log( 'No database aliases configured! Check PUBLICTECH_DB_CONNECTIONS in wp-config.php or 00-db-connections.php', 'err' );
	exit( 1 );
}

pt_log( 'Configured aliases: ' . implode( ', ', $aliases ), 'info' );

$all_ok = true;
foreach ( $aliases as $alias ) {
	try {
		$conn = Multi_DB::instance()->connection( $alias );
		$pdo  = $conn->pdo();
		$row  = $pdo->query( 'SELECT 1 AS ok' )->fetch();
		if ( $row && $row['ok'] == 1 ) {
			pt_log( "$alias — connected OK", 'ok' );
		} else {
			pt_log( "$alias — connected but SELECT 1 returned unexpected result", 'warn' );
		}
	} catch ( \Throwable $e ) {
		pt_log( "$alias — CONNECTION FAILED: " . $e->getMessage(), 'err' );
		$all_ok = false;
	}
}

// Check the entity cache DB (govdata_cache_01) separately.
try {
	$cache_available = Cache_DB::instance()->available();
	pt_log( 'Entity cache DB (govdata_cache_01): ' . ( $cache_available ? 'configured' : 'NOT configured' ), $cache_available ? 'ok' : 'warn' );
} catch ( \Throwable $e ) {
	pt_log( 'Entity cache DB check failed: ' . $e->getMessage(), 'warn' );
}

if ( ! $all_ok ) {
	pt_log( 'One or more DB connections failed. Fix connectivity before proceeding.', 'err' );
	pt_log( 'Check database/mu-plugins/00-db-connections.php for host/port/credentials.', 'info' );
	exit( 1 );
}

// ── Phase 2: Validate Schema (master_table columns) ──────────────────────

pt_section( 'Phase 2: Schema Validation' );

// Check that master_table exists and has the expected columns.
$expected_columns = [
	'primary_key', 'ref_number', 'agreement_type', 'agreement_number',
	'agreement_value', 'agreement_start_date', 'agreement_end_date',
	'year', 'fiscal_year', 'agreement_title_en', 'description_eng',
	'recipient_legal_name', 'recipient_operating_name',
	'recipient_province', 'recipient_city', 'recipient_postal_code',
	'federal_riding_name_en', 'federal_riding_number',
	'prog_name_en', 'owner_org_en', 'owner_org_title',
];

try {
	$conn = Multi_DB::instance()->connection( 'pt-grantconts-01' );
	$pdo  = $conn->pdo();

	// Check master_table exists.
	$tables = $pdo->query( "SHOW TABLES LIKE 'master_table'" )->fetchAll();
	if ( empty( $tables ) ) {
		pt_log( 'master_table DOES NOT EXIST in grantconts database!', 'err' );
		pt_log( 'This is the primary data source. Was it renamed during the update?', 'err' );
		exit( 1 );
	}
	pt_log( 'master_table exists', 'ok' );

	// Check columns.
	$cols_raw = $pdo->query( 'SHOW COLUMNS FROM `master_table`' )->fetchAll( PDO::FETCH_COLUMN, 0 );
	$cols_set = array_flip( $cols_raw );
	$missing  = [];
	foreach ( $expected_columns as $col ) {
		if ( ! isset( $cols_set[ $col ] ) ) {
			$missing[] = $col;
		}
	}

	if ( $missing ) {
		pt_log( 'master_table is MISSING expected columns: ' . implode( ', ', $missing ), 'err' );
		pt_log( 'The column maps in database/mu-plugins/01-publictech-schemas.php reference these columns.', 'err' );
		pt_log( 'Either rename the columns back or update the schema map to match the new names.', 'info' );
		exit( 1 );
	}
	pt_log( 'All ' . count( $expected_columns ) . ' expected columns present in master_table', 'ok' );

	// Quick row count.
	$count = $pdo->query( 'SELECT COUNT(*) AS cnt FROM `master_table`' )->fetch();
	pt_log( 'master_table row count: ' . number_format( (int) $count['cnt'] ), 'ok' );

	// Check pcbfr table (needed by riding cache warmer).
	$pcbfr = $pdo->query( "SHOW TABLES LIKE 'pcbfr'" )->fetchAll();
	pt_log( 'pcbfr table: ' . ( $pcbfr ? 'exists' : 'MISSING (riding cache warmer needs this)' ), $pcbfr ? 'ok' : 'warn' );

	// Check mps table.
	$mps = $pdo->query( "SHOW TABLES LIKE 'mps'" )->fetchAll();
	pt_log( 'mps table: ' . ( $mps ? 'exists' : 'MISSING' ), $mps ? 'ok' : 'warn' );

	// Check HoC tables (needed by MP Finance warmer).
	$hoc_exp = $pdo->query( "SHOW TABLES LIKE 'hoc_member_expenditures'" )->fetchAll();
	$hoc_per = $pdo->query( "SHOW TABLES LIKE 'hoc_reporting_periods'" )->fetchAll();
	pt_log( 'hoc_member_expenditures: ' . ( $hoc_exp ? 'exists' : 'MISSING (MP finance warmer needs this)' ), $hoc_exp ? 'ok' : 'warn' );
	pt_log( 'hoc_reporting_periods: ' . ( $hoc_per ? 'exists' : 'MISSING (MP finance warmer needs this)' ), $hoc_per ? 'ok' : 'warn' );

	// List all tables for reference.
	$all_tables = $pdo->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_COLUMN, 0 );
	pt_log( 'All tables in grantconts: ' . implode( ', ', $all_tables ), 'info' );

} catch ( \Throwable $e ) {
	pt_log( 'Schema validation failed: ' . $e->getMessage(), 'err' );
	exit( 1 );
}

// ── Phase 3: Flush Stale Caches ──────────────────────────────────────────

pt_section( 'Phase 3: Flushing Stale Caches' );

// Dashboard stats.
$deleted = delete_transient( 'pt_dash_stats_v2' );
pt_log( 'Transient pt_dash_stats_v2: ' . ( $deleted ? 'deleted' : 'was not set' ), 'ok' );

// Activity feed + forum threads.
delete_transient( 'pt_activity_feed' );
delete_transient( 'pt_forum_threads' );
pt_log( 'Transients pt_activity_feed, pt_forum_threads: cleared', 'ok' );

// Entity snapshot transients.
$entities = Entity_Reports::registry();
$ent_count = 0;
foreach ( $entities as $e ) {
	if ( delete_transient( 'pt_entity_snap_' . $e['slug'] ) ) {
		$ent_count++;
	}
}
pt_log( "Entity snapshot transients: cleared $ent_count of " . count( $entities ), 'ok' );

// MP count caches.
wp_cache_delete( 'pt_count_mp', 'publictech' );
wp_cache_delete( 'pt_count_flags', 'publictech' );
pt_log( 'Object cache keys pt_count_mp, pt_count_flags: cleared', 'ok' );

// Riding cache warmer lock (in case a previous warm was interrupted).
delete_transient( 'pt_riding_cache_warming' );
delete_transient( 'pt_mp_finance_warming' );
pt_log( 'Cache warmer locks: cleared', 'ok' );

// Full object cache flush.
wp_cache_flush();
pt_log( 'WP object cache: flushed', 'ok' );

// ── Phase 4: Re-warm Dashboard Stats ─────────────────────────────────────

pt_section( 'Phase 4: Warming Dashboard Stats' );

try {
	$t0 = microtime( true );
	Dashboard_Stats::run_warm();
	$elapsed = round( ( microtime( true ) - $t0 ), 1 );

	// Verify data was written.
	$dash = Dashboard_Stats::get();
	if ( ! empty( $dash['total_spend'] ) && $dash['total_spend'] > 0 ) {
		pt_log( "Dashboard warmed in {$elapsed}s — total_spend: $" . number_format( $dash['total_spend'], 0 ) . ", agreements: " . number_format( $dash['total_agreements'] ), 'ok' );
		pt_log( 'by_year entries: ' . count( $dash['by_year'] ) . ', by_province: ' . count( $dash['by_province'] ) . ', top_recipients: ' . count( $dash['top_recipients'] ), 'ok' );
	} else {
		pt_log( 'Dashboard warm completed but returned empty data — the SQL queries may be returning no rows.', 'warn' );
		pt_log( 'Check that master_table has rows where agreement_value > 0.', 'info' );
	}
} catch ( \Throwable $e ) {
	pt_log( 'Dashboard warm FAILED: ' . $e->getMessage(), 'err' );
}

// ── Phase 5: Re-warm Riding Cache ────────────────────────────────────────

pt_section( 'Phase 5: Warming Riding Cache' );

try {
	$t0 = microtime( true );
	$warmer  = new Riding_Cache_Warmer();
	$result  = $warmer->run();
	$elapsed = round( ( microtime( true ) - $t0 ), 1 );
	pt_log( "Riding cache warmed in {$elapsed}s", 'ok' );
	if ( is_array( $result ) ) {
		pt_log( 'Ridings: ' . ( $result['ridings'] ?? '?' ) . ', Agreements: ' . ( $result['agreements'] ?? '?' ), 'ok' );
	}
} catch ( \Throwable $e ) {
	pt_log( 'Riding cache warm FAILED: ' . $e->getMessage(), 'err' );
	pt_log( 'This needs the pcbfr table and master_table. Check they exist.', 'info' );
}

// ── Phase 6: Re-sync Entity Reports ──────────────────────────────────────

pt_section( 'Phase 6: Syncing Entity Reports' );

try {
	$t0      = microtime( true );
	$results = Entity_Reports::sync_all();
	$elapsed = round( ( microtime( true ) - $t0 ), 1 );

	$ok_count  = 0;
	$fail_count = 0;
	foreach ( $results as $slug => $r ) {
		if ( $r['ok'] ) {
			$ok_count++;
			pt_log( "$slug — {$r['rows']} rows, $" . number_format( $r['total'] ?? 0, 0 ), 'ok' );
		} else {
			$fail_count++;
			pt_log( "$slug — FAILED: " . ( $r['reason'] ?? 'unknown' ), 'err' );
		}
	}
	pt_log( "Entity sync completed in {$elapsed}s — $ok_count OK, $fail_count failed", $fail_count ? 'warn' : 'ok' );
} catch ( \Throwable $e ) {
	pt_log( 'Entity sync FAILED: ' . $e->getMessage(), 'err' );
	pt_log( 'This needs govdata_cache_01 (GOVDATA_CACHE_DB1 in wp-config). Check it is configured.', 'info' );
}

// ── Phase 7: Re-warm MP Finance ──────────────────────────────────────────

pt_section( 'Phase 7: Warming MP Finance Cache' );

try {
	$t0 = microtime( true );
	MP_Finance_Warmer::run_warm();
	$elapsed = round( ( microtime( true ) - $t0 ), 1 );
	pt_log( "MP finance cache warmed in {$elapsed}s", 'ok' );
} catch ( \Throwable $e ) {
	pt_log( 'MP finance warm FAILED: ' . $e->getMessage(), 'err' );
	pt_log( 'This needs hoc_member_expenditures and hoc_reporting_periods tables.', 'info' );
}

// ── Phase 8: Verify ──────────────────────────────────────────────────────

pt_section( 'Phase 8: Verification' );

// Dashboard.
$dash = Dashboard_Stats::get();
pt_log( 'Dashboard total_spend: $' . number_format( $dash['total_spend'] ?? 0, 0 ), ( $dash['total_spend'] ?? 0 ) > 0 ? 'ok' : 'warn' );
pt_log( 'Dashboard total_agreements: ' . number_format( $dash['total_agreements'] ?? 0 ), ( $dash['total_agreements'] ?? 0 ) > 0 ? 'ok' : 'warn' );

// Riding cache sample.
$riding_cache = \PublicTech\Core\Riding_Cache::instance();
$dir = $riding_cache->directory();
pt_log( 'Riding directory entries: ' . count( $dir ), count( $dir ) > 0 ? 'ok' : 'warn' );
if ( ! empty( $dir[0]['number'] ) ) {
	$sample = $riding_cache->report_by_number( (string) $dir[0]['number'] );
	pt_log( 'Sample riding (' . ( $dir[0]['name_en'] ?? $dir[0]['number'] ) . '): ' . ( $sample ? 'has data' : 'EMPTY' ), $sample ? 'ok' : 'warn' );
}

// Entity cache sample.
if ( ! empty( $entities[0]['slug'] ) ) {
	$snap = Entity_Reports::cached_snapshot( $entities[0]['slug'] );
	pt_log( 'Sample entity (' . $entities[0]['name'] . '): ' . ( $snap ? '$' . number_format( $snap['total'] ?? 0, 0 ) : 'NO CACHED DATA' ), $snap ? 'ok' : 'warn' );
}

// MP post meta spot-check.
$mp_posts = get_posts( [ 'post_type' => 'member-parliament', 'posts_per_page' => 1, 'fields' => 'ids' ] );
if ( ! empty( $mp_posts[0] ) ) {
	$spend = (int) get_post_meta( $mp_posts[0], 'total_spend', true );
	pt_log( 'Sample MP (post ' . $mp_posts[0] . ') total_spend: $' . number_format( $spend, 0 ), $spend > 0 ? 'ok' : 'warn' );
}

pt_section( 'Done' );
pt_log( 'All cache layers have been rebuilt. Reload the frontend pages to verify.', 'ok' );
pt_log( 'If any warnings above, check the specific warmer logs for details.', 'info' );
