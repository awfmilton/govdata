/**
 * Shared capture/signing routines for the PublicTech chart-snapshot tools.
 *
 * Both entry points (capture.mjs one-shot CLI, server.mjs webhook service)
 * drive the same flow: open the chart's raw render surface
 * (?pt_raw_render=1), wait for the bridge's deterministic ready signal
 * ([data-pt-rendered="1"], set by Billboard's onrendered with transitions
 * frozen), then screenshot the 1200x630 viewport.
 */
import { createHmac } from 'node:crypto';

export const READY_SELECTOR = '.pt-billboard-mount[data-pt-rendered="1"]';
export const ERROR_SELECTOR = '.pt-billboard-mount[data-pt-render-error="1"]';

export const DEFAULTS = Object.freeze({
	width: 1200,
	height: 630,
	scale: 1, // deviceScaleFactor; 2 doubles the pixel density (2400x1260)
	timeout: 20000, // ms budget for navigation and for the ready signal
	settle: 400, // ms grace after the ready signal for font/SVG paint
});

/**
 * Launch options for Puppeteer. Sandboxed by default; containers without a
 * usable user namespace can set PT_SNAPSHOT_NO_SANDBOX=1 (prefer fixing the
 * runtime instead). Extra flags go in PT_SNAPSHOT_BROWSER_ARGS.
 */
export function browserLaunchOptions(env = process.env) {
	const args = ['--hide-scrollbars', '--force-color-profile=srgb'];
	if (env.PT_SNAPSHOT_NO_SANDBOX === '1') {
		args.push('--no-sandbox', '--disable-setuid-sandbox');
	}
	if (env.PT_SNAPSHOT_BROWSER_ARGS) {
		args.push(...env.PT_SNAPSHOT_BROWSER_ARGS.split(/\s+/).filter(Boolean));
	}
	return { headless: true, args };
}

/**
 * Render one chart page and return the PNG bytes.
 *
 * @param {import('puppeteer').Browser} browser Shared browser instance.
 * @param {object} options { url, width, height, scale, timeout, settle }
 * @returns {Promise<Uint8Array>} PNG screenshot of the viewport.
 */
export async function captureChart(browser, options) {
	const opts = { ...DEFAULTS, ...options };
	if (!/^https?:\/\//i.test(String(opts.url))) {
		throw new Error(`refusing non-http(s) render URL: ${opts.url}`);
	}
	const page = await browser.newPage();
	try {
		await page.setViewport({
			width: opts.width,
			height: opts.height,
			deviceScaleFactor: opts.scale,
		});
		await page.goto(opts.url, { waitUntil: 'domcontentloaded', timeout: opts.timeout });
		await page.waitForSelector(`${READY_SELECTOR}, ${ERROR_SELECTOR}`, { timeout: opts.timeout });
		if (await page.$(ERROR_SELECTOR)) {
			throw new Error(`chart hydration failed on ${opts.url}`);
		}
		// Web fonts can land after the SVG does; don't ship fallback glyphs.
		await page.evaluate(() => (document.fonts ? document.fonts.ready : null));
		await new Promise((resolve) => setTimeout(resolve, opts.settle));
		return await page.screenshot({ type: 'png' });
	} finally {
		await page.close().catch(() => {});
	}
}

/**
 * HMAC-SHA256 over newline-joined fields — must stay byte-identical to
 * Chart_Snapshots::sign() on the PHP side.
 *
 * @param {Array<string|number>} fields Ordered fields to bind.
 * @param {string} secret Shared secret.
 */
export function signatureFor(fields, secret) {
	return createHmac('sha256', secret).update(fields.map(String).join('\n')).digest('hex');
}
