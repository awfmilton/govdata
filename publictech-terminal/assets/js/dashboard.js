/* PublicTech homepage dashboard — counter animations + D3 chart rendering.
   Reads window.PT_DASH (inlined by template-dashboard.php) and PT.chartTheme. */
(function (global) {
	'use strict';

	var PROVINCE = {
		AB: 'Alberta', BC: 'British Columbia', MB: 'Manitoba', NB: 'New Brunswick',
		NL: 'Newfoundland & Lab.', NS: 'Nova Scotia', NT: 'N.W.T.', NU: 'Nunavut',
		ON: 'Ontario', PE: 'P.E.I.', QC: 'Québec', SK: 'Saskatchewan', YT: 'Yukon'
	};

	// Honour the OS "reduce motion" setting (WCAG 2.3.3 / 2.2.2): skip the
	// count-up tween and the marquee ticker, painting final/static state instead.
	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/* ── Formatting helpers ─────────────────────────────────────────── */

	function fmtMoney(n) {
		if (n >= 1e9) return '$' + (n / 1e9).toFixed(2) + 'B';
		if (n >= 1e6) return '$' + (n / 1e6).toFixed(1) + 'M';
		if (n >= 1e3) return '$' + Math.round(n / 1e3) + 'K';
		return '$' + Math.round(n);
	}

	function fmtCount(n) {
		return Math.round(n).toLocaleString('en-CA');
	}

	/* ── Count-up animation ─────────────────────────────────────────── */

	function countUp(el) {
		var target   = parseFloat(el.dataset.countTarget) || 0;
		var unit     = el.dataset.countUnit || 'count';
		var duration = 1600;
		var startTs  = null;

		// Reduced motion: paint the final value immediately, no animation.
		if (reduceMotion) {
			el.textContent = unit === 'money' ? fmtMoney(target) : fmtCount(target);
			return;
		}

		function step(ts) {
			if (!startTs) startTs = ts;
			var elapsed  = ts - startTs;
			var progress = Math.min(1, elapsed / duration);
			var eased    = 1 - Math.pow(1 - progress, 3); // ease-out cubic
			var current  = target * eased;

			el.textContent = unit === 'money' ? fmtMoney(current) : fmtCount(current);

			if (progress < 1) {
				requestAnimationFrame(step);
			} else {
				el.textContent = unit === 'money' ? fmtMoney(target) : fmtCount(target);
			}
		}

		requestAnimationFrame(step);
	}

	function initCounters() {
		var els = document.querySelectorAll('[data-count-target]');
		if (!els.length || typeof IntersectionObserver === 'undefined') return;

		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (e) {
				if (!e.isIntersecting) return;
				countUp(e.target);
				io.unobserve(e.target);
			});
		}, { threshold: 0.4 });

		els.forEach(function (el) { io.observe(el); });
	}

	/* ── Ticker: duplicate items so the CSS marquee loops seamlessly ── */

	function initTicker() {
		var track = document.querySelector('.ticker-track');
		if (!track) return;
		// Reduced motion: leave the items static — no clone, no marquee animation.
		if (reduceMotion) return;
		track.innerHTML += track.innerHTML; // clone once — CSS animation runs -50%
		track.classList.add('ready');
	}

	/* ── CSS variable reader (for D3 chart colours) ─────────────────── */

	function cssVar(name) {
		return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
	}

	/* ── Horizontal bar chart (pure D3, IntersectionObserver-triggered) */

	function drawHBar(hostId, data, opts) {
		if (!global.d3 || !data || !data.length) return;
		var d3   = global.d3;
		var host = document.getElementById(hostId);
		if (!host) return;

		opts = opts || {};

		var maxVal  = d3.max(data, function (d) { return d.value; }) || 1;
		var accent  = cssVar('--accent');
		var pos     = cssVar('--pos');
		var ink1    = cssVar('--ink-1');
		var ink2    = cssVar('--ink-2');

		var wrap = d3.select(host);

		data.forEach(function (d, i) {
			var pct   = (d.value / maxVal * 100);
			var label = opts.labelMap ? (opts.labelMap[d.label] || d.label) : d.label;
			var val   = opts.fmt ? opts.fmt(d.value) : d.value;

			var row = wrap.append('div').attr('class', 'dash-hbar-item');

			row.append('div').attr('class', 'dash-hbar-label')
				.attr('title', label)
				.text(label.length > 16 ? label.slice(0, 15) + '…' : label);

			var fill = row.append('div').attr('class', 'dash-hbar-track')
				.append('div').attr('class', 'dash-hbar-fill');

			// Staggered entrance — small delay per row so bars animate in sequence.
			setTimeout(function () {
				fill.style('width', pct + '%');
			}, i * 55);

			row.append('div').attr('class', 'dash-hbar-val').text(val);
		});
	}

	/* Observe the host container and render only when visible. */
	function observeHBar(hostId, data, opts) {
		var host = document.getElementById(hostId);
		if (!host || !data || !data.length) return;

		if (typeof IntersectionObserver === 'undefined') {
			drawHBar(hostId, data, opts);
			return;
		}

		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (e) {
				if (!e.isIntersecting) return;
				drawHBar(hostId, data, opts);
				io.unobserve(e.target);
			});
		}, { threshold: 0.15 });

		io.observe(host);
	}

	/* ── Year trend (reuses PT.chart line renderer) ─────────────────── */

	function initYearChart(data) {
		if (!global.PT || !PT.chart || !data || !data.length) return;
		var host = document.getElementById('pt-year-chart');
		if (!host) return;

		// Observe so the draw-on animation fires when the chart scrolls into view.
		if (typeof IntersectionObserver === 'undefined') {
			PT.chart('#pt-year-chart', 'line', [{ key: 'Annual Spend', values: data }]);
			return;
		}

		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (e) {
				if (!e.isIntersecting) return;
				PT.chart('#pt-year-chart', 'line', [{ key: 'Annual Spend', values: data }]);
				io.unobserve(e.target);
			});
		}, { threshold: 0.2 });

		io.observe(host);
	}

	/* ── Bootstrap ───────────────────────────────────────────────────── */

	document.addEventListener('DOMContentLoaded', function () {
		initTicker();
		initCounters();

		var dash = global.PT_DASH;
		if (!dash) return;

		initYearChart(dash.by_year);

		observeHBar('pt-province-chart', dash.by_province, {
			fmt:      fmtMoney,
			labelMap: PROVINCE
		});

		observeHBar('pt-recipients-chart', dash.top_recipients, {
			fmt: fmtMoney
		});
	});

})(window);
