<?php
/**
 * Template Name: PT — Riding Report
 *
 * Grant and contribution spending report for a federal riding. The riding
 * slug is read from the `pt_riding_slug` post meta key so the same template
 * serves every riding page. Data is embedded server-side and handed to the
 * client as JSON for chart rendering via the standard PT.chart() pipeline.
 *
 * Currently seeded with Thunder Bay–Rainy River analysis data.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

/* ─── Riding identity ──────────────────────────────────────────────────── */
$pt_riding_name   = 'Thunder Bay–Rainy River';
$pt_riding_abbrev = 'TBAY-RR';
$pt_province      = 'Ontario';
$pt_riding_code   = 'tbayrainy';
$pt_report_date   = 'May 2026';
$pt_data_source   = 'search.open.canada.ca/grants';

/* ─── Yearly totals (source: tbayrainy_view) ───────────────────────────
   Columns: year, party, total, total_positive, total_negative,
            positive_agreements, negative_agreements
────────────────────────────────────────────────────────────────────────── */
$pt_yearly = [
	[ 2001, 'Liberal',       18836072,  18836072,          0,  59,  0 ],
	[ 2006, 'Conservative',   4450546,   4450546,          0,  25,  0 ],
	[ 2007, 'Conservative',   3976604,   3976604,          0,  29,  0 ],
	[ 2008, 'Conservative',   5378933,   5378933,          0,  22,  0 ],
	[ 2009, 'Conservative',   8618033,   8618033,          0,  28,  0 ],
	[ 2010, 'Conservative',   2614934,   2614934,          0,  11,  0 ],
	[ 2011, 'Conservative',   1668878,   1668878,          0,   4,  0 ],
	[ 2012, 'Conservative',   1744517,   1744517,          0,   9,  0 ],
	[ 2013, 'Conservative',  11347460,  11347460,          0,  56,  0 ],
	[ 2014, 'Conservative',  11210364,  11274864,     -64500,  61,  2 ],
	[ 2015, 'Liberal',       12205343,  16659501,   -4454158,  84,  2 ],
	[ 2016, 'Liberal',      174322883, 175450600,   -1127717, 471,  7 ],
	[ 2017, 'Liberal',       40268494,  40380708,    -112214, 114,  4 ],
	[ 2018, 'Liberal',      172940972, 172940972,          0, 352,  0 ],
	[ 2019, 'Liberal',      100907643, 101470921,    -563278, 230,  2 ],
	[ 2020, 'Liberal',      247785153, 258554646,  -10769493,1004, 12 ],
	[ 2021, 'Liberal',      634349650, 642724761,   -8375110,1140, 10 ],
	[ 2022, 'Liberal',      320198272, 323910016,   -3711744, 270,  2 ],
	[ 2023, 'Liberal',      370804664, 371414837,    -610173, 228,  3 ],
	[ 2024, 'Liberal',      104647865, 104647865,          0, 186,  0 ],
	[ 2025, 'Liberal',          40950,     40950,          0,   2,  0 ],
];

/* ─── Top-10 recipients ───────────────────────────────────────────────── */
$pt_recipients = [
	[ 'NAN Corporate Services',                            185700000 ],
	[ 'Big Grassy & Ojibways of Onigaming First Nations',  180300000 ],
	[ 'Lac La Croix First Nation',                         148700000 ],
	[ 'Seine River First Nation',                          112600000 ],
	[ 'Couchiching First Nation',                          112500000 ],
	[ 'Independent First Nations Alliance',                111000000 ],
	[ 'Nigigoonsiminikaaning First Nation',                105300000 ],
	[ 'Rainy River First Nation',                           96200000 ],
	[ 'Keewaytinook Okimakanak / Northern Chiefs Council',  93400000 ],
	[ 'Wequedong Lodge of Thunder Bay',                     92300000 ],
];

/* ─── Spending by recipient type ─────────────────────────────────────── */
$pt_rec_types = [
	[ 'Aboriginal',               1285000000 ],
	[ 'Other',                     590700000 ],
	[ 'Not-for-profit / Charity',  228100000 ],
	[ 'Government',                 35800000 ],
	[ 'Unknown / NULL',             25000000 ],
	[ 'For-profit',                 22100000 ],
	[ 'Academia',                   14400000 ],
	[ 'Individual / Sole Prop.',      166000 ],
];

/* ─── Top program purposes ───────────────────────────────────────────── */
$pt_purposes = [
	[ 'Canada Summer Jobs',                     652 ],
	[ 'Education',                              520 ],
	[ 'Social Development',                     251 ],
	[ 'Community Infrastructure',               231 ],
	[ 'Indian Government Support',              178 ],
	[ 'Health Promotion & Disease Prevention',  168 ],
	[ 'Child and Family Services',              166 ],
	[ 'Economic Growth (Northern Ontario)',     154 ],
	[ 'Primary Care',                           120 ],
	[ 'Other Program Reporting',                107 ],
];

/* ─── KPI roll-ups ───────────────────────────────────────────────────── */
$pt_total_spend      = 0;
$pt_total_agreements = 0;
$pt_peak_year        = [ 'year' => 0, 'total' => 0 ];
$pt_con_total        = 0;
$pt_lib_total        = 0;
$pt_con_agreements   = 0;
$pt_lib_agreements   = 0;

foreach ( $pt_yearly as $row ) {
	[ $yr, $party, $total, $pos, $neg, $pos_n, $neg_n ] = $row;
	if ( $yr < 2006 ) { continue; } // 2001 placeholder excluded from trend KPIs
	$pt_total_spend      += $total;
	$pt_total_agreements += $pos_n + $neg_n;
	if ( $total > $pt_peak_year['total'] ) {
		$pt_peak_year = [ 'year' => $yr, 'total' => $total ];
	}
	if ( 'Conservative' === $party ) {
		$pt_con_total      += $total;
		$pt_con_agreements += $pos_n + $neg_n;
	} else {
		$pt_lib_total      += $total;
		$pt_lib_agreements += $pos_n + $neg_n;
	}
}

/* ─── Helpers ─────────────────────────────────────────────────────────── */
function pt_fmt_m( int $v ): string {
	if ( $v >= 1_000_000_000 ) {
		return '$' . number_format( $v / 1_000_000_000, 2 ) . 'B';
	}
	return '$' . number_format( $v / 1_000_000, 1 ) . 'M';
}
function pt_fmt_dollar( int $v ): string {
	return '$' . number_format( $v );
}

get_header(); ?>

<main>
<section class="view active" data-pt-view="riding-report">

	<!-- ═══ HERO ══════════════════════════════════════════════════════════ -->
	<div class="hero corner" style="margin-bottom:18px">
		<div style="position:relative;z-index:1">
			<div class="mono uc" style="color:var(--accent);font-size:10px;letter-spacing:0.22em;margin-bottom:10px">
				◇ Federal Riding · Grant &amp; Contribution Analysis
			</div>
			<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px">
				<div>
					<h2 style="margin:0 0 6px;font-size:26px;font-weight:700;letter-spacing:0.02em">
						<?php echo esc_html( $pt_riding_name ); ?>
					</h2>
					<div style="color:var(--ink-1);font-size:13px;line-height:1.6;max-width:620px">
						Comprehensive analysis of federal grant and contribution agreements associated with
						this riding, sourced from <span class="mono" style="color:var(--accent)"><?php echo esc_html( $pt_data_source ); ?></span>.
						Agreements are linked via the Postal Code Federal Riding File (PCFRF, V2209).
						Data covers 2006–2025 agreement start years.
					</div>
				</div>
				<div style="display:flex;flex-direction:column;gap:6px;text-align:right;flex-shrink:0">
					<div class="mono" style="font-size:10px;color:var(--ink-3)">RIDING · <?php echo esc_html( $pt_riding_abbrev ); ?></div>
					<div class="mono" style="font-size:10px;color:var(--ink-3)">PROVINCE · <?php echo esc_html( $pt_province ); ?></div>
					<div class="mono" style="font-size:10px;color:var(--ink-3)">REPORT · <?php echo esc_html( $pt_report_date ); ?></div>
					<div class="mono" style="font-size:10px;color:var(--accent)">POSTAL CODES · 2,880</div>
				</div>
			</div>
		</div>
	</div>

	<!-- ═══ KPI STRIP ═════════════════════════════════════════════════════ -->
	<div class="kpis" style="margin-bottom:14px">
		<div class="kpi corner">
			<div class="lab">Total Spend (2006–2024)</div>
			<div class="val" style="font-size:20px"><?php echo esc_html( pt_fmt_m( $pt_total_spend ) ); ?></div>
			<div class="delta dim">All active years</div>
			<svg class="spark" id="ptSparkTotal" aria-hidden="true"></svg>
		</div>
		<div class="kpi">
			<div class="lab">Total Agreements</div>
			<div class="val"><?php echo esc_html( number_format( $pt_total_agreements ) ); ?></div>
			<div class="delta dim">Positive &amp; amended</div>
			<svg class="spark" id="ptSparkAgreements" aria-hidden="true"></svg>
		</div>
		<div class="kpi">
			<div class="lab">Peak Year</div>
			<div class="val"><?php echo esc_html( $pt_peak_year['year'] ); ?></div>
			<div class="delta pos"><?php echo esc_html( pt_fmt_m( $pt_peak_year['total'] ) ); ?></div>
			<svg class="spark" id="ptSparkPeak" aria-hidden="true"></svg>
		</div>
		<div class="kpi">
			<div class="lab">Top Recipient Category</div>
			<div class="val" style="font-size:14px;margin-top:12px;letter-spacing:0">Aboriginal</div>
			<div class="delta pos">$1.285 B · 59%</div>
		</div>
	</div>

	<!-- ═══ MAIN GRID ══════════════════════════════════════════════════════ -->
	<div style="display:grid;grid-template-columns:1fr 340px;gap:14px;align-items:start">

		<!-- Left column: charts -->
		<div style="display:flex;flex-direction:column;gap:14px">

			<!-- Spending by Start Year (bar chart) -->
			<div class="panel corner">
				<div class="hd">
					<h3>◇ Spending by Agreement Start Year</h3>
					<span class="meta">2006–2024 · NET TOTAL</span>
				</div>
				<div class="bd">
					<div style="display:flex;gap:14px;margin-bottom:10px;font-family:'JetBrains Mono';font-size:10px">
						<span><span style="display:inline-block;width:10px;height:10px;background:var(--party-con);border-radius:2px;margin-right:5px;vertical-align:middle"></span>Conservative (2006–2014)</span>
						<span><span style="display:inline-block;width:10px;height:10px;background:var(--party-lib);border-radius:2px;margin-right:5px;vertical-align:middle"></span>Liberal (2015–2025)</span>
					</div>
					<div class="chart-host lg" id="ptYearChart" style="height:320px"></div>
				</div>
			</div>

			<!-- Positive vs Negative spending line chart -->
			<div class="panel corner">
				<div class="hd">
					<h3>◇ Positive vs. Negative Spending Over Time</h3>
					<span class="meta">AMENDMENTS &amp; CANCELLATIONS</span>
				</div>
				<div class="bd">
					<p style="font-size:11px;color:var(--ink-2);margin:0 0 10px;line-height:1.5">
						Negative values represent funding reductions, amendments, or cancellations.
						The most significant negative totals appeared in 2020 (–$10.8 M) and 2021 (–$8.4 M).
					</p>
					<div class="chart-host" id="ptPosNegChart" style="height:260px"></div>
					<div class="legend" id="ptPosNegLegend" style="margin-top:8px"></div>
				</div>
			</div>

			<!-- Agreement count chart -->
			<div class="panel corner">
				<div class="hd">
					<h3>◇ Agreement Volume by Start Year</h3>
					<span class="meta">POSITIVE AGREEMENTS · COUNT</span>
				</div>
				<div class="bd">
					<p style="font-size:11px;color:var(--ink-2);margin:0 0 10px;line-height:1.5">
						Agreement volume jumped sharply from 2015 onwards, peaking at 1,140 in 2021
						and 1,004 in 2020—representing a 20× increase over the Conservative-era average.
					</p>
					<div class="chart-host" id="ptCountChart" style="height:240px"></div>
				</div>
			</div>

			<!-- Recipient type chart -->
			<div class="panel corner">
				<div class="hd">
					<h3>◇ Spending Distribution by Recipient Type</h3>
					<span class="meta">ALL TIME PERIODS</span>
				</div>
				<div class="bd">
					<div class="chart-host" id="ptRecTypeChart" style="height:280px"></div>
				</div>
			</div>

			<!-- Program purposes chart -->
			<div class="panel corner">
				<div class="hd">
					<h3>◇ Top 10 Program Purposes</h3>
					<span class="meta">BY AGREEMENT COUNT</span>
				</div>
				<div class="bd">
					<p style="font-size:11px;color:var(--ink-2);margin:0 0 10px;line-height:1.5">
						Youth employment (Canada Summer Jobs), education, and social services account
						for the largest share of agreement activity in the riding.
					</p>
					<div class="chart-host" id="ptPurposeChart" style="height:300px"></div>
				</div>
			</div>

		</div><!-- /left column -->

		<!-- Right column: sidebar panels -->
		<div style="display:flex;flex-direction:column;gap:14px">

			<!-- Party era comparison -->
			<div class="panel corner">
				<div class="hd">
					<h3>◇ Era Comparison</h3>
					<span class="meta">BY PARTY</span>
				</div>
				<div class="kvs">
					<div class="kv">
						<span class="k" style="color:var(--party-con)">Conservative Era</span>
						<span class="v" style="font-size:10px;color:var(--ink-2)">2006–2014</span>
					</div>
					<div class="kv">
						<span class="k">Total Spend</span>
						<span class="v"><?php echo esc_html( pt_fmt_m( $pt_con_total ) ); ?></span>
					</div>
					<div class="kv">
						<span class="k">Agreements</span>
						<span class="v"><?php echo esc_html( number_format( $pt_con_agreements ) ); ?></span>
					</div>
					<div class="kv">
						<span class="k">Avg / Year</span>
						<span class="v"><?php echo esc_html( pt_fmt_m( (int) ( $pt_con_total / 9 ) ) ); ?></span>
					</div>
					<div class="kv" style="background:rgba(0,0,0,0.1)">
						<span class="k" style="color:var(--party-lib)">Liberal Era</span>
						<span class="v" style="font-size:10px;color:var(--ink-2)">2015–2024</span>
					</div>
					<div class="kv">
						<span class="k">Total Spend</span>
						<span class="v"><?php echo esc_html( pt_fmt_m( $pt_lib_total ) ); ?></span>
					</div>
					<div class="kv">
						<span class="k">Agreements</span>
						<span class="v"><?php echo esc_html( number_format( $pt_lib_agreements ) ); ?></span>
					</div>
					<div class="kv">
						<span class="k">Avg / Year</span>
						<span class="v"><?php echo esc_html( pt_fmt_m( (int) ( $pt_lib_total / 10 ) ) ); ?></span>
					</div>
					<div class="kv">
						<span class="k" style="color:var(--pos)">Liberal vs. Con Ratio</span>
						<span class="v" style="color:var(--pos)">
							<?php echo esc_html( number_format( $pt_lib_total / max( $pt_con_total, 1 ), 1 ) ); ?>×
						</span>
					</div>
				</div>
			</div>

			<!-- Top Recipients -->
			<div class="panel corner">
				<div class="hd">
					<h3>◇ Top 10 Recipients</h3>
					<span class="meta">ALL PERIODS</span>
				</div>
				<div class="kvs">
					<?php foreach ( $pt_recipients as $i => $rec ) : ?>
						<div class="kv" style="align-items:flex-start">
							<span class="k" style="flex:1;line-height:1.4;text-transform:none;font-size:10px;padding-right:8px">
								<span class="mono" style="color:var(--accent);margin-right:4px"><?php echo esc_html( $i + 1 ); ?>.</span>
								<?php echo esc_html( $rec[0] ); ?>
							</span>
							<span class="v" style="color:var(--pos);flex-shrink:0"><?php echo esc_html( pt_fmt_m( $rec[1] ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Data notes -->
			<div class="panel">
				<div class="hd"><h3>◇ Data Notes</h3><span class="meta">METHODOLOGY</span></div>
				<div class="bd" style="font-size:11px;color:var(--ink-2);line-height:1.6;display:flex;flex-direction:column;gap:8px">
					<p style="margin:0">
						Agreements are linked to the riding via the
						<strong style="color:var(--ink-1)">Postal Code Federal Riding File (PCFRF)</strong>,
						V2209, based on the 2013 Representation Order / 2021 Census.
					</p>
					<p style="margin:0">
						Postal codes <span class="mono" style="color:var(--warn)">P0T1X0</span> and
						<span class="mono" style="color:var(--warn)">P0T2M0</span> are excluded—manual
						verification showed they primarily correspond to Thunder Bay–Superior North.
					</p>
					<p style="margin:0">
						The <strong style="color:var(--ink-1)">2001 data point ($18.8 M)</strong> represents
						agreements with missing or invalid start dates (placeholder '1899') that were
						normalised to 2001 during cleaning. It is excluded from era comparisons.
					</p>
					<p style="margin:0">
						Source: <span class="mono" style="color:var(--accent)">grantconts.tbayrainy_view</span>
						— a pre-filtered MySQL view restricting results to this riding's postal codes.
					</p>
				</div>
			</div>

		</div><!-- /right column -->
	</div><!-- /main grid -->

	<!-- ═══ FULL-WIDTH TABLES ══════════════════════════════════════════════ -->

	<!-- Yearly breakdown table -->
	<div class="panel corner" style="margin-top:14px">
		<div class="hd">
			<h3>◇ Annual Spending Breakdown — All Years</h3>
			<span class="meta">SOURCE: tbayrainy_view · yearly_totals tab</span>
		</div>
		<div style="overflow-x:auto">
			<table class="stream" style="min-width:680px">
				<thead>
					<tr>
						<th>Year</th>
						<th>Party</th>
						<th class="num">Net Total</th>
						<th class="num">Positive Total</th>
						<th class="num">Negative Total</th>
						<th class="num">+ Agreements</th>
						<th class="num">– Agreements</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( array_reverse( $pt_yearly ) as $row ) :
					[ $yr, $party, $total, $pos, $neg, $pos_n, $neg_n ] = $row;
					$is_lib = 'Liberal' === $party;
					$party_color = $is_lib ? 'var(--party-lib)' : 'var(--party-con)';
					$neg_display = $neg < 0 ? pt_fmt_dollar( $neg ) : '—';
					$neg_class   = $neg < 0 ? 'style="color:var(--neg)"' : '';
				?>
					<tr>
						<td class="mono" style="color:var(--accent);font-weight:600"><?php echo esc_html( $yr ); ?></td>
						<td>
							<span class="tag" style="color:<?php echo esc_attr( $party_color ); ?>;border-color:<?php echo esc_attr( $party_color ); ?>">
								<?php echo esc_html( strtoupper( substr( $party, 0, 3 ) ) ); ?>
							</span>
						</td>
						<td class="num" style="color:var(--ink-0);font-weight:600">
							<?php echo esc_html( pt_fmt_dollar( $total ) ); ?>
						</td>
						<td class="num" style="color:var(--pos)">
							<?php echo esc_html( pt_fmt_dollar( $pos ) ); ?>
						</td>
						<td class="num" <?php echo $neg_class; /* escaped above */ ?>>
							<?php echo esc_html( $neg_display ); ?>
						</td>
						<td class="num"><?php echo esc_html( number_format( $pos_n ) ); ?></td>
						<td class="num" style="<?php echo $neg_n > 0 ? 'color:var(--warn)' : ''; ?>">
							<?php echo esc_html( $neg_n > 0 ? $neg_n : '—' ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- Recipient type breakdown table -->
	<div class="panel corner" style="margin-top:14px">
		<div class="hd">
			<h3>◇ Spending by Recipient Type</h3>
			<span class="meta">ALL TIME PERIODS · SORTED BY TOTAL</span>
		</div>
		<div style="overflow-x:auto">
			<table class="stream">
				<thead>
					<tr>
						<th>Recipient Category</th>
						<th class="num">Total Funding</th>
						<th class="num">Share</th>
						<th style="width:200px">Distribution</th>
					</tr>
				</thead>
				<tbody>
				<?php
				$pt_rec_grand = array_sum( array_column( $pt_rec_types, 1 ) );
				foreach ( $pt_rec_types as $rt ) :
					$share = $pt_rec_grand > 0 ? ( $rt[1] / $pt_rec_grand ) * 100 : 0;
				?>
					<tr>
						<td style="font-weight:500"><?php echo esc_html( $rt[0] ); ?></td>
						<td class="num" style="color:var(--ink-0)"><?php echo esc_html( pt_fmt_m( $rt[1] ) ); ?></td>
						<td class="num" style="color:var(--accent)"><?php echo esc_html( number_format( $share, 1 ) ); ?>%</td>
						<td>
							<div style="height:6px;background:var(--bg-3);border-radius:3px;overflow:hidden">
								<div style="height:100%;width:<?php echo esc_attr( number_format( $share, 2 ) ); ?>%;background:var(--accent);border-radius:3px"></div>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- Program purposes table -->
	<div class="panel corner" style="margin-top:14px;margin-bottom:14px">
		<div class="hd">
			<h3>◇ Top 10 Program Purposes</h3>
			<span class="meta">BY AGREEMENT FREQUENCY · prog_purpose_en</span>
		</div>
		<div style="overflow-x:auto">
			<table class="stream">
				<thead>
					<tr>
						<th>#</th>
						<th>Program Purpose</th>
						<th class="num">Agreement Count</th>
						<th style="width:220px">Relative Frequency</th>
					</tr>
				</thead>
				<tbody>
				<?php
				$pt_purpose_max = $pt_purposes[0][1];
				foreach ( $pt_purposes as $i => $p ) :
					$pct = $pt_purpose_max > 0 ? ( $p[1] / $pt_purpose_max ) * 100 : 0;
				?>
					<tr>
						<td class="mono" style="color:var(--accent);width:32px"><?php echo esc_html( $i + 1 ); ?></td>
						<td><?php echo esc_html( $p[0] ); ?></td>
						<td class="num" style="color:var(--ink-0);font-weight:600"><?php echo esc_html( number_format( $p[1] ) ); ?></td>
						<td>
							<div style="height:6px;background:var(--bg-3);border-radius:3px;overflow:hidden">
								<div style="height:100%;width:<?php echo esc_attr( number_format( $pct, 2 ) ); ?>%;background:var(--pos);border-radius:3px"></div>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

</section>
</main>

<script>
(function () {
	'use strict';
	if (!window.PT || !PT.chart) return;

	/* ── Raw data passed from PHP ───────────────────────────────────────── */
	var yearly = <?php echo wp_json_encode( $pt_yearly ); ?>;
	// Columns: [year, party, total, pos, neg, pos_n, neg_n]

	/* ── Party colour map ───────────────────────────────────────────────── */
	var partyColors = {
		'Conservative': getComputedStyle(document.documentElement).getPropertyValue('--party-con').trim() || '#1a4fa0',
		'Liberal':      getComputedStyle(document.documentElement).getPropertyValue('--party-lib').trim() || '#d6202b'
	};
	var accent = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#00a0d2';
	var pos    = getComputedStyle(document.documentElement).getPropertyValue('--pos').trim()    || '#3ddc97';
	var neg    = getComputedStyle(document.documentElement).getPropertyValue('--neg').trim()    || '#ff6b6b';
	var ink2   = getComputedStyle(document.documentElement).getPropertyValue('--ink-2').trim()  || '#6b7686';

	/* Filter out the 2001 placeholder for trend charts */
	var trend = yearly.filter(function (r) { return r[0] >= 2006; });

	/* ── 1. Spending by Start Year — coloured bar chart ───────────────── */
	var yearData = trend.map(function (r) {
		return {
			label: String(r[0]),
			value: Math.round(r[2] / 1e6 * 10) / 10, // in $M
			color: partyColors[r[1]] || accent
		};
	});
	if (yearData.length) PT.chart('#ptYearChart', 'bar', yearData);

	/* ── 2. Positive vs. Negative line chart ─────────────────────────── */
	var posSeries = trend.map(function (r) { return { x: r[0], y: Math.round(r[3] / 1e6 * 10) / 10 }; });
	var negSeries = trend.filter(function (r) { return r[4] < 0; })
	                     .map(function (r) { return { x: r[0], y: Math.round(r[4] / 1e6 * 100) / 100 }; });

	PT.chart('#ptPosNegChart', 'line', [
		{ key: 'Positive Spend ($M)', values: posSeries },
		{ key: 'Negative Spend ($M)', values: negSeries }
	]);
	document.getElementById('ptPosNegLegend').innerHTML =
		'<span><span class="sw" style="background:' + pos + '"></span>Positive Spend ($M)</span>' +
		'<span><span class="sw" style="background:' + neg + '"></span>Negative Spend ($M)</span>';

	/* ── 3. Agreement volume bar chart ───────────────────────────────── */
	var countData = trend.map(function (r) {
		return { label: String(r[0]), value: r[5], color: partyColors[r[1]] || accent };
	});
	if (countData.length) PT.chart('#ptCountChart', 'bar', countData);

	/* ── 4. Recipient type horizontal bar chart ──────────────────────── */
	var recTypeData = <?php echo wp_json_encode( array_map( function( $r ) {
		return [ 'label' => $r[0], 'value' => round( $r[1] / 1e6, 1 ) ];
	}, $pt_rec_types ) ); ?>;
	if (recTypeData.length) PT.chart('#ptRecTypeChart', 'bar', recTypeData);

	/* ── 5. Program purposes bar chart ───────────────────────────────── */
	var purposeData = <?php echo wp_json_encode( array_map( function( $p ) {
		return [ 'label' => $p[0], 'value' => $p[1] ];
	}, $pt_purposes ) ); ?>;
	if (purposeData.length) PT.chart('#ptPurposeChart', 'bar', purposeData);

	/* ── Sparklines on KPI cards ─────────────────────────────────────── */
	var totalTrend = trend.map(function (r) { return r[2] / 1e6; });
	var countTrend = trend.map(function (r) { return r[5]; });

	if (window.PT && PT.chart) {
		PT.chart(document.getElementById('ptSparkTotal'),      'spark', totalTrend, { color: accent });
		PT.chart(document.getElementById('ptSparkAgreements'), 'spark', countTrend, { color: pos });
		PT.chart(document.getElementById('ptSparkPeak'),       'spark', totalTrend.map(function (v, i) {
			return i === totalTrend.indexOf(Math.max.apply(null, totalTrend)) ? v : v * 0.4;
		}), { color: pos });
	}
})();
</script>

<?php get_footer();
