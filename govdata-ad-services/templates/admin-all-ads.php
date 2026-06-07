<?php
/**
 * Admin-only: pending ad approval queue.
 *
 * @package GovAds
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'edit_others_posts' ) ) {
	wp_die( esc_html__( 'Not allowed.', 'govdata-ad-services' ) );
}

// Handle inline approve/reject before rendering.
if ( isset( $_GET['govads_action'], $_GET['ad_id'], $_GET['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security
	$action = sanitize_key( (string) $_GET['govads_action'] ); // phpcs:ignore WordPress.Security
	$ad_id  = (int) $_GET['ad_id']; // phpcs:ignore WordPress.Security
	if ( wp_verify_nonce( sanitize_text_field( (string) $_GET['_wpnonce'] ), 'govads_action_' . $ad_id ) ) { // phpcs:ignore WordPress.Security
		if ( 'approve' === $action ) {
			update_post_meta( $ad_id, 'govads_ad_status', 'approved' );
			wp_update_post( [ 'ID' => $ad_id, 'post_status' => 'publish' ] );
			// Notify advertiser.
			$ad    = get_post( $ad_id );
			$owner = $ad ? get_user_by( 'id', (int) $ad->post_author ) : null;
			if ( $owner ) {
				wp_mail(
					$owner->user_email,
					__( 'Your ad has been approved — GovData Ad Services', 'govdata-ad-services' ),
					/* translators: %s: ad title */
					sprintf( __( 'Your ad "%s" is now live.', 'govdata-ad-services' ), get_the_title( $ad_id ) )
				);
			}
		} elseif ( 'reject' === $action ) {
			update_post_meta( $ad_id, 'govads_ad_status', 'rejected' );
			wp_update_post( [ 'ID' => $ad_id, 'post_status' => 'draft' ] );
		}
		// Bust pool cache.
		foreach ( get_posts( [ 'post_type' => 'govads_zone', 'post_status' => 'publish', 'posts_per_page' => 100, 'fields' => 'ids', 'no_found_rows' => true ] ) as $zid ) {
			delete_transient( 'govads_pool_' . $zid );
		}
	}
	wp_redirect( admin_url( 'admin.php?page=govads-all-ads&done=1' ) );
	exit;
}

$all_ads = get_posts(
	[
		'post_type'      => 'govads_ad',
		'post_status'    => 'any',
		'posts_per_page' => 100,
		'no_found_rows'  => true,
		'orderby'        => 'date',
		'order'          => 'DESC',
	]
);
?>
<div class="wrap govads-admin-wrap">
	<h1><?php esc_html_e( 'All Ads', 'govdata-ad-services' ); ?></h1>

	<?php if ( isset( $_GET['done'] ) ) : // phpcs:ignore WordPress.Security ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Action completed.', 'govdata-ad-services' ); ?></p></div>
	<?php endif; ?>

	<?php if ( empty( $all_ads ) ) : ?>
		<p><?php esc_html_e( 'No ads submitted yet.', 'govdata-ad-services' ); ?></p>
	<?php else : ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Ad', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Advertiser', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Size', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Status', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Submitted', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'govdata-ad-services' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $all_ads as $ad ) :
				$status    = (string) get_post_meta( $ad->ID, 'govads_ad_status', true );
				$img       = (string) get_post_meta( $ad->ID, 'govads_ad_image_url', true );
				$size      = (string) get_post_meta( $ad->ID, 'govads_ad_size', true );
				$author    = get_user_by( 'id', (int) $ad->post_author );
				$nonce_app = wp_create_nonce( 'govads_action_' . $ad->ID );
			?>
			<tr>
				<td>
					<?php if ( $img ) : ?>
						<img class="govads-thumb" src="<?php echo esc_url( $img ); ?>" alt="">
					<?php endif; ?>
					<br><strong><?php echo esc_html( $ad->post_title ); ?></strong><br>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=govads-submit&edit=' . $ad->ID ) ); ?>">
						<?php esc_html_e( 'View', 'govdata-ad-services' ); ?>
					</a>
				</td>
				<td><?php echo $author ? esc_html( $author->display_name ) : '—'; ?></td>
				<td><?php echo esc_html( $size ); ?></td>
				<td><span class="govads-status <?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status ); ?></span></td>
				<td><?php echo esc_html( get_the_date( 'Y-m-d', $ad ) ); ?></td>
				<td>
					<?php if ( 'approved' !== $status ) : ?>
					<a class="button button-primary button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=govads-all-ads&govads_action=approve&ad_id=' . $ad->ID . '&_wpnonce=' . $nonce_app ) ); ?>">
						<?php esc_html_e( 'Approve', 'govdata-ad-services' ); ?>
					</a>
					<?php endif; ?>
					<?php if ( 'rejected' !== $status ) : ?>
					<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=govads-all-ads&govads_action=reject&ad_id=' . $ad->ID . '&_wpnonce=' . $nonce_app ) ); ?>"
						onclick="return confirm('<?php echo esc_js( __( 'Reject this ad?', 'govdata-ad-services' ) ); ?>')">
						<?php esc_html_e( 'Reject', 'govdata-ad-services' ); ?>
					</a>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</div>
