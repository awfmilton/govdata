<?php
/**
 * User Details — PublicTech `.user-hero` panel + `.settings-tabs` nav.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

do_action( 'bbp_template_before_user_details' );

$pt_uid     = (int) bbp_get_displayed_user_id();
$pt_name    = (string) bbp_get_displayed_user_field( 'display_name' );
$pt_initial = strtoupper( substr( wp_strip_all_tags( $pt_name ), 0, 2 ) );
$pt_email   = (string) bbp_get_displayed_user_field( 'user_email', 'raw' );
?>

<div id="bbp-single-user-details" class="user-hero corner">
	<div id="bbp-user-avatar" class="av">
		<a href="<?php bbp_user_profile_url(); ?>" rel="me">
			<?php echo $pt_initial ? esc_html( $pt_initial ) : get_avatar( $pt_email, 96 ); ?>
		</a>
	</div>
	<h2 class="uname"><?php echo esc_html( $pt_name ); ?></h2>
	<div class="handle">@<?php bbp_displayed_user_field( 'user_login' ); ?></div>
	<div class="role">
		<?php $pt_topics = (int) bbp_get_user_topic_count_raw( $pt_uid ); ?>
		<?php $pt_replies = (int) bbp_get_user_reply_count_raw( $pt_uid ); ?>
		<span class="badge"><?php echo esc_html( number_format_i18n( $pt_topics ) ); ?> topics</span>
		<span class="badge"><?php echo esc_html( number_format_i18n( $pt_replies ) ); ?> replies</span>
	</div>
</div>

<div id="bbp-user-navigation" class="panel">
	<div class="hd"><h3>◇ <?php esc_html_e( 'Navigation', 'publictech-terminal' ); ?></h3></div>
	<div class="cat-list">
		<a class="cat-item <?php echo bbp_is_single_user_profile() ? 'on' : ''; ?>" href="<?php bbp_user_profile_url(); ?>" rel="me">
			<span><span class="ico">◇</span><?php esc_html_e( 'Profile', 'publictech-terminal' ); ?></span>
		</a>
		<a class="cat-item <?php echo bbp_is_single_user_topics() ? 'on' : ''; ?>" href="<?php bbp_user_topics_created_url(); ?>">
			<span><span class="ico">≡</span><?php esc_html_e( 'Topics Started', 'publictech-terminal' ); ?></span>
		</a>
		<a class="cat-item <?php echo bbp_is_single_user_replies() ? 'on' : ''; ?>" href="<?php bbp_user_replies_created_url(); ?>">
			<span><span class="ico">↩</span><?php esc_html_e( 'Replies', 'publictech-terminal' ); ?></span>
		</a>
		<?php if ( bbp_is_engagements_active() ) : ?>
			<a class="cat-item <?php echo bbp_is_single_user_engagements() ? 'on' : ''; ?>" href="<?php bbp_user_engagements_url(); ?>">
				<span><span class="ico">◈</span><?php esc_html_e( 'Engagements', 'publictech-terminal' ); ?></span>
			</a>
		<?php endif; ?>
		<?php if ( bbp_is_favorites_active() ) : ?>
			<a class="cat-item <?php echo bbp_is_favorites() ? 'on' : ''; ?>" href="<?php bbp_favorites_permalink(); ?>">
				<span><span class="ico">★</span><?php esc_html_e( 'Favorites', 'publictech-terminal' ); ?></span>
			</a>
		<?php endif; ?>
		<?php if ( bbp_is_subscriptions_active() ) : ?>
			<a class="cat-item <?php echo bbp_is_subscriptions() ? 'on' : ''; ?>" href="<?php bbp_subscriptions_permalink(); ?>">
				<span><span class="ico">⌬</span><?php esc_html_e( 'Subscriptions', 'publictech-terminal' ); ?></span>
			</a>
		<?php endif; ?>
		<?php if ( bbp_current_user_can_view_edit_user_form() ) : ?>
			<a class="cat-item <?php echo bbp_is_single_user_edit() ? 'on' : ''; ?>" href="<?php bbp_user_edit_url(); ?>">
				<span><span class="ico">✎</span><?php esc_html_e( 'Edit', 'publictech-terminal' ); ?></span>
			</a>
		<?php endif; ?>
	</div>
</div>

<?php do_action( 'bbp_template_after_user_details' );
