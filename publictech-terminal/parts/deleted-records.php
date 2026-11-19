<?php
/**
 * Panel: grant records deleted by the Government of Canada (PublicTech\Core\Deleted_Records).
 *
 * Expects:
 *   $pt_deleted_rows   array  rows from Deleted_Records::all() / for_entity() / for_riding()
 *   $pt_deleted_title  string panel heading (optional)
 *   $pt_deleted_intro  string short context line (optional)
 *
 * Each record is shown exactly as last published, with its notice. These records are NOT included in any
 * total on the site; the panel shows its own subtotal.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$pt_deleted_rows = (array) ( $pt_deleted_rows ?? [] );
if ( ! $pt_deleted_rows ) {
	return;
}
$pt_deleted_title = (string) ( $pt_deleted_title ?? __( 'Deleted by the Government of Canada', 'publictech-terminal' ) );
$pt_deleted_total = \PublicTech\Core\Deleted_Records::total( $pt_deleted_rows );
?>
<section class="panel corner pt-deleted" id="deleted-records" style="margin-top:14px;margin-bottom:14px;border-color:var(--warn)">
	<div class="hd">
		<h3>⚠ <?php echo esc_html( $pt_deleted_title ); ?></h3>
		<span class="meta">
			<?php
			/* translators: 1: number of records, 2: total value */
			printf( esc_html__( '%1$s records · %2$s · not included in totals', 'publictech-terminal' ),
				esc_html( number_format_i18n( count( $pt_deleted_rows ) ) ),
				esc_html( '$' . number_format_i18n( $pt_deleted_total, 2 ) ) );
			?>
		</span>
	</div>
	<?php if ( ! empty( $pt_deleted_intro ) ) : ?>
		<p style="font-size:12px;color:var(--ink-2);margin:8px 0 0"><?php echo esc_html( $pt_deleted_intro ); ?></p>
	<?php endif; ?>
	<div style="display:grid;gap:10px;margin-top:10px">
		<?php foreach ( $pt_deleted_rows as $r ) :
			$pt_name  = \PublicTech\Core\one_language( (string) ( $r['recipient_operating_name'] ?: $r['recipient_legal_name'] ) );
			$pt_dept  = \PublicTech\Core\one_language( (string) $r['owner_org_en'] );
			$pt_url   = 'https://search.open.canada.ca/grants/record/' . rawurlencode( (string) $r['record_id'] );
			?>
			<article class="pt-deleted-record" style="border:1px solid var(--line);border-left:3px solid var(--warn);border-radius:4px;padding:10px 12px;background:var(--bg-1)">
				<div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
					<strong><?php echo esc_html( $pt_name ?: __( 'Unnamed recipient', 'publictech-terminal' ) ); ?></strong>
					<span class="mono" style="font-weight:600">$<?php echo esc_html( number_format_i18n( (float) $r['agreement_value'], 2 ) ); ?></span>
				</div>
				<div style="font-size:11px;color:var(--ink-2);margin-top:2px">
					<?php echo esc_html( implode( ' · ', array_filter( [
						$pt_dept,
						\PublicTech\Core\one_language( (string) $r['prog_name_en'] ),
						trim( \PublicTech\Core\one_language( (string) $r['recipient_city'] ) . ', ' . (string) $r['recipient_province'], ', ' ),
						(string) $r['federal_riding_name_en'],
						$r['agreement_start_date'] ? (string) $r['agreement_start_date'] . ( $r['agreement_end_date'] ? ' → ' . $r['agreement_end_date'] : '' ) : '',
					] ) ) ); ?>
				</div>
				<?php if ( ! empty( $r['agreement_title_en'] ) ) : ?>
					<div style="font-size:12px;margin-top:4px"><?php echo esc_html( (string) $r['agreement_title_en'] ); ?></div>
				<?php endif; ?>
				<div class="pt-notice" role="note" style="font-size:12px;margin-top:8px;padding:8px 10px;background:var(--bg-2);border-radius:3px">
					<strong>⚠ <?php echo esc_html( (string) $r['notice_title'] ); ?>.</strong>
					<?php echo esc_html( (string) $r['notice_body'] ); ?>
				</div>
				<div class="mono" style="font-size:11px;color:var(--ink-3);margin-top:6px">
					<?php esc_html_e( 'Reference', 'publictech-terminal' ); ?> <?php echo esc_html( (string) $r['ref_number'] ); ?> ·
					<a href="<?php echo esc_url( $pt_url ); ?>" target="_blank" rel="noopener nofollow" style="color:var(--accent)"><?php esc_html_e( 'Former Government of Canada record address', 'publictech-terminal' ); ?> ↗</a>
				</div>
			</article>
		<?php endforeach; ?>
	</div>
</section>
