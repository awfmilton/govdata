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

// Developer API key rotation. Available to users whose tier sets mcp=true;
// the actual indexed reverse-map is owned by Access_Tiers so the same token
// system that the admin UI uses backs this front-end surface.
if ( ! empty( $_POST['_pt_token_nonce'] ) && check_admin_referer( 'pt_token_rotate', '_pt_token_nonce' ) ) {
	$pt_quota_check = \PublicTech\Core\Access_Tiers::instance()->quota( get_current_user_id() );
	if ( ! empty( $pt_quota_check['mcp'] ) ) {
		$pt_plain = 'pt_' . bin2hex( random_bytes( 24 ) );
		\PublicTech\Core\Access_Tiers::instance()->index_token( get_current_user_id(), $pt_plain );
		set_transient( 'pt_token_show_' . get_current_user_id(), $pt_plain, 120 );
	}
	wp_safe_redirect( add_query_arg( 'pt_tab', 'security', remove_query_arg( 'pt_tab' ) ) );
	exit;
}

if ( ! empty( $_POST['_pt_token_revoke_nonce'] ) && check_admin_referer( 'pt_token_revoke', '_pt_token_revoke_nonce' ) ) {
	$pt_uid_revoke = get_current_user_id();
	$pt_old_hash   = (string) get_user_meta( $pt_uid_revoke, \PublicTech\Core\Access_Tiers::META_TOKEN, true );
	if ( $pt_old_hash ) {
		delete_option( 'pt_tok_' . substr( $pt_old_hash, 0, 16 ) );
		delete_user_meta( $pt_uid_revoke, \PublicTech\Core\Access_Tiers::META_TOKEN );
	}
	wp_safe_redirect( add_query_arg( 'pt_tab', 'security', remove_query_arg( 'pt_tab' ) ) );
	exit;
}

$pt_user    = wp_get_current_user();
$pt_tiers   = \PublicTech\Core\Access_Tiers::instance();
$pt_tier    = $pt_tiers->user_tier( $pt_user->ID );
$pt_quota   = $pt_tiers->quota( $pt_user->ID );
$pt_saved_raw = get_user_meta( $pt_user->ID, 'pt_saved_queries', true );
$pt_saved     = is_array( $pt_saved_raw ) ? array_filter( $pt_saved_raw, 'is_array' ) : [];
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
					<div class="hd"><h3>◇ <?php esc_html_e( 'Developer Settings', 'publictech-terminal' ); ?></h3><span class="meta">API · MCP</span></div>

					<?php
					$pt_has_token = (bool) get_user_meta( $pt_user->ID, \PublicTech\Core\Access_Tiers::META_TOKEN, true );
					$pt_freshly_minted = get_transient( 'pt_token_show_' . $pt_user->ID );
					if ( $pt_freshly_minted ) {
						delete_transient( 'pt_token_show_' . $pt_user->ID );
					}
					?>

					<?php if ( $pt_freshly_minted ) : ?>
						<div class="setting-row" style="background:rgba(0,160,210,0.08);border-left:3px solid var(--accent)">
							<div>
								<div class="label"><?php esc_html_e( 'New API key — copy now', 'publictech-terminal' ); ?></div>
								<div class="desc"><?php esc_html_e( 'This is the only time you will see this key. Store it somewhere safe; we only keep a hash on our side.', 'publictech-terminal' ); ?></div>
							</div>
							<code style="font-family:JetBrains Mono,monospace;font-size:11px;color:var(--accent);word-break:break-all;display:block;padding:8px;background:var(--bg-0);border-radius:3px"><?php echo esc_html( $pt_freshly_minted ); ?></code>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $pt_quota['mcp'] ) ) : ?>
						<form method="post" class="setting-row">
							<?php wp_nonce_field( 'pt_token_rotate', '_pt_token_nonce' ); ?>
							<div>
								<div class="label"><?php echo $pt_has_token ? esc_html__( 'Rotate API key', 'publictech-terminal' ) : esc_html__( 'Generate API key', 'publictech-terminal' ); ?></div>
								<div class="desc"><?php esc_html_e( 'Used as a Bearer token to authenticate against /publictech/v1/* and the MCP server (/wp-json/publictech-mcp/v1/rpc). Tier rate limits and read-only enforcement apply to every call.', 'publictech-terminal' ); ?></div>
							</div>
							<button type="submit" class="cta"><?php echo $pt_has_token ? '↻ ' . esc_html__( 'Rotate', 'publictech-terminal' ) : '＋ ' . esc_html__( 'Generate', 'publictech-terminal' ); ?></button>
						</form>

						<?php if ( $pt_has_token ) : ?>
							<form method="post" class="setting-row">
								<?php wp_nonce_field( 'pt_token_revoke', '_pt_token_revoke_nonce' ); ?>
								<div>
									<div class="label"><?php esc_html_e( 'Revoke API key', 'publictech-terminal' ); ?></div>
									<div class="desc"><?php esc_html_e( 'Immediately invalidate the active key. External clients using it will see 401 on next call.', 'publictech-terminal' ); ?></div>
								</div>
								<button type="submit" class="cta ghost" style="color:var(--neg);border-color:var(--neg)">⊗ <?php esc_html_e( 'Revoke', 'publictech-terminal' ); ?></button>
							</form>
						<?php endif; ?>

						<div class="setting-row">
							<div>
								<div class="label"><?php esc_html_e( 'MCP endpoint', 'publictech-terminal' ); ?></div>
								<div class="desc"><?php esc_html_e( 'Point your MCP-compatible client at this URL. Pass your API key as an Authorization: Bearer header.', 'publictech-terminal' ); ?></div>
							</div>
							<code style="font-family:JetBrains Mono,monospace;font-size:11px;color:var(--ink-0)"><?php echo esc_html( esc_url_raw( rest_url( 'publictech-mcp/v1/rpc' ) ) ); ?></code>
						</div>
					<?php else : ?>
						<div class="setting-row pt-tier-locked">
							<?php esc_html_e( 'Developer API access is included with the Institutional tier. Upgrade to issue an API key.', 'publictech-terminal' ); ?>
							<a href="<?php echo esc_url( home_url( '/pricing' ) ); ?>" style="margin-left:6px"><?php esc_html_e( 'See plans →', 'publictech-terminal' ); ?></a>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</section>
</main>

<?php get_footer();
