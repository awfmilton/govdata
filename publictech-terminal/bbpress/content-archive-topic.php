<?php
/**
 * bbPress Topic Archive Content — wrapped in PT `.forum-grid` shell.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;
?>

<div id="bbpress-forums" class="bbpress-wrapper pt-forum-shell">

	<?php bbp_breadcrumb(); ?>

	<div class="forum-toolbar">
		<div class="filters">
			<button class="on"><?php esc_html_e( 'Hot',       'publictech-terminal' ); ?></button>
			<button><?php           esc_html_e( 'Newest',    'publictech-terminal' ); ?></button>
			<button><?php           esc_html_e( 'Replies',   'publictech-terminal' ); ?></button>
			<button><?php           esc_html_e( 'Following', 'publictech-terminal' ); ?></button>
		</div>
		<?php if ( ! bbp_is_single_view() && ( bbp_is_user_keymaster() || bbp_current_user_can_publish_topics() ) ) : ?>
			<a class="new-thread" href="#new-post">+ <?php esc_html_e( 'New Topic', 'publictech-terminal' ); ?></a>
		<?php endif; ?>
	</div>

	<?php
	if ( bbp_has_topics() ) :
		bbp_get_template_part( 'pagination', 'topics' );
		bbp_get_template_part( 'loop',       'topics' );
		bbp_get_template_part( 'pagination', 'topics' );
	else :
		bbp_get_template_part( 'feedback', 'no-topics' );
		bbp_get_template_part( 'form',     'topic' );
	endif;
	?>

</div>
