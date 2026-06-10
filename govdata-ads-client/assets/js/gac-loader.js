/**
 * GovData Ads Client — slot loader for partner sites.
 *
 * The ad payload is server-rendered into each slot's data-ad attribute, so
 * this script makes NO cross-origin calls to fetch ads and never handles
 * credentials. Tracking events go to the hub's public /track endpoint as
 * query-string beacons: a plain POST with no custom headers or body, which
 * browsers treat as a "simple request" — no CORS preflight, and the HMAC
 * token inside the payload provides authenticity + site attribution.
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

		var hubUrl = slot.dataset.hub;
		var ad     = null;
		try {
			ad = JSON.parse( slot.dataset.ad || 'null' );
		} catch ( e ) {
			ad = null;
		}

		if ( ! ad || ! ad.ad_id || ! hubUrl ) {
			slot.style.display = 'none';
			return;
		}

		var html = buildAdHtml( ad );
		if ( ! html ) {
			slot.style.display = 'none';
			return;
		}
		slot.innerHTML      = html;
		slot.dataset.adId   = ad.ad_id;
		slot.dataset.zoneId = ad.zone_id;

		var link = slot.querySelector( '.gac-link' );
		if ( link ) {
			link.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				sendBeaconTrack( 'click', ad, hubUrl );
				setTimeout( function () {
					window.open( ad.target_url, '_blank', 'noopener,noreferrer' );
				}, 60 );
			} );
		}
		observeImpression( slot, ad, hubUrl );
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

	function observeImpression( slot, ad, hubUrl ) {
		if ( ! window.IntersectionObserver ) {
			sendBeaconTrack( 'impression', ad, hubUrl );
			return;
		}
		var timer = null;
		var io = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.intersectionRatio >= 0.5 ) {
					if ( ! timer ) {
						timer = setTimeout( function () {
							sendBeaconTrack( 'impression', ad, hubUrl );
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

	function sendBeaconTrack( type, ad, hubUrl ) {
		var params = [
			'type='       + encodeURIComponent( type ),
			'ad_id='      + encodeURIComponent( ad.ad_id ),
			'zone_id='    + encodeURIComponent( ad.zone_id || 0 ),
			'token='      + encodeURIComponent( ad.impression_token || '' ),
			'session_id=' + encodeURIComponent( SESSION_ID ),
			'page_url='   + encodeURIComponent( window.location.href ),
			'referrer='   + encodeURIComponent( document.referrer )
		];
		if ( type === 'click' && ad.target_url ) {
			params.push( 'target_url=' + encodeURIComponent( ad.target_url ) );
		}
		var url = hubUrl.replace( /\/+$/, '' ) + '/wp-json/govads/v1/track?' + params.join( '&' );
		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( url );
		} else {
			fetch( url, { method: 'POST', keepalive: true, mode: 'no-cors' } );
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
