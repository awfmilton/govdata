/* PublicTech Spending Map — interactive D3 world + Canadian-riding map.
   Depends on: d3, topojson-client, publictech-charts (ensures PT.chartTheme is ready).
   Config injected by wp_localize_script as window.PT_MAP. */
(function () {
	'use strict';

	var d3       = window.d3;
	var topojson = window.topojson;
	if ( ! d3 || ! topojson ) {
		console.error( '[PT Map] d3 or topojson-client not loaded.' );
		return;
	}

	var cfg      = window.PT_MAP || {};
	var REST_ROOT = ( cfg.rest_root || '' ).replace( /\/$/, '' );
	var RIDINGS   = cfg.ridings_url  || '';
	var TOTALS    = cfg.totals_url   || '';   /* static JSON file — fastest path */
	var WORLD     = 'https://cdn.jsdelivr.net/npm/world-atlas@2/countries-110m.json';

	/* ── ISO-3166 numeric → country name ──────────────────────────────────── */
	var ISO = {
		4:'Afghanistan',8:'Albania',12:'Algeria',24:'Angola',32:'Argentina',
		36:'Australia',40:'Austria',50:'Bangladesh',56:'Belgium',64:'Bhutan',
		68:'Bolivia',76:'Brazil',100:'Bulgaria',104:'Myanmar',116:'Cambodia',
		120:'Cameroon',144:'Sri Lanka',152:'Chile',156:'China',170:'Colombia',
		180:'DR Congo',191:'Croatia',192:'Cuba',203:'Czechia',208:'Denmark',
		218:'Ecuador',818:'Egypt',231:'Ethiopia',246:'Finland',250:'France',
		266:'Gabon',276:'Germany',288:'Ghana',300:'Greece',320:'Guatemala',
		332:'Haiti',340:'Honduras',348:'Hungary',356:'India',360:'Indonesia',
		364:'Iran',368:'Iraq',372:'Ireland',376:'Israel',380:'Italy',
		388:'Jamaica',392:'Japan',400:'Jordan',404:'Kenya',408:'North Korea',
		410:'South Korea',414:'Kuwait',418:'Laos',422:'Lebanon',430:'Liberia',
		434:'Libya',440:'Lithuania',442:'Luxembourg',458:'Malaysia',484:'Mexico',
		504:'Morocco',508:'Mozambique',524:'Nepal',528:'Netherlands',
		554:'New Zealand',562:'Niger',566:'Nigeria',578:'Norway',586:'Pakistan',
		598:'Papua New Guinea',604:'Peru',608:'Philippines',616:'Poland',
		620:'Portugal',634:'Qatar',642:'Romania',643:'Russia',682:'Saudi Arabia',
		694:'Sierra Leone',703:'Slovakia',706:'Somalia',710:'South Africa',
		729:'Sudan',752:'Sweden',756:'Switzerland',760:'Syria',764:'Thailand',
		792:'Turkey',800:'Uganda',804:'Ukraine',784:'UAE',826:'United Kingdom',
		840:'United States',858:'Uruguay',862:'Venezuela',704:'Vietnam',
		887:'Yemen',894:'Zambia',716:'Zimbabwe'
	};

	/* ── State ─────────────────────────────────────────────────────────────── */
	var mode              = 'choropleth';
	var ridingData        = {};
	var featureRidings    = null;
	var weightedCentroids = [];
	var heatBuilt         = false;
	var choroplethScale, heatmapScale;

	/* ── DOM refs ──────────────────────────────────────────────────────────── */
	var wrap    = document.getElementById( 'ptMapWrap' );
	var loading = document.getElementById( 'ptMapLoading' );
	var tipEl   = document.getElementById( 'ptMapTip' );
	var svg     = d3.select( '#ptMapSvg' );

	function W() { return wrap.clientWidth  || 960; }
	function H() { return wrap.clientHeight || 560; }

	/* ── SVG glow filter ───────────────────────────────────────────────────── */
	var defs  = svg.append( 'defs' );
	var fGlow = defs.append( 'filter' )
		.attr( 'id', 'ptGlow' )
		.attr( 'x', '-40%' ).attr( 'y', '-40%' )
		.attr( 'width', '180%' ).attr( 'height', '180%' );
	fGlow.append( 'feGaussianBlur' )
		.attr( 'in', 'SourceGraphic' ).attr( 'stdDeviation', '3.5' ).attr( 'result', 'blur' );
	var fm = fGlow.append( 'feMerge' );
	fm.append( 'feMergeNode' ).attr( 'in', 'blur' );
	fm.append( 'feMergeNode' ).attr( 'in', 'SourceGraphic' );

	/* ── Projection ────────────────────────────────────────────────────────── */
	var projection = d3.geoNaturalEarth1().scale( 153 ).translate( [ W() / 2, H() / 2 ] );
	var geoPath    = d3.geoPath().projection( projection );

	/* ── Layers ────────────────────────────────────────────────────────────── */
	var gRoot      = svg.append( 'g' ).attr( 'class', 'pt-map-root' );
	var gCountries = gRoot.append( 'g' ).attr( 'class', 'pt-layer-countries' );
	var gRidings   = gRoot.append( 'g' ).attr( 'class', 'pt-layer-ridings' );
	var gHeat      = gRoot.append( 'g' ).attr( 'class', 'pt-layer-heatmap' ).style( 'display', 'none' );

	/* ── Zoom ──────────────────────────────────────────────────────────────── */
	var zoom = d3.zoom().scaleExtent( [ 1, 20 ] ).on( 'zoom', function ( ev ) {
		gRoot.attr( 'transform', ev.transform );
	} );
	svg.call( zoom ).on( 'dblclick.zoom', null );

	function resetView() {
		svg.transition().duration( 600 ).call( zoom.transform, d3.zoomIdentity );
	}

	/* ── Tooltip ───────────────────────────────────────────────────────────── */
	function showTip( html, ev ) {
		tipEl.innerHTML = html;
		tipEl.classList.add( 'visible' );
		moveTip( ev );
	}
	function moveTip( ev ) {
		var r = tipEl.getBoundingClientRect();
		var x = ev.clientX + 14, y = ev.clientY + 14;
		if ( x + r.width  > window.innerWidth  ) x = ev.clientX - r.width  - 14;
		if ( y + r.height > window.innerHeight ) y = ev.clientY - r.height - 14;
		tipEl.style.left = x + 'px';
		tipEl.style.top  = y + 'px';
	}
	function hideTip() { tipEl.classList.remove( 'visible' ); }

	/* ── Formatters ────────────────────────────────────────────────────────── */
	function fmtMoney( n ) {
		if ( n >= 1e9 ) return '$' + ( n / 1e9 ).toFixed( 2 ) + 'B';
		if ( n >= 1e6 ) return '$' + ( n / 1e6 ).toFixed( 1 ) + 'M';
		if ( n >= 1e3 ) return '$' + Math.round( n / 1e3 ).toLocaleString() + 'K';
		return '$' + Math.round( n ).toLocaleString();
	}
	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"]/g, function ( c ) {
			return ( { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' } )[ c ];
		} );
	}

	/* ── Color scales ──────────────────────────────────────────────────────── */
	function buildScales( maxSpend ) {
		var t      = ( window.PT && PT.chartTheme ) || {};
		var accent = t.accent || '#00a0d2';
		var hex    = accent.replace( '#', '' );
		var ar = parseInt( hex.slice( 0, 2 ), 16 );
		var ag = parseInt( hex.slice( 2, 4 ), 16 );
		var ab = parseInt( hex.slice( 4, 6 ), 16 );
		choroplethScale = d3.scaleSequential()
			.domain( [ 0, maxSpend || 1 ] )
			.interpolator( function ( v ) {
				return 'rgba(' + ar + ',' + ag + ',' + ab + ',' + ( 0.08 + v * 0.84 ).toFixed( 3 ) + ')';
			} );
		heatmapScale = d3.scaleSequential( d3.interpolateInferno ).domain( [ 0, maxSpend || 1 ] );
	}

	function ridingFill( f ) {
		var row = ridingData[ String( f.properties.FED_NUM ) ];
		if ( ! row ) return 'rgba(120,130,150,0.12)';
		return mode === 'heatmap' ? heatmapScale( row.spend ) : choroplethScale( row.spend );
	}

	function applyRidingColors() {
		if ( featureRidings ) gRidings.selectAll( '.pt-riding' ).attr( 'fill', ridingFill );
	}

	/* ── Draw world countries ──────────────────────────────────────────────── */
	function drawCountries( fc ) {
		gCountries.selectAll( '.pt-country' ).data( fc.features ).enter().append( 'path' )
			.attr( 'class', 'pt-country' )
			.attr( 'd', geoPath )
			.on( 'mouseenter', function () { d3.select( this ).classed( 'hovered', true ); } )
			.on( 'mousemove',  function ( ev, f ) {
				showTip( '<div class="pt-tip-title">' + esc( ISO[ f.id ] || 'Region #' + f.id ) + '</div>', ev );
			} )
			.on( 'mouseleave', function () {
				hideTip(); d3.select( this ).classed( 'hovered', false );
			} );
	}

	/* ── Draw Canadian ridings ─────────────────────────────────────────────── */
	function drawRidings( fc ) {
		featureRidings = fc;
		gRidings.selectAll( '.pt-riding' ).data( fc.features ).enter().append( 'path' )
			.attr( 'class', 'pt-riding' )
			.attr( 'd', geoPath )
			.attr( 'fill', ridingFill )
			.on( 'mouseenter', function () { d3.select( this ).classed( 'hovered', true ).raise(); } )
			.on( 'mousemove',  function ( ev, f ) {
				var num   = String( f.properties.FED_NUM );
				var name  = f.properties.ED_NAMEE || num;
				var row   = ridingData[ num ] || {};
				showTip(
					'<div class="pt-tip-title">' + esc( name ) + '</div>' +
					( row.province ? '<div class="pt-tip-sub">' + esc( row.province ) + '</div>' : '' ) +
					'<div class="pt-tip-row"><span>Total Funding</span><b>' + fmtMoney( row.spend || 0 ) + '</b></div>' +
					'<div class="pt-tip-row"><span>Agreements</span><b>' + ( row.count || 0 ).toLocaleString() + '</b></div>',
					ev
				);
			} )
			.on( 'mouseleave', function () {
				hideTip(); d3.select( this ).classed( 'hovered', false );
			} );
	}

	/* ── Heatmap contour layer ─────────────────────────────────────────────── */
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
			.x( function ( d ) { return d.x; } ).y( function ( d ) { return d.y; } )
			.weight( function ( d ) { return d.w / maxW; } )
			.size( [ W(), H() ] ).bandwidth( 22 ).thresholds( 14 )( pts );
		var maxD     = d3.max( density, function ( d ) { return d.value; } ) || 1;
		var heatColor = d3.scaleSequential( d3.interpolateYlOrRd ).domain( [ 0, maxD ] );
		gHeat.selectAll( 'path' ).data( density ).enter().append( 'path' )
			.attr( 'd', d3.geoPath() )
			.attr( 'fill', function ( d ) { return heatColor( d.value ); } )
			.attr( 'opacity', 0.7 );
	}

	/* ── Mode toggle ───────────────────────────────────────────────────────── */
	function setMode( m ) {
		mode = m;
		var bc = document.getElementById( 'ptMapBtnChoropleth' );
		var bh = document.getElementById( 'ptMapBtnHeatmap' );
		if ( bc ) { bc.classList.toggle( 'active', m === 'choropleth' ); bc.setAttribute( 'aria-pressed', m === 'choropleth' ); }
		if ( bh ) { bh.classList.toggle( 'active', m === 'heatmap' );    bh.setAttribute( 'aria-pressed', m === 'heatmap'    ); }
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

	/* ── Legend ────────────────────────────────────────────────────────────── */
	function renderLegend() {
		var el = document.getElementById( 'ptMapLegend' ); if ( ! el ) return;
		var t  = ( window.PT && PT.chartTheme ) || {};
		var ac = t.accent || '#00a0d2';
		el.innerHTML = mode === 'choropleth'
			? '<span>$0</span><span class="pt-map-legend-bar" style="background:linear-gradient(to right,rgba(0,160,210,.08),' + ac + ')"></span><span>Max</span>'
			: '<span>Low</span><span class="pt-map-legend-bar" style="background:linear-gradient(to right,#000004,#fca50a,#fcffa4)"></span><span>High</span>';
	}

	/* ── Resize ────────────────────────────────────────────────────────────── */
	function onResize() {
		svg.attr( 'width', W() ).attr( 'height', H() );
		projection.translate( [ W() / 2, H() / 2 ] );
		gCountries.selectAll( 'path' ).attr( 'd', geoPath );
		gRidings.selectAll( 'path' ).attr( 'd', geoPath );
		if ( heatBuilt ) {
			heatBuilt = false;
			gHeat.selectAll( 'path' ).remove();
			if ( mode === 'heatmap' ) buildHeatmap();
		}
	}

	/* ── Load data and render ──────────────────────────────────────────────── */
	svg.attr( 'width', W() ).attr( 'height', H() );

	/* Try the static JSON file first; fall back to the REST endpoint. */
	var totalsUrl = TOTALS || ( REST_ROOT + '/map/riding-totals' );

	Promise.all( [
		fetch( WORLD ).then( function ( r ) { return r.json(); } ),
		fetch( RIDINGS ).then( function ( r ) { return r.json(); } ),
		fetch( totalsUrl ).then( function ( r ) { return r.json(); } )
			.catch( function () {
				/* Static file miss — fall back to REST */
				return fetch( REST_ROOT + '/map/riding-totals' ).then( function ( r ) { return r.json(); } );
			} )
	] ).then( function ( results ) {
		var worldTopo  = results[ 0 ];
		var ridingTopo = results[ 1 ];
		var totals     = results[ 2 ];

		ridingData = ( totals && typeof totals === 'object' && ! totals.code ) ? totals : {};

		var spendVals = Object.values( ridingData ).map( function ( d ) { return d.spend || 0; } );
		buildScales( d3.max( spendVals ) || 1 );

		/* Exclude Canada from the world layer — ridings cover it */
		var countries = topojson.feature( worldTopo, worldTopo.objects.countries );
		countries.features = countries.features.filter( function ( f ) { return f.id !== 124; } );
		drawCountries( countries );

		var ridingsFc = topojson.feature( ridingTopo, ridingTopo.objects.ridings );
		drawRidings( ridingsFc );

		ridingsFc.features.forEach( function ( f ) {
			var row = ridingData[ String( f.properties.FED_NUM ) ];
			if ( row && row.spend ) {
				var c = d3.geoCentroid( f );
				if ( c ) weightedCentroids.push( { lonlat: c, weight: row.spend } );
			}
		} );

		loading.classList.add( 'hidden' );
		renderLegend();

	} ).catch( function ( err ) {
		console.error( '[PT Map]', err );
		var s = loading.querySelector( 'span' );
		if ( s ) { s.textContent = 'Failed to load map. Please refresh.'; s.style.color = '#ff6b6b'; s.className = ''; }
	} );

	/* ── Button handlers ───────────────────────────────────────────────────── */
	var bc = document.getElementById( 'ptMapBtnChoropleth' );
	var bh = document.getElementById( 'ptMapBtnHeatmap' );
	var br = document.getElementById( 'ptMapBtnReset' );
	if ( bc ) bc.addEventListener( 'click', function () { setMode( 'choropleth' ); } );
	if ( bh ) bh.addEventListener( 'click', function () { setMode( 'heatmap' );    } );
	if ( br ) br.addEventListener( 'click', resetView );

	new ResizeObserver( onResize ).observe( wrap );

} )();
