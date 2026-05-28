<?php
/**
 * bbPress template wrapper.
 *
 * With add_theme_support('bbpress') declared in the parent theme, bbPress
 * routes all forum requests through this file rather than its compat layer.
 * Each view delegates to a template part in publictech-terminal/bbpress/,
 * which applies the terminal design language to every bbPress surface.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

get_header(); ?>

<main>
	<section class="view active" data-pt-view="forum">
		<?php
		do_action( 'bbp_before_main_content' );

		if ( bbp_is_search() ) :
			bbp_get_template_part( 'content', 'search' );

		elseif ( bbp_is_single_user() ) :
			bbp_get_template_part( 'content', 'single-user' );

		elseif ( bbp_is_single_topic() ) :
			bbp_get_template_part( 'content', 'single-topic' );

		elseif ( bbp_is_single_forum() ) :
			// Single forum → topic list (same template used for topic archive).
			if ( bbp_user_can_view_forum() ) {
				bbp_get_template_part( 'content', 'archive-topic' );
			} else {
				echo '<p class="pt-error">' . esc_html__( 'This forum is private.', 'publictech-terminal' ) . '</p>';
			}

		else :
			// Forum index — all top-level forums.
			bbp_get_template_part( 'content', 'archive-forum' );

		endif;

		do_action( 'bbp_after_main_content' );
		?>
	</section>
</main>

<?php get_footer();
