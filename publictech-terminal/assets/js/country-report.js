/**
 * Country pages (/countries/, /country/{slug}/): charts from the JSON embedded by country-report.php and
 * country-index.php, plus the index table's sort and filter. Needs d3 (loaded by the asset pipeline).
 */
(function () {
	'use strict';

	function theme() {
		var cs = getComputedStyle(document.documentElement);
		var v = function (n, d) { return (cs.getPropertyValue(n) || '').trim() || d; };
		return {
			accent: v('--accent', '#00a0d2'), warn: v('--warn', '#f5a623'), pos: v('--pos', '#3ddc97'),
			neg: v('--neg', '#ff6b6b'), ink1: v('--ink-1', '#c9d1d9'), ink2: v('--ink-2', '#8b949e'),
			ink3: v('--ink-3', '#6e7681'), line: v('--line', '#30363d'), bg1: v('--bg-1', '#0d1117')
		};
	}

	var fmtInt = function (d) { return d3.format(',')(Math.round(d)); };
	var fmtShort = function (d) { return Math.abs(d) >= 1000 ? d3.format('.2s')(d).replace('G', 'B') : d3.format(',.0f')(d); };
	var registry = [];
	/** Millions of dollars as C$12M / C$1.2B (d3's SI 'k' would print 'C$6.0kM'). */
	function moneyM(d, cur) {
		if (d === 0) { return '0'; }
		var a = Math.abs(d);
		return cur + (a >= 1000 ? d3.format('~g')(+(d / 1000).toPrecision(3)) + 'B' : d3.format('~g')(+d.toPrecision(3)) + 'M');
	}

	function mount(host, draw) {
		if (!host || !window.d3) { return; }
		var rec = { host: host, draw: draw };
		registry.push(rec);
		draw(host);
		if (window.ResizeObserver) {
			var w = host.clientWidth;
			new ResizeObserver(function () { if (host.clientWidth !== w) { w = host.clientWidth; draw(host); } }).observe(host);
		}
	}
	document.addEventListener('pt:theme', function () { registry.forEach(function (r) { r.draw(r.host); }); });

	function frame(host, m) {
		host.innerHTML = '';
		var W = host.clientWidth || 600, H = host.clientHeight || 260;
		var svg = d3.select(host).append('svg').attr('viewBox', '0 0 ' + W + ' ' + H).attr('width', '100%').attr('height', H)
			.attr('role', 'img');
		return { svg: svg, W: W, H: H, m: m };
	}

	/** Log scales: one tick per power of ten, so labels never pile up. */
	function decades(scale) {
		var d = scale.domain(), out = [];
		for (var e = Math.ceil(Math.log10(d[0])); e <= Math.floor(Math.log10(d[1])); e++) { out.push(Math.pow(10, e)); }
		return out;
	}

	function axes(f, x, y, t, xfmt, yfmt) {
		var ax = d3.axisBottom(x).tickFormat(xfmt || d3.format('d')), ay = d3.axisLeft(y).tickFormat(yfmt || fmtShort);
		if (x.base) { ax.tickValues(decades(x)); } else { ax.ticks(Math.max(2, Math.floor(f.W / 80))); }
		if (y.base) { ay.tickValues(decades(y)); } else { ay.ticks(5); }
		var g = f.svg.append('g').attr('transform', 'translate(0,' + (f.H - f.m.b) + ')').call(ax);
		var h = f.svg.append('g').attr('transform', 'translate(' + f.m.l + ',0)').call(ay);
		[g, h].forEach(function (a) { a.selectAll('text').attr('fill', t.ink2).style('font-size', '10px'); a.selectAll('path,line').attr('stroke', t.line); });
		f.svg.append('g').attr('transform', 'translate(' + f.m.l + ',0)')
			.call((y.base ? d3.axisLeft(y).tickValues(decades(y)) : d3.axisLeft(y).ticks(5)).tickSize(-(f.W - f.m.l - f.m.r)).tickFormat(''))
			.selectAll('line').attr('stroke', t.line).attr('opacity', 0.35);
		f.svg.selectAll('.domain').attr('stroke', t.line);
	}

	/** series: [{name, color, values: [[x, y], ...]}]; opts: {yfmt, markers: [x], legend} */
	function lineChart(host, series, opts) {
		opts = opts || {};
		var t = theme(), f = frame(host, { t: 12, r: 14, b: 26, l: 54 });
		var pts = [];
		series.forEach(function (s) { s.values.forEach(function (p) { if (p[1] !== null && p[1] !== undefined) { pts.push(p); } }); });
		if (!pts.length) { host.innerHTML = '<div class="pt-cty-empty">No data</div>'; return; }
		var x = d3.scaleLinear().domain(d3.extent(pts, function (p) { return p[0]; })).range([f.m.l, f.W - f.m.r]);
		var lo = Math.min(0, d3.min(pts, function (p) { return p[1]; }));
		var y = d3.scaleLinear().domain([lo, d3.max(pts, function (p) { return p[1]; }) * 1.08 || 1]).nice().range([f.H - f.m.b, f.m.t]);
		axes(f, x, y, t, null, opts.yfmt);
		(opts.hlines || []).forEach(function (h) {
			if (h.y < y.domain()[0] || h.y > y.domain()[1]) { return; }
			f.svg.append('line').attr('x1', f.m.l).attr('x2', f.W - f.m.r).attr('y1', y(h.y)).attr('y2', y(h.y))
				.attr('stroke', t.neg).attr('stroke-dasharray', '4,3');
			f.svg.append('text').attr('x', f.W - f.m.r - 4).attr('y', y(h.y) - 4).attr('text-anchor', 'end')
				.attr('fill', t.neg).style('font-size', '10px').text(h.label);
		});
		(opts.markers || []).forEach(function (mx) {
			if (mx < x.domain()[0] || mx > x.domain()[1]) { return; }
			f.svg.append('line').attr('x1', x(mx)).attr('x2', x(mx)).attr('y1', f.m.t).attr('y2', f.H - f.m.b)
				.attr('stroke', t.warn).attr('stroke-dasharray', '3,3').attr('opacity', 0.9)
				.append('title').text(mx);
		});
		series.forEach(function (s) {
			var vals = s.values.filter(function (p) { return p[1] !== null && p[1] !== undefined; });
			// Break the line where a year is missing, so an unpublished year is not drawn as a trend.
			var line = d3.line().x(function (p) { return x(p[0]); }).y(function (p) { return y(p[1]); });
			var segs = [], cur = [];
			vals.forEach(function (p, i) {
				if (i && p[0] - vals[i - 1][0] > (opts.gap || 1)) { segs.push(cur); cur = []; }
				cur.push(p);
			});
			segs.push(cur);
			segs.forEach(function (seg) {
				f.svg.append('path').datum(seg).attr('fill', 'none').attr('stroke', s.color).attr('stroke-width', 1.8).attr('d', line);
				if (seg.length === 1) {
					f.svg.append('circle').attr('cx', x(seg[0][0])).attr('cy', y(seg[0][1])).attr('r', 2.5).attr('fill', s.color);
				}
			});
			f.svg.append('g').selectAll('circle').data(vals).enter().append('circle')
				.attr('cx', function (p) { return x(p[0]); }).attr('cy', function (p) { return y(p[1]); })
				.attr('r', 6).attr('fill', 'transparent')
				.append('title').text(function (p) { return s.name + ' ' + p[0] + ': ' + (opts.tipfmt || fmtInt)(p[1]); });
		});
		if (series.length > 1) {
			var lg = f.svg.append('g').attr('transform', 'translate(' + (f.m.l + 6) + ',' + (f.m.t + 4) + ')');
			var lx = 0, ly = 0, room = f.W - f.m.l - f.m.r - 12;
			series.forEach(function (s) {
				if (!s.name) { return; }
				var w = 24 + s.name.length * 5.6;
				if (lx && lx + w > room) { lx = 0; ly += 13; }     // wrap rather than run off the chart
				var g = lg.append('g').attr('transform', 'translate(' + lx + ',' + ly + ')');
				lx += w;
				g.append('rect').attr('width', 10).attr('height', 3).attr('y', 4).attr('fill', s.color);
				g.append('text').attr('x', 14).attr('y', 9).text(s.name).attr('fill', t.ink2).style('font-size', '10px');
			});
		}
	}

	/** values: [[label, y]] vertical bars */
	function barChart(host, values, opts) {
		opts = opts || {};
		var t = theme(), f = frame(host, { t: 8, r: 8, b: 22, l: 40 });
		if (!values.length) { host.innerHTML = '<div class="pt-cty-empty">No data</div>'; return; }
		var x = d3.scaleBand().domain(values.map(function (v) { return v[0]; })).range([f.m.l, f.W - f.m.r]).padding(0.15);
		var y = d3.scaleLinear().domain([0, d3.max(values, function (v) { return v[1]; }) || 1]).nice().range([f.H - f.m.b, f.m.t]);
		var every = Math.ceil(values.length / Math.max(2, Math.floor(f.W / 60)));
		var g = f.svg.append('g').attr('transform', 'translate(0,' + (f.H - f.m.b) + ')')
			.call(d3.axisBottom(x).tickValues(x.domain().filter(function (_, i) { return i % every === 0; })));
		var h = f.svg.append('g').attr('transform', 'translate(' + f.m.l + ',0)').call(d3.axisLeft(y).ticks(3).tickFormat(opts.yfmt || fmtShort));
		[g, h].forEach(function (a) { a.selectAll('text').attr('fill', t.ink2).style('font-size', '10px'); a.selectAll('path,line').attr('stroke', t.line); });
		f.svg.append('g').selectAll('rect').data(values).enter().append('rect')
			.attr('x', function (v) { return x(v[0]); }).attr('width', x.bandwidth())
			.attr('y', function (v) { return y(v[1]); }).attr('height', function (v) { return y(0) - y(v[1]); })
			.attr('fill', opts.color || t.accent)
			.append('title').text(function (v) { return v[0] + ': ' + fmtInt(v[1]); });
	}

	function scatter(host, rows) {
		var t = theme(), f = frame(host, { t: 12, r: 16, b: 34, l: 60 });
		rows = rows.filter(function (r) { return r[2] > 0 && r[3] > 0; });
		if (!rows.length) { host.innerHTML = '<div class="pt-cty-empty">No data</div>'; return; }
		var x = d3.scaleLog().domain(d3.extent(rows, function (r) { return r[3]; })).nice().range([f.m.l, f.W - f.m.r]);
		var y = d3.scaleLog().domain(d3.extent(rows, function (r) { return r[2]; })).nice().range([f.H - f.m.b, f.m.t]);
		var r = d3.scaleSqrt().domain([0, d3.max(rows, function (d) { return d[4]; }) || 1]).range([3, 14]);
		axes(f, x, y, t, function (d) { return d >= 1000 ? '$' + d3.format('~g')(d / 1000) + 'B' : '$' + d3.format('~g')(d) + 'M'; }, fmtShort);
		f.svg.append('text').attr('x', f.W - f.m.r).attr('y', f.H - 4).attr('text-anchor', 'end').attr('fill', t.ink3).style('font-size', '10px').text('Aid, US$ millions (log)');
		f.svg.append('text').attr('x', f.m.l + 6).attr('y', f.m.t + 10).attr('fill', t.ink3).style('font-size', '10px').text('Immigrants (log)');
		var dots = f.svg.append('g').selectAll('a').data(rows).enter().append('a')
			.attr('href', function (d) { return '/country/' + d[0] + '/'; });
		dots.append('circle').attr('cx', function (d) { return x(d[3]); }).attr('cy', function (d) { return y(d[2]); })
			.attr('r', function (d) { return r(d[4]); }).attr('fill', function (d) { return d[4] ? t.warn : t.accent; }).attr('opacity', 0.65)
			.attr('stroke', t.bg1).attr('stroke-width', 0.5)
			.append('title').text(function (d) { return d[1] + ': ' + fmtInt(d[2]) + ' immigrants, US$' + fmtShort(d[3]) + 'M aid, ' + d[4] + ' MPs'; });
		var big = rows.slice().sort(function (a, b) { return (b[2] * b[3]) - (a[2] * a[3]); }).slice(0, 12);
		f.svg.append('g').selectAll('text').data(big).enter().append('text')
			.attr('x', function (d) { return x(d[3]) + r(d[4]) + 2; }).attr('y', function (d) { return y(d[2]) + 3; })
			.text(function (d) { return d[1]; }).attr('fill', t.ink2).style('font-size', '9px').style('pointer-events', 'none');
	}

	/* ── country page ───────────────────────────────────────────────────── */
	function countryPage() {
		var node = document.getElementById('ptCountryData');
		if (!node) { return; }
		var D = JSON.parse(node.textContent || '{}');
		var t = theme();
		var sit = D.sitting || [];
		var sitSet = {};
		sit.forEach(function (r) { if (r[1] > 0) { sitSet[r[0]] = true; } });
		var markers = Object.keys(sitSet).map(Number).filter(function (y) { return !sitSet[y - 1] && y >= 1960; });

		var imm = D.immigration || [];
		var mode = 'count';
		function drawImm(host) {
			var vals = imm.map(function (r) { return [r[0], mode === 'count' ? r[1] : (r[2] === null ? null : r[2] * 100)]; });
			lineChart(host, [{ name: mode === 'count' ? 'Immigrants' : 'Share of all immigrants (%)', color: t.accent, values: vals }], {
				markers: markers, yfmt: mode === 'count' ? fmtShort : function (d) { return d3.format('.2~f')(d) + '%'; },
				tipfmt: mode === 'count' ? fmtInt : function (d) { return d3.format('.3f')(d) + '%'; }
			});
		}
		var immHost = document.getElementById('ptCtyImmChart');
		mount(immHost, drawImm);
		document.querySelectorAll('[data-pt-imm]').forEach(function (b) {
			b.addEventListener('click', function () {
				mode = b.getAttribute('data-pt-imm');
				document.querySelectorAll('[data-pt-imm]').forEach(function (o) { o.classList.toggle('on', o === b); });
				drawImm(immHost);
			});
		});

		var aid = D.aid || [];
		mount(document.getElementById('ptCtyAidChart'), function (host) {
			lineChart(host, [
				{ name: 'Gross ODA', color: t.pos, values: aid.map(function (r) { return [r[0], r[1]]; }) },
				{ name: 'Net ODA', color: t.ink2, values: aid.map(function (r) { return [r[0], r[2]]; }) },
				{ name: 'Humanitarian', color: t.warn, values: aid.filter(function (r) { return r[4] !== null; }).map(function (r) { return [r[0], r[4]]; }) }
			], { markers: markers, yfmt: function (d) { return moneyM(d, '$'); }, tipfmt: function (d) { return 'US$' + d3.format(',.1f')(d) + 'M'; } });
		});

		var tf = D.tempFlows || {}, ts = D.tempStocks || {}, asy = D.asylum || [];
		var tnames = { study: 'Study permits', tfwp: 'TFWP work permits', imp: 'IMP work permits', hc: 'Humanitarian work permits' };
		var tcols = { study: t.warn, tfwp: t.neg, imp: t.pos, hc: t.ink2 };
		mount(document.getElementById('ptCtyTemp'), function (host) {
			var series = [{ name: 'New permanent residents', color: t.accent, values: imm.filter(function (r) { return r[0] >= 2015; }).map(function (r) { return [r[0], r[1]]; }) }];
			['study', 'tfwp', 'imp', 'hc'].forEach(function (k) { if (tf[k] && tf[k].length) { series.push({ name: tnames[k], color: tcols[k], values: tf[k] }); } });
			var full = asy.filter(function (r) { return !r[8] || String(r[8]).indexOf('December') !== -1; });
			if (full.length) { series.push({ name: 'Refugee claims', color: '#9b7bff', values: full.map(function (r) { return [r[0], r[1] || 0]; }) }); }
			lineChart(host, series, { yfmt: fmtShort });
		});
		mount(document.getElementById('ptCtyHolders'), function (host) {
			var series = [];
			['study', 'tfwp', 'imp'].forEach(function (k) { if (ts[k] && ts[k].length) { series.push({ name: tnames[k].replace(' permits', ''), color: tcols[k], values: ts[k] }); } });
			lineChart(host, series, { yfmt: fmtShort });
		});

		var cadS = D.aidCad || [];
		mount(document.getElementById('ptCtyAidCad'), function (host) {
			var fy = function (r) { return parseInt(r[0], 10); };
			lineChart(host, [
				{ name: 'International assistance', color: t.accent, values: cadS.map(function (r) { return [fy(r), r[1] / 1e6]; }) },
				{ name: 'of which ODA (2016-17 on)', color: t.pos, values: cadS.filter(function (r) { return r[2] !== null; }).map(function (r) { return [fy(r), r[2] / 1e6]; }) }
			], { yfmt: function (d) { return moneyM(d, 'C$'); }, tipfmt: function (d) { return 'C$' + d3.format(',.1f')(d) + 'M'; } });
		});

		if (sit.length) {
			var y0 = Math.min(1960, sit[0][0]), y1 = new Date().getFullYear(), bars = [], byY = {};
			sit.forEach(function (r) { byY[r[0]] = r[1]; });
			for (var y = y0; y <= y1; y++) { bars.push([String(y), byY[y] || 0]); }
			mount(document.getElementById('ptCtySitting'), function (host) { barChart(host, bars, { color: t.warn, yfmt: d3.format('d') }); });
		} else {
			var s = document.getElementById('ptCtySitting');
			if (s) { s.style.display = 'none'; }
		}
		mount(document.getElementById('ptCtyMonthly'), function (host) { barChart(host, (D.monthly || []).map(function (r) { return [r[0].slice(2), r[1]]; })); });
		var bases = D.bases || {}, names = { last_permanent_residence: 'Last residence', citizenship: 'Citizenship', birth: 'Birth' };
		var cols = { last_permanent_residence: t.accent, citizenship: t.warn, birth: t.pos };
		mount(document.getElementById('ptCtyBases'), function (host) {
			lineChart(host, Object.keys(bases).map(function (k) { return { name: names[k] || k, color: cols[k] || t.ink1, values: bases[k] }; }), { gap: 1 });
		});
	}

	/* ── index page ─────────────────────────────────────────────────────── */
	function sortable(table) {
		var ths = table.querySelectorAll('th[data-sort]');
		ths.forEach(function (th, col) {
			th.style.cursor = 'pointer';
			th.setAttribute('tabindex', '0');
			var dir = 1;
			function go() {
				var tb = table.tBodies[0], rows = Array.prototype.slice.call(tb.rows), num = th.getAttribute('data-sort') === 'num';
				dir = -dir;
				rows.sort(function (a, b) {
					var x = a.cells[col].getAttribute('data-v') || a.cells[col].textContent, y = b.cells[col].getAttribute('data-v') || b.cells[col].textContent;
					return num ? dir * ((parseFloat(x) || 0) - (parseFloat(y) || 0)) : dir * x.localeCompare(y);
				});
				rows.forEach(function (r) { tb.appendChild(r); });
				ths.forEach(function (o) { o.removeAttribute('aria-sort'); });
				th.setAttribute('aria-sort', dir > 0 ? 'ascending' : 'descending');
			}
			th.addEventListener('click', go);
			th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
		});
	}

	function indexPage() {
		document.querySelectorAll('table.sortable').forEach(sortable);
		var f = document.getElementById('ptCtyFilter'), tbl = document.getElementById('ptCtyTable');
		if (f && tbl) {
			f.addEventListener('input', function () {
				var q = f.value.trim().toLowerCase();
				Array.prototype.forEach.call(tbl.tBodies[0].rows, function (r) { r.style.display = !q || r.getAttribute('data-name').indexOf(q) !== -1 ? '' : 'none'; });
			});
		}
		var node = document.getElementById('ptCountryIndexData');
		if (node) {
			var D = JSON.parse(node.textContent || '{}');
			mount(document.getElementById('ptCtyScatter'), function (host) { scatter(host, D.scatter || []); });
		}
	}

	/* ── foreign aid page ───────────────────────────────────────────────── */
	function aidPage() {
		var node = document.getElementById('ptAidData');
		if (!node) { return; }
		var D = JSON.parse(node.textContent || '{}'), t = theme(), tot = D.totals || [];
		var cad = function (d) { return moneyM(d, 'C$'); };
		var mode = 'cad';
		function drawTotal(host) {
			if (mode === 'gni') {
				var pct = function (d) { return d3.format('.2f')(d) + '%'; };
				// Lift the y-axis to show the 0.7% target even when aid sits well below it.
				var series = [
					{ name: 'Net ODA / GNI', color: t.accent, values: tot.map(function (r) { return [r[0], r[3]]; }) },
					{ name: 'Grant-equivalent (official, 2018+)', color: t.warn, values: tot.filter(function (r) { return r[4] !== null; }).map(function (r) { return [r[0], r[4]]; }) },
					{ name: '', color: 'transparent', values: [[tot[0][0], 0.75]] }
				];
				lineChart(host, series, { yfmt: pct, tipfmt: pct, hlines: [{ y: 0.7, label: 'UN target 0.7%' }] });
			} else if (mode === 'real') {
				lineChart(host, [{ name: 'Net ODA, ' + D.baseYear + ' US$', color: t.pos, values: tot.map(function (r) { return [r[0], r[2]]; }) }],
					{ yfmt: function (d) { return moneyM(d, '$'); }, tipfmt: function (d) { return 'US$' + d3.format(',.0f')(d) + 'M'; } });
			} else {
				lineChart(host, [{ name: 'Net ODA, current C$', color: t.accent, values: tot.map(function (r) { return [r[0], r[1]]; }) }],
					{ yfmt: cad, tipfmt: function (d) { return 'C$' + d3.format(',.0f')(d) + 'M'; } });
			}
		}
		var host = document.getElementById('ptAidTotal');
		mount(host, drawTotal);
		document.querySelectorAll('[data-pt-aid]').forEach(function (b) {
			b.addEventListener('click', function () {
				mode = b.getAttribute('data-pt-aid');
				document.querySelectorAll('[data-pt-aid]').forEach(function (o) { o.classList.toggle('on', o === b); });
				drawTotal(host);
			});
		});
		mount(document.getElementById('ptAidChannels'), function (h) {
			lineChart(h, [
				{ name: 'Bilateral', color: t.accent, values: tot.filter(function (r) { return r[5] !== null; }).map(function (r) { return [r[0], r[5]]; }) },
				{ name: 'Multilateral', color: t.warn, values: tot.filter(function (r) { return r[6] !== null; }).map(function (r) { return [r[0], r[6]]; }) }
			], { yfmt: cad, tipfmt: function (d) { return 'C$' + d3.format(',.0f')(d) + 'M'; } });
		});
		mount(document.getElementById('ptAidRefugees'), function (h) {
			lineChart(h, [{ name: 'Refugee costs in Canada counted as aid', color: t.neg, values: (D.refugees || []).filter(function (r) { return r[1] !== null; }).map(function (r) { return [r[0], r[1]]; }) }],
				{ yfmt: cad, tipfmt: function (d) { return 'C$' + d3.format(',.0f')(d) + 'M'; } });
		});
		var regs = D.regions || {}, cols = [t.accent, t.warn, t.pos, t.neg, t.ink2];
		mount(document.getElementById('ptAidRegions'), function (h) {
			lineChart(h, Object.keys(regs).map(function (k, i) { return { name: k, color: cols[i % cols.length], values: regs[k] }; }),
				{ yfmt: function (d) { return moneyM(d, '$'); }, tipfmt: function (d) { return 'US$' + d3.format(',.0f')(d) + 'M'; } });
		});
		mount(document.getElementById('ptAidCadDept'), function (h) {
			var fy = function (r) { return [parseInt(r[0], 10), r[1] / 1e6]; };
			var depts = D.cadDepts || {}, dcols = [t.warn, t.pos, t.neg, t.ink2, '#9b7bff', '#ff9bd2'];
			var series = [{ name: 'All departments', color: t.accent, values: (D.cadTotals || []).map(fy) }];
			Object.keys(depts).forEach(function (k, i) {
				series.push({ name: k.replace(/^Immigration, Refugees and Citizenship Canada$/, 'IRCC').replace(/^Department of /, '').slice(0, 30),
					color: dcols[i % dcols.length], values: depts[k].map(fy) });
			});
			lineChart(h, series, { yfmt: function (d) { return moneyM(d, 'C$'); }, tipfmt: function (d) { return 'C$' + d3.format(',.0f')(d) + 'M'; } });
		});
		mount(document.getElementById('ptAidGac'), function (h) {
			barChart(h, (D.gacYears || []).map(function (r) { return [r[0] + '-' + String(r[0] + 1).slice(2), r[2] / 1e6]; }),
				{ yfmt: function (d) { return moneyM(d, 'C$'); } });
		});
	}

	function boot() { countryPage(); indexPage(); aidPage(); }
	if (document.readyState === 'complete') { boot(); } else { window.addEventListener('load', boot); }
})();
