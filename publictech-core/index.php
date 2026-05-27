<?php
/**
 * Fallback template. The child theme overrides every front-end view; this file
 * exists so WordPress recognizes the parent as a valid theme.
 *
 * @package PublicTech\Core
 */

defined( 'ABSPATH' ) || exit;

get_header();

if ( have_posts() ) :
	while ( have_posts() ) :
		the_post();
		the_title( '<h1>', '</h1>' );
		the_content();
	endwhile;
endif;

get_footer();
