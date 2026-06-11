<?php
/**
 * Raw chart render — headless capture surface for the snapshot service.
 *
 * Served when a single saved chart is requested with ?pt_raw_render=1
 * (template_include swap in functions.php). Deliberately skips get_header()
 * / get_footer() / wp_body_open() so the 1200x630 screenshot contains the
 * chart card and nothing else: no header rail, no nav, no footer, no
 * customizer window. wp_head()/wp_footer() still run so the theme CSS,
 * Billboard and the bridge (which freezes transitions when it sees the
 * pt-raw-render body class) load normally. Core emits noindex for this
 * variant — it is a rendering target, not a page.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=<?php echo (int) \PublicTech\Core\Chart_Snapshots::WIDTH; ?>" />
<?php wp_head(); ?>
<style>
	html, body {
		margin: 0;
		padding: 0;
		overflow: hidden;
		background: var(--bg0, #0b0e11);
	}
	.pt-raw-card {
		box-sizing: border-box;
		width: <?php echo (int) \PublicTech\Core\Chart_Snapshots::WIDTH; ?>px;
		height: <?php echo (int) \PublicTech\Core\Chart_Snapshots::HEIGHT; ?>px;
		display: flex;
		flex-direction: column;
		gap: 14px;
		padding: 30px 40px 34px;
	}
	.pt-raw-head .kicker {
		color: var(--accent, #00a0d2);
		font-size: 11px;
		letter-spacing: 0.22em;
		text-transform: uppercase;
	}
	.pt-raw-head h1 {
		margin: 8px 0 0;
		font-size: 30px;
		line-height: 1.2;
		color: var(--ink0, #e8edf2);
		/* Long titles must never push the chart off the card. */
		display: -webkit-box;
		-webkit-line-clamp: 2;
		-webkit-box-orient: vertical;
		overflow: hidden;
	}
	.pt-raw-chart {
		flex: 1 1 auto;
		min-height: 0;
	}
</style>
</head>
<body <?php body_class( 'pt-raw-render' ); ?>>
<?php while ( have_posts() ) : the_post(); ?>
	<div class="pt-raw-card">
		<header class="pt-raw-head">
			<div class="mono uc kicker">◇ <?php echo esc_html( get_bloginfo( 'name' ) ); ?> · <?php esc_html_e( 'Saved Chart', 'publictech-terminal' ); ?></div>
			<h1><?php the_title(); ?></h1>
		</header>
		<div class="pt-raw-chart pt-billboard-mount" data-pt-chart-id="<?php echo (int) get_the_ID(); ?>"></div>
	</div>
<?php endwhile; ?>
<?php wp_footer(); ?>
</body>
</html>
