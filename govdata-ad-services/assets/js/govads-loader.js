/**
 * GovData Ad Services — async slot loader.
 *
 * 1. Finds all .govads-slot placeholder divs rendered by PHP.
 * 2. Uses IntersectionObserver (200px rootMargin) to fill slots lazily as
 *    they approach the viewport — ads never block page render.
 * 3. A second IntersectionObserver fires an impression once an ad is 50%
 *    visible for at least 1 second (IAB viewability standard).
 * 4. Clicks send a beacon to /track then navigate to the ad destination.
 * 5. Session ID is stored in a first-party cookie (govads_sid, 30-day).
 */
(function () {
	'use strict';

	if ( typeof GOVADS === 'undefined' ) { return; }

	var REST  = GOVADS.rest;
	var NONCE = GOVADS.nonce;

	// ── Session ID ────────────────────────────────────────────────────────────

	function getSessionId() {
		var match = document.cookie.match( /(?:^|;\s*)govads_sid=([^;]+)/ );
		if ( match ) { return match[1]; }
		var sid = 'gs_' + Math.random().toString( 36 ).slice( 2, 18 );
		var exp = new Date( Date.now() + 30 * 86400 * 1000 ).toUTCString();
		document.cookie = 'govads_sid=' + sid + '; expires=' + exp + '; path=/; SameSite=Lax';
		return sid;
	}

	var SESSION_ID = getSessionId();

	// ── Slot filling ──────────────────────────────────────────────────────────

	function fillSlot( slot ) {
		var zone = slot.dataset.zone;
		var zid  = slot.dataset.zoneId || '0';
		if ( slot.dataset.filled ) { return; }
		slot.dataset.filled = '1';

		fetch( REST + '/serve?zone=' + encodeURIComponent( zone ), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': NONCE }
		} )
		.then( function ( r ) { return r.status === 200 ? r.json() : null; } )
		.then( function ( ad ) {
			if ( ! ad ) {
				slot.style.display = 'none';
				return;
			}
			slot.innerHTML = buildAdHtml( ad );
			slot.dataset.adId    = ad.ad_id;
			slot.dataset.zoneId  = ad.zone_id;
			slot.dataset.token   = ad.impression_token;

			var link = slot.querySelector( '.govads-link' );
			if ( link ) {
				link.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					sendBeaconTrack( 'click', ad, zone );
					setTimeout( function () {
						window.open( ad.target_url, '_blank', 'noopener,noreferrer' );
					}, 60 );
				} );
			}

			observeImpression( slot, ad, zone );
		} )
		.catch( function () {
			slot.style.display = 'none';
		} );
	}

	function buildAdHtml( ad ) {
		var label = '<span class="govads-label" aria-hidden="true">AD</span>';
		if ( ad.type === 'image' && ad.image_url ) {
			return '<a class="govads-link govads-img-wrap" href="' + escAttr( ad.target_url ) + '" target="_blank" rel="noopener sponsored">'
				+ '<img src="' + escAttr( ad.image_url ) + '" alt="" loading="lazy" width="' + ad.width + '" height="' + ad.height + '">'
				+ label + '</a>';
		}
		if ( ad.type === 'html5' && ad.html ) {
			return '<div class="govads-link govads-html-wrap">' + ad.html + label + '</div>';
		}
		return '';
	}

	// ── Impression tracking ───────────────────────────────────────────────────

	function observeImpression( slot, ad, zone ) {
		if ( ! window.IntersectionObserver ) {
			sendBeaconTrack( 'impression', ad, zone );
			return;
		}
		var timer = null;
		var io = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.intersectionRatio >= 0.5 ) {
					if ( ! timer ) {
						timer = setTimeout( function () {
							sendBeaconTrack( 'impression', ad, zone );
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

	function sendBeaconTrack( type, ad, zone ) {
		var body = JSON.stringify( {
			type:       type,
			ad_id:      ad.ad_id,
			zone_id:    ad.zone_id,
			token:      ad.impression_token,
			session_id: SESSION_ID,
			page_url:   window.location.href,
			referrer:   document.referrer
		} );
		var url = REST + '/track';
		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( url, new Blob( [ body ], { type: 'application/json' } ) );
		} else {
			fetch( url, { method: 'POST', body: body, keepalive: true,
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE } } );
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
		var slots = document.querySelectorAll( '.govads-slot' );
		if ( ! slots.length ) { return; }

		if ( ! window.IntersectionObserver ) {
			// Fallback: fill all slots immediately.
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
