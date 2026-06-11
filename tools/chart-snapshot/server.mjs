#!/usr/bin/env node
/**
 * Chart-snapshot webhook service — the "webhook mode" backend behind
 * PUBLICTECH_SNAPSHOT_WEBHOOK. Plain node:http + Puppeteer, no framework.
 *
 *   POST /render   signed job from WP ({ chart_id, filename, render_url,
 *                  ingest_url, width, height, issued_at, signature });
 *                  responds 202 and captures asynchronously.
 *   GET  /healthz  liveness + queue depth.
 *
 * Delivery: POSTs the PNG back to the WP ingest route with HMAC headers, or
 * writes straight into PT_SNAPSHOT_OUTPUT_DIR when co-located with WP.
 *
 * Environment:
 *   PT_SNAPSHOT_SECRET           required — must equal PUBLICTECH_SNAPSHOT_SECRET
 *   PT_SNAPSHOT_ALLOWED_ORIGINS  required — comma list of origins the service
 *                                may browse to / deliver to, e.g.
 *                                "https://example.org" (SSRF guard)
 *   PT_SNAPSHOT_HOST / PORT      bind address, default 127.0.0.1:8787
 *   PT_SNAPSHOT_OUTPUT_DIR       co-located mode: write PNGs here instead of
 *                                POSTing to ingest_url
 *   PT_SNAPSHOT_SCALE            deviceScaleFactor (default 1)
 *   PT_SNAPSHOT_SETTLE / TIMEOUT capture tuning (ms)
 *   PT_SNAPSHOT_MAX_QUEUE        pending-job cap (default 20)
 *   PT_SNAPSHOT_NO_SANDBOX=1     for containers without userns (see README)
 *   PT_SNAPSHOT_BROWSER_ARGS     extra Chromium flags
 */
import { createHash } from 'node:crypto';
import { createServer } from 'node:http';
import { mkdir, rename, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import puppeteer from 'puppeteer';
import { browserLaunchOptions, captureChart, DEFAULTS, signatureFor } from './lib.mjs';

const SECRET = process.env.PT_SNAPSHOT_SECRET || '';
const ORIGINS = (process.env.PT_SNAPSHOT_ALLOWED_ORIGINS || '')
	.split(',')
	.map((s) => s.trim())
	.filter(Boolean);
if (!SECRET || ORIGINS.length === 0) {
	console.error('PT_SNAPSHOT_SECRET and PT_SNAPSHOT_ALLOWED_ORIGINS are required.');
	process.exit(2);
}

const HOST = process.env.PT_SNAPSHOT_HOST || '127.0.0.1';
const PORT = parseInt(process.env.PT_SNAPSHOT_PORT || '8787', 10);
const OUTPUT_DIR = process.env.PT_SNAPSHOT_OUTPUT_DIR || '';
const MAX_QUEUE = parseInt(process.env.PT_SNAPSHOT_MAX_QUEUE || '20', 10);
const SCALE = parseInt(process.env.PT_SNAPSHOT_SCALE || String(DEFAULTS.scale), 10) || DEFAULTS.scale;
const SETTLE = parseInt(process.env.PT_SNAPSHOT_SETTLE || String(DEFAULTS.settle), 10) || DEFAULTS.settle;
const TIMEOUT = parseInt(process.env.PT_SNAPSHOT_TIMEOUT || String(DEFAULTS.timeout), 10) || DEFAULTS.timeout;
const SIGNATURE_WINDOW = 600; // seconds, mirrors Chart_Snapshots::SIGNATURE_WINDOW
const MAX_JOB_BYTES = 65536;

/* ---------- job intake ---------- */

function originAllowed(url) {
	try {
		return ORIGINS.includes(new URL(url).origin);
	} catch {
		return false;
	}
}

/** Validate a posted job; returns an error string or null when acceptable. */
function jobError(job) {
	const id = Number(job.chart_id);
	if (!Number.isInteger(id) || id <= 0) return 'bad chart_id';
	if (typeof job.filename !== 'string' || !new RegExp(`^chart-${id}-[a-f0-9]{10}\\.png$`).test(job.filename)) {
		return 'bad filename';
	}
	const issued = Number(job.issued_at);
	if (!Number.isInteger(issued) || Math.abs(Date.now() / 1000 - issued) > SIGNATURE_WINDOW) {
		return 'stale issued_at';
	}
	if (!originAllowed(job.render_url)) return 'render_url origin not allowed';
	if (!OUTPUT_DIR && !originAllowed(job.ingest_url)) return 'ingest_url origin not allowed';
	const expected = signatureFor(
		[id, job.filename, job.render_url, job.ingest_url, issued],
		SECRET
	);
	// Same length (hex sha256) ⇒ a plain comparison would leak nothing useful
	// over HTTP timing here, but stay disciplined anyway.
	if (typeof job.signature !== 'string' || expected.length !== job.signature.length) return 'bad signature';
	let diff = 0;
	for (let i = 0; i < expected.length; i++) diff |= expected.charCodeAt(i) ^ job.signature.charCodeAt(i);
	if (diff !== 0) return 'bad signature';
	return null;
}

/* ---------- capture queue (concurrency 1, newest job per chart wins) ---------- */

const queue = [];
let working = false;
let browser = null;

async function getBrowser() {
	if (browser && browser.connected) return browser;
	browser = await puppeteer.launch(browserLaunchOptions());
	return browser;
}

function enqueue(job) {
	const existing = queue.findIndex((j) => j.chart_id === job.chart_id);
	if (existing !== -1) {
		queue[existing] = job; // a newer rev supersedes the queued one
		return true;
	}
	if (queue.length >= MAX_QUEUE) return false;
	queue.push(job);
	return true;
}

async function deliver(job, png) {
	if (OUTPUT_DIR) {
		await mkdir(OUTPUT_DIR, { recursive: true });
		const dest = join(OUTPUT_DIR, job.filename);
		await writeFile(`${dest}.part`, png);
		await rename(`${dest}.part`, dest);
		return;
	}
	const ts = Math.floor(Date.now() / 1000);
	const bodyHash = createHash('sha256').update(png).digest('hex');
	const res = await fetch(job.ingest_url, {
		method: 'POST',
		headers: {
			'Content-Type': 'image/png',
			'X-PT-Snapshot-Filename': job.filename,
			'X-PT-Snapshot-Timestamp': String(ts),
			'X-PT-Snapshot-Signature': signatureFor([job.chart_id, job.filename, ts, bodyHash], SECRET),
		},
		body: png,
	});
	if (!res.ok) {
		const detail = await res.text().catch(() => '');
		throw new Error(`ingest rejected: HTTP ${res.status} ${detail.slice(0, 200)}`);
	}
}

async function work() {
	if (working) return;
	working = true;
	try {
		while (queue.length > 0) {
			const job = queue.shift();
			try {
				const png = await captureChart(await getBrowser(), {
					url: job.render_url,
					width: Number(job.width) || DEFAULTS.width,
					height: Number(job.height) || DEFAULTS.height,
					scale: SCALE,
					settle: SETTLE,
					timeout: TIMEOUT,
				});
				await deliver(job, png);
				console.log(`ok chart=${job.chart_id} file=${job.filename} bytes=${png.length}`);
			} catch (err) {
				console.error(`fail chart=${job.chart_id} file=${job.filename}: ${err.message}`);
				if (browser && !browser.connected) browser = null; // relaunch next job
			}
		}
	} finally {
		working = false;
	}
}

/* ---------- http ---------- */

function readBody(req, limit) {
	return new Promise((resolve, reject) => {
		const chunks = [];
		let size = 0;
		req.on('data', (chunk) => {
			size += chunk.length;
			if (size > limit) {
				reject(new Error('body too large'));
				req.destroy();
				return;
			}
			chunks.push(chunk);
		});
		req.on('end', () => resolve(Buffer.concat(chunks)));
		req.on('error', reject);
	});
}

function json(res, status, payload) {
	const body = JSON.stringify(payload);
	res.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) });
	res.end(body);
}

const server = createServer(async (req, res) => {
	if (req.method === 'GET' && req.url === '/healthz') {
		json(res, 200, { ok: true, queued: queue.length, working });
		return;
	}
	if (req.method !== 'POST' || req.url !== '/render') {
		json(res, 404, { ok: false, error: 'not found' });
		return;
	}
	let job;
	try {
		job = JSON.parse((await readBody(req, MAX_JOB_BYTES)).toString('utf8'));
	} catch (err) {
		json(res, 400, { ok: false, error: err.message });
		return;
	}
	const problem = jobError(job);
	if (problem) {
		// One generic line to the caller; the detail stays in our log.
		console.error(`reject chart=${job && job.chart_id}: ${problem}`);
		json(res, 403, { ok: false, error: 'rejected' });
		return;
	}
	job.chart_id = Number(job.chart_id);
	if (!enqueue(job)) {
		json(res, 429, { ok: false, error: 'queue full' });
		return;
	}
	json(res, 202, { ok: true, queued: queue.length });
	void work();
});

server.listen(PORT, HOST, () => {
	console.log(
		`pt-chart-snapshot listening on http://${HOST}:${PORT} ` +
		`(${OUTPUT_DIR ? `output-dir=${OUTPUT_DIR}` : 'delivery=ingest POST'})`
	);
});

for (const signal of ['SIGINT', 'SIGTERM']) {
	process.on(signal, async () => {
		server.close();
		if (browser) await browser.close().catch(() => {});
		process.exit(0);
	});
}
