<?php
/**
 * Template Name: PT — Chart Builder
 *
 * Three-column terminal panel layout. Left = chart type + data source picker,
 * centre = live Billboard preview, right = axis/series/legend controls + save
 * panel. Paid tiers (Analyst+) only — anonymous and free see the upgrade gate.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$pt_uid     = get_current_user_id() ?: null;
$pt_quota   = \PublicTech\Core\Access_Tiers::instance()->quota( $pt_uid );
$pt_can     = \PublicTech\Core\Chart_Spec::user_can_create( $pt_quota );
$pt_schemas = \PublicTech\Core\Multi_DB::instance()->aliases();

// Pre-fill from /ai or /stream entry points. We sanitise and pass through to JS.
$pt_from_ai     = isset( $_GET['fromAI'] )      ? sanitize_text_field( wp_unslash( (string) $_GET['fromAI'] ) )      : '';
$pt_from_stream = isset( $_GET['fromStream'] )  ? sanitize_text_field( wp_unslash( (string) $_GET['fromStream'] ) )  : '';
$pt_fork_of     = isset( $_GET['fork'] )        ? absint( $_GET['fork'] )                                            : 0;
$pt_edit_of     = isset( $_GET['edit'] )        ? absint( $_GET['edit'] )                                            : 0;

get_header(); ?>

<main id="pt-main" tabindex="-1">
	<section class="view active" data-pt-view="chart-builder">

		<?php if ( ! $pt_can ) : ?>
			<div class="panel corner pt-tier-locked" style="max-width:640px;margin:48px auto">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Chart Builder · Upgrade Required', 'publictech-terminal' ); ?></h3></div>
				<div class="bd" style="padding:20px;line-height:1.6">
					<?php esc_html_e( 'The chart builder is bundled with paid tiers. Upgrade to compose your own Billboard.js charts, save them privately, or publish them to the public gallery.', 'publictech-terminal' ); ?>
					<div style="margin-top:14px">
						<a class="cta" href="<?php echo esc_url( home_url( '/pricing' ) ); ?>"><?php esc_html_e( 'See plans →', 'publictech-terminal' ); ?></a>
						<a class="cta ghost" href="<?php echo esc_url( home_url( '/charts' ) ); ?>"><?php esc_html_e( 'Browse the gallery →', 'publictech-terminal' ); ?></a>
					</div>
				</div>
			</div>
		<?php else :
			require PUBLICTECH_TERMINAL_DIR . 'parts/chart-builder-ui.php';
		endif; ?>

	</section>
</main>

<script type="application/json" id="pt-builder-boot">
<?php echo wp_json_encode( [
	'fromAI'     => $pt_from_ai,
	'fromStream' => $pt_from_stream,
	'forkOf'     => $pt_fork_of,
	'editOf'     => $pt_edit_of,
	'schemas'    => array_values( $pt_schemas ),
] ); ?>
</script>

<?php get_footer();
