<?php
/**
 * Template Name: PT — Riding Report
 *
 * Grant and contribution spending report for a federal riding. Which riding is
 * resolved from post meta — `pt_riding_number` (preferred) or `pt_riding_slug`
 * — so the same template serves every riding page.
 *
 * All figures are read from the SECONDARY cache database (pt-cache-02) via
 * {@see \PublicTech\Core\Riding_Cache}; this template never queries the primary
 * grantconts instance. The cache is populated by the riding-cache warmer
 * (weekly cron, or database/warm-riding-cache.php).
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

use function PublicTech\Core\pt_format_money;
use function PublicTech\Core\pt_format_money_short;

/* ─── Resolve the riding + load its cached report ──────────────────────────── */
$pt_post_id = get_queried_object_id();
$pt_number  = (string) get_post_meta( $pt_post_id, 'pt_riding_number', true );
$pt_slug    = (string) get_post_meta( $pt_post_id, 'pt_riding_slug',   true );

$pt_payload = null;
if ( class_exists( \PublicTech\Core\Riding_Cache::class ) ) {
	$pt_rc = \PublicTech\Core\Riding_Cache::instance();
	if ( '' !== $pt_number ) {
		$pt_payload = $pt_rc->report_by_number( $pt_number );
	}
	if ( ! $pt_payload && '' !== $pt_slug ) {
		$pt_payload = $pt_rc->report_by_slug( $pt_slug );
	}
	// Seeded sample page with no meta set → Thunder Bay–Rainy River.
	if ( ! $pt_payload && '' === $pt_number && '' === $pt_slug ) {
		$pt_payload = $pt_rc->report_by_slug( 'Thunder Bay–Rainy River' );
	}
}

$pt_riding_name = (string) ( $pt_payload['riding']['name_en'] ?? '' );
if ( '' === $pt_riding_name ) {
	$pt_riding_name = (string) get_post_meta( $pt_post_id, 'pt_riding_name', true );
}
if ( '' === $pt_riding_name ) {
	$pt_riding_name = get_the_title( $pt_post_id ) ?: __( 'Riding Report', 'publictech-terminal' );
}

/* ─── Empty state — cache miss or warmer hasn't run yet ────────────────────── */
if ( ! is_array( $pt_payload ) || empty( $pt_payload['totals'] ) ) {
	get_header(); ?>
	<main id="pt-main" tabindex="-1">
	<section class="view active" data-pt-view="riding-report">
		<div class="hero corner" style="margin-bottom:18px">
			<div style="position:relative;z-index:1">
				<div class="mono uc" style="color:var(--accent);font-size:10px;letter-spacing:0.22em;margin-bottom:10px">◇ Federal Riding · Grant &amp; Contribution Analysis</div>
				<h2 style="margin:0 0 6px;font-size:26px;font-weight:700"><?php echo esc_html( $pt_riding_name ); ?></h2>
			</div>
		</div>
		<div class="panel corner">
			<div class="hd"><h3>◇ <?php esc_html_e( 'Report being compiled', 'publictech-terminal' ); ?></h3><span class="meta">CACHE</span></div>
			<div class="bd" style="font-size:13px;color:var(--ink-2);line-height:1.7">
				<?php esc_html_e( 'The grant and contribution rollups for this riding have not been cached yet. They are generated from the federal Grants &amp; Contributions database on a weekly schedule. Please check back shortly.', 'publictech-terminal' ); ?>
			</div>
		</div>
	</section>
	</main>
	<?php
	get_footer();
	return;
}

/* ─── Shape the cached payload into the arrays the markup + charts expect ───── */
$pt_totals     = (array) $pt_payload['totals'];
$pt_riding      = (array) ( $pt_payload['riding'] ?? [] );
$pt_province    = (string) ( $pt_riding['province'] ?? '' );
$pt_report_date = $pt_payload['generated_at'] ? gmdate( 'M Y', strtotime( (string) $pt_payload['generated_at'] ) ) : gmdate( 'M Y' );
$pt_pc_count    = (int) ( $pt_riding['postal_code_count'] ?? 0 );
$pt_first_year  = (int) ( $pt_totals['first_year'] ?? 0 );
$pt_last_year   = (int) ( $pt_totals['last_year'] ?? 0 );
$pt_span        = $pt_first_year && $pt_last_year ? ( $pt_first_year . '–' . $pt_last_year ) : __( 'all years', 'publictech-terminal' );

// Yearly rows: [year, party, total, positive, negative, +agreements, −agreements].
$pt_yearly = [];
foreach ( (array) ( $pt_payload['by_year'] ?? [] ) as $row ) {
	$pt_yearly[] = [
		(int) ( $row['year'] ?? 0 ),
		(string) ( $row['party'] ?? 'Liberal' ),
		(float) ( $row['total'] ?? 0 ),
		(float) ( $row['positive_total'] ?? 0 ),
		(float) ( $row['negative_total'] ?? 0 ),
		(int) ( $row['positive_agreements'] ?? 0 ),
		(int) ( $row['negative_agreements'] ?? 0 ),
	];
}

// Top recipients: [name, total].
$pt_recipients = [];
foreach ( (array) ( $pt_payload['top_recipients'] ?? [] ) as $row ) {
	$pt_recipients[] = [ (string) ( $row['name'] ?? '' ), (float) ( $row['total'] ?? 0 ) ];
}

// Recipient type split: [label, total].
$pt_rec_types = [];
foreach ( (array) ( $pt_payload['by_recipient_type'] ?? [] ) as $row ) {
	$pt_rec_types[] = [ (string) ( $row['label'] ?? '' ), (float) ( $row['total'] ?? 0 ) ];
}

// Top programs: [name, agreement count].
$pt_purposes = [];
foreach ( (array) ( $pt_payload['top_programs'] ?? [] ) as $row ) {
	$pt_purposes[] = [ (string) ( $row['name'] ?? '' ), (int) ( $row['agreements'] ?? 0 ) ];
}

// Keyword scan (only matches): [label, total, agreements].
$pt_keywords = [];
foreach ( (array) ( $pt_payload['by_keyword'] ?? [] ) as $row ) {
	if ( (int) ( $row['agreements'] ?? 0 ) > 0 ) {
		$pt_keywords[] = [ (string) ( $row['label'] ?? '' ), (float) ( $row['total'] ?? 0 ), (int) ( $row['agreements'] ?? 0 ) ];
	}
}

/* ─── KPI + era roll-ups ───────────────────────────────────────────────────── */
$pt_total_spend      = (float) $pt_totals['total_value'];
$pt_total_agreements = (int) $pt_totals['agreement_count'];
$pt_peak_year        = (int) ( $pt_totals['peak_year'] ?? 0 );
$pt_peak_total       = (float) ( $pt_totals['peak_year_total'] ?? 0 );
$pt_top_rec_type     = (string) ( $pt_totals['top_recipient_type'] ?? '' );

$pt_con_total = 0.0;
$pt_lib_total = 0.0;
$pt_con_ag    = 0;
$pt_lib_ag    = 0;
$pt_con_years = [];
$pt_lib_years = [];
foreach ( $pt_yearly as $row ) {
	[ $yr, $party, $total, $pos, $neg, $pos_n, $neg_n ] = $row;
	if ( 'Conservative' === $party ) {
		$pt_con_total += $total;
		$pt_con_ag    += $pos_n + $neg_n;
		$pt_con_years[ $yr ] = 1;
	} else {
		$pt_lib_total += $total;
		$pt_lib_ag    += $pos_n + $neg_n;
		$pt_lib_years[ $yr ] = 1;
	}
}
$pt_rec_grand = array_sum( array_column( $pt_rec_types, 1 ) );

get_header(); ?>

<main id="pt-main" tabindex="-1">
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
						this riding, sourced from <span class="mono" style="color:var(--accent)">search.open.canada.ca/grants</span>.
						Agreements are linked via the Postal Code Federal Riding File (PCFRF).
						Data covers <?php echo esc_html( $pt_span ); ?> agreement start years.
					</div>
				</div>
				<div style="display:flex;flex-direction:column;gap:6px;text-align:right;flex-shrink:0">
					<?php if ( $pt_province ) : ?>
						<div class="mono" style="font-size:10px;color:var(--ink-3)">PROVINCE · <?php echo esc_html( $pt_province ); ?></div>
					<?php endif; ?>
					<?php if ( ! empty( $pt_riding['number'] ) ) : ?>
						<div class="mono" style="font-size:10px;color:var(--ink-3)">FED · <?php echo esc_html( (string) $pt_riding['number'] ); ?></div>
					<?php endif; ?>
					<div class="mono" style="font-size:10px;color:var(--ink-3)">REPORT · <?php echo esc_html( $pt_report_date ); ?></div>
					<div class="mono" style="font-size:10px;color:var(--accent)">POSTAL CODES · <?php echo esc_html( number_format( $pt_pc_count ) ); ?></div>
				</div>
			</div>
		</div>
	</div>

	<!-- ═══ KPI STRIP ═════════════════════════════════════════════════════ -->
	<div class="kpis" style="margin-bottom:14px">
		<div class="kpi corner">
			<div class="lab">Total Spend (<?php echo esc_html( $pt_span ); ?>)</div>
			<div class="val" style="font-size:20px"><?php echo esc_html( pt_format_money_short( $pt_total_spend ) ); ?></div>
			<div class="delta dim">Since data began</div>
			<svg class="spark" id="ptSparkTotal" aria-hidden="true"></svg>
		</div>
		<div class="kpi">
			<div class="lab">Total Agreements</div>
			<div class="val"><?php echo esc_html( number_format( $pt_total_agreements ) ); ?></div>
			<div class="delta dim">All recipients</div>
			<svg class="spark" id="ptSparkAgreements" aria-hidden="true"></svg>
		</div>
		<div class="kpi">
			<div class="lab">Peak Year</div>
			<div class="val"><?php echo esc_html( $pt_peak_year ?: '—' ); ?></div>
			<div class="delta pos"><?php echo esc_html( pt_format_money_short( $pt_peak_total ) ); ?></div>
			<svg class="spark" id="ptSparkPeak" aria-hidden="true"></svg>
		</div>
		<div class="kpi">
			<div class="lab">Top Recipient Category</div>
			<div class="val" style="font-size:14px;margin-top:12px;letter-spacing:0"><?php echo esc_html( $pt_top_rec_type ?: '—' ); ?></div>
			<?php if ( $pt_rec_grand > 0 && $pt_rec_types ) : ?>
				<div class="delta pos"><?php echo esc_html( pt_format_money_short( (float) $pt_rec_types[0][1] ) . ' · ' . number_format( ( $pt_rec_types[0][1] / $pt_rec_grand ) * 100, 0 ) . '%' ); ?></div>
			<?php endif; ?>
		</div>
	</div>

	<!-- ═══ MAIN GRID ══════════════════════════════════════════════════════ -->
	<div style="display:grid;grid-template-columns:1fr 340px;gap:14px;align-items:start">

		<!-- Left column: charts -->
		<div style="display:flex;flex-direction:column;gap:14px">

			<div class="panel corner">
				<div class="hd">
					<h3>◇ Spending by Agreement Start Year</h3>
					<span class="meta"><?php echo esc_html( $pt_span ); ?> · NET TOTAL</span>
				</div>
				<div class="bd">
					<div style="display:flex;gap:14px;margin-bottom:10px;font-family:'JetBrains Mono';font-size:10px">
						<span><span style="display:inline-block;width:10px;height:10px;background:var(--party-con);border-radius:2px;margin-right:5px;vertical-align:middle"></span>Conservative era (2006–2014)</span>
						<span><span style="display:inline-block;width:10px;height:10px;background:var(--party-lib);border-radius:2px;margin-right:5px;vertical-align:middle"></span>Liberal era (2015+)</span>
					</div>
					<div class="chart-host lg" id="ptYearChart" style="height:320px"></div>
				</div>
			</div>

			<div class="panel corner">
				<div class="hd">
					<h3>◇ Positive vs. Negative Spending Over Time</h3>
					<span class="meta">AMENDMENTS &amp; CANCELLATIONS</span>
				</div>
				<div class="bd">
					<p style="font-size:11px;color:var(--ink-2);margin:0 0 10px;line-height:1.5">
						Negative values represent funding reductions, amendments, or cancellations recorded against the riding.
					</p>
					<div class="chart-host" id="ptPosNegChart" style="height:260px"></div>
					<div class="legend" id="ptPosNegLegend" style="margin-top:8px"></div>
				</div>
			</div>

			<div class="panel corner">
				<div class="hd">
					<h3>◇ Agreement Volume by Start Year</h3>
					<span class="meta">POSITIVE AGREEMENTS · COUNT</span>
				</div>
				<div class="bd">
					<div class="chart-host" id="ptCountChart" style="height:240px"></div>
				</div>
			</div>

			<div class="panel corner">
				<div class="hd">
					<h3>◇ Spending Distribution by Recipient Type</h3>
					<span class="meta">ALL TIME PERIODS</span>
				</div>
				<div class="bd">
					<div class="chart-host" id="ptRecTypeChart" style="height:280px"></div>
				</div>
			</div>

			<div class="panel corner">
				<div class="hd">
					<h3>◇ Top 10 Programs</h3>
					<span class="meta">BY AGREEMENT COUNT · prog_name_en</span>
				</div>
				<div class="bd">
					<div class="chart-host" id="ptPurposeChart" style="height:300px"></div>
				</div>
			</div>

		</div><!-- /left column -->

		<!-- Right column: sidebar panels -->
		<div style="display:flex;flex-direction:column;gap:14px">

			<div class="panel corner">
				<div class="hd">
					<h3>◇ Era Comparison</h3>
					<span class="meta">BY GOVERNING PARTY</span>
				</div>
				<div class="kvs">
					<div class="kv">
						<span class="k" style="color:var(--party-con)">Conservative Era</span>
						<span class="v" style="font-size:10px;color:var(--ink-2)">2006–2014</span>
					</div>
					<div class="kv"><span class="k">Total Spend</span><span class="v"><?php echo esc_html( pt_format_money_short( $pt_con_total ) ); ?></span></div>
					<div class="kv"><span class="k">Agreements</span><span class="v"><?php echo esc_html( number_format( $pt_con_ag ) ); ?></span></div>
					<div class="kv"><span class="k">Avg / Year</span><span class="v"><?php echo esc_html( pt_format_money_short( $pt_con_total / max( count( $pt_con_years ), 1 ) ) ); ?></span></div>
					<div class="kv" style="background:rgba(0,0,0,0.1)">
						<span class="k" style="color:var(--party-lib)">Liberal Era</span>
						<span class="v" style="font-size:10px;color:var(--ink-2)">2015+</span>
					</div>
					<div class="kv"><span class="k">Total Spend</span><span class="v"><?php echo esc_html( pt_format_money_short( $pt_lib_total ) ); ?></span></div>
					<div class="kv"><span class="k">Agreements</span><span class="v"><?php echo esc_html( number_format( $pt_lib_ag ) ); ?></span></div>
					<div class="kv"><span class="k">Avg / Year</span><span class="v"><?php echo esc_html( pt_format_money_short( $pt_lib_total / max( count( $pt_lib_years ), 1 ) ) ); ?></span></div>
					<?php if ( $pt_con_total > 0 ) : ?>
						<div class="kv">
							<span class="k" style="color:var(--pos)">Liberal vs. Con Ratio</span>
							<span class="v" style="color:var(--pos)"><?php echo esc_html( number_format( $pt_lib_total / max( $pt_con_total, 1 ), 1 ) ); ?>×</span>
						</div>
					<?php endif; ?>
				</div>
			</div>

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
							<span class="v" style="color:var(--pos);flex-shrink:0"><?php echo esc_html( pt_format_money_short( (float) $rec[1] ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="panel">
				<div class="hd"><h3>◇ Data Notes</h3><span class="meta">METHODOLOGY</span></div>
				<div class="bd" style="font-size:11px;color:var(--ink-2);line-height:1.6;display:flex;flex-direction:column;gap:8px">
					<p style="margin:0">
						Agreements are linked to the riding via the
						<strong style="color:var(--ink-1)">Postal Code Federal Riding File (PCFRF)</strong> —
						a recipient postal code is matched to this riding's postal codes. A postal code
						that spans two ridings contributes to both.
					</p>
					<p style="margin:0">
						Figures are precomputed and served from the cache database
						<span class="mono" style="color:var(--accent)">pt-cache-02.riding_cache</span>,
						refreshed weekly from <span class="mono" style="color:var(--accent)">grantconts.master_table</span>.
					</p>
					<p style="margin:0">Generated <?php echo esc_html( $pt_report_date ); ?>.</p>
				</div>
			</div>

		</div><!-- /right column -->
	</div><!-- /main grid -->

	<!-- ═══ KEYWORD SCAN ═══════════════════════════════════════════════════ -->
	<div class="panel corner" style="margin-top:14px">
		<div class="hd">
			<h3>◇ Spending by Tracked Keyword / Phrase</h3>
			<span class="meta">SCRUTINY KEYWORDS · recipient / title / program / description</span>
		</div>
		<div class="bd">
			<?php if ( $pt_keywords ) : ?>
				<p style="font-size:11px;color:var(--ink-2);margin:0 0 10px;line-height:1.5">
					Funding in this riding whose recipient, agreement title, program, or description text
					matches each frequently-scrutinised keyword. A single agreement can match more than one keyword.
				</p>
				<div class="chart-host" id="ptKeywordChart" style="height:300px;margin-bottom:12px"></div>
				<div style="overflow-x:auto">
					<table class="stream">
						<thead>
							<tr>
								<th>#</th>
								<th>Keyword / Phrase</th>
								<th class="num">Total Funding</th>
								<th class="num">Agreements</th>
								<th style="width:200px">Relative Funding</th>
							</tr>
						</thead>
						<tbody>
						<?php
						$pt_kw_max = (float) ( $pt_keywords[0][1] ?? 0 );
						foreach ( $pt_keywords as $i => $kw ) :
							$pct = $pt_kw_max > 0 ? ( $kw[1] / $pt_kw_max ) * 100 : 0;
							?>
							<tr>
								<td class="mono" style="color:var(--accent);width:32px"><?php echo esc_html( $i + 1 ); ?></td>
								<td style="font-weight:500"><?php echo esc_html( $kw[0] ); ?></td>
								<td class="num" style="color:var(--ink-0)"><?php echo esc_html( pt_format_money( (float) $kw[1] ) ); ?></td>
								<td class="num" style="color:var(--accent)"><?php echo esc_html( number_format( $kw[2] ) ); ?></td>
								<td>
									<div style="height:6px;background:var(--bg-3);border-radius:3px;overflow:hidden">
										<div style="height:100%;width:<?php echo esc_attr( number_format( $pct, 2 ) ); ?>%;background:var(--warn);border-radius:3px"></div>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<p style="font-size:11px;color:var(--ink-2);margin:0">No agreements in this riding match the tracked scrutiny keywords.</p>
			<?php endif; ?>
		</div>
	</div>

	<!-- ═══ ANNUAL TABLE ════════════════════════════════════════════════════ -->
	<div class="panel corner" style="margin-top:14px;margin-bottom:14px">
		<div class="hd">
			<h3>◇ Annual Spending Breakdown — All Years</h3>
			<span class="meta">SOURCE: riding_cache · by_year</span>
		</div>
		<div style="overflow-x:auto">
			<table class="stream" style="min-width:680px">
				<thead>
					<tr>
						<th>Year</th>
						<th>Era</th>
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
					$party_color = 'Liberal' === $party ? 'var(--party-lib)' : 'var(--party-con)';
					$neg_display = $neg < 0 ? pt_format_money( $neg ) : '—';
					?>
					<tr>
						<td class="mono" style="color:var(--accent);font-weight:600"><?php echo esc_html( $yr ); ?></td>
						<td>
							<span class="tag" style="color:<?php echo esc_attr( $party_color ); ?>;border-color:<?php echo esc_attr( $party_color ); ?>">
								<?php echo esc_html( strtoupper( substr( $party, 0, 3 ) ) ); ?>
							</span>
						</td>
						<td class="num" style="color:var(--ink-0);font-weight:600"><?php echo esc_html( pt_format_money( $total ) ); ?></td>
						<td class="num" style="color:var(--pos)"><?php echo esc_html( pt_format_money( $pos ) ); ?></td>
						<td class="num" <?php echo $neg < 0 ? 'style="color:var(--neg)"' : ''; ?>><?php echo esc_html( $neg_display ); ?></td>
						<td class="num"><?php echo esc_html( number_format( $pos_n ) ); ?></td>
						<td class="num" style="<?php echo $neg_n > 0 ? 'color:var(--warn)' : ''; ?>"><?php echo esc_html( $neg_n > 0 ? $neg_n : '—' ); ?></td>
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
	if (!window.PT || !PT.chart) { return; }

	var yearly   = <?php echo wp_json_encode( $pt_yearly ); ?>;     // [year, party, total, pos, neg, pos_n, neg_n]
	var recTypes = <?php echo wp_json_encode( $pt_rec_types ); ?>;  // [label, total]
	var purposes = <?php echo wp_json_encode( $pt_purposes ); ?>;   // [name, count]
	var keywords = <?php echo wp_json_encode( $pt_keywords ); ?>;   // [label, total, agreements]

	var css    = getComputedStyle(document.documentElement);
	var accent = css.getPropertyValue('--accent').trim() || '#00a0d2';
	var pos    = css.getPropertyValue('--pos').trim()    || '#3ddc97';
	var neg    = css.getPropertyValue('--neg').trim()    || '#ff6b6b';
	var warn   = css.getPropertyValue('--warn').trim()   || '#f5a623';
	var partyColors = {
		Conservative: css.getPropertyValue('--party-con').trim() || '#1a4fa0',
		Liberal:      css.getPropertyValue('--party-lib').trim() || '#d6202b'
	};

	/* 1. Spending by start year (coloured bar, $M). */
	var yearData = yearly.map(function (r) {
		return { label: String(r[0]), value: Math.round(r[2] / 1e6 * 10) / 10, color: partyColors[r[1]] || accent };
	});
	if (yearData.length) { PT.chart('#ptYearChart', 'bar', yearData); }

	/* 2. Positive vs. negative line chart ($M). */
	var posSeries = yearly.map(function (r) { return { x: r[0], y: Math.round(r[3] / 1e6 * 10) / 10 }; });
	var negSeries = yearly.filter(function (r) { return r[4] < 0; })
	                      .map(function (r) { return { x: r[0], y: Math.round(r[4] / 1e6 * 100) / 100 }; });
	PT.chart('#ptPosNegChart', 'line', [
		{ key: 'Positive Spend ($M)', values: posSeries },
		{ key: 'Negative Spend ($M)', values: negSeries }
	]);
	var legend = document.getElementById('ptPosNegLegend');
	if (legend) {
		legend.innerHTML =
			'<span><span class="sw" style="background:' + pos + '"></span>Positive Spend ($M)</span>' +
			'<span><span class="sw" style="background:' + neg + '"></span>Negative Spend ($M)</span>';
	}

	/* 3. Agreement volume bar chart. */
	var countData = yearly.map(function (r) {
		return { label: String(r[0]), value: r[5], color: partyColors[r[1]] || accent };
	});
	if (countData.length) { PT.chart('#ptCountChart', 'bar', countData); }

	/* 4. Recipient type horizontal bar chart ($M). */
	var recTypeData = recTypes.map(function (r) { return { label: r[0], value: Math.round(r[1] / 1e6 * 10) / 10 }; });
	if (recTypeData.length) { PT.chart('#ptRecTypeChart', 'bar', recTypeData); }

	/* 5. Top programs bar chart (count). */
	var purposeData = purposes.map(function (p) { return { label: p[0], value: p[1] }; });
	if (purposeData.length) { PT.chart('#ptPurposeChart', 'bar', purposeData); }

	/* 6. Keyword funding bar chart ($). */
	var kwData = keywords.map(function (k) { return { label: k[0], value: Math.round(k[1]), color: warn }; });
	if (kwData.length) { PT.chart('#ptKeywordChart', 'bar', kwData); }

	/* Sparklines. */
	var totalTrend = yearly.map(function (r) { return r[2] / 1e6; });
	var countTrend = yearly.map(function (r) { return r[5]; });
	if (totalTrend.length) {
		PT.chart(document.getElementById('ptSparkTotal'),      'spark', totalTrend, { color: accent });
		PT.chart(document.getElementById('ptSparkAgreements'), 'spark', countTrend, { color: pos });
		PT.chart(document.getElementById('ptSparkPeak'),       'spark', totalTrend, { color: pos });
	}
})();
</script>

<?php get_footer();
