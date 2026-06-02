/* Informative hover/focus tooltips.

   Any element carrying [data-pt-tip] gets a styled bubble on hover or keyboard
   focus; an optional [data-pt-tip-title] adds an uppercase heading line. One
   shared bubble node is reused, positioned edge-aware (prefers above, flips
   below, clamps horizontally) with its caret kept aimed at the trigger. Touch-
   and reduced-motion friendly. Purely presentational — no dependencies.

   Re-scan dynamically injected markup via PT.tooltips(rootEl). */
(function () {
	'use strict';

	var bubble, titleEl, bodyEl, current;
	var GAP = 10;     // px between trigger and bubble
	var MARGIN = 8;   // viewport edge padding

	function build() {
		if (bubble) return;
		bubble = document.createElement('div');
		bubble.className = 'pt-tooltip';
		bubble.id = 'pt-tooltip';
		bubble.setAttribute('role', 'tooltip');
		titleEl = document.createElement('span');
		titleEl.className = 'pt-tip-h';
		bodyEl = document.createElement('span');
		bodyEl.className = 'pt-tip-b';
		bubble.appendChild(titleEl);
		bubble.appendChild(bodyEl);
		document.body.appendChild(bubble);
	}

	function position(target) {
		var r = target.getBoundingClientRect();
		var bw = bubble.offsetWidth;
		var bh = bubble.offsetHeight;
		var vw = document.documentElement.clientWidth;
		var vh = document.documentElement.clientHeight;

		// Vertical: prefer above; flip below only if above would clip and
		// below actually fits.
		var below = (r.top - bh - GAP) < MARGIN && (r.bottom + bh + GAP) < vh;
		var top = below ? (r.bottom + GAP) : (r.top - bh - GAP);
		bubble.classList.toggle('pt-tooltip--below', below);

		// Horizontal: centre on the trigger, then clamp to the viewport.
		var centre = r.left + r.width / 2;
		var left = Math.max(MARGIN, Math.min(centre - bw / 2, vw - bw - MARGIN));

		// Keep the caret pointing at the trigger even after clamping.
		var caret = Math.max(12, Math.min(centre - left, bw - 12));
		bubble.style.setProperty('--pt-caret', caret + 'px');
		bubble.style.left = Math.round(left) + 'px';
		bubble.style.top = Math.round(top) + 'px';
	}

	function show(target) {
		build();
		var title = target.getAttribute('data-pt-tip-title') || '';
		titleEl.textContent = title;
		titleEl.style.display = title ? '' : 'none';
		bodyEl.textContent = target.getAttribute('data-pt-tip') || '';
		current = target;

		// Lay it out invisibly, then reveal next frame so the transition runs.
		bubble.classList.remove('is-visible');
		position(target);
		requestAnimationFrame(function () {
			if (current === target) bubble.classList.add('is-visible');
		});
	}

	function hide() {
		if (!bubble) return;
		current = null;
		bubble.classList.remove('is-visible');
	}

	function bind(el) {
		if (el.__ptTip) return;
		el.__ptTip = true;

		// Accessibility: expose the description and let keyboard users reach it
		// (WCAG 1.4.13 — content on hover *or* focus).
		if (!el.hasAttribute('aria-describedby')) el.setAttribute('aria-describedby', 'pt-tooltip');
		if (!el.matches('a,button,input,select,textarea,[tabindex]')) el.setAttribute('tabindex', '0');

		el.addEventListener('mouseenter', function () { show(el); });
		el.addEventListener('mouseleave', hide);
		el.addEventListener('focus', function () { show(el); });
		el.addEventListener('blur', hide);
	}

	function scan(root) {
		(root || document).querySelectorAll('[data-pt-tip]').forEach(bind);
	}

	document.addEventListener('DOMContentLoaded', function () {
		build(); // exist up-front so aria-describedby="pt-tooltip" resolves.
		scan(document);

		// Dismiss on Escape, and whenever the page shifts under a pinned bubble.
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });
		window.addEventListener('scroll', hide, true);
		window.addEventListener('resize', hide);

		// Touch: tap a trigger to toggle, tap elsewhere to dismiss.
		document.addEventListener('touchstart', function (e) {
			var t = e.target.closest ? e.target.closest('[data-pt-tip]') : null;
			if (t) { current === t ? hide() : show(t); }
			else { hide(); }
		}, { passive: true });
	});

	// Re-scan hook for markup injected after load.
	window.PT = window.PT || {};
	window.PT.tooltips = scan;
})();
