<?php
/**
 * Single Topic Lead Post — the original post, rendered as the same `.pt-reply`
 * card the replies use so the conversation reads visually consistent.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$pt_topic_id    = bbp_get_topic_id();
$pt_author_name = bbp_get_topic_author_display_name( $pt_topic_id );
$pt_initials    = strtoupper( substr( wp_strip_all_tags( (string) $pt_author_name ), 0, 2 ) );
?>
<article id="post-<?php echo (int) $pt_topic_id; ?>" <?php bbp_topic_class( [ 'panel', 'pt-reply', 'pt-reply-lead' ] ); ?>>
	<aside class="pt-reply-rail">
		<div class="av"><?php echo esc_html( $pt_initials ?: '··' ); ?></div>
		<div class="who">
			<div class="name"><?php bbp_topic_author_link( [ 'show_role' => false, 'type' => 'name', 'size' => 36 ] ); ?></div>
			<div class="role"><?php bbp_topic_author_role(); ?></div>
		</div>
	</aside>
	<div class="pt-reply-main">
		<header class="pt-reply-head">
			<span class="when"><?php bbp_topic_post_date(); ?></span>
			<a class="permalink" href="<?php bbp_topic_permalink(); ?>">#<?php echo (int) $pt_topic_id; ?></a>
			<span class="actions"><?php bbp_topic_admin_links(); ?></span>
		</header>
		<div class="pt-reply-body">
			<?php do_action( 'bbp_theme_before_topic_content' ); ?>
			<?php bbp_topic_content(); ?>
			<?php do_action( 'bbp_theme_after_topic_content' ); ?>
		</div>
	</div>
</article>
