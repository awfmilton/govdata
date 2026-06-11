#!/usr/bin/env node
/**
 * One-shot chart capture — the "local mode" backend that
 * Chart_Snapshots::dispatch_local() backgrounds from WP-Cron:
 *
 *   node capture.mjs \
 *     --url="https://example.org/chart/spending-by-region/?pt_raw_render=1" \
 *     --out=/var/www/wp-content/uploads/pt-charts/chart-42-9f2c01ab34.png
 *
 * Optional: --width=1200 --height=630 --scale=1 --timeout=20000 --settle=400
 * (--scale=2 renders the same 1.91:1 card at 2400x1260 for retina feeds.)
 *
 * The PNG is written atomically (.part then rename) so the web server never
 * serves a half-written file. Exit code 0 on success, 1 on capture failure,
 * 2 on bad usage.
 */
import { parseArgs } from 'node:util';
import { mkdir, rename, writeFile } from 'node:fs/promises';
import { dirname } from 'node:path';
import puppeteer from 'puppeteer';
import { browserLaunchOptions, captureChart, DEFAULTS } from './lib.mjs';

const { values: argv } = parseArgs({
	options: {
		url: { type: 'string' },
		out: { type: 'string' },
		width: { type: 'string' },
		height: { type: 'string' },
		scale: { type: 'string' },
		timeout: { type: 'string' },
		settle: { type: 'string' },
	},
});

if (!argv.url || !argv.out) {
	console.error(
		'usage: capture.mjs --url=<render url> --out=<file.png> [--width --height --scale --timeout --settle]'
	);
	process.exit(2);
}

const num = (value, fallback) => {
	const n = parseInt(String(value ?? ''), 10);
	return Number.isFinite(n) && n > 0 ? n : fallback;
};

const browser = await puppeteer.launch(browserLaunchOptions());
try {
	const png = await captureChart(browser, {
		url: argv.url,
		width: num(argv.width, DEFAULTS.width),
		height: num(argv.height, DEFAULTS.height),
		scale: num(argv.scale, DEFAULTS.scale),
		timeout: num(argv.timeout, DEFAULTS.timeout),
		settle: num(argv.settle, DEFAULTS.settle),
	});
	await mkdir(dirname(argv.out), { recursive: true });
	const part = `${argv.out}.part`;
	await writeFile(part, png);
	await rename(part, argv.out);
	console.log(`captured ${argv.url} -> ${argv.out} (${png.length} bytes)`);
} catch (err) {
	console.error(`capture failed: ${err.message}`);
	process.exitCode = 1;
} finally {
	await browser.close().catch(() => {});
}
