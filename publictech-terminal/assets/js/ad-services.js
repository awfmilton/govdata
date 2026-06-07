/**
 * GovData Ad Services — Front-End Portal JS
 *
 * Plain ES5 IIFE matching existing app.js conventions.
 * All data is fetched from /govads/v1/me/* REST endpoints.
 */
(function () {
	'use strict';

	var P = window.GOVADS_PORTAL;
	if ( ! P ) { return; }

	// ── Unauthenticated: request-access button ────────────────────────────────
	var reqBtn = document.getElementById( 'adsRequestAccess' );
	if ( reqBtn ) {
		reqBtn.addEventListener( 'click', function () {
			reqBtn.disabled = true;
			reqBtn.textContent = 'Sending…';
			apiFetch( 'POST', '/me/request-access' ).then( function () {
				var msg = document.getElementById( 'adsRequestMsg' );
				if ( msg ) {
					msg.className = 'ads-notice info';
					msg.textContent = 'Request sent! You will receive an email once your account is approved.';
					msg.style.display = '';
				}
				reqBtn.style.display = 'none';
			} ).catch( function () {
				reqBtn.disabled = false;
				reqBtn.textContent = 'Request Advertiser Access';
			} );
		} );
	}

	if ( ! P.canAds ) { return; }

	// ── State ─────────────────────────────────────────────────────────────────
	var REST  = P.rest;
	var NONCE = P.nonce;
	var ads       = [];
	var campaigns = [];
	var stats     = [];
	var statsLoaded = false;

	// ── API helper ────────────────────────────────────────────────────────────
	function apiFetch( method, path, body ) {
		return fetch( REST + path, {
			method:      method,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce':    NONCE,
				'Content-Type':  'application/json',
			},
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( r ) {
			if ( r.status === 204 ) { return null; }
			return r.json();
		} );
	}

	function fmtNum( n ) {
		if ( n >= 1e6 ) { return ( n / 1e6 ).toFixed( 1 ) + 'M'; }
		if ( n >= 1e3 ) { return ( n / 1e3 ).toFixed( 1 ) + 'K'; }
		return String( n );
	}

	function escHtml( s ) {
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function statusBadge( status ) {
		return '<span class="ads-status ' + escHtml( status ) + '">' + escHtml( status ) + '</span>';
	}

	// ── Tab switching ─────────────────────────────────────────────────────────
	var tabBtns  = document.querySelectorAll( '.ads-nav-btn' );
	var tabViews = document.querySelectorAll( '.ads-view' );

	function showTab( name ) {
		tabBtns.forEach( function ( b ) {
			var active = b.dataset.adsTab === name;
			b.classList.toggle( 'active', active );
			b.setAttribute( 'aria-selected', active ? 'true' : 'false' );
		} );
		tabViews.forEach( function ( v ) {
			v.classList.toggle( 'active', v.id === 'view-' + name );
		} );
		if ( name === 'stats' && ! statsLoaded ) {
			loadStats();
		}
	}

	tabBtns.forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			showTab( btn.dataset.adsTab );
		} );
	} );

	var goStatsBtn = document.getElementById( 'adsGoStats' );
	if ( goStatsBtn ) {
		goStatsBtn.addEventListener( 'click', function () { showTab( 'stats' ); } );
	}

	// ── Initial data load ─────────────────────────────────────────────────────
	Promise.all( [
		apiFetch( 'GET', '/me/ads' ),
		apiFetch( 'GET', '/me/campaigns' ),
		apiFetch( 'GET', '/stats?days=30' ),
	] ).then( function ( results ) {
		ads       = Array.isArray( results[0] ) ? results[0] : [];
		campaigns = Array.isArray( results[1] ) ? results[1] : [];
		stats     = Array.isArray( results[2] ) ? results[2] : [];
		statsLoaded = true;
		renderOverview();
		renderAdsList();
		renderCampaigns();
		renderStats();
		populateCampaignDropdown();
		updateNavBadges();
	} ).catch( function ( err ) {
		console.error( 'GovAds portal init error', err );
	} );

	// ── Overview ──────────────────────────────────────────────────────────────
	function renderOverview() {
		var totalImp = 0, totalClk = 0;
		stats.forEach( function ( r ) {
			totalImp += parseInt( r.impressions, 10 ) || 0;
			totalClk += parseInt( r.clicks, 10 )      || 0;
		} );
		var ctr       = totalImp ? ( totalClk / totalImp * 100 ).toFixed( 2 ) : '0.00';
		var activeAds = ads.filter( function ( a ) { return a.status === 'approved'; } ).length;

		setText( 'kpiImp',    fmtNum( totalImp ) );
		setText( 'kpiClk',    fmtNum( totalClk ) );
		setText( 'kpiCtr',    ctr + '%' );
		setText( 'kpiActive', String( activeAds ) );

		// Group stats by date for activity table.
		var byDate = {};
		stats.forEach( function ( r ) {
			var d = r.stat_date;
			if ( ! byDate[ d ] ) { byDate[ d ] = { imp: 0, clk: 0 }; }
			byDate[ d ].imp += parseInt( r.impressions, 10 ) || 0;
			byDate[ d ].clk += parseInt( r.clicks, 10 )      || 0;
		} );

		var dates = Object.keys( byDate ).sort().reverse().slice( 0, 14 );
		var body  = document.getElementById( 'adsActivityBody' );
		if ( ! body ) { return; }

		if ( dates.length === 0 ) {
			body.innerHTML = '<div class="ads-empty">No activity yet — submit and get an ad approved to start tracking.</div>';
			return;
		}

		var rows = dates.map( function ( d ) {
			var r   = byDate[ d ];
			var ctrD = r.imp ? ( r.clk / r.imp * 100 ).toFixed( 2 ) + '%' : '—';
			return '<tr>'
				+ '<td>' + escHtml( d ) + '</td>'
				+ '<td>' + fmtNum( r.imp ) + '</td>'
				+ '<td>' + fmtNum( r.clk ) + '</td>'
				+ '<td>' + ctrD + '</td>'
				+ '</tr>';
		} ).join( '' );

		body.innerHTML = '<table class="ads-table">'
			+ '<thead><tr><th>Date</th><th>Impressions</th><th>Clicks</th><th>CTR</th></tr></thead>'
			+ '<tbody>' + rows + '</tbody>'
			+ '</table>';
	}

	// ── Ads list ──────────────────────────────────────────────────────────────
	function renderAdsList() {
		var body = document.getElementById( 'adsListBody' );
		if ( ! body ) { return; }

		if ( ads.length === 0 ) {
			body.innerHTML = '<div class="ads-empty">No ads yet. Click <strong>+ New Ad</strong> to get started.</div>';
			return;
		}

		var rows = ads.map( function ( ad ) {
			var thumb = ad.image_url
				? '<img class="ads-thumb" src="' + escHtml( ad.image_url ) + '" alt="" loading="lazy">'
				: '<div class="ads-thumb-ph">' + escHtml( ad.type ) + '</div>';

			var campName = '';
			if ( ad.campaign_id ) {
				var camp = campaigns.find( function ( c ) { return c.id === ad.campaign_id; } );
				if ( camp ) { campName = '<div class="sub">↳ ' + escHtml( camp.title ) + '</div>'; }
			}

			var pauseBtn = '';
			if ( ad.status === 'approved' ) {
				pauseBtn = '<button class="ads-btn" data-action="pause" data-id="' + ad.id + '">Pause</button>';
			} else if ( ad.status === 'paused' ) {
				pauseBtn = '<button class="ads-btn" data-action="resume" data-id="' + ad.id + '">Resume</button>';
			}

			return '<tr>'
				+ '<td>' + thumb + '</td>'
				+ '<td><strong>' + escHtml( ad.title ) + '</strong><div class="sub mono">' + escHtml( ad.size ) + '</div>' + campName + '</td>'
				+ '<td>' + statusBadge( ad.status ) + '</td>'
				+ '<td class="mono" style="font-size:10px;color:var(--ink-2)">' + escHtml( ad.created ? ad.created.split( ' ' )[0] : '' ) + '</td>'
				+ '<td><div style="display:flex;gap:6px">'
				+ ( ad.mine !== false ? '<button class="ads-btn" data-action="edit" data-id="' + ad.id + '">Edit</button>' : '' )
				+ pauseBtn
				+ '</div></td>'
				+ '</tr>';
		} ).join( '' );

		body.innerHTML = '<table class="ads-table">'
			+ '<thead><tr><th>Preview</th><th>Ad</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>'
			+ '<tbody>' + rows + '</tbody>'
			+ '</table>';

		// Bind row action buttons.
		body.querySelectorAll( '[data-action]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				handleAdAction( btn.dataset.action, parseInt( btn.dataset.id, 10 ) );
			} );
		} );
	}

	function handleAdAction( action, adId ) {
		if ( action === 'edit' ) {
			var ad = ads.find( function ( a ) { return a.id === adId; } );
			if ( ad ) { openAdForm( ad ); }
			return;
		}
		if ( action === 'pause' || action === 'resume' ) {
			apiFetch( 'POST', '/me/ads/' + adId, { action: action } ).then( function ( res ) {
				if ( res && res.status ) {
					var ad = ads.find( function ( a ) { return a.id === adId; } );
					if ( ad ) { ad.status = res.status; }
					renderAdsList();
				}
			} );
		}
	}

	function updateNavBadges() {
		var pending = ads.filter( function ( a ) { return a.status === 'pending'; } ).length;
		var badge   = document.getElementById( 'navBadgeAds' );
		if ( badge ) {
			badge.textContent = String( pending );
			badge.style.display = pending ? '' : 'none';
		}
	}

	// ── Ad form ───────────────────────────────────────────────────────────────
	var formPanel    = document.getElementById( 'adsFormPanel' );
	var listPanel    = document.getElementById( 'adsListPanel' );

	function openAdForm( ad ) {
		var heading   = document.getElementById( 'adsFormHeading' );
		var editId    = document.getElementById( 'adsEditId' );
		var submitBtn = document.getElementById( 'adsFormSubmitBtn' );

		if ( ad ) {
			if ( heading )   { heading.textContent = 'Edit Ad'; }
			if ( editId )    { editId.value = String( ad.id ); }
			if ( submitBtn ) { submitBtn.textContent = 'Update Ad'; }
			setVal( 'adTitle',     ad.title );
			setVal( 'adType',      ad.type );
			setVal( 'adSize',      ad.size );
			setVal( 'adImageUrl',  ad.image_url );
			setVal( 'adHtml',      '' );
			setVal( 'adTargetUrl', ad.target_url );
			setVal( 'adCampaign',  ad.campaign_id || 0 );
			setVal( 'adStartDate', ad.start_date || '' );
			setVal( 'adEndDate',   ad.end_date   || '' );
			toggleTypeFields( ad.type );
		} else {
			if ( heading )   { heading.textContent = 'Submit New Ad'; }
			if ( editId )    { editId.value = ''; }
			if ( submitBtn ) { submitBtn.textContent = 'Submit for Review'; }
			var form = document.getElementById( 'adsNewAdForm' );
			if ( form ) { form.reset(); }
			toggleTypeFields( 'image' );
		}

		hideMsg( 'adsFormMsg' );
		if ( formPanel ) { formPanel.style.display = ''; }
		showTab( 'ads' );
		if ( formPanel ) { formPanel.scrollIntoView( { behavior: 'smooth', block: 'start' } ); }
	}

	function closeAdForm() {
		if ( formPanel ) { formPanel.style.display = 'none'; }
		var editId = document.getElementById( 'adsEditId' );
		if ( editId ) { editId.value = ''; }
	}

	// Wire open buttons.
	[ 'adsShowNewAd', 'adsOpenForm' ].forEach( function ( id ) {
		var el = document.getElementById( id );
		if ( el ) { el.addEventListener( 'click', function () { openAdForm( null ); } ); }
	} );

	[ 'adsCloseForm', 'adsCancelForm' ].forEach( function ( id ) {
		var el = document.getElementById( id );
		if ( el ) { el.addEventListener( 'click', closeAdForm ); }
	} );

	// Ad type toggle.
	var adTypeEl = document.getElementById( 'adType' );
	if ( adTypeEl ) {
		adTypeEl.addEventListener( 'change', function () {
			toggleTypeFields( adTypeEl.value );
		} );
	}

	function toggleTypeFields( type ) {
		var rowImg  = document.getElementById( 'rowImageUrl' );
		var rowHtml = document.getElementById( 'rowHtml' );
		if ( rowImg )  { rowImg.style.display  = type === 'image' ? '' : 'none'; }
		if ( rowHtml ) { rowHtml.style.display = type === 'html5' ? '' : 'none'; }
	}

	// Ad form submit.
	var adForm = document.getElementById( 'adsNewAdForm' );
	if ( adForm ) {
		adForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var editId = getVal( 'adsEditId' );
			var body = {
				title:       getVal( 'adTitle' ),
				type:        getVal( 'adType' ),
				size:        getVal( 'adSize' ),
				image_url:   getVal( 'adImageUrl' ),
				html:        getVal( 'adHtml' ),
				target_url:  getVal( 'adTargetUrl' ),
				campaign_id: parseInt( getVal( 'adCampaign' ), 10 ) || 0,
				start_date:  getVal( 'adStartDate' ),
				end_date:    getVal( 'adEndDate' ),
			};

			if ( ! body.title || ! body.target_url ) {
				showMsg( 'adsFormMsg', 'error', 'Ad name and destination URL are required.' );
				return;
			}

			var submitBtn = document.getElementById( 'adsFormSubmitBtn' );
			if ( submitBtn ) { submitBtn.disabled = true; submitBtn.textContent = 'Saving…'; }

			var method = 'POST';
			var path   = editId ? '/me/ads/' + editId : '/me/ads';
			if ( editId ) { body.action = 'update'; }

			apiFetch( method, path, body ).then( function ( res ) {
				if ( submitBtn ) { submitBtn.disabled = false; }
				if ( ! res || res.error ) {
					if ( submitBtn ) { submitBtn.textContent = editId ? 'Update Ad' : 'Submit for Review'; }
					showMsg( 'adsFormMsg', 'error', 'Something went wrong. Please try again.' );
					return;
				}
				if ( editId ) {
					// Update in local array.
					ads = ads.map( function ( a ) {
						return a.id === parseInt( editId, 10 ) ? Object.assign( {}, a, body ) : a;
					} );
					showMsg( 'adsFormMsg', 'ok', 'Ad updated.' );
					if ( submitBtn ) { submitBtn.textContent = 'Update Ad'; }
				} else {
					// Prepend new ad.
					ads.unshift( Object.assign( {}, body, { id: res.id, status: 'pending', created: new Date().toISOString().slice( 0, 10 ) } ) );
					closeAdForm();
				}
				renderAdsList();
				renderOverview();
				updateNavBadges();
			} ).catch( function () {
				if ( submitBtn ) {
					submitBtn.disabled   = false;
					submitBtn.textContent = editId ? 'Update Ad' : 'Submit for Review';
				}
				showMsg( 'adsFormMsg', 'error', 'Network error. Please try again.' );
			} );
		} );
	}

	// ── Campaigns ─────────────────────────────────────────────────────────────
	function renderCampaigns() {
		var body = document.getElementById( 'adsCampaignBody' );
		if ( ! body ) { return; }

		if ( campaigns.length === 0 ) {
			body.innerHTML = '<div class="ads-empty">No campaigns yet. Campaigns help you group and schedule multiple ads.</div>';
			return;
		}

		var rows = campaigns.map( function ( c ) {
			var adCount = ads.filter( function ( a ) { return a.campaign_id === c.id; } ).length;
			return '<tr>'
				+ '<td><strong>' + escHtml( c.title ) + '</strong></td>'
				+ '<td>' + statusBadge( c.status ) + '</td>'
				+ '<td class="mono" style="font-size:10px;color:var(--ink-2)">' + escHtml( c.start_date || '—' ) + ' → ' + escHtml( c.end_date || '—' ) + '</td>'
				+ '<td class="mono" style="font-size:10px;color:var(--ink-2)">' + String( adCount ) + ' ad' + ( adCount !== 1 ? 's' : '' ) + '</td>'
				+ '</tr>';
		} ).join( '' );

		body.innerHTML = '<table class="ads-table">'
			+ '<thead><tr><th>Campaign</th><th>Status</th><th>Dates</th><th>Ads</th></tr></thead>'
			+ '<tbody>' + rows + '</tbody>'
			+ '</table>';
	}

	function populateCampaignDropdown() {
		var sel = document.getElementById( 'adCampaign' );
		if ( ! sel ) { return; }
		campaigns.forEach( function ( c ) {
			var opt = document.createElement( 'option' );
			opt.value       = String( c.id );
			opt.textContent = c.title;
			sel.appendChild( opt );
		} );
	}

	// Campaign form toggle.
	var campFormPanel = document.getElementById( 'adsCampaignFormPanel' );
	document.getElementById( 'adsOpenCampaignForm' ) && document.getElementById( 'adsOpenCampaignForm' ).addEventListener( 'click', function () {
		if ( campFormPanel ) { campFormPanel.style.display = ''; }
	} );
	[ 'adsCloseCampaignForm', 'adsCancelCampaign' ].forEach( function ( id ) {
		var el = document.getElementById( id );
		if ( el ) {
			el.addEventListener( 'click', function () {
				if ( campFormPanel ) { campFormPanel.style.display = 'none'; }
			} );
		}
	} );

	// Campaign form submit.
	var campForm = document.getElementById( 'adsNewCampaignForm' );
	if ( campForm ) {
		campForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var title = getVal( 'campTitle' );
			if ( ! title ) {
				showMsg( 'adsCampMsg', 'error', 'Campaign name is required.' );
				return;
			}
			var submitBtn = campForm.querySelector( '[type=submit]' );
			if ( submitBtn ) { submitBtn.disabled = true; submitBtn.textContent = 'Creating…'; }
			apiFetch( 'POST', '/me/campaigns', {
				title:      title,
				start_date: getVal( 'campStart' ),
				end_date:   getVal( 'campEnd' ),
			} ).then( function ( res ) {
				if ( submitBtn ) { submitBtn.disabled = false; submitBtn.textContent = 'Create Campaign'; }
				if ( ! res || res.error ) {
					showMsg( 'adsCampMsg', 'error', 'Something went wrong. Please try again.' );
					return;
				}
				campaigns.push( { id: res.id, title: res.title, status: 'active', start_date: '', end_date: '' } );
				campForm.reset();
				if ( campFormPanel ) { campFormPanel.style.display = 'none'; }
				renderCampaigns();
				populateCampaignDropdown();
			} ).catch( function () {
				if ( submitBtn ) { submitBtn.disabled = false; submitBtn.textContent = 'Create Campaign'; }
				showMsg( 'adsCampMsg', 'error', 'Network error. Please try again.' );
			} );
		} );
	}

	// ── Stats ─────────────────────────────────────────────────────────────────
	function loadStats() {
		var days = getVal( 'statsDays' ) || '30';
		var body = document.getElementById( 'adsStatsBody' );
		if ( body ) { body.innerHTML = '<div class="ads-loading">Loading</div>'; }
		apiFetch( 'GET', '/stats?days=' + days ).then( function ( data ) {
			stats       = Array.isArray( data ) ? data : [];
			statsLoaded = true;
			renderStats();
			renderOverview();
		} );
	}

	var statsDaysSel = document.getElementById( 'statsDays' );
	if ( statsDaysSel ) {
		statsDaysSel.addEventListener( 'change', loadStats );
	}

	function renderStats() {
		var body = document.getElementById( 'adsStatsBody' );
		if ( ! body ) { return; }

		if ( stats.length === 0 ) {
			body.innerHTML = '<div class="ads-empty">No stats available yet. Stats appear the day after your ad starts serving.</div>';
			return;
		}

		// Aggregate per ad.
		var perAd = {};
		stats.forEach( function ( r ) {
			var aid = r.ad_id;
			if ( ! perAd[ aid ] ) { perAd[ aid ] = { imp: 0, clk: 0, daily: [] }; }
			perAd[ aid ].imp  += parseInt( r.impressions, 10 ) || 0;
			perAd[ aid ].clk  += parseInt( r.clicks, 10 )      || 0;
			perAd[ aid ].daily.push( parseInt( r.impressions, 10 ) || 0 );
		} );

		// Sort by impressions desc.
		var adIds = Object.keys( perAd ).sort( function ( a, b ) {
			return perAd[ b ].imp - perAd[ a ].imp;
		} );

		var rows = adIds.map( function ( aid ) {
			var d    = perAd[ aid ];
			var ctr  = d.imp ? ( d.clk / d.imp * 100 ).toFixed( 2 ) + '%' : '—';
			var ad   = ads.find( function ( a ) { return String( a.id ) === String( aid ); } );
			var name = ad ? escHtml( ad.title ) : 'Ad #' + aid;
			var size = ad ? '<div class="sub mono">' + escHtml( ad.size ) + '</div>' : '';
			var spark = buildSparkline( d.daily.reverse() );

			return '<tr>'
				+ '<td><strong>' + name + '</strong>' + size + '</td>'
				+ '<td>' + fmtNum( d.imp ) + '</td>'
				+ '<td>' + fmtNum( d.clk ) + '</td>'
				+ '<td>' + ctr + '</td>'
				+ '<td class="ads-spark-cell">' + spark + '</td>'
				+ '</tr>';
		} ).join( '' );

		body.innerHTML = '<table class="ads-table">'
			+ '<thead><tr><th>Ad</th><th>Impressions</th><th>Clicks</th><th>CTR</th><th>Trend</th></tr></thead>'
			+ '<tbody>' + rows + '</tbody>'
			+ '</table>';
	}

	function buildSparkline( vals ) {
		var n = vals.length;
		if ( n < 2 ) { return '—'; }
		var max = Math.max.apply( null, vals ) || 1;
		var w = 80, h = 24;
		var pts = vals.map( function ( v, i ) {
			var x = Math.round( i / ( n - 1 ) * w );
			var y = Math.round( ( 1 - v / max ) * ( h - 4 ) + 2 );
			return x + ',' + y;
		} ).join( ' ' );
		return '<svg width="' + w + '" height="' + h + '" aria-hidden="true">'
			+ '<polyline points="' + pts + '" fill="none" stroke="var(--accent)" stroke-width="1.5" stroke-linejoin="round"/>'
			+ '</svg>';
	}

	// ── DOM helpers ───────────────────────────────────────────────────────────
	function setText( id, text ) {
		var el = document.getElementById( id );
		if ( el ) { el.textContent = text; }
	}

	function getVal( id ) {
		var el = document.getElementById( id );
		return el ? el.value : '';
	}

	function setVal( id, val ) {
		var el = document.getElementById( id );
		if ( el ) { el.value = val == null ? '' : val; }
	}

	function showMsg( id, type, text ) {
		var el = document.getElementById( id );
		if ( ! el ) { return; }
		el.className   = 'ads-notice ' + type;
		el.textContent = text;
		el.style.display = '';
	}

	function hideMsg( id ) {
		var el = document.getElementById( id );
		if ( el ) { el.style.display = 'none'; }
	}
})();
