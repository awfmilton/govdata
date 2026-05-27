/* Asynchronous search for the MP directory. FacetWP, if installed, owns the
   facets sidebar entirely (we just listen for its refresh events). If FacetWP
   is absent we drive a vanilla AJAX loop against /publictech/v1/mp/search.
   Browser state is kept in the URL so deep links round-trip. */
(function (global) {
	'use strict';

	const PT = global.PT = global.PT || {};
	const boot = global.PT_BOOT || {};

	function $(sel, root) { return (root || document).querySelector(sel); }
	function $$(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

	const state = { q: '', party: [], region: [], postal: '', spend_gt: 0, page: 1, sort: 'spend DESC' };

	function hydrateFromURL() {
		const sp = new URLSearchParams(location.search);
		state.q       = sp.get('q')        || '';
		state.party   = sp.getAll('party');
		state.region  = sp.getAll('region');
		state.postal  = sp.get('postal')   || '';
		state.spend_gt= +sp.get('spend_gt')|| 0;
		state.page    = +sp.get('page')    || 1;
		state.sort    = sp.get('sort')     || 'spend DESC';
	}
	function pushToURL() {
		const sp = new URLSearchParams();
		if (state.q) sp.set('q', state.q);
		state.party.forEach(function (v) { sp.append('party', v); });
		state.region.forEach(function (v) { sp.append('region', v); });
		if (state.postal)   sp.set('postal', state.postal);
		if (state.spend_gt) sp.set('spend_gt', state.spend_gt);
		if (state.page > 1) sp.set('page', state.page);
		if (state.sort !== 'spend DESC') sp.set('sort', state.sort);
		const url = location.pathname + (sp.toString() ? '?' + sp.toString() : '');
		history.replaceState(state, '', url);
	}

	let inFlight;
	function search() {
		if (!boot.rest) return;
		pushToURL();
		const url = new URL(boot.rest + '/mp/search');
		Object.entries(state).forEach(function (kv) {
			if (Array.isArray(kv[1])) kv[1].forEach(function (v) { url.searchParams.append(kv[0], v); });
			else if (kv[1] !== '' && kv[1] !== 0) url.searchParams.set(kv[0], kv[1]);
		});
		if (inFlight) inFlight.abort();
		inFlight = new AbortController();
		const results = $('#mpResults'); if (results) results.setAttribute('aria-busy', 'true');
		const t0 = performance.now();
		return fetch(url, { credentials: 'same-origin', signal: inFlight.signal })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				renderResults(json, performance.now() - t0);
				results && results.setAttribute('aria-busy', 'false');
			})
			.catch(function (err) {
				if (err.name === 'AbortError') return;
				results && results.setAttribute('aria-busy', 'false');
				if (results) results.innerHTML = '<div class="pt-error">' + (boot.i18n.queryError || 'Query failed.') + '</div>';
			});
	}

	function renderResults(json, ms) {
		const host = $('#mpResults');
		if (!host) return;
		if (!json || !json.rows || json.rows.length === 0) {
			host.innerHTML = '<div class="pt-error">' + (boot.i18n.noResults || 'No matching records.') + '</div>';
			return;
		}
		host.innerHTML = json.rows.map(function (r) {
			const partyClass = String(r.party || 'unknown').toLowerCase().replace(/[^a-z0-9-]/g, '-');
			const href = safeHref(r.permalink);
			return '<a class="mp-card" href="' + href + '" data-id="' + (+r.id || 0) + '">' +
				'<div class="mp-portrait">' + escape(initials(r.name)) + '</div>' +
				'<div class="mp-info"><div class="name">' + escape(r.name) + '</div>' +
				'<div class="meta"><span><span class="party-bar party-' + escape(partyClass) + '"></span>' + escape(r.party) + '</span>' +
				'<span class="dim">·</span><span>' + escape(r.riding) + '</span></div></div>' +
				'<div class="mp-stats"><b>$' + escape(Math.round((+r.spend || 0) / 1000)) + 'k</b>' +
				'<span>' + (+r.votes || 0) + ' votes</span><br>' +
				'<span>' + (+r.flags || 0) + ' flag' + (r.flags === 1 ? '' : 's') + '</span></div></a>';
		}).join('');
		const meta = $('#mpResultsMeta');
		if (meta) {
			meta.innerHTML = 'Showing <b>' + json.rows.length + '</b> of <b>' + json.total + '</b> · query <span style="color:var(--accent)">' + Math.round(ms) + 'ms</span>';
		}
	}

	function initials(name) {
		return (name || '').split(/\s+/).filter(Boolean).map(function (w) { return w[0]; }).slice(0, 2).join('').toUpperCase();
	}
	function escape(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; });
	}
	function safeHref(url) {
		const s = String(url == null ? '' : url);
		// Allow only same-origin / protocol-relative / absolute http(s).
		if (/^(https?:)?\/\//i.test(s) || s.startsWith('/')) return escape(s);
		return '#';
	}

	document.addEventListener('DOMContentLoaded', function () {
		hydrateFromURL();
		const inp = $('#globalSearch');
		if (inp) {
			inp.value = state.q;
			let t;
			inp.addEventListener('input', function () {
				clearTimeout(t);
				t = setTimeout(function () { state.q = inp.value; state.page = 1; search(); }, 180);
			});
		}
		$$('[data-pt-facet]').forEach(function (el) {
			el.addEventListener('change', function () {
				const key = el.dataset.ptFacet;
				const value = el.value;
				if (Array.isArray(state[key])) {
					state[key] = el.checked
						? state[key].concat([value])
						: state[key].filter(function (v) { return v !== value; });
				} else {
					state[key] = value;
				}
				state.page = 1;
				search();
			});
		});

		document.addEventListener('facetwp-loaded', function () {
			// FacetWP updates its own results — just keep our URL state aligned.
			pushToURL();
		});

		if ($('#mpResults')) search();
	});

	PT.search = { state, run: search };
})(window);
