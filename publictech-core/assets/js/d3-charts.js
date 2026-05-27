/* PublicTech D3 chart wrappers. Each public function takes a host element id
   (or selector) plus a data payload. All charts subscribe to "pt:theme" so a
   customizer change re-renders without losing state. Charts also re-render on
   resize via a single shared ResizeObserver to avoid leak-prone listeners. */
(function (global) {
	'use strict';

	const PT = global.PT = global.PT || {};
	const d3 = global.d3;
	if (!d3) return;

	const charts = new Map(); // hostEl -> { kind, data, opts }
	const ro = new ResizeObserver(function (entries) {
		entries.forEach(function (e) {
			const rec = charts.get(e.target);
			if (rec) draw(e.target, rec);
		});
	});

	function tipShow(html, ev) {
		let t = document.getElementById('pt-ttip');
		if (!t) {
			t = document.createElement('div');
			t.id = 'pt-ttip';
			t.className = 'ttip';
			document.body.appendChild(t);
		}
		t.innerHTML = html;
		t.style.opacity = 1;
		tipMove(ev);
	}
	function tipMove(ev) {
		const t = document.getElementById('pt-ttip'); if (!t) return;
		const r = t.getBoundingClientRect();
		let x = ev.clientX + 14, y = ev.clientY + 14;
		if (x + r.width > innerWidth) x = ev.clientX - r.width - 14;
		t.style.left = x + 'px'; t.style.top = y + 'px';
	}
	function tipHide() {
		const t = document.getElementById('pt-ttip'); if (t) t.style.opacity = 0;
	}

	function clear(host) {
		while (host.firstChild) host.removeChild(host.firstChild);
	}

	function svg(host) {
		const W = host.clientWidth, H = host.clientHeight;
		if (!W || !H) return null;
		return { W, H, sel: d3.select(host).append('svg').attr('viewBox', '0 0 ' + W + ' ' + H) };
	}

	function drawLine(host, rec) {
		const t = PT.chartTheme; const s = svg(host); if (!s) return;
		const { W, H, sel } = s;
		const m = { t: 14, r: 14, b: 30, l: 48 };
		const series = rec.data;
		const allPoints = series.flatMap(function (g) { return g.values; });
		const x = d3.scaleLinear()
			.domain(d3.extent(allPoints, function (p) { return +p.x; }))
			.range([m.l, W - m.r]);
		const y = d3.scaleLinear()
			.domain([0, d3.max(allPoints, function (p) { return +p.y; }) * 1.1 || 1])
			.range([H - m.b, m.t]).nice();

		if (t.grid !== 'off') {
			sel.append('g').attr('class', 'grid').attr('transform', 'translate(' + m.l + ',0)')
				.call(d3.axisLeft(y).ticks(5).tickSize(-(W - m.l - m.r)).tickFormat(''))
				.selectAll('line').attr('stroke', t.line).attr('stroke-dasharray', PT.gridDash()).attr('opacity', PT.gridOpacity());
		}
		[ ['bottom', d3.axisBottom(x).ticks(7).tickFormat(d3.format('d')), 'translate(0,' + (H - m.b) + ')'],
		  ['left',   d3.axisLeft(y).ticks(5).tickFormat(function (d) { return '$' + d3.format('.2s')(d); }), 'translate(' + m.l + ',0)'] ]
			.forEach(function (it) {
				const g = sel.append('g').attr('class', 'axis').attr('transform', it[2]).call(it[1]);
				g.selectAll('text').attr('fill', t.ink2);
				g.selectAll('path,line').attr('stroke', t.line);
			});

		const line = d3.line().x(function (d) { return x(+d.x); }).y(function (d) { return y(+d.y); }).curve(PT.curveFn());
		const colors = [t.accent, t.warn, t.pos, t.ink1, t.neg];

		if (t.area > 0) {
			const defs = sel.append('defs');
			const grad = defs.append('linearGradient').attr('id', 'ptArea-' + Math.random().toString(36).slice(2, 7))
				.attr('x1', 0).attr('x2', 0).attr('y1', 0).attr('y2', 1);
			grad.append('stop').attr('offset', '0%').attr('stop-color', t.accent).attr('stop-opacity', t.area);
			grad.append('stop').attr('offset', '100%').attr('stop-color', t.accent).attr('stop-opacity', 0);
			const area = d3.area().x(function (d) { return x(+d.x); }).y0(H - m.b).y1(function (d) { return y(+d.y); }).curve(PT.curveFn());
			sel.append('path').datum(series[0].values).attr('fill', 'url(#' + grad.attr('id') + ')').attr('d', area);
		}

		series.forEach(function (g, i) {
			const col = colors[i % colors.length];
			const p = sel.append('path').datum(g.values).attr('fill', 'none')
				.attr('stroke', col).attr('stroke-width', t.stroke).attr('stroke-linecap', 'round')
				.attr('stroke-linejoin', 'round').attr('d', line);
			if (t.anim) {
				const len = p.node().getTotalLength();
				p.attr('stroke-dasharray', len).attr('stroke-dashoffset', len)
					.transition().duration(700).delay(i * 80).attr('stroke-dashoffset', 0);
			}
			if (t.marker > 0) {
				sel.selectAll('.dot-' + i).data(g.values).enter().append('circle')
					.attr('cx', function (d) { return x(+d.x); }).attr('cy', function (d) { return y(+d.y); })
					.attr('r', t.marker).attr('fill', t.bg0).attr('stroke', col).attr('stroke-width', 1.5)
					.on('mousemove', function (e, d) { tipShow('<div class="t-k">' + g.key + ' · ' + d.x + '</div><div class="t-row"><span>Value</span><b>' + d.y + '</b></div>', e); })
					.on('mouseleave', tipHide);
			}
		});
	}

	function drawBars(host, rec) {
		const t = PT.chartTheme; const s = svg(host); if (!s) return;
		const { W, H, sel } = s;
		const m = { t: 14, r: 14, b: 50, l: 50 };
		const data = rec.data;
		const x = d3.scaleBand().domain(data.map(function (d) { return d.label; })).range([m.l, W - m.r]).padding(0.3);
		const y = d3.scaleLinear().domain([0, d3.max(data, function (d) { return +d.value; }) * 1.1 || 1]).range([H - m.b, m.t]);

		if (t.grid !== 'off') {
			sel.append('g').attr('class', 'grid').attr('transform', 'translate(' + m.l + ',0)')
				.call(d3.axisLeft(y).ticks(5).tickSize(-(W - m.l - m.r)).tickFormat(''))
				.selectAll('line').attr('stroke', t.line).attr('stroke-dasharray', PT.gridDash()).attr('opacity', PT.gridOpacity());
		}
		const ax = sel.append('g').attr('class', 'axis').attr('transform', 'translate(0,' + (H - m.b) + ')').call(d3.axisBottom(x));
		ax.selectAll('text').attr('fill', t.ink2).attr('transform', 'rotate(-28)').attr('text-anchor', 'end').attr('dx', '-4').attr('dy', '6');
		sel.append('g').attr('class', 'axis').attr('transform', 'translate(' + m.l + ',0)')
			.call(d3.axisLeft(y).ticks(5).tickFormat(function (d) { return '$' + d3.format('.2s')(d); }))
			.selectAll('text').attr('fill', t.ink2);

		let fill = t.accent, stroke = 'none';
		if (t.bar === 'outline') { fill = 'none'; stroke = t.accent; }
		if (t.bar === 'stripe') {
			const defs = sel.append('defs');
			const p = defs.append('pattern').attr('id', 'ptStripe').attr('width', 6).attr('height', 6)
				.attr('patternUnits', 'userSpaceOnUse').attr('patternTransform', 'rotate(45)');
			p.append('rect').attr('width', 6).attr('height', 6).attr('fill', t.accent).attr('opacity', 0.35);
			p.append('rect').attr('width', 3).attr('height', 6).attr('fill', t.accent);
			fill = 'url(#ptStripe)';
		}

		sel.selectAll('rect.b').data(data).enter().append('rect').attr('class', 'b')
			.attr('x', function (d) { return x(d.label); })
			.attr('width', x.bandwidth())
			.attr('y', t.anim ? H - m.b : function (d) { return y(d.value); })
			.attr('height', t.anim ? 0 : function (d) { return H - m.b - y(d.value); })
			.attr('fill', fill).attr('stroke', stroke).attr('stroke-width', 1.5)
			.on('mousemove', function (e, d) { tipShow('<div class="t-k">' + d.label + '</div><div class="t-row"><span>Value</span><b>' + d.value + '</b></div>', e); })
			.on('mouseleave', tipHide)
			.transition().duration(t.anim ? 500 : 0)
				.attr('y', function (d) { return y(d.value); })
				.attr('height', function (d) { return H - m.b - y(d.value); });
	}

	function drawNetwork(host, rec) {
		const t = PT.chartTheme; const s = svg(host); if (!s) return;
		const { W, H, sel } = s;
		const { nodes, links } = rec.data;
		const sim = d3.forceSimulation(nodes)
			.force('link', d3.forceLink(links).id(function (d) { return d.id; }).distance(function (d) { return d.target && d.target.type === 'cat' ? 70 : 50; }).strength(0.4))
			.force('charge', d3.forceManyBody().strength(-120))
			.force('center', d3.forceCenter(W / 2, H / 2))
			.force('collide', d3.forceCollide().radius(function (d) { return (d.r || 6) + 6; }));

		const link = sel.append('g').attr('stroke', t.line2).attr('stroke-opacity', 0.6)
			.selectAll('line').data(links).enter().append('line').attr('stroke-width', function (d) { return d.w || 1; });
		const node = sel.append('g').selectAll('g').data(nodes).enter().append('g')
			.style('cursor', 'pointer')
			.on('mousemove', function (e, d) { tipShow('<div class="t-k">' + (d.type === 'cat' ? 'CATEGORY' : 'VENDOR') + '</div><div class="t-row"><span>' + d.id + '</span></div>', e); })
			.on('mouseleave', tipHide);
		node.append('circle')
			.attr('r', function (d) { return d.r || 6; })
			.attr('fill', function (d) { return d.type === 'cat' ? t.accent : t.bg1; })
			.attr('stroke', function (d) { return d.type === 'cat' ? t.accent : t.line2; })
			.attr('stroke-width', function (d) { return d.type === 'cat' ? 0 : 1; });

		sim.on('tick', function () {
			link.attr('x1', function (d) { return d.source.x; })
				.attr('y1', function (d) { return d.source.y; })
				.attr('x2', function (d) { return d.target.x; })
				.attr('y2', function (d) { return d.target.y; });
			node.attr('transform', function (d) { return 'translate(' + d.x + ',' + d.y + ')'; });
		});
	}

	function drawSpark(host, rec) {
		const t = PT.chartTheme;
		clear(host);
		const W = host.clientWidth || 60, H = host.clientHeight || 34;
		const sel = d3.select(host).append('svg').attr('viewBox', '0 0 ' + W + ' ' + H);
		const data = rec.data;
		const x = d3.scaleLinear().domain([0, data.length - 1]).range([2, W - 2]);
		const y = d3.scaleLinear().domain(d3.extent(data)).range([H - 2, 2]);
		sel.append('path').attr('fill', 'none').attr('stroke', rec.opts.color || t.accent).attr('stroke-width', 1.5)
			.attr('d', d3.line().x(function (_, i) { return x(i); }).y(function (d) { return y(d); }).curve(d3.curveMonotoneX)(data));
	}

	const renderers = { line: drawLine, bar: drawBars, network: drawNetwork, spark: drawSpark };

	function draw(host, rec) {
		clear(host);
		const fn = renderers[rec.kind];
		if (fn) fn(host, rec);
	}

	PT.chart = function (target, kind, data, opts) {
		const host = typeof target === 'string' ? document.querySelector(target) : target;
		if (!host) return;
		const rec = { kind, data, opts: opts || {} };
		charts.set(host, rec);
		ro.observe(host);
		draw(host, rec);
	};

	PT.redrawAll = function () {
		charts.forEach(function (rec, host) { draw(host, rec); });
	};

	document.addEventListener('pt:theme', PT.redrawAll);
})(window);
