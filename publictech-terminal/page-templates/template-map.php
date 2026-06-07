<?php
/**
 * Template Name: PT — Spending Map
 *
 * Interactive world + Canadian-riding spending map.
 *
 * Two modes:
 *   Choropleth — each riding filled proportional to total federal funding.
 *   Heat Map   — D3 contour-density overlay weighted by spend.
 *
 * Data sources:
 *   - Canadian riding polygons: assets/topojson/ridings-2023.topo.json
 *   - World countries:          cdn.jsdelivr.net/npm/world-atlas@2/countries-110m.json
 *   - Riding spending totals:   wp-content/uploads/pt-map/riding-totals.json (static file)
 *                               Falls back to REST /publictech/v1/map/riding-totals
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

/* ── URLs resolved server-side ─────────────────────────────────────────── */
$pt_ridings_url = esc_url_raw( PUBLICTECH_TERMINAL_URL . 'assets/topojson/ridings-2023.topo.json' );
$pt_rest_root   = esc_url_raw( get_rest_url( null, PUBLICTECH_CORE_REST_NS ) );
$pt_totals_url  = class_exists( '\\PublicTech\\Core\\Map_Cache' )
	? esc_url_raw( \PublicTech\Core\Map_Cache::file_url() )
	: '';
$pt_world_url   = 'https://cdn.jsdelivr.net/npm/world-atlas@2/countries-110m.json';

/* ── Enqueue map script with proper dep chain ───────────────────────────── */
/* This runs before get_header() so wp_enqueue_scripts fires in time.       */
add_action( 'wp_enqueue_scripts', static function () use ( $pt_ridings_url, $pt_rest_root, $pt_totals_url ) {
	wp_enqueue_script(
		'publictech-map',
		PUBLICTECH_TERMINAL_URL . 'assets/js/map.js',
		[ 'd3', 'topojson-client', 'publictech-charts' ],
		PUBLICTECH_TERMINAL_VERSION,
		true
	);
	wp_localize_script( 'publictech-map', 'PT_MAP', [
		'rest_root'   => $pt_rest_root,
		'ridings_url' => $pt_ridings_url,
		'totals_url'  => $pt_totals_url,
	] );
} );

/* ── Preload the three data fetches so they start before JS executes ─────── */
add_action( 'wp_head', static function () use ( $pt_ridings_url, $pt_totals_url, $pt_world_url ) {
	$preloads = array_filter( [ $pt_ridings_url, $pt_world_url, $pt_totals_url ] );
	foreach ( $preloads as $url ) {
		echo '<link rel="preload" href="' . esc_attr( $url ) . '" as="fetch" crossorigin="anonymous">' . "\n";
	}
}, 5 );

get_header();
?>

<style>
/* ── Map page layout ───────────────────────────────────────────────────────── */
.pt-map-page {
	display: flex;
	flex-direction: column;
	height: calc(100vh - var(--header-h, 64px));
	padding: 20px 24px 16px;
	gap: 14px;
	box-sizing: border-box;
}
.pt-map-hdr {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
	flex-shrink: 0;
}
.pt-map-hdr-left { flex: 1; min-width: 0; }
.pt-map-hdr-eyebrow {
	color: var(--accent, #00a0d2);
	font: 500 10px/1 ui-monospace, monospace;
	text-transform: uppercase;
	letter-spacing: .2em;
	margin-bottom: 4px;
}
.pt-map-hdr-title {
	font: 600 18px/1.2 var(--font-ui, 'Montserrat', sans-serif);
	color: var(--ink1, #e0e4ec);
	margin: 0;
}
.pt-map-controls { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.pt-map-btn {
	background: var(--bg2, #22252d);
	border: 1px solid var(--line, #333);
	color: var(--ink2, #8b93a0);
	padding: 6px 14px;
	border-radius: 3px;
	cursor: pointer;
	font: 500 11px/1 ui-monospace, monospace;
	text-transform: uppercase;
	letter-spacing: .06em;
	transition: border-color .15s, color .15s, background .15s;
}
.pt-map-btn:hover,
.pt-map-btn.active {
	border-color: var(--accent, #00a0d2);
	color: var(--accent, #00a0d2);
	background: rgba(0,160,210,.1);
}
.pt-map-btn-ghost { background: transparent; }
.pt-map-sep { color: var(--line, #444); user-select: none; }
.pt-map-legend {
	display: flex;
	align-items: center;
	gap: 8px;
	font: 500 10px/1 ui-monospace, monospace;
	color: var(--ink2, #8b93a0);
	flex-shrink: 0;
}
.pt-map-legend-bar {
	display: inline-block;
	width: 110px;
	height: 7px;
	border-radius: 2px;
}

/* ── Map container ─────────────────────────────────────────────────────────── */
.pt-map-wrap {
	flex: 1;
	position: relative;
	background: var(--bg2, #1c1f28);
	border: 1px solid var(--line, #2a2d35);
	border-radius: 4px;
	overflow: hidden;
	min-height: 0;
}
#ptMapSvg {
	position: absolute;
	inset: 0;
	width: 100%;
	height: 100%;
	cursor: grab;
}
#ptMapSvg:active { cursor: grabbing; }
.pt-map-loading {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 4;
	background: var(--bg2, #1c1f28);
	transition: opacity .4s;
}
.pt-map-loading.hidden { opacity: 0; pointer-events: none; }

/* ── Country + riding path styles ──────────────────────────────────────────── */
.pt-country {
	fill: var(--bg3, #252932);
	stroke: var(--line-dim, #353a48);
	stroke-width: 0.35px;
	cursor: pointer;
}
.pt-country.hovered {
	stroke: var(--accent, #00a0d2);
	stroke-width: 1.6px;
	filter: drop-shadow(0 0 5px rgba(0,160,210,.5));
}
.pt-riding {
	stroke: rgba(0,160,210,.28);
	stroke-width: 0.5px;
	cursor: pointer;
}
.pt-riding.hovered {
	stroke: var(--accent, #00a0d2);
	stroke-width: 2px;
	filter: drop-shadow(0 0 9px rgba(0,160,210,.7));
}

/* ── Tooltip ───────────────────────────────────────────────────────────────── */
#ptMapTip {
	position: fixed;
	background: var(--bg0, #13151a);
	border: 1px solid var(--accent, #00a0d2);
	border-radius: 4px;
	padding: 10px 14px;
	font: 400 12px/1.6 ui-monospace, monospace;
	color: var(--ink1, #e0e4ec);
	pointer-events: none;
	z-index: 200;
	opacity: 0;
	transition: opacity .1s;
	max-width: 230px;
	box-shadow: 0 4px 18px rgba(0,0,0,.45);
}
#ptMapTip.visible { opacity: 1; }
.pt-tip-title { font: 600 13px/1.3 var(--font-ui, 'Montserrat', sans-serif); color: var(--accent, #00a0d2); margin-bottom: 5px; }
.pt-tip-sub   { font: 500 9px/1 ui-monospace, monospace; color: var(--ink2, #8b93a0); text-transform: uppercase; letter-spacing: .1em; margin-bottom: 7px; }
.pt-tip-row   { display: flex; justify-content: space-between; gap: 18px; padding: 2px 0; font-size: 11px; }
.pt-tip-row span { color: var(--ink2, #8b93a0); }
.pt-tip-row b    { color: var(--ink1, #e0e4ec); }

/* ── Selected state ────────────────────────────────────────────────────────── */
.pt-country.selected {
	stroke: var(--accent, #00a0d2) !important;
	stroke-width: 2px !important;
	filter: drop-shadow(0 0 8px rgba(0,160,210,.7)) !important;
}
.pt-riding.selected {
	stroke: #fff !important;
	stroke-width: 2px !important;
	filter: drop-shadow(0 0 10px rgba(255,255,255,.5)) !important;
}

/* ── Detail side panel ─────────────────────────────────────────────────────── */
#ptMapPanel {
	position: absolute;
	top: 0; right: 0;
	width: 340px;
	height: 100%;
	background: var(--bg0, #13151a);
	border-left: 1px solid var(--accent, #00a0d2);
	z-index: 10;
	display: flex;
	flex-direction: column;
	transform: translateX(100%);
	transition: transform .3s cubic-bezier(.4,0,.2,1);
	box-shadow: -6px 0 28px rgba(0,0,0,.6);
}
#ptMapPanel.open { transform: translateX(0); }
.ptmp-hdr {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	border-bottom: 1px solid var(--line, #2a2d35);
	flex-shrink: 0;
}
.ptmp-hdr-label {
	font: 500 9px/1 ui-monospace, monospace;
	text-transform: uppercase;
	letter-spacing: .15em;
	color: var(--accent, #00a0d2);
}
.ptmp-close {
	background: none;
	border: 1px solid var(--line, #333);
	color: var(--ink2, #8b93a0);
	width: 24px; height: 24px;
	border-radius: 3px;
	cursor: pointer;
	font-size: 14px;
	line-height: 1;
	display: flex; align-items: center; justify-content: center;
	transition: border-color .15s, color .15s;
}
.ptmp-close:hover { border-color: var(--accent); color: var(--accent); }
#ptMapPanelBody {
	flex: 1;
	overflow-y: auto;
	padding: 16px;
	font: 400 12px/1.6 ui-monospace, monospace;
	color: var(--ink1, #e0e4ec);
}
/* panel content styles */
.ptmp-title {
	font: 700 15px/1.3 var(--font-ui, 'Montserrat', sans-serif);
	color: var(--ink1, #e0e4ec);
	margin-bottom: 2px;
}
.ptmp-sub {
	font: 500 9px/1 ui-monospace, monospace;
	color: var(--ink2, #8b93a0);
	text-transform: uppercase;
	letter-spacing: .1em;
	margin-bottom: 12px;
}
.ptmp-kpis {
	display: flex;
	gap: 12px;
	margin-bottom: 16px;
}
.ptmp-kpi {
	flex: 1;
	background: var(--bg2, #1c1f28);
	border: 1px solid var(--line, #2a2d35);
	border-radius: 3px;
	padding: 8px 10px;
}
.ptmp-kv {
	font: 700 14px/1 var(--font-ui, 'Montserrat', sans-serif);
	color: var(--accent, #00a0d2);
	margin-bottom: 3px;
}
.ptmp-kl {
	font: 500 9px/1 ui-monospace, monospace;
	color: var(--ink2, #8b93a0);
	text-transform: uppercase;
	letter-spacing: .08em;
}
.ptmp-section { margin-bottom: 18px; }
.ptmp-sh {
	font: 600 10px/1 ui-monospace, monospace;
	text-transform: uppercase;
	letter-spacing: .12em;
	color: var(--accent, #00a0d2);
	margin-bottom: 8px;
	padding-bottom: 4px;
	border-bottom: 1px solid var(--line, #2a2d35);
}
.ptmp-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 11px;
}
.ptmp-table th {
	text-align: left;
	font: 500 9px/1 ui-monospace, monospace;
	text-transform: uppercase;
	letter-spacing: .08em;
	color: var(--ink2, #8b93a0);
	padding: 4px 6px 6px 0;
	border-bottom: 1px solid var(--line, #2a2d35);
}
.ptmp-table td {
	padding: 4px 6px 4px 0;
	border-bottom: 1px solid rgba(255,255,255,.04);
	color: var(--ink1, #e0e4ec);
	vertical-align: top;
}
.ptmp-table tr:last-child td { border-bottom: none; }
.ptmp-prog {
	padding: 7px 0;
	border-bottom: 1px solid rgba(255,255,255,.05);
}
.ptmp-prog:last-child { border-bottom: none; }
.ptmp-pn {
	font: 500 11px/1.4 ui-monospace, monospace;
	color: var(--ink1, #e0e4ec);
	margin-bottom: 3px;
}
.ptmp-pp {
	font-size: 10px;
	color: var(--ink2, #8b93a0);
	line-height: 1.4;
	margin-bottom: 3px;
}
.ptmp-pm {
	font: 500 10px/1 ui-monospace, monospace;
	color: var(--accent, #00a0d2);
}
.ptmp-loading {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 80px;
}
</style>

<main id="pt-main" tabindex="-1">
<section class="view active" data-pt-view="map">

<div class="pt-map-page">

	<header class="pt-map-hdr">
		<div class="pt-map-hdr-left">
			<div class="pt-map-hdr-eyebrow">◇ <?php esc_html_e( 'PublicTech Terminal', 'publictech-terminal' ); ?></div>
			<h1 class="pt-map-hdr-title"><?php esc_html_e( 'Federal Spending Map', 'publictech-terminal' ); ?></h1>
		</div>
		<div class="pt-map-controls">
			<button id="ptMapBtnChoropleth" class="pt-map-btn active" aria-pressed="true">
				<?php esc_html_e( 'Choropleth', 'publictech-terminal' ); ?>
			</button>
			<button id="ptMapBtnHeatmap" class="pt-map-btn" aria-pressed="false">
				<?php esc_html_e( 'Heat Map', 'publictech-terminal' ); ?>
			</button>
			<span class="pt-map-sep" aria-hidden="true">|</span>
			<button id="ptMapBtnReset" class="pt-map-btn pt-map-btn-ghost">
				<?php esc_html_e( '↺ Reset', 'publictech-terminal' ); ?>
			</button>
		</div>
		<div id="ptMapLegend" class="pt-map-legend" aria-hidden="true"></div>
	</header>

	<div class="pt-map-wrap" id="ptMapWrap">
		<div class="pt-map-loading" id="ptMapLoading">
			<span class="pt-loading"><?php esc_html_e( 'Loading map data…', 'publictech-terminal' ); ?></span>
		</div>
		<svg id="ptMapSvg" aria-label="<?php esc_attr_e( 'Federal spending map', 'publictech-terminal' ); ?>" role="img"></svg>

		<aside id="ptMapPanel" aria-label="<?php esc_attr_e( 'Funding detail', 'publictech-terminal' ); ?>">
			<div class="ptmp-hdr">
				<span class="ptmp-hdr-label">◇ <?php esc_html_e( 'Funding Detail', 'publictech-terminal' ); ?></span>
				<button id="ptMapPanelClose" class="ptmp-close" aria-label="<?php esc_attr_e( 'Close panel', 'publictech-terminal' ); ?>">✕</button>
			</div>
			<div id="ptMapPanelBody"></div>
		</aside>
	</div>

</div>

</section>
</main>

<div id="ptMapTip" role="tooltip"></div>

<?php get_footer(); ?>
