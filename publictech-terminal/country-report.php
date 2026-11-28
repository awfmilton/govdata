<?php
/**
 * One country and Canada: immigration, aid, federal grants, laws and bills that name it, and the members of
 * Parliament born there -- with the before/after comparison for the years those members took their seats.
 *
 * Routed by \PublicTech\Core\Country_Reports (/country/{slug}/). Every number comes from the precomputed payload
 * (grantconts.cty_report, built by database/immigration/run.py report); this template does no arithmetic beyond
 * formatting.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

use PublicTech\Core\Country_Reports;
use function PublicTech\Core\pt_format_money_short;

$pt_cr = Country_Reports::instance();
$pt_p  = $pt_cr->country( Country_Reports::slug() ) ?? [];
$pt_c  = (array) ( $pt_p['country'] ?? [] );
$pt_im = (array) ( $pt_p['immigration'] ?? [] );
$pt_ad = (array) ( $pt_p['aid'] ?? [] );
$pt_gr = is_array( $pt_p['grants'] ?? null ) ? $pt_p['grants'] : null;
$pt_pp = (array) ( $pt_p['parliamentarians'] ?? [] );
$pt_ev = (array) ( $pt_p['events'] ?? [] );
$pt_lw = (array) ( $pt_p['laws'] ?? [] );
$pt_bl = (array) ( $pt_p['bills'] ?? [] );
$pt_rp = (array) ( $pt_p['representation'] ?? [] );
$pt_vb = (array) ( $pt_p['immigration_vote_baseline'] ?? [] );
$pt_name = (string) ( $pt_c['name'] ?? '' );

$pt_n    = static fn( $v ): string => null === $v ? '—' : number_format( (float) $v );
$pt_usd  = static function ( $m ): string {           // US$ millions
	if ( null === $m ) { return '—'; }
	$m = (float) $m;
	if ( 0.0 === $m ) { return __( 'None', 'publictech-terminal' ); }
	return abs( $m ) >= 1000 ? 'US$' . number_format( $m / 1000, 2 ) . 'B' : 'US$' . number_format( $m, abs( $m ) >= 10 ? 0 : 1 ) . 'M';
};
$pt_pct  = static fn( $v ): string => null === $v ? '—' : ( ( $v > 0 ? '+' : '' ) . number_format( (float) $v, 1 ) . '%' );
$pt_rank = static fn( $r ): string => $r ? '#' . (int) $r : '—';
$pt_basis_label = [
	'citizenship'              => __( 'citizenship', 'publictech-terminal' ),
	'birth'                    => __( 'country of birth', 'publictech-terminal' ),
	'last_permanent_residence' => __( 'country of last permanent residence', 'publictech-terminal' ),
];
$pt_last_full = (int) ( $pt_im['last_full_year'] ?? 0 );
$pt_aid_last  = (int) ( $pt_ad['last_year'] ?? 0 );
$pt_base_year = (int) ( $pt_ad['base_year'] ?? 0 );
$pt_mps  = array_values( array_filter( $pt_pp, static fn( $p ) => ! empty( $p['mp'] ) ) );
$pt_sens = array_values( array_filter( $pt_pp, static fn( $p ) => ! empty( $p['senator'] ) ) );
$pt_sanction_laws = array_values( array_filter( $pt_lw, static fn( $l ) => 'sanctions' === ( $l['topic'] ?? '' ) ) );

/* Charts and styles for the country pages (enqueued before get_header so wp_enqueue_scripts sees them). */
add_action( 'wp_enqueue_scripts', static function () {
	wp_enqueue_style( 'publictech-country', PUBLICTECH_TERMINAL_URL . 'assets/css/country.css', [], PUBLICTECH_TERMINAL_VERSION );
	wp_enqueue_script( 'publictech-country', PUBLICTECH_TERMINAL_URL . 'assets/js/country-report.js', [ 'd3', 'publictech-charts' ], PUBLICTECH_TERMINAL_VERSION, true );
} );

get_header(); ?>

<main id="pt-main" tabindex="-1">
<section class="view active pt-country" data-pt-view="country-report">

	<div class="hero corner" style="margin-bottom:18px">
		<div style="position:relative;z-index:1">
			<div class="mono uc" style="color:var(--accent);font-size:10px;letter-spacing:0.22em;margin-bottom:10px">
				<a href="<?php echo esc_url( home_url( '/countries/' ) ); ?>" style="color:inherit">◇ <?php esc_html_e( 'Countries', 'publictech-terminal' ); ?></a> · <?php esc_html_e( 'Immigration · Aid · Laws · Parliament', 'publictech-terminal' ); ?>
			</div>
			<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px">
				<div>
					<h2 style="margin:0 0 6px;font-size:26px;font-weight:700;letter-spacing:0.02em"><?php echo esc_html( $pt_name ); ?></h2>
					<div style="color:var(--ink-1);font-size:13px;line-height:1.6;max-width:680px">
						<?php
						printf(
							/* translators: %s: country name */
							esc_html__( 'How many immigrants Canada has taken from %s since 1964, how much aid Canada has sent it since 1960, which federal laws and bills name it, and which members of Parliament were born there — and whether immigration or aid changed after they took their seats.', 'publictech-terminal' ),
							esc_html( $pt_name )
						);
						?>
						<?php if ( ! empty( $pt_c['notes'] ) ) : ?>
							<div class="mono" style="font-size:11px;color:var(--warn);margin-top:6px"><?php echo esc_html( (string) $pt_c['notes'] ); ?></div>
						<?php endif; ?>
					</div>
				</div>
				<div style="display:flex;flex-direction:column;gap:6px;text-align:right;flex-shrink:0">
					<?php if ( ! empty( $pt_c['iso2'] ) ) : ?>
						<div class="mono" style="font-size:10px;color:var(--ink-3)">ISO · <?php echo esc_html( $pt_c['iso2'] . ' / ' . $pt_c['iso3'] ); ?></div>
					<?php endif; ?>
					<div class="mono" style="font-size:10px;color:var(--ink-3)"><?php esc_html_e( 'BUILT', 'publictech-terminal' ); ?> · <?php echo esc_html( substr( (string) ( $pt_p['generated_at'] ?? '' ), 0, 10 ) ); ?></div>
				</div>
			</div>
		</div>
	</div>

	<div class="kpis" style="margin-bottom:14px">
		<div class="kpi corner">
			<div class="lab"><?php printf( esc_html__( 'Permanent residents %1$d–%2$d', 'publictech-terminal' ), $pt_last_full - 9, $pt_last_full ); ?></div>
			<div class="val"><?php echo esc_html( $pt_n( $pt_im['last10'] ?? null ) ); ?></div>
			<div class="delta dim"><?php printf( esc_html__( 'Rank %s among source countries', 'publictech-terminal' ), esc_html( $pt_rank( $pt_im['rank_last10'] ?? null ) ) ); ?></div>
		</div>
		<div class="kpi">
			<div class="lab"><?php printf( esc_html__( 'Canadian aid %1$d–%2$d', 'publictech-terminal' ), $pt_aid_last - 9, $pt_aid_last ); ?></div>
			<?php if ( (float) ( $pt_ad['last10_gross'] ?? 0 ) > 0 ) : ?>
				<div class="val"><?php echo esc_html( $pt_usd( $pt_ad['last10_gross'] ) ); ?></div>
				<div class="delta dim"><?php printf( esc_html__( 'Gross ODA, %1$d US$ · rank %2$s', 'publictech-terminal' ), $pt_base_year, esc_html( $pt_rank( $pt_ad['rank_last10'] ?? null ) ) ); ?></div>
			<?php else : ?>
				<div class="val"><?php esc_html_e( 'None', 'publictech-terminal' ); ?></div>
				<div class="delta dim"><?php echo esc_html( (float) ( $pt_ad['total_gross'] ?? 0 ) > 0 ? sprintf( __( '%s in earlier years', 'publictech-terminal' ), $pt_usd( $pt_ad['total_gross'] ) ) : __( 'not an aid recipient', 'publictech-terminal' ) ); ?></div>
			<?php endif; ?>
		</div>
		<div class="kpi">
			<div class="lab"><?php esc_html_e( 'Parliamentarians born here', 'publictech-terminal' ); ?></div>
			<div class="val"><?php echo esc_html( count( $pt_mps ) . ' / ' . count( $pt_sens ) ); ?></div>
			<div class="delta dim"><?php esc_html_e( 'MPs / senators since 1867', 'publictech-terminal' ); ?></div>
		</div>
		<div class="kpi">
			<div class="lab"><?php esc_html_e( 'Federal laws & bills naming it', 'publictech-terminal' ); ?></div>
			<div class="val"><?php echo esc_html( count( $pt_lw ) . ' / ' . count( $pt_bl ) ); ?></div>
			<div class="delta dim"><?php printf( esc_html__( '%d sanctions instruments', 'publictech-terminal' ), count( $pt_sanction_laws ) ); ?></div>
		</div>
	</div>

	<div class="pt-cty-grid">
		<div class="pt-cty-main">

			<div class="panel corner">
				<div class="hd">
					<h3>◇ <?php esc_html_e( 'Immigrants to Canada from this country', 'publictech-terminal' ); ?></h3>
					<span class="meta"><?php esc_html_e( 'PERMANENT RESIDENTS PER YEAR', 'publictech-terminal' ); ?></span>
				</div>
				<div class="bd">
					<div class="pt-cty-toggle mono" role="group" aria-label="<?php esc_attr_e( 'Measure', 'publictech-terminal' ); ?>">
						<button type="button" class="on" data-pt-imm="count"><?php esc_html_e( 'Count', 'publictech-terminal' ); ?></button>
						<button type="button" data-pt-imm="share"><?php esc_html_e( 'Share of all immigrants', 'publictech-terminal' ); ?></button>
					</div>
					<div class="chart-host lg" id="ptCtyImmChart" style="height:300px"></div>
					<div class="legend mono" style="font-size:10px;margin-top:6px;color:var(--ink-2)">
						<span class="pt-cty-key mk-mp"></span> <?php esc_html_e( 'the House goes from no member born here to at least one', 'publictech-terminal' ); ?>
					</div>
					<p class="pt-cty-note">
						<?php esc_html_e( '1964–1979: the printed annual reports (MP22-1), by the basis printed for most countries that year; 1980 onward: IRCC, by country of citizenship. Years before 1964 have no per-country figures; a year missing from the line was not published for this country.', 'publictech-terminal' ); ?>
						<?php esc_html_e( 'These are permanent residents only: students, temporary workers and asylum claimants are counted below, and only appear here if and when they become permanent residents.', 'publictech-terminal' ); ?>
					</p>
				</div>
			</div>

			<?php $pt_tr = is_array( $pt_p['temporary'] ?? null ) ? $pt_p['temporary'] : null; if ( $pt_tr ) :
				$pt_y5 = implode( '–', (array) $pt_tr['years5'] ); ?>
			<div class="panel corner" id="temporary">
				<div class="hd">
					<h3>◇ <?php esc_html_e( 'Students, temporary workers and refugee claimants', 'publictech-terminal' ); ?></h3>
					<span class="meta"><?php esc_html_e( 'IRCC · IMMIGRATION AND REFUGEE BOARD', 'publictech-terminal' ); ?></span>
				</div>
				<div class="kvs">
					<div class="kv"><span class="k"><?php echo esc_html( sprintf( __( 'New permanent residents, %s', 'publictech-terminal' ), $pt_y5 ) ); ?></span><span class="v"><?php echo esc_html( $pt_n( $pt_tr['pr5'] ) ); ?></span></div>
					<div class="kv"><span class="k"><?php echo esc_html( sprintf( __( 'Study permits that took effect, %s', 'publictech-terminal' ), $pt_y5 ) ); ?></span><span class="v"><?php echo esc_html( $pt_n( $pt_tr['study5'] ) ); ?></span></div>
					<div class="kv"><span class="k"><?php echo esc_html( sprintf( __( 'Temporary Foreign Worker Program permits, %s', 'publictech-terminal' ), $pt_y5 ) ); ?></span><span class="v"><?php echo esc_html( $pt_n( $pt_tr['tfwp5'] ) ); ?></span></div>
					<div class="kv"><span class="k"><?php echo esc_html( sprintf( __( 'International Mobility Program permits, %s', 'publictech-terminal' ), $pt_y5 ) ); ?></span><span class="v"><?php echo esc_html( $pt_n( $pt_tr['imp5'] ) ); ?></span></div>
					<div class="kv"><span class="k"><?php echo esc_html( sprintf( __( 'Humanitarian work permits, %s', 'publictech-terminal' ), $pt_y5 ) ); ?></span><span class="v"><?php echo esc_html( $pt_n( $pt_tr['hc5'] ) ); ?></span></div>
					<div class="kv"><span class="k"><?php esc_html_e( 'Study and work permits per new permanent resident', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( null === $pt_tr['temp_per_pr'] ? '—' : number_format( (float) $pt_tr['temp_per_pr'], 2 ) ); ?></span></div>
					<?php if ( $pt_tr['asylum_years'] ) : $pt_ay = implode( '–', (array) $pt_tr['asylum_years'] ); ?>
					<div class="kv"><span class="k"><?php echo esc_html( sprintf( __( 'Refugee claims, %s', 'publictech-terminal' ), $pt_ay ) ); ?></span><span class="v"><?php echo esc_html( $pt_n( $pt_tr['asylum_referred5'] ) . ( null !== $pt_tr['asylum_share_pct'] ? ' · ' . number_format( (float) $pt_tr['asylum_share_pct'], 1 ) . '% of all' : '' ) ); ?></span></div>
					<div class="kv"><span class="k"><?php esc_html_e( 'Claims accepted (of those decided on the merits)', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( ( null === $pt_tr['acceptance_pct'] ? '—' : $pt_tr['acceptance_pct'] . '%' ) . ' · all countries ' . ( null === $pt_tr['national_acceptance_pct'] ? '—' : $pt_tr['national_acceptance_pct'] . '%' ) ); ?></span></div>
					<?php endif; ?>
				</div>
				<div class="bd">
					<h4 class="pt-cty-h4" style="margin-top:0"><?php esc_html_e( 'Arrivals per year', 'publictech-terminal' ); ?></h4>
					<div class="chart-host" id="ptCtyTemp" style="height:260px"></div>
					<h4 class="pt-cty-h4"><?php esc_html_e( 'Holding a permit on 31 December', 'publictech-terminal' ); ?></h4>
					<div class="chart-host" id="ptCtyHolders" style="height:220px"></div>
					<p class="pt-cty-note"><?php esc_html_e( 'Permits are counted when they take effect, so one person can be counted more than once (a renewal, or a study permit followed by a work permit), and many later become permanent residents: the lines are not added together. The International Mobility Program covers open work permits such as post-graduation, spousal, working-holiday and emergency programs (Ukraine 2022 on); humanitarian work permits go mostly to refugee claimants. Refugee claims are by country of alleged persecution; a claim decided in a later year counts in that year’s decisions.', 'publictech-terminal' ); ?></p>
					<?php if ( ! empty( $pt_tr['asylum'] ) ) : ?>
					<h4 class="pt-cty-h4"><?php esc_html_e( 'Refugee claims (Immigration and Refugee Board)', 'publictech-terminal' ); ?></h4>
					<div class="pt-cty-scroll" style="max-height:320px">
					<table class="pt-cty-table compact">
						<thead><tr><th><?php esc_html_e( 'Year', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Claims', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Accepted', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Rejected', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Abandoned / withdrawn', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Accepted %', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Pending at year end', 'publictech-terminal' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( array_reverse( (array) $pt_tr['asylum'] ) as $r ) :
							$pt_dec = (int) $r[2] + (int) $r[3];
							$pt_cell = static fn( $v ): string => null === $v ? '<5' : number_format( (int) $v ); ?>
							<tr>
								<td class="mono"><?php echo esc_html( $r[0] . ( ! empty( $r[8] ) && ! str_contains( (string) $r[8], 'December' ) ? ' (to date)' : '' ) ); ?></td>
								<td class="mono"><?php echo esc_html( $pt_cell( $r[1] ) ); ?></td>
								<td class="mono"><?php echo esc_html( $pt_cell( $r[2] ) ); ?></td>
								<td class="mono"><?php echo esc_html( $pt_cell( $r[3] ) ); ?></td>
								<td class="mono"><?php echo esc_html( null === $r[4] && null === $r[5] ? '—' : number_format( (int) $r[4] + (int) $r[5] ) ); ?></td>
								<td class="mono"><?php echo esc_html( $pt_dec ? number_format( 100 * (int) $r[2] / $pt_dec, 0 ) . '%' : '—' ); ?></td>
								<td class="mono"><?php echo esc_html( $pt_cell( $r[7] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; ?>

			<div class="panel corner">
				<div class="hd">
					<h3>◇ <?php esc_html_e( 'Canada’s aid to this country', 'publictech-terminal' ); ?></h3>
					<span class="meta"><?php printf( esc_html__( 'OECD DAC2a · MILLIONS OF %d US$', 'publictech-terminal' ), $pt_base_year ); ?></span>
				</div>
				<div class="bd">
					<?php if ( ! empty( $pt_ad['series'] ) ) : ?>
						<div class="chart-host lg" id="ptCtyAidChart" style="height:280px"></div>
						<p class="pt-cty-note"><?php esc_html_e( 'Gross ODA is what Canada disbursed; net ODA subtracts loan repayments received. Constant dollars remove inflation. Aid given through the UN, World Bank and other agencies is not assigned to countries here.', 'publictech-terminal' ); ?></p>
					<?php else : ?>
						<p class="pt-cty-note"><?php esc_html_e( 'The OECD lists no Canadian official development assistance to this country (high-income countries are not eligible).', 'publictech-terminal' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<?php $pt_cad = is_array( $pt_p['aid_cad'] ?? null ) ? $pt_p['aid_cad'] : null; if ( $pt_cad && ! empty( $pt_cad['series'] ) ) :
				$pt_m = static fn( $v ): string => null === $v ? '—' : pt_format_money_short( (float) $v ); ?>
			<div class="panel corner" id="aid-cad">
				<div class="hd">
					<h3>◇ <?php esc_html_e( 'Canadian spending here, by fiscal year', 'publictech-terminal' ); ?></h3>
					<span class="meta"><?php esc_html_e( 'GLOBAL AFFAIRS CANADA · CAD', 'publictech-terminal' ); ?></span>
				</div>
				<div class="bd">
					<div class="chart-host" id="ptCtyAidCad" style="height:240px"></div>
					<p class="pt-cty-note"><?php esc_html_e( 'International assistance disbursed by every federal department to this country, in Canadian dollars, from Global Affairs Canada’s own statistics: the Statistical Report on International Assistance to 2015-16, the Historical Project Data Set after. From 2016-17 the darker line is the part that counts as official development assistance; the rest is mostly loans and other official flows.', 'publictech-terminal' ); ?></p>
					<div class="pt-cty-two" style="margin-top:10px">
						<?php foreach ( [ 'departments' => __( 'Departments', 'publictech-terminal' ), 'sectors' => __( 'Sectors', 'publictech-terminal' ) ] as $k => $label ) : if ( empty( $pt_cad[ $k ] ) ) { continue; } ?>
							<div>
								<h4 class="pt-cty-h4" style="margin-top:0"><?php echo esc_html( $label . ' · ' . implode( '–', (array) $pt_cad['window'] ) ); ?></h4>
								<table class="pt-cty-table compact"><tbody>
								<?php foreach ( (array) $pt_cad[ $k ] as $r ) : ?>
									<tr><td><?php echo esc_html( (string) $r[0] ); ?></td><td class="mono"><?php echo esc_html( $pt_m( $r[1] ) ); ?></td></tr>
								<?php endforeach; ?>
								</tbody></table>
							</div>
						<?php endforeach; ?>
					</div>
					<?php if ( ! empty( $pt_cad['partners'] ) ) : ?>
						<h4 class="pt-cty-h4"><?php echo esc_html( __( 'Partner organisations', 'publictech-terminal' ) . ' · ' . implode( '–', (array) $pt_cad['window'] ) ); ?></h4>
						<table class="pt-cty-table compact"><tbody>
						<?php foreach ( (array) $pt_cad['partners'] as $r ) : ?>
							<tr><td><?php echo esc_html( (string) $r[0] ); ?></td><td class="mono"><?php echo esc_html( $pt_m( $r[1] ) ); ?></td></tr>
						<?php endforeach; ?>
						</tbody></table>
					<?php endif; ?>
					<?php if ( ! empty( $pt_cad['projects'] ) ) : ?>
						<h4 class="pt-cty-h4"><?php echo esc_html( __( 'Largest projects', 'publictech-terminal' ) . ' · ' . implode( '–', (array) $pt_cad['projects_window'] ) ); ?></h4>
						<div class="pt-cty-scroll" style="max-height:340px">
						<table class="pt-cty-table compact">
							<thead><tr><th><?php esc_html_e( 'Project', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Department / partner', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Spent', 'publictech-terminal' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( (array) $pt_cad['projects'] as $r ) : ?>
								<tr>
									<td><?php if ( ! empty( $r[5] ) ) : ?><a href="<?php echo esc_url( (string) $r[5] ); ?>" rel="noopener" target="_blank"><?php endif; ?><?php echo esc_html( (string) ( $r[1] ?: $r[0] ) ); ?><?php if ( ! empty( $r[5] ) ) : ?></a><?php endif; ?> <span class="mono pt-cty-sub"><?php echo esc_html( (string) $r[0] ); ?></span></td>
									<td><?php echo esc_html( trim( (string) $r[2] . ( $r[3] ? ' · ' . $r[3] : '' ) ) ); ?></td>
									<td class="mono"><?php echo esc_html( $pt_m( $r[4] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						</div>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; ?>

			<div class="panel corner" id="deep-dive">
				<div class="hd">
					<h3>◇ <?php esc_html_e( 'After an MP born here took a seat', 'publictech-terminal' ); ?></h3>
					<span class="meta"><?php esc_html_e( 'BEFORE / AFTER · 5 YEARS', 'publictech-terminal' ); ?></span>
				</div>
				<div class="bd">
					<?php if ( $pt_ev ) : ?>
						<p class="pt-cty-note" style="margin-top:0">
							<?php esc_html_e( 'For each year in which the House went from no member born here to at least one, the country’s share of Canada’s immigrants and of Canada’s aid in the five years after is compared with the five years before. "Percentile" places the change among every other country and year with no such member — 50 is a typical change, 90 means larger than nine in ten.', 'publictech-terminal' ); ?>
						</p>
						<div class="pt-cty-scroll">
						<table class="pt-cty-table">
							<thead><tr>
								<th><?php esc_html_e( 'Year', 'publictech-terminal' ); ?></th>
								<th><?php esc_html_e( 'Members', 'publictech-terminal' ); ?></th>
								<th><?php esc_html_e( 'Immigration share', 'publictech-terminal' ); ?></th>
								<th><?php esc_html_e( 'Pctile', 'publictech-terminal' ); ?></th>
								<th><?php esc_html_e( 'Aid share', 'publictech-terminal' ); ?></th>
								<th><?php esc_html_e( 'Pctile', 'publictech-terminal' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $pt_ev as $e ) :
								$ei = is_array( $e['immigration'] ?? null ) ? $e['immigration'] : null;
								$ea = is_array( $e['aid'] ?? null ) ? $e['aid'] : null; ?>
								<tr>
									<td class="mono"><?php echo (int) $e['year']; ?></td>
									<td><?php echo esc_html( implode( ', ', (array) ( $e['members'] ?? [] ) ) ); ?></td>
									<td class="mono"><?php echo $ei ? esc_html( sprintf( '%.2f%% → %.2f%% (%s)', 100 * $ei['pre_share'], 100 * $ei['post_share'], $pt_pct( $ei['change_pct'] ) ) ) : '—'; ?></td>
									<td class="mono"><?php echo $ei ? (int) $ei['percentile'] : '—'; ?></td>
									<td class="mono"><?php echo $ea ? esc_html( sprintf( '%.2f%% → %.2f%% (%s)', 100 * $ea['pre_share'], 100 * $ea['post_share'], $pt_pct( $ea['change_pct'] ) ) ) : '—'; ?></td>
									<td class="mono"><?php echo $ea ? (int) $ea['percentile'] : '—'; ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						</div>
						<?php foreach ( $pt_ev as $e ) :
							$ei = is_array( $e['immigration'] ?? null ) ? $e['immigration'] : null;
							if ( $ei && null !== $ei['pretrend_pct'] ) : ?>
								<p class="pt-cty-note"><?php printf( esc_html__( 'Before %1$d the share had already moved %2$s over the previous five years — a community that is growing both sends more immigrants and elects members, so a rise after the election is not evidence the member caused it.', 'publictech-terminal' ), (int) $e['year'], esc_html( $pt_pct( $ei['pretrend_pct'] ) ) ); ?></p>
							<?php break; endif;
						endforeach; ?>
					<?php elseif ( $pt_mps ) : ?>
						<p class="pt-cty-note" style="margin-top:0"><?php esc_html_e( 'Members born here sat in years the comparison cannot use: before 1969 (no per-country immigration figures five years earlier), within five years of the latest data, or without a year with no such member before them.', 'publictech-terminal' ); ?></p>
					<?php else : ?>
						<p class="pt-cty-note" style="margin-top:0"><?php esc_html_e( 'No member of the House of Commons born in this country is recorded, so there is no before/after to compare. This country is part of the comparison group for the others.', 'publictech-terminal' ); ?></p>
					<?php endif; ?>
					<div class="chart-host" id="ptCtySitting" style="height:120px;margin-top:10px"></div>
					<p class="pt-cty-note"><a href="<?php echo esc_url( home_url( '/countries/#analysis' ) ); ?>"><?php esc_html_e( 'All countries together: does an MP from a country go with more immigrants or more aid from Canada? →', 'publictech-terminal' ); ?></a></p>
				</div>
			</div>

			<div class="panel corner">
				<div class="hd">
					<h3>◇ <?php esc_html_e( 'Members of Parliament and senators born here', 'publictech-terminal' ); ?></h3>
					<span class="meta"><?php esc_html_e( 'WIKIDATA · LIBRARY OF PARLIAMENT', 'publictech-terminal' ); ?></span>
				</div>
				<div class="bd">
					<?php if ( $pt_pp ) : ?>
						<div class="pt-cty-scroll">
						<table class="pt-cty-table">
							<thead><tr>
								<th><?php esc_html_e( 'Name', 'publictech-terminal' ); ?></th>
								<th><?php esc_html_e( 'Chamber', 'publictech-terminal' ); ?></th>
								<th><?php esc_html_e( 'First seat', 'publictech-terminal' ); ?></th>
								<th><?php esc_html_e( 'Born in', 'publictech-terminal' ); ?></th>
								<th title="<?php esc_attr_e( 'Votes on immigration bills (2004 onward) cast with the majority of the member’s own caucus', 'publictech-terminal' ); ?>"><?php esc_html_e( 'Immigration votes with caucus', 'publictech-terminal' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $pt_pp as $p ) :
								$iv = is_array( $p['immigration_votes'] ?? null ) ? $p['immigration_votes'] : null;
								$first = $p['mp_from'] ?? $p['senate_from'] ?? null; ?>
								<tr>
									<td><a href="<?php echo esc_url( 'https://www.wikidata.org/wiki/' . rawurlencode( (string) $p['qid'] ) ); ?>" rel="noopener" target="_blank"><?php echo esc_html( (string) $p['name'] ); ?></a></td>
									<td class="mono"><?php echo esc_html( trim( ( ! empty( $p['mp'] ) ? 'MP ' : '' ) . ( ! empty( $p['senator'] ) ? 'Senate' : '' ) ) ); ?></td>
									<td class="mono"><?php echo esc_html( $first ? substr( (string) $first, 0, 4 ) : '—' ); ?></td>
									<td><?php echo esc_html( (string) ( $p['birth_place'] ?? '' ) ); ?></td>
									<td class="mono"><?php echo $iv ? esc_html( number_format( (float) $iv['with_caucus_pct'], 1 ) . '% of ' . (int) $iv['n'] ) : '—'; ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						</div>
						<p class="pt-cty-note">
							<?php printf( esc_html__( 'Across all MPs, %1$s of votes on the %2$d divisions about immigration bills since 2004 followed the member’s own caucus majority.', 'publictech-terminal' ), esc_html( number_format( (float) ( $pt_vb['with_caucus_pct'] ?? 0 ), 1 ) . '%' ), (int) ( $pt_vb['divisions'] ?? 0 ) ); ?>
							<?php if ( ! empty( $pt_rp['per_100k'] ) ) :
								printf( ' ' . esc_html__( 'Members first elected since 1968 born here: %1$s per 100,000 immigrants from here since 1964 (all countries: %2$s).', 'publictech-terminal' ), esc_html( number_format( (float) $pt_rp['per_100k'], 2 ) ), esc_html( number_format( (float) ( $pt_rp['all_countries_per_100k'] ?? 0 ), 2 ) ) );
							endif; ?>
						</p>
					<?php else : ?>
						<p class="pt-cty-note" style="margin-top:0"><?php esc_html_e( 'No member of the House of Commons or Senate is recorded as born in this country. About one in six parliamentarians has no recorded birthplace, most of them from the 19th century.', 'publictech-terminal' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="panel corner">
				<div class="hd">
					<h3>◇ <?php esc_html_e( 'Federal laws that name this country', 'publictech-terminal' ); ?></h3>
					<span class="meta"><?php esc_html_e( 'JUSTICE LAWS · IN FORCE', 'publictech-terminal' ); ?></span>
				</div>
				<div class="bd">
					<?php if ( $pt_lw ) : ?>
						<div class="pt-cty-scroll">
						<table class="pt-cty-table">
							<thead><tr><th><?php esc_html_e( 'Title', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Kind', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Topic', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Number', 'publictech-terminal' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $pt_lw as $l ) : ?>
								<tr>
									<td><a href="<?php echo esc_url( (string) $l['url'] ); ?>" rel="noopener" target="_blank"><?php echo esc_html( (string) $l['title'] ); ?></a></td>
									<td class="mono"><?php echo esc_html( (string) $l['kind'] ); ?></td>
									<td><span class="pt-cty-tag t-<?php echo esc_attr( sanitize_html_class( (string) $l['topic'] ) ); ?>"><?php echo esc_html( (string) $l['topic'] ); ?></span></td>
									<td class="mono"><?php echo esc_html( (string) ( $l['number'] ?? '' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						</div>
						<p class="pt-cty-note"><?php esc_html_e( 'Consolidated acts and regulations now in force whose title names the country. Repealed laws are not in the consolidation.', 'publictech-terminal' ); ?></p>
					<?php else : ?>
						<p class="pt-cty-note" style="margin-top:0"><?php esc_html_e( 'No consolidated federal act or regulation in force names this country in its title.', 'publictech-terminal' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="panel corner">
				<div class="hd">
					<h3>◇ <?php esc_html_e( 'House of Commons bills that name this country', 'publictech-terminal' ); ?></h3>
					<span class="meta"><?php esc_html_e( 'LEGISINFO · 1994 ONWARD', 'publictech-terminal' ); ?></span>
				</div>
				<div class="bd">
					<?php if ( $pt_bl ) : ?>
						<?php foreach ( $pt_bl as $b ) : ?>
							<div class="pt-cty-bill">
								<div><a href="<?php echo esc_url( (string) $b['url'] ); ?>" rel="noopener" target="_blank"><span class="mono"><?php echo esc_html( ( $b['number'] ?? '' ) . ' (' . ( $b['session'] ?? '' ) . ')' ); ?></span> <?php echo esc_html( (string) $b['title'] ); ?></a></div>
								<div class="mono pt-cty-sub"><?php echo esc_html( trim( ( $b['status'] ?? '' ) . ( ! empty( $b['royal_assent'] ) ? ' · royal assent ' . $b['royal_assent'] : '' ) ) ); ?></div>
								<?php foreach ( (array) ( $b['divisions'] ?? [] ) as $d ) : ?>
									<div class="pt-cty-div mono">
										<?php echo esc_html( $d['date'] . ' · ' . $d['result'] . ' ' . $d['yeas'] . '–' . $d['nays'] ); ?>
										<?php if ( ! empty( $d['born_here'] ) ) :
											echo ' · ' . esc_html__( 'members born here:', 'publictech-terminal' ) . ' ' . esc_html( implode( ', ', array_map( static fn( $v ) => $v[0] . ' (' . $v[1] . ')', (array) $d['born_here'] ) ) );
										endif; ?>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endforeach; ?>
					<?php else : ?>
						<p class="pt-cty-note" style="margin-top:0"><?php esc_html_e( 'No House bill since 1994 names this country in its title.', 'publictech-terminal' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

		</div>

		<aside class="pt-cty-side">

			<div class="panel corner">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Immigration and aid together', 'publictech-terminal' ); ?></h3></div>
				<div class="kvs">
					<div class="kv"><span class="k"><?php esc_html_e( 'Immigrants since 1964', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( $pt_n( $pt_im['since_1964'] ?? null ) ); ?></span></div>
					<div class="kv"><span class="k"><?php esc_html_e( 'Aid since 1960 (gross)', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( $pt_usd( $pt_ad['total_gross'] ?? null ) ); ?></span></div>
					<div class="kv"><span class="k"><?php esc_html_e( 'Immigrants per US$1M of aid', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( $pt_n( $pt_ad['immigrants_per_musd'] ?? null ) ); ?></span></div>
				</div>
				<?php if ( ! empty( $pt_ad['lead_lag'] ) ) : ?>
					<div class="bd" style="padding-top:0">
						<p class="pt-cty-note" style="margin-top:0"><?php esc_html_e( 'Do aid and immigration move together? Correlation of year-to-year changes; a lag of +2 compares aid with immigration two years later.', 'publictech-terminal' ); ?></p>
						<table class="pt-cty-table compact">
							<thead><tr><th><?php esc_html_e( 'Lag', 'publictech-terminal' ); ?></th><th>r</th><th>n</th></tr></thead>
							<tbody>
							<?php foreach ( (array) $pt_ad['lead_lag'] as $ll ) : ?>
								<tr><td class="mono"><?php echo esc_html( ( $ll[0] > 0 ? '+' : '' ) . $ll[0] ); ?></td><td class="mono"><?php echo esc_html( number_format( (float) $ll[1], 2 ) ); ?></td><td class="mono"><?php echo (int) $ll[2]; ?></td></tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>

			<?php $pt_mix = (array) ( $pt_im['categories']['mix'] ?? [] ); if ( $pt_mix ) : ?>
			<div class="panel corner">
				<div class="hd"><h3>◇ <?php esc_html_e( 'How they came', 'publictech-terminal' ); ?></h3><span class="meta"><?php echo esc_html( implode( '–', (array) $pt_im['categories']['years'] ) ); ?></span></div>
				<div class="bd">
					<table class="pt-cty-table compact">
						<thead><tr><th><?php esc_html_e( 'Category', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'Here', 'publictech-terminal' ); ?></th><th><?php esc_html_e( 'All', 'publictech-terminal' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $pt_mix as $m ) : ?>
							<tr><td><?php echo esc_html( (string) $m[0] ); ?></td><td class="mono"><?php echo esc_html( null === $m[2] ? '—' : $m[2] . '%' ); ?></td><td class="mono"><?php echo esc_html( null === $m[3] ? '—' : $m[3] . '%' ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="pt-cty-note"><?php esc_html_e( 'IRCC immigration category, compared with all permanent residents in the same years.', 'publictech-terminal' ); ?></p>
				</div>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $pt_im['monthly_24'] ) ) : ?>
			<div class="panel corner">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Last 24 months', 'publictech-terminal' ); ?></h3><span class="meta">IRCC</span></div>
				<div class="bd"><div class="chart-host" id="ptCtyMonthly" style="height:150px"></div></div>
			</div>
			<?php endif; ?>

			<?php if ( $pt_gr ) : ?>
			<div class="panel corner">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Federal grants to recipients here', 'publictech-terminal' ); ?></h3><span class="meta">CAD</span></div>
				<div class="kvs">
					<div class="kv"><span class="k"><?php esc_html_e( 'Agreements', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( $pt_n( $pt_gr['count'] ) ); ?></span></div>
					<div class="kv"><span class="k"><?php esc_html_e( 'Value', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( pt_format_money_short( (float) $pt_gr['total'] ) ); ?></span></div>
				</div>
				<div class="bd" style="padding-top:0">
					<?php foreach ( array_slice( (array) $pt_gr['departments'], 0, 5 ) as $d ) : ?>
						<div class="kv"><span class="k" style="font-size:11px"><?php echo esc_html( (string) $d[0] ); ?></span><span class="v"><?php echo esc_html( pt_format_money_short( (float) $d[1] ) ); ?></span></div>
					<?php endforeach; ?>
					<p class="pt-cty-note"><?php esc_html_e( 'Grants and contributions (proactive disclosure) to recipients whose address is in this country. Most aid goes through Canadian organisations and is counted in the aid figures above, not here.', 'publictech-terminal' ); ?></p>
				</div>
			</div>
			<?php endif; ?>

			<?php $pt_fr = (array) ( $pt_im['francophone'] ?? [] ); $pt_ado = (array) ( $pt_im['adoptions'] ?? [] ); if ( $pt_fr || $pt_ado ) : ?>
			<div class="panel corner">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Since 2015', 'publictech-terminal' ); ?></h3><span class="meta">IRCC</span></div>
				<div class="kvs">
					<?php if ( $pt_fr ) : ?><div class="kv"><span class="k"><?php esc_html_e( 'French-speaking PRs outside Quebec', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( $pt_n( array_sum( array_column( $pt_fr, 1 ) ) ) ); ?></span></div><?php endif; ?>
					<?php if ( $pt_ado ) : ?><div class="kv"><span class="k"><?php esc_html_e( 'International adoptions (born here)', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( $pt_n( array_sum( array_column( $pt_ado, 1 ) ) ) ); ?></span></div><?php endif; ?>
				</div>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $pt_im['mp22_bases'] ) && count( (array) $pt_im['mp22_bases'] ) > 1 ) : ?>
			<div class="panel corner">
				<div class="hd"><h3>◇ 1964–1979 <?php esc_html_e( 'by basis', 'publictech-terminal' ); ?></h3><span class="meta">MP22-1</span></div>
				<div class="bd">
					<div class="chart-host" id="ptCtyBases" style="height:160px"></div>
					<p class="pt-cty-note"><?php esc_html_e( 'The printed reports counted immigrants by last country of residence, by citizenship and by birth; the three can differ a great deal (for example people born in one country who lived in another).', 'publictech-terminal' ); ?></p>
				</div>
			</div>
			<?php endif; ?>

			<div class="panel corner">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Sources', 'publictech-terminal' ); ?></h3></div>
				<div class="bd pt-cty-note" style="margin:0">
					<?php esc_html_e( 'Immigration: IRCC open data (permanent residents), IRCC 1980–2015 tables, Immigration Statistics MP22-1 (1964–1979, transcribed and checked against the printed totals), Statistics Canada 17-10-0040. Aid: OECD DAC2a. Grants: Government of Canada proactive disclosure. Laws: Justice Laws consolidation. Bills and votes: LEGISinfo and House of Commons divisions. Birthplaces: Wikidata (Library of Parliament).', 'publictech-terminal' ); ?>
				</div>
			</div>
		</aside>
	</div>
</section>
</main>

<script id="ptCountryData" type="application/json"><?php
echo wp_json_encode( [
	'immigration' => $pt_im['series'] ?? [],
	'aid'         => $pt_ad['series'] ?? [],
	'aidCad'      => $pt_p['aid_cad']['series'] ?? [],
	'tempFlows'   => $pt_p['temporary']['flows'] ?? new \stdClass(),
	'tempStocks'  => $pt_p['temporary']['stocks'] ?? new \stdClass(),
	'asylum'      => $pt_p['temporary']['asylum'] ?? [],
	'aidShare'    => $pt_ad['share'] ?? [],
	'sitting'     => $pt_p['sitting_by_year'] ?? [],
	'events'      => array_map( static fn( $e ) => (int) $e['year'], $pt_ev ),
	'monthly'     => $pt_im['monthly_24'] ?? [],
	'bases'       => $pt_im['mp22_bases'] ?? new \stdClass(),
	'firstMp'     => $pt_mps ? (int) substr( (string) ( $pt_mps[0]['mp_from'] ?? '0' ), 0, 4 ) : null,
], JSON_HEX_TAG | JSON_HEX_AMP );
?></script>

<?php get_footer(); ?>
