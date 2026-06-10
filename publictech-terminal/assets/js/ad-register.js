/**
 * GovData Ad Services — Advertiser registration.
 * Posts to /govads/v1/register, then redirects into the portal on success.
 */
(function () {
	'use strict';

	var R = window.GOVADS_REG;
	if ( ! R ) { return; }

	var form = document.getElementById( 'adsRegForm' );
	if ( ! form ) { return; }

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();

		var name     = val( 'regName' );
		var company  = val( 'regCompany' );
		var email    = val( 'regEmail' );
		var password = val( 'regPassword' );

		if ( ! name || ! email || ! password ) {
			msg( 'error', 'Please fill in all required fields.' );
			return;
		}
		if ( password.length < 8 ) {
			msg( 'error', 'Password must be at least 8 characters.' );
			return;
		}

		var btn = document.getElementById( 'adsRegSubmit' );
		if ( btn ) { btn.disabled = true; btn.textContent = 'Creating account…'; }

		fetch( R.rest + '/register', {
			method:      'POST',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce':   R.nonce,
				'Content-Type': 'application/json',
			},
			body: JSON.stringify( {
				name:     name,
				company:  company,
				email:    email,
				password: password,
			} ),
		} ).then( function ( r ) {
			return r.json().then( function ( data ) {
				return { status: r.status, data: data };
			} );
		} ).then( function ( res ) {
			if ( res.status === 201 && res.data && res.data.ok ) {
				msg( 'ok', 'Account created! Redirecting to your dashboard…' );
				setTimeout( function () {
					window.location.href = res.data.redirect || R.portalUrl;
				}, 800 );
				return;
			}
			if ( btn ) { btn.disabled = false; btn.textContent = 'Create Account'; }
			var m = ( res.data && res.data.message ) ? res.data.message : 'Registration failed. Please try again.';
			msg( 'error', m );
		} ).catch( function () {
			if ( btn ) { btn.disabled = false; btn.textContent = 'Create Account'; }
			msg( 'error', 'Network error. Please try again.' );
		} );
	} );

	function val( id ) {
		var el = document.getElementById( id );
		return el ? el.value.trim() : '';
	}

	function msg( type, text ) {
		var el = document.getElementById( 'adsRegMsg' );
		if ( ! el ) { return; }
		el.className     = 'ads-notice ' + type;
		el.textContent   = text;
		el.style.display = '';
	}
})();
