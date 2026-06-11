/* PT ↔ Billboard.js bridge.
 *
 *   PT.billboard.render(host, spec, data)  → returns the Billboard chart
 *   PT.billboard.unload(host)              → tears the chart down cleanly
 *   PT.billboard.hydrateMount(node)        → fetches a stored chart by id
 *
 * Charts are tracked in a Map keyed by host element so theme changes and
 * resize events can rerun .load() against an existing instance instead of
 * recreating it (Billboard's native state-engine handles the transition). */
(function (global) {
	'use strict';

	const PT = global.PT = global.PT || {};
	const bb = global.bb;
	const spec = PT.ChartSpec;
	if (!bb || !spec) return;

	const charts = new Map();        // host -> { instance, spec, data }
	const ro = new ResizeObserver(function (entries) {
		entries.forEach(function (e) {
			const rec = charts.get(e.target);
			if (rec && rec.instance && typeof rec.instance.resize === 'function') {
				rec.instance.resize();
			}
		});
	});

	function paletteFromTheme() {
		const t = PT.chartTheme || {};
		const pool = [t.accent, t.warn, t.pos, t.ink1, t.neg].filter(Boolean);
		return pool.length ? pool : ['#00a0d2'];
	}

	/* The ?pt_raw_render=1 capture surface (single-user-chart-raw.php) tags
	   <body> so charts can render screenshot-deterministically. */
	function isRawRender() {
		return !!(document.body && document.body.classList.contains('pt-raw-render'));
	}

	/* Convert PT spec + row payload to Billboard.js columns.
	   Single-series column charts use {x: 'label', y: 'value'} as a default
	   mapping; multi-series picks up every numeric column. */
	function rowsToColumns(rowSpec, rows) {
		if (!Array.isArray(rows) || !rows.length) {
			return { columns: [], x: null, type: rowSpec.data.type };
		}
		const sample = rows[0] || {};
		const keys = Object.keys(sample);
		// Pick the first non-numeric key as the X axis when the chart is
		// category-shaped; numeric axes use sequential indices.
		let xKey = null;
		if (rowSpec.axis.x.type === 'category') {
			xKey = keys.find(function (k) {
				const v = sample[k];
				return typeof v !== 'number';
			}) || keys[0];
		} else if (rowSpec.axis.x.type === 'timeseries') {
			xKey = keys.find(function (k) {
				const v = sample[k];
				return typeof v === 'string' && /\d{4}-\d{2}-\d{2}/.test(v);
			}) || keys[0];
		}
		const valueKeys = keys.filter(function (k) {
			if (k === xKey) return false;
			return rows.some(function (r) { return typeof r[k] === 'number'; });
		});

		const columns = [];
		if (xKey != null) {
			columns.push(['x'].concat(rows.map(function (r) { return r[xKey]; })));
		}
		valueKeys.forEach(function (k) {
			columns.push([k].concat(rows.map(function (r) { return Number(r[k]) || 0; })));
		});

		return { columns: columns, x: xKey != null ? 'x' : null, type: rowSpec.data.type };
	}

	function specToConfig(host, normalised, data) {
		const shaped = rowsToColumns(normalised, data || []);

		const cfg = {
			bindto: host,
			data: {
				x: shaped.x || undefined,
				columns: shaped.columns,
				type: normalised.data.type,
				groups: normalised.data.groups,
			},
			color: {
				pattern: paletteFromTheme(),
			},
			axis: {
				x: {
					type: normalised.axis.x.type === 'log' ? 'indexed' : normalised.axis.x.type,
					label: normalised.axis.x.label || undefined,
				},
				y: {
					type: normalised.axis.y.type === 'log' ? 'indexed' : normalised.axis.y.type,
					label: normalised.axis.y.label || undefined,
				},
			},
			grid: {
				x: { show: !!(normalised.grid && normalised.grid.x && normalised.grid.x.show) },
				y: { show: !!(normalised.grid && normalised.grid.y && normalised.grid.y.show) },
			},
			legend: normalised.legend ? { show: !!normalised.legend.show } : undefined,
			point:  normalised.point  ? { show: !!normalised.point.show  } : undefined,
			transition: normalised.transition ? { duration: normalised.transition.duration } : undefined,
		};

		// Pie/donut/gauge don't honour axis config — Billboard warns if you set it.
		if (['pie', 'donut', 'gauge'].indexOf(normalised.data.type) !== -1) {
			delete cfg.axis;
			delete cfg.grid;
		}

		// CSS-vars based tooltip background so themed charts look native.
		cfg.tooltip = { grouped: true };
		// Background — Billboard leaves it transparent so it inherits the panel.
		cfg.background = { color: 'transparent' };

		// Snapshot capture: freeze transitions/interactivity so a headless
		// screenshot can never race an animation frame.
		if (isRawRender()) {
			cfg.transition = { duration: 0 };
			cfg.interaction = { enabled: false };
		}

		// Deterministic ready-signal for the snapshot service — flips once
		// Billboard has painted the SVG into the mount.
		cfg.onrendered = function () {
			host.setAttribute('data-pt-rendered', '1');
		};

		return cfg;
	}

	const Billboard = {
		validate: function (s) { return spec.validate(s); },

		render: function (target, rawSpec, data) {
			const host = typeof target === 'string' ? document.querySelector(target) : target;
			if (!host) return null;
			host.removeAttribute('data-pt-rendered');
			const normalised = spec.normalise(rawSpec);
			const cfg = specToConfig(host, normalised, data || []);

			const existing = charts.get(host);
			if (existing && existing.instance) {
				try {
					existing.instance.unload();
				} catch (err) { /* fall through to destroy */ }
				try {
					existing.instance.destroy();
				} catch (err) { /* not fatal */ }
			}

			const instance = bb.generate(cfg);
			charts.set(host, { instance: instance, spec: normalised, data: data });
			ro.observe(host);
			return instance;
		},

		unload: function (target) {
			const host = typeof target === 'string' ? document.querySelector(target) : target;
			if (!host) return;
			const rec = charts.get(host);
			if (!rec) return;
			try { rec.instance.destroy(); } catch (err) { /* ignore */ }
			ro.unobserve(host);
			charts.delete(host);
		},

		redrawAll: function () {
			charts.forEach(function (rec, host) {
				rec.instance && rec.instance.config && rec.instance.config('color', { pattern: paletteFromTheme() });
				// Re-render via .load to keep state alive (per spec).
				if (rec.instance && rec.instance.load && rec.spec) {
					const shaped = rowsToColumns(rec.spec, rec.data || []);
					rec.instance.load({ columns: shaped.columns, unload: true });
				}
			});
		},

		/* Auto-hydrate every [data-pt-chart-id] mount on the page by
		   fetching the saved spec + source from the REST API. */
		hydrateMount: function (node) {
			const id = parseInt(node.getAttribute('data-pt-chart-id'), 10);
			if (!id || !PT.boot || !PT.boot.rest) return;
			fetch(PT.boot.rest + '/charts/' + id, { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (chart) {
					if (!chart || !chart.spec) {
						// Fail-fast signal so the snapshot service aborts
						// instead of waiting out its full render timeout.
						node.setAttribute('data-pt-render-error', '1');
						return;
					}
					const src = chart.source || { type: 'static', rows: [] };
					return resolveSource(src).then(function (rows) {
						Billboard.render(node, chart.spec, rows);
					});
				})
				.catch(function (err) {
					node.setAttribute('data-pt-render-error', '1');
					console.error('[PT] Chart hydrate failed', err);
				});
		},
	};

	/* Resolve a data source to an array of row objects. Static sources are
	   inline; REST sources fetch from a whitelisted endpoint with the nonce
	   header; AI sources read from a local cache the AI Console populates. */
	function resolveSource(src) {
		if (!src) return Promise.resolve([]);
		if (src.type === 'static') {
			return Promise.resolve(Array.isArray(src.rows) ? src.rows : []);
		}
		if (src.type === 'rest' && src.endpoint && PT.boot) {
			const root = PT.boot.rest.replace(/\/publictech\/v1\/?$/, '');
			return fetch(root + src.endpoint, {
				credentials: 'same-origin',
				headers: { 'x-wp-nonce': PT.boot.nonce || '' },
			}).then(function (r) { return r.json(); }).then(function (j) {
				return Array.isArray(j) ? j : (Array.isArray(j && j.rows) ? j.rows : []);
			}).catch(function () { return []; });
		}
		if (src.type === 'ai') {
			const cache = (global.PT_AI_CACHE && global.PT_AI_CACHE[src.query_id]) || null;
			return Promise.resolve(Array.isArray(cache) ? cache : []);
		}
		return Promise.resolve([]);
	}

	Billboard.resolveSource = resolveSource;

	PT.billboard = Billboard;

	document.addEventListener('pt:theme', Billboard.redrawAll);

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.pt-billboard-mount[data-pt-chart-id]').forEach(Billboard.hydrateMount);
	});
})(window);
