<?php
/**
 * @package PublicTech\Terminal
 */
defined( 'ABSPATH' ) || exit;
?>
<footer class="pt-footer">
	<?php do_action( 'govads_zone', 'global_footer' ); ?>
	<div class="rail">
		<div class="l">
			<span>© <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?></span>
			<?php wp_nav_menu( [ 'theme_location' => 'utility', 'container' => false, 'fallback_cb' => '__return_empty_string' ] ); ?>
		</div>
		<div class="r">
			<span><?php esc_html_e( 'PublicTech Terminal · v', 'publictech-terminal' ); echo esc_html( PUBLICTECH_TERMINAL_VERSION ); ?></span>
		</div>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
