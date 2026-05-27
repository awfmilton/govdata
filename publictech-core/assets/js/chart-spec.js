/* Client-side mirror of the PHP Chart_Spec validator. Used by the builder
   to fail fast before posting + by billboard-bridge.js to coerce stored
   payloads on render. The PHP side is still authoritative — this only
   keeps the UI honest. */
(function (global) {
	'use strict';

	const PT = global.PT = global.PT || {};

	const CHART_TYPES = [
		'bar', 'line', 'area', 'area-line-range', 'area-spline',
		'spline', 'step', 'area-step', 'scatter',
		'pie', 'donut', 'gauge',
	];
	const AXIS_TYPES   = ['category', 'indexed', 'timeseries', 'log'];
	const SOURCE_TYPES = ['static', 'rest', 'ai'];
	const VERSION      = 1;
	const LABEL_MAX    = 64;
	const ROWS_MAX     = 1000;

	function clampLabel(s) {
		return String(s == null ? '' : s).slice(0, LABEL_MAX);
	}
	function pickAxisType(v, fallback) {
		return AXIS_TYPES.indexOf(v) !== -1 ? v : fallback;
	}

	const ChartSpec = {
		VERSION: VERSION,
		CHART_TYPES: CHART_TYPES,
		AXIS_TYPES: AXIS_TYPES,
		SOURCE_TYPES: SOURCE_TYPES,

		/** Returns null on success, otherwise an error string. */
		validate: function (raw) {
			if (!raw || typeof raw !== 'object') return 'Spec must be an object.';
			if (raw.version !== VERSION) return 'Unsupported spec version.';
			if (!raw.data || CHART_TYPES.indexOf(raw.data.type) === -1) return 'Unknown chart type.';
			return null;
		},

		/** Coerces a free-form spec into the canonical shape. */
		normalise: function (raw) {
			raw = raw || {};
			const data = raw.data || {};
			const axis = raw.axis || {};
			const grid = raw.grid || {};
			const theme = raw.theme || {};
			const out = {
				version: VERSION,
				data: {
					type: CHART_TYPES.indexOf(data.type) !== -1 ? data.type : 'bar',
					groups: Array.isArray(data.groups) ? data.groups.slice(0, 8).map(function (g) {
						return (Array.isArray(g) ? g : []).slice(0, 32)
							.map(function (n) { return String(n || ''); })
							.filter(Boolean);
					}).filter(function (g) { return g.length; }) : [],
				},
				axis: {
					x: { label: clampLabel((axis.x || {}).label), type: pickAxisType((axis.x || {}).type, 'category') },
					y: { label: clampLabel((axis.y || {}).label), type: pickAxisType((axis.y || {}).type, 'indexed') },
				},
				grid: {
					x: { show: !!(grid.x && grid.x.show) },
					y: { show: !!(grid.y && grid.y.show) },
				},
				theme: {
					useGlobalCustomizer: theme.useGlobalCustomizer !== false,
				},
			};
			if (raw.legend && typeof raw.legend === 'object' && 'show' in raw.legend) {
				out.legend = { show: !!raw.legend.show };
			}
			if (raw.point && typeof raw.point === 'object' && 'show' in raw.point) {
				out.point = { show: !!raw.point.show };
			}
			if (raw.transition && typeof raw.transition === 'object' && raw.transition.duration != null) {
				const d = parseInt(raw.transition.duration, 10) || 0;
				out.transition = { duration: Math.max(0, Math.min(2000, d)) };
			}
			return out;
		},

		validateSource: function (raw) {
			if (!raw || typeof raw !== 'object') return 'Source must be an object.';
			if (SOURCE_TYPES.indexOf(raw.type) === -1) return 'Unknown source type.';
			if (raw.type === 'static' && !Array.isArray(raw.rows)) return 'rows must be an array.';
			if (raw.type === 'static' && raw.rows.length > ROWS_MAX) return 'Too many rows.';
			if (raw.type === 'rest' && !/^\/publictech\/v1\/[A-Za-z0-9_/-]+$/.test(String(raw.endpoint || ''))) return 'Invalid endpoint.';
			if (raw.type === 'ai' && !/^[A-Fa-f0-9]{8,32}$/.test(String(raw.query_id || ''))) return 'Invalid query_id.';
			return null;
		},

		normaliseSource: function (raw) {
			raw = raw || { type: 'static', rows: [] };
			const t = SOURCE_TYPES.indexOf(raw.type) !== -1 ? raw.type : 'static';
			if (t === 'static') {
				return { type: 'static', rows: Array.isArray(raw.rows) ? raw.rows.slice(0, ROWS_MAX) : [] };
			}
			if (t === 'rest') {
				return { type: 'rest', endpoint: String(raw.endpoint || '') };
			}
			return { type: 'ai', query_id: String(raw.query_id || '') };
		},

		/** Default spec used when the user starts from a blank slate. */
		blank: function (kind) {
			return {
				version: VERSION,
				data:    { type: CHART_TYPES.indexOf(kind) !== -1 ? kind : 'bar', groups: [] },
				axis:    { x: { label: '', type: 'category' }, y: { label: '', type: 'indexed' } },
				grid:    { x: { show: false }, y: { show: true } },
				theme:   { useGlobalCustomizer: true },
				legend:  { show: true },
			};
		},
	};

	PT.ChartSpec = ChartSpec;
})(window);
