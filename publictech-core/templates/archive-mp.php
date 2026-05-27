<?php
/**
 * Fallback archive template for the member-parliament CPT. The child theme
 * overrides this with the full directory layout; this version exists so the
 * parent theme alone still produces a usable index.
 *
 * @package PublicTech\Core
 */

defined( 'ABSPATH' ) || exit;

get_header(); ?>
<main class="pt-archive-fallback">
	<header>
		<h1><?php post_type_archive_title(); ?></h1>
	</header>
	<div class="search-bar">
		<input id="globalSearch" placeholder="<?php esc_attr_e( 'Search members…', 'publictech-core' ); ?>" />
	</div>
	<div id="mpResultsMeta" class="pt-loading"></div>
	<div id="mpResults" class="results"></div>
</main>
<?php get_footer();
