/* Light/dark switcher. Honors:
   - localStorage:pt-theme (highest precedence — user clicked the toggle),
   - WP customizer default (server-rendered into PT_THEME_DEFAULT),
   - prefers-color-scheme as the lowest-precedence fallback.
   Re-fires "pt:theme" so D3 charts pick up the new palette. */
(function (global) {
	'use strict';

	function apply(mode) {
		document.documentElement.setAttribute('data-theme', mode);
		try { localStorage.setItem('pt-theme', mode); } catch (e) {}
		const btn = document.getElementById('pt-theme-btn');
		if (btn) {
			btn.classList.toggle('on', mode === 'light');
			btn.textContent = mode === 'light' ? '◑' : '◐';
			btn.setAttribute('aria-pressed', mode === 'light' ? 'true' : 'false');
		}
		if (global.PT) { PT.refreshCSSVars && PT.refreshCSSVars(); PT.emit && PT.emit(); }
	}

	function initial() {
		try {
			const stored = localStorage.getItem('pt-theme');
			if (stored) return stored;
		} catch (e) {}
		const def = (global.PT_THEME_DEFAULT && global.PT_THEME_DEFAULT.mode) || 'dark';
		if (def === 'system') {
			return matchMedia && matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
		}
		return def;
	}

	document.addEventListener('DOMContentLoaded', function () {
		apply(initial());
		const btn = document.getElementById('pt-theme-btn');
		if (btn) {
			btn.addEventListener('click', function () {
				apply(document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light');
			});
		}
		if (matchMedia) {
			matchMedia('(prefers-color-scheme: light)').addEventListener('change', function (e) {
				const stored = (function () { try { return localStorage.getItem('pt-theme'); } catch (_) { return null; } })();
				if (!stored && (global.PT_THEME_DEFAULT && global.PT_THEME_DEFAULT.mode) === 'system') {
					apply(e.matches ? 'light' : 'dark');
				}
			});
		}
	});
})(window);
