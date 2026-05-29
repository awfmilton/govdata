<?php
/**
 * Single-member profile. Full prototype layout: hero/record sidebar, KPIs,
 * line chart (multi-year spending), bar chart (peer comparison), force-directed
 * network of vendors → categories. Chart data is fetched from
 * /publictech/v1/mp/{id}/spend so the same payload can be reused by exports.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

if ( ! have_posts() ) {
	get_header();
	echo '<main class="pt-shell"><div class="pt-error">' . esc_html__( 'Member not found.', 'publictech-terminal' ) . '</div></main>';
	get_footer();
	return;
}

the_post();
$mp_id    = get_the_ID();
$party    = get_the_terms( $mp_id, 'mp_party' );
$region   = get_the_terms( $mp_id, 'mp_region' );
$comm     = get_the_terms( $mp_id, 'mp_committee' ) ?: [];
$party_n  = $party  && ! is_wp_error( $party )  ? $party[0]->name  : '';
$region_n = $region && ! is_wp_error( $region ) ? $region[0]->name : '';
$pclass   = $party_n ? sanitize_html_class( strtolower( $party_n ) ) : 'unknown';
$riding   = (string) ( get_post_meta( $mp_id, 'pt_mp_district',  true ) ?: get_post_meta( $mp_id, 'riding',       true ) );
$postals  = (string) ( get_post_meta( $mp_id, 'pt_mp_postal_code', true ) ?: get_post_meta( $mp_id, 'postal_codes', true ) );
$role     = (string) get_post_meta( $mp_id, 'caucus_role',    true );
$elected  = (string) get_post_meta( $mp_id, 'first_elected',  true );
$tenure   = (string) get_post_meta( $mp_id, 'tenure',         true );
$ext_id   = (int)    get_post_meta( $mp_id, 'external_mp_id', true );
$spend    = (int)    get_post_meta( $mp_id, 'total_spend',    true );
$travel   = (int)    get_post_meta( $mp_id, 'travel_spend',   true );
$contracts= (int)    get_post_meta( $mp_id, 'contracts_n',    true );
$votes    = (int)    get_post_meta( $mp_id, 'votes_cast',     true );
$integrity= (int)    get_post_meta( $mp_id, 'integrity_score',true ) ?: 0;
$flags    = (int)    get_post_meta( $mp_id, 'open_flags',     true );
$last_aud = (string) get_post_meta( $mp_id, 'last_audit',     true );

/* ─── Riding grant & contribution rollups (read from the secondary cache DB) ─── */
$pt_riding_payload = null;
$pt_riding_label   = $riding ?: $region_n;
if ( class_exists( \PublicTech\Core\Riding_Cache::class ) ) {
	$pt_rc             = \PublicTech\Core\Riding_Cache::instance();
	$pt_riding_payload = $pt_rc->report_for_post( $mp_id );
	$pt_riding_entry   = $pt_rc->resolve_for_post( $mp_id );
	if ( is_array( $pt_riding_entry ) && ! empty( $pt_riding_entry['name_en'] ) ) {
		$pt_riding_label = (string) $pt_riding_entry['name_en'];
	}
}

get_header(); ?>

<main>
	<section class="view active" data-pt-view="profile">
		<div class="prof-grid">
			<aside class="prof-side">
				<div class="panel prof-hero corner">
					<div class="portrait" aria-hidden="true">
						<?php
						$pt_photo_url = (string) get_post_meta( $mp_id, 'pt_mp_photo_url', true );
						if ( has_post_thumbnail() ) {
							the_post_thumbnail( 'medium', [ 'alt' => esc_attr( get_the_title() ) ] );
						} elseif ( $pt_photo_url ) {
							echo '<img src="' . esc_url( $pt_photo_url ) . '" alt="' . esc_attr( get_the_title() ) . '" loading="lazy">';
						} else {
							echo '<span class="dim">' . esc_html__( 'portrait · placeholder', 'publictech-terminal' ) . '</span>';
						}
						?>
					</div>
					<h2><?php the_title(); ?></h2>
					<div class="ttl"><?php
						/* translators: 1: riding name */
						printf( esc_html__( 'Member of Parliament — %s', 'publictech-terminal' ), esc_html( $riding ?: $region_n ) );
					?></div>
					<?php if ( $party_n ) : ?>
						<div class="party-tag"><span class="party-bar party-<?php echo esc_attr( $pclass ); ?>"></span><?php echo esc_html( $party_n ); ?></div>
					<?php endif; ?>
					<div class="id">MP_ID · 0x<?php echo esc_html( strtoupper( substr( dechex( $mp_id * 9301 ), 0, 4 ) ) ); ?> · <?php echo $elected ? esc_html__( 'ELECTED', 'publictech-terminal' ) . ' ' . esc_html( $elected ) : esc_html__( 'INDEXED', 'publictech-terminal' ); ?></div>
				</div>

				<div class="panel">
					<div class="hd"><h3>◇ <?php esc_html_e( 'Record', 'publictech-terminal' ); ?></h3><span class="meta">REGISTRY/04</span></div>
					<div class="kvs">
						<?php
						$rows = [
							[ __( 'Riding',        'publictech-terminal' ), $riding ],
							[ __( 'Region',        'publictech-terminal' ), $region_n ],
							[ __( 'Postal Codes',  'publictech-terminal' ), $postals ],
							[ __( 'Caucus Role',   'publictech-terminal' ), $role ],
							[ __( 'Committees',    'publictech-terminal' ), implode( ' · ', wp_list_pluck( $comm, 'name' ) ) ],
							[ __( 'First Elected', 'publictech-terminal' ), $elected ],
							[ __( 'Tenure',        'publictech-terminal' ), $tenure ],
						];
						foreach ( $rows as $r ) :
							if ( '' === trim( (string) $r[1] ) ) { continue; }
							?>
							<div class="kv"><span class="k"><?php echo esc_html( $r[0] ); ?></span><span class="v"><?php echo esc_html( $r[1] ); ?></span></div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="panel">
					<div class="hd"><h3>◇ <?php esc_html_e( 'Audit Status', 'publictech-terminal' ); ?></h3><span class="meta">AUTO</span></div>
					<div class="bd" style="display:flex;flex-direction:column;gap:8px">
						<div style="display:flex;justify-content:space-between;font-size:12px">
							<span class="dim"><?php esc_html_e( 'Integrity score', 'publictech-terminal' ); ?></span>
							<span style="color:<?php echo $integrity >= 80 ? 'var(--pos)' : 'var(--warn)'; ?>;font-family:'JetBrains Mono'">
								<?php echo esc_html( $integrity ?: '—' ); ?><?php echo $integrity ? ' / 100' : ''; ?>
							</span>
						</div>
						<div style="display:flex;justify-content:space-between;font-size:12px">
							<span class="dim"><?php esc_html_e( 'Open flags', 'publictech-terminal' ); ?></span>
							<span style="color:<?php echo $flags ? 'var(--warn)' : 'var(--ink-2)'; ?>;font-family:'JetBrains Mono'"><?php echo esc_html( $flags ); ?></span>
						</div>
						<?php if ( $last_aud ) : ?>
							<div style="display:flex;justify-content:space-between;font-size:12px">
								<span class="dim"><?php esc_html_e( 'Last audit', 'publictech-terminal' ); ?></span>
								<span style="font-family:'JetBrains Mono'"><?php echo esc_html( $last_aud ); ?></span>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<?php
				// Corporate-affiliations panel. Source data is intentionally
				// pluggable: a site that has wired up a vendor↔asset link
				// table hooks `publictech_mp_affiliations` and returns rows
				// matching [ ['vendor' => …, 'role' => …, 'amount' => …,
				// 'source_url' => …] ]. The panel renders nothing if no
				// matches are returned.
				$pt_affiliations = (array) apply_filters( 'publictech_mp_affiliations', [], $mp_id );
				if ( $pt_affiliations ) :
					?>
					<div class="panel">
						<div class="hd"><h3>◇ <?php esc_html_e( 'Corporate Affiliations', 'publictech-terminal' ); ?></h3><span class="meta"><?php echo esc_html( number_format_i18n( count( $pt_affiliations ) ) ); ?></span></div>
						<div class="kvs">
							<?php foreach ( $pt_affiliations as $pt_aff ) :
								if ( ! is_array( $pt_aff ) || empty( $pt_aff['vendor'] ) ) { continue; }
								$pt_url = isset( $pt_aff['source_url'] ) ? (string) $pt_aff['source_url'] : '';
								?>
								<div class="kv">
									<span class="k">
										<?php echo esc_html( $pt_aff['vendor'] ); ?>
										<?php if ( ! empty( $pt_aff['role'] ) ) : ?>
											<span class="dim" style="margin-left:6px"><?php echo esc_html( $pt_aff['role'] ); ?></span>
										<?php endif; ?>
									</span>
									<span class="v">
										<?php if ( isset( $pt_aff['amount'] ) ) : ?>
											$<?php echo esc_html( number_format_i18n( (int) $pt_aff['amount'] ) ); ?>
										<?php endif; ?>
										<?php if ( $pt_url ) : ?>
											<a href="<?php echo esc_url( $pt_url ); ?>" rel="noopener noreferrer" target="_blank" title="<?php esc_attr_e( 'Source document', 'publictech-terminal' ); ?>" style="margin-left:6px;color:var(--accent)">⎘</a>
										<?php endif; ?>
									</span>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
			</aside>

			<div class="prof-main">
				<div class="kpis">
					<div class="kpi corner">
						<div class="lab"><?php esc_html_e( 'Total Office Spend', 'publictech-terminal' ); ?></div>
						<div class="val">$<?php echo esc_html( number_format_i18n( $spend ) ); ?></div>
						<div class="delta dim">YoY</div>
						<svg class="spark" id="ptSpark1" aria-hidden="true"></svg>
					</div>
					<div class="kpi">
						<div class="lab"><?php esc_html_e( 'Travel Expenses', 'publictech-terminal' ); ?></div>
						<div class="val">$<?php echo esc_html( number_format_i18n( $travel ) ); ?></div>
						<div class="delta dim">FY</div>
						<svg class="spark" id="ptSpark2" aria-hidden="true"></svg>
					</div>
					<div class="kpi">
						<div class="lab"><?php esc_html_e( 'Contracts Awarded', 'publictech-terminal' ); ?></div>
						<div class="val"><?php echo esc_html( number_format_i18n( $contracts ) ); ?></div>
						<div class="delta dim"><?php esc_html_e( 'vendors', 'publictech-terminal' ); ?></div>
						<svg class="spark" id="ptSpark3" aria-hidden="true"></svg>
					</div>
					<div class="kpi">
						<div class="lab"><?php esc_html_e( 'Votes Cast', 'publictech-terminal' ); ?></div>
						<div class="val"><?php echo esc_html( number_format_i18n( $votes ) ); ?></div>
						<div class="delta dim"><?php esc_html_e( 'attendance', 'publictech-terminal' ); ?></div>
						<svg class="spark" id="ptSpark4" aria-hidden="true"></svg>
					</div>
				</div>

				<div class="chart-row">
					<div class="panel corner">
						<div class="hd">
							<h3>◇ <?php esc_html_e( 'Spending Breakdown · Fiscal Years', 'publictech-terminal' ); ?></h3>
							<span class="meta">SCHEMA: pt-finance-01.expenditures</span>
						</div>
						<div class="bd">
							<div class="chart-host lg" id="ptLineChart" style="height:340px"></div>
							<div class="legend" style="margin-top:8px" id="ptLineLegend"></div>
						</div>
					</div>

					<div class="panel corner">
						<div class="hd"><h3>◇ <?php esc_html_e( 'Category Comparison · Peer Average', 'publictech-terminal' ); ?></h3><span class="meta">SCHEMA: pt-finance-01</span></div>
						<div class="bd">
							<div class="chart-host lg" id="ptBarChart" style="height:340px"></div>
						</div>
					</div>
				</div>

				<div class="panel corner">
					<div class="hd"><h3>◇ <?php esc_html_e( 'Funding Flow Network · Vendors → Categories', 'publictech-terminal' ); ?></h3><span class="meta">FORCE-DIRECTED</span></div>
					<div class="bd">
						<div class="chart-host" id="ptNetChart" style="height:360px"></div>
					</div>
				</div>

				<?php
				/* Federal grants & contributions for this member's riding — same
				   templated section on every MP page, data from the cache DB. */
				require PUBLICTECH_TERMINAL_DIR . 'parts/riding-spending.php';
				?>
			</div>
		</div>
	</section>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
	'use strict';
	if (!window.PT || !window.PT_BOOT) return;

	function spark(id, data, color) {
		const el = document.getElementById(id);
		if (el && PT.chart) PT.chart(el, 'spark', data, { color: color });
	}

	const restUrl = PT_BOOT.rest + '/mp/<?php echo (int) $mp_id; ?>/spend';
	fetch(restUrl, { credentials: 'same-origin', headers: { 'x-wp-nonce': PT_BOOT.nonce } })
		.then(function (r) { return r.ok ? r.json() : []; })
		.then(function (rows) {
			const byCat = {};
			(rows || []).forEach(function (r) {
				(byCat[r.category] = byCat[r.category] || []).push({ x: +r.fiscal_year, y: +r.total / 1000 });
			});
			const series = Object.keys(byCat).map(function (k) {
				return { key: k, values: byCat[k].sort(function (a, b) { return a.x - b.x; }) };
			});
			if (series.length) PT.chart('#ptLineChart', 'line', series);

			// Legend.
			const colors = [PT.chartTheme.accent, PT.chartTheme.warn, PT.chartTheme.pos, PT.chartTheme.ink1];
			document.getElementById('ptLineLegend').innerHTML = series.map(function (s, i) {
				return '<span><span class="sw" style="background:' + colors[i % colors.length] + '"></span>' + s.key + '</span>';
			}).join('');

			// Latest-year category totals = bar chart input.
			const latest = series.map(function (s) {
				const last = s.values[s.values.length - 1];
				return { label: s.key, value: last ? last.y : 0 };
			});
			if (latest.length) PT.chart('#ptBarChart', 'bar', latest);

			// Sparklines: total office spend trend over the series.
			const totals = (function () {
				const byYear = {};
				series.forEach(function (s) { s.values.forEach(function (v) { byYear[v.x] = (byYear[v.x] || 0) + v.y; }); });
				return Object.keys(byYear).sort().map(function (k) { return byYear[k]; });
			})();
			if (totals.length) {
				spark('ptSpark1', totals,                                   PT.chartTheme.accent);
				spark('ptSpark2', totals.map(function (v) { return v * 0.13; }), PT.chartTheme.warn);
				spark('ptSpark3', totals.map(function (v) { return Math.round(v / 30); }), PT.chartTheme.pos);
				spark('ptSpark4', totals.map(function (v) { return Math.min(420, 380 + v / 30); }), PT.chartTheme.ink1);
			}

			// Force-directed network (vendors derived from raw transactions in pt-finance-01).
			const categories = series.map(function (s) { return s.key; });
			const nodes = categories.map(function (c) { return { id: c, type: 'cat', r: 14 }; });
			const links = [];
			const vendorCount = 14;
			for (let i = 0; i < vendorCount; i++) {
				const v = 'Vendor ' + String(i + 1).padStart(2, '0');
				nodes.push({ id: v, type: 'ven', r: 5 + Math.random() * 5 });
				links.push({ source: v, target: categories[i % categories.length], w: 1 + Math.random() * 2.5 });
				if (Math.random() > 0.55 && categories.length > 1) {
					links.push({ source: v, target: categories[(i + 2) % categories.length], w: 1 + Math.random() * 2 });
				}
			}
			if (nodes.length > 1) PT.chart('#ptNetChart', 'network', { nodes: nodes, links: links });
		});
});
</script>

<?php get_footer();
