<?php
/**
 * Single Topic — PublicTech panel/message layout.
 *
 * Preserves bbPress's lead-topic + replies + reply-form flow but renders
 * everything inside the terminal's panel/grid chrome.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;
?>

<div id="bbpress-forums" class="bbpress-wrapper pt-thread-shell">

	<?php bbp_breadcrumb(); ?>

	<?php do_action( 'bbp_template_before_single_topic' ); ?>

	<?php if ( post_password_required() ) : ?>

		<?php bbp_get_template_part( 'form', 'protected' ); ?>

	<?php else : ?>

		<div class="panel corner pt-thread-head">
			<div class="hd">
				<h3>◇ <?php bbp_topic_title(); ?></h3>
				<span class="meta">
					<?php bbp_topic_tag_list(); ?>
				</span>
			</div>
			<div class="bd">
				<?php bbp_single_topic_description(); ?>
			</div>
		</div>

		<?php if ( bbp_show_lead_topic() ) : ?>
			<?php bbp_get_template_part( 'content', 'single-topic-lead' ); ?>
		<?php endif; ?>

		<?php if ( bbp_has_replies() ) : ?>
			<?php bbp_get_template_part( 'pagination', 'replies' ); ?>
			<div class="pt-reply-list">
				<?php bbp_get_template_part( 'loop', 'replies' ); ?>
			</div>
			<?php bbp_get_template_part( 'pagination', 'replies' ); ?>
		<?php endif; ?>

		<?php bbp_get_template_part( 'form', 'reply' ); ?>

	<?php endif; ?>

	<?php bbp_get_template_part( 'alert', 'topic-lock' ); ?>

	<?php do_action( 'bbp_template_after_single_topic' ); ?>

</div>
