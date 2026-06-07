/* PublicTech Spending Map — D3 world map + Canadian riding choropleth + heatmap.
   Depends on: d3, topojson-client, publictech-charts.
   Config via window.PT_MAP (wp_localize_script). */
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
	var RIDINGS   = cfg.ridings_url || '';
	var TOTALS    = cfg.totals_url  || '';
	var WORLD     = 'https://cdn.jsdelivr.net/npm/world-atlas@2/countries-110m.json';

	/* ── Data state ────────────────────────────────────────────────────────── */
	var ridingData   = {};   /* { "10001": {spend, count, name, province} } */
	var countryData  = {};   /* { "840":   {spend, count, name}           } */

	var mode             = 'choropleth';
	var featureRidings   = null;
	var weightedCentroids = [];
	var heatBuilt        = false;
	var ridingScale, countryScale, ridingHeatScale, countryHeatScale;

	/* ── DOM ───────────────────────────────────────────────────────────────── */
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

	/* ── Projection + layers ───────────────────────────────────────────────── */
	var projection = d3.geoNaturalEarth1().scale( 153 ).translate( [ W() / 2, H() / 2 ] );
	var geoPath    = d3.geoPath().projection( projection );

	var gRoot      = svg.append( 'g' ).attr( 'class', 'pt-map-root' );
	var gCountries = gRoot.append( 'g' ).attr( 'class', 'pt-layer-countries' );
	var gRidings   = gRoot.append( 'g' ).attr( 'class', 'pt-layer-ridings' );
	var gHeat      = gRoot.append( 'g' ).attr( 'class', 'pt-layer-heatmap' ).style( 'display', 'none' );

	/* ── Zoom ──────────────────────────────────────────────────────────────── */
	var zoomTimer = null;
	var zoom = d3.zoom().scaleExtent( [ 1, 20 ] ).on( 'zoom', function ( ev ) {
		currentTransform = ev.transform;
		gRoot.attr( 'transform', ev.transform );
		/* Debounce heatmap rebuild: 250ms after zoom stops */
		if ( mode === 'heatmap' ) {
			clearTimeout( zoomTimer );
			zoomTimer = setTimeout( function () {
				heatBuilt = false;
				buildHeatmap( true );
			}, 250 );
		}
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
		tipEl.style.left = x + 'px'; tipEl.style.top = y + 'px';
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
	function buildScales() {
		var t      = ( window.PT && PT.chartTheme ) || {};
		var accent = t.accent || '#00a0d2';
		var hex    = accent.replace( '#', '' );
		var ar = parseInt( hex.slice( 0, 2 ), 16 );
		var ag = parseInt( hex.slice( 2, 4 ), 16 );
		var ab = parseInt( hex.slice( 4, 6 ), 16 );

		/* Ridings and countries each have their own linear scale so relative
		   differences are visible within each group. */
		var rSpends = Object.values( ridingData ).map( function ( d ) { return d.spend || 0; } );
		var cSpends = Object.values( countryData ).map( function ( d ) { return d.spend || 0; } );
		var maxR = d3.max( rSpends ) || 1;
		var maxC = d3.max( cSpends ) || 1;

		ridingScale = d3.scaleSequential()
			.domain( [ 0, maxR ] )
			.interpolator( function ( v ) {
				return 'rgba(' + ar + ',' + ag + ',' + ab + ',' + ( 0.08 + v * 0.84 ).toFixed( 3 ) + ')';
			} );

		/* Countries: log scale from near-black → bright amber using fully opaque
		   rgb interpolation. Avoids the rgba-over-dark-bg contrast problem.
		   US ($19B) → #e8920a; $50M → dim brownish-amber; $0 → kept dark. */
		var cSpendPos = Object.values( countryData )
			.map( function ( d ) { return d.spend || 0; } )
			.filter( function ( v ) { return v > 0; } );
		var minC = Math.max( 1, d3.min( cSpendPos ) || 1 );
		var logCountry = d3.scaleLog().domain( [ minC, maxC ] ).range( [ 0, 1 ] ).clamp( true );
		var interpCountry = d3.interpolateRgb( '#1e1a08', '#e8920a' );
		countryScale = function ( spend ) {
			return interpCountry( logCountry( Math.max( spend, minC ) ) );
		};

		/* Country heatmap also uses log scale so US doesn't crush everything */
		var logCountryH = d3.scaleLog().domain( [ minC, maxC ] ).range( [ 0, 1 ] ).clamp( true );
		countryHeatScale = function ( spend ) {
			return d3.interpolateInferno( logCountryH( Math.max( spend, minC ) ) );
		};
		ridingHeatScale = d3.scaleSequential( d3.interpolateInferno ).domain( [ 0, maxR ] );
	}

	function countryFill( f ) {
		var row = countryData[ String( f.id ) ];
		if ( ! row || ! row.spend ) return '#1d2029';   /* no-data: near-black so funded countries pop */
		if ( mode === 'heatmap' ) return countryHeatScale ? countryHeatScale( row.spend ) : '#1d2029';
		return countryScale ? countryScale( row.spend ) : '#1d2029';
	}

	function ridingFill( f ) {
		var row = ridingData[ String( f.properties.FED_NUM ) ];
		if ( ! row ) return 'rgba(120,130,150,0.12)';
		return mode === 'heatmap' ? ridingHeatScale( row.spend ) : ridingScale( row.spend );
	}

	/* ── Draw world countries ──────────────────────────────────────────────── */
	function drawCountries( fc ) {
		gCountries.selectAll( '.pt-country' ).data( fc.features ).enter().append( 'path' )
			.attr( 'class', 'pt-country' )
			.attr( 'd', geoPath )
			.attr( 'fill', countryFill )
			.on( 'mouseenter', function () { d3.select( this ).classed( 'hovered', true ); } )
			.on( 'mousemove',  function ( ev, f ) {
				var row  = countryData[ String( f.id ) ];
				var name = ( row && row.name ) ? row.name : ( 'Region #' + f.id );
				var html = '<div class="pt-tip-title">' + esc( name ) + '</div>';
				if ( row ) {
					html += '<div class="pt-tip-row"><span>CA Federal Funding</span><b>' + fmtMoney( row.spend ) + '</b></div>';
					html += '<div class="pt-tip-row"><span>Agreements</span><b>' + row.count.toLocaleString() + '</b></div>';
				}
				showTip( html, ev );
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
				var num  = String( f.properties.FED_NUM );
				var row  = ridingData[ num ] || {};
				var name = f.properties.ED_NAMEE || num;
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

	function applyColors() {
		if ( featureRidings ) gRidings.selectAll( '.pt-riding' ).attr( 'fill', ridingFill );
		gCountries.selectAll( '.pt-country' ).attr( 'fill', countryFill );
	}

	/* ── Heatmap contour layer ─────────────────────────────────────────────── */

	/* Current zoom transform — updated on every zoom event so heatmap rebuild
	   knows how far the user has zoomed in. */
	var currentTransform = d3.zoomIdentity;

	function buildHeatmap( forceRebuild ) {
		if ( heatBuilt && ! forceRebuild ) return;
		heatBuilt = true;
		gHeat.selectAll( 'path' ).remove();

		var k   = currentTransform.k;           /* zoom scale factor        */
		var pts = weightedCentroids.map( function ( d ) {
			/* Apply the current zoom transform so points are in screen space */
			var px = projection( d.lonlat );
			if ( ! px ) return null;
			var sx = currentTransform.applyX( px[0] );
			var sy = currentTransform.applyY( px[1] );
			return { x: sx, y: sy, w: d.weight };
		} ).filter( Boolean );
		if ( ! pts.length ) return;

		var maxW = d3.max( pts, function ( d ) { return d.w; } ) || 1;

		/* Bandwidth shrinks as you zoom in so clusters tighten naturally.
		   Base 18px at zoom=1, scales down to ~6px at zoom=6+. */
		var bw = Math.max( 6, 18 / Math.sqrt( k ) );

		var density = d3.contourDensity()
			.x( function ( d ) { return d.x; } )
			.y( function ( d ) { return d.y; } )
			.weight( function ( d ) { return d.w / maxW; } )
			.size( [ W(), H() ] )
			.bandwidth( bw )
			.thresholds( 16 )( pts );

		if ( ! density.length ) return;

		/* Log scale: prevents a single peak from swamping everything else.
		   Adds 1 to avoid log(0). */
		var maxD  = d3.max( density, function ( d ) { return d.value; } ) || 1;
		var logScale = d3.scaleLog()
			.domain( [ 1e-10, maxD ] )
			.range( [ 0, 1 ] )
			.clamp( true );
		var hCol = function ( d ) {
			return d3.interpolateYlOrRd( logScale( d.value ) );
		};

		gHeat.selectAll( 'path' ).data( density ).enter().append( 'path' )
			.attr( 'd', d3.geoPath() )
			.attr( 'fill', hCol )
			.attr( 'opacity', 0.72 );
	}

	/* ── Mode toggle ───────────────────────────────────────────────────────── */
	function setMode( m ) {
		mode = m;
		var bc = document.getElementById( 'ptMapBtnChoropleth' );
		var bh = document.getElementById( 'ptMapBtnHeatmap' );
		if ( bc ) { bc.classList.toggle( 'active', m === 'choropleth' ); bc.setAttribute( 'aria-pressed', String( m === 'choropleth' ) ); }
		if ( bh ) { bh.classList.toggle( 'active', m === 'heatmap' );    bh.setAttribute( 'aria-pressed', String( m === 'heatmap' ) ); }
		if ( m === 'heatmap' ) {
			buildHeatmap( false );
			gHeat.style( 'display', null );
			gRidings.attr( 'opacity', 0.3 );
		} else {
			gHeat.style( 'display', 'none' );
			gRidings.attr( 'opacity', null );
		}
		applyColors();
		renderLegend();
	}

	/* ── Legend ────────────────────────────────────────────────────────────── */
	function renderLegend() {
		var el = document.getElementById( 'ptMapLegend' ); if ( ! el ) return;
		var t  = ( window.PT && PT.chartTheme ) || {};
		var ac = t.accent || '#00a0d2';
		if ( mode === 'choropleth' ) {
			el.innerHTML =
				'<span>Ridings</span>' +
				'<span class="pt-map-legend-bar" style="background:linear-gradient(to right,rgba(0,160,210,.06),' + ac + ')"></span>' +
				'<span style="margin-left:10px">Countries</span>' +
				'<span class="pt-map-legend-bar" style="background:linear-gradient(to right,#1e1a08,#e8920a)"></span>';
		} else {
			el.innerHTML =
				'<span>Low</span>' +
				'<span class="pt-map-legend-bar" style="background:linear-gradient(to right,#000004,#fca50a,#fcffa4)"></span>' +
				'<span>High</span>';
		}
	}

	/* ── Resize ────────────────────────────────────────────────────────────── */
	function onResize() {
		svg.attr( 'width', W() ).attr( 'height', H() );
		projection.translate( [ W() / 2, H() / 2 ] );
		gCountries.selectAll( 'path' ).attr( 'd', geoPath );
		gRidings.selectAll( 'path' ).attr( 'd', geoPath );
		if ( mode === 'heatmap' ) {
			heatBuilt = false;
			buildHeatmap( true );
		}
	}

	/* ── Bootstrap ─────────────────────────────────────────────────────────── */
	svg.attr( 'width', W() ).attr( 'height', H() );

	var totalsUrl = TOTALS || ( REST_ROOT + '/map/riding-totals' );

	Promise.all( [
		fetch( WORLD ).then( function ( r ) { return r.json(); } ),
		fetch( RIDINGS ).then( function ( r ) { return r.json(); } ),
		fetch( totalsUrl ).then( function ( r ) { return r.json(); } )
			.catch( function () {
				return fetch( REST_ROOT + '/map/riding-totals' ).then( function ( r ) { return r.json(); } );
			} )
	] ).then( function ( results ) {
		var worldTopo  = results[0];
		var ridingTopo = results[1];
		var totals     = results[2];

		/* Support both old flat format and new {ridings, countries} format */
		if ( totals && totals.ridings ) {
			ridingData  = totals.ridings  || {};
			countryData = totals.countries || {};
		} else if ( totals && ! totals.code ) {
			ridingData  = totals;
			countryData = {};
		}

		buildScales();

		/* World countries — color by data where available */
		var countries = topojson.feature( worldTopo, worldTopo.objects.countries );
		/* Exclude Canada — riding layer covers it */
		countries.features = countries.features.filter( function ( f ) { return f.id !== 124; } );
		drawCountries( countries );

		/* Canadian ridings */
		var ridingsFc = topojson.feature( ridingTopo, ridingTopo.objects.ridings );
		drawRidings( ridingsFc );

		/* Weighted centroids for heatmap */
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

	/* ── Buttons ───────────────────────────────────────────────────────────── */
	var bc = document.getElementById( 'ptMapBtnChoropleth' );
	var bh = document.getElementById( 'ptMapBtnHeatmap' );
	var br = document.getElementById( 'ptMapBtnReset' );
	if ( bc ) bc.addEventListener( 'click', function () { setMode( 'choropleth' ); } );
	if ( bh ) bh.addEventListener( 'click', function () { setMode( 'heatmap' ); } );
	if ( br ) br.addEventListener( 'click', resetView );

	new ResizeObserver( onResize ).observe( wrap );

} )();
