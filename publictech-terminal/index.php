<?php
/**
 * @package PublicTech\Terminal
 */
defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="pt-main" tabindex="-1" class="pt-shell">
	<?php
	if ( have_posts() ) :
		while ( have_posts() ) :
			the_post();
			?>
			<article <?php post_class( 'panel corner' ); ?>>
				<div class="hd"><h3>◇ <?php the_title(); ?></h3></div>
				<div class="bd"><?php the_content(); ?></div>
			</article>
			<?php
		endwhile;
		the_posts_pagination();
	else :
		?>
		<div class="pt-error"><?php esc_html_e( 'Nothing here yet.', 'publictech-terminal' ); ?></div>
		<?php
	endif;
	?>
</main>
<?php
get_footer();
