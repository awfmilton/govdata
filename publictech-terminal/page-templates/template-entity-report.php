<?php
/**
 * Template Name: PT — Entity Funding Report
 *
 * Federal grant & contribution funding report for a single organisation
 * ("entity"). The entity is identified by the `pt_entity_slug` post meta, so the
 * same template serves every organisation page.
 *
 * Data flow:
 *   - The pre-computed JSON snapshot is read through Entity_Reports::cached_snapshot()
 *     which serves a WordPress transient (page-level cache) in front of the
 *     govdata_cache_01 database. The source grantconts DB is NEVER touched on a
 *     page render.
 *   - The in-page search box queries the cache database live via the
 *     /publictech/v1/entity/{slug}/search REST route.
 *
 * Presentation is intentionally neutral and factual. All figures come from the
 * public Government of Canada Grants & Contributions open data; see the
 * disclaimer panel at the foot of the page.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

use PublicTech\Core\Entity_Reports;

/* ─── Resolve the entity ──────────────────────────────────────────────────── */
$pt_post_id = get_queried_object_id();
$pt_slug    = sanitize_title( (string) get_post_meta( $pt_post_id, 'pt_entity_slug', true ) );
if ( '' === $pt_slug ) {
	$pt_slug = sanitize_title( (string) get_post_field( 'post_name', $pt_post_id ) );
}

$pt_entity = class_exists( Entity_Reports::class ) ? Entity_Reports::entity( $pt_slug ) : null;
$pt_snap   = class_exists( Entity_Reports::class ) ? Entity_Reports::cached_snapshot( $pt_slug ) : null;

$pt_name  = $pt_entity['name'] ?? ( $pt_snap['name'] ?? get_the_title( $pt_post_id ) );
$pt_blurb = $pt_entity['blurb'] ?? ( $pt_snap['payload']['blurb'] ?? '' );
$pt_data  = is_array( $pt_snap['payload'] ?? null ) ? $pt_snap['payload'] : [];
$pt_has   = ! empty( $pt_data['totals'] );

$pt_official = 'https://search.open.canada.ca/grants/?search_text=' . rawurlencode( $pt_name );
$pt_updated  = $pt_snap['updated_at'] ?? '';

/* ─── Local money formatter (guarded against the riding-report twin) ───────── */
if ( ! function_exists( 'pt_entity_money' ) ) {
	function pt_entity_money( float $v ): string {
		$abs  = abs( $v );
		$sign = $v < 0 ? '-' : '';
		if ( $abs >= 1_000_000_000 ) {
			return $sign . '$' . number_format( $abs / 1_000_000_000, 2 ) . 'B';
		}
		if ( $abs >= 1_000_000 ) {
			return $sign . '$' . number_format( $abs / 1_000_000, 2 ) . 'M';
		}
		if ( $abs >= 1_000 ) {
			return $sign . '$' . number_format( $abs / 1_000, 1 ) . 'K';
		}
		return $sign . '$' . number_format( $abs );
	}
}

$pt_totals = $pt_data['totals'] ?? [];

get_header(); ?>

<main>
<section class="view active" data-pt-view="entity-report" data-pt-slug="<?php echo esc_attr( $pt_slug ); ?>">

	<!-- ═══ HERO ══════════════════════════════════════════════════════════ -->
	<div class="hero corner" style="margin-bottom:18px">
		<div style="position:relative;z-index:1">
			<div class="mono uc" style="color:var(--accent);font-size:10px;letter-spacing:0.22em;margin-bottom:10px">
				◇ Organisation · Grant &amp; Contribution Funding
			</div>
			<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px">
				<div>
					<h2 style="margin:0 0 6px;font-size:26px;font-weight:700;letter-spacing:0.02em">
						<?php echo esc_html( $pt_name ); ?>
					</h2>
					<?php if ( $pt_blurb ) : ?>
						<div style="color:var(--ink-1);font-size:13px;line-height:1.6;max-width:640px">
							<?php echo esc_html( $pt_blurb ); ?>
						</div>
					<?php endif; ?>
					<div style="color:var(--ink-2);font-size:12px;line-height:1.6;max-width:640px;margin-top:8px">
						Federal grant &amp; contribution agreements matched to this organisation, sourced from
						<a href="<?php echo esc_url( $pt_official ); ?>" target="_blank" rel="noopener nofollow" class="mono" style="color:var(--accent)">search.open.canada.ca/grants</a>.
					</div>
				</div>
				<div style="display:flex;flex-direction:column;gap:6px;text-align:right;flex-shrink:0">
					<div class="mono" style="font-size:10px;color:var(--ink-3)">SLUG · <?php echo esc_html( $pt_slug ); ?></div>
					<?php if ( $pt_has ) : ?>
						<div class="mono" style="font-size:10px;color:var(--ink-3)">AGREEMENTS · <?php echo esc_html( number_format( (int) $pt_totals['agreement_count'] ) ); ?></div>
						<?php if ( ! empty( $pt_totals['first_year'] ) && ! empty( $pt_totals['last_year'] ) ) : ?>
							<div class="mono" style="font-size:10px;color:var(--ink-3)">YEARS · <?php echo esc_html( $pt_totals['first_year'] . '–' . $pt_totals['last_year'] ); ?></div>
						<?php endif; ?>
					<?php endif; ?>
					<?php if ( $pt_updated ) : ?>
						<div class="mono" style="font-size:10px;color:var(--accent)">UPDATED · <?php echo esc_html( gmdate( 'Y-m-d', strtotime( $pt_updated ) ) ); ?></div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<?php if ( ! $pt_has ) : ?>

		<!-- ═══ EMPTY STATE (no snapshot cached yet) ════════════════════════ -->
		<div class="panel corner" style="margin-bottom:14px">
			<div class="hd"><h3>◇ Awaiting first data sync</h3><span class="meta">NO CACHED SNAPSHOT</span></div>
			<div class="bd" style="font-size:13px;color:var(--ink-1);line-height:1.7">
				<p style="margin:0 0 10px">
					This page renders cached data from the <span class="mono" style="color:var(--accent)">govdata_cache_01</span>
					database. No snapshot has been written for <strong><?php echo esc_html( $pt_name ); ?></strong> yet.
				</p>
				<p style="margin:0 0 6px;color:var(--ink-2)">To populate it, run the one-time setup and refresh job:</p>
				<ol class="mono" style="font-size:12px;color:var(--ink-2);line-height:1.8;margin:0 0 10px;padding-left:20px">
					<li>Apply the cache schema: <code>mysql … govdata_cache_01 &lt; database/cache-schema.sql</code></li>
					<li>Define <code>GOVDATA_CACHE_DB1</code> in <code>wp-config.php</code></li>
					<li>Sync: <code>wp eval-file database/refresh-entity-cache.php</code></li>
				</ol>
				<p style="margin:0">
					In the meantime, view this organisation directly on the official portal:
					<a href="<?php echo esc_url( $pt_official ); ?>" target="_blank" rel="noopener nofollow" style="color:var(--accent)">open.canada.ca&nbsp;↗</a>
				</p>
			</div>
		</div>

	<?php else : ?>

		<!-- ═══ KPI STRIP ═══════════════════════════════════════════════════ -->
		<div class="kpis" style="margin-bottom:14px">
			<div class="kpi corner">
				<div class="lab">Total Funding (net)</div>
				<div class="val" style="font-size:20px"><?php echo esc_html( pt_entity_money( (float) $pt_totals['total_funding'] ) ); ?></div>
				<div class="delta dim">All matched agreements</div>
				<svg class="spark" id="ptEntSparkTotal" aria-hidden="true"></svg>
			</div>
			<div class="kpi">
				<div class="lab">Agreements</div>
				<div class="val"><?php echo esc_html( number_format( (int) $pt_totals['agreement_count'] ) ); ?></div>
				<div class="delta dim">Incl. amendments</div>
				<svg class="spark" id="ptEntSparkCount" aria-hidden="true"></svg>
			</div>
			<div class="kpi">
				<div class="lab">Peak Year</div>
				<div class="val"><?php echo esc_html( $pt_totals['peak_year']['year'] ?: '—' ); ?></div>
				<div class="delta pos"><?php echo esc_html( pt_entity_money( (float) $pt_totals['peak_year']['total'] ) ); ?></div>
			</div>
			<div class="kpi">
				<div class="lab">Avg / Year</div>
				<div class="val" style="font-size:18px"><?php echo esc_html( pt_entity_money( (float) $pt_totals['avg_per_year'] ) ); ?></div>
				<div class="delta dim">Largest: <?php echo esc_html( pt_entity_money( (float) $pt_totals['max_agreement'] ) ); ?></div>
			</div>
		</div>

		<!-- ═══ IN-PAGE SEARCH ══════════════════════════════════════════════ -->
		<div class="panel corner" style="margin-bottom:14px">
			<div class="hd">
				<h3>◇ Search this organisation's agreements</h3>
				<span class="meta">LIVE · CACHE DATABASE</span>
			</div>
			<div class="bd">
				<form id="ptEntSearchForm" style="display:flex;gap:8px;flex-wrap:wrap" role="search">
					<input id="ptEntSearchInput" type="search" autocomplete="off"
						placeholder="Search titles, programs, departments, descriptions…"
						aria-label="Search agreements"
						style="flex:1;min-width:240px;background:var(--bg-3);border:1px solid var(--line);color:var(--ink-0);padding:9px 12px;border-radius:6px;font-size:13px" />
					<button type="submit" class="btn" style="flex-shrink:0">Search</button>
				</form>
				<div id="ptEntSearchMeta" class="mono" style="font-size:10px;color:var(--ink-3);margin-top:8px"></div>
				<div id="ptEntSearchResults" style="overflow-x:auto;margin-top:6px"></div>
			</div>
		</div>

		<!-- ═══ MAIN GRID ════════════════════════════════════════════════════ -->
		<div style="display:grid;grid-template-columns:1fr 340px;gap:14px;align-items:start">

			<!-- Left column: charts -->
			<div style="display:flex;flex-direction:column;gap:14px">

				<div class="panel corner">
					<div class="hd"><h3>◇ Funding by Agreement Start Year</h3><span class="meta">NET TOTAL · $M</span></div>
					<div class="bd"><div class="chart-host lg" id="ptEntYearChart" style="height:300px"></div></div>
				</div>

				<div class="panel corner">
					<div class="hd"><h3>◇ Cumulative Funding Over Time</h3><span class="meta">RUNNING NET TOTAL · $M</span></div>
					<div class="bd">
						<div class="chart-host" id="ptEntCumChart" style="height:240px"></div>
						<div class="legend" id="ptEntCumLegend" style="margin-top:8px"></div>
					</div>
				</div>

				<div class="panel corner">
					<div class="hd"><h3>◇ Top Programs / Initiatives</h3><span class="meta">BY FUNDING · $M</span></div>
					<div class="bd">
						<p style="font-size:11px;color:var(--ink-2);margin:0 0 10px;line-height:1.5">
							Funding grouped by the federal program ("initiative") each agreement was made under.
						</p>
						<div class="chart-host" id="ptEntProgramChart" style="height:300px"></div>
					</div>
				</div>

				<div class="panel corner">
					<div class="hd"><h3>◇ Funding by Department</h3><span class="meta">OWNER ORG · $M</span></div>
					<div class="bd"><div class="chart-host" id="ptEntDeptChart" style="height:280px"></div></div>
				</div>

				<?php if ( ! empty( $pt_data['keyword_hits'] ) ) : ?>
				<div class="panel corner">
					<div class="hd"><h3>◇ Funding by Keyword</h3><span class="meta">MENTIONS IN TITLE / PURPOSE · $M</span></div>
					<div class="bd">
						<p style="font-size:11px;color:var(--ink-2);margin:0 0 10px;line-height:1.5">
							Agreement value where the keyword appears in the agreement title, description, or program text.
							Keywords can overlap, so totals are not additive.
						</p>
						<div class="chart-host" id="ptEntKeywordChart" style="height:240px"></div>
					</div>
				</div>
				<?php endif; ?>

			</div><!-- /left -->

			<!-- Right column: sidebar -->
			<div style="display:flex;flex-direction:column;gap:14px">

				<div class="panel corner">
					<div class="hd"><h3>◇ Summary</h3><span class="meta">ROLL-UP</span></div>
					<div class="kvs">
						<div class="kv"><span class="k">Total (net)</span><span class="v" style="color:var(--pos)"><?php echo esc_html( pt_entity_money( (float) $pt_totals['total_funding'] ) ); ?></span></div>
						<div class="kv"><span class="k">Positive</span><span class="v"><?php echo esc_html( pt_entity_money( (float) $pt_totals['positive_funding'] ) ); ?></span></div>
						<div class="kv"><span class="k">Negative / amendments</span><span class="v" style="color:var(--neg)"><?php echo esc_html( pt_entity_money( (float) $pt_totals['negative_funding'] ) ); ?></span></div>
						<div class="kv"><span class="k">Agreements</span><span class="v"><?php echo esc_html( number_format( (int) $pt_totals['agreement_count'] ) ); ?></span></div>
						<div class="kv"><span class="k">Largest agreement</span><span class="v"><?php echo esc_html( pt_entity_money( (float) $pt_totals['max_agreement'] ) ); ?></span></div>
					</div>
				</div>

				<?php if ( ! empty( $pt_data['matched_names'] ) ) : ?>
				<div class="panel corner">
					<div class="hd"><h3>◇ Matched Recipient Names</h3><span class="meta">AS RECORDED</span></div>
					<div class="kvs">
						<?php foreach ( array_slice( (array) $pt_data['matched_names'], 0, 8 ) as $mn ) : ?>
							<div class="kv" style="align-items:flex-start">
								<span class="k" style="flex:1;line-height:1.4;text-transform:none;font-size:10px;padding-right:8px"><?php echo esc_html( $mn['name'] ); ?></span>
								<span class="v" style="flex-shrink:0"><?php echo esc_html( number_format( (int) $mn['count'] ) ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
				<?php endif; ?>

				<div class="panel">
					<div class="hd"><h3>◇ Data Notes</h3><span class="meta">METHODOLOGY</span></div>
					<div class="bd" style="font-size:11px;color:var(--ink-2);line-height:1.6;display:flex;flex-direction:column;gap:8px">
						<p style="margin:0">
							Agreements are matched to this organisation by recipient name against
							<span class="mono" style="color:var(--accent)">grantconts.master_table</span>; near-name matches
							may include or omit edge cases.
						</p>
						<p style="margin:0">
							Totals are <strong style="color:var(--ink-1)">net</strong>: amendment rows carry positive top-ups and
							negative reductions, reported separately above.
						</p>
						<p style="margin:0">
							Figures are drawn from public Government of Canada open data and are provided for transparency.
							This page is not affiliated with or endorsed by the organisation. Verify against the
							<a href="<?php echo esc_url( $pt_official ); ?>" target="_blank" rel="noopener nofollow" style="color:var(--accent)">official portal</a>.
						</p>
					</div>
				</div>

			</div><!-- /right -->
		</div><!-- /grid -->

		<!-- ═══ TABLES ═══════════════════════════════════════════════════════ -->
		<div class="panel corner" style="margin-top:14px">
			<div class="hd"><h3>◇ Annual Funding Breakdown</h3><span class="meta">BY START YEAR</span></div>
			<div style="overflow-x:auto">
				<table class="stream" style="min-width:640px">
					<thead>
						<tr>
							<th>Year</th><th class="num">Net Total</th><th class="num">Positive</th>
							<th class="num">Negative</th><th class="num">+ Agreements</th><th class="num">– Agreements</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( array_reverse( (array) $pt_data['by_year'] ) as $row ) : ?>
						<tr>
							<td class="mono" style="color:var(--accent);font-weight:600"><?php echo esc_html( $row['year'] ); ?></td>
							<td class="num" style="font-weight:600"><?php echo esc_html( pt_entity_money( (float) $row['total'] ) ); ?></td>
							<td class="num" style="color:var(--pos)"><?php echo esc_html( pt_entity_money( (float) $row['positive'] ) ); ?></td>
							<td class="num" <?php echo (float) $row['negative'] < 0 ? 'style="color:var(--neg)"' : ''; ?>><?php echo esc_html( (float) $row['negative'] < 0 ? pt_entity_money( (float) $row['negative'] ) : '—' ); ?></td>
							<td class="num"><?php echo esc_html( number_format( (int) $row['pos_count'] ) ); ?></td>
							<td class="num" style="<?php echo (int) $row['neg_count'] > 0 ? 'color:var(--warn)' : ''; ?>"><?php echo esc_html( (int) $row['neg_count'] > 0 ? $row['neg_count'] : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="panel corner" style="margin-top:14px">
			<div class="hd"><h3>◇ Top Programs / Initiatives</h3><span class="meta">BY FUNDING</span></div>
			<div style="overflow-x:auto">
				<table class="stream">
					<thead><tr><th>#</th><th>Program</th><th class="num">Funding</th><th class="num">Agreements</th><th style="width:200px">Share</th></tr></thead>
					<tbody>
					<?php
					$pt_prog_max = ! empty( $pt_data['by_program'] ) ? (float) $pt_data['by_program'][0]['total'] : 0;
					foreach ( (array) $pt_data['by_program'] as $i => $p ) :
						$pct = $pt_prog_max > 0 ? ( (float) $p['total'] / $pt_prog_max ) * 100 : 0;
					?>
						<tr>
							<td class="mono" style="color:var(--accent);width:32px"><?php echo esc_html( $i + 1 ); ?></td>
							<td><?php echo esc_html( $p['program'] ); ?></td>
							<td class="num" style="font-weight:600"><?php echo esc_html( pt_entity_money( (float) $p['total'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format( (int) $p['count'] ) ); ?></td>
							<td><div style="height:6px;background:var(--bg-3);border-radius:3px;overflow:hidden"><div style="height:100%;width:<?php echo esc_attr( number_format( $pct, 2 ) ); ?>%;background:var(--accent);border-radius:3px"></div></div></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="panel corner" style="margin-top:14px;margin-bottom:14px">
			<div class="hd"><h3>◇ Largest Agreements</h3><span class="meta">TOP 25 BY VALUE</span></div>
			<div style="overflow-x:auto">
				<table class="stream" style="min-width:720px">
					<thead><tr><th>Year</th><th class="num">Value</th><th>Department</th><th>Program</th><th>Title</th></tr></thead>
					<tbody>
					<?php foreach ( (array) $pt_data['top_agreements'] as $a ) : ?>
						<tr>
							<td class="mono" style="color:var(--accent)"><?php echo esc_html( $a['year'] ?: '—' ); ?></td>
							<td class="num" style="font-weight:600"><?php echo esc_html( pt_entity_money( (float) $a['value'] ) ); ?></td>
							<td style="font-size:11px"><?php echo esc_html( $a['department'] ); ?></td>
							<td style="font-size:11px"><?php echo esc_html( $a['program'] ); ?></td>
							<td style="font-size:11px;color:var(--ink-1)"><?php echo esc_html( $a['title'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

	<?php endif; /* $pt_has */ ?>

</section>
</main>

<script>
(function () {
	'use strict';
	var SLUG = <?php echo wp_json_encode( $pt_slug ); ?>;
	var DATA = <?php echo wp_json_encode( $pt_data ); ?>;
	var boot = (window.PT && PT.boot) || {};

	/* ── Charts ───────────────────────────────────────────────────────────── */
	function ptEntDrawCharts() {
		if (!(window.PT && PT.chart && DATA && DATA.totals)) { return; }
		var accent = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#00a0d2';
		var pos    = getComputedStyle(document.documentElement).getPropertyValue('--pos').trim()    || '#3ddc97';
		var ink2   = getComputedStyle(document.documentElement).getPropertyValue('--ink-2').trim()  || '#6b7686';

		var byYear = DATA.by_year || [];
		var toM = function (n) { return Math.round((+n / 1e6) * 100) / 100; };
		var clip = function (s, n) { s = String(s || ''); return s.length > n ? s.slice(0, n - 1) + '…' : s; };

		// Funding by year (bar).
		var yearData = byYear.map(function (r) { return { label: String(r.year), value: toM(r.total), color: accent }; });
		if (yearData.length) PT.chart('#ptEntYearChart', 'bar', yearData);

		// Cumulative + annual line.
		var cum = 0;
		var cumSeries = byYear.map(function (r) { cum += +r.total; return { x: r.year, y: toM(cum) }; });
		var annSeries = byYear.map(function (r) { return { x: r.year, y: toM(r.total) }; });
		PT.chart('#ptEntCumChart', 'line', [
			{ key: 'Cumulative ($M)', values: cumSeries },
			{ key: 'Annual ($M)',     values: annSeries }
		]);
		var legend = document.getElementById('ptEntCumLegend');
		if (legend) {
			legend.innerHTML =
				'<span><span class="sw" style="background:' + accent + '"></span>Cumulative ($M)</span>' +
				'<span><span class="sw" style="background:' + pos + '"></span>Annual ($M)</span>';
		}

		// Top programs (bar, top 10).
		var progData = (DATA.by_program || []).slice(0, 10).map(function (p) { return { label: clip(p.program, 28), value: toM(p.total) }; });
		if (progData.length) PT.chart('#ptEntProgramChart', 'bar', progData);

		// Departments (bar).
		var deptData = (DATA.by_department || []).map(function (d) { return { label: clip(d.department, 28), value: toM(d.total) }; });
		if (deptData.length) PT.chart('#ptEntDeptChart', 'bar', deptData);

		// Keywords (bar).
		var kwData = (DATA.keyword_hits || []).filter(function (k) { return +k.total > 0; })
			.map(function (k) { return { label: k.keyword, value: toM(k.total) }; });
		if (kwData.length) PT.chart('#ptEntKeywordChart', 'bar', kwData);

		// Sparklines.
		var trend = byYear.map(function (r) { return +r.total / 1e6; });
		var counts = byYear.map(function (r) { return (+r.pos_count) + (+r.neg_count); });
		PT.chart(document.getElementById('ptEntSparkTotal'), 'spark', trend, { color: accent });
		PT.chart(document.getElementById('ptEntSparkCount'), 'spark', counts, { color: pos });
	}

	// Defer chart rendering until window.PT (the chart library) is available.
	// This inline script runs in the page body, BEFORE the footer-enqueued
	// publictech-charts script, so a one-time guard skipped every chart. Draw
	// now if ready, otherwise on DOMContentLoaded, otherwise poll briefly.
	(function ptEntChartsReady() {
		if (window.PT && PT.chart) { ptEntDrawCharts(); return; }
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', ptEntChartsReady);
			return;
		}
		var tries = 0;
		var iv = setInterval(function () {
			if (window.PT && PT.chart) { clearInterval(iv); ptEntDrawCharts(); }
			else if (++tries > 100) { clearInterval(iv); }
		}, 50);
	})();

	/* ── In-page live search → cache database via REST ──────────────────────── */
	var form = document.getElementById('ptEntSearchForm');
	if (form && boot.rest) {
		var input   = document.getElementById('ptEntSearchInput');
		var metaEl  = document.getElementById('ptEntSearchMeta');
		var results = document.getElementById('ptEntSearchResults');

		var esc = function (s) {
			return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
		};
		var money = function (v) {
			v = +v || 0; var a = Math.abs(v), s = v < 0 ? '-' : '';
			if (a >= 1e9) return s + '$' + (a / 1e9).toFixed(2) + 'B';
			if (a >= 1e6) return s + '$' + (a / 1e6).toFixed(2) + 'M';
			if (a >= 1e3) return s + '$' + (a / 1e3).toFixed(1) + 'K';
			return s + '$' + a.toLocaleString();
		};

		var render = function (rows) {
			if (!rows.length) { results.innerHTML = '<p style="color:var(--ink-3);font-size:12px;padding:8px 0">No matching agreements.</p>'; return; }
			var html = '<table class="stream" style="min-width:680px"><thead><tr>' +
				'<th>Year</th><th class="num">Value</th><th>Department</th><th>Program</th><th>Title</th></tr></thead><tbody>';
			rows.forEach(function (r) {
				html += '<tr>' +
					'<td class="mono" style="color:var(--accent)">' + esc(r.year || '—') + '</td>' +
					'<td class="num" style="font-weight:600">' + money(r.agreement_value) + '</td>' +
					'<td style="font-size:11px">' + esc(r.owner_org || '') + '</td>' +
					'<td style="font-size:11px">' + esc(r.prog_name || '') + '</td>' +
					'<td style="font-size:11px;color:var(--ink-1)">' + esc(r.agreement_title || '') + '</td>' +
					'</tr>';
			});
			html += '</tbody></table>';
			results.innerHTML = html;
		};

		var run = function (q) {
			metaEl.textContent = 'Querying cache database…';
			var url = new URL(boot.rest + '/entity/' + encodeURIComponent(SLUG) + '/search');
			url.searchParams.set('q', q);
			url.searchParams.set('limit', '50');
			fetch(url.toString(), { credentials: 'same-origin', headers: { 'x-wp-nonce': boot.nonce || '' } })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					var rows = (data && data.rows) || [];
					metaEl.textContent = rows.length + ' result' + (rows.length === 1 ? '' : 's') +
						(q ? ' for “' + q + '”' : ' (largest agreements)');
					render(rows);
				})
				.catch(function () { metaEl.textContent = 'Search is temporarily unavailable.'; });
		};

		form.addEventListener('submit', function (e) { e.preventDefault(); run(input.value.trim()); });
		run(''); // Prime with the largest agreements.
	}
})();
</script>

<?php get_footer();
