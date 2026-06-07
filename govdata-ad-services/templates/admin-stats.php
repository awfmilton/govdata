<?php
/**
 * Per-ad stats table with sparkline SVGs (no D3 required — pure SVG).
 *
 * @package GovAds
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$uid      = get_current_user_id();
$is_admin = current_user_can( 'edit_others_posts' );
$days     = min( 90, max( 7, (int) ( $_GET['days'] ?? 30 ) ) ); // phpcs:ignore WordPress.Security

$ad_ids = get_posts(
	[
		'post_type'      => 'govads_ad',
		'post_status'    => 'any',
		'author'         => $is_admin ? 0 : $uid,
		'posts_per_page' => 100,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	]
);

$rows_by_ad = [];
if ( ! empty( $ad_ids ) ) {
	$stats_table  = GovAds\DB::table( 'daily_stats' );
	$placeholders = implode( ',', array_fill( 0, count( $ad_ids ), '%d' ) );
	$date_from    = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ad_id, stat_date, SUM(impressions) AS imp, SUM(clicks) AS clk
			 FROM `{$stats_table}`
			 WHERE ad_id IN ({$placeholders}) AND stat_date >= %s
			 GROUP BY ad_id, stat_date
			 ORDER BY ad_id, stat_date DESC",
			...array_merge( $ad_ids, [ $date_from ] )
		),
		ARRAY_A
	);
	// phpcs:enable

	foreach ( $rows as $row ) {
		$rows_by_ad[ (int) $row['ad_id'] ][] = $row;
	}
}

// Aggregate per-ad totals.
$ad_totals = [];
foreach ( $rows_by_ad as $aid => $ad_rows ) {
	$imp = array_sum( array_column( $ad_rows, 'imp' ) );
	$clk = array_sum( array_column( $ad_rows, 'clk' ) );
	$ad_totals[ $aid ] = [
		'imp'  => $imp,
		'clk'  => $clk,
		'ctr'  => $imp ? round( $clk / $imp * 100, 2 ) : 0,
		'rows' => $ad_rows,
	];
}

arsort( $ad_totals );
?>
<div class="wrap govads-admin-wrap">
	<h1><?php esc_html_e( 'Ad Stats', 'govdata-ad-services' ); ?></h1>

	<form method="get" style="margin-bottom:16px">
		<input type="hidden" name="page" value="govads-stats">
		<label><?php esc_html_e( 'Show last:', 'govdata-ad-services' ); ?>
			<select name="days" onchange="this.form.submit()">
				<?php foreach ( [ 7, 14, 30, 60, 90 ] as $d ) : ?>
					<option value="<?php echo esc_attr( (string) $d ); ?>" <?php selected( $days, $d ); ?>>
						<?php echo esc_html( number_format_i18n( $d ) ); ?> <?php esc_html_e( 'days', 'govdata-ad-services' ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>
	</form>

	<?php if ( empty( $ad_totals ) ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'No stats available yet.', 'govdata-ad-services' ); ?></p></div>
	<?php else : ?>
	<table class="widefat striped govads-stats-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Ad', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Status', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Impressions', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Clicks', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'CTR', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Trend', 'govdata-ad-services' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $ad_totals as $aid => $data ) :
				$status    = (string) get_post_meta( $aid, 'govads_ad_status', true );
				$imp_vals  = array_reverse( array_column( $data['rows'], 'imp' ) );
				$max_imp   = max( 1, max( $imp_vals ) );
				$svg_w     = 80; $svg_h = 24;
				$n         = count( $imp_vals );
				if ( $n > 1 ) {
					$pts = [];
					foreach ( $imp_vals as $i => $v ) {
						$x = (int) round( $i / ( $n - 1 ) * $svg_w );
						$y = (int) round( ( 1 - $v / $max_imp ) * ( $svg_h - 4 ) + 2 );
						$pts[] = $x . ',' . $y;
					}
					$sparkline = '<svg width="' . $svg_w . '" height="' . $svg_h . '" style="vertical-align:middle">'
						. '<polyline points="' . esc_attr( implode( ' ', $pts ) ) . '" fill="none" stroke="#2271b1" stroke-width="1.5"/>'
						. '</svg>';
				} else {
					$sparkline = '—';
				}
			?>
			<tr>
				<td>
					<strong><?php echo esc_html( get_the_title( $aid ) ); ?></strong><br>
					<small class="description"><?php echo esc_html( (string) get_post_meta( $aid, 'govads_ad_size', true ) ); ?></small>
				</td>
				<td><span class="govads-status <?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status ); ?></span></td>
				<td><?php echo esc_html( number_format_i18n( (int) $data['imp'] ) ); ?></td>
				<td><?php echo esc_html( number_format_i18n( (int) $data['clk'] ) ); ?></td>
				<td><?php echo esc_html( $data['ctr'] ); ?>%</td>
				<td><?php echo wp_kses( $sparkline, [ 'svg' => [ 'width' => [], 'height' => [], 'style' => [] ], 'polyline' => [ 'points' => [], 'fill' => [], 'stroke' => [], 'stroke-width' => [] ] ] ); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</div>
