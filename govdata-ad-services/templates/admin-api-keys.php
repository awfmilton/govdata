<?php
/**
 * API key management for partner sites (admin only).
 *
 * @package GovAds
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Not allowed.', 'govdata-ad-services' ) );
}

$auth = GovAds\Auth::instance();
$new_key = null;

// Handle generate.
if ( isset( $_POST['govads_keys_nonce'] ) && wp_verify_nonce( sanitize_text_field( (string) $_POST['govads_keys_nonce'] ), 'govads_generate_key' ) ) { // phpcs:ignore WordPress.Security
	$label    = sanitize_text_field( (string) ( $_POST['key_label']    ?? '' ) ); // phpcs:ignore WordPress.Security
	$site_url = esc_url_raw( (string) ( $_POST['key_site_url'] ?? '' ) );          // phpcs:ignore WordPress.Security
	if ( $label ) {
		$new_key = $auth->generate_key( $label, $site_url );
	}
}

// Handle revoke.
if ( isset( $_GET['revoke_hash'], $_GET['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security
	if ( wp_verify_nonce( sanitize_text_field( (string) $_GET['_wpnonce'] ), 'govads_revoke_key' ) ) { // phpcs:ignore WordPress.Security
		$auth->revoke_key( sanitize_text_field( (string) $_GET['revoke_hash'] ) ); // phpcs:ignore WordPress.Security
		wp_redirect( admin_url( 'admin.php?page=govads-keys&revoked=1' ) );
		exit;
	}
}

$keys = $auth->all_keys();
?>
<div class="wrap govads-admin-wrap">
	<h1><?php esc_html_e( 'API Keys — Partner Sites', 'govdata-ad-services' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Generate an API key for each partner site that will display ads from this network. Each site installs the govdata-ads-client plugin and enters their key in Settings → GovData Ads Client.', 'govdata-ad-services' ); ?>
	</p>

	<?php if ( isset( $_GET['revoked'] ) ) : // phpcs:ignore WordPress.Security ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Key revoked.', 'govdata-ad-services' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $new_key ) : ?>
	<div class="govads-key-notice">
		<strong><?php esc_html_e( 'New API Key — copy it now, it will not be shown again:', 'govdata-ad-services' ); ?></strong><br>
		<code class="govads-key-code"><?php echo esc_html( $new_key['raw'] ); ?></code>
	</div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Generate New Key', 'govdata-ad-services' ); ?></h2>
	<form method="post">
		<?php wp_nonce_field( 'govads_generate_key', 'govads_keys_nonce' ); ?>
		<table class="form-table">
			<tr>
				<th><label for="key_label"><?php esc_html_e( 'Label', 'govdata-ad-services' ); ?></label></th>
				<td><input type="text" id="key_label" name="key_label" class="regular-text"
					placeholder="<?php esc_attr_e( 'e.g. Toronto Star Website', 'govdata-ad-services' ); ?>" required></td>
			</tr>
			<tr>
				<th><label for="key_site_url"><?php esc_html_e( 'Site URL', 'govdata-ad-services' ); ?></label></th>
				<td><input type="url" id="key_site_url" name="key_site_url" class="regular-text"
					placeholder="https://partner-site.ca"></td>
			</tr>
		</table>
		<?php submit_button( __( 'Generate Key', 'govdata-ad-services' ) ); ?>
	</form>

	<hr>
	<h2><?php esc_html_e( 'Active Keys', 'govdata-ad-services' ); ?></h2>

	<?php if ( empty( $keys ) ) : ?>
		<p><?php esc_html_e( 'No keys generated yet.', 'govdata-ad-services' ); ?></p>
	<?php else : ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Label', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Site URL', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Created', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Last Used', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Status', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'govdata-ad-services' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $keys as $k ) : ?>
			<tr>
				<td><?php echo esc_html( $k['label'] ?? '' ); ?></td>
				<td><?php echo $k['site_url'] ? '<a href="' . esc_url( $k['site_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $k['site_url'] ) . '</a>' : '—'; ?></td>
				<td><?php echo esc_html( $k['created_at'] ?? '' ); ?></td>
				<td><?php echo esc_html( $k['last_used'] ?? __( 'Never', 'govdata-ad-services' ) ); ?></td>
				<td>
					<?php if ( ! empty( $k['active'] ) ) : ?>
						<span class="govads-status approved"><?php esc_html_e( 'Active', 'govdata-ad-services' ); ?></span>
					<?php else : ?>
						<span class="govads-status rejected"><?php esc_html_e( 'Revoked', 'govdata-ad-services' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( ! empty( $k['active'] ) ) : ?>
					<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=govads-keys&revoke_hash=' . rawurlencode( $k['hash'] ) . '&_wpnonce=' . wp_create_nonce( 'govads_revoke_key' ) ) ); ?>"
						onclick="return confirm('<?php echo esc_js( __( 'Revoke this key?', 'govdata-ad-services' ) ); ?>')">
						<?php esc_html_e( 'Revoke', 'govdata-ad-services' ); ?>
					</a>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

	<hr>
	<h2><?php esc_html_e( 'Zone Slugs for Partner Configuration', 'govdata-ad-services' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Share these zone slugs with partner site operators so they know which zones to use in the [govads zone="..."] shortcode.', 'govdata-ad-services' ); ?></p>
	<?php
	$zones = get_posts(
		[
			'post_type'      => 'govads_zone',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'no_found_rows'  => true,
		]
	);
	if ( $zones ) : ?>
	<table class="widefat striped" style="max-width:600px">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Zone', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Slug', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Sizes', 'govdata-ad-services' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $zones as $z ) :
				$slug  = (string) get_post_meta( $z->ID, 'govads_zone_slug', true );
				$sizes = (array) get_post_meta( $z->ID, 'govads_zone_sizes', true );
			?>
			<tr>
				<td><?php echo esc_html( $z->post_title ); ?></td>
				<td><code><?php echo esc_html( $slug ); ?></code></td>
				<td><?php echo esc_html( implode( ', ', $sizes ) ); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php else : ?>
	<p><?php esc_html_e( 'No zones created yet.', 'govdata-ad-services' ); ?></p>
	<?php endif; ?>
</div>
