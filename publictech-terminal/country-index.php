<?php
/**
 * /countries/ — every country with immigration, aid, laws and Parliament figures, and the cross-country question:
 * when a member of Parliament born in a country takes a seat, does Canada take more immigrants from it or send it
 * more aid?
 *
 * Routed by \PublicTech\Core\Country_Reports; reads the 'index' and 'analysis' payloads (grantconts.cty_report).
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

use PublicTech\Core\Country_Reports;

$pt_cr  = Country_Reports::instance();
$pt_ix  = $pt_cr->index() ?? [];
$pt_an  = $pt_cr->analysis() ?? [];
$pt_tot = (array) ( $pt_ix['totals'] ?? [] );
$pt_rows = (array) ( $pt_ix['countries'] ?? [] );
$pt_ai  = (array) ( $pt_an['immigration'] ?? [] );
$pt_aa  = (array) ( $pt_an['aid'] ?? [] );
$pt_fe  = (array) ( $pt_an['fixed_effects'] ?? [] );

$pt_pct = static fn( $v ): string => null === $v ? '—' : ( ( $v > 0 ? '+' : '' ) . number_format( (float) $v, 1 ) . '%' );
$pt_p   = static fn( $v ): string => null === $v ? '—' : ( $v < 0.001 ? '< 0.001' : number_format( (float) $v, 3 ) );
$pt_last = (int) ( $pt_tot['immigration_last_full_year'] ?? 0 );
$pt_alast = (int) ( $pt_tot['aid_last_year'] ?? 0 );

/* Charts and styles for the country pages (enqueued before get_header so wp_enqueue_scripts sees them). */
add_action( 'wp_enqueue_scripts', static function () {
	wp_enqueue_style( 'publictech-country', PUBLICTECH_TERMINAL_URL . 'assets/css/country.css', [], PUBLICTECH_TERMINAL_VERSION );
	wp_enqueue_script( 'publictech-country', PUBLICTECH_TERMINAL_URL . 'assets/js/country-report.js', [ 'd3', 'publictech-charts' ], PUBLICTECH_TERMINAL_VERSION, true );
} );

/** Plain-language verdict for one outcome of the event study. */
$pt_verdict = static function ( array $s, string $what ) use ( $pt_pct ): string {
	if ( empty( $s['n_events'] ) || ! isset( $s['difference_pct'] ) ) {
		return __( 'Not enough data to compare.', 'publictech-terminal' );
	}
	$diff = (float) $s['difference_pct'];
	$p    = (float) $s['p_value'];
	$size = number_format( abs( $diff ), 1 ) . '%';
	$dir  = $diff > 0 ? __( 'higher', 'publictech-terminal' ) : __( 'lower', 'publictech-terminal' );
	if ( $p >= 0.05 ) {
		/* translators: 1: immigrants|aid, 2: size of change, 3: higher|lower, 4: p-value */
		return sprintf( __( 'No clear effect. In the five years after, the country’s share of Canada’s %1$s was %2$s %3$s than in comparable years with no such member — a difference this size turns up by chance often (p = %4$s).', 'publictech-terminal' ), $what, $size, $dir, number_format( $p, 2 ) );
	}
	/* translators: 1: immigrants|aid, 2: size of change, 3: higher|lower, 4: p-value */
	return sprintf( __( 'In the five years after, the country’s share of Canada’s %1$s was %2$s %3$s than in comparable years with no such member (p = %4$s). Read the pre-trend, the medians and the second check below before drawing a conclusion: this is one of several comparisons, and it is an association, not a cause.', 'publictech-terminal' ), $what, $size, $dir, number_format( $p, 3 ) );
};

get_header(); ?>

<main id="pt-main" tabindex="-1">
<section class="view active pt-country" data-pt-view="country-index">

	<div class="hero corner" style="margin-bottom:18px">
		<div style="position:relative;z-index:1">
			<div class="mono uc" style="color:var(--accent);font-size:10px;letter-spacing:0.22em;margin-bottom:10px">◇ <?php esc_html_e( 'Countries · Immigration · Aid · Laws · Parliament', 'publictech-terminal' ); ?></div>
			<h2 style="margin:0 0 6px;font-size:26px;font-weight:700;letter-spacing:0.02em"><?php esc_html_e( 'Canada and the world', 'publictech-terminal' ); ?></h2>
			<div style="color:var(--ink-1);font-size:13px;line-height:1.6;max-width:760px">
				<?php esc_html_e( 'For every country: the immigrants Canada has admitted from it since 1964, the development aid Canada has sent it since 1960, the federal grants paid to recipients there, the laws and bills that name it, and the members of Parliament and senators born there. Below the table: when someone born in a country takes a seat in the House of Commons, does Canada go on to take more immigrants from that country, or send it more aid?', 'publictech-terminal' ); ?>
			</div>
		</div>
	</div>

	<?php if ( ! $pt_rows ) : ?>
		<div class="panel corner"><div class="hd"><h3>◇ <?php esc_html_e( 'Report being compiled', 'publictech-terminal' ); ?></h3></div>
		<div class="bd pt-cty-note"><?php esc_html_e( 'The country figures are rebuilt daily from the published sources. Please check back shortly.', 'publictech-terminal' ); ?></div></div>
	<?php else : ?>

	<div class="kpis" style="margin-bottom:14px">
		<div class="kpi corner"><div class="lab"><?php esc_html_e( 'Countries', 'publictech-terminal' ); ?></div><div class="val"><?php echo esc_html( number_format( (int) ( $pt_tot['countries'] ?? 0 ) ) ); ?></div><div class="delta dim"><?php esc_html_e( 'incl. historical states', 'publictech-terminal' ); ?></div></div>
		<div class="kpi"><div class="lab"><?php esc_html_e( 'Parliamentarians born abroad', 'publictech-terminal' ); ?></div><div class="val"><?php echo esc_html( number_format( (int) ( $pt_tot['parliamentarians_born_abroad'] ?? 0 ) ) ); ?></div><div class="delta dim"><?php esc_html_e( 'MPs + senators since 1867', 'publictech-terminal' ); ?></div></div>
		<div class="kpi"><div class="lab"><?php esc_html_e( 'Laws naming a country', 'publictech-terminal' ); ?></div><div class="val"><?php echo esc_html( number_format( (int) ( $pt_tot['laws'] ?? 0 ) ) ); ?></div><div class="delta dim"><?php echo esc_html( sprintf( __( '+ %d House bills since 1994', 'publictech-terminal' ), (int) ( $pt_tot['bills'] ?? 0 ) ) ); ?></div></div>
		<div class="kpi"><div class="lab"><?php esc_html_e( 'Latest complete year', 'publictech-terminal' ); ?></div><div class="val"><?php echo (int) $pt_last; ?></div><div class="delta dim"><?php echo esc_html( sprintf( __( 'aid data to %d', 'publictech-terminal' ), $pt_alast ) ); ?></div></div>
	</div>

	<div class="panel corner" style="margin-bottom:14px">
		<div class="hd">
			<h3>◇ <?php esc_html_e( 'All countries', 'publictech-terminal' ); ?></h3>
			<span class="meta"><?php printf( esc_html__( 'IMMIGRANTS %1$d–%2$d · AID %3$d–%4$d (GROSS ODA, %5$d US$)', 'publictech-terminal' ), $pt_last - 9, $pt_last, $pt_alast - 9, $pt_alast, (int) ( $pt_tot['aid_base_year'] ?? 0 ) ); ?></span>
		</div>
		<div class="bd">
			<input type="search" id="ptCtyFilter" class="pt-cty-filter" placeholder="<?php esc_attr_e( 'Filter countries…', 'publictech-terminal' ); ?>" aria-label="<?php esc_attr_e( 'Filter countries', 'publictech-terminal' ); ?>">
			<div class="pt-cty-scroll" style="max-height:560px">
			<table class="pt-cty-table sortable" id="ptCtyTable">
				<thead><tr>
					<th data-sort="text"><?php esc_html_e( 'Country', 'publictech-terminal' ); ?></th>
					<th data-sort="num"><?php esc_html_e( 'Immigrants (10 yrs)', 'publictech-terminal' ); ?></th>
					<th data-sort="num"><?php esc_html_e( 'Since 1964', 'publictech-terminal' ); ?></th>
					<th data-sort="num"><?php esc_html_e( 'Aid US$M (10 yrs)', 'publictech-terminal' ); ?></th>
					<th data-sort="num"><?php esc_html_e( 'MPs', 'publictech-terminal' ); ?></th>
					<th data-sort="num"><?php esc_html_e( 'Senators', 'publictech-terminal' ); ?></th>
					<th data-sort="num"><?php esc_html_e( 'Laws', 'publictech-terminal' ); ?></th>
					<th data-sort="num"><?php esc_html_e( 'Bills', 'publictech-terminal' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $pt_rows as $r ) : ?>
					<tr data-name="<?php echo esc_attr( strtolower( (string) $r['name'] ) ); ?>">
						<td data-v="<?php echo esc_attr( (string) $r['name'] ); ?>"><a href="<?php echo esc_url( Country_Reports::url( (string) $r['slug'] ) ); ?>"><?php echo esc_html( (string) $r['name'] ); ?></a><?php echo ! empty( $r['historical'] ) ? ' <span class="mono pt-cty-sub">' . esc_html__( 'historical', 'publictech-terminal' ) . '</span>' : ''; ?></td>
						<td class="mono" data-v="<?php echo (int) $r['imm_last10']; ?>"><?php echo esc_html( number_format( (int) $r['imm_last10'] ) ); ?></td>
						<td class="mono" data-v="<?php echo (int) $r['imm_since_1964']; ?>"><?php echo esc_html( number_format( (int) $r['imm_since_1964'] ) ); ?></td>
						<td class="mono" data-v="<?php echo esc_attr( (string) (float) $r['aid_last10'] ); ?>"><?php echo esc_html( $r['aid_last10'] ? number_format( (float) $r['aid_last10'], 1 ) : '—' ); ?></td>
						<td class="mono" data-v="<?php echo (int) $r['mps']; ?>"><?php echo (int) $r['mps']; ?></td>
						<td class="mono" data-v="<?php echo (int) $r['senators']; ?>"><?php echo (int) $r['senators']; ?></td>
						<td class="mono" data-v="<?php echo (int) $r['laws']; ?>"><?php echo (int) $r['laws']; ?></td>
						<td class="mono" data-v="<?php echo (int) $r['bills']; ?>"><?php echo (int) $r['bills']; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>
	</div>

	<?php $pt_cm = (array) ( $pt_ix['current_mps'] ?? [] ); if ( ! empty( $pt_cm['members'] ) ) : ?>
	<div class="panel corner" id="current-mps" style="margin-bottom:14px">
		<div class="hd">
			<h3>◇ <?php esc_html_e( 'Today’s House of Commons: where members were born', 'publictech-terminal' ); ?></h3>
			<span class="meta"><?php esc_html_e( 'LIBRARY OF PARLIAMENT · WIKIDATA', 'publictech-terminal' ); ?></span>
		</div>
		<div class="bd">
			<div class="kvs" style="max-width:560px">
				<div class="kv"><span class="k"><?php esc_html_e( 'Members voting this session', 'publictech-terminal' ); ?></span><span class="v"><?php echo (int) $pt_cm['members']; ?></span></div>
				<div class="kv"><span class="k"><?php esc_html_e( 'Born in Canada', 'publictech-terminal' ); ?></span><span class="v"><?php echo (int) $pt_cm['born_in_canada']; ?></span></div>
				<div class="kv"><span class="k"><?php esc_html_e( 'Born outside Canada', 'publictech-terminal' ); ?></span><span class="v"><?php echo (int) $pt_cm['born_abroad']; ?></span></div>
				<div class="kv"><span class="k"><?php esc_html_e( 'Asked the Library of Parliament not to publish their birthplace', 'publictech-terminal' ); ?></span><span class="v"><?php echo (int) $pt_cm['not_disclosed']; ?></span></div>
				<div class="kv"><span class="k"><?php esc_html_e( 'Not recorded anywhere we can find', 'publictech-terminal' ); ?></span><span class="v"><?php echo (int) $pt_cm['unknown']; ?></span></div>
			</div>
			<p class="pt-cty-note"><?php esc_html_e( 'Birthplace from the Library of Parliament’s biographies (Parlinfo) where they record one, otherwise Wikidata, whose entries cite Parlinfo. No public source records whether a member immigrated or became a citizen by naturalization; birthplace is the closest public fact.', 'publictech-terminal' ); ?></p>
			<?php if ( ! empty( $pt_cm['born_abroad_list'] ) ) : ?>
			<div class="pt-cty-scroll" style="max-height:360px;margin-top:10px">
			<table class="pt-cty-table compact sortable">
				<thead><tr><th data-sort="text"><?php esc_html_e( 'Member', 'publictech-terminal' ); ?></th><th data-sort="text"><?php esc_html_e( 'Caucus', 'publictech-terminal' ); ?></th><th data-sort="text"><?php esc_html_e( 'Riding', 'publictech-terminal' ); ?></th><th data-sort="text"><?php esc_html_e( 'Born in', 'publictech-terminal' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( (array) $pt_cm['born_abroad_list'] as $r ) : ?>
					<tr>
						<td data-v="<?php echo esc_attr( (string) $r[0] ); ?>"><?php echo esc_html( (string) $r[0] ); ?></td>
						<td data-v="<?php echo esc_attr( (string) $r[1] ); ?>"><?php echo esc_html( (string) $r[1] ); ?></td>
						<td data-v="<?php echo esc_attr( (string) $r[2] ); ?>"><?php echo esc_html( (string) $r[2] ); ?></td>
						<td data-v="<?php echo esc_attr( (string) $r[3] ); ?>"><?php if ( ! empty( $r[4] ) ) : ?><a href="<?php echo esc_url( Country_Reports::url( (string) $r[4] ) ); ?>"><?php echo esc_html( (string) $r[3] ); ?></a><?php else : echo esc_html( (string) $r[3] ); endif; ?><?php echo ! empty( $r[5] ) ? ' <span class="pt-cty-sub">' . esc_html( (string) $r[5] ) . '</span>' : ''; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>

	<div class="panel corner" id="analysis" style="margin-bottom:14px">
		<div class="hd">
			<h3>◇ <?php esc_html_e( 'When an MP born in a country takes a seat, does Canada take more of its immigrants or send it more aid?', 'publictech-terminal' ); ?></h3>
			<span class="meta"><?php esc_html_e( 'EVENT STUDY · ALL COUNTRIES', 'publictech-terminal' ); ?></span>
		</div>
		<div class="bd">
			<div class="pt-cty-two">
				<div class="pt-cty-finding">
					<div class="mono uc pt-cty-sub"><?php esc_html_e( 'Immigration', 'publictech-terminal' ); ?></div>
					<p><?php echo esc_html( $pt_verdict( $pt_ai, __( 'immigrants', 'publictech-terminal' ) ) ); ?></p>
					<div class="kvs">
						<div class="kv"><span class="k"><?php esc_html_e( 'Events (first member from a country)', 'publictech-terminal' ); ?></span><span class="v"><?php echo (int) ( $pt_ai['n_events'] ?? 0 ); ?></span></div>
						<div class="kv"><span class="k"><?php esc_html_e( 'Change after, mean / median', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( $pt_pct( $pt_ai['event_mean_pct'] ?? null ) . ' / ' . $pt_pct( $pt_ai['event_median_pct'] ?? null ) ); ?></span></div>
						<div class="kv"><span class="k"><?php esc_html_e( 'Comparison years, mean / median', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( $pt_pct( $pt_ai['placebo_mean_pct'] ?? null ) . ' / ' . $pt_pct( $pt_ai['placebo_median_pct'] ?? null ) ); ?></span></div>
						<div class="kv"><span class="k"><?php esc_html_e( 'Share that rose', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( ( $pt_ai['share_up_pct'] ?? '—' ) . '% vs ' . ( $pt_ai['placebo_share_up_pct'] ?? '—' ) . '%' ); ?></span></div>
						<div class="kv"><span class="k"><?php esc_html_e( 'Trend in the 5 years before', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( $pt_pct( $pt_ai['event_pretrend_pct'] ?? null ) . ' vs ' . $pt_pct( $pt_ai['placebo_pretrend_pct'] ?? null ) ); ?></span></div>
						<div class="kv"><span class="k"><?php esc_html_e( 'Permutation p-value', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( $pt_p( $pt_ai['p_value'] ?? null ) ); ?></span></div>
					</div>
				</div>
				<div class="pt-cty-finding">
					<div class="mono uc pt-cty-sub"><?php esc_html_e( 'Aid', 'publictech-terminal' ); ?></div>
					<p><?php echo esc_html( $pt_verdict( $pt_aa, __( 'aid', 'publictech-terminal' ) ) ); ?></p>
					<div class="kvs">
						<div class="kv"><span class="k"><?php esc_html_e( 'Events (aid-receiving countries)', 'publictech-terminal' ); ?></span><span class="v"><?php echo (int) ( $pt_aa['n_events'] ?? 0 ); ?></span></div>
						<div class="kv"><span class="k"><?php esc_html_e( 'Change after, mean / median', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( $pt_pct( $pt_aa['event_mean_pct'] ?? null ) . ' / ' . $pt_pct( $pt_aa['event_median_pct'] ?? null ) ); ?></span></div>
						<div class="kv"><span class="k"><?php esc_html_e( 'Comparison years, mean / median', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( $pt_pct( $pt_aa['placebo_mean_pct'] ?? null ) . ' / ' . $pt_pct( $pt_aa['placebo_median_pct'] ?? null ) ); ?></span></div>
						<div class="kv"><span class="k"><?php esc_html_e( 'Share that rose', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( ( $pt_aa['share_up_pct'] ?? '—' ) . '% vs ' . ( $pt_aa['placebo_share_up_pct'] ?? '—' ) . '%' ); ?></span></div>
						<div class="kv"><span class="k"><?php esc_html_e( 'Trend in the 5 years before', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( $pt_pct( $pt_aa['event_pretrend_pct'] ?? null ) . ' vs ' . $pt_pct( $pt_aa['placebo_pretrend_pct'] ?? null ) ); ?></span></div>
						<div class="kv"><span class="k"><?php esc_html_e( 'Permutation p-value', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( $pt_p( $pt_aa['p_value'] ?? null ) ); ?></span></div>
					</div>
				</div>
			</div>

			<h4 class="pt-cty-h4"><?php esc_html_e( 'Second check: every year, not just the first', 'publictech-terminal' ); ?></h4>
			<p class="pt-cty-note"><?php esc_html_e( 'A regression over every country and year: the country’s share in a year when at least one MP born there sits, against its own average and that year’s average (country and year fixed effects, errors clustered by country). The "3 years ahead" line uses members who will sit three years later — if future members "predict" today’s share as well, the pattern comes from growing communities, not from the members.', 'publictech-terminal' ); ?></p>
			<table class="pt-cty-table compact" style="max-width:720px">
				<thead><tr><th></th><th><?php esc_html_e( 'Share when a member sits', 'publictech-terminal' ); ?></th><th>z</th><th><?php esc_html_e( 'Country-years', 'publictech-terminal' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( [ 'immigration' => __( 'Immigration', 'publictech-terminal' ), 'immigration_lead3' => __( 'Immigration, member 3 years ahead', 'publictech-terminal' ), 'aid' => __( 'Aid', 'publictech-terminal' ), 'aid_lead3' => __( 'Aid, member 3 years ahead', 'publictech-terminal' ) ] as $k => $label ) :
					$f = is_array( $pt_fe[ $k ] ?? null ) ? $pt_fe[ $k ] : null; ?>
					<tr><td><?php echo esc_html( $label ); ?></td><td class="mono"><?php echo esc_html( $f ? $pt_pct( $f['pct'] ) : '—' ); ?></td><td class="mono"><?php echo esc_html( $f ? (string) $f['z'] : '—' ); ?></td><td class="mono"><?php echo esc_html( $f ? number_format( (int) $f['n_obs'] ) : '—' ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="pt-cty-note"><?php esc_html_e( '|z| below 2 means the estimate cannot be told apart from zero.', 'publictech-terminal' ); ?></p>

			<h4 class="pt-cty-h4"><?php esc_html_e( 'Immigrants and aid, by country', 'publictech-terminal' ); ?></h4>
			<div class="chart-host lg" id="ptCtyScatter" style="height:420px"></div>
			<p class="pt-cty-note"><?php printf( esc_html__( 'Each dot is a country: immigrants to Canada %1$d–%2$d against Canada’s gross aid %3$d–%4$d, both on log scales; larger, orange dots have MPs born there. Rank correlation across the %5$d countries with both: %6$s (1 = the same order, 0 = no relation).', 'publictech-terminal' ), $pt_last - 9, $pt_last, $pt_alast - 9, $pt_alast, (int) ( $pt_an['scatter_n'] ?? 0 ), esc_html( isset( $pt_an['scatter_spearman'] ) ? number_format( (float) $pt_an['scatter_spearman'], 2 ) : '—' ) ); ?></p>

			<h4 class="pt-cty-h4"><?php esc_html_e( 'Every event', 'publictech-terminal' ); ?></h4>
			<div class="pt-cty-scroll" style="max-height:420px">
			<table class="pt-cty-table sortable">
				<thead><tr><th data-sort="text"><?php esc_html_e( 'Country', 'publictech-terminal' ); ?></th><th data-sort="num"><?php esc_html_e( 'Year', 'publictech-terminal' ); ?></th><th data-sort="text"><?php esc_html_e( 'Members', 'publictech-terminal' ); ?></th><th data-sort="num"><?php esc_html_e( 'Immigration change', 'publictech-terminal' ); ?></th><th data-sort="num"><?php esc_html_e( 'Pre-trend', 'publictech-terminal' ); ?></th><th data-sort="num"><?php esc_html_e( 'Pctile', 'publictech-terminal' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( (array) ( $pt_ai['events'] ?? [] ) as $e ) : ?>
					<tr>
						<td data-v="<?php echo esc_attr( (string) $e['country'] ); ?>"><a href="<?php echo esc_url( Country_Reports::url( (string) $e['slug'] ) . '#deep-dive' ); ?>"><?php echo esc_html( (string) $e['country'] ); ?></a></td>
						<td class="mono" data-v="<?php echo (int) $e['year']; ?>"><?php echo (int) $e['year']; ?></td>
						<td data-v="<?php echo esc_attr( implode( ', ', (array) $e['members'] ) ); ?>"><?php echo esc_html( implode( ', ', (array) $e['members'] ) ); ?></td>
						<td class="mono" data-v="<?php echo esc_attr( (string) (float) $e['change_pct'] ); ?>"><?php echo esc_html( $pt_pct( $e['change_pct'] ) ); ?></td>
						<td class="mono" data-v="<?php echo esc_attr( (string) (float) ( $e['pretrend_pct'] ?? 0 ) ); ?>"><?php echo esc_html( $pt_pct( $e['pretrend_pct'] ?? null ) ); ?></td>
						<td class="mono" data-v="<?php echo (int) $e['percentile']; ?>"><?php echo (int) $e['percentile']; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>

			<h4 class="pt-cty-h4"><?php esc_html_e( 'How this is measured, and what it cannot show', 'publictech-terminal' ); ?></h4>
			<ul class="pt-cty-note pt-cty-list">
				<li><?php printf( esc_html__( 'An event is a year in which the House goes from no member born in a country to at least one. The change compares the country’s share of all immigrants (or of Canada’s aid to individual countries) in the %1$d years after with the %1$d years before, needing at least %2$d years of data in each. Comparison years are every other country and year with no such member within %1$d years; the p-value is the chance that %3$s random draws of the same number of comparison years differ as much.', 'publictech-terminal' ), (int) ( $pt_an['window_years'] ?? 5 ), (int) ( $pt_an['min_window_years'] ?? 3 ), esc_html( number_format( (int) ( $pt_an['permutations'] ?? 5000 ) ) ) ); ?></li>
				<li><?php esc_html_e( 'This is association, not cause. A growing community sends more immigrants and, later, elects members; a crisis (Syria 2015) or a new state (Croatia 1993) moves the figures regardless of who sits in the House.', 'publictech-terminal' ); ?></li>
				<li><?php esc_html_e( 'Members are counted by place of birth (the place’s country today), not ancestry: an MP whose parents came from a country is not counted for it. About one in six parliamentarians has no recorded birthplace.', 'publictech-terminal' ); ?></li>
				<li><?php esc_html_e( 'Per-country immigration figures start in 1964, so the first usable event year is 1969. The 1964–1979 reports list only the larger source countries each year, by country of last residence, citizenship or birth; from 1980 the basis is citizenship.', 'publictech-terminal' ); ?></li>
				<li><?php esc_html_e( 'Aid is bilateral official development assistance only; high-income countries receive none, so the aid comparison covers fewer countries.', 'publictech-terminal' ); ?></li>
			</ul>
		</div>
	</div>
	<?php endif; ?>
</section>
</main>

<script id="ptCountryIndexData" type="application/json"><?php echo wp_json_encode( [ 'scatter' => $pt_an['scatter'] ?? [] ], JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>

<?php get_footer(); ?>
