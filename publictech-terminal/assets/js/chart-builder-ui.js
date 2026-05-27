/* Chart-builder UI behaviour.
 *
 * State machine:
 *   1. user picks a chart type     → spec.data.type, redraw
 *   2. user picks a data source    → fetch + parse rows
 *   3. user tweaks axes / grid     → rebuild spec, redraw
 *   4. user clicks Save            → POST to /charts (or PUT on edit)
 *
 * All redraws funnel through render(), which normalises the in-memory spec
 * via PT.ChartSpec.normalise and hands it to PT.billboard.render. */
(function (global) {
	'use strict';

	const PT = global.PT;
	if (!PT || !PT.billboard || !PT.ChartSpec) return;

	const root = document.querySelector('.pt-builder');
	if (!root) return;

	const bootNode = document.getElementById('pt-builder-boot');
	const boot     = bootNode ? JSON.parse(bootNode.textContent || '{}') : {};

	/* ---------- in-memory state ---------- */
	const state = {
		spec:   PT.ChartSpec.blank('bar'),
		source: { type: 'static', rows: [] },
		rows:   [],
		title:  '',
		desc:   '',
		visibility: 'private',
		sourceType: 'static',
		editingId:  boot.editOf || 0,
		forkOfId:   boot.forkOf || 0,
	};

	const $ = function (sel) { return root.querySelector(sel); };
	const $$ = function (sel) { return Array.from(root.querySelectorAll(sel)); };
	const canvas = $('#pt-builder-canvas');
	const seriesList = $('#pt-series-list');
	const statusEl   = $('#pt-builder-status');
	const metaEl     = $('#pt-builder-canvas-meta');
	const saveStatus = $('#pt-builder-save-status');

	/* ---------- helpers ---------- */
	function setSegmented(segEl, value) {
		segEl.querySelectorAll('button').forEach(function (b) {
			b.classList.toggle('on', b.dataset.val === value);
		});
	}
	function ariaSay(msg) {
		statusEl.textContent = msg;
	}

	function parseRows(raw) {
		const trimmed = String(raw || '').trim();
		if (!trimmed) return [];
		// JSON first.
		if (trimmed[0] === '[' || trimmed[0] === '{') {
			try {
				const j = JSON.parse(trimmed);
				return Array.isArray(j) ? j : (j && Array.isArray(j.rows) ? j.rows : []);
			} catch (err) { /* fall through to CSV */ }
		}
		// CSV: first line headers, comma split, naive (no quoted-comma handling
		// — paste your JSON if you have escaped commas).
		const lines = trimmed.split(/\r?\n/).filter(Boolean);
		if (!lines.length) return [];
		const header = lines.shift().split(',').map(function (h) { return h.trim(); });
		return lines.map(function (line) {
			const cells = line.split(',');
			const row = {};
			header.forEach(function (h, i) {
				const v = (cells[i] || '').trim();
				const n = Number(v);
				row[h] = (v !== '' && !isNaN(n)) ? n : v;
			});
			return row;
		});
	}

	function rebuildSeriesList() {
		seriesList.innerHTML = '';
		const sample = state.rows[0] || {};
		const keys = Object.keys(sample);
		if (!keys.length) {
			seriesList.innerHTML = '<div class="dim">Load data to see numeric columns.</div>';
			return;
		}
		keys.forEach(function (k) {
			const numeric = state.rows.some(function (r) { return typeof r[k] === 'number'; });
			const row = document.createElement('div');
			row.className = 'pt-series-row';
			row.innerHTML =
				'<span class="pt-series-name">' + escapeHTML(k) + '</span>' +
				'<span class="pt-series-kind ' + (numeric ? 'is-num' : 'is-cat') + '">' +
				(numeric ? 'numeric' : 'category') + '</span>';
			seriesList.appendChild(row);
		});
	}

	function render() {
		const normalised = PT.ChartSpec.normalise(state.spec);
		try {
			PT.billboard.render(canvas, normalised, state.rows);
			ariaSay(state.rows.length ? state.rows.length + ' rows' : 'awaiting data');
			metaEl.textContent = normalised.data.type + ' · ' +
				(state.rows.length ? state.rows.length + ' rows' : 'no rows yet');
		} catch (err) {
			console.error('[PT-builder] render error', err);
			ariaSay('render error — check console');
		}
	}

	function escapeHTML(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c];
		});
	}

	/* ---------- chart-type tiles ---------- */
	root.querySelectorAll('.pt-type-tile').forEach(function (btn) {
		btn.addEventListener('click', function () {
			root.querySelectorAll('.pt-type-tile').forEach(function (b) { b.classList.remove('on'); });
			btn.classList.add('on');
			state.spec.data.type = btn.dataset.val || 'bar';
			render();
		});
	});

	/* ---------- source-type segmented ---------- */
	const sourceSeg = root.querySelector('.pt-builder-source-picker');
	sourceSeg.querySelectorAll('button').forEach(function (btn) {
		btn.addEventListener('click', function () {
			setSegmented(sourceSeg, btn.dataset.val);
			state.sourceType = btn.dataset.val;
			$$('.pt-source-block').forEach(function (b) {
				b.hidden = b.dataset.source !== state.sourceType;
			});
		});
	});

	/* ---------- static source: textarea + file upload ---------- */
	$('#pt-static-apply').addEventListener('click', function () {
		state.rows = parseRows($('#pt-static-csv').value);
		state.source = { type: 'static', rows: state.rows };
		rebuildSeriesList();
		render();
	});
	$('#pt-static-file').addEventListener('change', function (ev) {
		const file = ev.target.files && ev.target.files[0];
		if (!file) return;
		const reader = new FileReader();
		reader.onload = function () {
			$('#pt-static-csv').value = String(reader.result || '');
		};
		reader.readAsText(file);
	});

	/* ---------- REST source: live endpoint preview ---------- */
	$('#pt-rest-load').addEventListener('click', function () {
		const endpoint = $('#pt-rest-endpoint').value.trim();
		const err = PT.ChartSpec.validateSource({ type: 'rest', endpoint: endpoint });
		if (err) {
			ariaSay('REST: ' + err);
			return;
		}
		const root2 = PT.boot.rest.replace(/\/publictech\/v1\/?$/, '');
		ariaSay('fetching…');
		fetch(root2 + endpoint, {
			credentials: 'same-origin',
			headers: { 'x-wp-nonce': PT.boot.nonce || '' },
		})
			.then(function (r) { return r.json(); })
			.then(function (j) {
				state.rows = Array.isArray(j) ? j : (Array.isArray(j && j.rows) ? j.rows : []);
				state.source = { type: 'rest', endpoint: endpoint };
				rebuildSeriesList();
				render();
			})
			.catch(function (err2) {
				console.error(err2);
				ariaSay('fetch failed');
			});
	});

	/* ---------- AI source: hydrate from cached query hash ---------- */
	$('#pt-ai-query').addEventListener('change', function (ev) {
		const qid = ev.target.value.trim();
		const cache = (global.PT_AI_CACHE && global.PT_AI_CACHE[qid]) || null;
		if (!cache) {
			ariaSay('No cached payload found — run the query in /ai first.');
			return;
		}
		state.rows = cache;
		state.source = { type: 'ai', query_id: qid };
		rebuildSeriesList();
		render();
	});

	/* ---------- Stream source: hydrate from current filtered stream-table data ---------- */
	$('#pt-stream-target').addEventListener('change', function (ev) {
		const target = ev.target.value.trim();
		const m = target.match(/^([a-z0-9-]+)\/([a-z0-9_]+)$/i);
		if (!m) {
			ariaSay('Stream: format is schema/table');
			return;
		}
		const endpoint = '/publictech/v1/stream/' + m[1] + '/' + m[2] + '?limit=200';
		$('#pt-rest-endpoint').value = endpoint;
		setSegmented(sourceSeg, 'rest');
		state.sourceType = 'rest';
		$$('.pt-source-block').forEach(function (b) { b.hidden = b.dataset.source !== 'rest'; });
		$('#pt-rest-load').click();
	});

	/* ---------- axis controls ---------- */
	$('#pt-axis-x-label').addEventListener('input', function (ev) {
		state.spec.axis.x.label = ev.target.value;
		render();
	});
	$('#pt-axis-x-type').addEventListener('change', function (ev) {
		state.spec.axis.x.type = ev.target.value;
		render();
	});
	$('#pt-axis-y-label').addEventListener('input', function (ev) {
		state.spec.axis.y.label = ev.target.value;
		render();
	});
	$('#pt-grid-x').addEventListener('change', function (ev) {
		state.spec.grid.x.show = !!ev.target.checked;
		render();
	});
	$('#pt-grid-y').addEventListener('change', function (ev) {
		state.spec.grid.y.show = !!ev.target.checked;
		render();
	});
	$('#pt-legend-show').addEventListener('change', function (ev) {
		state.spec.legend = { show: !!ev.target.checked };
		render();
	});
	$('#pt-point-show').addEventListener('change', function (ev) {
		state.spec.point = { show: !!ev.target.checked };
		render();
	});

	/* ---------- stacking ---------- */
	$('#pt-stacking-add').addEventListener('click', function () {
		const wrap = $('#pt-stacking-groups');
		const div = document.createElement('div');
		div.className = 'pt-stacking-group';
		div.innerHTML = '<input type="text" placeholder="series A, series B" data-stack-input>';
		wrap.appendChild(div);
		div.querySelector('[data-stack-input]').addEventListener('change', recalcStacking);
	});
	function recalcStacking() {
		const groups = $$('[data-stack-input]').map(function (el) {
			return el.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
		}).filter(function (g) { return g.length; });
		state.spec.data.groups = groups;
		render();
	}

	/* ---------- visibility segmented ---------- */
	const visSeg = root.querySelector('[data-knob="visibility"]');
	visSeg.querySelectorAll('button').forEach(function (btn) {
		btn.addEventListener('click', function () {
			setSegmented(visSeg, btn.dataset.val);
			state.visibility = btn.dataset.val;
		});
	});

	/* ---------- save ---------- */
	$('#pt-builder-save').addEventListener('click', function () {
		state.title = $('#pt-save-title').value.trim();
		state.desc  = $('#pt-save-desc').value.trim();
		if (!state.title) {
			saveStatus.textContent = 'Title required.';
			return;
		}
		const specErr = PT.ChartSpec.validate(state.spec);
		if (specErr) { saveStatus.textContent = 'Spec: ' + specErr; return; }

		saveStatus.textContent = 'saving…';
		const body = {
			title:       state.title,
			description: state.desc,
			visibility:  state.visibility,
			spec:        PT.ChartSpec.normalise(state.spec),
			source:      PT.ChartSpec.normaliseSource(state.source),
		};
		const url = state.editingId
			? PT.boot.rest + '/charts/' + state.editingId
			: PT.boot.rest + '/charts';
		fetch(url, {
			method: state.editingId ? 'PUT' : 'POST',
			credentials: 'same-origin',
			headers: {
				'content-type': 'application/json',
				'x-wp-nonce': PT.boot.nonce || '',
			},
			body: JSON.stringify(body),
		})
			.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
			.then(function (resp) {
				if (!resp.ok) {
					saveStatus.textContent = 'save failed: ' + (resp.body && resp.body.message || 'unknown');
					return;
				}
				saveStatus.innerHTML = 'saved · <a href="' + resp.body.permalink + '">view chart</a>';
				state.editingId = resp.body.id;
			})
			.catch(function (err) {
				saveStatus.textContent = 'network error';
				console.error(err);
			});
	});

	/* ---------- export JSON ---------- */
	$('#pt-builder-export-json').addEventListener('click', function () {
		const blob = new Blob([JSON.stringify({
			spec:   PT.ChartSpec.normalise(state.spec),
			source: PT.ChartSpec.normaliseSource(state.source),
			rows:   state.rows,
		}, null, 2)], { type: 'application/json' });
		const a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = (state.title || 'chart') + '.json';
		document.body.appendChild(a); a.click(); a.remove();
	});

	$('#pt-builder-preview-refresh').addEventListener('click', render);

	$('#pt-builder-canvas-shuffle').addEventListener('click', function () {
		const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
		state.rows = months.map(function (m, i) {
			return { month: m, sales: 100 + Math.round(80 * Math.sin(i / 1.5) + 40), returns: Math.round(20 + Math.random() * 30) };
		});
		state.source = { type: 'static', rows: state.rows };
		$('#pt-static-csv').value = JSON.stringify(state.rows, null, 2);
		rebuildSeriesList();
		render();
	});

	/* ---------- boot ---------- */
	function bootstrap() {
		// Edit mode: hydrate from existing chart record.
		if (state.editingId) {
			fetch(PT.boot.rest + '/charts/' + state.editingId, {
				credentials: 'same-origin',
				headers: { 'x-wp-nonce': PT.boot.nonce || '' },
			})
				.then(function (r) { return r.json(); })
				.then(function (chart) {
					if (chart && chart.spec) {
						state.spec   = PT.ChartSpec.normalise(chart.spec);
						state.source = PT.ChartSpec.normaliseSource(chart.source || { type: 'static', rows: [] });
						state.title  = chart.title || '';
						state.desc   = chart.description || '';
						state.visibility = chart.visibility === 'public' ? 'public' : 'private';
						$('#pt-save-title').value = state.title;
						$('#pt-save-desc').value  = state.desc;
						setSegmented(visSeg, state.visibility);
						return PT.billboard.resolveSource(state.source).then(function (rows) {
							state.rows = rows;
							rebuildSeriesList();
							render();
						});
					}
				});
			return;
		}

		// fromAI param hydrates the AI Console cache, if present.
		if (boot.fromAI && global.PT_AI_CACHE && global.PT_AI_CACHE[boot.fromAI]) {
			state.rows = global.PT_AI_CACHE[boot.fromAI];
			state.source = { type: 'ai', query_id: boot.fromAI };
			$('#pt-ai-query').value = boot.fromAI;
			setSegmented(sourceSeg, 'ai');
			$$('.pt-source-block').forEach(function (b) { b.hidden = b.dataset.source !== 'ai'; });
			rebuildSeriesList();
			render();
			return;
		}

		// fromStream param prefills the stream block, then loads.
		if (boot.fromStream) {
			$('#pt-stream-target').value = boot.fromStream;
			setSegmented(sourceSeg, 'stream');
			$$('.pt-source-block').forEach(function (b) { b.hidden = b.dataset.source !== 'stream'; });
			$('#pt-stream-target').dispatchEvent(new Event('change'));
			return;
		}

		// Fork mode: deep-link populates the staging form but doesn't write yet.
		if (state.forkOfId) {
			fetch(PT.boot.rest + '/charts/' + state.forkOfId, { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (chart) {
					if (chart && chart.spec) {
						state.spec   = PT.ChartSpec.normalise(chart.spec);
						state.source = PT.ChartSpec.normaliseSource(chart.source || { type: 'static', rows: [] });
						$('#pt-save-title').value = 'Fork of: ' + (chart.title || '');
						$('#pt-builder-fork').hidden = false;
						return PT.billboard.resolveSource(state.source).then(function (rows) {
							state.rows = rows;
							rebuildSeriesList();
							render();
						});
					}
				});
			return;
		}

		// Empty boot: kick off the sample preview so the canvas isn't blank.
		$('#pt-builder-canvas-shuffle').click();
	}

	$('#pt-builder-fork').addEventListener('click', function () {
		if (!state.forkOfId) return;
		fetch(PT.boot.rest + '/charts/' + state.forkOfId + '/fork', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'x-wp-nonce': PT.boot.nonce || '' },
		})
			.then(function (r) { return r.json(); })
			.then(function (chart) {
				if (chart && chart.permalink) {
					window.location.href = chart.permalink;
				}
			});
	});

	bootstrap();
})(window);
