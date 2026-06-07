/**
 * GovData Ads Client — slot loader for partner sites.
 *
 * Mirrors the hub's govads-loader.js but reads hub URL and API key from the
 * slot's data-hub and data-key attributes (server-rendered by PHP).
 * This lets a single script work for any zone without localisation.
 */
(function () {
	'use strict';

	// ── Session ID ────────────────────────────────────────────────────────────

	function getSessionId() {
		var match = document.cookie.match( /(?:^|;\s*)gac_sid=([^;]+)/ );
		if ( match ) { return match[1]; }
		var sid = 'gcs_' + Math.random().toString( 36 ).slice( 2, 18 );
		var exp = new Date( Date.now() + 30 * 86400 * 1000 ).toUTCString();
		document.cookie = 'gac_sid=' + sid + '; expires=' + exp + '; path=/; SameSite=Lax';
		return sid;
	}

	var SESSION_ID = getSessionId();

	// ── Slot filling ──────────────────────────────────────────────────────────

	function fillSlot( slot ) {
		if ( slot.dataset.filled ) { return; }
		slot.dataset.filled = '1';

		var zone   = slot.dataset.zone;
		var hubUrl = slot.dataset.hub;
		var apiKey = slot.dataset.key;

		if ( ! zone || ! hubUrl || ! apiKey ) { return; }

		var serveUrl = hubUrl.replace( /\/+$/, '' ) + '/wp-json/govads/v1/serve?zone=' + encodeURIComponent( zone );

		fetch( serveUrl, {
			headers: { 'X-GovAds-Site-Key': apiKey }
		} )
		.then( function ( r ) { return r.status === 200 ? r.json() : null; } )
		.then( function ( ad ) {
			if ( ! ad ) { slot.style.display = 'none'; return; }
			slot.innerHTML = buildAdHtml( ad );
			slot.dataset.adId   = ad.ad_id;
			slot.dataset.zoneId = ad.zone_id;

			var link = slot.querySelector( '.gac-link' );
			if ( link ) {
				link.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					sendBeaconTrack( 'click', ad, hubUrl, apiKey );
					setTimeout( function () {
						window.open( ad.target_url, '_blank', 'noopener,noreferrer' );
					}, 60 );
				} );
			}
			observeImpression( slot, ad, hubUrl, apiKey );
		} )
		.catch( function () { slot.style.display = 'none'; } );
	}

	function buildAdHtml( ad ) {
		var label = '<span class="gac-label" aria-hidden="true">AD</span>';
		if ( ad.type === 'image' && ad.image_url ) {
			return '<a class="gac-link gac-img-wrap" href="' + escAttr( ad.target_url ) + '" target="_blank" rel="noopener sponsored">'
				+ '<img src="' + escAttr( ad.image_url ) + '" alt="" loading="lazy" width="' + ad.width + '" height="' + ad.height + '">'
				+ label + '</a>';
		}
		if ( ad.type === 'html5' && ad.html ) {
			return '<div class="gac-link gac-html-wrap">' + ad.html + label + '</div>';
		}
		return '';
	}

	// ── Impression tracking ───────────────────────────────────────────────────

	function observeImpression( slot, ad, hubUrl, apiKey ) {
		if ( ! window.IntersectionObserver ) {
			sendBeaconTrack( 'impression', ad, hubUrl, apiKey );
			return;
		}
		var timer = null;
		var io = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.intersectionRatio >= 0.5 ) {
					if ( ! timer ) {
						timer = setTimeout( function () {
							sendBeaconTrack( 'impression', ad, hubUrl, apiKey );
							io.disconnect();
						}, 1000 );
					}
				} else {
					if ( timer ) { clearTimeout( timer ); timer = null; }
				}
			} );
		}, { threshold: [ 0, 0.5, 1 ] } );
		io.observe( slot );
	}

	function sendBeaconTrack( type, ad, hubUrl, apiKey ) {
		var url  = hubUrl.replace( /\/+$/, '' ) + '/wp-json/govads/v1/track';
		var body = JSON.stringify( {
			type:       type,
			ad_id:      ad.ad_id,
			zone_id:    ad.zone_id,
			token:      ad.impression_token,
			session_id: SESSION_ID,
			page_url:   window.location.href,
			referrer:   document.referrer
		} );
		var headers = {
			'Content-Type':        'application/json',
			'X-GovAds-Site-Key':   apiKey
		};
		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( url, new Blob( [ body ], { type: 'application/json' } ) );
		} else {
			fetch( url, { method: 'POST', body: body, keepalive: true, headers: headers } );
		}
	}

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	function escAttr( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	function init() {
		var slots = document.querySelectorAll( '.gac-slot' );
		if ( ! slots.length ) { return; }

		if ( ! window.IntersectionObserver ) {
			slots.forEach( fillSlot );
			return;
		}

		var fillObserver = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					fillObserver.unobserve( entry.target );
					fillSlot( entry.target );
				}
			} );
		}, { rootMargin: '200px' } );

		slots.forEach( function ( slot ) { fillObserver.observe( slot ); } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
})();
