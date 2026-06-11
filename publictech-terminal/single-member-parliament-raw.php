<?php
/**
 * Raw MP social card — 1200x630 capture surface for ApiFlash.
 *
 * Served when a member-parliament single is requested with ?pt_raw_render=1
 * (template_include swap in functions.php). No header rail / footer / admin
 * bar, so the screenshot is only the card. Identity and KPI figures are
 * server-rendered (always present); the office-spend line chart hydrates
 * client-side from the public /mp/{id}/spend endpoint, which ApiFlash's
 * headless browser executes before the capture. Core emits noindex for this
 * variant.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

if ( ! have_posts() ) {
	return;
}
the_post();
$mp_id     = get_the_ID();
$party     = get_the_terms( $mp_id, 'mp_party' );
$party_n   = $party && ! is_wp_error( $party ) ? $party[0]->name : '';
$pclass    = $party_n ? sanitize_html_class( strtolower( $party_n ) ) : 'unknown';
$region    = get_the_terms( $mp_id, 'mp_region' );
$region_n  = $region && ! is_wp_error( $region ) ? $region[0]->name : '';
$riding    = (string) ( get_post_meta( $mp_id, 'pt_mp_district', true ) ?: get_post_meta( $mp_id, 'riding', true ) );
$photo_url = (string) get_post_meta( $mp_id, 'pt_mp_photo_url', true );
$spend     = (int) get_post_meta( $mp_id, 'total_spend', true );
$travel    = (int) get_post_meta( $mp_id, 'travel_spend', true );
$contracts = (int) get_post_meta( $mp_id, 'contracts_n', true );
$votes     = (int) get_post_meta( $mp_id, 'votes_cast', true );

$kpis = [
	[ __( 'Total Office Spend', 'publictech-terminal' ), '$' . number_format_i18n( $spend ) ],
	[ __( 'Travel Expenses', 'publictech-terminal' ),    '$' . number_format_i18n( $travel ) ],
	[ __( 'Contract Spend', 'publictech-terminal' ),     '$' . number_format_i18n( $contracts ) ],
	[ __( 'Votes Cast', 'publictech-terminal' ),         $votes ? number_format_i18n( $votes ) : '—' ],
];
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=<?php echo (int) \PublicTech\Core\MP_Social_Cards::WIDTH; ?>" />
<?php wp_head(); ?>
<style>
	html, body { margin: 0; padding: 0; overflow: hidden; background: var(--bg0, #0b0e11); }
	.pt-mpc {
		box-sizing: border-box;
		width: <?php echo (int) \PublicTech\Core\MP_Social_Cards::WIDTH; ?>px;
		height: <?php echo (int) \PublicTech\Core\MP_Social_Cards::HEIGHT; ?>px;
		display: flex; flex-direction: column; gap: 16px;
		padding: 34px 44px;
		color: var(--ink0, #e8edf2);
		font-family: 'Montserrat', system-ui, sans-serif;
	}
	.pt-mpc .kicker { color: var(--accent, #00a0d2); font-size: 12px; letter-spacing: 0.22em; text-transform: uppercase; }
	.pt-mpc .idrow { display: flex; align-items: center; gap: 22px; }
	.pt-mpc .portrait {
		position: relative; width: 96px; height: 96px; border-radius: 8px; overflow: hidden;
		flex: 0 0 auto; border: 1px solid rgba(255,255,255,0.12);
		background: linear-gradient(135deg, rgba(0,160,210,0.30), rgba(255,255,255,0.04));
	}
	.pt-mpc .portrait .mono {
		position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
		font-size: 34px; font-weight: 600; letter-spacing: 0.03em; color: var(--ink0, #e8edf2);
	}
	.pt-mpc .portrait img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
	.pt-mpc h1 { margin: 0; font-size: 40px; line-height: 1.1; }
	.pt-mpc .sub { margin-top: 8px; display: flex; align-items: center; gap: 12px; font-size: 16px; color: var(--ink-2, #9aa7b2); }
	.pt-mpc .pbar { display: inline-block; width: 12px; height: 12px; border-radius: 2px; background: var(--accent, #00a0d2); }
	.pt-mpc .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
	.pt-mpc .kpi { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 12px 14px; }
	.pt-mpc .kpi .lab { font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--ink-2, #9aa7b2); }
	.pt-mpc .kpi .val { font-size: 24px; font-family: 'JetBrains Mono', monospace; margin-top: 4px; }
	.pt-mpc .chart-wrap { flex: 1 1 auto; min-height: 0; border-top: 1px solid rgba(255,255,255,0.08); padding-top: 12px; }
	.pt-mpc .chart-wrap .lab { font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--ink-2, #9aa7b2); margin-bottom: 6px; }
	.pt-mpc #ptLineChart { width: 100%; height: 190px; }
</style>
</head>
<body <?php body_class( 'pt-raw-render pt-mp-card' ); ?>>
	<div class="pt-mpc">
		<div class="kicker">◇ <?php echo esc_html( get_bloginfo( 'name' ) ); ?> · <?php esc_html_e( 'MP Office Spending', 'publictech-terminal' ); ?></div>
		<div class="idrow">
			<?php
			// Monogram fallback: MP portraits come from an external CDN that is
			// often unreachable from a headless capture, so always render initials
			// and layer the photo on top — onerror drops a broken image back to
			// the monogram, so the card never shows a broken-image icon.
			$pt_words    = preg_split( '/\s+/', trim( wp_strip_all_tags( (string) get_the_title() ) ) ) ?: [];
			$pt_initials = '';
			if ( $pt_words ) {
				$pt_initials = mb_substr( $pt_words[0], 0, 1 );
				if ( count( $pt_words ) > 1 ) {
					$pt_initials .= mb_substr( $pt_words[ count( $pt_words ) - 1 ], 0, 1 );
				}
			}
			$pt_initials = mb_strtoupper( $pt_initials );
			$pt_portrait = has_post_thumbnail() ? (string) get_the_post_thumbnail_url( $mp_id, 'medium' ) : $photo_url;
			?>
			<div class="portrait">
				<span class="mono"><?php echo esc_html( $pt_initials ); ?></span>
				<?php if ( $pt_portrait ) : ?>
					<img src="<?php echo esc_url( $pt_portrait ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" onerror="this.remove()" />
				<?php endif; ?>
			</div>
			<div>
				<h1><?php the_title(); ?></h1>
				<div class="sub">
					<?php if ( $party_n ) : ?>
						<span><span class="pbar party-<?php echo esc_attr( $pclass ); ?>"></span> <?php echo esc_html( $party_n ); ?></span>
					<?php endif; ?>
					<?php $place = $riding ?: $region_n; ?>
					<?php if ( $place ) : ?>
						<span><?php
						/* translators: %s: riding or region name */
						printf( esc_html__( 'MP — %s', 'publictech-terminal' ), esc_html( $place ) );
						?></span>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="kpis">
			<?php foreach ( $kpis as $kpi ) : ?>
				<div class="kpi">
					<div class="lab"><?php echo esc_html( $kpi[0] ); ?></div>
					<div class="val"><?php echo esc_html( $kpi[1] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="chart-wrap">
			<div class="lab"><?php esc_html_e( 'Office Spending by Fiscal Year', 'publictech-terminal' ); ?></div>
			<div id="ptLineChart"></div>
		</div>
	</div>

	<script>
	document.addEventListener('DOMContentLoaded', function () {
		if (!window.PT || !window.PT.chart || !window.PT_BOOT) {
			document.body.setAttribute('data-pt-card-ready', 'noscript');
			return;
		}
		var url = PT_BOOT.rest + '/mp/<?php echo (int) $mp_id; ?>/spend';
		fetch(url, { credentials: 'same-origin', headers: { 'x-wp-nonce': PT_BOOT.nonce } })
			.then(function (r) { return r.ok ? r.json() : []; })
			.then(function (rows) {
				var byCat = {};
				(rows || []).forEach(function (r) {
					(byCat[r.category] = byCat[r.category] || []).push({ x: +r.fiscal_year, y: +r.total / 1000 });
				});
				var series = Object.keys(byCat).map(function (k) {
					return { key: k, values: byCat[k].sort(function (a, b) { return a.x - b.x; }) };
				});
				if (series.length) { PT.chart('#ptLineChart', 'line', series); }
				document.body.setAttribute('data-pt-card-ready', '1');
			})
			.catch(function () { document.body.setAttribute('data-pt-card-ready', 'error'); });
	});
	</script>
<?php wp_footer(); ?>
</body>
</html>
