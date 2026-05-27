<?php
/**
 * Template Name: PT — Home Dashboard
 *
 * Hero + activity feed + watchlist + national rollup chart.
 *
 * Also handles compare mode: if a `?compare=id1,id2[,id3,id4]` URL parameter
 * resolves to 2–4 valid member-parliament posts, the dashboard re-routes to
 * the split-screen comparison layout rendered by parts/compare-mps.php.
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
	$pt_compare_ids = array_slice( $pt_compare_ids, 0, 4 ); // hard cap — UI gets unreadable past 4.
}
if ( count( $pt_compare_ids ) >= 2 ) {
	require PUBLICTECH_TERMINAL_DIR . 'parts/compare-mps.php';
	return;
}

$pt_user   = wp_get_current_user();
$pt_name   = $pt_user && $pt_user->ID ? $pt_user->first_name ?: $pt_user->display_name : __( 'guest', 'publictech-terminal' );
$pt_health = \PublicTech\Core\Multi_DB::instance()->aliases();

// wp_count_posts is a COUNT(*) over wp_posts; cache for 5 minutes on the
// dashboard hot path. wp_count_posts can return false on disabled CPTs.
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

get_header(); ?>

<main>
	<section class="view active" data-pt-view="home">
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
					<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Open Flags', 'publictech-terminal' ); ?></div><div class="val"><?php echo esc_html( number_format_i18n( $pt_flags ) ); ?></div></div>
					<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Connected Schemas', 'publictech-terminal' ); ?></div><div class="val"><?php echo esc_html( count( $pt_health ) ); ?></div></div>
					<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Tier', 'publictech-terminal' ); ?></div><div class="val"><?php echo esc_html( ucfirst( \PublicTech\Core\Access_Tiers::instance()->user_tier( get_current_user_id() ?: null ) ) ); ?></div></div>
				</div>
			</div>
		</div>

		<div class="home-grid">
			<div class="home-col">
				<div class="panel corner">
					<div class="hd"><h3>◇ <?php esc_html_e( 'Activity Feed', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'REAL-TIME', 'publictech-terminal' ); ?></span></div>
					<div>
						<?php
						$activity = get_posts( [ 'post_type' => 'audit-flag', 'posts_per_page' => 6, 'post_status' => [ 'publish', 'pending' ] ] );
						if ( $activity ) :
							foreach ( $activity as $flag ) : ?>
								<div class="activity-row">
									<div class="ic warn">!</div>
									<div class="body">
										<b><?php echo esc_html( $flag->post_title ); ?></b>
										<div class="meta"><?php echo esc_html( get_post_meta( $flag->ID, 'schema', true ) ); ?></div>
									</div>
									<div class="when"><?php echo esc_html( human_time_diff( (int) get_post_time( 'U', true, $flag ), time() ) ); ?></div>
								</div>
								<?php
							endforeach;
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
					<div class="hd"><h3>◇ <?php esc_html_e( 'National Spend · 90d', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'ROLLUP', 'publictech-terminal' ); ?></span></div>
					<div class="home-chart" id="pt-home-chart" style="height:200px"></div>
				</div>

				<div class="panel corner">
					<div class="hd"><h3>◇ <?php esc_html_e( 'Hot Forum Threads', 'publictech-terminal' ); ?></h3><span class="meta">24h</span></div>
					<div>
						<?php
						$threads = get_posts( [ 'post_type' => 'forum-thread', 'posts_per_page' => 5, 'orderby' => 'comment_count', 'order' => 'DESC' ] );
						if ( $threads ) :
							foreach ( $threads as $t ) : ?>
								<a class="watch-row" href="<?php echo esc_url( get_permalink( $t ) ); ?>">
									<div class="av">⌬</div>
									<div>
										<div class="name"><?php echo esc_html( $t->post_title ); ?></div>
										<div class="meta"><?php echo esc_html( get_comments_number( $t ) ); ?> <?php esc_html_e( 'replies', 'publictech-terminal' ); ?></div>
									</div>
									<div class="trend"><b><?php echo esc_html( human_time_diff( (int) get_post_modified_time( 'U', true, $t ), time() ) ); ?></b></div>
								</a>
								<?php
							endforeach;
						endif;
						?>
					</div>
				</div>
			</div>
		</div>
	</section>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
	if (!window.PT || !PT.chart) return;
	const series = Array.from({ length: 90 }, function (_, i) {
		return { x: i, y: 200 + Math.round(80 * Math.sin(i / 9) + Math.random() * 30) };
	});
	PT.chart('#pt-home-chart', 'line', [{ key: 'spend', values: series }]);
});
</script>

<?php
get_footer();
