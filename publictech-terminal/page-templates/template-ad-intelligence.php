<?php
/**
 * Template Name: PT — Ad Intelligence (Landing)
 *
 * Public information page for GovData Ad Services. Explains how the
 * platform's government-data analysis engines power advertiser analytics.
 * Fully static — no JavaScript, renders from cache-friendly markup only.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

wp_enqueue_style(
	'govads-ad-services',
	get_stylesheet_directory_uri() . '/assets/css/ad-services.css',
	[],
	PUBLICTECH_TERMINAL_VERSION
);

$pt_cta_url   = is_user_logged_in() ? home_url( '/ad-services/' ) : home_url( '/advertiser-register/' );
$pt_cta_label = is_user_logged_in()
	? __( 'Open Your Dashboard', 'publictech-terminal' )
	: __( 'Start Advertising', 'publictech-terminal' );

$pt_engines = [
	[
		'◈',
		__( 'Daily Aggregation Pipeline', 'publictech-terminal' ),
		__( 'The rollup engine that condenses millions of government transactions into riding and entity reports aggregates your raw impressions and clicks into per-ad, per-zone, per-site, per-day statistics — every night, automatically.', 'publictech-terminal' ),
	],
	[
		'◎',
		__( 'Trend & CTR Analytics', 'publictech-terminal' ),
		__( 'The same charting stack that visualises federal spending curves renders your campaign trends: daily activity tables, click-through rates, and per-ad sparklines, sliceable from 7 to 90 days.', 'publictech-terminal' ),
	],
	[
		'⚙',
		__( 'Signal Integrity Screening', 'publictech-terminal' ),
		__( 'Techniques we use to keep audit data trustworthy keep your numbers honest: every tracking event is HMAC-signed at serve time, deduplicated per session, and rate-limited per IP. Fabricated impressions never reach your reports.', 'publictech-terminal' ),
	],
	[
		'◐',
		__( 'Privacy-First Measurement', 'publictech-terminal' ),
		__( 'Built for a platform that takes data governance seriously: IP addresses are anonymised before storage, sessions live in a first-party cookie, and no third-party trackers are ever loaded. PIPEDA-aligned by design.', 'publictech-terminal' ),
	],
];

$pt_parity = [
	[ __( 'Campaigns & flight scheduling', 'publictech-terminal' ), __( 'Group ads into campaigns with start and end dates; scheduling is enforced at serve time.', 'publictech-terminal' ) ],
	[ __( 'Standard IAB creative sizes', 'publictech-terminal' ), __( '9 industry-standard formats, from 300×250 medium rectangles to 970×90 leaderboards.', 'publictech-terminal' ) ],
	[ __( 'Weighted rotation', 'publictech-terminal' ), __( 'Per-ad weights control how often each creative wins an auction within its zone.', 'publictech-terminal' ) ],
	[ __( 'Viewable impressions (IAB standard)', 'publictech-terminal' ), __( 'An impression counts only after 50% of the ad is on screen for a full second — the same standard the major ad platforms use.', 'publictech-terminal' ) ],
	[ __( 'Frequency hygiene', 'publictech-terminal' ), __( 'Impressions are deduplicated per visitor session, so one reader reloading a page doesn\'t inflate your spend metrics.', 'publictech-terminal' ) ],
	[ __( 'Verified click tracking', 'publictech-terminal' ), __( 'Clicks carry a cryptographic token issued at serve time; double-clicks and replays are absorbed automatically.', 'publictech-terminal' ) ],
	[ __( 'Publisher network', 'publictech-terminal' ), __( 'Serve beyond GovData: partner WordPress sites join the network with a one-minute plugin install, and your reports break performance out per site.', 'publictech-terminal' ) ],
	[ __( 'Self-serve everything', 'publictech-terminal' ), __( 'Create, schedule, pause, resume, and measure your ads from one dashboard — no sales calls required.', 'publictech-terminal' ) ],
	[ __( 'Human review', 'publictech-terminal' ), __( 'Every creative is approved by a moderator before it serves, protecting the network and your brand context.', 'publictech-terminal' ) ],
];

get_header(); ?>

<main id="pt-main" tabindex="-1">
<section class="view active" data-pt-view="ad-intelligence">

	<!-- ── HERO ──────────────────────────────────────────── -->
	<div class="hero corner" style="margin-bottom:18px">
		<div class="hero-grid">
			<div>
				<div class="mono uc" style="color:var(--accent);font-size:10px;letter-spacing:0.2em;margin-bottom:10px">◇ <?php esc_html_e( 'GovData Ad Intelligence', 'publictech-terminal' ); ?></div>
				<h2><?php esc_html_e( 'Your ads, analysed like government spending.', 'publictech-terminal' ); ?></h2>
				<p class="lede"><?php esc_html_e( 'GovData built revolutionary data analysis engines to audit billions of dollars in public spending. Advertise with us and those same engines go to work on your campaigns — every impression verified, every click measured, every trend visible.', 'publictech-terminal' ); ?></p>
				<div class="ctas">
					<a class="cta" href="<?php echo esc_url( $pt_cta_url ); ?>">▶ <?php echo esc_html( $pt_cta_label ); ?></a>
					<a class="cta ghost" href="<?php echo esc_url( home_url( '/advertise/' ) ); ?>">◈ <?php esc_html_e( 'Plans & Placements', 'publictech-terminal' ); ?></a>
				</div>
			</div>
			<div class="hero-stats">
				<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Ad Zones', 'publictech-terminal' ); ?></div><div class="val">9</div></div>
				<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Viewability Standard', 'publictech-terminal' ); ?></div><div class="val">IAB</div></div>
				<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Render Blocking', 'publictech-terminal' ); ?></div><div class="val">0 ms</div></div>
				<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Third-Party Trackers', 'publictech-terminal' ); ?></div><div class="val">0</div></div>
			</div>
		</div>
	</div>

	<!-- ── ENGINES ───────────────────────────────────────── -->
	<div style="margin-bottom:8px">
		<div class="mono uc" style="color:var(--accent);font-size:10px;letter-spacing:0.2em;margin-bottom:6px;text-align:center">◇ <?php esc_html_e( 'The Engine Room', 'publictech-terminal' ); ?></div>
		<h2 style="text-align:center;font-size:22px;margin:0 0 4px;color:var(--ink-0)"><?php esc_html_e( 'Built to audit governments. Pointed at your ads.', 'publictech-terminal' ); ?></h2>
		<p style="text-align:center;color:var(--ink-2);font-size:13px;margin:0 0 20px;max-width:680px;margin-left:auto;margin-right:auto">
			<?php esc_html_e( 'The pipelines behind our riding reports, entity funding audits, and anomaly detection don\'t take nights off — and now they run your campaign analytics too.', 'publictech-terminal' ); ?>
		</p>
	</div>

	<div class="kpis" style="margin-bottom:18px">
		<?php foreach ( $pt_engines as $pt_engine ) : ?>
			<div class="panel corner"><div class="bd">
				<div class="mono" style="color:var(--accent);font-size:18px;margin-bottom:6px"><?php echo esc_html( $pt_engine[0] ); ?></div>
				<div style="font-weight:600;color:var(--ink-0);margin-bottom:4px"><?php echo esc_html( $pt_engine[1] ); ?></div>
				<div style="font-size:12px;color:var(--ink-2);line-height:1.5"><?php echo esc_html( $pt_engine[2] ); ?></div>
			</div></div>
		<?php endforeach; ?>
	</div>

	<!-- ── FEATURE PARITY ────────────────────────────────── -->
	<div class="panel corner" style="margin-bottom:18px">
		<div class="hd"><h3>◇ <?php esc_html_e( 'Everything you expect from a major ad platform', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'FEATURE SET', 'publictech-terminal' ); ?></span></div>
		<div class="bd">
			<table class="ads-table">
				<thead><tr>
					<th style="width:240px"><?php esc_html_e( 'Capability', 'publictech-terminal' ); ?></th>
					<th><?php esc_html_e( 'How it works here', 'publictech-terminal' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $pt_parity as $pt_row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $pt_row[0] ); ?></strong></td>
							<td style="color:var(--ink-2)"><?php echo esc_html( $pt_row[1] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- ── PERFORMANCE ───────────────────────────────────── -->
	<div class="panel corner" style="margin-bottom:18px">
		<div class="hd"><h3>◇ <?php esc_html_e( 'Engineered so the site never slows down', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'PERFORMANCE', 'publictech-terminal' ); ?></span></div>
		<div class="bd">
			<div class="kpis">
				<div class="kpi corner">
					<div class="lab"><?php esc_html_e( 'Loader Strategy', 'publictech-terminal' ); ?></div>
					<div class="val" style="font-size:15px"><?php esc_html_e( 'Deferred', 'publictech-terminal' ); ?></div>
					<div style="font-size:11px;color:var(--ink-2);margin-top:4px"><?php esc_html_e( 'A ~3 KB script runs after the page paints. Ads never block rendering.', 'publictech-terminal' ); ?></div>
				</div>
				<div class="kpi corner">
					<div class="lab"><?php esc_html_e( 'Layout Shift', 'publictech-terminal' ); ?></div>
					<div class="val" style="font-size:15px"><?php esc_html_e( 'Zero', 'publictech-terminal' ); ?></div>
					<div style="font-size:11px;color:var(--ink-2);margin-top:4px"><?php esc_html_e( 'Every slot reserves its exact dimensions before the creative arrives.', 'publictech-terminal' ); ?></div>
				</div>
				<div class="kpi corner">
					<div class="lab"><?php esc_html_e( 'Slot Filling', 'publictech-terminal' ); ?></div>
					<div class="val" style="font-size:15px"><?php esc_html_e( 'Lazy', 'publictech-terminal' ); ?></div>
					<div style="font-size:11px;color:var(--ink-2);margin-top:4px"><?php esc_html_e( 'Creatives load only as their slot approaches the viewport.', 'publictech-terminal' ); ?></div>
				</div>
				<div class="kpi corner">
					<div class="lab"><?php esc_html_e( 'Ad Decisions', 'publictech-terminal' ); ?></div>
					<div class="val" style="font-size:15px">&lt; 60 s</div>
					<div style="font-size:11px;color:var(--ink-2);margin-top:4px"><?php esc_html_e( 'The serving pool is cached and refreshed every minute — fast responses, fresh rotations.', 'publictech-terminal' ); ?></div>
				</div>
			</div>
		</div>
	</div>

	<!-- ── HOW IT WORKS ──────────────────────────────────── -->
	<div class="panel corner" style="margin-bottom:18px">
		<div class="hd"><h3>◇ <?php esc_html_e( 'Live in three steps', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'SELF-SERVE', 'publictech-terminal' ); ?></span></div>
		<div class="bd">
			<ol style="margin:0;padding-left:22px;color:var(--ink-1);font-size:13px;line-height:2">
				<li><?php esc_html_e( 'Create your advertiser account — it takes under a minute.', 'publictech-terminal' ); ?></li>
				<li><?php esc_html_e( 'Submit your creative: pick an IAB size, set your destination URL, optionally schedule flight dates. Our team reviews it, usually within one business day.', 'publictech-terminal' ); ?></li>
				<li><?php esc_html_e( 'Watch the analytics roll in: impressions, clicks, CTR, per-zone and per-site breakdowns, and daily trends — refreshed by the same engines that power GovData\'s public reports.', 'publictech-terminal' ); ?></li>
			</ol>
		</div>
	</div>

	<!-- ── FINAL CTA ─────────────────────────────────────── -->
	<div class="panel corner" style="text-align:center;margin-bottom:8px">
		<div class="bd" style="padding:32px">
			<h2 style="margin:0 0 8px;color:var(--ink-0);font-size:20px"><?php esc_html_e( 'Put the audit engines to work for your brand.', 'publictech-terminal' ); ?></h2>
			<p style="color:var(--ink-2);font-size:13px;margin:0 0 18px"><?php esc_html_e( 'Transparent placements, verified measurement, and analytics you can actually trust.', 'publictech-terminal' ); ?></p>
			<div class="ctas" style="justify-content:center">
				<a class="cta" href="<?php echo esc_url( $pt_cta_url ); ?>">▶ <?php echo esc_html( $pt_cta_label ); ?></a>
				<a class="cta ghost" href="<?php echo esc_url( home_url( '/advertise/' ) ); ?>">◈ <?php esc_html_e( 'View Pricing', 'publictech-terminal' ); ?></a>
			</div>
		</div>
	</div>

</section>
</main>

<?php get_footer(); ?>
