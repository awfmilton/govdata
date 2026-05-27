/* PublicTech chart theme — reads CSS vars + per-user overrides, exposes a
   single PT.chartTheme object that all D3 wrappers reference. Re-emits a
   "pt:theme" event whenever the user changes anything so individual charts
   can redraw without re-binding their data. */
(function (global) {
	'use strict';

	const PT = global.PT = global.PT || {};
	const boot = global.PT_BOOT || {};

	const defaults = {
		accent: '#00a0d2', curve: 'monotone', stroke: 1.8, marker: 3,
		grid: 'dotted',  area: 0.35, bar: 'solid', font: 'Montserrat', anim: true,
	};

	PT.chartTheme = Object.assign({}, defaults, boot.chartTheme || {});
	PT.boot = boot;

	function rgbStops(hex) {
		const v = hex.replace('#', '');
		return [parseInt(v.slice(0, 2), 16), parseInt(v.slice(2, 4), 16), parseInt(v.slice(4, 6), 16)];
	}

	PT.refreshCSSVars = function () {
		const cs = getComputedStyle(document.documentElement);
		const t = PT.chartTheme;
		t.ink0 = cs.getPropertyValue('--ink-0').trim();
		t.ink1 = cs.getPropertyValue('--ink-1').trim();
		t.ink2 = cs.getPropertyValue('--ink-2').trim();
		t.ink3 = cs.getPropertyValue('--ink-3').trim();
		t.bg0  = cs.getPropertyValue('--bg-0').trim();
		t.bg1  = cs.getPropertyValue('--bg-1').trim();
		t.line = cs.getPropertyValue('--line').trim();
		t.line2 = cs.getPropertyValue('--line-2').trim();
		t.warn = cs.getPropertyValue('--warn').trim();
		t.pos  = cs.getPropertyValue('--pos').trim();
		t.neg  = cs.getPropertyValue('--neg').trim();
	};

	PT.applyAccent = function (hex) {
		PT.chartTheme.accent = hex;
		const [r, g, b] = rgbStops(hex);
		const root = document.documentElement.style;
		root.setProperty('--accent', hex);
		root.setProperty('--accent-soft', 'rgba(' + r + ',' + g + ',' + b + ',0.12)');
		root.setProperty('--accent-glow', 'rgba(' + r + ',' + g + ',' + b + ',0.45)');
		PT.refreshCSSVars();
		PT.emit();
	};

	PT.set = function (patch) {
		Object.assign(PT.chartTheme, patch);
		if (patch.font) document.documentElement.style.setProperty('--chart-font', patch.font);
		PT.refreshCSSVars();
		PT.emit();
	};

	PT.reset = function () {
		Object.assign(PT.chartTheme, defaults);
		PT.applyAccent(defaults.accent);
		document.documentElement.style.setProperty('--chart-font', defaults.font);
		PT.emit();
	};

	PT.persist = function () {
		if (!boot.rest || !boot.nonce) return Promise.resolve();
		return fetch(boot.rest + '/customizer/save', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'content-type': 'application/json', 'x-wp-nonce': boot.nonce },
			body: JSON.stringify(PT.chartTheme),
		});
	};

	let raf = 0;
	PT.emit = function () {
		cancelAnimationFrame(raf);
		raf = requestAnimationFrame(function () {
			document.dispatchEvent(new CustomEvent('pt:theme', { detail: PT.chartTheme }));
		});
	};

	PT.curveFn = function () {
		if (!global.d3) return null;
		if (PT.chartTheme.curve === 'linear') return d3.curveLinear;
		if (PT.chartTheme.curve === 'step') return d3.curveStepAfter;
		return d3.curveMonotoneX;
	};
	PT.gridOpacity = function () {
		return PT.chartTheme.grid === 'off' ? 0 : PT.chartTheme.grid === 'solid' ? 0.8 : 0.5;
	};
	PT.gridDash = function () {
		return PT.chartTheme.grid === 'solid' ? 'none' : '2,3';
	};

	document.addEventListener('DOMContentLoaded', PT.refreshCSSVars);
})(window);
