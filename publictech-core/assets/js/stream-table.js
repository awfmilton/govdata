/* Lightweight, client-side streaming table. The server returns a JSON array
   via /publictech/v1/stream/<schema>/<table>; we filter/sort/paginate
   entirely in the browser so the user never re-hits the network during
   exploration. Designed to handle thousands of rows comfortably. */
(function (global) {
	'use strict';

	const PT = global.PT = global.PT || {};
	const boot = global.PT_BOOT || {};

	class StreamTable {
		constructor(root) {
			this.root = root;
			this.schema = root.dataset.schema;
			this.table  = root.dataset.table;
			this.limit  = +(root.dataset.limit || 1000);
			this.rows   = [];
			this.view   = [];
			this.sortKey = null;
			this.sortDir = -1;
			this.filter  = '';
			this.page    = 1;
			this.pageSize = +(root.dataset.pageSize || 24);
			this.body   = root.querySelector('tbody');
			this.foot   = root.parentElement.querySelector('[data-stream-foot]');
			this.input  = root.parentElement.querySelector('[data-stream-filter]');
			this.bind();
			this.load();
		}
		bind() {
			const self = this;
			this.root.querySelectorAll('th[data-sort]').forEach(function (th) {
				th.addEventListener('click', function () {
					const k = th.dataset.sort;
					if (self.sortKey === k) self.sortDir *= -1;
					else { self.sortKey = k; self.sortDir = -1; }
					self.render();
				});
			});
			if (this.input) {
				let t;
				this.input.addEventListener('input', function () {
					clearTimeout(t);
					t = setTimeout(function () { self.filter = self.input.value; self.page = 1; self.render(); }, 120);
				});
			}
		}
		load() {
			const url = boot.rest + '/stream/' + encodeURIComponent(this.schema) + '/' + encodeURIComponent(this.table) + '?limit=' + this.limit;
			this.root.setAttribute('aria-busy', 'true');
			fetch(url, { credentials: 'same-origin', headers: { 'x-wp-nonce': boot.nonce } })
				.then(function (r) {
					if (r.status === 429) throw new Error(boot.i18n.rateLimit);
					if (r.status === 403) throw new Error(boot.i18n.tierLocked);
					return r.json();
				})
				.then((rows) => {
					this.rows = Array.isArray(rows) ? rows : [];
					this.root.setAttribute('aria-busy', 'false');
					this.render();
				})
				.catch((err) => {
					this.root.setAttribute('aria-busy', 'false');
					this.body.innerHTML = '<tr><td colspan="99"><div class="pt-error">' + err.message + '</div></td></tr>';
				});
		}
		render() {
			const f = this.filter.trim().toLowerCase();
			let view = this.rows;
			if (f) {
				const gt = f.match(/^>\s*([\d.]+)/);
				const lt = f.match(/^<\s*([\d.]+)/);
				if (gt) view = view.filter(function (r) { return +(r.amount_cad || r.amount || 0) > +gt[1]; });
				else if (lt) view = view.filter(function (r) { return +(r.amount_cad || r.amount || 0) < +lt[1]; });
				else view = view.filter(function (r) { return Object.values(r).some(function (v) { return String(v).toLowerCase().includes(f); }); });
			}
			if (this.sortKey) {
				const k = this.sortKey, d = this.sortDir;
				view.sort(function (a, b) {
					const av = a[k], bv = b[k];
					if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * d;
					return String(av || '').localeCompare(String(bv || '')) * d;
				});
			}
			this.view = view;
			const start = (this.page - 1) * this.pageSize;
			const pageRows = view.slice(start, start + this.pageSize);
			this.body.innerHTML = pageRows.map((row) => '<tr>' + Array.from(this.root.querySelectorAll('th')).map(function (th) {
				const k = th.dataset.sort;
				const v = row[k];
				if (k === 'amount_cad' || k === 'amount') {
					return '<td class="num">$' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</td>';
				}
				return '<td>' + escape(v) + '</td>';
			}).join('') + '</tr>').join('');

			if (this.foot) {
				this.foot.innerHTML = 'Streamed <b>' + this.rows.length + '</b> rows · viewing <b>' + view.length + '</b> · page ' + this.page;
			}
		}
	}

	function escape(s) {
		return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' })[c]; });
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('table[data-stream]').forEach(function (t) { new StreamTable(t); });
	});

	PT.StreamTable = StreamTable;
})(window);
