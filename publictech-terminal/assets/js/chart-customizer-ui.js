/* In-page chart customizer drawer. Mirrors the controls the WP Customizer
   exposes so users can override theme defaults per session, with a "Save"
   action that persists to user_meta via /publictech/v1/customizer/save. */
(function (global) {
	'use strict';

	const PT = global.PT = global.PT || {};

	function $(s) { return document.querySelector(s); }
	function $$(s) { return Array.from(document.querySelectorAll(s)); }

	function openDrawer() {
		const d = $('#pt-customizer'), s = $('#pt-scrim'), b = $('#pt-customizer-btn');
		d && d.classList.add('open');
		s && s.classList.add('open');
		b && b.classList.add('on');
		drawPreview();
	}
	function closeDrawer() {
		const d = $('#pt-customizer'), s = $('#pt-scrim'), b = $('#pt-customizer-btn');
		d && d.classList.remove('open');
		s && s.classList.remove('open');
		b && b.classList.remove('on');
	}

	function drawPreview() {
		const host = $('#pt-customizer-preview');
		if (!host || !PT.chart) return;
		const sample = [10, 14, 13, 18, 22, 19, 25, 28, 24, 30].map(function (v, i) { return { x: i, y: v }; });
		PT.chart(host, 'line', [{ key: 'preview', values: sample }]);
	}

	function bindSwatches() {
		$$('#pt-customizer .pt-swatch').forEach(function (sw) {
			sw.addEventListener('click', function () {
				$$('#pt-customizer .pt-swatch').forEach(function (x) { x.classList.remove('on'); });
				sw.classList.add('on');
				PT.applyAccent(sw.dataset.color);
				const v = $('#pt-cust-accent-val'); if (v) v.textContent = sw.dataset.color;
				drawPreview();
			});
		});
	}

	function bindSegments() {
		$$('#pt-customizer .pt-seg').forEach(function (group) {
			group.querySelectorAll('button').forEach(function (btn) {
				btn.addEventListener('click', function () {
					group.querySelectorAll('button').forEach(function (x) { x.classList.remove('on'); });
					btn.classList.add('on');
					const key = group.dataset.knob;
					const val = btn.dataset.val;
					const patch = {};
					patch[key] = key === 'anim' ? (val === 'on') : val;
					PT.set(patch);
					drawPreview();
				});
			});
		});
	}

	function bindRange(id, key, labelId, fmt) {
		const el = document.getElementById(id);
		if (!el) return;
		el.addEventListener('input', function () {
			const v = +el.value;
			const patch = {};
			patch[key] = key === 'area' ? v / 100 : v;
			PT.set(patch);
			const lab = document.getElementById(labelId);
			if (lab) lab.textContent = fmt(v);
			drawPreview();
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		const btn = $('#pt-customizer-btn');
		if (btn) btn.addEventListener('click', openDrawer);
		const close = $('#pt-customizer-close');
		if (close) close.addEventListener('click', closeDrawer);
		const scrim = $('#pt-scrim');
		if (scrim) scrim.addEventListener('click', closeDrawer);

		bindSwatches();
		bindSegments();
		bindRange('pt-cust-stroke', 'stroke', 'pt-cust-stroke-val', function (v) { return v.toFixed(1) + ' px'; });
		bindRange('pt-cust-marker', 'marker', 'pt-cust-marker-val', function (v) { return v + ' px'; });
		bindRange('pt-cust-area',   'area',   'pt-cust-area-val',   function (v) { return v + '%'; });

		const reset = $('#pt-customizer-reset');
		if (reset) reset.addEventListener('click', function () { PT.reset(); drawPreview(); });

		const save = $('#pt-customizer-save');
		if (save) save.addEventListener('click', function () {
			save.disabled = true; save.textContent = 'Saving…';
			PT.persist().finally(function () { save.disabled = false; save.textContent = 'Save'; closeDrawer(); });
		});
	});
})(window);
