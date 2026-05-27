<?php
/**
 * Compare-MPs split-screen layout.
 *
 * Renders 2–4 member-parliament profiles side-by-side: header card with
 * party/riding, KPI grid, mini-line chart of category spend over time
 * (data fetched client-side from /publictech/v1/mp/{id}/spend), and a row of
 * raw transactions streamed from pt-finance-01.
 *
 * Invoked from template-dashboard.php when the `?compare=` URL parameter
 * resolves to 2+ valid MP IDs.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $pt_compare_ids ) || count( $pt_compare_ids ) < 2 ) {
	return;
}

get_header();

$pt_col_count = count( $pt_compare_ids );
?>

<main>
	<section class="view active pt-compare" data-pt-view="compare" data-pt-cols="<?php echo (int) $pt_col_count; ?>">
		<div class="pt-compare-head">
			<div>
				<div class="mono uc" style="color:var(--accent);font-size:10px;letter-spacing:0.2em;margin-bottom:6px">◇ <?php esc_html_e( 'Comparison Mode', 'publictech-terminal' ); ?></div>
				<h2 style="margin:0;font-size:20px;font-weight:600">
					<?php
					/* translators: %d: number of members being compared */
					printf( esc_html__( 'Side-by-side · %d members', 'publictech-terminal' ), (int) $pt_col_count );
					?>
				</h2>
			</div>
			<a class="cta ghost" href="<?php echo esc_url( remove_query_arg( 'compare' ) ); ?>">↩ <?php esc_html_e( 'Exit comparison', 'publictech-terminal' ); ?></a>
		</div>

		<div class="pt-compare-grid">
			<?php foreach ( $pt_compare_ids as $pt_idx => $pt_mp_id ) :
				$pt_party_terms  = get_the_terms( $pt_mp_id, 'mp_party' );
				$pt_region_terms = get_the_terms( $pt_mp_id, 'mp_region' );
				$pt_party        = $pt_party_terms  && ! is_wp_error( $pt_party_terms  ) ? $pt_party_terms[0]->name  : '';
				$pt_region       = $pt_region_terms && ! is_wp_error( $pt_region_terms ) ? $pt_region_terms[0]->name : '';
				$pt_pclass       = $pt_party ? sanitize_html_class( strtolower( $pt_party ) ) : 'unknown';
				$pt_riding       = (string) get_post_meta( $pt_mp_id, 'riding',       true );
				$pt_spend        = (int)    get_post_meta( $pt_mp_id, 'total_spend',  true );
				$pt_travel       = (int)    get_post_meta( $pt_mp_id, 'travel_spend', true );
				$pt_flags        = (int)    get_post_meta( $pt_mp_id, 'open_flags',   true );
				$pt_initials     = strtoupper( substr( wp_strip_all_tags( (string) get_the_title( $pt_mp_id ) ), 0, 2 ) );
				?>
				<div class="pt-compare-col panel corner" data-mp-id="<?php echo (int) $pt_mp_id; ?>">
					<div class="hd">
						<h3>◇ <?php echo esc_html( get_the_title( $pt_mp_id ) ); ?></h3>
						<a class="meta" href="<?php echo esc_url( get_permalink( $pt_mp_id ) ); ?>"><?php esc_html_e( 'open profile →', 'publictech-terminal' ); ?></a>
					</div>
					<div class="bd">
						<div class="pt-compare-id">
							<div class="mp-portrait" style="width:46px;height:46px"><?php echo esc_html( $pt_initials ?: '··' ); ?></div>
							<div>
								<div class="pt-compare-party"><span class="party-bar party-<?php echo esc_attr( $pt_pclass ); ?>"></span><?php echo esc_html( $pt_party ); ?></div>
								<div class="dim" style="font-size:11px;margin-top:2px"><?php echo esc_html( trim( $pt_riding . ( $pt_region ? ', ' . $pt_region : '' ), ', ' ) ); ?></div>
							</div>
						</div>

						<div class="kpis" style="margin-top:14px;grid-template-columns:repeat(2,1fr)">
							<div class="kpi">
								<div class="lab"><?php esc_html_e( 'Office Spend', 'publictech-terminal' ); ?></div>
								<div class="val">$<?php echo esc_html( number_format_i18n( $pt_spend ) ); ?></div>
							</div>
							<div class="kpi">
								<div class="lab"><?php esc_html_e( 'Travel', 'publictech-terminal' ); ?></div>
								<div class="val">$<?php echo esc_html( number_format_i18n( $pt_travel ) ); ?></div>
							</div>
							<div class="kpi">
								<div class="lab"><?php esc_html_e( 'Open Flags', 'publictech-terminal' ); ?></div>
								<div class="val" style="color:<?php echo $pt_flags ? 'var(--warn)' : 'var(--ink-0)'; ?>"><?php echo esc_html( $pt_flags ); ?></div>
							</div>
							<div class="kpi">
								<div class="lab"><?php esc_html_e( 'Spend / Flag', 'publictech-terminal' ); ?></div>
								<div class="val"><?php echo $pt_flags ? '$' . esc_html( number_format_i18n( (int) round( $pt_spend / max( 1, $pt_flags ) ) ) ) : '—'; ?></div>
							</div>
						</div>

						<div class="chart-host" id="pt-compare-line-<?php echo (int) $pt_idx; ?>" style="height:200px;margin-top:14px"></div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</section>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
	if (!window.PT || !PT.chart || !window.PT_BOOT) return;
	<?php foreach ( $pt_compare_ids as $pt_idx => $pt_mp_id ) : ?>
	fetch(PT_BOOT.rest + '/mp/<?php echo (int) $pt_mp_id; ?>/spend', { credentials: 'same-origin', headers: { 'x-wp-nonce': PT_BOOT.nonce } })
		.then(function (r) { return r.ok ? r.json() : []; })
		.then(function (rows) {
			const byCat = {};
			(rows || []).forEach(function (r) {
				(byCat[r.category] = byCat[r.category] || []).push({ x: +r.fiscal_year, y: +r.total / 1000 });
			});
			const series = Object.keys(byCat).map(function (k) {
				return { key: k, values: byCat[k].sort(function (a, b) { return a.x - b.x; }) };
			});
			if (series.length) PT.chart('#pt-compare-line-<?php echo (int) $pt_idx; ?>', 'line', series);
		});
	<?php endforeach; ?>
});
</script>

<style>
.pt-compare-head{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:14px;gap:14px}
.pt-compare-grid{display:grid;grid-template-columns:repeat(var(--pt-compare-cols, 2), 1fr);gap:14px}
.pt-compare[data-pt-cols="2"]{--pt-compare-cols:2}
.pt-compare[data-pt-cols="3"]{--pt-compare-cols:3}
.pt-compare[data-pt-cols="4"]{--pt-compare-cols:4}
.pt-compare-col .bd{display:flex;flex-direction:column}
.pt-compare-id{display:flex;align-items:center;gap:12px}
.pt-compare-party{font-size:11px;color:var(--ink-1);display:flex;align-items:center;gap:6px}
@media (max-width:980px){.pt-compare-grid{grid-template-columns:1fr}}
</style>

<?php
get_footer();
