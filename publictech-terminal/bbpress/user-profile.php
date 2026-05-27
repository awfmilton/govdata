<?php
/**
 * User Profile Body — PT panel + `.kvs` layout.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

do_action( 'bbp_template_before_user_profile' );

$pt_uid    = (int) bbp_get_displayed_user_id();
$pt_url    = (string) bbp_get_displayed_user_field( 'user_url' );
$pt_desc   = (string) bbp_get_displayed_user_field( 'description', 'display' );
$pt_joined = (string) get_the_author_meta( 'user_registered', $pt_uid );
?>
<div id="bbp-user-profile">
	<div class="panel corner" style="margin-bottom:14px">
		<div class="hd"><h3>◇ <?php esc_html_e( 'Profile', 'publictech-terminal' ); ?></h3></div>
		<div class="kvs">
			<div class="kv"><span class="k"><?php esc_html_e( 'Name', 'publictech-terminal' ); ?></span><span class="v"><?php bbp_displayed_user_field( 'display_name' ); ?></span></div>
			<?php if ( $pt_url ) : ?>
				<div class="kv"><span class="k"><?php esc_html_e( 'Website', 'publictech-terminal' ); ?></span><span class="v"><a href="<?php echo esc_url( $pt_url ); ?>" rel="me nofollow"><?php echo esc_html( $pt_url ); ?></a></span></div>
			<?php endif; ?>
			<?php if ( $pt_joined ) : ?>
				<div class="kv"><span class="k"><?php esc_html_e( 'Member Since', 'publictech-terminal' ); ?></span><span class="v"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $pt_joined ) ); ?></span></div>
			<?php endif; ?>
			<div class="kv"><span class="k"><?php esc_html_e( 'Roles', 'publictech-terminal' ); ?></span><span class="v"><?php bbp_user_display_role(); ?></span></div>
		</div>
	</div>

	<?php if ( $pt_desc ) : ?>
		<div class="panel corner">
			<div class="hd"><h3>◇ <?php esc_html_e( 'About', 'publictech-terminal' ); ?></h3></div>
			<div class="bd"><?php echo wp_kses_post( wpautop( $pt_desc ) ); ?></div>
		</div>
	<?php endif; ?>
</div>

<?php do_action( 'bbp_template_after_user_profile' );
