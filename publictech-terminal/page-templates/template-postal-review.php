<?php
/**
 * Template Name: Postal Code Review
 *
 * Every postal code whose 2023 federal riding could not be confirmed with Elections Canada, with what our
 * check found and how confident it is (PublicTech\Core\Postal_Review). Readers can send corrections.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

get_header();

$pt_rows   = class_exists( \PublicTech\Core\Postal_Review::class ) ? \PublicTech\Core\Postal_Review::all() : [];
$pt_counts = class_exists( \PublicTech\Core\Postal_Review::class ) ? \PublicTech\Core\Postal_Review::counts( $pt_rows ) : [];
$pt_mapped = class_exists( \PublicTech\Core\Postal_Review::class ) ? \PublicTech\Core\Postal_Review::mapped_total() : 0;
?>
<main class="wrap" style="padding:18px 16px">
	<section class="panel corner" style="border-color:var(--warn)">
		<div class="hd"><h1 style="margin:0;font-size:20px"><?php esc_html_e( 'Uncertain postal codes', 'publictech-terminal' ); ?></h1></div>
		<?php if ( $pt_rows ) : ?>
			<p style="font-size:13px;color:var(--ink-2)">
				<?php
				printf(
					/* translators: 1: questionable codes, 2: all mapped codes, 3: high, 4: medium, 5: low */
					esc_html__( 'We match %2$s postal codes to the 2023 federal ridings. Elections Canada could not confirm the riding for %1$s of them, often because the code is new, rural, retired or split between ridings. We checked each one against public sources: %3$s were corrected with high confidence. The %4$s medium- and %5$s low-confidence codes keep their original riding until someone can confirm them.', 'publictech-terminal' ),
					esc_html( number_format_i18n( $pt_counts['total'] ) ),
					esc_html( number_format_i18n( $pt_mapped ) ),
					esc_html( number_format_i18n( $pt_counts['high'] ) ),
					esc_html( number_format_i18n( $pt_counts['medium'] ) ),
					esc_html( number_format_i18n( $pt_counts['low'] ) )
				);
				?>
			</p>
			<p style="font-size:13px">
				<?php esc_html_e( 'If you know which riding a postal code belongs to, we would be grateful for your help:', 'publictech-terminal' ); ?>
				<a href="<?php echo esc_url( \PublicTech\Core\Postal_Review::feedback_url() ); ?>"><?php esc_html_e( 'send us a message', 'publictech-terminal' ); ?></a>.
			</p>
			<div style="overflow-x:auto">
				<table class="pt-table" style="width:100%;font-size:12px">
					<thead><tr>
						<th><?php esc_html_e( 'Postal code', 'publictech-terminal' ); ?></th>
						<th><?php esc_html_e( 'Place', 'publictech-terminal' ); ?></th>
						<th><?php esc_html_e( 'Riding used now', 'publictech-terminal' ); ?></th>
						<th><?php esc_html_e( 'Our check found', 'publictech-terminal' ); ?></th>
						<th><?php esc_html_e( 'Confidence', 'publictech-terminal' ); ?></th>
						<th><?php esc_html_e( 'Why', 'publictech-terminal' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $pt_rows as $r ) :
						$used = $r['applied'] ? $r['checked_riding'] : $r['original_riding']; ?>
						<tr>
							<td><?php echo esc_html( $r['postal_code'] ); ?></td>
							<td><?php echo esc_html( $r['city'] ); ?></td>
							<td><?php echo esc_html( $used ); ?></td>
							<td><?php echo esc_html( $r['checked_riding'] ?: __( 'could not tell', 'publictech-terminal' ) ); ?></td>
							<td><?php echo esc_html( $r['confidence'] ); ?></td>
							<td><?php echo esc_html( $r['evidence'] ); ?></td>
							<td><a href="<?php echo esc_url( \PublicTech\Core\Postal_Review::feedback_url( $r['postal_code'] ) ); ?>"><?php esc_html_e( 'correct', 'publictech-terminal' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php else : ?>
			<p><?php esc_html_e( 'The list is not available right now. Please try again later.', 'publictech-terminal' ); ?></p>
		<?php endif; ?>
	</section>
</main>
<?php
get_footer();
