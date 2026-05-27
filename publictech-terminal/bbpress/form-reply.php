<?php
/**
 * New / Edit Reply — PT panel wrapper.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

if ( ! bbp_current_user_can_access_create_reply_form() ) {
	return;
}
?>
<div id="new-reply-<?php bbp_topic_id(); ?>" class="bbp-reply-form panel corner pt-form">
	<div class="hd">
		<h3>◇ <?php echo bbp_is_reply_edit() ? esc_html__( 'Edit Reply', 'publictech-terminal' ) : esc_html__( 'Reply', 'publictech-terminal' ); ?></h3>
		<span class="meta"><?php bbp_topic_title(); ?></span>
	</div>
	<div class="bd">
		<form id="new-post" name="new-post" method="post">
			<?php do_action( 'bbp_theme_before_reply_form' ); ?>

			<?php if ( ! bbp_is_topic_open() && ! bbp_is_reply_edit() ) : ?>
				<div class="pt-error"><?php esc_html_e( 'This topic is closed to new replies.', 'publictech-terminal' ); ?></div>
			<?php endif; ?>

			<?php do_action( 'bbp_template_notices' ); ?>

			<?php bbp_get_template_part( 'form', 'anonymous' ); ?>

			<div class="pt-form-row">
				<?php bbp_the_content( [ 'context' => 'reply' ] ); ?>
				<?php bbp_get_template_part( 'form', 'allowed-tags' ); ?>
			</div>

			<?php if ( bbp_is_subscriptions_active() && ! bbp_is_anonymous() ) : ?>
				<div class="pt-form-row pt-form-row-inline">
					<label class="sw">
						<input name="bbp_topic_subscription" type="checkbox" value="bbp_subscribe"<?php bbp_form_topic_subscribed(); ?> />
						<span class="slot"></span>
					</label>
					<span><?php esc_html_e( 'Notify me of follow-up replies', 'publictech-terminal' ); ?></span>
				</div>
			<?php endif; ?>

			<div class="pt-form-actions">
				<button type="submit" class="cta" id="bbp_reply_submit" name="bbp_reply_submit" tabindex="<?php bbp_tab_index(); ?>">
					<?php echo bbp_is_reply_edit() ? esc_html__( 'Save Changes', 'publictech-terminal' ) : esc_html__( 'Post Reply', 'publictech-terminal' ); ?>
				</button>
			</div>

			<?php bbp_reply_form_fields(); ?>
			<?php do_action( 'bbp_theme_after_reply_form' ); ?>
		</form>
	</div>
</div>
