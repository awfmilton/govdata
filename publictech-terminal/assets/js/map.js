/* PublicTech Spending Map — D3 world map + Canadian-riding choropleth + heatmap.
   Depends on: d3, topojson-client, publictech-charts.
   Config via window.PT_MAP (wp_localize_script). */
(function () {
	'use strict';

	var d3       = window.d3;
	var topojson = window.topojson;
	if ( ! d3 || ! topojson ) { console.error( '[PT Map] d3 or topojson-client not loaded.' ); return; }

	var cfg       = window.PT_MAP || {};
	var REST_ROOT = ( cfg.rest_root || '' ).replace( /\/$/, '' );
	var RIDINGS   = cfg.ridings_url || '';
	var TOTALS    = cfg.totals_url  || '';
	var WORLD     = 'https://cdn.jsdelivr.net/npm/world-atlas@2/countries-110m.json';

	/* ── State ─────────────────────────────────────────────────────────────── */
	var ridingData        = {};
	var countryData       = {};
	var mode              = 'choropleth';
	var featureRidings    = null;
	var ridingCentroids   = [];     /* only riding-level points for heatmap */
	var heatBuilt         = false;
	var currentTransform  = d3.zoomIdentity;
	var zoomTimer         = null;
	var selectedEl        = null;   /* currently highlighted path element */
	var ridingScale, countryScale, ridingHeatScale, countryHeatScale;

	/* ── Normalize world-atlas numeric ID (may be zero-padded: "004" → "4") ─ */
	function normId( id ) { return String( parseInt( id, 10 ) ); }

	/* ── DOM ────────────────────────────────────────────────────────────────── */
	var wrap    = document.getElementById( 'ptMapWrap' );
	var loading = document.getElementById( 'ptMapLoading' );
	var tipEl   = document.getElementById( 'ptMapTip' );
	var svg     = d3.select( '#ptMapSvg' );

	function W() { return wrap.clientWidth  || 960; }
	function H() { return wrap.clientHeight || 560; }

	/* ── SVG glow filter ───────────────────────────────────────────────────── */
	var defs  = svg.append( 'defs' );
	var fGlow = defs.append( 'filter' )
		.attr( 'id', 'ptGlow' ).attr( 'x', '-40%' ).attr( 'y', '-40%' )
		.attr( 'width', '180%' ).attr( 'height', '180%' );
	fGlow.append( 'feGaussianBlur' ).attr( 'in', 'SourceGraphic' ).attr( 'stdDeviation', '3.5' ).attr( 'result', 'blur' );
	var fm = fGlow.append( 'feMerge' );
	fm.append( 'feMergeNode' ).attr( 'in', 'blur' );
	fm.append( 'feMergeNode' ).attr( 'in', 'SourceGraphic' );

	/* ── Projection + layers ────────────────────────────────────────────────── */
	var projection = d3.geoNaturalEarth1().scale( 153 ).translate( [ W() / 2, H() / 2 ] );
	var geoPath    = d3.geoPath().projection( projection );

	var gRoot      = svg.append( 'g' ).attr( 'class', 'pt-map-root' );
	var gCountries = gRoot.append( 'g' ).attr( 'class', 'pt-layer-countries' );
	var gRidings   = gRoot.append( 'g' ).attr( 'class', 'pt-layer-ridings' );
	var gHeat      = gRoot.append( 'g' ).attr( 'class', 'pt-layer-heatmap' ).style( 'display', 'none' );

	/* ── Zoom ───────────────────────────────────────────────────────────────── */
	var zoom = d3.zoom().scaleExtent( [ 1, 20 ] ).on( 'zoom', function ( ev ) {
		currentTransform = ev.transform;
		gRoot.attr( 'transform', ev.transform );
		if ( mode === 'heatmap' ) {
			clearTimeout( zoomTimer );
			zoomTimer = setTimeout( function () { heatBuilt = false; buildHeatmap(); }, 250 );
		}
	} );
	svg.call( zoom ).on( 'dblclick.zoom', null );

	function resetView() {
		clearSelection();
		svg.transition().duration( 600 ).call( zoom.transform, d3.zoomIdentity );
	}

	/* ── Zoom to a GeoJSON feature ──────────────────────────────────────────── */
	function zoomToFeature( feature ) {
		var bounds = geoPath.bounds( feature );
		var dx = bounds[1][0] - bounds[0][0];
		var dy = bounds[1][1] - bounds[0][1];
		if ( ! dx || ! dy ) return;
		var cx = ( bounds[0][0] + bounds[1][0] ) / 2;
		var cy = ( bounds[0][1] + bounds[1][1] ) / 2;
		var scale = Math.max( 1.5, Math.min( 18, 0.85 / Math.max( dx / W(), dy / H() ) ) );
		svg.transition().duration( 750 ).call(
			zoom.transform,
			d3.zoomIdentity.translate( W() / 2 - scale * cx, H() / 2 - scale * cy ).scale( scale )
		);
	}

	/* ── Selection / highlight ──────────────────────────────────────────────── */
	function clearSelection() {
		if ( selectedEl ) { d3.select( selectedEl ).classed( 'selected', false ); selectedEl = null; }
		closePanel();
	}
	function selectEl( el ) {
		if ( selectedEl && selectedEl !== el ) d3.select( selectedEl ).classed( 'selected', false );
		selectedEl = el;
		d3.select( el ).classed( 'selected', true );
	}

	/* ── Tooltip ────────────────────────────────────────────────────────────── */
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

	/* ── Formatters ─────────────────────────────────────────────────────────── */
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

	/* ── Color scales ───────────────────────────────────────────────────────── */
	function buildScales() {
		var t      = ( window.PT && PT.chartTheme ) || {};
		var accent = t.accent || '#00a0d2';
		var hex    = accent.replace( '#', '' );
		var ar = parseInt( hex.slice( 0, 2 ), 16 );
		var ag = parseInt( hex.slice( 2, 4 ), 16 );
		var ab = parseInt( hex.slice( 4, 6 ), 16 );

		var rSpends = Object.values( ridingData ).map( function ( d ) { return d.spend || 0; } );
		var cSpends = Object.values( countryData ).map( function ( d ) { return d.spend || 0; } ).filter( Boolean );
		var maxR = d3.max( rSpends ) || 1;
		var maxC = d3.max( cSpends ) || 1;
		var minC = Math.max( 1, d3.min( cSpends ) || 1 );

		/* Ridings: linear accent scale */
		ridingScale = d3.scaleSequential()
			.domain( [ 0, maxR ] )
			.interpolator( function ( v ) {
				return 'rgba(' + ar + ',' + ag + ',' + ab + ',' + ( 0.08 + v * 0.84 ).toFixed( 3 ) + ')';
			} );

		/* Countries: log scale → opaque rgb interpolation from near-black to amber */
		var logC = d3.scaleLog().domain( [ minC, maxC ] ).range( [ 0, 1 ] ).clamp( true );
		var intC = d3.interpolateRgb( '#1e1a08', '#e8920a' );
		countryScale = function ( spend ) { return intC( logC( Math.max( spend, minC ) ) ); };

		/* Heatmap scales */
		ridingHeatScale  = d3.scaleSequential( d3.interpolateInferno ).domain( [ 0, maxR ] );
		var logCH = d3.scaleLog().domain( [ minC, maxC ] ).range( [ 0, 1 ] ).clamp( true );
		countryHeatScale = function ( spend ) { return d3.interpolateInferno( logCH( Math.max( spend, minC ) ) ); };
	}

	function countryFill( f ) {
		var row = countryData[ normId( f.id ) ];
		if ( ! row || ! row.spend ) return '#1d2029';
		return mode === 'heatmap' ? ( countryHeatScale ? countryHeatScale( row.spend ) : '#1d2029' )
		                           : ( countryScale    ? countryScale( row.spend )     : '#1d2029' );
	}
	function ridingFill( f ) {
		var row = ridingData[ String( f.properties.FED_NUM ) ];
		if ( ! row ) return 'rgba(120,130,150,0.12)';
		return mode === 'heatmap' ? ridingHeatScale( row.spend ) : ridingScale( row.spend );
	}

	/* ── Draw world countries ───────────────────────────────────────────────── */
	function drawCountries( fc ) {
		gCountries.selectAll( '.pt-country' ).data( fc.features ).enter().append( 'path' )
			.attr( 'class', 'pt-country' )
			.attr( 'd', geoPath )
			.style( 'fill', countryFill )
			.on( 'mouseenter', function () { d3.select( this ).classed( 'hovered', true ); } )
			.on( 'mousemove',  function ( ev, f ) {
				var row  = countryData[ normId( f.id ) ];
				var name = ( row && row.name ) ? row.name : ( 'Region #' + f.id );
				var html = '<div class="pt-tip-title">' + esc( name ) + '</div>';
				if ( row ) {
					html += '<div class="pt-tip-row"><span>CA Federal Funding</span><b>' + fmtMoney( row.spend ) + '</b></div>';
					html += '<div class="pt-tip-row"><span>Agreements</span><b>' + row.count.toLocaleString() + '</b></div>';
					html += '<div class="pt-tip-row" style="margin-top:4px;font-size:10px;color:var(--ink2)">Click for breakdown</div>';
				}
				showTip( html, ev );
			} )
			.on( 'mouseleave', function () { hideTip(); d3.select( this ).classed( 'hovered', false ); } )
			.on( 'click', function ( ev, f ) {
				ev.stopPropagation();
				var row = countryData[ normId( f.id ) ];
				if ( ! row ) return;
				selectEl( this );
				zoomToFeature( f );
				openCountryPanel( row.name || ( 'Region #' + f.id ), row );
			} );
	}

	/* ── Draw Canadian ridings ──────────────────────────────────────────────── */
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
					'<div class="pt-tip-row"><span>Agreements</span><b>' + ( row.count || 0 ).toLocaleString() + '</b></div>' +
					'<div class="pt-tip-row" style="margin-top:4px;font-size:10px;color:var(--ink2)">Click for breakdown</div>',
					ev
				);
			} )
			.on( 'mouseleave', function () { hideTip(); d3.select( this ).classed( 'hovered', false ); } )
			.on( 'click', function ( ev, f ) {
				ev.stopPropagation();
				var num  = String( f.properties.FED_NUM );
				var row  = ridingData[ num ] || {};
				var name = f.properties.ED_NAMEE || num;
				selectEl( this );
				zoomToFeature( f );
				openRidingPanel( name, num, row );
			} );
	}

	function applyColors() {
		if ( featureRidings ) gRidings.selectAll( '.pt-riding' ).attr( 'fill', ridingFill );
		gCountries.selectAll( '.pt-country' ).style( 'fill', countryFill );
	}

	/* ── Heatmap (riding-level only — country centroids excluded) ───────────── */
	function buildHeatmap() {
		if ( heatBuilt ) return;
		heatBuilt = true;
		gHeat.selectAll( 'path' ).remove();

		var k = currentTransform.k;

		/* Only show density contours when zoomed in (k ≥ 1.8).
		   At world zoom the choropleth country fills are the "heatmap". */
		if ( k < 1.8 ) return;

		var pts = ridingCentroids.map( function ( d ) {
			var px = projection( d.lonlat );
			if ( ! px ) return null;
			return {
				x: currentTransform.applyX( px[0] ),
				y: currentTransform.applyY( px[1] ),
				w: d.weight
			};
		} ).filter( Boolean );
		if ( ! pts.length ) return;

		var maxW = d3.max( pts, function ( d ) { return d.w; } ) || 1;
		/* Bandwidth: tighter at higher zoom so detail shows through */
		var bw = Math.max( 5, 14 / Math.sqrt( k ) );

		var density = d3.contourDensity()
			.x( function ( d ) { return d.x; } )
			.y( function ( d ) { return d.y; } )
			.weight( function ( d ) { return d.w / maxW; } )
			.size( [ W(), H() ] )
			.bandwidth( bw )
			.thresholds( 14 )( pts );
		if ( ! density.length ) return;

		/* Log scale prevents one hot spot from washing out the rest */
		var maxD = d3.max( density, function ( d ) { return d.value; } ) || 1;
		var logD = d3.scaleLog().domain( [ 1e-10, maxD ] ).range( [ 0, 1 ] ).clamp( true );
		gHeat.selectAll( 'path' ).data( density ).enter().append( 'path' )
			.attr( 'd', d3.geoPath() )
			.attr( 'fill', function ( d ) { return d3.interpolateYlOrRd( logD( d.value ) ); } )
			.attr( 'opacity', 0.75 );
	}

	/* ── Mode toggle ────────────────────────────────────────────────────────── */
	function setMode( m ) {
		mode = m;
		var bc = document.getElementById( 'ptMapBtnChoropleth' );
		var bh = document.getElementById( 'ptMapBtnHeatmap' );
		if ( bc ) { bc.classList.toggle( 'active', m === 'choropleth' ); bc.setAttribute( 'aria-pressed', String( m === 'choropleth' ) ); }
		if ( bh ) { bh.classList.toggle( 'active', m === 'heatmap' );    bh.setAttribute( 'aria-pressed', String( m === 'heatmap' ) ); }
		if ( m === 'heatmap' ) {
			heatBuilt = false;
			buildHeatmap();
			gHeat.style( 'display', null );
			gRidings.attr( 'opacity', 0.35 );
		} else {
			gHeat.style( 'display', 'none' );
			gRidings.attr( 'opacity', null );
		}
		applyColors();
		renderLegend();
	}

	/* ── Legend ─────────────────────────────────────────────────────────────── */
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
				'<span>High</span>' +
				'<span style="margin-left:10px;font-size:9px;color:var(--ink2)">Zoom into Canada for riding detail</span>';
		}
	}

	/* ── Resize ─────────────────────────────────────────────────────────────── */
	function onResize() {
		svg.attr( 'width', W() ).attr( 'height', H() );
		projection.translate( [ W() / 2, H() / 2 ] );
		gCountries.selectAll( 'path' ).attr( 'd', geoPath );
		gRidings.selectAll( 'path' ).attr( 'd', geoPath );
		if ( mode === 'heatmap' ) { heatBuilt = false; buildHeatmap(); }
	}

	/* ═══════════════════════════════════════════════════════════════════════════
	   SIDE PANEL — country / riding detail
	 ═══════════════════════════════════════════════════════════════════════════ */

	var panel     = document.getElementById( 'ptMapPanel' );
	var panelBody = document.getElementById( 'ptMapPanelBody' );

	function closePanel() {
		if ( panel ) panel.classList.remove( 'open' );
	}
	function openPanel( html ) {
		if ( ! panel || ! panelBody ) return;
		panelBody.innerHTML = html;
		panel.classList.add( 'open' );
	}

	/* ── Country panel ──────────────────────────────────────────────────────── */
	function openCountryPanel( name, summaryRow ) {
		openPanel(
			'<div class="ptmp-loading"><span class="pt-loading">Loading…</span></div>'
		);
		/* Fetch detailed breakdown from REST */
		fetch( REST_ROOT + '/map/country/' + encodeURIComponent( summaryRow.cc || '' ) )
			.then( function ( r ) { return r.ok ? r.json() : null; } )
			.then( function ( data ) {
				if ( ! data ) {
					openPanel( buildCountrySummary( name, summaryRow, null ) );
					return;
				}
				openPanel( buildCountrySummary( name, summaryRow, data ) );
			} )
			.catch( function () {
				openPanel( buildCountrySummary( name, summaryRow, null ) );
			} );
	}

	function buildCountrySummary( name, row, data ) {
		var h = '';
		h += '<div class="ptmp-title">' + esc( name ) + '</div>';
		h += '<div class="ptmp-kpis">';
		h += '<div class="ptmp-kpi"><div class="ptmp-kv">' + fmtMoney( row.spend || 0 ) + '</div><div class="ptmp-kl">Total Funding</div></div>';
		h += '<div class="ptmp-kpi"><div class="ptmp-kv">' + ( row.count || 0 ).toLocaleString() + '</div><div class="ptmp-kl">Agreements</div></div>';
		h += '</div>';

		if ( ! data ) {
			h += '<div class="ptmp-section">Detailed breakdown unavailable.</div>';
			return h;
		}

		/* By fiscal year */
		if ( data.by_fiscal_year && data.by_fiscal_year.length ) {
			h += '<div class="ptmp-section">';
			h += '<div class="ptmp-sh">By Fiscal Year</div>';
			h += '<table class="ptmp-table"><thead><tr><th>Year</th><th>Funding</th><th>Count</th></tr></thead><tbody>';
			data.by_fiscal_year.slice( 0, 10 ).forEach( function ( r ) {
				h += '<tr><td>' + esc( r.year ) + '</td><td>' + fmtMoney( r.spend ) + '</td><td>' + r.count.toLocaleString() + '</td></tr>';
			} );
			h += '</tbody></table></div>';
		}

		/* Top programs */
		if ( data.top_programs && data.top_programs.length ) {
			h += '<div class="ptmp-section">';
			h += '<div class="ptmp-sh">Top Programs</div>';
			data.top_programs.slice( 0, 8 ).forEach( function ( p ) {
				h += '<div class="ptmp-prog">';
				h += '<div class="ptmp-pn">' + esc( p.name ) + '</div>';
				if ( p.purpose ) h += '<div class="ptmp-pp">' + esc( p.purpose.slice( 0, 160 ) ) + ( p.purpose.length > 160 ? '…' : '' ) + '</div>';
				h += '<div class="ptmp-pm">' + fmtMoney( p.spend ) + ' · ' + p.count.toLocaleString() + ' agreements</div>';
				h += '</div>';
			} );
			h += '</div>';
		}

		/* Recent large agreements */
		if ( data.recent && data.recent.length ) {
			h += '<div class="ptmp-section">';
			h += '<div class="ptmp-sh">Largest Agreements</div>';
			h += '<table class="ptmp-table"><thead><tr><th>Year</th><th>Amount</th><th>Program</th></tr></thead><tbody>';
			data.recent.slice( 0, 8 ).forEach( function ( r ) {
				h += '<tr>';
				h += '<td>' + esc( r.fiscal_year ) + '</td>';
				h += '<td>' + fmtMoney( r.value ) + '</td>';
				h += '<td title="' + esc( r.title || r.recipient ) + '">' + esc( ( r.program || r.title || '—' ).slice( 0, 35 ) ) + '</td>';
				h += '</tr>';
			} );
			h += '</tbody></table></div>';
		}

		return h;
	}

	/* ── Riding panel ───────────────────────────────────────────────────────── */
	function openRidingPanel( name, ridingNum, summaryRow ) {
		openPanel( '<div class="ptmp-loading"><span class="pt-loading">Loading…</span></div>' );
		fetch( REST_ROOT + '/riding/' + encodeURIComponent( ridingNum ) + '/report' )
			.then( function ( r ) { return r.ok ? r.json() : null; } )
			.then( function ( data ) { openPanel( buildRidingPanel( name, summaryRow, data ) ); } )
			.catch( function ()      { openPanel( buildRidingPanel( name, summaryRow, null ) ); } );
	}

	function buildRidingPanel( name, row, data ) {
		var h = '';
		h += '<div class="ptmp-title">' + esc( name ) + '</div>';
		if ( row.province ) h += '<div class="ptmp-sub">' + esc( row.province ) + '</div>';
		h += '<div class="ptmp-kpis">';
		h += '<div class="ptmp-kpi"><div class="ptmp-kv">' + fmtMoney( row.spend || 0 ) + '</div><div class="ptmp-kl">Total Funding</div></div>';
		h += '<div class="ptmp-kpi"><div class="ptmp-kv">' + ( row.count || 0 ).toLocaleString() + '</div><div class="ptmp-kl">Agreements</div></div>';
		h += '</div>';

		if ( ! data ) {
			h += '<div class="ptmp-section">Detailed breakdown unavailable.</div>';
			return h;
		}

		/* By year */
		if ( data.by_year && data.by_year.length ) {
			h += '<div class="ptmp-section">';
			h += '<div class="ptmp-sh">By Year</div>';
			h += '<table class="ptmp-table"><thead><tr><th>Year</th><th>Funding</th><th>Count</th></tr></thead><tbody>';
			var sorted = data.by_year.slice().sort( function ( a, b ) { return b.year - a.year; } );
			sorted.slice( 0, 10 ).forEach( function ( r ) {
				h += '<tr><td>' + r.year + '</td><td>' + fmtMoney( r.total ) + '</td><td>' + ( r.positive_agreements || 0 ).toLocaleString() + '</td></tr>';
			} );
			h += '</tbody></table></div>';
		}

		/* Top recipients */
		if ( data.top_recipients && data.top_recipients.length ) {
			h += '<div class="ptmp-section">';
			h += '<div class="ptmp-sh">Top Recipients</div>';
			data.top_recipients.slice( 0, 6 ).forEach( function ( r ) {
				h += '<div class="ptmp-prog">';
				h += '<div class="ptmp-pn">' + esc( r.name ) + '</div>';
				h += '<div class="ptmp-pm">' + fmtMoney( r.total ) + ' · ' + r.agreements.toLocaleString() + ' agreements</div>';
				h += '</div>';
			} );
			h += '</div>';
		}

		/* Top programs */
		if ( data.top_programs && data.top_programs.length ) {
			h += '<div class="ptmp-section">';
			h += '<div class="ptmp-sh">Top Programs</div>';
			data.top_programs.slice( 0, 6 ).forEach( function ( p ) {
				h += '<div class="ptmp-prog">';
				h += '<div class="ptmp-pn">' + esc( p.name ) + '</div>';
				h += '<div class="ptmp-pm">' + fmtMoney( p.total ) + ' · ' + p.agreements.toLocaleString() + ' agreements</div>';
				h += '</div>';
			} );
			h += '</div>';
		}

		return h;
	}

	/* ── Bootstrap ──────────────────────────────────────────────────────────── */
	svg.attr( 'width', W() ).attr( 'height', H() );

	var totalsUrl = TOTALS || ( REST_ROOT + '/map/riding-totals' );

	Promise.all( [
		fetch( WORLD ).then( function ( r ) { return r.json(); } ),
		fetch( RIDINGS ).then( function ( r ) { return r.json(); } ),
		fetch( totalsUrl ).then( function ( r ) { return r.json(); } )
			.catch( function () { return fetch( REST_ROOT + '/map/riding-totals' ).then( function ( r ) { return r.json(); } ); } )
	] ).then( function ( results ) {
		var worldTopo  = results[0];
		var ridingTopo = results[1];
		var totals     = results[2];

		if ( totals && totals.ridings ) {
			ridingData  = totals.ridings   || {};
			countryData = totals.countries || {};
		} else if ( totals && ! totals.code ) {
			ridingData = totals; countryData = {};
		}

		/* Attach alpha-2 codes to countryData entries for the REST fetch */
		/* The JSON keys are ISO numeric; we need to reverse-map to alpha-2. */
		/* We store .cc on each row by checking PT_MAP iso map if available. */
		/* Simpler: just build numeric→alpha2 from the static JSON's key → loop */
		/* Actually: the PHP already wrote .cc? No. We'll pass numeric as param. */
		/* Map endpoint accepts alpha-2; we need to look it up. Build reverse map. */
		var numericToAlpha2 = {};
		/* We'll use the country name data to reverse-map — but we don't have alpha2 in JSON */
		/* Simplest fix: embed a compact numeric→alpha2 in map.js */
		var ISO_NUM_TO_ALPHA2 = {
			4:'AF',8:'AL',12:'DZ',20:'AD',24:'AO',28:'AG',31:'AZ',32:'AR',36:'AU',40:'AT',
			44:'BS',48:'BH',50:'BD',51:'AM',52:'BB',56:'BE',60:'BM',64:'BT',68:'BO',70:'BA',
			72:'BW',76:'BR',84:'BZ',90:'SB',96:'BN',100:'BG',104:'MM',108:'BI',112:'BY',
			116:'KH',120:'CM',132:'CV',140:'CF',144:'LK',148:'TD',152:'CL',156:'CN',158:'TW',
			170:'CO',174:'KM',178:'CG',180:'CD',188:'CR',191:'HR',192:'CU',196:'CY',203:'CZ',
			204:'BJ',208:'DK',212:'DM',214:'DO',218:'EC',222:'SV',226:'GQ',231:'ET',232:'ER',
			233:'EE',238:'FK',242:'FJ',246:'FI',250:'FR',262:'DJ',266:'GA',268:'GE',270:'GM',
			275:'PS',276:'DE',288:'GH',296:'KI',300:'GR',304:'GL',308:'GD',316:'GU',320:'GT',
			324:'GN',328:'GY',332:'HT',340:'HN',344:'HK',348:'HU',352:'IS',356:'IN',360:'ID',
			364:'IR',368:'IQ',372:'IE',376:'IL',380:'IT',384:'CI',388:'JM',392:'JP',398:'KZ',
			400:'JO',404:'KE',408:'KP',410:'KR',414:'KW',417:'KG',418:'LA',422:'LB',426:'LS',
			428:'LV',430:'LR',434:'LY',438:'LI',440:'LT',442:'LU',446:'MO',450:'MG',454:'MW',
			458:'MY',462:'MV',466:'ML',470:'MT',478:'MR',480:'MU',484:'MX',492:'MC',496:'MN',
			498:'MD',499:'ME',504:'MA',508:'MZ',512:'OM',516:'NA',520:'NR',524:'NP',528:'NL',
			540:'NC',548:'VU',554:'NZ',558:'NI',562:'NE',566:'NG',578:'NO',583:'FM',584:'MH',
			585:'PW',586:'PK',591:'PA',598:'PG',600:'PY',604:'PE',608:'PH',616:'PL',620:'PT',
			624:'GW',626:'TL',630:'PR',634:'QA',642:'RO',643:'RU',646:'RW',659:'KN',662:'LC',
			670:'VC',674:'SM',678:'ST',682:'SA',686:'SN',688:'RS',690:'SC',694:'SL',702:'SG',
			703:'SK',704:'VN',705:'SI',706:'SO',710:'ZA',716:'ZW',724:'ES',728:'SS',729:'SD',
			732:'EH',740:'SR',752:'SE',756:'CH',760:'SY',762:'TJ',764:'TH',768:'TG',776:'TO',
			780:'TT',784:'AE',788:'TN',792:'TR',795:'TM',800:'UG',804:'UA',807:'MK',818:'EG',
			826:'GB',834:'TZ',840:'US',854:'BF',858:'UY',860:'UZ',862:'VE',882:'WS',887:'YE',
			894:'ZM'
		};
		/* Attach .cc to each countryData row */
		Object.keys( countryData ).forEach( function ( numKey ) {
			var alpha2 = ISO_NUM_TO_ALPHA2[ parseInt( numKey, 10 ) ];
			if ( alpha2 ) countryData[ numKey ].cc = alpha2;
		} );

		buildScales();

		/* World countries — exclude Canada (id=124); ridings layer covers it */
		var countries = topojson.feature( worldTopo, worldTopo.objects.countries );
		countries.features = countries.features.filter( function ( f ) { return parseInt( f.id, 10 ) !== 124; } );
		drawCountries( countries );

		/* Canadian ridings */
		var ridingsFc = topojson.feature( ridingTopo, ridingTopo.objects.ridings );
		drawRidings( ridingsFc );

		/* Riding centroids for heatmap (country centroids NOT included) */
		ridingsFc.features.forEach( function ( f ) {
			var row = ridingData[ String( f.properties.FED_NUM ) ];
			if ( row && row.spend ) {
				var c = d3.geoCentroid( f );
				if ( c ) ridingCentroids.push( { lonlat: c, weight: row.spend } );
			}
		} );

		loading.classList.add( 'hidden' );
		renderLegend();

	} ).catch( function ( err ) {
		console.error( '[PT Map]', err );
		var s = loading.querySelector( 'span' );
		if ( s ) { s.textContent = 'Failed to load map. Please refresh.'; s.style.color = '#ff6b6b'; s.className = ''; }
	} );

	/* ── Buttons ────────────────────────────────────────────────────────────── */
	var bc = document.getElementById( 'ptMapBtnChoropleth' );
	var bh = document.getElementById( 'ptMapBtnHeatmap' );
	var br = document.getElementById( 'ptMapBtnReset' );
	var bx = document.getElementById( 'ptMapPanelClose' );
	if ( bc ) bc.addEventListener( 'click', function () { setMode( 'choropleth' ); } );
	if ( bh ) bh.addEventListener( 'click', function () { setMode( 'heatmap' );    } );
	if ( br ) br.addEventListener( 'click', resetView );
	if ( bx ) bx.addEventListener( 'click', clearSelection );

	/* Click on SVG background clears selection */
	svg.on( 'click', function ( ev ) {
		if ( ev.target === svg.node() || d3.select( ev.target ).classed( 'pt-map-root' ) ) {
			clearSelection();
		}
	} );

	new ResizeObserver( onResize ).observe( wrap );

} )();
