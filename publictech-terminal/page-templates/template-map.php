<?php
/**
 * Template Name: PT — Spending Map
 *
 * Interactive world + Canadian-riding spending map.
 *
 * Two modes:
 *   Choropleth — each riding filled with an accent-gradient proportional to
 *                total federal funding. World countries shown as a neutral base.
 *   Heat Map   — D3 contour-density overlay computed from riding centroids
 *                weighted by spend, rendered over a dimmed riding layer.
 *
 * Data sources:
 *   - Canadian riding polygons: publictech-terminal/assets/topojson/ridings-2023.topo.json
 *     (2023 PCBFR boundary, FED_CA_2023_EN, simplified to ~180 KB via mapshaper)
 *   - World countries:          cdn.jsdelivr.net/npm/world-atlas@2/countries-110m.json
 *   - Riding spending totals:   REST /publictech/v1/map/riding-totals
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$pt_ridings_url = esc_url_raw( PUBLICTECH_TERMINAL_URL . 'assets/topojson/ridings-2023.topo.json' );
$pt_rest_root   = esc_url_raw( get_rest_url( null, PUBLICTECH_CORE_REST_NS ) );

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
.pt-map-controls {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-shrink: 0;
}
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
.pt-map-btn:hover {
	border-color: var(--accent, #00a0d2);
	color: var(--accent, #00a0d2);
	background: rgba(0,160,210,.06);
}
.pt-map-btn.active {
	border-color: var(--accent, #00a0d2);
	color: var(--accent, #00a0d2);
	background: rgba(0,160,210,.12);
}
.pt-map-btn-ghost {
	background: transparent;
}
.pt-map-sep {
	color: var(--line, #444);
	user-select: none;
}
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
	transition: opacity .3s;
}
.pt-map-loading.hidden { opacity: 0; pointer-events: none; }

/* ── Country + riding SVG styles ───────────────────────────────────────────── */
.pt-country {
	fill: var(--bg3, #252932);
	stroke: var(--line-dim, #353a48);
	stroke-width: 0.35px;
	cursor: pointer;
	transition: stroke-width .1s;
}
.pt-country.hovered {
	stroke: var(--accent, #00a0d2);
	stroke-width: 1.6px;
	filter: drop-shadow(0 0 5px rgba(0, 160, 210, .55));
}
.pt-riding {
	stroke: rgba(0,160,210,.3);
	stroke-width: 0.5px;
	cursor: pointer;
	transition: stroke-width .1s;
}
.pt-riding.hovered {
	stroke: var(--accent, #00a0d2);
	stroke-width: 2px;
	filter: drop-shadow(0 0 9px rgba(0, 160, 210, .7));
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
.pt-tip-title {
	font: 600 13px/1.3 var(--font-ui, 'Montserrat', sans-serif);
	color: var(--accent, #00a0d2);
	margin-bottom: 5px;
}
.pt-tip-sub {
	font: 500 9px/1 ui-monospace, monospace;
	color: var(--ink2, #8b93a0);
	text-transform: uppercase;
	letter-spacing: .1em;
	margin-bottom: 7px;
}
.pt-tip-row {
	display: flex;
	justify-content: space-between;
	gap: 18px;
	padding: 2px 0;
	font-size: 11px;
}
.pt-tip-row span { color: var(--ink2, #8b93a0); }
.pt-tip-row b    { color: var(--ink1, #e0e4ec); }
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
	</div>

</div>

</section>
</main>

<div id="ptMapTip" role="tooltip" aria-live="polite"></div>

<script>
(function () {
	'use strict';

	var d3       = window.d3;
	var topojson = window.topojson;
	if ( ! d3 || ! topojson ) {
		console.error( '[PT Map] d3 or topojson-client not loaded.' );
		return;
	}

	/* ── URLs (PHP-injected) ─────────────────────────────────────────────── */
	var REST_ROOT  = <?php echo wp_json_encode( $pt_rest_root ); ?>.replace( /\/$/, '' );
	var RIDINGS    = <?php echo wp_json_encode( $pt_ridings_url ); ?>;
	var WORLD      = 'https://cdn.jsdelivr.net/npm/world-atlas@2/countries-110m.json';

	/* ── ISO-3166 numeric → country name lookup ──────────────────────────── */
	var ISO_NAMES = {
		4:'Afghanistan',8:'Albania',12:'Algeria',24:'Angola',32:'Argentina',
		36:'Australia',40:'Austria',50:'Bangladesh',56:'Belgium',64:'Bhutan',
		68:'Bolivia',76:'Brazil',100:'Bulgaria',104:'Myanmar',116:'Cambodia',
		120:'Cameroon',124:'Canada',144:'Sri Lanka',152:'Chile',156:'China',
		170:'Colombia',180:'DR Congo',191:'Croatia',192:'Cuba',203:'Czechia',
		208:'Denmark',218:'Ecuador',818:'Egypt',231:'Ethiopia',246:'Finland',
		250:'France',266:'Gabon',276:'Germany',288:'Ghana',300:'Greece',
		320:'Guatemala',332:'Haiti',340:'Honduras',348:'Hungary',356:'India',
		360:'Indonesia',364:'Iran',368:'Iraq',372:'Ireland',376:'Israel',
		380:'Italy',388:'Jamaica',392:'Japan',404:'Kenya',408:'North Korea',
		410:'South Korea',414:'Kuwait',418:'Laos',422:'Lebanon',430:'Liberia',
		434:'Libya',440:'Lithuania',442:'Luxembourg',484:'Mexico',504:'Morocco',
		508:'Mozambique',524:'Nepal',528:'Netherlands',554:'New Zealand',
		562:'Niger',566:'Nigeria',578:'Norway',586:'Pakistan',
		598:'Papua New Guinea',604:'Peru',608:'Philippines',616:'Poland',
		620:'Portugal',634:'Qatar',642:'Romania',643:'Russia',682:'Saudi Arabia',
		694:'Sierra Leone',703:'Slovakia',706:'Somalia',710:'South Africa',
		729:'Sudan',752:'Sweden',756:'Switzerland',760:'Syria',764:'Thailand',
		792:'Turkey',800:'Uganda',804:'Ukraine',784:'UAE',826:'United Kingdom',
		840:'United States',858:'Uruguay',862:'Venezuela',704:'Vietnam',
		887:'Yemen',894:'Zambia',716:'Zimbabwe',528:'Netherlands',
		156:'China',250:'France',276:'Germany',554:'New Zealand',752:'Sweden'
	};

	/* ── State ───────────────────────────────────────────────────────────── */
	var mode             = 'choropleth';
	var ridingData       = {};
	var featureRidings   = null;
	var weightedCentroids = [];
	var heatBuilt        = false;
	var choroplethScale, heatmapScale;

	/* ── DOM refs ────────────────────────────────────────────────────────── */
	var wrap    = document.getElementById( 'ptMapWrap' );
	var loading = document.getElementById( 'ptMapLoading' );
	var tipEl   = document.getElementById( 'ptMapTip' );
	var svg     = d3.select( '#ptMapSvg' );

	function W() { return wrap.clientWidth  || 960; }
	function H() { return wrap.clientHeight || 560; }

	/* ── SVG defs: glow filter ───────────────────────────────────────────── */
	var defs = svg.append( 'defs' );
	var fGlow = defs.append( 'filter' )
		.attr( 'id', 'ptGlow' )
		.attr( 'x', '-40%' ).attr( 'y', '-40%' )
		.attr( 'width', '180%' ).attr( 'height', '180%' );
	fGlow.append( 'feGaussianBlur' )
		.attr( 'in', 'SourceGraphic' )
		.attr( 'stdDeviation', '3.5' )
		.attr( 'result', 'blur' );
	var fMerge = fGlow.append( 'feMerge' );
	fMerge.append( 'feMergeNode' ).attr( 'in', 'blur' );
	fMerge.append( 'feMergeNode' ).attr( 'in', 'SourceGraphic' );

	/* ── Projection + path generator ────────────────────────────────────── */
	var projection = d3.geoNaturalEarth1()
		.scale( 153 )
		.translate( [ W() / 2, H() / 2 ] );
	var geoPath = d3.geoPath().projection( projection );

	/* ── Layers ──────────────────────────────────────────────────────────── */
	var gRoot      = svg.append( 'g' ).attr( 'class', 'pt-map-root' );
	var gCountries = gRoot.append( 'g' ).attr( 'class', 'pt-layer-countries' );
	var gRidings   = gRoot.append( 'g' ).attr( 'class', 'pt-layer-ridings' );
	var gHeat      = gRoot.append( 'g' ).attr( 'class', 'pt-layer-heatmap' ).style( 'display', 'none' );

	/* ── Zoom ────────────────────────────────────────────────────────────── */
	var zoom = d3.zoom()
		.scaleExtent( [ 1, 20 ] )
		.on( 'zoom', function ( ev ) {
			gRoot.attr( 'transform', ev.transform );
		} );
	svg.call( zoom ).on( 'dblclick.zoom', null );

	function resetView() {
		svg.transition().duration( 600 )
			.call( zoom.transform, d3.zoomIdentity );
	}

	/* ── Tooltip ─────────────────────────────────────────────────────────── */
	function showTip( html, ev ) {
		tipEl.innerHTML = html;
		tipEl.classList.add( 'visible' );
		moveTip( ev );
	}
	function moveTip( ev ) {
		var r = tipEl.getBoundingClientRect();
		var x = ev.clientX + 14;
		var y = ev.clientY + 14;
		if ( x + r.width  > window.innerWidth  ) x = ev.clientX - r.width  - 14;
		if ( y + r.height > window.innerHeight ) y = ev.clientY - r.height - 14;
		tipEl.style.left = x + 'px';
		tipEl.style.top  = y + 'px';
	}
	function hideTip() { tipEl.classList.remove( 'visible' ); }

	/* ── Formatters ──────────────────────────────────────────────────────── */
	function fmtMoney( n ) {
		if ( n >= 1e9 ) return '$' + ( n / 1e9 ).toFixed( 2 ) + 'B';
		if ( n >= 1e6 ) return '$' + ( n / 1e6 ).toFixed( 1 ) + 'M';
		if ( n >= 1e3 ) return '$' + Math.round( n / 1e3 ).toLocaleString() + 'K';
		return '$' + Math.round( n ).toLocaleString();
	}
	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"]/g, function ( c ) {
			return ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' } )[ c ];
		} );
	}

	/* ── Color scales ────────────────────────────────────────────────────── */
	function buildScales( maxSpend ) {
		var theme  = ( window.PT && PT.chartTheme ) || {};
		var accent = theme.accent || '#00a0d2';
		var hex    = accent.replace( '#', '' );
		var ar = parseInt( hex.slice( 0, 2 ), 16 );
		var ag = parseInt( hex.slice( 2, 4 ), 16 );
		var ab = parseInt( hex.slice( 4, 6 ), 16 );

		choroplethScale = d3.scaleSequential()
			.domain( [ 0, maxSpend || 1 ] )
			.interpolator( function ( v ) {
				return 'rgba(' + ar + ',' + ag + ',' + ab + ',' + ( 0.08 + v * 0.84 ).toFixed( 3 ) + ')';
			} );

		heatmapScale = d3.scaleSequential( d3.interpolateInferno )
			.domain( [ 0, maxSpend || 1 ] );
	}

	function ridingFill( f ) {
		var row = ridingData[ String( f.properties.FED_NUM ) ];
		if ( ! row ) return 'rgba(120,130,150,0.12)';
		return mode === 'heatmap' ? heatmapScale( row.spend ) : choroplethScale( row.spend );
	}

	function applyRidingColors() {
		if ( ! featureRidings ) return;
		gRidings.selectAll( '.pt-riding' ).attr( 'fill', ridingFill );
	}

	/* ── Draw world countries ────────────────────────────────────────────── */
	function drawCountries( fc ) {
		gCountries.selectAll( '.pt-country' )
			.data( fc.features )
			.enter().append( 'path' )
				.attr( 'class', 'pt-country' )
				.attr( 'd', geoPath )
				.on( 'mousemove', function ( ev, f ) {
					var name = ISO_NAMES[ f.id ] || ( 'Region #' + f.id );
					showTip(
						'<div class="pt-tip-title">' + esc( name ) + '</div>',
						ev
					);
					d3.select( this ).classed( 'hovered', true );
				} )
				.on( 'mouseleave', function () {
					hideTip();
					d3.select( this ).classed( 'hovered', false );
				} );
	}

	/* ── Draw Canadian ridings ───────────────────────────────────────────── */
	function drawRidings( fc ) {
		featureRidings = fc;

		gRidings.selectAll( '.pt-riding' )
			.data( fc.features )
			.enter().append( 'path' )
				.attr( 'class', 'pt-riding' )
				.attr( 'd', geoPath )
				.attr( 'fill', ridingFill )
				.on( 'mouseenter', function ( ev, f ) {
					d3.select( this ).classed( 'hovered', true ).raise();
				} )
				.on( 'mousemove', function ( ev, f ) {
					var num   = String( f.properties.FED_NUM );
					var name  = f.properties.ED_NAMEE || num;
					var row   = ridingData[ num ] || {};
					var prov  = row.province || '';
					var spend = row.spend    || 0;
					var count = row.count    || 0;
					showTip(
						'<div class="pt-tip-title">' + esc( name ) + '</div>' +
						( prov ? '<div class="pt-tip-sub">' + esc( prov ) + '</div>' : '' ) +
						'<div class="pt-tip-row"><span>Total Funding</span><b>' + fmtMoney( spend ) + '</b></div>' +
						'<div class="pt-tip-row"><span>Agreements</span><b>' + count.toLocaleString() + '</b></div>',
						ev
					);
				} )
				.on( 'mouseleave', function () {
					hideTip();
					d3.select( this ).classed( 'hovered', false );
				} );
	}

	/* ── Build heatmap contour layer ─────────────────────────────────────── */
	function buildHeatmap() {
		if ( heatBuilt ) return;
		heatBuilt = true;

		var pts = weightedCentroids.map( function ( d ) {
			var px = projection( d.lonlat );
			return px ? { x: px[ 0 ], y: px[ 1 ], w: d.weight } : null;
		} ).filter( Boolean );

		if ( ! pts.length ) return;

		var maxW    = d3.max( pts, function ( d ) { return d.w; } ) || 1;
		var density = d3.contourDensity()
			.x( function ( d ) { return d.x; } )
			.y( function ( d ) { return d.y; } )
			.weight( function ( d ) { return d.w / maxW; } )
			.size( [ W(), H() ] )
			.bandwidth( 22 )
			.thresholds( 14 )( pts );

		var maxDensity = d3.max( density, function ( d ) { return d.value; } ) || 1;
		var heatColor  = d3.scaleSequential( d3.interpolateYlOrRd )
			.domain( [ 0, maxDensity ] );

		gHeat.selectAll( 'path' )
			.data( density )
			.enter().append( 'path' )
				.attr( 'd', d3.geoPath() )
				.attr( 'fill', function ( d ) { return heatColor( d.value ); } )
				.attr( 'opacity', 0.7 );
	}

	/* ── Mode switching ──────────────────────────────────────────────────── */
	function setMode( m ) {
		mode = m;

		var btnC = document.getElementById( 'ptMapBtnChoropleth' );
		var btnH = document.getElementById( 'ptMapBtnHeatmap' );
		btnC.classList.toggle( 'active', m === 'choropleth' );
		btnH.classList.toggle( 'active', m === 'heatmap' );
		btnC.setAttribute( 'aria-pressed', m === 'choropleth' ? 'true' : 'false' );
		btnH.setAttribute( 'aria-pressed', m === 'heatmap'    ? 'true' : 'false' );

		if ( m === 'heatmap' ) {
			buildHeatmap();
			gHeat.style( 'display', null );
			gRidings.attr( 'opacity', 0.3 );
		} else {
			gHeat.style( 'display', 'none' );
			gRidings.attr( 'opacity', null );
		}

		applyRidingColors();
		renderLegend();
	}

	/* ── Legend ──────────────────────────────────────────────────────────── */
	function renderLegend() {
		var el = document.getElementById( 'ptMapLegend' );
		if ( ! el ) return;
		var theme  = ( window.PT && PT.chartTheme ) || {};
		var accent = theme.accent || '#00a0d2';

		if ( mode === 'choropleth' ) {
			el.innerHTML =
				'<span>$0</span>' +
				'<span class="pt-map-legend-bar" style="background:linear-gradient(to right,rgba(0,160,210,.08),' + accent + ')"></span>' +
				'<span>Max</span>';
		} else {
			el.innerHTML =
				'<span>Low</span>' +
				'<span class="pt-map-legend-bar" style="background:linear-gradient(to right,#000004,#fca50a,#fcffa4)"></span>' +
				'<span>High</span>';
		}
	}

	/* ── Resize ──────────────────────────────────────────────────────────── */
	function onResize() {
		svg.attr( 'width', W() ).attr( 'height', H() );
		projection.translate( [ W() / 2, H() / 2 ] );
		gCountries.selectAll( 'path' ).attr( 'd', geoPath );
		gRidings.selectAll( 'path' ).attr( 'd', geoPath );
		/* heatmap density is pixel-space; invalidate it so it rebuilds on next toggle */
		if ( heatBuilt ) {
			heatBuilt = false;
			gHeat.selectAll( 'path' ).remove();
			if ( mode === 'heatmap' ) buildHeatmap();
		}
	}

	/* ── Initial SVG size ────────────────────────────────────────────────── */
	svg.attr( 'width', W() ).attr( 'height', H() );

	/* ── Fetch all data in parallel ──────────────────────────────────────── */
	Promise.all( [
		fetch( WORLD ).then( function ( r ) { return r.json(); } ),
		fetch( RIDINGS ).then( function ( r ) { return r.json(); } ),
		fetch( REST_ROOT + '/map/riding-totals' ).then( function ( r ) { return r.json(); } ),
	] ).then( function ( results ) {
		var worldTopo  = results[ 0 ];
		var ridingTopo = results[ 1 ];
		var totals     = results[ 2 ];

		ridingData = ( totals && typeof totals === 'object' && ! totals.code ) ? totals : {};

		/* Color scales derived from actual max spend */
		var spendVals = Object.values( ridingData ).map( function ( d ) { return d.spend || 0; } );
		var maxSpend  = d3.max( spendVals ) || 1;
		buildScales( maxSpend );

		/* World countries — exclude Canada (id 124); ridings layer covers it */
		var countries = topojson.feature( worldTopo, worldTopo.objects.countries );
		countries.features = countries.features.filter( function ( f ) { return f.id !== 124; } );
		drawCountries( countries );

		/* Canadian ridings */
		var ridingsFc = topojson.feature( ridingTopo, ridingTopo.objects.ridings );
		drawRidings( ridingsFc );

		/* Precompute weighted centroids for the heat map */
		ridingsFc.features.forEach( function ( f ) {
			var num = String( f.properties.FED_NUM );
			var row = ridingData[ num ];
			if ( ! row || ! row.spend ) return;
			var c = d3.geoCentroid( f );
			if ( c ) weightedCentroids.push( { lonlat: c, weight: row.spend } );
		} );

		loading.classList.add( 'hidden' );
		renderLegend();

	} ).catch( function ( err ) {
		console.error( '[PT Map]', err );
		loading.querySelector( 'span' ).textContent = 'Failed to load map. Please refresh.';
		loading.querySelector( 'span' ).style.color = '#ff6b6b';
		loading.querySelector( 'span' ).classList.remove( 'pt-loading' );
	} );

	/* ── Button handlers ─────────────────────────────────────────────────── */
	document.getElementById( 'ptMapBtnChoropleth' ).addEventListener( 'click', function () { setMode( 'choropleth' ); } );
	document.getElementById( 'ptMapBtnHeatmap'    ).addEventListener( 'click', function () { setMode( 'heatmap' );    } );
	document.getElementById( 'ptMapBtnReset'      ).addEventListener( 'click', resetView );

	/* ── Resize observer ─────────────────────────────────────────────────── */
	new ResizeObserver( onResize ).observe( wrap );

} )();
</script>

<?php get_footer(); ?>
