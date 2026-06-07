<?php
/**
 * Ad submission / edit form.
 *
 * @package GovAds
 */

defined( 'ABSPATH' ) || exit;

$uid      = get_current_user_id();
$is_admin = current_user_can( 'edit_others_posts' );

$edit_id   = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security
$edit_post = $edit_id ? get_post( $edit_id ) : null;
if ( $edit_post && 'govads_ad' !== $edit_post->post_type ) {
	$edit_post = null;
}
if ( $edit_post && (int) $edit_post->post_author !== $uid && ! $is_admin ) {
	$edit_post = null;
}

$meta = $edit_post ? get_post_meta( $edit_post->ID ) : [];
$val  = static function ( string $key ) use ( $meta ): string {
	return isset( $meta[ $key ][0] ) ? (string) $meta[ $key ][0] : '';
};

// My campaigns for the dropdown.
$my_campaigns = get_posts(
	[
		'post_type'      => 'govads_campaign',
		'post_status'    => 'publish',
		'author'         => $is_admin ? 0 : $uid,
		'posts_per_page' => 50,
		'no_found_rows'  => true,
	]
);

$sizes = array_keys( GovAds\Renderer::SIZES );
?>
<div class="wrap govads-admin-wrap">
	<h1><?php echo $edit_post ? esc_html__( 'Edit Ad', 'govdata-ad-services' ) : esc_html__( 'Submit New Ad', 'govdata-ad-services' ); ?></h1>

	<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php esc_html_e( 'Ad submitted for review. You will receive an email when it is approved.', 'govdata-ad-services' ); ?>
		</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['error'] ) ) : // phpcs:ignore WordPress.Security ?>
		<div class="notice notice-error is-dismissible"><p>
			<?php esc_html_e( 'Please fill in all required fields.', 'govdata-ad-services' ); ?>
		</p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'govads_ad_save' ); ?>
		<input type="hidden" name="action" value="govads_save_ad">
		<input type="hidden" name="ad_id" value="<?php echo $edit_post ? esc_attr( (string) $edit_post->ID ) : '0'; ?>">

		<table class="form-table">
			<tr>
				<th><label for="ad_title"><?php esc_html_e( 'Ad Name', 'govdata-ad-services' ); ?> *</label></th>
				<td><input type="text" id="ad_title" name="ad_title" class="regular-text"
					value="<?php echo $edit_post ? esc_attr( $edit_post->post_title ) : ''; ?>" required></td>
			</tr>
			<tr>
				<th><label for="ad_type"><?php esc_html_e( 'Ad Type', 'govdata-ad-services' ); ?></label></th>
				<td>
					<select id="ad_type" name="ad_type">
						<option value="image" <?php selected( $val( 'govads_ad_type' ), 'image' ); ?>><?php esc_html_e( 'Image Banner', 'govdata-ad-services' ); ?></option>
						<option value="html5" <?php selected( $val( 'govads_ad_type' ), 'html5' ); ?>><?php esc_html_e( 'HTML5 / Rich Media', 'govdata-ad-services' ); ?></option>
					</select>
				</td>
			</tr>
			<tr id="row_image_url">
				<th><label for="ad_image_url"><?php esc_html_e( 'Image URL', 'govdata-ad-services' ); ?></label></th>
				<td>
					<input type="url" id="ad_image_url" name="ad_image_url" class="large-text"
						value="<?php echo esc_attr( esc_url( $val( 'govads_ad_image_url' ) ) ); ?>">
					<p class="description"><?php esc_html_e( 'Full URL to your ad image. Recommended: PNG or WebP at the selected IAB size.', 'govdata-ad-services' ); ?></p>
				</td>
			</tr>
			<tr id="row_html" style="display:none">
				<th><label for="ad_html"><?php esc_html_e( 'HTML Content', 'govdata-ad-services' ); ?></label></th>
				<td><textarea id="ad_html" name="ad_html" rows="6" class="large-text"><?php echo esc_textarea( $val( 'govads_ad_html' ) ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="ad_target_url"><?php esc_html_e( 'Destination URL', 'govdata-ad-services' ); ?> *</label></th>
				<td>
					<input type="url" id="ad_target_url" name="ad_target_url" class="large-text"
						value="<?php echo esc_attr( esc_url( $val( 'govads_ad_target_url' ) ) ); ?>" required>
					<p class="description"><?php esc_html_e( 'Where users land after clicking your ad.', 'govdata-ad-services' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="ad_size"><?php esc_html_e( 'Ad Size (IAB)', 'govdata-ad-services' ); ?></label></th>
				<td>
					<select id="ad_size" name="ad_size">
						<?php foreach ( $sizes as $sz ) : ?>
							<option value="<?php echo esc_attr( $sz ); ?>" <?php selected( $val( 'govads_ad_size' ) ?: '300x250', $sz ); ?>>
								<?php echo esc_html( $sz ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="ad_campaign_id"><?php esc_html_e( 'Campaign', 'govdata-ad-services' ); ?></label></th>
				<td>
					<select id="ad_campaign_id" name="ad_campaign_id">
						<option value="0"><?php esc_html_e( '— No campaign —', 'govdata-ad-services' ); ?></option>
						<?php foreach ( $my_campaigns as $c ) : ?>
							<option value="<?php echo esc_attr( (string) $c->ID ); ?>"
								<?php selected( (int) $val( 'govads_ad_campaign_id' ), $c->ID ); ?>>
								<?php echo esc_html( $c->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=govads-campaigns' ) ); ?>" style="margin-left:8px">
						<?php esc_html_e( '+ New Campaign', 'govdata-ad-services' ); ?>
					</a>
				</td>
			</tr>
			<tr>
				<th><label for="ad_start_date"><?php esc_html_e( 'Start Date', 'govdata-ad-services' ); ?></label></th>
				<td><input type="date" id="ad_start_date" name="ad_start_date" value="<?php echo esc_attr( $val( 'govads_ad_start_date' ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="ad_end_date"><?php esc_html_e( 'End Date', 'govdata-ad-services' ); ?></label></th>
				<td><input type="date" id="ad_end_date" name="ad_end_date" value="<?php echo esc_attr( $val( 'govads_ad_end_date' ) ); ?>"></td>
			</tr>
		</table>

		<?php if ( $edit_post ) :
			$status = (string) get_post_meta( $edit_post->ID, 'govads_ad_status', true ); ?>
		<p>
			<?php esc_html_e( 'Status:', 'govdata-ad-services' ); ?>
			<span class="govads-status <?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status ); ?></span>
		</p>
		<?php endif; ?>

		<?php submit_button( $edit_post ? __( 'Update Ad', 'govdata-ad-services' ) : __( 'Submit for Review', 'govdata-ad-services' ) ); ?>
	</form>
</div>
<script>
document.getElementById('ad_type').addEventListener('change', function() {
	document.getElementById('row_image_url').style.display = this.value === 'image' ? '' : 'none';
	document.getElementById('row_html').style.display = this.value === 'html5' ? '' : 'none';
});
document.getElementById('ad_type').dispatchEvent(new Event('change'));
</script>
