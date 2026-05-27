<?php
/**
 * Search results page — terminal panel shell.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="bbpress-forums" class="bbpress-wrapper pt-search-shell">

	<?php bbp_breadcrumb(); ?>

	<div class="panel corner" style="margin-bottom:14px">
		<div class="hd">
			<h3>◇ <?php
				/* translators: %s: search terms */
				printf( esc_html__( 'Search results for %s', 'publictech-terminal' ), '<span style="color:var(--accent)">' . esc_html( bbp_get_search_terms() ) . '</span>' );
			?></h3>
		</div>
		<div class="bd"><?php bbp_get_template_part( 'form', 'search' ); ?></div>
	</div>

	<?php if ( bbp_has_search_results() ) :
		bbp_get_template_part( 'pagination', 'search' );
		bbp_get_template_part( 'loop',       'search' );
		bbp_get_template_part( 'pagination', 'search' );
	elseif ( bbp_get_search_terms() ) :
		bbp_get_template_part( 'feedback',   'no-search' );
	endif; ?>

</div>
