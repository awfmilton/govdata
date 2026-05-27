<?php
/**
 * Template Name: PT — Forum
 *
 * Categories sidebar + thread list + contributors panel. Works against the
 * forum-thread CPT registered by the parent theme. If bbPress is active it
 * will own its own templates and this one is unused.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$pt_active_cat = sanitize_title( get_query_var( 'forum_category', '' ) );
$pt_categories = get_terms( [ 'taxonomy' => 'forum_category', 'hide_empty' => false ] );
$pt_threads    = new WP_Query( [
	'post_type'      => 'forum-thread',
	'posts_per_page' => 25,
	'tax_query'      => $pt_active_cat ? [ [ 'taxonomy' => 'forum_category', 'field' => 'slug', 'terms' => [ $pt_active_cat ] ] ] : [],
] );

get_header(); ?>

<main>
	<section class="view active" data-pt-view="forum">
		<div class="forum-grid">
			<aside class="panel corner">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Categories', 'publictech-terminal' ); ?></h3><span class="meta"><?php echo esc_html( count( $pt_categories ) ); ?></span></div>
				<div class="cat-list">
					<a class="cat-item <?php echo $pt_active_cat ? '' : 'on'; ?>" href="<?php echo esc_url( get_post_type_archive_link( 'forum-thread' ) ); ?>">
						<span><span class="ico">⌬</span><?php esc_html_e( 'All Discussions', 'publictech-terminal' ); ?></span>
						<span class="n"><?php echo esc_html( wp_count_posts( 'forum-thread' )->publish ?? 0 ); ?></span>
					</a>
					<?php foreach ( $pt_categories as $cat ) : ?>
						<a class="cat-item <?php echo $pt_active_cat === $cat->slug ? 'on' : ''; ?>" href="<?php echo esc_url( get_term_link( $cat ) ); ?>">
							<span><span class="ico">◇</span><?php echo esc_html( $cat->name ); ?></span>
							<span class="n"><?php echo esc_html( $cat->count ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
			</aside>

			<div>
				<div class="forum-toolbar">
					<div class="filters">
						<button class="on">Hot</button>
						<button>Newest</button>
						<button>Most Replies</button>
						<button>Unanswered</button>
					</div>
					<?php if ( current_user_can( 'edit_posts' ) ) : ?>
						<a class="new-thread" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=forum-thread' ) ); ?>">+ <?php esc_html_e( 'New Thread', 'publictech-terminal' ); ?></a>
					<?php endif; ?>
				</div>

				<?php
				if ( $pt_threads->have_posts() ) :
					while ( $pt_threads->have_posts() ) :
						$pt_threads->the_post();
						$pt_terms = get_the_terms( get_the_ID(), 'forum_tag' ) ?: [];
						?>
						<a class="thread" href="<?php the_permalink(); ?>">
							<div class="av"><?php echo esc_html( strtoupper( substr( wp_strip_all_tags( get_the_author() ), 0, 2 ) ) ); ?></div>
							<div>
								<div class="ttl"><?php the_title(); ?></div>
								<div class="meta">
									<span><?php esc_html_e( 'by', 'publictech-terminal' ); ?> <b style="color:var(--ink-0)">@<?php echo esc_html( get_the_author_meta( 'user_login' ) ); ?></b></span>
									<?php if ( $pt_terms ) : ?>
										<div class="tags">
											<?php foreach ( $pt_terms as $t ) : ?>
												<span class="tag-mini"><?php echo esc_html( $t->name ); ?></span>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
								</div>
							</div>
							<div class="stats">
								<div class="col"><b><?php echo esc_html( get_comments_number() ); ?></b><div class="lab"><?php esc_html_e( 'Replies', 'publictech-terminal' ); ?></div></div>
							</div>
							<div class="last"><b><?php echo esc_html( human_time_diff( (int) get_post_modified_time( 'U', true ), time() ) ); ?></b></div>
						</a>
						<?php
					endwhile;
					wp_reset_postdata();
				else :
					?>
					<div class="thread"><div></div>
						<div><div class="ttl"><?php esc_html_e( 'No threads yet.', 'publictech-terminal' ); ?></div></div>
					</div>
				<?php endif; ?>

				<div class="stream-foot">
					<?php
					previous_posts_link( '‹' );
					next_posts_link( '›', $pt_threads->max_num_pages );
					?>
				</div>
			</div>

			<aside>
				<div class="panel corner contributors">
					<div class="hd"><h3>◇ <?php esc_html_e( 'Top Contributors', 'publictech-terminal' ); ?></h3><span class="meta">WEEKLY</span></div>
					<div>
						<?php
						$top = get_users( [ 'orderby' => 'post_count', 'order' => 'DESC', 'number' => 6 ] );
						foreach ( $top as $u ) : ?>
							<div class="row">
								<div class="av"><?php echo esc_html( strtoupper( substr( $u->display_name, 0, 2 ) ) ); ?></div>
								<div class="name"><?php echo esc_html( $u->display_name ); ?></div>
								<div class="karma">↑ <?php echo esc_html( count_user_posts( $u->ID, 'forum-thread' ) ); ?></div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="panel corner" style="margin-top:14px">
					<div class="hd"><h3>◇ <?php esc_html_e( 'Trending Tags', 'publictech-terminal' ); ?></h3><span class="meta">7d</span></div>
					<div class="tag-cloud">
						<?php
						$tags = get_terms( [ 'taxonomy' => 'forum_tag', 'orderby' => 'count', 'order' => 'DESC', 'number' => 18 ] );
						foreach ( $tags as $t ) :
							?>
							<a href="<?php echo esc_url( get_term_link( $t ) ); ?>">#<?php echo esc_html( $t->name ); ?></a>
						<?php endforeach; ?>
					</div>
				</div>
			</aside>
		</div>
	</section>
</main>

<?php get_footer();
