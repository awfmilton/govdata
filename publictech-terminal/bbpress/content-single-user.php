<?php
/**
 * Single User Profile — PublicTech `.user-grid` shell.
 *
 * Replaces bbPress's stacked layout with the same two-column user-hero +
 * panel-stack used by template-profile.php.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="bbpress-forums" class="bbpress-wrapper pt-user-shell">
	<?php do_action( 'bbp_template_notices' ); ?>
	<?php do_action( 'bbp_template_before_user_wrapper' ); ?>

	<div id="bbp-user-wrapper" class="user-grid">
		<aside class="pt-user-side">
			<?php bbp_get_template_part( 'user', 'details' ); ?>
		</aside>

		<div id="bbp-user-body" class="pt-user-body">
			<?php
			if ( bbp_is_favorites() )                 : bbp_get_template_part( 'user', 'favorites' );        endif;
			if ( bbp_is_subscriptions() )             : bbp_get_template_part( 'user', 'subscriptions' );    endif;
			if ( bbp_is_single_user_engagements() )   : bbp_get_template_part( 'user', 'engagements' );      endif;
			if ( bbp_is_single_user_topics() )        : bbp_get_template_part( 'user', 'topics-created' );   endif;
			if ( bbp_is_single_user_replies() )       : bbp_get_template_part( 'user', 'replies-created' );  endif;
			if ( bbp_is_single_user_edit() )          : bbp_get_template_part( 'form', 'user-edit' );        endif;
			if ( bbp_is_single_user_profile() )       : bbp_get_template_part( 'user', 'profile' );          endif;
			?>
		</div>
	</div>

	<?php do_action( 'bbp_template_after_user_wrapper' ); ?>
</div>
