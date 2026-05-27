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
					if (!this.body) return;
					const tr = document.createElement('tr');
					const td = document.createElement('td');
					td.colSpan = 99;
					const div = document.createElement('div');
					div.className = 'pt-error';
					div.textContent = err && err.message ? String(err.message) : 'Stream failed.';
					td.appendChild(div);
					tr.appendChild(td);
					this.body.replaceChildren(tr);
				});
		}
		render() {
			if (!this.body) return;
			if (!this._cols) this._cols = Array.from(this.root.querySelectorAll('th')).map(function (th) { return th.dataset.sort; });
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
			const cols = this._cols;
			// If any row carries a source_url or atip_ref column, surface a
			// linked PDF/document icon next to the row. The href is sanitised
			// through safeHref() so only http(s) / absolute paths render.
			this.body.innerHTML = pageRows.map((row) => {
				const sourceHref  = safeHref(row.source_url || row.atip_ref || '');
				const sourceLabel = row.atip_ref ? 'ATIP: ' + row.atip_ref : 'Source document';
				const cells = cols.map(function (k) {
					const v = row[k];
					if (k === 'amount_cad' || k === 'amount') {
						return '<td class="num">$' + escape(Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })) + '</td>';
					}
					if ((k === 'source_url' || k === 'atip_ref') && sourceHref !== '#') {
						return '<td><a class="pt-source-link" href="' + sourceHref + '" target="_blank" rel="noopener noreferrer" title="' + escape(sourceLabel) + '" aria-label="' + escape(sourceLabel) + '">⎘ PDF</a></td>';
					}
					return '<td>' + escape(v) + '</td>';
				}).join('');
				return '<tr>' + cells + '</tr>';
			}).join('');

			if (this.foot) {
				this.foot.innerHTML = 'Streamed <b>' + this.rows.length + '</b> rows · viewing <b>' + view.length + '</b> · page ' + this.page;
			}
		}
	}

	function safeHref(url) {
		const s = String(url == null ? '' : url);
		if (/^https?:\/\//i.test(s) || s.startsWith('/')) {
			return s.replace(/[<>"']/g, function (c) { return ({ '<':'%3C','>':'%3E','"':'%22',"'":'%27' })[c]; });
		}
		return '#';
	}
	function escape(s) {
		return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' })[c]; });
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('table[data-stream]').forEach(function (t) { new StreamTable(t); });
	});

	PT.StreamTable = StreamTable;
})(window);
