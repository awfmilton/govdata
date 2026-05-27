<?php
/**
 * bbPress Single-Topic Row — PublicTech terminal `.thread` layout.
 *
 * One row per topic, mirroring the design used by template-forum.php so that
 * native PT forum threads and bbPress topics render visually identical.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$pt_topic_id     = bbp_get_topic_id();
$pt_author_id    = bbp_get_topic_author_id();
$pt_author_name  = bbp_get_topic_author_display_name( $pt_topic_id );
$pt_initials     = strtoupper( substr( wp_strip_all_tags( (string) $pt_author_name ), 0, 2 ) );
$pt_forum_id     = bbp_get_topic_forum_id( $pt_topic_id );
$pt_is_pinned    = bbp_is_topic_sticky( $pt_topic_id ) || bbp_is_topic_super_sticky( $pt_topic_id );
$pt_reply_count  = (int) bbp_get_topic_reply_count( $pt_topic_id );
$pt_voice_count  = (int) bbp_get_topic_voice_count( $pt_topic_id );
$pt_freshness_id = bbp_get_topic_last_active_id( $pt_topic_id );
$pt_tags         = get_the_terms( $pt_topic_id, bbp_get_topic_tag_tax_id() );
?>
<a id="bbp-topic-<?php echo (int) $pt_topic_id; ?>" class="thread<?php echo $pt_is_pinned ? ' pinned' : ''; ?>" href="<?php bbp_topic_permalink( $pt_topic_id ); ?>">
	<div class="av" style="<?php echo $pt_is_pinned ? 'color:var(--warn);border-color:var(--warn)' : ''; ?>">
		<?php echo $pt_is_pinned ? '⌃' : esc_html( $pt_initials ?: '··' ); ?>
	</div>
	<div>
		<div class="ttl"><?php echo $pt_is_pinned ? '📌 ' : ''; ?><?php bbp_topic_title( $pt_topic_id ); ?></div>
		<div class="meta">
			<?php if ( $pt_is_pinned ) : ?>
				<span class="pin-mark">[ <?php esc_html_e( 'PINNED · MOD', 'publictech-terminal' ); ?> ]</span>
			<?php endif; ?>
			<span><?php esc_html_e( 'by', 'publictech-terminal' ); ?>
				<b style="color:var(--ink-0)"><?php echo wp_kses( bbp_get_topic_author_link( [ 'show_role' => false, 'type' => 'name', 'size' => 14 ] ), [ 'a' => [ 'href' => [], 'class' => [], 'rel' => [] ], 'span' => [ 'class' => [] ] ] ); ?></b>
			</span>
			<?php if ( $pt_forum_id ) : ?>
				<span>· <?php esc_html_e( 'in', 'publictech-terminal' ); ?> <b style="color:var(--accent)"><a style="color:inherit" href="<?php bbp_forum_permalink( $pt_forum_id ); ?>"><?php bbp_forum_title( $pt_forum_id ); ?></a></b></span>
			<?php endif; ?>
			<?php if ( $pt_tags && ! is_wp_error( $pt_tags ) ) : ?>
				<div class="tags">
					<?php foreach ( $pt_tags as $pt_tag ) : ?>
						<span class="tag-mini"><?php echo esc_html( $pt_tag->name ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<div class="stats">
		<div class="col">
			<b><?php echo esc_html( number_format_i18n( $pt_reply_count ) ); ?></b>
			<div class="lab"><?php esc_html_e( 'Replies', 'publictech-terminal' ); ?></div>
		</div>
		<div class="col">
			<b><?php echo esc_html( number_format_i18n( $pt_voice_count ) ); ?></b>
			<div class="lab"><?php esc_html_e( 'Voices', 'publictech-terminal' ); ?></div>
		</div>
	</div>
	<div class="last">
		<b><?php echo wp_kses( bbp_get_author_link( [ 'post_id' => $pt_freshness_id, 'size' => 14, 'type' => 'name' ] ), [ 'a' => [ 'href' => [], 'class' => [] ] ] ); ?></b>
		<?php echo esc_html( bbp_get_topic_last_active_time( $pt_topic_id ) ); ?>
	</div>
</a>
