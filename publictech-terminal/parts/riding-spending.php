<?php
/**
 * Riding spending section — reusable part for the MP profile.
 *
 * Renders the federal grant & contribution rollups for a member's riding from
 * the precomputed cache payload. Templated: every MP page includes this same
 * part; only the data differs.
 *
 * Expects the including template to set:
 *   $pt_riding_payload  array|null  Decoded cache payload (Riding_Cache::report_for_post()).
 *   $pt_riding_label    string      Riding display name for the heading.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

/** @var array<string,mixed>|null $pt_riding_payload */
$pt_riding_payload = $pt_riding_payload ?? null;
/** @var string $pt_riding_label */
$pt_riding_label = isset( $pt_riding_label ) ? (string) $pt_riding_label : '';

// No cache row yet (riding unresolved, or the warmer hasn't run). Show a calm
// placeholder rather than empty space or a fatal.
if ( ! is_array( $pt_riding_payload ) || empty( $pt_riding_payload['totals'] ) ) :
	?>
	<div class="panel corner" style="margin-top:18px">
		<div class="hd"><h3>◇ <?php esc_html_e( 'Riding Grants &amp; Contributions', 'publictech-terminal' ); ?></h3><span class="meta">CACHE</span></div>
		<div class="bd" style="font-size:12px;color:var(--ink-2);line-height:1.6">
			<?php esc_html_e( 'Federal grant &amp; contribution spending for this riding is being compiled and will appear here shortly.', 'publictech-terminal' ); ?>
		</div>
	</div>
	<?php
	return;
endif;

$pt_totals     = (array) $pt_riding_payload['totals'];
$pt_by_year    = (array) ( $pt_riding_payload['by_year'] ?? [] );
$pt_recipients = (array) ( $pt_riding_payload['top_recipients'] ?? [] );
$pt_keywords   = array_values( array_filter(
	(array) ( $pt_riding_payload['by_keyword'] ?? [] ),
	static fn( $k ) => ( (int) ( $k['agreements'] ?? 0 ) ) > 0
) );
$pt_first_year = (int) ( $pt_totals['first_year'] ?? 0 );
$pt_last_year  = (int) ( $pt_totals['last_year'] ?? 0 );
$pt_span       = $pt_first_year && $pt_last_year ? ( $pt_first_year . '–' . $pt_last_year ) : __( 'all years', 'publictech-terminal' );

// Year rows for the chart: [year, party, $millions].
$pt_year_js = [];
foreach ( $pt_by_year as $row ) {
	$pt_year_js[] = [
		(int) ( $row['year'] ?? 0 ),
		(string) ( $row['party'] ?? 'Liberal' ),
		round( ( (float) ( $row['total'] ?? 0 ) ) / 1_000_000, 2 ),
	];
}
// Keyword rows for the chart: [label, total dollars].
$pt_kw_js = [];
foreach ( $pt_keywords as $row ) {
	$pt_kw_js[] = [ (string) ( $row['label'] ?? '' ), round( (float) ( $row['total'] ?? 0 ) ) ];
}
?>

<section class="pt-riding-spending" aria-label="<?php esc_attr_e( 'Riding grant and contribution spending', 'publictech-terminal' ); ?>" style="margin-top:18px">

	<div class="panel corner" style="margin-bottom:14px">
		<div class="hd">
			<h3>◇ <?php
				/* translators: %s: riding name */
				printf( esc_html__( 'Federal Grants &amp; Contributions — %s', 'publictech-terminal' ), esc_html( $pt_riding_label ) );
			?></h3>
			<span class="meta">CACHE · pt-cache-02</span>
		</div>
		<div class="bd" style="font-size:12px;color:var(--ink-2);line-height:1.6">
			<?php
			printf(
				/* translators: 1: postal-code count, 2: year span */
				esc_html__( 'Grant and contribution agreements attributed to this riding via the Postal Code Federal Riding File. Covers %2$s agreement years.', 'publictech-terminal' ),
				(int) ( $pt_riding_payload['riding']['postal_code_count'] ?? 0 ),
				esc_html( $pt_span )
			);
			?>
		</div>
	</div>

	<!-- KPI strip -->
	<div class="kpis" style="margin-bottom:14px">
		<div class="kpi corner">
			<div class="lab"><?php esc_html_e( 'Total Spend (all years)', 'publictech-terminal' ); ?></div>
			<div class="val" style="font-size:20px"><?php echo esc_html( \PublicTech\Core\pt_format_money_short( (float) $pt_totals['total_value'] ) ); ?></div>
			<div class="delta dim"><?php echo esc_html( $pt_span ); ?></div>
			<svg class="spark" id="ptRidingSparkTotal" aria-hidden="true"></svg>
		</div>
		<div class="kpi">
			<div class="lab"><?php esc_html_e( 'Total Agreements', 'publictech-terminal' ); ?></div>
			<div class="val"><?php echo esc_html( number_format_i18n( (int) $pt_totals['agreement_count'] ) ); ?></div>
			<div class="delta dim"><?php esc_html_e( 'since data began', 'publictech-terminal' ); ?></div>
		</div>
		<div class="kpi">
			<div class="lab"><?php esc_html_e( 'Peak Year', 'publictech-terminal' ); ?></div>
			<div class="val"><?php echo esc_html( (int) ( $pt_totals['peak_year'] ?? 0 ) ?: '—' ); ?></div>
			<div class="delta pos"><?php echo esc_html( \PublicTech\Core\pt_format_money_short( (float) ( $pt_totals['peak_year_total'] ?? 0 ) ) ); ?></div>
		</div>
		<div class="kpi">
			<div class="lab"><?php esc_html_e( 'Top Recipient Category', 'publictech-terminal' ); ?></div>
			<div class="val" style="font-size:14px;margin-top:12px;letter-spacing:0"><?php echo esc_html( (string) ( $pt_totals['top_recipient_type'] ?: '—' ) ); ?></div>
		</div>
	</div>

	<div style="display:grid;grid-template-columns:1fr 320px;gap:14px;align-items:start">

		<!-- Spending by year -->
		<div class="panel corner">
			<div class="hd">
				<h3>◇ <?php esc_html_e( 'Spending by Agreement Start Year', 'publictech-terminal' ); ?></h3>
				<span class="meta"><?php echo esc_html( $pt_span ); ?> · NET $M</span>
			</div>
			<div class="bd">
				<div class="chart-host lg" id="ptRidingYearChart" style="height:300px"></div>
			</div>
		</div>

		<!-- Top recipients -->
		<div class="panel corner">
			<div class="hd"><h3>◇ <?php esc_html_e( 'Top 10 Recipients', 'publictech-terminal' ); ?></h3><span class="meta">ALL PERIODS</span></div>
			<div class="kvs">
				<?php if ( $pt_recipients ) : ?>
					<?php foreach ( array_slice( $pt_recipients, 0, 10 ) as $i => $rec ) : ?>
						<div class="kv" style="align-items:flex-start">
							<span class="k" style="flex:1;line-height:1.4;text-transform:none;font-size:10px;padding-right:8px">
								<span class="mono" style="color:var(--accent);margin-right:4px"><?php echo esc_html( $i + 1 ); ?>.</span>
								<?php echo esc_html( (string) ( $rec['name'] ?? '' ) ); ?>
							</span>
							<span class="v" style="color:var(--pos);flex-shrink:0"><?php echo esc_html( \PublicTech\Core\pt_format_money_short( (float) ( $rec['total'] ?? 0 ) ) ); ?></span>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<div class="bd" style="font-size:11px;color:var(--ink-2)"><?php esc_html_e( 'No recipients on record.', 'publictech-terminal' ); ?></div>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- Controversial-keyword scan -->
	<div class="panel corner" style="margin-top:14px">
		<div class="hd">
			<h3>◇ <?php esc_html_e( 'Spending by Tracked Keyword', 'publictech-terminal' ); ?></h3>
			<span class="meta"><?php esc_html_e( 'SCRUTINY KEYWORDS · recipient / title / program / description', 'publictech-terminal' ); ?></span>
		</div>
		<div class="bd">
			<?php if ( $pt_kw_js ) : ?>
				<div class="chart-host" id="ptRidingKwChart" style="height:280px;margin-bottom:12px"></div>
				<div style="overflow-x:auto">
					<table class="stream">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Keyword / Phrase', 'publictech-terminal' ); ?></th>
								<th class="num"><?php esc_html_e( 'Total Funding', 'publictech-terminal' ); ?></th>
								<th class="num"><?php esc_html_e( 'Agreements', 'publictech-terminal' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $pt_keywords as $kw ) : ?>
							<tr>
								<td style="font-weight:500"><?php echo esc_html( (string) ( $kw['label'] ?? '' ) ); ?></td>
								<td class="num" style="color:var(--ink-0)"><?php echo esc_html( \PublicTech\Core\pt_format_money( (float) ( $kw['total'] ?? 0 ) ) ); ?></td>
								<td class="num" style="color:var(--accent)"><?php echo esc_html( number_format_i18n( (int) ( $kw['agreements'] ?? 0 ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<p style="font-size:11px;color:var(--ink-2);margin:0"><?php esc_html_e( 'No agreements in this riding match the tracked scrutiny keywords.', 'publictech-terminal' ); ?></p>
			<?php endif; ?>
		</div>
	</div>

</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
	'use strict';
	if (!window.PT || !PT.chart) { return; }

	var yearly   = <?php echo wp_json_encode( $pt_year_js ); ?>;   // [year, party, $M]
	var keywords = <?php echo wp_json_encode( $pt_kw_js ); ?>;     // [label, $]

	var css      = getComputedStyle(document.documentElement);
	var accent   = css.getPropertyValue('--accent').trim() || '#00a0d2';
	var pos      = css.getPropertyValue('--pos').trim()    || '#3ddc97';
	var partyCol = {
		Conservative: css.getPropertyValue('--party-con').trim() || '#1a4fa0',
		Liberal:      css.getPropertyValue('--party-lib').trim() || '#d6202b'
	};

	if (yearly.length) {
		var yearData = yearly.map(function (r) {
			return { label: String(r[0]), value: r[2], color: partyCol[r[1]] || accent };
		});
		PT.chart('#ptRidingYearChart', 'bar', yearData);

		var totals = yearly.map(function (r) { return r[2]; });
		var sparkEl = document.getElementById('ptRidingSparkTotal');
		if (sparkEl) { PT.chart(sparkEl, 'spark', totals, { color: accent }); }
	}

	if (keywords.length) {
		var kwData = keywords.map(function (r) { return { label: r[0], value: r[1], color: pos }; });
		PT.chart('#ptRidingKwChart', 'bar', kwData);
	}
});
</script>
