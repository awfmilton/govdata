<?php
/**
 * Template Name: PT — Home Dashboard
 *
 * Hero + animated KPI counters + scrolling transaction ticker + real-data
 * D3 charts (annual trend, province breakdown, top recipients) + activity
 * feed + featured queries + forum threads.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

// Compare mode short-circuits the regular dashboard render.
$pt_compare_ids = [];
if ( isset( $_GET['compare'] ) ) {
	$pt_compare_ids = array_values( array_filter( array_map( 'absint', explode( ',', wp_unslash( (string) $_GET['compare'] ) ) ) ) );
	$pt_compare_ids = array_values( array_filter( $pt_compare_ids, static function ( $id ) {
		return 'member-parliament' === get_post_type( $id );
	} ) );
	$pt_compare_ids = array_slice( $pt_compare_ids, 0, 4 );
}
if ( count( $pt_compare_ids ) >= 2 ) {
	require PUBLICTECH_TERMINAL_DIR . 'parts/compare-mps.php';
	return;
}

$pt_user   = wp_get_current_user();
$pt_name   = $pt_user && $pt_user->ID ? $pt_user->first_name ?: $pt_user->display_name : __( 'guest', 'publictech-terminal' );
$pt_health = \PublicTech\Core\Multi_DB::instance()->aliases();

$pt_total = wp_cache_get( 'pt_count_mp', 'publictech' );
if ( false === $pt_total ) {
	$counts   = wp_count_posts( 'member-parliament' );
	$pt_total = is_object( $counts ) ? (int) ( $counts->publish ?? 0 ) : 0;
	wp_cache_set( 'pt_count_mp', $pt_total, 'publictech', 300 );
}
$pt_flags = wp_cache_get( 'pt_count_flags', 'publictech' );
if ( false === $pt_flags ) {
	$counts   = wp_count_posts( 'audit-flag' );
	$pt_flags = is_object( $counts ) ? (int) ( $counts->publish ?? 0 ) : 0;
	wp_cache_set( 'pt_count_flags', $pt_flags, 'publictech', 300 );
}

// Dashboard aggregate stats (cached 15 min; returns empty payload on DB error).
$pt_dash = class_exists( '\\PublicTech\\Core\\Dashboard_Stats' )
	? \PublicTech\Core\Dashboard_Stats::get()
	: [ 'total_spend' => 0, 'total_agreements' => 0, 'avg_value' => 0, 'recent_large' => [], 'by_year' => [], 'by_province' => [], 'top_recipients' => [] ];

// PHP number formatter for KPI display.
$pt_fmt_money = static function ( float $n ): string {
	if ( $n >= 1e9 ) return '$' . number_format( $n / 1e9, 2 ) . 'B';
	if ( $n >= 1e6 ) return '$' . number_format( $n / 1e6, 1 ) . 'M';
	if ( $n >= 1e3 ) return '$' . number_format( $n / 1e3, 0 ) . 'K';
	return '$' . number_format( $n, 0 );
};

get_header(); ?>

<main>
	<section class="view active" data-pt-view="home">

		<?php /* ── HERO ──────────────────────────────────────────────── */ ?>
		<div class="hero corner">
			<div class="hero-grid">
				<div>
					<div class="mono uc" style="color:var(--accent);font-size:10px;letter-spacing:0.2em;margin-bottom:10px">◇ <?php esc_html_e( 'Public Records · Audit Workspace', 'publictech-terminal' ); ?></div>
					<h2><?php
						/* translators: %s: user display name */
						printf( esc_html__( 'Welcome back, %s.', 'publictech-terminal' ), '<span class="acc">' . esc_html( $pt_name ) . '</span>' );
					?></h2>
					<p class="lede"><?php esc_html_e( 'Real-time access to government grants, contributions and contracts. Search across every Member of Parliament, stream millions of transactions, or ask the AI Console in natural language.', 'publictech-terminal' ); ?></p>
					<div class="ctas">
						<a class="cta"       href="<?php echo esc_url( home_url( '/ai' ) ); ?>">▶ <?php esc_html_e( 'Open AI Console', 'publictech-terminal' ); ?></a>
						<a class="cta ghost" href="<?php echo esc_url( get_post_type_archive_link( 'member-parliament' ) ); ?>">⌕ <?php esc_html_e( 'Browse Directory', 'publictech-terminal' ); ?></a>
						<a class="cta ghost" href="<?php echo esc_url( home_url( '/stream' ) ); ?>">≡ <?php esc_html_e( 'Stream Transactions', 'publictech-terminal' ); ?></a>
					</div>
				</div>
				<div class="hero-stats">
					<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Members', 'publictech-terminal' ); ?></div><div class="val"><?php echo esc_html( number_format_i18n( $pt_total ) ); ?></div></div>
					<div class="hero-stat pt-tip"
						data-pt-tip-title="<?php esc_attr_e( 'Open flags', 'publictech-terminal' ); ?>"
						data-pt-tip="<?php esc_attr_e( 'Audit flags raised by the community on specific transactions that are still open for review.', 'publictech-terminal' ); ?>"><div class="lab"><?php esc_html_e( 'Open Flags', 'publictech-terminal' ); ?></div><div class="val"><?php echo esc_html( number_format_i18n( $pt_flags ) ); ?></div></div>
					<div class="hero-stat pt-tip"
						data-pt-tip-title="<?php esc_attr_e( 'Data schemas', 'publictech-terminal' ); ?>"
						data-pt-tip="<?php esc_attr_e( 'Connected audit-data clusters you can query at your current tier — finance, grants, contracts and registry.', 'publictech-terminal' ); ?>"><div class="lab"><?php esc_html_e( 'Schemas', 'publictech-terminal' ); ?></div><div class="val"><?php echo esc_html( count( $pt_health ) ); ?></div></div>
					<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Tier', 'publictech-terminal' ); ?></div><div class="val"><?php echo esc_html( ucfirst( \PublicTech\Core\Access_Tiers::instance()->user_tier( get_current_user_id() ?: null ) ) ); ?></div></div>
				</div>
			</div>
		</div>

		<?php /* ── TRANSACTION TICKER ──────────────────────────────── */ ?>
		<?php if ( ! empty( $pt_dash['recent_large'] ) ) : ?>
		<div class="ticker-wrap" aria-label="<?php esc_attr_e( 'Recent large agreements', 'publictech-terminal' ); ?>" aria-hidden="true">
			<div class="ticker-track">
				<?php foreach ( $pt_dash['recent_large'] as $tx ) :
					$tx_name = mb_strlen( $tx['name'] ) > 42 ? mb_substr( $tx['name'], 0, 42 ) . '…' : $tx['name'];
				?>
				<div class="ticker-item">
					<span class="t-ico">◇</span>
					<span class="t-name"><?php echo esc_html( $tx_name ); ?></span>
					<span class="t-pipe">·</span>
					<span class="t-val"><?php echo esc_html( '$' . number_format( $tx['value'], 0 ) ); ?></span>
					<?php if ( $tx['province'] ) : ?>
					<span class="t-pipe">·</span>
					<span class="t-prov"><?php echo esc_html( $tx['province'] ); ?></span>
					<?php endif; ?>
					<?php if ( $tx['fiscal_year'] ) : ?>
					<span class="t-pipe">·</span>
					<span class="t-prov"><?php echo esc_html( $tx['fiscal_year'] ); ?></span>
					<?php endif; ?>
					<span class="t-gap">　　</span>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>

		<?php /* ── KPI ROW ─────────────────────────────────────────── */ ?>
		<div class="dash-kpis">
			<div class="dash-kpi corner pt-tip"
				data-pt-tip-title="<?php esc_attr_e( 'Total disbursed', 'publictech-terminal' ); ?>"
				data-pt-tip="<?php esc_attr_e( 'Sum of every federal grant and contribution agreement in the database, across all fiscal years and provinces.', 'publictech-terminal' ); ?>">
				<div class="dk-val"
					data-count-target="<?php echo esc_attr( $pt_dash['total_spend'] ); ?>"
					data-count-prefix="$"
					data-count-unit="money"><?php echo esc_html( $pt_fmt_money( (float) $pt_dash['total_spend'] ) ); ?></div>
				<div class="dk-lab"><?php esc_html_e( 'Total Disbursed', 'publictech-terminal' ); ?></div>
				<div class="dk-sub"><?php esc_html_e( 'Grants &amp; Contributions', 'publictech-terminal' ); ?></div>
			</div>
			<div class="dash-kpi corner pt-tip"
				data-pt-tip-title="<?php esc_attr_e( 'Total agreements', 'publictech-terminal' ); ?>"
				data-pt-tip="<?php esc_attr_e( 'Count of individual grant and contribution agreements on record across every connected schema.', 'publictech-terminal' ); ?>">
				<div class="dk-val"
					data-count-target="<?php echo esc_attr( $pt_dash['total_agreements'] ); ?>"
					data-count-unit="count"><?php echo esc_html( number_format_i18n( (int) $pt_dash['total_agreements'] ) ); ?></div>
				<div class="dk-lab"><?php esc_html_e( 'Total Agreements', 'publictech-terminal' ); ?></div>
				<div class="dk-sub"><?php esc_html_e( 'In the database', 'publictech-terminal' ); ?></div>
			</div>
			<div class="dash-kpi corner pt-tip"
				data-pt-tip-title="<?php esc_attr_e( 'Average award', 'publictech-terminal' ); ?>"
				data-pt-tip="<?php esc_attr_e( 'Total disbursed divided by the number of agreements — the mean value of a single award.', 'publictech-terminal' ); ?>">
				<div class="dk-val"
					data-count-target="<?php echo esc_attr( $pt_dash['avg_value'] ); ?>"
					data-count-prefix="$"
					data-count-unit="money"><?php echo esc_html( $pt_fmt_money( (float) $pt_dash['avg_value'] ) ); ?></div>
				<div class="dk-lab"><?php esc_html_e( 'Average Award', 'publictech-terminal' ); ?></div>
				<div class="dk-sub"><?php esc_html_e( 'Per agreement', 'publictech-terminal' ); ?></div>
			</div>
		</div>

		<?php /* ── VISUALIZATION ROW ──────────────────────────────── */ ?>
		<div class="viz-row">
			<div class="panel corner">
				<div class="hd">
					<h3>◇ <?php esc_html_e( 'Annual Spend Trend', 'publictech-terminal' ); ?></h3>
					<span class="meta"><?php esc_html_e( '2010 — PRESENT', 'publictech-terminal' ); ?></span>
				</div>
				<div class="home-chart" id="pt-year-chart" style="height:220px"></div>
			</div>
			<div class="panel corner">
				<div class="hd">
					<h3>◇ <?php esc_html_e( 'Spend by Province', 'publictech-terminal' ); ?></h3>
					<span class="meta"><?php esc_html_e( 'TOP 13', 'publictech-terminal' ); ?></span>
				</div>
				<div id="pt-province-chart" class="dash-hbar-host"></div>
			</div>
			<div class="panel corner">
				<div class="hd">
					<h3>◇ <?php esc_html_e( 'Top Recipients', 'publictech-terminal' ); ?></h3>
					<span class="meta"><?php esc_html_e( 'BY VALUE', 'publictech-terminal' ); ?></span>
				</div>
				<div id="pt-recipients-chart" class="dash-hbar-host"></div>
			</div>
		</div>

		<?php /* ── MAIN CONTENT GRID ────────────────────────────────── */ ?>
		<div class="home-grid">
			<div class="home-col">

				<div class="panel corner">
					<div class="hd"><h3>◇ <?php esc_html_e( 'Activity Feed', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'RECENT', 'publictech-terminal' ); ?></span></div>
					<div>
						<?php
						$activity = get_transient( 'pt_activity_feed' );
						if ( false === $activity ) {
							$_posts = get_posts( [ 'post_type' => 'audit-flag', 'posts_per_page' => 6, 'post_status' => [ 'publish', 'pending' ] ] );
							$activity = array_map( static function ( \WP_Post $p ): array {
								return [
									'title'     => $p->post_title,
									'schema'    => (string) get_post_meta( $p->ID, 'schema', true ),
									'timestamp' => (int) get_post_time( 'U', true, $p ),
								];
							}, $_posts );
							set_transient( 'pt_activity_feed', $activity, 120 );
						}
						if ( $activity ) :
							foreach ( $activity as $flag ) : ?>
								<div class="activity-row">
									<div class="ic warn">!</div>
									<div class="body">
										<b><?php echo esc_html( $flag['title'] ); ?></b>
										<div class="meta"><?php echo esc_html( $flag['schema'] ); ?></div>
									</div>
									<div class="when"><?php echo esc_html( human_time_diff( $flag['timestamp'], time() ) ); ?></div>
								</div>
							<?php endforeach;
						else : ?>
							<div class="activity-row"><div class="ic">⌬</div><div class="body"><?php esc_html_e( 'No recent activity. Run a query to populate this feed.', 'publictech-terminal' ); ?></div></div>
						<?php endif; ?>
					</div>
				</div>

				<div class="panel corner">
					<div class="hd"><h3>◇ <?php esc_html_e( 'Featured Queries', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'CURATED', 'publictech-terminal' ); ?></span></div>
					<div class="featured">
						<?php foreach ( [
							[ 'Spending', __( 'Office expenditures across all members, ranked by Q1 variance.', 'publictech-terminal' ) ],
							[ 'Contracts', __( 'Vendors awarded more than 5 sole-source contracts in 24 months.', 'publictech-terminal' ) ],
							[ 'Grants', __( 'Green-tech disbursements to recipients with no prior funding history.', 'publictech-terminal' ) ],
							[ 'Travel', __( 'International travel claims clustering by destination and committee.', 'publictech-terminal' ) ],
						] as $q ) : ?>
							<a class="featured-q" href="<?php echo esc_url( home_url( '/ai' ) ); ?>">
								<div class="lab">// <?php echo esc_html( $q[0] ); ?></div>
								<div class="q"><?php echo esc_html( $q[1] ); ?></div>
							</a>
						<?php endforeach; ?>
					</div>
				</div>

			</div>

			<div class="home-col">

				<div class="panel corner">
					<div class="hd"><h3>◇ <?php esc_html_e( 'Hot Forum Threads', 'publictech-terminal' ); ?></h3><span class="meta">24h</span></div>
					<div>
						<?php
						$threads = get_transient( 'pt_forum_threads' );
						if ( false === $threads ) {
							$_posts = get_posts( [ 'post_type' => 'forum-thread', 'posts_per_page' => 5, 'orderby' => 'comment_count', 'order' => 'DESC' ] );
							$threads = array_map( static function ( \WP_Post $p ): array {
								return [
									'title'         => $p->post_title,
									'permalink'     => get_permalink( $p->ID ),
									'comment_count' => (int) $p->comment_count,
									'modified'      => (int) get_post_modified_time( 'U', true, $p ),
								];
							}, $_posts );
							set_transient( 'pt_forum_threads', $threads, 120 );
						}
						if ( $threads ) :
							foreach ( $threads as $t ) : ?>
								<a class="watch-row" href="<?php echo esc_url( $t['permalink'] ); ?>">
									<div class="av">⌬</div>
									<div>
										<div class="name"><?php echo esc_html( $t['title'] ); ?></div>
										<div class="meta"><?php echo esc_html( number_format_i18n( $t['comment_count'] ) ); ?> <?php esc_html_e( 'replies', 'publictech-terminal' ); ?></div>
									</div>
									<div class="trend"><b><?php echo esc_html( human_time_diff( $t['modified'], time() ) ); ?></b></div>
								</a>
							<?php endforeach;
						else : ?>
							<div class="activity-row"><div class="ic">⌬</div><div class="body"><?php esc_html_e( 'No forum threads yet. Start a discussion to populate this panel.', 'publictech-terminal' ); ?></div></div>
						<?php endif; ?>
					</div>
				</div>

			</div>
		</div>

	</section>
</main>

<?php /* Inline dashboard data for JS chart rendering (avoids an extra AJAX round-trip). */ ?>
<script>window.PT_DASH = <?php echo wp_json_encode( $pt_dash ); ?>;</script>

<?php
get_footer();
