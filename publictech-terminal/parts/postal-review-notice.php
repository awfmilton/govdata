<?php
/**
 * Notice: some postal codes in this riding have a questionable riding assignment (PublicTech\Core\Postal_Review).
 *
 * Expects:
 *   $pt_review_riding  string 5-digit riding number
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \PublicTech\Core\Postal_Review::class ) ) {
	return;
}
$pt_review_rows = \PublicTech\Core\Postal_Review::for_riding( (string) ( $pt_review_riding ?? '' ) );
if ( ! $pt_review_rows ) {
	return;
}
$pt_review_all  = \PublicTech\Core\Postal_Review::counts();
$pt_review_here = \PublicTech\Core\Postal_Review::counts( $pt_review_rows );
$pt_review_page = home_url( '/postal-code-review/' );
?>
<section class="panel corner pt-postal-review" id="postal-review" style="margin-top:14px;margin-bottom:14px;border-color:var(--warn)">
	<div class="hd">
		<h3>⚠ <?php esc_html_e( 'Some postal codes in this riding are uncertain', 'publictech-terminal' ); ?></h3>
		<span class="meta">
			<?php
			/* translators: %s: number of postal codes */
			printf( esc_html( _n( '%s code here', '%s codes here', $pt_review_here['total'], 'publictech-terminal' ) ), esc_html( number_format_i18n( $pt_review_here['total'] ) ) );
			?>
		</span>
	</div>
	<p style="font-size:12px;color:var(--ink-2);margin:8px 0 0">
		<?php
		printf(
			/* translators: 1: questionable codes, 2: all mapped codes, 3: high, 4: medium, 5: low */
			esc_html__( '%1$s of the %2$s postal codes we match to federal ridings could not be confirmed with Elections Canada. We checked each one: %3$s were corrected with high confidence; %4$s (medium confidence) and %5$s (low confidence) keep their original riding until they can be confirmed. Grants to recipients with these postal codes may be counted in the wrong riding.', 'publictech-terminal' ),
			esc_html( number_format_i18n( $pt_review_all['total'] ) ),
			esc_html( number_format_i18n( \PublicTech\Core\Postal_Review::mapped_total() ) ),
			esc_html( number_format_i18n( $pt_review_all['high'] ) ),
			esc_html( number_format_i18n( $pt_review_all['medium'] ) ),
			esc_html( number_format_i18n( $pt_review_all['low'] ) )
		);
		?>
	</p>
	<p style="font-size:12px;margin:8px 0 0">
		<?php
		$pt_codes = array_map( static fn( $r ) => $r['postal_code'] . ' (' . $r['confidence'] . ')', $pt_review_rows );
		echo esc_html__( 'In or near this riding:', 'publictech-terminal' ) . ' ' . esc_html( implode( ', ', $pt_codes ) );
		?>
	</p>
	<p style="font-size:12px;margin:8px 0 0">
		<?php esc_html_e( 'Know which riding one of these belongs to? Help us fix it:', 'publictech-terminal' ); ?>
		<a href="<?php echo esc_url( \PublicTech\Core\Postal_Review::feedback_url() ); ?>"><?php esc_html_e( 'send us a message', 'publictech-terminal' ); ?></a>
		· <a href="<?php echo esc_url( $pt_review_page ); ?>"><?php esc_html_e( 'see all uncertain postal codes', 'publictech-terminal' ); ?></a>
	</p>
</section>
