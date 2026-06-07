<?php
/**
 * Admin dashboard — overview KPIs for the current advertiser (or all if admin).
 *
 * @package GovAds
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$uid      = get_current_user_id();
$is_admin = current_user_can( 'edit_others_posts' );

// Fetch ad IDs for this advertiser.
$ad_ids = get_posts(
	[
		'post_type'      => 'govads_ad',
		'post_status'    => 'any',
		'author'         => $is_admin ? 0 : $uid,
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	]
);

$total_imp    = 0;
$total_clk    = 0;
$ctr          = 0.0;
$last_30_rows = [];

if ( ! empty( $ad_ids ) ) {
	$stats_table  = GovAds\DB::table( 'daily_stats' );
	$placeholders = implode( ',', array_fill( 0, count( $ad_ids ), '%d' ) );
	$date_from    = gmdate( 'Y-m-d', strtotime( '-30 days' ) );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	$agg = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT SUM(impressions) AS imp, SUM(clicks) AS clk
			 FROM `{$stats_table}`
			 WHERE ad_id IN ({$placeholders}) AND stat_date >= %s",
			...array_merge( $ad_ids, [ $date_from ] )
		),
		ARRAY_A
	);

	$last_30_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT stat_date, SUM(impressions) AS imp, SUM(clicks) AS clk
			 FROM `{$stats_table}`
			 WHERE ad_id IN ({$placeholders}) AND stat_date >= %s
			 GROUP BY stat_date
			 ORDER BY stat_date DESC
			 LIMIT 30",
			...array_merge( $ad_ids, [ $date_from ] )
		),
		ARRAY_A
	);
	// phpcs:enable

	$total_imp = (int) ( $agg['imp'] ?? 0 );
	$total_clk = (int) ( $agg['clk'] ?? 0 );
	$ctr       = $total_imp ? round( $total_clk / $total_imp * 100, 2 ) : 0.0;
}

$pending_count   = (int) wp_count_posts( 'govads_ad' )->pending ?? 0;
$campaign_count  = (int) wp_count_posts( 'govads_campaign' )->publish ?? 0;
$my_ad_count     = count( $ad_ids );
?>
<div class="wrap govads-admin-wrap">
	<h1><?php esc_html_e( 'GovData Ad Services', 'govdata-ad-services' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Overview for the last 30 days.', 'govdata-ad-services' ); ?></p>

	<div class="govads-kpis">
		<div class="govads-kpi">
			<div class="govads-kpi-val"><?php echo esc_html( number_format_i18n( $total_imp ) ); ?></div>
			<div class="govads-kpi-lab"><?php esc_html_e( 'Impressions', 'govdata-ad-services' ); ?></div>
		</div>
		<div class="govads-kpi">
			<div class="govads-kpi-val"><?php echo esc_html( number_format_i18n( $total_clk ) ); ?></div>
			<div class="govads-kpi-lab"><?php esc_html_e( 'Clicks', 'govdata-ad-services' ); ?></div>
		</div>
		<div class="govads-kpi">
			<div class="govads-kpi-val"><?php echo esc_html( $ctr ); ?>%</div>
			<div class="govads-kpi-lab"><?php esc_html_e( 'CTR', 'govdata-ad-services' ); ?></div>
		</div>
		<div class="govads-kpi">
			<div class="govads-kpi-val"><?php echo esc_html( number_format_i18n( $my_ad_count ) ); ?></div>
			<div class="govads-kpi-lab"><?php esc_html_e( 'Total Ads', 'govdata-ad-services' ); ?></div>
		</div>
		<?php if ( $is_admin ) : ?>
		<div class="govads-kpi">
			<div class="govads-kpi-val" style="color:<?php echo $pending_count ? '#b22222' : '#1a7e31'; ?>">
				<?php echo esc_html( $pending_count ); ?>
			</div>
			<div class="govads-kpi-lab"><?php esc_html_e( 'Pending Review', 'govdata-ad-services' ); ?></div>
		</div>
		<?php endif; ?>
	</div>

	<?php if ( ! empty( $last_30_rows ) ) : ?>
	<h2><?php esc_html_e( 'Daily Activity', 'govdata-ad-services' ); ?></h2>
	<table class="widefat striped govads-stats-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Impressions', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'Clicks', 'govdata-ad-services' ); ?></th>
				<th><?php esc_html_e( 'CTR', 'govdata-ad-services' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $last_30_rows as $row ) :
				$row_ctr = (int) $row['imp'] ? round( (int) $row['clk'] / (int) $row['imp'] * 100, 2 ) : 0; ?>
			<tr>
				<td><?php echo esc_html( $row['stat_date'] ); ?></td>
				<td><?php echo esc_html( number_format_i18n( (int) $row['imp'] ) ); ?></td>
				<td><?php echo esc_html( number_format_i18n( (int) $row['clk'] ) ); ?></td>
				<td><?php echo esc_html( $row_ctr ); ?>%</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php else : ?>
	<div class="notice notice-info inline"><p>
		<?php esc_html_e( 'No stats yet. Create a campaign, submit an ad, and wait for it to be approved.', 'govdata-ad-services' ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=govads-submit' ) ); ?>"><?php esc_html_e( 'Submit an ad →', 'govdata-ad-services' ); ?></a>
	</p></div>
	<?php endif; ?>
</div>
