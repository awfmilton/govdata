<?php
/**
 * Forum archive — search bar + forum category list.
 *
 * Loaded by bbpress.php for the root /forum/ page (bbp_is_forum_archive()).
 * The #bbpress-forums id is required so bbpress.css rules apply.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="bbpress-forums" class="bbpress-wrapper pt-forum-archive">

	<?php bbp_breadcrumb(); ?>

	<?php do_action( 'bbp_template_notices' ); ?>

	<?php bbp_get_template_part( 'form', 'search' ); ?>

	<?php
	if ( bbp_has_forums() ) {
		bbp_get_template_part( 'loop', 'forums' );
	} else {
		bbp_get_template_part( 'feedback', 'no-forums' );
	}
	?>

</div>
