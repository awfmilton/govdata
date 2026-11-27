<?php
/**
 * /foreign-aid/ — Canada's international assistance as a whole: how much (in Canadian dollars and as a share of
 * national income), through whom (bilateral, UN, World Bank, other agencies), where (regions, income groups,
 * countries, by decade), how much is spent inside Canada (refugee costs, administration), Global Affairs Canada's
 * grant agreements, and the laws and bills about aid.
 *
 * Routed by \PublicTech\Core\Country_Reports; reads the 'aid' payload (grantconts.cty_report).
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

use PublicTech\Core\Country_Reports;
use function PublicTech\Core\pt_format_money_short;

$pt_a     = Country_Reports::instance()->aid() ?? [];
$pt_tot   = (array) ( $pt_a['totals'] ?? [] );
$pt_lastr = $pt_tot ? (array) end( $pt_tot ) : [];
$pt_ref   = (array) ( $pt_a['refugees'] ?? [] );
$pt_gac   = (array) ( $pt_a['gac'] ?? [] );
$pt_win   = (array) ( $pt_a['window'] ?? [ 0, 0 ] );
$pt_base  = (int) ( $pt_a['base_year'] ?? 0 );

$pt_cad = static function ( $m ): string {             // C$ millions
	if ( null === $m ) { return '—'; }
	$m = (float) $m;
	return abs( $m ) >= 1000 ? 'C$' . number_format( $m / 1000, 2 ) . 'B' : 'C$' . number_format( $m, 0 ) . 'M';
};
$pt_usd = static function ( $m ): string {
	if ( null === $m ) { return '—'; }
	$m = (float) $m;
	return abs( $m ) >= 1000 ? 'US$' . number_format( $m / 1000, 2 ) . 'B' : 'US$' . number_format( $m, abs( $m ) >= 10 ? 0 : 1 ) . 'M';
};
$pt_n = static fn( $v ): string => null === $v ? '—' : number_format( (float) $v );

/* Latest year with a grant-equivalent ODA/GNI figure (the OECD's official measure since 2018). */
$pt_ge = null;
foreach ( array_reverse( $pt_tot ) as $r ) {
	if ( null !== $r[4] ) { $pt_ge = $r; break; }
}
/* First fiscal year in which the department's disclosures are complete enough to count (100+ agreements). */
$pt_gac_from = 0;
foreach ( (array) ( $pt_gac['by_year'] ?? [] ) as $r ) {
	if ( (int) $r[1] >= 100 ) { $pt_gac_from = (int) $r[0]; break; }
}
$pt_ref_last = null;
foreach ( array_reverse( $pt_ref ) as $r ) {
	if ( null !== $r[1] && null !== $r[3] ) { $pt_ref_last = $r; break; }
}

add_action( 'wp_enqueue_scripts', static function () {
	wp_enqueue_style( 'publictech-country', PUBLICTECH_TERMINAL_URL . 'assets/css/country.css', [], PUBLICTECH_TERMINAL_VERSION );
	wp_enqueue_script( 'publictech-country', PUBLICTECH_TERMINAL_URL . 'assets/js/country-report.js', [ 'd3', 'publictech-charts' ], PUBLICTECH_TERMINAL_VERSION, true );
} );

get_header(); ?>

<main id="pt-main" tabindex="-1">
<section class="view active pt-country" data-pt-view="foreign-aid">

	<div class="hero corner" style="margin-bottom:18px">
		<div style="position:relative;z-index:1">
			<div class="mono uc" style="color:var(--accent);font-size:10px;letter-spacing:0.22em;margin-bottom:10px">◇ <?php esc_html_e( 'World · Foreign aid', 'publictech-terminal' ); ?></div>
			<h2 style="margin:0 0 6px;font-size:26px;font-weight:700;letter-spacing:0.02em"><?php esc_html_e( 'Canada’s foreign aid', 'publictech-terminal' ); ?></h2>
			<div style="color:var(--ink-1);font-size:13px;line-height:1.6;max-width:780px">
				<?php esc_html_e( 'How much official development assistance Canada gives, how that compares with the size of the economy, how much is spent inside Canada, which agencies and countries receive it, which organisations Global Affairs Canada signs agreements with, and the laws that govern it. Every country page has its own aid history.', 'publictech-terminal' ); ?>
			</div>
		</div>
	</div>

	<?php if ( ! $pt_tot ) : ?>
		<div class="panel corner"><div class="hd"><h3>◇ <?php esc_html_e( 'Report being compiled', 'publictech-terminal' ); ?></h3></div>
		<div class="bd pt-cty-note"><?php esc_html_e( 'The aid figures are rebuilt daily from the published sources. Please check back shortly.', 'publictech-terminal' ); ?></div></div>
	<?php else : ?>

	<div class="kpis" style="margin-bottom:14px">
		<div class="kpi corner">
			<div class="lab"><?php printf( esc_html__( 'Aid in %d', 'publictech-terminal' ), (int) $pt_lastr[0] ); ?></div>
			<div class="val"><?php echo esc_html( $pt_cad( $pt_lastr[1] ) ); ?></div>
			<div class="delta dim"><?php esc_html_e( 'Net ODA, current dollars', 'publictech-terminal' ); ?></div>
		</div>
		<div class="kpi">
			<div class="lab"><?php esc_html_e( 'Share of national income', 'publictech-terminal' ); ?></div>
			<div class="val"><?php echo esc_html( $pt_ge ? number_format( (float) $pt_ge[4], 2 ) . '%' : '—' ); ?></div>
			<div class="delta dim"><?php echo esc_html( $pt_ge ? sprintf( __( '%d, OECD grant-equivalent · UN target 0.7%%', 'publictech-terminal' ), (int) $pt_ge[0] ) : '' ); ?></div>
		</div>
		<div class="kpi">
			<div class="lab"><?php esc_html_e( 'Spent on refugees in Canada', 'publictech-terminal' ); ?></div>
			<div class="val"><?php echo esc_html( $pt_ref_last ? $pt_cad( $pt_ref_last[1] ) : '—' ); ?></div>
			<div class="delta dim"><?php echo esc_html( $pt_ref_last && $pt_ref_last[3] ? sprintf( __( '%1$s%% of all aid in %2$d', 'publictech-terminal' ), number_format( 100 * $pt_ref_last[1] / $pt_ref_last[3], 1 ), (int) $pt_ref_last[0] ) : '' ); ?></div>
		</div>
		<div class="kpi">
			<div class="lab"><?php esc_html_e( 'Global Affairs Canada agreements', 'publictech-terminal' ); ?></div>
			<div class="val"><?php echo esc_html( pt_format_money_short( (float) ( $pt_gac['value'] ?? 0 ) ) ); ?></div>
			<div class="delta dim"><?php printf( esc_html__( '%1$s grants and contributions since %2$d', 'publictech-terminal' ), esc_html( $pt_n( $pt_gac['n'] ?? 0 ) ), $pt_gac_from ); ?></div>
		</div>
	</div>

	<div class="pt-cty-grid">
		<div class="pt-cty-main">

			<div class="panel corner">
				<div class="hd"><h3>◇ <?php esc_html_e( 'How much, since 1960', 'publictech-terminal' ); ?></h3><span class="meta">OECD DAC1</span></div>
				<div class="bd">
					<div class="pt-cty-toggle mono" role="group" aria-label="<?php esc_attr_e( 'Measure', 'publictech-terminal' ); ?>">
						<button type="button" class="on" data-pt-aid="cad"><?php esc_html_e( 'Canadian dollars', 'publictech-terminal' ); ?></button>
						<button type="button" data-pt-aid="real"><?php printf( esc_html__( 'Inflation-adjusted (%d US$)', 'publictech-terminal' ), $pt_base ); ?></button>
						<button type="button" data-pt-aid="gni"><?php esc_html_e( '% of national income', 'publictech-terminal' ); ?></button>
					</div>
					<div class="chart-host lg" id="ptAidTotal" style="height:300px"></div>
					<p class="pt-cty-note"><?php esc_html_e( 'Net official development assistance (gifts plus loans, minus loan repayments received). The share of national income uses the same net figure for every year; since 2018 the OECD’s official measure counts loans at their grant value instead, shown as the second line. The dashed line is the 0.7% target Canada first endorsed at the UN in 1970.', 'publictech-terminal' ); ?></p>
				</div>
			</div>

			<div class="panel corner">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Directly, or through international organisations', 'publictech-terminal' ); ?></h3><span class="meta">CAD · DAC1</span></div>
				<div class="bd">
					<div class="chart-host" id="ptAidChannels" style="height:260px"></div>
					<p class="pt-cty-note"><?php esc_html_e( 'Bilateral aid is spent by Canada itself (including through Canadian and international NGOs, and costs in Canada). Multilateral aid is Canada’s core funding to the UN, the World Bank and other agencies, which decide where it goes.', 'publictech-terminal' ); ?></p>
					<?php $pt_mp = (array) ( $pt_a['multilateral_parts'] ?? [] ); if ( $pt_mp ) : ?>
					<table class="pt-cty-table compact" style="margin-top:10px">
						<thead><tr><th><?php esc_html_e( 'Multilateral channel', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Latest year', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Amount', 'publictech-terminal' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $pt_mp as $label => $series ) : $l = $series ? end( $series ) : null; if ( ! $l ) { continue; } ?>
							<tr><td><?php echo esc_html( (string) $label ); ?></td><td class="mono"><?php echo (int) $l[0]; ?></td><td class="mono"><?php echo esc_html( $pt_cad( $l[1] ) ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php endif; ?>
				</div>
			</div>

			<div class="panel corner" id="refugees">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Aid spent inside Canada: refugees', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'IN-DONOR REFUGEE COSTS', 'publictech-terminal' ); ?></span></div>
				<div class="bd">
					<p class="pt-cty-note" style="margin-top:0"><?php esc_html_e( 'OECD rules let a donor count the first year of support for asylum seekers and refugees in its own country as development aid. Canada has done so since 1993. The table sets that cost beside the number of refugees and protected persons admitted as permanent residents that year (IRCC, 2015 onward); the cost also covers asylum claimants who are not, or not yet, permanent residents, so the last column is a ratio, not a cost per person.', 'publictech-terminal' ); ?></p>
					<div class="chart-host" id="ptAidRefugees" style="height:220px"></div>
					<div class="pt-cty-scroll" style="max-height:300px;margin-top:10px">
					<table class="pt-cty-table compact">
						<thead><tr><th><?php esc_html_e( 'Year', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Refugee costs in Canada', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Share of all aid', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Refugees admitted (PR)', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Cost ÷ admitted', 'publictech-terminal' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( array_reverse( $pt_ref ) as $r ) : ?>
							<tr><td class="mono"><?php echo (int) $r[0]; ?></td><td class="mono"><?php echo esc_html( $pt_cad( $r[1] ) ); ?></td><td class="mono"><?php echo esc_html( $r[1] && $r[3] ? number_format( 100 * $r[1] / $r[3], 1 ) . '%' : '—' ); ?></td><td class="mono"><?php echo esc_html( $pt_n( $r[2] ) ); ?></td><td class="mono"><?php echo esc_html( $r[4] ? 'C$' . number_format( (float) $r[4] ) : '—' ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
					<?php $pt_bp = (array) ( $pt_a['bilateral_parts'] ?? [] ); if ( $pt_bp ) : ?>
					<h4 class="pt-cty-h4"><?php esc_html_e( 'Other parts of bilateral aid, latest year', 'publictech-terminal' ); ?></h4>
					<table class="pt-cty-table compact">
						<tbody>
						<?php foreach ( $pt_bp as $label => $series ) : $l = $series ? end( $series ) : null; if ( ! $l ) { continue; } ?>
							<tr><td><?php echo esc_html( (string) $label ); ?></td><td class="mono"><?php echo (int) $l[0]; ?></td><td class="mono"><?php echo esc_html( $pt_cad( $l[1] ) ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php endif; ?>
				</div>
			</div>

			<div class="panel corner">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Where it goes', 'publictech-terminal' ); ?></h3><span class="meta"><?php printf( esc_html__( 'GROSS ODA · %d US$ · OECD DAC2a', 'publictech-terminal' ), $pt_base ); ?></span></div>
				<div class="bd">
					<div class="chart-host" id="ptAidRegions" style="height:260px"></div>
					<p class="pt-cty-note"><?php esc_html_e( 'Aid that can be assigned to a region. Much of it cannot (global programmes, core funding to agencies), so the regions add up to less than the total.', 'publictech-terminal' ); ?></p>
					<h4 class="pt-cty-h4"><?php printf( esc_html__( 'Largest recipients, %1$d–%2$d', 'publictech-terminal' ), (int) $pt_win[0], (int) $pt_win[1] ); ?></h4>
					<div class="pt-cty-scroll" style="max-height:360px">
					<table class="pt-cty-table compact">
						<thead><tr><th><?php esc_html_e( 'Country', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Aid', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Immigrants to Canada, 10 yrs', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'MPs born there', 'publictech-terminal' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( (array) ( $pt_a['top_last10'] ?? [] ) as $r ) : ?>
							<tr><td><a href="<?php echo esc_url( Country_Reports::url( (string) $r[0] ) ); ?>"><?php echo esc_html( (string) $r[1] ); ?></a></td><td class="mono"><?php echo esc_html( $pt_usd( $r[2] ) ); ?></td><td class="mono"><?php echo esc_html( $pt_n( $r[3] ) ); ?></td><td class="mono"><?php echo (int) $r[4]; ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
					<h4 class="pt-cty-h4"><?php esc_html_e( 'Top five by decade', 'publictech-terminal' ); ?></h4>
					<div class="pt-cty-scroll" style="max-height:none">
					<table class="pt-cty-table compact">
						<thead><tr><th><?php esc_html_e( 'Decade', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Largest recipients (share of aid to countries)', 'publictech-terminal' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( (array) ( $pt_a['decades'] ?? [] ) as $d ) : ?>
							<tr><td class="mono"><?php echo esc_html( (string) $d['decade'] ); ?></td><td><?php
								echo implode( ' · ', array_map( static fn( $t ) => '<a href="' . esc_url( Country_Reports::url( (string) $t[0] ) ) . '">' . esc_html( (string) $t[1] ) . '</a> ' . esc_html( number_format( (float) $t[3], 1 ) . '%' ), (array) $d['top'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- each part escaped above
							?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
					<p class="pt-cty-note"><?php printf( esc_html__( 'Across countries, the ones that send Canada more immigrants also tend to receive more aid: rank correlation %s over the last ten years.', 'publictech-terminal' ), esc_html( isset( $pt_a['spearman_imm_aid'] ) ? number_format( (float) $pt_a['spearman_imm_aid'], 2 ) : '—' ) ); ?> <a href="<?php echo esc_url( home_url( '/countries/#analysis' ) ); ?>"><?php esc_html_e( 'Does an MP born in a country change that? →', 'publictech-terminal' ); ?></a></p>
				</div>
			</div>

			<div class="panel corner">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Global Affairs Canada’s grants and contributions', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'PROACTIVE DISCLOSURE · CAD', 'publictech-terminal' ); ?></span></div>
				<div class="bd">
					<p class="pt-cty-note" style="margin-top:0"><?php printf( esc_html__( 'Agreements the department has disclosed since fiscal %3$d, at their value when signed (a multi-year agreement counts once, in the year it starts). %1$s went to organisations based in Canada and %2$s to organisations based abroad — mostly UN agencies, the World Bank and international NGOs.', 'publictech-terminal' ), esc_html( pt_format_money_short( (float) ( $pt_gac['in_canada'][1] ?? 0 ) ) ), esc_html( pt_format_money_short( (float) ( $pt_gac['abroad'][1] ?? 0 ) ) ), $pt_gac_from ); ?></p>
					<div class="chart-host" id="ptAidGac" style="height:200px"></div>
					<h4 class="pt-cty-h4"><?php esc_html_e( 'Largest recipients', 'publictech-terminal' ); ?></h4>
					<div class="pt-cty-scroll" style="max-height:380px">
					<table class="pt-cty-table compact">
						<thead><tr><th><?php esc_html_e( 'Recipient', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Based in', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Agreements', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Value', 'publictech-terminal' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( (array) ( $pt_gac['recipients'] ?? [] ) as $r ) : ?>
							<tr><td><?php echo esc_html( (string) $r[0] ); ?></td><td class="mono"><?php echo esc_html( (string) $r[2] ); ?></td><td class="mono"><?php echo (int) $r[3]; ?></td><td class="mono"><?php echo esc_html( pt_format_money_short( (float) $r[4] ) ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
					<h4 class="pt-cty-h4"><?php esc_html_e( 'Largest programs', 'publictech-terminal' ); ?></h4>
					<table class="pt-cty-table compact">
						<tbody>
						<?php foreach ( (array) ( $pt_gac['programs'] ?? [] ) as $r ) : ?>
							<tr><td><?php echo esc_html( (string) $r[0] ); ?></td><td class="mono"><?php echo (int) $r[1]; ?></td><td class="mono"><?php echo esc_html( pt_format_money_short( (float) $r[2] ) ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>

			<div class="panel corner">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Laws and bills about aid', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'JUSTICE LAWS · LEGISINFO', 'publictech-terminal' ); ?></span></div>
				<div class="bd">
					<?php foreach ( (array) ( $pt_a['laws'] ?? [] ) as $l ) : ?>
						<div class="pt-cty-bill">
							<div><a href="<?php echo esc_url( (string) $l['url'] ); ?>" rel="noopener" target="_blank"><span class="mono"><?php echo esc_html( 'bill' === $l['kind'] ? ( $l['number'] ?? '' ) . ' (' . ( $l['session'] ?? '' ) . ')' : $l['kind'] ); ?></span> <?php echo esc_html( (string) $l['title'] ); ?></a></div>
							<?php if ( 'bill' === $l['kind'] ) : ?>
								<div class="mono pt-cty-sub"><?php echo esc_html( trim( (string) ( $l['status'] ?? '' ) . ( ! empty( $l['royal_assent'] ) ? ' · royal assent ' . $l['royal_assent'] : '' ) ) ); ?></div>
								<?php foreach ( (array) ( $l['divisions'] ?? [] ) as $d ) : ?>
									<div class="pt-cty-div mono"><?php echo esc_html( $d['date'] . ' · ' . $d['result'] . ' ' . $d['yeas'] . '–' . $d['nays'] . ' · ' . mb_substr( (string) $d['subject'], 0, 120 ) ); ?></div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
					<?php if ( ! empty( $pt_a['remissions'] ) ) : ?>
						<h4 class="pt-cty-h4"><?php esc_html_e( 'Debt forgiven to individual countries (orders in force)', 'publictech-terminal' ); ?></h4>
						<?php foreach ( (array) $pt_a['remissions'] as $r ) : ?>
							<div class="pt-cty-div"><a href="<?php echo esc_url( Country_Reports::url( (string) $r['slug'] ) ); ?>"><?php echo esc_html( (string) $r['country'] ); ?></a> — <a href="<?php echo esc_url( (string) $r['url'] ); ?>" rel="noopener" target="_blank"><?php echo esc_html( (string) $r['title'] ); ?></a></div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<aside class="pt-cty-side">
			<div class="panel corner">
				<div class="hd"><h3>◇ <?php esc_html_e( 'By income of the recipient', 'publictech-terminal' ); ?></h3><span class="meta"><?php echo esc_html( $pt_win[0] . '–' . $pt_win[1] ); ?></span></div>
				<div class="kvs">
					<?php foreach ( (array) ( $pt_a['income_last10'] ?? [] ) as $r ) : ?>
						<div class="kv"><span class="k"><?php echo esc_html( (string) $r[0] ); ?></span><span class="v"><?php echo esc_html( $pt_usd( $r[1] ) ); ?></span></div>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="panel corner">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Agencies receiving core funding', 'publictech-terminal' ); ?></h3><span class="meta"><?php echo esc_html( $pt_win[0] . '–' . $pt_win[1] ); ?></span></div>
				<div class="kvs">
					<?php foreach ( (array) ( $pt_a['agencies_last10'] ?? [] ) as $r ) : ?>
						<div class="kv"><span class="k" style="font-size:11px"><?php echo esc_html( (string) $r[0] ); ?></span><span class="v"><?php echo esc_html( $pt_usd( $r[1] ) ); ?></span></div>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="panel corner">
				<div class="hd"><h3>◇ <?php esc_html_e( 'GAC agreements by recipient type', 'publictech-terminal' ); ?></h3></div>
				<div class="kvs">
					<?php foreach ( (array) ( $pt_gac['types'] ?? [] ) as $r ) : ?>
						<div class="kv"><span class="k" style="font-size:11px"><?php echo esc_html( (string) $r[0] ); ?></span><span class="v"><?php echo esc_html( pt_format_money_short( (float) $r[2] ) ); ?></span></div>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="panel corner">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Sources', 'publictech-terminal' ); ?></h3></div>
				<div class="bd pt-cty-note" style="margin:0">
					<?php esc_html_e( 'OECD DAC1 (Canada’s totals, channels and national income, in Canadian dollars) and DAC2a (by recipient, constant US dollars); IRCC permanent residents by category; Government of Canada proactive disclosure of grants and contributions (Global Affairs Canada); Justice Laws; LEGISinfo and House of Commons divisions. Figures for the latest year are preliminary.', 'publictech-terminal' ); ?>
				</div>
			</div>
		</aside>
	</div>
	<?php endif; ?>
</section>
</main>

<script id="ptAidData" type="application/json"><?php
echo wp_json_encode( [
	'totals'   => $pt_tot,
	'regions'  => $pt_a['regions'] ?? new \stdClass(),
	'refugees' => $pt_ref,
	'gacYears' => array_values( array_filter( (array) ( $pt_gac['by_year'] ?? [] ), static fn( $r ) => (int) $r[0] >= $pt_gac_from && (int) $r[0] <= (int) gmdate( 'Y' ) ) ),
	'baseYear' => $pt_base,
], JSON_HEX_TAG | JSON_HEX_AMP );
?></script>

<?php get_footer(); ?>
