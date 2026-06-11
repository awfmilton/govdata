/* Social share actions for the single saved-chart view.
 *
 * Progressive enhancement over plain web-intent anchors rendered by
 * single-user-chart.php:
 *   [data-pt-share="popup"]  → centered share popup (anchor href is the intent)
 *   [data-pt-share="copy"]   → clipboard copy of the permalink
 *   [data-pt-share="native"] → navigator.share() sheet (revealed when supported)
 * With JS disabled the anchors still open the intents in a new tab and the
 * buttons stay inert/hidden, so nothing breaks. Dependency-free. */
(function () {
	'use strict';

	function setStatus(el, message) {
		const panel = el.closest('.bd');
		const status = panel ? panel.querySelector('.pt-share-status') : null;
		if (status) {
			status.textContent = message;
		}
	}

	/* Centered popup for share intents. Returns false when the browser
	   blocked it, in which case the anchor's target=_blank takes over. */
	function openPopup(href, size) {
		const parts = String(size || '600x640').split('x');
		const w = parseInt(parts[0], 10) || 600;
		const h = parseInt(parts[1], 10) || 640;
		const left = Math.max(0, Math.round((window.screen.width - w) / 2));
		const top = Math.max(0, Math.round((window.screen.height - h) / 2));
		const win = window.open(
			href,
			'ptShare',
			'popup=yes,width=' + w + ',height=' + h + ',left=' + left + ',top=' + top
		);
		if (!win) {
			return false;
		}
		win.opener = null;
		return true;
	}

	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		// Legacy fallback (http dev hosts, older WebViews).
		return new Promise(function (resolve, reject) {
			const scratch = document.createElement('textarea');
			scratch.value = text;
			scratch.setAttribute('readonly', '');
			scratch.style.position = 'fixed';
			scratch.style.opacity = '0';
			document.body.appendChild(scratch);
			scratch.select();
			let ok = false;
			try {
				ok = document.execCommand('copy');
			} catch (err) {
				ok = false;
			}
			document.body.removeChild(scratch);
			if (ok) {
				resolve();
			} else {
				reject(new Error('copy rejected'));
			}
		});
	}

	document.addEventListener('click', function (e) {
		const el = e.target && e.target.closest ? e.target.closest('[data-pt-share]') : null;
		if (!el) return;
		const kind = el.getAttribute('data-pt-share');

		if (kind === 'popup') {
			if (openPopup(el.href, el.getAttribute('data-pt-popup'))) {
				e.preventDefault();
			}
			return;
		}

		if (kind === 'copy') {
			e.preventDefault();
			const url = el.getAttribute('data-pt-share-url') || window.location.href;
			copyText(url).then(function () {
				setStatus(el, el.getAttribute('data-pt-copied-text') || 'Copied.');
			}).catch(function () {
				setStatus(el, url); // Worst case: surface the URL for manual copy.
			});
			return;
		}

		if (kind === 'native' && navigator.share) {
			e.preventDefault();
			navigator.share({
				title: el.getAttribute('data-pt-share-title') || document.title,
				url: el.getAttribute('data-pt-share-url') || window.location.href,
			}).catch(function () {
				/* user dismissed the sheet — not an error */
			});
		}
	});

	document.addEventListener('DOMContentLoaded', function () {
		if (navigator.share) {
			document.querySelectorAll('[data-pt-share="native"]').forEach(function (btn) {
				btn.hidden = false;
			});
		}
	});
})();
