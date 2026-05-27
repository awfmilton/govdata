<?php
/**
 * Single Reply — PublicTech reply card.
 *
 * Each reply becomes a `.pt-reply` panel with an author rail on the left
 * (avatar + handle + role) and the post body on the right.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$pt_reply_id    = bbp_get_reply_id();
$pt_author_id   = bbp_get_reply_author_id( $pt_reply_id );
$pt_author_name = bbp_get_reply_author_display_name( $pt_reply_id );
$pt_initials    = strtoupper( substr( wp_strip_all_tags( (string) $pt_author_name ), 0, 2 ) );
?>
<article id="post-<?php echo (int) $pt_reply_id; ?>" <?php bbp_reply_class( [ 'panel', 'pt-reply' ] ); ?>>
	<aside class="pt-reply-rail">
		<div class="av"><?php echo esc_html( $pt_initials ?: '··' ); ?></div>
		<div class="who">
			<div class="name"><?php bbp_reply_author_link( [ 'show_role' => false, 'type' => 'name', 'size' => 36 ] ); ?></div>
			<div class="role"><?php bbp_reply_author_role(); ?></div>
		</div>
		<?php if ( current_user_can( 'moderate', $pt_reply_id ) ) : ?>
			<div class="pt-reply-ip"><?php bbp_author_ip( $pt_reply_id ); ?></div>
		<?php endif; ?>
	</aside>

	<div class="pt-reply-main">
		<header class="pt-reply-head">
			<span class="when"><?php echo esc_html( bbp_get_reply_post_date( $pt_reply_id ) ); ?></span>
			<?php if ( bbp_is_single_user_replies() ) : ?>
				<span class="in"><?php esc_html_e( 'in reply to:', 'publictech-terminal' ); ?>
					<a class="bbp-topic-permalink" href="<?php bbp_topic_permalink( bbp_get_reply_topic_id() ); ?>"><?php bbp_topic_title( bbp_get_reply_topic_id() ); ?></a>
				</span>
			<?php endif; ?>
			<a class="permalink" href="<?php bbp_reply_url(); ?>">#<?php echo (int) $pt_reply_id; ?></a>
			<span class="actions"><?php bbp_reply_admin_links(); ?></span>
		</header>

		<div class="pt-reply-body">
			<?php do_action( 'bbp_theme_before_reply_content' ); ?>
			<?php bbp_reply_content(); ?>
			<?php do_action( 'bbp_theme_after_reply_content' ); ?>
		</div>
	</div>
</article>
