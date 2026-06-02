<?php
/**
 * Template Name: PT — Chart Gallery
 *
 * Public-by-default grid of saved Billboard.js charts. Cards instantiate a
 * miniature Billboard instance once the bridge has hydrated them. Filter +
 * sort facets reuse the .panel.corner directory pattern from the MP archive.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$pt_uid   = get_current_user_id() ?: null;
$pt_quota = \PublicTech\Core\Access_Tiers::instance()->quota( $pt_uid );
$pt_can   = \PublicTech\Core\Chart_Spec::user_can_create( $pt_quota );

$pt_sort = sanitize_key( (string) ( $_GET['sort'] ?? 'recent' ) );
$pt_kind = sanitize_title( (string) ( $_GET['kind'] ?? '' ) );

$pt_args = [
	'post_type'      => 'user-chart',
	'post_status'    => 'publish',
	'posts_per_page' => 24,
	'paged'          => max( 1, (int) ( $_GET['paged'] ?? 1 ) ),
];
if ( 'forks' === $pt_sort ) {
	$pt_args['orderby']  = 'meta_value_num';
	$pt_args['meta_key'] = 'pt_forks';
	$pt_args['order']    = 'DESC';
} elseif ( 'views' === $pt_sort ) {
	$pt_args['orderby']  = 'meta_value_num';
	$pt_args['meta_key'] = 'pt_views';
	$pt_args['order']    = 'DESC';
} else {
	$pt_args['orderby'] = 'date';
	$pt_args['order']   = 'DESC';
}
if ( $pt_kind ) {
	$pt_args['tax_query'] = [ [ 'taxonomy' => 'chart_kind', 'field' => 'slug', 'terms' => $pt_kind ] ];
}
$pt_query = new WP_Query( $pt_args );
$pt_kinds = get_terms( [ 'taxonomy' => 'chart_kind', 'hide_empty' => true ] );

get_header(); ?>

<main id="pt-main" tabindex="-1">
	<section class="view active" data-pt-view="chart-gallery">

		<div class="hero corner" style="margin-bottom:24px">
			<div class="hero-grid">
				<div>
					<div class="mono uc" style="color:var(--accent);font-size:10px;letter-spacing:0.2em;margin-bottom:10px">◇ <?php esc_html_e( 'Community Charts · Public Gallery', 'publictech-terminal' ); ?></div>
					<h2><?php esc_html_e( 'Charts the community has built.', 'publictech-terminal' ); ?></h2>
					<p class="lede"><?php esc_html_e( 'Fork any chart into your account and rebuild it with fresh filters, your own data, or a different chart type.', 'publictech-terminal' ); ?></p>
					<div class="ctas">
						<?php if ( $pt_can ) : ?>
							<a class="cta" href="<?php echo esc_url( home_url( '/chart-builder' ) ); ?>">▶ <?php esc_html_e( 'Open Chart Builder', 'publictech-terminal' ); ?></a>
						<?php else : ?>
							<a class="cta" href="<?php echo esc_url( home_url( '/pricing' ) ); ?>">★ <?php esc_html_e( 'Upgrade to build charts', 'publictech-terminal' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>

		<form class="stream-bar corner" method="get" style="margin-bottom:24px">
			<select name="kind" onchange="this.form.submit()">
				<option value=""><?php esc_html_e( 'All chart kinds', 'publictech-terminal' ); ?></option>
				<?php foreach ( $pt_kinds as $term ) : ?>
					<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $pt_kind, $term->slug ); ?>>
						<?php echo esc_html( $term->name ); ?> · <?php echo esc_html( $term->count ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<select name="sort" onchange="this.form.submit()">
				<option value="recent" <?php selected( $pt_sort, 'recent' ); ?>><?php esc_html_e( 'Most recent', 'publictech-terminal' ); ?></option>
				<option value="views"  <?php selected( $pt_sort, 'views' ); ?>><?php esc_html_e( 'Most viewed',  'publictech-terminal' ); ?></option>
				<option value="forks"  <?php selected( $pt_sort, 'forks' ); ?>><?php esc_html_e( 'Most forked',  'publictech-terminal' ); ?></option>
			</select>
		</form>

		<?php if ( ! $pt_query->have_posts() ) : ?>
			<div class="panel corner" style="padding:32px;text-align:center">
				<div class="dim"><?php esc_html_e( 'No charts published yet. Be the first.', 'publictech-terminal' ); ?></div>
			</div>
		<?php else : ?>
			<div class="pt-gallery-grid">
				<?php while ( $pt_query->have_posts() ) : $pt_query->the_post();
					$pt_id     = get_the_ID();
					$pt_kinds_for = wp_get_object_terms( $pt_id, 'chart_kind', [ 'fields' => 'names' ] );
					$pt_views_ct  = (int) get_post_meta( $pt_id, 'pt_views', true );
					$pt_forks_ct  = (int) get_post_meta( $pt_id, 'pt_forks', true );
				?>
					<article class="pt-gallery-card panel corner">
						<header class="pt-gallery-card-hd">
							<a class="pt-gallery-card-title" href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
							<span class="pt-gallery-kind"><?php echo esc_html( $pt_kinds_for[0] ?? '' ); ?></span>
						</header>
						<div class="pt-gallery-card-canvas pt-billboard-mount" data-pt-chart-id="<?php echo (int) $pt_id; ?>" style="height:200px"></div>
						<footer class="pt-gallery-card-ft">
							<span class="pt-gallery-by">
								<?php
								/* translators: %s: author display name */
								printf( esc_html__( 'by %s', 'publictech-terminal' ), '<b>' . esc_html( get_the_author() ) . '</b>' );
								?>
							</span>
							<span class="pt-gallery-stats">
								<?php
								/* translators: 1: views count, 2: forks count */
								printf( esc_html__( '%1$s views · %2$s forks', 'publictech-terminal' ),
									esc_html( number_format_i18n( $pt_views_ct ) ),
									esc_html( number_format_i18n( $pt_forks_ct ) )
								);
								?>
							</span>
							<?php if ( $pt_can && get_current_user_id() !== (int) get_the_author_meta( 'ID' ) ) : ?>
								<button class="btn ghost pt-gallery-fork" data-chart-id="<?php echo (int) $pt_id; ?>">⑂ <?php esc_html_e( 'Fork', 'publictech-terminal' ); ?></button>
							<?php endif; ?>
						</footer>
					</article>
				<?php endwhile; ?>
			</div>

			<?php
			$pt_pagination = paginate_links( [
				'total'   => $pt_query->max_num_pages,
				'current' => max( 1, (int) ( $_GET['paged'] ?? 1 ) ),
				'format'  => '?paged=%#%',
				'add_args' => array_filter( [
					'sort' => $pt_sort,
					'kind' => $pt_kind,
				] ),
			] );
			if ( $pt_pagination ) :
			?>
				<nav class="pt-gallery-pagination" aria-label="<?php esc_attr_e( 'Gallery pagination', 'publictech-terminal' ); ?>">
					<?php echo $pt_pagination; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — paginate_links output is already escaped. ?>
				</nav>
			<?php endif; wp_reset_postdata(); ?>
		<?php endif; ?>
	</section>
</main>

<script>
document.addEventListener('click', function (ev) {
	const btn = ev.target.closest('.pt-gallery-fork');
	if (!btn || !window.PT || !PT.boot) return;
	ev.preventDefault();
	const id = btn.dataset.chartId;
	btn.disabled = true;
	btn.textContent = '⑂ forking…';
	fetch(PT.boot.rest + '/charts/' + id + '/fork', {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'x-wp-nonce': PT.boot.nonce || '' },
	})
		.then(function (r) { return r.json(); })
		.then(function (chart) {
			if (chart && chart.permalink) {
				window.location.href = chart.permalink;
			} else {
				btn.disabled = false;
				btn.textContent = '⑂ fork failed';
			}
		});
});
</script>

<?php get_footer();
