<?php
/**
 * Campaign list + create/edit form.
 *
 * @package GovAds
 */

defined( 'ABSPATH' ) || exit;

$uid      = get_current_user_id();
$is_admin = current_user_can( 'edit_others_posts' );

$campaigns = get_posts(
	[
		'post_type'      => 'govads_campaign',
		'post_status'    => 'any',
		'author'         => $is_admin ? 0 : $uid,
		'posts_per_page' => 50,
		'no_found_rows'  => true,
	]
);

// Edit mode.
$edit_id  = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security
$edit_post = $edit_id ? get_post( $edit_id ) : null;
if ( $edit_post && ( (int) $edit_post->post_author !== $uid && ! $is_admin ) ) {
	$edit_post = null;
}
?>
<div class="wrap govads-admin-wrap">
	<h1><?php esc_html_e( 'My Campaigns', 'govdata-ad-services' ); ?></h1>

	<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Campaign saved.', 'govdata-ad-services' ); ?></p></div>
	<?php endif; ?>

	<h2><?php echo $edit_post ? esc_html__( 'Edit Campaign', 'govdata-ad-services' ) : esc_html__( 'New Campaign', 'govdata-ad-services' ); ?></h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'govads_campaign_save' ); ?>
		<input type="hidden" name="action" value="govads_save_campaign">
		<input type="hidden" name="campaign_id" value="<?php echo $edit_post ? esc_attr( (string) $edit_post->ID ) : '0'; ?>">

		<table class="form-table">
			<tr>
				<th><label for="campaign_title"><?php esc_html_e( 'Campaign Name', 'govdata-ad-services' ); ?></label></th>
				<td><input type="text" id="campaign_title" name="campaign_title" class="regular-text"
					value="<?php echo $edit_post ? esc_attr( $edit_post->post_title ) : ''; ?>" required></td>
			</tr>
			<tr>
				<th><label for="campaign_start_date"><?php esc_html_e( 'Start Date', 'govdata-ad-services' ); ?></label></th>
				<td><input type="date" id="campaign_start_date" name="campaign_start_date"
					value="<?php echo $edit_post ? esc_attr( (string) get_post_meta( $edit_post->ID, 'govads_campaign_start_date', true ) ) : ''; ?>"></td>
			</tr>
			<tr>
				<th><label for="campaign_end_date"><?php esc_html_e( 'End Date', 'govdata-ad-services' ); ?></label></th>
				<td><input type="date" id="campaign_end_date" name="campaign_end_date"
					value="<?php echo $edit_post ? esc_attr( (string) get_post_meta( $edit_post->ID, 'govads_campaign_end_date', true ) ) : ''; ?>"></td>
			</tr>
		</table>
		<?php submit_button( $edit_post ? __( 'Update Campaign', 'govdata-ad-services' ) : __( 'Create Campaign', 'govdata-ad-services' ) ); ?>
	</form>

	<hr>
	<h2><?php esc_html_e( 'All Campaigns', 'govdata-ad-services' ); ?></h2>

	<?php if ( empty( $campaigns ) ) : ?>
		<p><?php esc_html_e( 'No campaigns yet.', 'govdata-ad-services' ); ?></p>
	<?php else : ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Start', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'End', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'govdata-ad-services' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $campaigns as $c ) : ?>
			<tr>
				<td><?php echo esc_html( $c->post_title ); ?></td>
				<td><?php echo esc_html( (string) get_post_meta( $c->ID, 'govads_campaign_start_date', true ) ); ?></td>
				<td><?php echo esc_html( (string) get_post_meta( $c->ID, 'govads_campaign_end_date', true ) ); ?></td>
				<td>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=govads-campaigns&edit=' . $c->ID ) ); ?>">
						<?php esc_html_e( 'Edit', 'govdata-ad-services' ); ?>
					</a>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</div>
