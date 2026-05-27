<?php
/**
 * Template Name: PT — User Profile
 *
 * Authenticated user's account & preferences page (their own settings, saved
 * queries, MCP token visibility). Distinct from the single-mp.php template
 * which shows a Member of Parliament profile.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	wp_safe_redirect( wp_login_url( get_permalink() ) );
	exit;
}

if ( ! empty( $_POST['_pt_profile_nonce'] ) && check_admin_referer( 'pt_profile_update', '_pt_profile_nonce' ) ) {
	wp_update_user( [
		'ID'           => get_current_user_id(),
		'display_name' => sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) ),
		'user_email'   => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
		'description'  => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
	] );
	wp_safe_redirect( add_query_arg( 'pt_updated', '1', remove_query_arg( 'pt_updated' ) ) );
	exit;
}

$pt_user    = wp_get_current_user();
$pt_tiers   = \PublicTech\Core\Access_Tiers::instance();
$pt_tier    = $pt_tiers->user_tier( $pt_user->ID );
$pt_quota   = $pt_tiers->quota( $pt_user->ID );
$pt_saved   = (array) get_user_meta( $pt_user->ID, 'pt_saved_queries', true );
$pt_initial = strtoupper( substr( $pt_user->display_name, 0, 2 ) );

get_header(); ?>

<main>
	<section class="view active" data-pt-view="user">
		<div class="user-grid">
			<aside style="display:flex;flex-direction:column;gap:14px">
				<div class="user-hero corner">
					<div class="av"><?php echo esc_html( $pt_initial ); ?></div>
					<h2 class="uname"><?php echo esc_html( $pt_user->display_name ); ?></h2>
					<div class="handle">@<?php echo esc_html( $pt_user->user_login ); ?></div>
					<div class="role"><?php echo esc_html( $pt_user->user_email ); ?></div>
					<div class="badges">
						<span class="badge"><?php echo esc_html( ucfirst( $pt_tier ) ); ?></span>
						<?php if ( in_array( 'administrator', (array) $pt_user->roles, true ) ) : ?>
							<span class="badge gold">★ Admin</span>
						<?php endif; ?>
					</div>
				</div>

				<div class="panel">
					<div class="hd"><h3>◇ <?php esc_html_e( 'Quota', 'publictech-terminal' ); ?></h3><span class="meta">TIER</span></div>
					<div class="kvs">
						<div class="kv"><span class="k"><?php esc_html_e( 'Requests / min', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( number_format_i18n( (int) $pt_quota['rpm'] ) ); ?></span></div>
						<div class="kv"><span class="k"><?php esc_html_e( 'Requests / day', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( number_format_i18n( (int) $pt_quota['rpd'] ) ); ?></span></div>
						<div class="kv"><span class="k"><?php esc_html_e( 'Max rows / call', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( number_format_i18n( (int) $pt_quota['max_rows'] ) ); ?></span></div>
						<div class="kv"><span class="k"><?php esc_html_e( 'AI Console', 'publictech-terminal' ); ?></span><span class="v" style="color:<?php echo $pt_quota['mcp'] ? 'var(--pos)' : 'var(--ink-3)'; ?>"><?php echo $pt_quota['mcp'] ? 'enabled' : 'locked'; ?></span></div>
					</div>
				</div>
			</aside>

			<div>
				<div class="settings-tabs" data-pt-tabs="profile">
					<button class="on" data-pt-tab="profile">◇ <?php esc_html_e( 'Profile',       'publictech-terminal' ); ?></button>
					<button data-pt-tab="prefs">◇ <?php esc_html_e( 'Preferences',  'publictech-terminal' ); ?></button>
					<button data-pt-tab="saved">◇ <?php esc_html_e( 'Saved Queries','publictech-terminal' ); ?></button>
					<button data-pt-tab="security">◇ <?php esc_html_e( 'Security',    'publictech-terminal' ); ?></button>
				</div>

				<form method="post" class="panel corner" data-pt-view="profile">
					<?php wp_nonce_field( 'pt_profile_update', '_pt_profile_nonce' ); ?>
					<div class="hd"><h3>◇ <?php esc_html_e( 'Profile Information', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'EDITABLE', 'publictech-terminal' ); ?></span></div>
					<div>
						<div class="setting-row">
							<div><div class="label"><?php esc_html_e( 'Display name', 'publictech-terminal' ); ?></div><div class="desc"><?php esc_html_e( 'Shown on forum posts and audit-flag attributions.', 'publictech-terminal' ); ?></div></div>
							<input type="text" name="display_name" value="<?php echo esc_attr( $pt_user->display_name ); ?>" />
						</div>
						<div class="setting-row">
							<div><div class="label"><?php esc_html_e( 'Contact email', 'publictech-terminal' ); ?></div><div class="desc"><?php esc_html_e( 'Digest summaries and flag-response notifications.', 'publictech-terminal' ); ?></div></div>
							<input type="email" name="email" value="<?php echo esc_attr( $pt_user->user_email ); ?>" />
						</div>
						<div class="setting-row">
							<div><div class="label"><?php esc_html_e( 'Bio', 'publictech-terminal' ); ?></div><div class="desc"><?php esc_html_e( 'Displayed beneath your name on profile and contributions.', 'publictech-terminal' ); ?></div></div>
							<input type="text" name="description" value="<?php echo esc_attr( get_user_meta( $pt_user->ID, 'description', true ) ); ?>" />
						</div>
					</div>
				</form>

				<div class="panel corner" data-pt-view="prefs" style="display:none">
					<div class="hd"><h3>◇ <?php esc_html_e( 'Workspace Preferences', 'publictech-terminal' ); ?></h3><span class="meta">LIVE</span></div>
					<div>
						<div class="setting-row">
							<div><div class="label"><?php esc_html_e( 'Default theme', 'publictech-terminal' ); ?></div><div class="desc"><?php esc_html_e( 'Override the auto-detected system theme.', 'publictech-terminal' ); ?></div></div>
							<select id="pref-theme">
								<option value="dark">Dark (terminal)</option>
								<option value="light">Light (paper)</option>
								<option value="system">Match system</option>
							</select>
						</div>
						<div class="setting-row">
							<div><div class="label"><?php esc_html_e( 'Open chart customizer', 'publictech-terminal' ); ?></div><div class="desc"><?php esc_html_e( 'Recolour every chart you render. Stored against your account.', 'publictech-terminal' ); ?></div></div>
							<button type="button" class="cta" id="pref-open-cust">◈ <?php esc_html_e( 'Open', 'publictech-terminal' ); ?></button>
						</div>
					</div>
				</div>

				<div class="panel corner" data-pt-view="saved" style="display:none">
					<div class="hd"><h3>◇ <?php esc_html_e( 'Saved Queries', 'publictech-terminal' ); ?></h3><span class="meta"><?php echo esc_html( number_format_i18n( count( $pt_saved ) ) ); ?></span></div>
					<div>
						<?php if ( $pt_saved ) : foreach ( $pt_saved as $sq ) : ?>
							<div class="saved-q">
								<div><div class="q"><?php echo esc_html( $sq['title'] ?? '' ); ?></div><div class="schema">// <?php echo esc_html( $sq['schema'] ?? '' ); ?></div></div>
								<div class="date"><?php echo esc_html( $sq['date'] ?? '' ); ?></div>
								<div class="runs"><b><?php echo esc_html( (int) ( $sq['runs'] ?? 0 ) ); ?></b>runs</div>
							</div>
						<?php endforeach; else : ?>
							<div class="setting-row"><div><div class="desc"><?php esc_html_e( 'No saved queries yet. Star a query in the AI Console to save it here.', 'publictech-terminal' ); ?></div></div></div>
						<?php endif; ?>
					</div>
				</div>

				<div class="panel corner" data-pt-view="security" style="display:none">
					<div class="hd"><h3>◇ <?php esc_html_e( 'Security & API Tokens', 'publictech-terminal' ); ?></h3><span class="meta">MCP</span></div>
					<div class="setting-row">
						<div>
							<div class="label"><?php esc_html_e( 'MCP bearer token', 'publictech-terminal' ); ?></div>
							<div class="desc"><?php esc_html_e( 'Used by external AI clients to query PublicTech via the MCP server. Tokens are stored hashed; rotate from your WordPress profile page.', 'publictech-terminal' ); ?></div>
						</div>
						<a class="cta ghost" href="<?php echo esc_url( admin_url( 'profile.php#publictech_token' ) ); ?>"><?php esc_html_e( 'Manage', 'publictech-terminal' ); ?></a>
					</div>
				</div>
			</div>
		</div>
	</section>
</main>

<?php get_footer();
