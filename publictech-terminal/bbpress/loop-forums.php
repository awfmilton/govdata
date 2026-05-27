<?php
/**
 * bbPress Forums Loop — PublicTech `.cat-item` category list.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

do_action( 'bbp_template_before_forums_loop' ); ?>

<div class="panel corner pt-forum-cats">
	<div class="hd">
		<h3>◇ <?php esc_html_e( 'Forums', 'publictech-terminal' ); ?></h3>
		<span class="meta"><?php echo esc_html( number_format_i18n( bbp_get_forum_count() ) ); ?></span>
	</div>
	<div class="cat-list">
		<?php while ( bbp_forums() ) :
			bbp_the_forum();
			$pt_fid    = bbp_get_forum_id();
			$pt_tcount = (int) bbp_get_forum_topic_count( $pt_fid );
			?>
			<a class="cat-item" href="<?php bbp_forum_permalink( $pt_fid ); ?>">
				<span><span class="ico">◇</span><?php bbp_forum_title( $pt_fid ); ?></span>
				<span class="n"><?php echo esc_html( number_format_i18n( $pt_tcount ) ); ?></span>
			</a>
		<?php endwhile; ?>
	</div>
</div>

<?php do_action( 'bbp_template_after_forums_loop' );
