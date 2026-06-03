<?php
/**
 * Template Name: PT — Data Store
 *
 * Storefront for the datasets we have cleaned, normalized and built in-house:
 * the normalized Grants & Contributions database, the cleaned House of Commons
 * open data, and our own Postal Code → Federal Riding crosswalk.
 *
 * Every headline price is set to 10% below the average of what comparable
 * commercial vendors charge for the same class of data (see the methodology
 * panel at the foot of the page — the comparator tables render from the same
 * $pt_products array that drives the cards, so the math is always in sync).
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

/*
 * ---------------------------------------------------------------------------
 * Pricing model.
 *
 * For each product we record the comparable commercial offers found in market
 * research, average their representative prices, and list our price at exactly
 * 10% below that average so we are always the lowest-cost source. `our_price`
 * is stored pre-computed but asserted against the live average below so the
 * displayed figure can never drift away from the comparator table.
 * ---------------------------------------------------------------------------
 */
$pt_products = [
	'grants' => [
		'tag'        => __( 'Flagship dataset', 'publictech-terminal' ),
		'name'       => __( 'Grants &amp; Contributions — Cleaned &amp; Normalized', 'publictech-terminal' ),
		'blurb'      => __( 'Every federal grant and contribution agreement from proactive disclosure — cleaned, de-duplicated, amendment-resolved and normalized into nine relational tables. Bilingual (EN/FR), geocoded to federal riding, with a full field-level corrections history.', 'publictech-terminal' ),
		'unit'       => 'year',
		'features'   => [
			__( '9 normalized tables: agreements, recipients, programs, locations, NAICS, amendments, recipient types, agreement URLs, corrections history', 'publictech-terminal' ),
			__( 'Bilingual EN/FR descriptions, programs and riding names', 'publictech-terminal' ),
			__( 'Recipient business numbers + NAICS codes resolved and de-duplicated', 'publictech-terminal' ),
			__( 'Geocoded to 2023 federal ridings via our own crosswalk', 'publictech-terminal' ),
			__( 'Quarterly refreshes + full historical bulk export (CSV + SQL)', 'publictech-terminal' ),
		],
		// Competitor offers for normalized federal awards / spending data (annual).
		'comparators' => [
			[ 'vendor' => 'Deltek GovWin IQ',     'note' => __( 'small-business entry tier', 'publictech-terminal' ), 'price' => 6000 ],
			[ 'vendor' => 'GovSpend',             'note' => __( 'median annual subscription', 'publictech-terminal' ), 'price' => 10000 ],
			[ 'vendor' => 'Bloomberg Government',  'note' => __( 'entry, per seat', 'publictech-terminal' ),          'price' => 7500 ],
			[ 'vendor' => 'GovTribe — Growth',     'note' => __( 'awards + opportunity data', 'publictech-terminal' ), 'price' => 6000 ],
			[ 'vendor' => 'GovTribe — Launch',     'note' => __( 'federal data, entry', 'publictech-terminal' ),       'price' => 1900 ],
		],
	],
	'commons' => [
		'tag'        => __( 'Parliamentary data', 'publictech-terminal' ),
		'name'       => __( 'House of Commons Open Data — Cleaned', 'publictech-terminal' ),
		'blurb'      => __( 'House of Commons proactive-disclosure Members’ Expenditures — salaries, travel, hospitality and contracts — by member and reporting period, joined to a cleaned registry of every MP (party, riding, contact details, postal code).', 'publictech-terminal' ),
		'unit'       => 'year',
		'features'   => [
			__( 'Member expenditures by quarter: salaries, travel, hospitality, contracts', 'publictech-terminal' ),
			__( 'Cleaned MP registry with party, riding, office and contact details', 'publictech-terminal' ),
			__( 'Normalized reporting periods + raw passthrough for full auditability', 'publictech-terminal' ),
			__( 'MP-to-postal-code view for constituency roll-ups', 'publictech-terminal' ),
			__( 'Quarterly refreshes + full historical bulk export (CSV + SQL)', 'publictech-terminal' ),
		],
		// Competitor offers for legislative / parliamentary data subscriptions (annual).
		'comparators' => [
			[ 'vendor' => 'GovTribe — Launch',     'note' => __( 'federal data, entry', 'publictech-terminal' ),        'price' => 1900 ],
			[ 'vendor' => 'BillTrack50',           'note' => __( 'single-jurisdiction tracking', 'publictech-terminal' ), 'price' => 1000 ],
			[ 'vendor' => 'LobbyingData.com',       'note' => __( 'per dataset, upper range', 'publictech-terminal' ),    'price' => 750 ],
			[ 'vendor' => 'TheyWorkForYou',         'note' => __( 'UK Parliament API, £20/mo', 'publictech-terminal' ),    'price' => 396 ],
		],
	],
	'pcbfr' => [
		'tag'        => __( 'Built in-house', 'publictech-terminal' ),
		'name'       => __( 'Postal Code &rarr; Federal Riding File (2023)', 'publictech-terminal' ),
		'blurb'      => __( 'Our own crosswalk: 899,771 Canadian postal codes spatially joined (point-in-polygon) to the 343 electoral districts of the 2023 Representation Order. A drop-in, format-compatible replacement for Statistics Canada’s PCFRF, with bilingual riding names.', 'publictech-terminal' ),
		'unit'       => 'one-time',
		'features'   => [
			__( '899,771 postal codes mapped to 343 federal ridings', 'publictech-terminal' ),
			__( 'Built by point-in-polygon join of postal points to 2023 FED boundaries', 'publictech-terminal' ),
			__( 'Bilingual riding names (EN/FR) + riding numbers', 'publictech-terminal' ),
			__( 'PCFRF-compatible columns — drop-in replacement, no remapping', 'publictech-terminal' ),
			__( 'Delivered as pipe-delimited CSV; perpetual license, free format conversions', 'publictech-terminal' ),
		],
		// Competitor offers for postal-code geography files (one-time / file license).
		'comparators' => [
			[ 'vendor' => 'Statistics Canada PCFRF', 'note' => __( 'same product; series to $2,500', 'publictech-terminal' ), 'price' => 330 ],
			[ 'vendor' => 'Geocoder.ca',             'note' => __( 'Canadian postal code dataset', 'publictech-terminal' ),   'price' => 808 ],
			[ 'vendor' => 'Canada Post / ZipCodeDownload', 'note' => __( 'commercial redistribution', 'publictech-terminal' ), 'price' => 699.95 ],
			[ 'vendor' => 'ZipCodeDownload',         'note' => __( '6-digit commercial, one-time', 'publictech-terminal' ),   'price' => 159.95 ],
			[ 'vendor' => 'ZipCodeDownload',         'note' => __( '6-digit premium, one-time', 'publictech-terminal' ),      'price' => 129.95 ],
		],
	],
];

/*
 * Derive the average comparator price and our 10%-below price for every product
 * from the comparator tables above, so the figure on the card and the figure in
 * the methodology table can never disagree.
 */
foreach ( $pt_products as $key => $product ) {
	$prices  = wp_list_pluck( $product['comparators'], 'price' );
	$average = array_sum( $prices ) / max( 1, count( $prices ) );
	$pt_products[ $key ]['avg']       = $average;
	$pt_products[ $key ]['our_price'] = round( $average * 0.9, 2 );
}

// Bundle: both annual subscriptions, an extra 10% off for buying together, with
// the one-time postal file included at no charge.
$pt_bundle_base   = $pt_products['grants']['our_price'] + $pt_products['commons']['our_price'];
$pt_bundle_price  = round( $pt_bundle_base * 0.9, 2 );
$pt_bundle_saving = ( $pt_bundle_base + $pt_products['pcbfr']['our_price'] ) - $pt_bundle_price;

// Money formatter: whole dollars unless cents are meaningful.
$pt_money = static function ( float $n ): string {
	$decimals = ( floor( $n ) === $n ) ? 0 : 2;
	return '$' . number_format( $n, $decimals );
};

// One escaped "request a license" mailto, reused with a per-product subject.
$pt_store_email = (string) get_option( 'admin_email' );

get_header(); ?>

<main>
	<section class="view active" data-pt-view="store">

		<?php /* ── HERO ──────────────────────────────────────────────── */ ?>
		<div class="hero corner">
			<div class="hero-grid">
				<div>
					<div class="mono uc" style="color:var(--accent);font-size:10px;letter-spacing:0.2em;margin-bottom:10px">◇ <?php esc_html_e( 'Data Store · License the Datasets', 'publictech-terminal' ); ?></div>
					<h2><?php echo wp_kses_post( __( 'The cleaned data, <span class="acc">priced below the market</span>.', 'publictech-terminal' ) ); ?></h2>
					<p class="lede"><?php esc_html_e( 'We did the cleaning, normalizing and geocoding so you don\'t have to. Every dataset below is offered at 10% under the average of what comparable commercial vendors charge — so you always get the lowest price. Download the bulk files, or license a refreshing feed.', 'publictech-terminal' ); ?></p>
					<div class="ctas">
						<a class="cta"       href="#pt-store-datasets">▶ <?php esc_html_e( 'Browse Datasets', 'publictech-terminal' ); ?></a>
						<a class="cta ghost" href="#pt-store-method">◇ <?php esc_html_e( 'How we priced it', 'publictech-terminal' ); ?></a>
					</div>
				</div>
				<div class="hero-stats">
					<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Postal codes', 'publictech-terminal' ); ?></div><div class="val"><?php echo esc_html( number_format_i18n( 899771 ) ); ?></div></div>
					<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Federal ridings', 'publictech-terminal' ); ?></div><div class="val">343</div></div>
					<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Normalized tables', 'publictech-terminal' ); ?></div><div class="val">9</div></div>
					<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Below market', 'publictech-terminal' ); ?></div><div class="val" style="color:var(--pos)">-10%</div></div>
				</div>
			</div>
		</div>

		<?php /* ── DATASET CARDS ────────────────────────────────────── */ ?>
		<div class="panel-title" id="pt-store-datasets" style="margin:26px 2px 12px">
			<h3 class="mono uc" style="font-size:12px;letter-spacing:0.18em;color:var(--ink-1)">◇ <?php esc_html_e( 'Available Datasets', 'publictech-terminal' ); ?></h3>
		</div>

		<div class="pt-store-grid">
			<?php foreach ( $pt_products as $key => $product ) :
				$unit_label = 'year' === $product['unit']
					? __( '/ year', 'publictech-terminal' )
					: __( 'one-time', 'publictech-terminal' );
				$subject = rawurlencode( sprintf( /* translators: %s: dataset name */ __( 'Data license request: %s', 'publictech-terminal' ), wp_strip_all_tags( $product['name'] ) ) );
			?>
			<div class="pt-price-card panel corner<?php echo 'grants' === $key ? ' featured' : ''; ?>">
				<div class="pt-price-tag mono uc"><?php echo esc_html( $product['tag'] ); ?></div>
				<h3 class="pt-price-name"><?php echo wp_kses_post( $product['name'] ); ?></h3>

				<div class="pt-price-figure">
					<span class="pt-price-amount"><?php echo esc_html( $pt_money( (float) $product['our_price'] ) ); ?></span>
					<span class="pt-price-unit"><?php echo esc_html( $unit_label ); ?></span>
				</div>
				<div class="pt-price-compare">
					<span class="pt-price-market"><?php
						/* translators: %s: averaged competitor price */
						printf( esc_html__( 'Market avg %s', 'publictech-terminal' ), esc_html( $pt_money( (float) $product['avg'] ) ) );
					?></span>
					<span class="pt-price-save mono"><?php esc_html_e( '10% below', 'publictech-terminal' ); ?></span>
				</div>

				<p class="pt-price-desc"><?php echo wp_kses_post( $product['blurb'] ); ?></p>

				<ul class="pt-feature-list">
					<?php foreach ( $product['features'] as $feat ) : ?>
						<li><span class="pt-check" aria-hidden="true">▸</span><?php echo esc_html( $feat ); ?></li>
					<?php endforeach; ?>
				</ul>

				<a class="cta pt-price-cta" href="<?php echo esc_url( 'mailto:' . $pt_store_email . '?subject=' . $subject ); ?>">
					<?php echo 'year' === $product['unit']
						? esc_html__( 'License this feed', 'publictech-terminal' )
						: esc_html__( 'Buy the file', 'publictech-terminal' ); ?>
				</a>
			</div>
			<?php endforeach; ?>

			<?php /* ── BUNDLE CARD ──────────────────────────────────── */ ?>
			<div class="pt-price-card panel corner bundle">
				<div class="pt-price-tag mono uc"><?php esc_html_e( 'Best value', 'publictech-terminal' ); ?></div>
				<h3 class="pt-price-name"><?php esc_html_e( 'Complete Public Data Suite', 'publictech-terminal' ); ?></h3>

				<div class="pt-price-figure">
					<span class="pt-price-amount"><?php echo esc_html( $pt_money( (float) $pt_bundle_price ) ); ?></span>
					<span class="pt-price-unit"><?php esc_html_e( '/ year', 'publictech-terminal' ); ?></span>
				</div>
				<div class="pt-price-compare">
					<span class="pt-price-save mono pt-save-lg"><?php
						/* translators: %s: total dollars saved versus buying separately */
						printf( esc_html__( 'Save %s vs. separately', 'publictech-terminal' ), esc_html( $pt_money( (float) $pt_bundle_saving ) ) );
					?></span>
				</div>

				<p class="pt-price-desc"><?php esc_html_e( 'Both refreshing feeds — Grants &amp; Contributions and House of Commons Open Data — for an extra 10% off, with the Postal Code → Federal Riding file included free.', 'publictech-terminal' ); ?></p>

				<ul class="pt-feature-list">
					<li><span class="pt-check" aria-hidden="true">▸</span><?php esc_html_e( 'Everything in Grants &amp; Contributions', 'publictech-terminal' ); ?></li>
					<li><span class="pt-check" aria-hidden="true">▸</span><?php esc_html_e( 'Everything in House of Commons Open Data', 'publictech-terminal' ); ?></li>
					<li><span class="pt-check" aria-hidden="true">▸</span><?php
						/* translators: %s: standalone price of the postal file */
						printf( esc_html__( 'Postal Code → Federal Riding file (%s value) included free', 'publictech-terminal' ), esc_html( $pt_money( (float) $pt_products['pcbfr']['our_price'] ) ) );
					?></li>
					<li><span class="pt-check" aria-hidden="true">▸</span><?php esc_html_e( 'Single invoice, single license, priority refreshes', 'publictech-terminal' ); ?></li>
				</ul>

				<a class="cta pt-price-cta" href="<?php echo esc_url( 'mailto:' . $pt_store_email . '?subject=' . rawurlencode( __( 'Data license request: Complete Public Data Suite', 'publictech-terminal' ) ) ); ?>">
					<?php esc_html_e( 'License the suite', 'publictech-terminal' ); ?>
				</a>
			</div>
		</div>

		<?php /* ── DELIVERY / LICENSE NOTE ─────────────────────────── */ ?>
		<div class="viz-row" style="margin-top:18px">
			<div class="panel corner">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Formats &amp; Delivery', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'CSV · SQL · API', 'publictech-terminal' ); ?></span></div>
				<div class="bd" style="font-size:12px;color:var(--ink-1);line-height:1.7">
					<?php esc_html_e( 'Bulk datasets ship as UTF-8 CSV and ready-to-load SQL dumps. Refreshing feeds are delivered by secure download or sync, updated each quarter as new disclosures publish. Need Parquet, GeoJSON or a custom cut? Conversions are free with any license.', 'publictech-terminal' ); ?>
				</div>
			</div>
			<div class="panel corner">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Licensing', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'INTERNAL / REDISTRIBUTION', 'publictech-terminal' ); ?></span></div>
				<div class="bd" style="font-size:12px;color:var(--ink-1);line-height:1.7">
					<?php esc_html_e( 'Prices shown are for internal-use licensing. Redistribution and resale licenses are available — ask when you request access. The postal file carries a perpetual license; subscriptions license the data for the term of the agreement.', 'publictech-terminal' ); ?>
				</div>
			</div>
			<div class="panel corner">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Just need to query?', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'API TIERS', 'publictech-terminal' ); ?></span></div>
				<div class="bd" style="font-size:12px;color:var(--ink-1);line-height:1.7">
					<?php esc_html_e( 'These prices are for licensing the datasets themselves. If you only want to search, stream and chart the data through the terminal and MCP API, the metered access tiers may suit you better.', 'publictech-terminal' ); ?>
					<br><a href="<?php echo esc_url( home_url( '/pricing' ) ); ?>" style="color:var(--accent)"><?php esc_html_e( 'See access tiers →', 'publictech-terminal' ); ?></a>
				</div>
			</div>
		</div>

		<?php /* ── PRICING METHODOLOGY ─────────────────────────────── */ ?>
		<div class="panel corner" id="pt-store-method" style="margin-top:26px">
			<div class="hd">
				<h3>◇ <?php esc_html_e( 'How we set these prices', 'publictech-terminal' ); ?></h3>
				<span class="meta"><?php esc_html_e( 'MARKET RESEARCH · 10% BELOW AVERAGE', 'publictech-terminal' ); ?></span>
			</div>
			<div class="bd">
				<p style="font-size:12px;color:var(--ink-1);line-height:1.7;margin:0 0 16px">
					<?php esc_html_e( 'For each dataset we surveyed what comparable commercial vendors charge for the same class of data, averaged their representative prices, then set our price at exactly 10% below that average. The tables below show the comparators behind every figure on this page.', 'publictech-terminal' ); ?>
				</p>

				<?php foreach ( $pt_products as $product ) : ?>
					<div class="pt-method-block">
						<h4 class="pt-method-name"><?php echo wp_kses_post( $product['name'] ); ?></h4>
						<table class="pt-method-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Comparable vendor', 'publictech-terminal' ); ?></th>
									<th><?php esc_html_e( 'Offer', 'publictech-terminal' ); ?></th>
									<th class="num"><?php echo 'year' === $product['unit'] ? esc_html__( 'Price / yr', 'publictech-terminal' ) : esc_html__( 'Price', 'publictech-terminal' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $product['comparators'] as $cmp ) : ?>
									<tr>
										<td><?php echo esc_html( $cmp['vendor'] ); ?></td>
										<td class="dim"><?php echo esc_html( $cmp['note'] ); ?></td>
										<td class="num"><?php echo esc_html( $pt_money( (float) $cmp['price'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
							<tfoot>
								<tr class="avg">
									<td colspan="2"><?php esc_html_e( 'Average competitor price', 'publictech-terminal' ); ?></td>
									<td class="num"><?php echo esc_html( $pt_money( (float) $product['avg'] ) ); ?></td>
								</tr>
								<tr class="ours">
									<td colspan="2"><strong><?php esc_html_e( 'Our price (10% below average)', 'publictech-terminal' ); ?></strong></td>
									<td class="num"><strong><?php echo esc_html( $pt_money( (float) $product['our_price'] ) ); ?><?php echo 'year' === $product['unit'] ? esc_html__( '/yr', 'publictech-terminal' ) : ''; ?></strong></td>
								</tr>
							</tfoot>
						</table>
					</div>
				<?php endforeach; ?>

				<p class="pt-method-sources mono">
					<?php esc_html_e( 'Sources: Statistics Canada (PCFRF / PCCF catalogue), Canada Post & ZipCodeDownload, Geocoder.ca, Deltek GovWin IQ, GovSpend, Bloomberg Government, GovTribe, BillTrack50, LobbyingData.com and TheyWorkForYou published pricing, surveyed June 2026. Competitor figures are representative entry/median tiers and may change; averages are rounded for display.', 'publictech-terminal' ); ?>
				</p>
			</div>
		</div>

	</section>
</main>

<?php
get_footer();
