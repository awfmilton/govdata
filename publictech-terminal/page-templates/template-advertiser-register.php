<?php
/**
 * Template Name: PT — Advertiser Registration
 *
 * Self-serve advertiser signup. Creates a govdata_advertiser account via the
 * /govads/v1/register REST endpoint, logs the user in, and redirects to the
 * /ad-services portal. Already-logged-in users are bounced to the portal.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

// Logged-in users don't need to register.
if ( is_user_logged_in() ) {
	wp_safe_redirect( home_url( '/ad-services/' ) );
	exit;
}

wp_enqueue_style(
	'govads-ad-services',
	get_stylesheet_directory_uri() . '/assets/css/ad-services.css',
	[],
	PUBLICTECH_TERMINAL_VERSION
);

wp_enqueue_script(
	'govads-register',
	get_stylesheet_directory_uri() . '/assets/js/ad-register.js',
	[],
	PUBLICTECH_TERMINAL_VERSION,
	[ 'strategy' => 'defer', 'in_footer' => true ]
);

wp_localize_script( 'govads-register', 'GOVADS_REG', [
	'rest'     => esc_url_raw( rest_url( 'govads/v1' ) ),
	'nonce'    => wp_create_nonce( 'wp_rest' ),
	'loginUrl' => wp_login_url( home_url( '/ad-services/' ) ),
	'portalUrl'=> esc_url( home_url( '/ad-services/' ) ),
] );

get_header(); ?>

<main id="pt-main" tabindex="-1">
<section class="view active" data-pt-view="ad-register">
	<div class="ads-gate">
		<div class="panel corner ads-gate-panel">
			<div class="hd">
				<h3>◇ <?php esc_html_e( 'Create Advertiser Account', 'publictech-terminal' ); ?></h3>
				<span class="meta">GOVDATA AD SERVICES</span>
			</div>
			<div class="bd">
				<p style="margin:0 0 20px;color:var(--ink-1);font-size:13px;line-height:1.6">
					<?php esc_html_e( 'Get your ads in front of engaged Canadians researching government spending, MPs, and federal data. Set up takes under a minute.', 'publictech-terminal' ); ?>
				</p>

				<form id="adsRegForm" class="ads-form" novalidate>
					<div class="ads-form-row">
						<label class="full">
							<?php esc_html_e( 'Full Name', 'publictech-terminal' ); ?> *
							<input type="text" id="regName" autocomplete="name" required>
						</label>
					</div>
					<div class="ads-form-row">
						<label class="full">
							<?php esc_html_e( 'Company / Organization', 'publictech-terminal' ); ?>
							<input type="text" id="regCompany" autocomplete="organization">
						</label>
					</div>
					<div class="ads-form-row">
						<label class="full">
							<?php esc_html_e( 'Email', 'publictech-terminal' ); ?> *
							<input type="email" id="regEmail" autocomplete="email" required>
						</label>
					</div>
					<div class="ads-form-row">
						<label class="full">
							<?php esc_html_e( 'Password', 'publictech-terminal' ); ?> *
							<input type="password" id="regPassword" autocomplete="new-password" minlength="8" required>
							<span style="font-size:9px;color:var(--ink-3);text-transform:none;letter-spacing:0;font-family:'JetBrains Mono'">
								<?php esc_html_e( 'Minimum 8 characters.', 'publictech-terminal' ); ?>
							</span>
						</label>
					</div>

					<div class="ads-notice" id="adsRegMsg" style="display:none"></div>

					<div class="ads-form-actions">
						<button type="submit" class="cta" id="adsRegSubmit" style="flex:1">
							<?php esc_html_e( 'Create Account', 'publictech-terminal' ); ?>
						</button>
					</div>
				</form>

				<p style="margin:16px 0 0;font-size:11px;color:var(--ink-2)">
					<?php
					printf(
						/* translators: %s: sign-in link */
						esc_html__( 'Already have an account? %s', 'publictech-terminal' ),
						'<a href="' . esc_url( wp_login_url( home_url( '/ad-services/' ) ) ) . '" style="color:var(--accent)">'
						. esc_html__( 'Sign in', 'publictech-terminal' ) . '</a>'
					);
					?>
				</p>
			</div>
		</div>
	</div>
</section>
</main>

<?php get_footer(); ?>
