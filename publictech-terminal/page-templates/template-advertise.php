<?php
/**
 * Template Name: PT — Advertise (Pricing)
 *
 * Public marketing / pricing landing page for GovData Ad Services.
 * CTAs route to /advertiser-register (or /ad-services if already logged in).
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
	? __( 'Go to Your Dashboard', 'publictech-terminal' )
	: __( 'Start Advertising', 'publictech-terminal' );

// Audience stats for the hero (cached, cheap).
$pt_mp_total = wp_cache_get( 'pt_count_mp', 'publictech' );
if ( false === $pt_mp_total ) {
	$pt_mp_total = (int) ( wp_count_posts( 'member-parliament' )->publish ?? 0 );
	wp_cache_set( 'pt_count_mp', $pt_mp_total, 'publictech', 300 );
}

/*
 * Rate card. Filterable so pricing can be tuned without a code edit:
 *   add_filter( 'govads_rate_card', fn() => [ ... ] );
 */
$pt_plans = (array) apply_filters( 'govads_rate_card', [
	[
		'name'     => __( 'Starter', 'publictech-terminal' ),
		'price'    => '$199',
		'period'   => __( '/ month', 'publictech-terminal' ),
		'tagline'  => __( 'For small businesses testing the waters.', 'publictech-terminal' ),
		'featured' => false,
		'features' => [
			__( 'Up to 25,000 impressions / mo', 'publictech-terminal' ),
			__( '1 active ad', 'publictech-terminal' ),
			__( 'Footer & archive zones', 'publictech-terminal' ),
			__( 'Self-serve dashboard', 'publictech-terminal' ),
			__( 'Impression & click tracking', 'publictech-terminal' ),
		],
	],
	[
		'name'     => __( 'Growth', 'publictech-terminal' ),
		'price'    => '$549',
		'period'   => __( '/ month', 'publictech-terminal' ),
		'tagline'  => __( 'For brands that want premium placement.', 'publictech-terminal' ),
		'featured' => true,
		'features' => [
			__( 'Up to 100,000 impressions / mo', 'publictech-terminal' ),
			__( '5 active ads', 'publictech-terminal' ),
			__( 'All zones incl. MP profile sidebar', 'publictech-terminal' ),
			__( 'Campaign scheduling', 'publictech-terminal' ),
			__( 'Priority approval', 'publictech-terminal' ),
			__( 'Full performance analytics', 'publictech-terminal' ),
		],
	],
	[
		'name'     => __( 'Enterprise', 'publictech-terminal' ),
		'price'    => __( 'Custom', 'publictech-terminal' ),
		'period'   => '',
		'tagline'  => __( 'For agencies & high-volume campaigns.', 'publictech-terminal' ),
		'featured' => false,
		'features' => [
			__( 'Unlimited impressions', 'publictech-terminal' ),
			__( 'Unlimited ads & campaigns', 'publictech-terminal' ),
			__( 'Network-wide placement', 'publictech-terminal' ),
			__( 'Dedicated account manager', 'publictech-terminal' ),
			__( 'Custom reporting & API access', 'publictech-terminal' ),
		],
	],
] );

$pt_zones = [
	[ 'MP Profile Sidebar',      '300×250', __( 'Beside every Member of Parliament profile.', 'publictech-terminal' ) ],
	[ 'MP Profile Mid-Content',  '728×90',  __( 'Inside the MP profile, between data panels.', 'publictech-terminal' ) ],
	[ 'Riding Report Sidebar',   '300×250', __( 'On every federal riding spending report.', 'publictech-terminal' ) ],
	[ 'Entity Report Sidebar',   '300×250', __( 'On every organisation funding page.', 'publictech-terminal' ) ],
	[ 'Dashboard Leaderboard',   '970×90',  __( 'Top of the homepage, below the hero.', 'publictech-terminal' ) ],
	[ 'Directory Banner',        '728×90',  __( 'Across the MP directory search results.', 'publictech-terminal' ) ],
	[ 'Directory Sidebar',       '300×250', __( 'Beside the MP directory filters.', 'publictech-terminal' ) ],
	[ 'Directory Footer Banner', '728×90',  __( 'Below the directory, before pagination.', 'publictech-terminal' ) ],
	[ 'Global Footer',           '728×90',  __( 'On the footer of every page, site-wide.', 'publictech-terminal' ) ],
];

get_header(); ?>

<main id="pt-main" tabindex="-1">
<section class="view active" data-pt-view="advertise">

	<!-- ── HERO ──────────────────────────────────────────── -->
	<div class="hero corner" style="margin-bottom:18px">
		<div class="hero-grid">
			<div>
				<div class="mono uc" style="color:var(--accent);font-size:10px;letter-spacing:0.2em;margin-bottom:10px">◇ <?php esc_html_e( 'GovData Ad Services', 'publictech-terminal' ); ?></div>
				<h2><?php esc_html_e( 'Advertise where Canada watches its government.', 'publictech-terminal' ); ?></h2>
				<p class="lede"><?php esc_html_e( 'Reach journalists, researchers, policy professionals, and engaged citizens exploring federal spending, grants, and Members of Parliament. Self-serve, transparent, and measurable.', 'publictech-terminal' ); ?></p>
				<div class="ctas">
					<a class="cta" href="<?php echo esc_url( $pt_cta_url ); ?>">▶ <?php echo esc_html( $pt_cta_label ); ?></a>
					<a class="cta ghost" href="#ads-pricing">◈ <?php esc_html_e( 'View Pricing', 'publictech-terminal' ); ?></a>
				</div>
			</div>
			<div class="hero-stats">
				<div class="hero-stat"><div class="lab"><?php esc_html_e( 'MP Profiles', 'publictech-terminal' ); ?></div><div class="val"><?php echo esc_html( number_format_i18n( $pt_mp_total ) ); ?></div></div>
				<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Ad Zones', 'publictech-terminal' ); ?></div><div class="val">9</div></div>
				<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Audience', 'publictech-terminal' ); ?></div><div class="val"><?php esc_html_e( 'High-intent', 'publictech-terminal' ); ?></div></div>
				<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Setup', 'publictech-terminal' ); ?></div><div class="val">&lt; 1 min</div></div>
			</div>
		</div>
	</div>

	<!-- ── WHY ───────────────────────────────────────────── -->
	<div class="kpis" style="margin-bottom:18px">
		<div class="panel corner"><div class="bd">
			<div class="mono" style="color:var(--accent);font-size:18px;margin-bottom:6px">◎</div>
			<div style="font-weight:600;color:var(--ink-0);margin-bottom:4px"><?php esc_html_e( 'High-Intent Audience', 'publictech-terminal' ); ?></div>
			<div style="font-size:12px;color:var(--ink-2);line-height:1.5"><?php esc_html_e( 'Visitors come to research real government data — not to scroll. Your message lands with people who are paying attention.', 'publictech-terminal' ); ?></div>
		</div></div>
		<div class="panel corner"><div class="bd">
			<div class="mono" style="color:var(--accent);font-size:18px;margin-bottom:6px">⚡</div>
			<div style="font-weight:600;color:var(--ink-0);margin-bottom:4px"><?php esc_html_e( 'Fast & Beautiful', 'publictech-terminal' ); ?></div>
			<div style="font-size:12px;color:var(--ink-2);line-height:1.5"><?php esc_html_e( 'Ads load lazily and never slow the page. They match the site aesthetic, so they feel native, not intrusive.', 'publictech-terminal' ); ?></div>
		</div></div>
		<div class="panel corner"><div class="bd">
			<div class="mono" style="color:var(--accent);font-size:18px;margin-bottom:6px">◈</div>
			<div style="font-weight:600;color:var(--ink-0);margin-bottom:4px"><?php esc_html_e( 'Full Transparency', 'publictech-terminal' ); ?></div>
			<div style="font-size:12px;color:var(--ink-2);line-height:1.5"><?php esc_html_e( 'Track every impression and click from your own dashboard. Know exactly where your audience came from.', 'publictech-terminal' ); ?></div>
		</div></div>
		<div class="panel corner"><div class="bd">
			<div class="mono" style="color:var(--accent);font-size:18px;margin-bottom:6px">◐</div>
			<div style="font-weight:600;color:var(--ink-0);margin-bottom:4px"><?php esc_html_e( 'Self-Serve', 'publictech-terminal' ); ?></div>
			<div style="font-size:12px;color:var(--ink-2);line-height:1.5"><?php esc_html_e( 'Create, schedule, pause, and measure your own ads. No sales calls, no waiting — you are in control.', 'publictech-terminal' ); ?></div>
		</div></div>
	</div>

	<!-- ── PRICING ───────────────────────────────────────── -->
	<div id="ads-pricing" style="margin-bottom:8px">
		<div class="mono uc" style="color:var(--accent);font-size:10px;letter-spacing:0.2em;margin-bottom:6px;text-align:center">◇ <?php esc_html_e( 'Pricing', 'publictech-terminal' ); ?></div>
		<h2 style="text-align:center;font-size:22px;margin:0 0 4px;color:var(--ink-0)"><?php esc_html_e( 'Simple, transparent plans', 'publictech-terminal' ); ?></h2>
		<p style="text-align:center;color:var(--ink-2);font-size:13px;margin:0 0 20px"><?php esc_html_e( 'Cancel anytime. All plans include the self-serve dashboard and full tracking.', 'publictech-terminal' ); ?></p>
	</div>

	<div class="ads-pricing-grid">
		<?php foreach ( $pt_plans as $plan ) : ?>
			<div class="ads-plan panel corner<?php echo $plan['featured'] ? ' featured' : ''; ?>">
				<?php if ( $plan['featured'] ) : ?>
					<div class="ads-plan-badge"><?php esc_html_e( 'Most Popular', 'publictech-terminal' ); ?></div>
				<?php endif; ?>
				<div class="bd">
					<div class="ads-plan-name"><?php echo esc_html( $plan['name'] ); ?></div>
					<div class="ads-plan-price"><?php echo esc_html( $plan['price'] ); ?><span><?php echo esc_html( $plan['period'] ); ?></span></div>
					<div class="ads-plan-tagline"><?php echo esc_html( $plan['tagline'] ); ?></div>
					<ul class="ads-plan-features">
						<?php foreach ( $plan['features'] as $feat ) : ?>
							<li><?php echo esc_html( $feat ); ?></li>
						<?php endforeach; ?>
					</ul>
					<a class="cta<?php echo $plan['featured'] ? '' : ' ghost'; ?>" style="width:100%;justify-content:center" href="<?php echo esc_url( $pt_cta_url ); ?>">
						<?php echo esc_html( $pt_cta_label ); ?>
					</a>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<!-- ── ZONES ─────────────────────────────────────────── -->
	<div class="panel corner" style="margin:18px 0">
		<div class="hd"><h3>◇ <?php esc_html_e( 'Available Ad Placements', 'publictech-terminal' ); ?></h3><span class="meta">9 ZONES</span></div>
		<div class="bd">
			<table class="ads-table">
				<thead><tr>
					<th><?php esc_html_e( 'Placement', 'publictech-terminal' ); ?></th>
					<th><?php esc_html_e( 'Size', 'publictech-terminal' ); ?></th>
					<th><?php esc_html_e( 'Description', 'publictech-terminal' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $pt_zones as $z ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $z[0] ); ?></strong></td>
							<td class="mono" style="color:var(--accent)"><?php echo esc_html( $z[1] ); ?></td>
							<td style="color:var(--ink-2)"><?php echo esc_html( $z[2] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- ── FINAL CTA ─────────────────────────────────────── -->
	<div class="panel corner" style="text-align:center;margin-bottom:8px">
		<div class="bd" style="padding:32px">
			<h2 style="margin:0 0 8px;color:var(--ink-0);font-size:20px"><?php esc_html_e( 'Ready to reach a high-intent audience?', 'publictech-terminal' ); ?></h2>
			<p style="color:var(--ink-2);font-size:13px;margin:0 0 18px"><?php esc_html_e( 'Create your account and launch your first ad in minutes.', 'publictech-terminal' ); ?></p>
			<a class="cta" href="<?php echo esc_url( $pt_cta_url ); ?>">▶ <?php echo esc_html( $pt_cta_label ); ?></a>
		</div>
	</div>

</section>
</main>

<?php get_footer(); ?>
