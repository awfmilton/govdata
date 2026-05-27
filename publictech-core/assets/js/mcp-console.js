/* AI/MCP console — wraps the /publictech/v1/ai/query endpoint. The endpoint
   itself defers to the publictech_text_to_sql PHP filter, so the front-end is
   model-agnostic. The console swaps between Chart / Table / Raw JSON views
   without re-querying. */
(function (global) {
	'use strict';

	const PT = global.PT = global.PT || {};
	const boot = global.PT_BOOT || {};

	function $(s, r) { return (r || document).querySelector(s); }
	function $$(s, r) { return Array.from((r || document).querySelectorAll(s)); }

	function highlightSQL(sql) {
		return String(sql || '')
			.replace(/--[^\n]*/g, function (m) { return '<span class="cm">' + escape(m) + '</span>'; })
			.replace(/\b(SELECT|FROM|WHERE|AND|OR|GROUP BY|ORDER BY|LIMIT|JOIN|ON|AS|IN|BETWEEN|SUM|COUNT|AVG|MAX|MIN|DESC|ASC)\b/g, '<span class="kw">$1</span>')
			.replace(/('[^']*')/g, '<span class="st">$1</span>')
			.replace(/\b(\d+(?:\.\d+)?)\b/g, '<span class="nm">$1</span>');
	}
	function escape(s) {
		return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' })[c]; });
	}

	let lastResult = null;
	function render(view) {
		const host = $('#aiResponse');
		if (!host || !lastResult) return;
		if (view === 'sql') {
			host.innerHTML = '<div class="resp-sql">' + highlightSQL(lastResult.sql) + '</div>';
		} else if (view === 'table') {
			const rows = lastResult.rows || [];
			if (!rows.length) { host.innerHTML = '<div class="dim">No rows.</div>'; return; }
			const cols = Object.keys(rows[0]);
			host.innerHTML = '<table class="stream"><thead><tr>' + cols.map(function (c) { return '<th>' + escape(c) + '</th>'; }).join('') +
				'</tr></thead><tbody>' + rows.slice(0, 100).map(function (r) {
					return '<tr>' + cols.map(function (c) { return '<td>' + escape(r[c]) + '</td>'; }).join('') + '</tr>';
				}).join('') + '</tbody></table>';
		} else if (view === 'json') {
			host.innerHTML = '<pre style="white-space:pre-wrap;font:11px ui-monospace,monospace;color:var(--ink-1)">' + escape(JSON.stringify(lastResult, null, 2)) + '</pre>';
		} else {
			host.innerHTML = '<div class="chart-host" id="aiChart" style="height:300px"></div>';
			const rows = lastResult.rows || [];
			if (rows.length && PT.chart) {
				const cols = Object.keys(rows[0]);
				const labelCol = cols.find(function (c) { return typeof rows[0][c] === 'string'; }) || cols[0];
				const valueCol = cols.find(function (c) { return typeof rows[0][c] === 'number'; }) || cols[1];
				PT.chart('#aiChart', 'bar', rows.map(function (r) { return { label: String(r[labelCol]), value: +r[valueCol] || 0 }; }));
			}
		}
	}

	function execute() {
		const prompt = ($('#aiPrompt') || {}).value || '';
		if (!prompt.trim()) return;
		const host = $('#aiResponse');
		host && (host.innerHTML = '<div class="pt-loading">Compiling…</div>');

		fetch(boot.rest + '/ai/query', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'content-type': 'application/json', 'x-wp-nonce': boot.nonce },
			body: JSON.stringify({ prompt: prompt }),
		})
			.then(function (r) {
				if (r.status === 402) throw new Error(boot.i18n.tierLocked);
				if (r.status === 429) throw new Error(boot.i18n.rateLimit);
				return r.json();
			})
			.then(function (data) {
				lastResult = data;
				const active = ($('.ai-view-switch .on') || {}).dataset || { view: 'chart' };
				render(active.view);
			})
			.catch(function (e) {
				if (!host) return;
				const div = document.createElement('div');
				div.className = 'pt-error';
				div.textContent = e && e.message ? String(e.message) : 'Query failed.';
				host.replaceChildren(div);
			});
	}

	document.addEventListener('DOMContentLoaded', function () {
		const run = $('#aiRun');
		if (run) run.addEventListener('click', execute);
		const prompt = $('#aiPrompt');
		if (prompt) prompt.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); execute(); }
		});
		$$('.ai-view-switch button').forEach(function (b) {
			b.addEventListener('click', function () {
				$$('.ai-view-switch button').forEach(function (x) { x.classList.remove('on'); });
				b.classList.add('on');
				render(b.dataset.view);
			});
		});
		$$('.sug').forEach(function (s) {
			s.addEventListener('click', function () {
				if ($('#aiPrompt')) $('#aiPrompt').value = s.textContent.replace(/^\/\/[^\n]*\n?/, '').trim();
			});
		});
	});

	PT.mcp = { execute };
})(window);
