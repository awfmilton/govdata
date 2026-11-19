<?php
/**
 * Template Name: Deleted Government Records
 *
 * Every grant and contribution record the Government of Canada deleted from its published data,
 * preserved as last published, with the notice for each. See PublicTech\Core\Deleted_Records.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$pt_deleted_rows  = \PublicTech\Core\Deleted_Records::all();
$pt_deleted_title = __( 'Grant records deleted by the Government of Canada', 'publictech-terminal' );
$pt_deleted_intro = __( 'These records appeared in the Government of Canada\'s published grants and contributions data and were later removed without an amendment or correction. Each is preserved exactly as it was last published. We do not know why they were deleted; each requires further investigation.', 'publictech-terminal' );

get_header();
?>
<main class="wrap" style="padding-top:18px">
	<h1 style="margin:0 0 6px"><?php the_title(); ?></h1>
	<?php while ( have_posts() ) : the_post(); the_content(); endwhile; ?>
	<?php if ( ! $pt_deleted_rows ) : ?>
		<p><?php esc_html_e( 'No deleted records are currently on file.', 'publictech-terminal' ); ?></p>
	<?php else : ?>
		<?php
		$pt_by_dept = [];
		foreach ( $pt_deleted_rows as $r ) {
			$d = \PublicTech\Core\one_language( (string) $r['owner_org_en'] ) ?: __( 'Unknown department', 'publictech-terminal' );
			$pt_by_dept[ $d ]['n']   = ( $pt_by_dept[ $d ]['n'] ?? 0 ) + 1;
			$pt_by_dept[ $d ]['sum'] = ( $pt_by_dept[ $d ]['sum'] ?? 0 ) + (float) $r['agreement_value'];
		}
		uasort( $pt_by_dept, static fn( $a, $b ) => $b['sum'] <=> $a['sum'] );
		?>
		<section class="panel corner" style="margin-top:12px">
			<div class="hd"><h3>◇ <?php esc_html_e( 'By department', 'publictech-terminal' ); ?></h3></div>
			<table class="stream"><thead><tr><th><?php esc_html_e( 'Department', 'publictech-terminal' ); ?></th><th class="num"><?php esc_html_e( 'Records', 'publictech-terminal' ); ?></th><th class="num"><?php esc_html_e( 'Value', 'publictech-terminal' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $pt_by_dept as $d => $v ) : ?>
				<tr><td><?php echo esc_html( $d ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $v['n'] ) ); ?></td><td class="num">$<?php echo esc_html( number_format_i18n( $v['sum'], 2 ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		</section>
		<?php require PUBLICTECH_TERMINAL_DIR . 'parts/deleted-records.php'; ?>
	<?php endif; ?>
</main>
<?php
get_footer();
