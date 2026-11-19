/* Terminal SPA-lite. Activates panel tabs for any element marked
   [data-pt-tabs] / [data-pt-view] inside a templated page. Keeps the URL
   hash in sync so users can deep-link to any panel. */
(function () {
	'use strict';

	// Panels may live inside the tab scope or beside it (e.g. the profile settings tabs, whose panels are
	// siblings of the tab bar and start hidden with inline display:none) — matrix D9.
	function viewsFor(scope) {
		const inner = scope.querySelectorAll('[data-pt-view]');
		if (inner.length) return Array.prototype.slice.call(inner);
		const parent = scope.parentElement;
		return parent ? Array.prototype.filter.call(parent.children, function (el) { return el.hasAttribute('data-pt-view'); }) : [];
	}

	function activate(scope, name) {
		const views = viewsFor(scope);
		if (!views.some(function (v) { return v.dataset.ptView === name; })) return;   // unknown tab: leave as is
		views.forEach(function (v) {
			const on = v.dataset.ptView === name;
			v.classList.toggle('active', on);
			v.style.display = on ? '' : 'none';
		});
		scope.querySelectorAll('[data-pt-tab]').forEach(function (b) {
			const on = b.dataset.ptTab === name;
			b.classList.toggle('active', on);
			b.classList.toggle('on', on);
			b.setAttribute('aria-selected', on ? 'true' : 'false');
		});
		if (history.replaceState) history.replaceState(null, '', '#' + name);
		document.dispatchEvent(new CustomEvent('pt:view', { detail: { name: name } }));
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('[data-pt-tabs]').forEach(function (scope) {
			scope.querySelectorAll('[data-pt-tab]').forEach(function (btn) {
				btn.addEventListener('click', function (e) { e.preventDefault(); activate(scope, btn.dataset.ptTab); });
			});
			// ?pt_tab= is what the profile form handlers redirect to (e.g. after rotating an API key).
			const target = location.hash.replace('#', '') || new URLSearchParams(location.search).get('pt_tab') || scope.dataset.ptTabs;
			if (target) activate(scope, target);
		});

		document.querySelectorAll('[data-go]').forEach(function (el) {
			el.addEventListener('click', function (e) {
				e.preventDefault();
				const scope = document.querySelector('[data-pt-tabs]');
				if (scope) activate(scope, el.dataset.go);
			});
		});
	});
})();
