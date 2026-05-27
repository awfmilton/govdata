<?php
/**
 * bbPress Topics Loop — PublicTech terminal layout.
 *
 * Renders bbPress's `bbp_topics` loop using our `.thread` row design from
 * publictech-terminal/page-templates/template-forum.php. Drops bbPress's
 * <ul>/<li>-table-with-headers in favour of stacked anchor rows that match
 * the design language of the rest of the audit terminal.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

do_action( 'bbp_template_before_topics_loop' );
?>

<div id="bbp-forum-<?php bbp_forum_id(); ?>" class="bbp-topics pt-thread-list">
	<?php while ( bbp_topics() ) :
		bbp_the_topic();
		bbp_get_template_part( 'loop', 'single-topic' );
	endwhile; ?>
</div>

<?php do_action( 'bbp_template_after_topics_loop' );
