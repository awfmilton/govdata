<?php
/**
 * Search results loop — emits PT thread/reply rows per result type.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

do_action( 'bbp_template_before_search_results_loop' ); ?>

<div class="pt-search-results">
	<?php while ( bbp_search_results() ) :
		the_post();
		$pt_type = get_post_type();
		if ( 'topic' === $pt_type ) {
			bbp_get_template_part( 'loop', 'single-topic' );
		} elseif ( 'reply' === $pt_type ) {
			bbp_get_template_part( 'loop', 'single-reply' );
		} elseif ( 'forum' === $pt_type ) {
			bbp_get_template_part( 'loop', 'single-forum' );
		} else {
			bbp_get_template_part( 'loop', 'single-' . $pt_type );
		}
	endwhile; ?>
</div>

<?php do_action( 'bbp_template_after_search_results_loop' );
