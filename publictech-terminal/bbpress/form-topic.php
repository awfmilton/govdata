<?php
/**
 * New / Edit Topic — wrapped in a PT `.panel` so the form inherits the
 * terminal chrome. The body of the form intentionally delegates to bbPress
 * helpers (`bbp_the_content`, allowed-tags, anonymous) so role/cap checks,
 * nonces and edit-vs-new logic stay identical to upstream — the CSS bridge
 * (assets/css/bbpress.css) handles the input styling.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

if ( ! bbp_current_user_can_access_create_topic_form() && ! bbp_is_topic_edit() ) {
	return;
}
?>
<div id="new-topic-<?php bbp_topic_id(); ?>" class="bbp-topic-form panel corner pt-form">
	<div class="hd">
		<h3>◇ <?php echo bbp_is_topic_edit() ? esc_html__( 'Edit Topic', 'publictech-terminal' ) : esc_html__( 'New Topic', 'publictech-terminal' ); ?></h3>
	</div>
	<div class="bd">
		<form id="new-post" name="new-post" method="post">
			<?php do_action( 'bbp_theme_before_topic_form' ); ?>

			<?php if ( ! bbp_is_topic_edit() && bbp_is_forum_closed() ) : ?>
				<div class="bbp-template-notice pt-error">
					<?php esc_html_e( 'This forum is closed to new topics.', 'publictech-terminal' ); ?>
				</div>
			<?php endif; ?>

			<?php do_action( 'bbp_template_notices' ); ?>

			<div class="pt-form-row">
				<label for="bbp_topic_title"><?php esc_html_e( 'Title', 'publictech-terminal' ); ?></label>
				<input type="text" id="bbp_topic_title" name="bbp_topic_title" maxlength="80" size="40"
					value="<?php bbp_form_topic_title(); ?>" />
			</div>

			<div class="pt-form-row">
				<label><?php esc_html_e( 'Body', 'publictech-terminal' ); ?></label>
				<?php bbp_get_template_part( 'form', 'anonymous' ); ?>
				<?php bbp_the_content( [ 'context' => 'topic' ] ); ?>
				<?php bbp_get_template_part( 'form', 'allowed-tags' ); ?>
			</div>

			<?php if ( bbp_allow_topic_tags() && current_user_can( 'assign_topic_tags' ) ) : ?>
				<div class="pt-form-row">
					<label for="bbp_topic_tags"><?php esc_html_e( 'Tags', 'publictech-terminal' ); ?></label>
					<input type="text" id="bbp_topic_tags" name="bbp_topic_tags" size="40"
						value="<?php bbp_form_topic_tags(); ?>" />
				</div>
			<?php endif; ?>

			<?php if ( ! bbp_is_single_forum() ) : ?>
				<div class="pt-form-row">
					<label for="bbp_forum_id"><?php esc_html_e( 'Forum', 'publictech-terminal' ); ?></label>
					<?php bbp_dropdown( [ 'selected' => bbp_get_form_topic_forum() ] ); ?>
				</div>
			<?php endif; ?>

			<?php if ( bbp_is_subscriptions_active() && ! bbp_is_anonymous() ) : ?>
				<div class="pt-form-row pt-form-row-inline">
					<label class="sw">
						<input name="bbp_topic_subscription" type="checkbox" value="bbp_subscribe"<?php bbp_form_topic_subscribed(); ?> />
						<span class="slot"></span>
					</label>
					<span><?php esc_html_e( 'Subscribe to this topic', 'publictech-terminal' ); ?></span>
				</div>
			<?php endif; ?>

			<div class="pt-form-actions">
				<button type="submit" class="cta" id="bbp_topic_submit" name="bbp_topic_submit" tabindex="<?php bbp_tab_index(); ?>">
					<?php echo bbp_is_topic_edit() ? esc_html__( 'Save Changes', 'publictech-terminal' ) : esc_html__( 'Post Topic', 'publictech-terminal' ); ?>
				</button>
			</div>

			<?php bbp_topic_form_fields(); ?>
			<?php do_action( 'bbp_theme_after_topic_form' ); ?>
		</form>
	</div>
</div>
