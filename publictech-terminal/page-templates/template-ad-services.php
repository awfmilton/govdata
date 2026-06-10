<?php
/**
 * Template Name: PT — Ad Services Portal
 *
 * Self-serve advertiser portal. No WP Admin access required.
 * Logged-in advertisers manage campaigns, submit ads, and track performance.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$pt_user    = wp_get_current_user();
$pt_logged  = (bool) $pt_user->ID;
$pt_can_ads = $pt_logged && (
	current_user_can( 'govads_submit_ads' ) ||
	current_user_can( 'edit_others_posts' )
);
$pt_is_admin    = $pt_logged && current_user_can( 'edit_others_posts' );
$pt_requested   = $pt_logged && (bool) get_user_meta( $pt_user->ID, 'govads_access_requested', true );
$pt_ad_sizes    = class_exists( 'GovAds\\Renderer' ) ? array_keys( GovAds\Renderer::SIZES ) : [ '300x250', '728x90', '160x600', '300x600', '970x90', '320x50' ];

wp_enqueue_style(
	'govads-ad-services',
	get_stylesheet_directory_uri() . '/assets/css/ad-services.css',
	[],
	PUBLICTECH_TERMINAL_VERSION
);

wp_enqueue_script(
	'govads-portal',
	get_stylesheet_directory_uri() . '/assets/js/ad-services.js',
	[],
	PUBLICTECH_TERMINAL_VERSION,
	[ 'strategy' => 'defer', 'in_footer' => true ]
);

wp_localize_script( 'govads-portal', 'GOVADS_PORTAL', [
	'rest'        => esc_url_raw( rest_url( 'govads/v1' ) ),
	'nonce'       => wp_create_nonce( 'wp_rest' ),
	'loggedIn'    => $pt_logged,
	'canAds'      => $pt_can_ads,
	'isAdmin'     => $pt_is_admin,
	'sizes'       => $pt_ad_sizes,
	'loginUrl'    => wp_login_url( get_permalink() ),
	'siteUrl'     => esc_url( home_url() ),
] );

get_header(); ?>

<main id="pt-main" tabindex="-1">
<section class="view active" data-pt-view="ad-services">

<?php if ( ! $pt_logged ) : ?>
<!-- ════════════════════ NOT LOGGED IN ════════════════════ -->
<div class="ads-gate">
	<div class="panel corner ads-gate-panel">
		<div class="hd">
			<h3>◇ <?php esc_html_e( 'GovData Ad Services', 'publictech-terminal' ); ?></h3>
			<span class="meta">ADVERTISER PORTAL</span>
		</div>
		<div class="bd">
			<p style="margin:0 0 20px;color:var(--ink-1);font-size:13px;line-height:1.6">
				<?php esc_html_e( 'Reach engaged Canadians researching government data. Sign in to create and track your campaigns.', 'publictech-terminal' ); ?>
			</p>
			<?php
			wp_login_form( [
				'redirect'      => get_permalink(),
				'label_log_in'  => __( 'Sign In', 'publictech-terminal' ),
			] );
			?>
			<p style="margin:16px 0 0;font-size:11px;color:var(--ink-2)">
				<?php
				printf(
					/* translators: %s: registration link */
					esc_html__( "Don't have an account? %s", 'publictech-terminal' ),
					'<a href="' . esc_url( wp_registration_url() ) . '" style="color:var(--accent)">'
					. esc_html__( 'Register', 'publictech-terminal' ) . '</a>'
				);
				?>
			</p>
		</div>
	</div>
</div>

<?php elseif ( ! $pt_can_ads ) : ?>
<!-- ════════════════ LOGGED IN, NO ADVERTISER ROLE ════════════════ -->
<div class="ads-gate">
	<div class="panel corner ads-gate-panel">
		<div class="hd">
			<h3>◇ <?php esc_html_e( 'GovData Ad Services', 'publictech-terminal' ); ?></h3>
			<span class="meta">ACCESS PENDING</span>
		</div>
		<div class="bd">
			<p style="margin:0 0 14px;color:var(--ink-0)">
				<?php
				printf(
					/* translators: %s: user name */
					esc_html__( 'Welcome, %s.', 'publictech-terminal' ),
					esc_html( $pt_user->display_name )
				);
				?>
			</p>
			<?php if ( $pt_requested ) : ?>
				<div class="ads-notice info">
					<?php esc_html_e( 'Your advertiser access request has been received. You will receive an email once your account is approved — usually within 1 business day.', 'publictech-terminal' ); ?>
				</div>
			<?php else : ?>
				<p style="margin:0 0 16px;color:var(--ink-2);font-size:12px;line-height:1.6">
					<?php esc_html_e( 'Your account does not yet have advertiser access. Request access below and our team will review it promptly.', 'publictech-terminal' ); ?>
				</p>
				<button class="cta" id="adsRequestAccess">
					<?php esc_html_e( 'Request Advertiser Access', 'publictech-terminal' ); ?>
				</button>
				<div id="adsRequestMsg" style="margin-top:14px;display:none"></div>
			<?php endif; ?>
		</div>
	</div>
</div>

<?php else : ?>
<!-- ════════════════════ FULL ADVERTISER PORTAL ════════════════════ -->

<!-- Header bar -->
<div class="ads-header">
	<div>
		<div class="mono uc" style="color:var(--accent);font-size:10px;letter-spacing:0.2em;margin-bottom:6px">◇ <?php esc_html_e( 'GovData Ad Services', 'publictech-terminal' ); ?></div>
		<h2>
			<?php
			printf(
				/* translators: %s: first name or display name */
				esc_html__( 'Welcome back, %s.', 'publictech-terminal' ),
				esc_html( $pt_user->first_name ?: $pt_user->display_name )
			);
			?>
		</h2>
	</div>
	<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
		<button class="cta ghost" id="adsGoStats">◈ <?php esc_html_e( 'Stats', 'publictech-terminal' ); ?></button>
		<button class="cta" id="adsShowNewAd">+ <?php esc_html_e( 'New Ad', 'publictech-terminal' ); ?></button>
	</div>
</div>

<!-- Tab navigation -->
<nav class="ads-nav" role="tablist" aria-label="<?php esc_attr_e( 'Ad Services navigation', 'publictech-terminal' ); ?>">
	<button class="ads-nav-btn active" data-ads-tab="overview" role="tab" aria-selected="true">
		<?php esc_html_e( 'Overview', 'publictech-terminal' ); ?>
	</button>
	<button class="ads-nav-btn" data-ads-tab="ads" role="tab" aria-selected="false">
		<?php esc_html_e( 'My Ads', 'publictech-terminal' ); ?>
		<span class="ads-nav-badge" id="navBadgeAds" style="display:none"></span>
	</button>
	<button class="ads-nav-btn" data-ads-tab="campaigns" role="tab" aria-selected="false">
		<?php esc_html_e( 'Campaigns', 'publictech-terminal' ); ?>
	</button>
	<button class="ads-nav-btn" data-ads-tab="stats" role="tab" aria-selected="false">
		<?php esc_html_e( 'Stats', 'publictech-terminal' ); ?>
	</button>
	<?php if ( $pt_is_admin ) : ?>
	<button class="ads-nav-btn" data-ads-tab="admin" role="tab" aria-selected="false" style="margin-left:auto;color:var(--warn)">
		⚙ <?php esc_html_e( 'Admin', 'publictech-terminal' ); ?>
		<span class="ads-nav-badge" id="navBadgePending" style="display:none"></span>
	</button>
	<?php endif; ?>
</nav>

<!-- ─── OVERVIEW tab ──────────────────────────────── -->
<div class="ads-view active" id="view-overview">

	<!-- KPI strip -->
	<div class="kpis" style="margin-bottom:14px">
		<div class="kpi corner">
			<div class="lab"><?php esc_html_e( 'Impressions (30d)', 'publictech-terminal' ); ?></div>
			<div class="val" id="kpiImp">—</div>
		</div>
		<div class="kpi corner">
			<div class="lab"><?php esc_html_e( 'Clicks (30d)', 'publictech-terminal' ); ?></div>
			<div class="val" id="kpiClk">—</div>
		</div>
		<div class="kpi corner">
			<div class="lab"><?php esc_html_e( 'CTR', 'publictech-terminal' ); ?></div>
			<div class="val" id="kpiCtr">—</div>
		</div>
		<div class="kpi corner">
			<div class="lab"><?php esc_html_e( 'Active Ads', 'publictech-terminal' ); ?></div>
			<div class="val" id="kpiActive">—</div>
		</div>
	</div>

	<!-- Recent activity -->
	<div class="panel corner">
		<div class="hd">
			<h3>◇ <?php esc_html_e( 'Recent Activity', 'publictech-terminal' ); ?></h3>
			<span class="meta"><?php esc_html_e( '30 DAYS', 'publictech-terminal' ); ?></span>
		</div>
		<div class="bd" id="adsActivityBody">
			<div class="ads-loading"><?php esc_html_e( 'Loading', 'publictech-terminal' ); ?></div>
		</div>
	</div>

</div><!-- /view-overview -->

<!-- ─── MY ADS tab ───────────────────────────────── -->
<div class="ads-view" id="view-ads">

	<!-- Ads list panel -->
	<div class="panel corner" style="margin-bottom:14px" id="adsListPanel">
		<div class="hd">
			<h3>◇ <?php esc_html_e( 'My Ads', 'publictech-terminal' ); ?></h3>
			<button class="cta ghost" style="font-size:10px;padding:4px 10px" id="adsOpenForm">
				+ <?php esc_html_e( 'New Ad', 'publictech-terminal' ); ?>
			</button>
		</div>
		<div class="bd" id="adsListBody">
			<div class="ads-loading"><?php esc_html_e( 'Loading', 'publictech-terminal' ); ?></div>
		</div>
	</div>

	<!-- Ad create/edit form (hidden by default) -->
	<div class="panel corner" id="adsFormPanel" style="display:none">
		<div class="hd">
			<h3>◇ <span id="adsFormHeading"><?php esc_html_e( 'Submit New Ad', 'publictech-terminal' ); ?></span></h3>
			<button class="ads-close-btn" id="adsCloseForm" aria-label="<?php esc_attr_e( 'Close form', 'publictech-terminal' ); ?>">✕</button>
		</div>
		<div class="bd">
			<form id="adsNewAdForm" class="ads-form" novalidate>
				<input type="hidden" id="adsEditId" value="">

				<div class="ads-form-row">
					<label class="full">
						<?php esc_html_e( 'Ad Name', 'publictech-terminal' ); ?> *
						<input type="text" id="adTitle" placeholder="<?php esc_attr_e( 'e.g. Summer Campaign Banner', 'publictech-terminal' ); ?>" required>
					</label>
				</div>

				<div class="ads-form-row">
					<label>
						<?php esc_html_e( 'Ad Type', 'publictech-terminal' ); ?>
						<select id="adType">
							<option value="image"><?php esc_html_e( 'Image Banner', 'publictech-terminal' ); ?></option>
							<option value="html5"><?php esc_html_e( 'HTML5 / Rich Media', 'publictech-terminal' ); ?></option>
						</select>
					</label>
					<label>
						<?php esc_html_e( 'IAB Size', 'publictech-terminal' ); ?>
						<select id="adSize">
							<?php foreach ( $pt_ad_sizes as $sz ) : ?>
								<option value="<?php echo esc_attr( $sz ); ?>"><?php echo esc_html( $sz ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>

				<div class="ads-form-row" id="rowImageUrl">
					<label class="full">
						<?php esc_html_e( 'Image URL', 'publictech-terminal' ); ?>
						<input type="url" id="adImageUrl" placeholder="https://example.com/banner-300x250.png">
					</label>
				</div>

				<div class="ads-form-row" id="rowHtml" style="display:none">
					<label class="full">
						<?php esc_html_e( 'HTML Content', 'publictech-terminal' ); ?>
						<textarea id="adHtml" rows="6" placeholder="<?php esc_attr_e( 'Paste your HTML5 ad code here…', 'publictech-terminal' ); ?>"></textarea>
					</label>
				</div>

				<div class="ads-form-row">
					<label class="full">
						<?php esc_html_e( 'Destination URL', 'publictech-terminal' ); ?> *
						<input type="url" id="adTargetUrl" placeholder="https://your-website.ca/landing" required>
					</label>
				</div>

				<div class="ads-form-row">
					<label>
						<?php esc_html_e( 'Campaign', 'publictech-terminal' ); ?>
						<select id="adCampaign">
							<option value="0"><?php esc_html_e( '— No campaign —', 'publictech-terminal' ); ?></option>
						</select>
					</label>
					<label>
						<?php esc_html_e( 'Start Date', 'publictech-terminal' ); ?>
						<input type="date" id="adStartDate">
					</label>
					<label>
						<?php esc_html_e( 'End Date', 'publictech-terminal' ); ?>
						<input type="date" id="adEndDate">
					</label>
				</div>

				<div class="ads-notice" id="adsFormMsg" style="display:none"></div>

				<div class="ads-form-actions">
					<button type="submit" class="cta" id="adsFormSubmitBtn">
						<?php esc_html_e( 'Submit for Review', 'publictech-terminal' ); ?>
					</button>
					<button type="button" class="cta ghost" id="adsCancelForm">
						<?php esc_html_e( 'Cancel', 'publictech-terminal' ); ?>
					</button>
				</div>
			</form>
		</div>
	</div>

</div><!-- /view-ads -->

<!-- ─── CAMPAIGNS tab ────────────────────────────── -->
<div class="ads-view" id="view-campaigns">

	<div class="panel corner" style="margin-bottom:14px">
		<div class="hd">
			<h3>◇ <?php esc_html_e( 'My Campaigns', 'publictech-terminal' ); ?></h3>
			<button class="cta ghost" style="font-size:10px;padding:4px 10px" id="adsOpenCampaignForm">
				+ <?php esc_html_e( 'New', 'publictech-terminal' ); ?>
			</button>
		</div>
		<div class="bd" id="adsCampaignBody">
			<div class="ads-loading"><?php esc_html_e( 'Loading', 'publictech-terminal' ); ?></div>
		</div>
	</div>

	<!-- Campaign create form -->
	<div class="panel corner" id="adsCampaignFormPanel" style="display:none">
		<div class="hd">
			<h3>◇ <?php esc_html_e( 'New Campaign', 'publictech-terminal' ); ?></h3>
			<button class="ads-close-btn" id="adsCloseCampaignForm">✕</button>
		</div>
		<div class="bd">
			<form id="adsNewCampaignForm" class="ads-form" novalidate>
				<div class="ads-form-row">
					<label class="full">
						<?php esc_html_e( 'Campaign Name', 'publictech-terminal' ); ?> *
						<input type="text" id="campTitle" placeholder="<?php esc_attr_e( 'e.g. Summer 2026', 'publictech-terminal' ); ?>" required>
					</label>
				</div>
				<div class="ads-form-row">
					<label>
						<?php esc_html_e( 'Start Date', 'publictech-terminal' ); ?>
						<input type="date" id="campStart">
					</label>
					<label>
						<?php esc_html_e( 'End Date', 'publictech-terminal' ); ?>
						<input type="date" id="campEnd">
					</label>
				</div>
				<div class="ads-notice" id="adsCampMsg" style="display:none"></div>
				<div class="ads-form-actions">
					<button type="submit" class="cta"><?php esc_html_e( 'Create Campaign', 'publictech-terminal' ); ?></button>
					<button type="button" class="cta ghost" id="adsCancelCampaign"><?php esc_html_e( 'Cancel', 'publictech-terminal' ); ?></button>
				</div>
			</form>
		</div>
	</div>

</div><!-- /view-campaigns -->

<!-- ─── STATS tab ────────────────────────────────── -->
<div class="ads-view" id="view-stats">

	<div class="panel corner">
		<div class="hd">
			<h3>◇ <?php esc_html_e( 'Ad Performance', 'publictech-terminal' ); ?></h3>
			<div style="display:flex;align-items:center;gap:8px">
				<span class="mono" style="font-size:10px;color:var(--ink-3);text-transform:uppercase;letter-spacing:0.1em">
					<?php esc_html_e( 'Last', 'publictech-terminal' ); ?>
				</span>
				<select id="statsDays" class="mono" style="font-size:11px;padding:3px 8px;background:var(--bg-3);border:1px solid var(--line-2);color:var(--ink-0);border-radius:3px">
					<option value="7">7 <?php esc_html_e( 'days', 'publictech-terminal' ); ?></option>
					<option value="14">14 <?php esc_html_e( 'days', 'publictech-terminal' ); ?></option>
					<option value="30" selected>30 <?php esc_html_e( 'days', 'publictech-terminal' ); ?></option>
					<option value="90">90 <?php esc_html_e( 'days', 'publictech-terminal' ); ?></option>
				</select>
			</div>
		</div>
		<div class="bd" id="adsStatsBody">
			<div class="ads-loading"><?php esc_html_e( 'Loading', 'publictech-terminal' ); ?></div>
		</div>
	</div>

</div><!-- /view-stats -->

<?php if ( $pt_is_admin ) : ?>
<!-- ─── ADMIN tab ────────────────────────────────── -->
<div class="ads-view" id="view-admin">

	<!-- Platform KPI strip -->
	<div class="kpis" style="margin-bottom:14px">
		<div class="kpi corner">
			<div class="lab"><?php esc_html_e( 'Total Impressions (30d)', 'publictech-terminal' ); ?></div>
			<div class="val" id="admKpiImp">—</div>
		</div>
		<div class="kpi corner">
			<div class="lab"><?php esc_html_e( 'Total Clicks (30d)', 'publictech-terminal' ); ?></div>
			<div class="val" id="admKpiClk">—</div>
		</div>
		<div class="kpi corner">
			<div class="lab"><?php esc_html_e( 'Advertisers', 'publictech-terminal' ); ?></div>
			<div class="val" id="admKpiAdvertisers">—</div>
		</div>
		<div class="kpi corner">
			<div class="lab"><?php esc_html_e( 'Pending Review', 'publictech-terminal' ); ?></div>
			<div class="val" id="admKpiPending" style="color:var(--warn)">—</div>
		</div>
	</div>

	<!-- Pending approval queue -->
	<div class="panel corner" style="margin-bottom:14px">
		<div class="hd">
			<h3>◇ <?php esc_html_e( 'Pending Approval', 'publictech-terminal' ); ?></h3>
			<span class="meta"><?php esc_html_e( 'AWAITING REVIEW', 'publictech-terminal' ); ?></span>
		</div>
		<div class="bd" id="adsAdminPendingBody">
			<div class="ads-loading"><?php esc_html_e( 'Loading', 'publictech-terminal' ); ?></div>
		</div>
	</div>

	<!-- Advertiser leaderboard -->
	<div class="panel corner">
		<div class="hd">
			<h3>◇ <?php esc_html_e( 'Advertisers', 'publictech-terminal' ); ?></h3>
			<span class="meta"><?php esc_html_e( 'BY 30-DAY IMPRESSIONS', 'publictech-terminal' ); ?></span>
		</div>
		<div class="bd" id="adsAdminAdvertisersBody">
			<div class="ads-loading"><?php esc_html_e( 'Loading', 'publictech-terminal' ); ?></div>
		</div>
	</div>

</div><!-- /view-admin -->
<?php endif; ?>

<?php endif; // end $pt_can_ads ?>

</section>
</main>

<?php get_footer(); ?>
