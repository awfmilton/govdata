<?php
/**
 * Fallback single-member template. Overridden by the child theme.
 *
 * @package PublicTech\Core
 */

defined( 'ABSPATH' ) || exit;

get_header();
while ( have_posts() ) :
	the_post();
	$mp_id = get_the_ID();
	?>
	<main class="pt-profile-fallback">
		<h1><?php the_title(); ?></h1>
		<div class="kvs">
			<div class="kv"><span class="k">Riding</span><span class="v"><?php echo esc_html( get_post_meta( $mp_id, 'riding', true ) ); ?></span></div>
			<div class="kv"><span class="k">Total Spend</span><span class="v">$<?php echo esc_html( number_format_i18n( (int) get_post_meta( $mp_id, 'total_spend', true ) ) ); ?></span></div>
		</div>
		<div class="chart-host" id="lineChart" style="height:280px"></div>
		<script>
			(function () {
				if (!window.PT) return;
				fetch(PT_BOOT.rest + '/mp/<?php echo (int) $mp_id; ?>/spend', { credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (rows) {
						const byCat = {};
						rows.forEach(function (r) {
							(byCat[r.category] = byCat[r.category] || []).push({ x: +r.fiscal_year, y: +r.total });
						});
						PT.chart('#lineChart', 'line', Object.keys(byCat).map(function (k) { return { key: k, values: byCat[k] }; }));
					});
			})();
		</script>
	</main>
	<?php
endwhile;
get_footer();
