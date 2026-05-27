/* Terminal SPA-lite. Activates panel tabs for any element marked
   [data-pt-tabs] / [data-pt-view] inside a templated page. Keeps the URL
   hash in sync so users can deep-link to any panel. */
(function () {
	'use strict';

	function activate(scope, name) {
		scope.querySelectorAll('[data-pt-view]').forEach(function (v) { v.classList.toggle('active', v.dataset.ptView === name); });
		scope.querySelectorAll('[data-pt-tab]').forEach(function (b) { b.classList.toggle('active', b.dataset.ptTab === name); });
		if (history.replaceState) history.replaceState(null, '', '#' + name);
		document.dispatchEvent(new CustomEvent('pt:view', { detail: { name: name } }));
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('[data-pt-tabs]').forEach(function (scope) {
			scope.querySelectorAll('[data-pt-tab]').forEach(function (btn) {
				btn.addEventListener('click', function () { activate(scope, btn.dataset.ptTab); });
			});
			const target = location.hash.replace('#', '') || scope.dataset.ptTabs;
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
