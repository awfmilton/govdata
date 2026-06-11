<?php
/**
 * Single saved chart — permalink target.
 *
 * Shows a large hydrated Billboard render plus owner-side controls
 * (Edit / Delete) and a Fork button for paid-tier viewers.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$pt_uid    = get_current_user_id() ?: null;
$pt_quota  = \PublicTech\Core\Access_Tiers::instance()->quota( $pt_uid );
$pt_can    = \PublicTech\Core\Chart_Spec::user_can_create( $pt_quota );

get_header(); ?>

<?php while ( have_posts() ) : the_post();
	$pt_id       = get_the_ID();
	$pt_is_owner = $pt_uid && (int) get_post_field( 'post_author', $pt_id ) === $pt_uid;
	$pt_kind     = wp_get_object_terms( $pt_id, 'chart_kind', [ 'fields' => 'names' ] );
	$pt_views_ct = (int) get_post_meta( $pt_id, 'pt_views', true );
	$pt_forks_ct = (int) get_post_meta( $pt_id, 'pt_forks', true );
?>
<main id="pt-main" tabindex="-1">
	<section class="view active" data-pt-view="chart-single">

		<header class="hero corner" style="margin-bottom:24px">
			<div class="hero-grid">
				<div>
					<div class="mono uc" style="color:var(--accent);font-size:10px;letter-spacing:0.2em;margin-bottom:10px">◇ <?php esc_html_e( 'Saved Chart', 'publictech-terminal' ); ?> <?php echo $pt_kind ? '· ' . esc_html( $pt_kind[0] ) : ''; ?></div>
					<h2><?php the_title(); ?></h2>
					<?php if ( get_the_excerpt() ) : ?>
						<p class="lede"><?php the_excerpt(); ?></p>
					<?php endif; ?>
					<div class="mono uc dim" style="font-size:10px;letter-spacing:0.18em;margin-top:8px">
						<?php
						/* translators: 1: author name, 2: created date, 3: views count, 4: forks count */
						printf(
							esc_html__( 'by %1$s · %2$s · %3$s views · %4$s forks', 'publictech-terminal' ),
							esc_html( get_the_author() ),
							esc_html( get_the_date() ),
							esc_html( number_format_i18n( $pt_views_ct ) ),
							esc_html( number_format_i18n( $pt_forks_ct ) )
						);
						?>
					</div>
				</div>
				<div class="ctas" style="align-self:center">
					<?php if ( $pt_is_owner ) : ?>
						<a class="cta" href="<?php echo esc_url( add_query_arg( 'edit', $pt_id, home_url( '/chart-builder' ) ) ); ?>">✎ <?php esc_html_e( 'Edit chart', 'publictech-terminal' ); ?></a>
					<?php elseif ( $pt_can ) : ?>
						<a class="cta" href="<?php echo esc_url( add_query_arg( 'fork', $pt_id, home_url( '/chart-builder' ) ) ); ?>">⑂ <?php esc_html_e( 'Fork to my account', 'publictech-terminal' ); ?></a>
					<?php endif; ?>
					<a class="cta ghost" href="<?php echo esc_url( home_url( '/charts' ) ); ?>">≡ <?php esc_html_e( 'Browse gallery', 'publictech-terminal' ); ?></a>
				</div>
			</div>
		</header>

		<div class="panel corner">
			<div class="pt-billboard-mount" data-pt-chart-id="<?php echo (int) $pt_id; ?>" style="height:520px"></div>
		</div>

		<?php
		// Social share targets: plain web intents around the permalink, so
		// they work with JS disabled (target=_blank); chart-share.js upgrades
		// them to centered popups and powers copy-link / native share.
		$pt_share_url  = (string) get_permalink( $pt_id );
		$pt_share_text = sprintf(
			/* translators: 1: chart title, 2: site name */
			__( '%1$s — %2$s', 'publictech-terminal' ),
			get_the_title(),
			get_bloginfo( 'name' )
		);
		$pt_intent_x  = 'https://x.com/intent/post?text=' . rawurlencode( $pt_share_text ) . '&url=' . rawurlencode( $pt_share_url );
		$pt_intent_fb = 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $pt_share_url );
		?>
		<div class="panel corner" style="margin-top:24px">
			<div class="hd"><h3>◇ <?php esc_html_e( 'Share this chart', 'publictech-terminal' ); ?></h3></div>
			<div class="bd">
				<div class="ctas pt-share-actions">
					<a class="cta" data-pt-share="popup" data-pt-popup="560x640" href="<?php echo esc_url( $pt_intent_x ); ?>" target="_blank" rel="noopener noreferrer">𝕏 <?php esc_html_e( 'Share to X', 'publictech-terminal' ); ?></a>
					<a class="cta" data-pt-share="popup" data-pt-popup="600x680" href="<?php echo esc_url( $pt_intent_fb ); ?>" target="_blank" rel="noopener noreferrer">ƒ <?php esc_html_e( 'Share to Facebook', 'publictech-terminal' ); ?></a>
					<button class="cta ghost" type="button" data-pt-share="copy" data-pt-share-url="<?php echo esc_attr( $pt_share_url ); ?>" data-pt-copied-text="<?php echo esc_attr__( 'Link copied to clipboard.', 'publictech-terminal' ); ?>">⧉ <?php esc_html_e( 'Copy link', 'publictech-terminal' ); ?></button>
					<button class="cta ghost" type="button" data-pt-share="native" data-pt-share-url="<?php echo esc_attr( $pt_share_url ); ?>" data-pt-share-title="<?php echo esc_attr( $pt_share_text ); ?>" hidden>⤴ <?php esc_html_e( 'More…', 'publictech-terminal' ); ?></button>
				</div>
				<p class="dim pt-share-status" role="status" aria-live="polite" style="margin-top:8px">
					<?php if ( 'publish' !== get_post_status( $pt_id ) ) : ?>
						<?php esc_html_e( 'This chart is private — only you can open the shared link.', 'publictech-terminal' ); ?>
					<?php endif; ?>
				</p>
			</div>
		</div>

		<?php if ( $pt_is_owner ) : ?>
			<div class="panel corner" style="margin-top:24px">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Embed this chart', 'publictech-terminal' ); ?></h3></div>
				<div class="bd">
					<code class="pt-embed-snippet">[publictech_chart id="<?php echo (int) $pt_id; ?>" height="320"]</code>
					<p class="dim" style="margin-top:8px"><?php esc_html_e( 'Paste this shortcode into any post or page to embed the chart.', 'publictech-terminal' ); ?></p>
				</div>
			</div>
		<?php endif; ?>

	</section>
</main>
<?php endwhile; ?>

<?php get_footer();
