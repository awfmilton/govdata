<?php
/**
 * Template Name: PT — Stream Terminal
 *
 * Live JSON-stream table over a configurable schema/table. Defaults to the
 * grants & contributions table (pt-grantconts-01.master_table) but honours
 * ?schema=…&table=… query params.
 *
 * The default used to be pt-finance-01.expenditures, a connection that was never
 * configured, so the page the dashboard's "Stream transactions" button opens
 * answered 500 "Stream failed." to everyone.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$pt_schemas = \PublicTech\Core\Multi_DB::instance()->aliases();
$pt_schema  = sanitize_key( (string) ( $_GET['schema'] ?? 'pt-grantconts-01' ) );
$pt_table   = sanitize_key( (string) ( $_GET['table']  ?? 'master_table' ) );

// Columns come from the same publictech_columns_* whitelist the stream endpoint
// enforces, so the header always matches what the server will return. Known
// tables get a curated, readable subset; anything else shows its first columns.
$pt_exposed = (array) apply_filters( "publictech_columns_{$pt_schema}_{$pt_table}", [], $pt_schema, $pt_table );
$pt_curated = [
	'pt-grantconts-01/master_table' => [
		'agreement_start_date' => __( 'Start date', 'publictech-terminal' ),
		'recipient_legal_name' => __( 'Recipient', 'publictech-terminal' ),
		'owner_org_title'      => __( 'Department', 'publictech-terminal' ),
		'prog_name_en'         => __( 'Program', 'publictech-terminal' ),
		'recipient_province'   => __( 'Prov.', 'publictech-terminal' ),
		'agreement_value'      => __( 'Value (CAD)', 'publictech-terminal' ),
	],
	'pt-finance-01/expenditures' => [
		'txn_id'     => __( 'TXN ID', 'publictech-terminal' ),
		'date'       => __( 'Date', 'publictech-terminal' ),
		'vendor'     => __( 'Vendor / Payee', 'publictech-terminal' ),
		'category'   => __( 'Category', 'publictech-terminal' ),
		'region'     => __( 'Region', 'publictech-terminal' ),
		'amount_cad' => __( 'Amount (CAD)', 'publictech-terminal' ),
	],
];
$pt_columns = $pt_curated[ $pt_schema . '/' . $pt_table ] ?? [];
$pt_columns = array_intersect_key( $pt_columns, $pt_exposed ) ?: array_combine(
	array_slice( array_keys( $pt_exposed ), 0, 8 ),
	array_map( static fn( $k ) => ucwords( str_replace( '_', ' ', (string) $k ) ), array_slice( array_keys( $pt_exposed ), 0, 8 ) )
);
$pt_numeric = [ 'agreement_value', 'amount_cad', 'amount', 'total', 'value' ];
$pt_can_chart = \PublicTech\Core\Chart_Spec::user_can_create(
	\PublicTech\Core\Access_Tiers::instance()->quota( get_current_user_id() ?: null )
);

get_header(); ?>

<main id="pt-main" tabindex="-1">
	<section class="view active" data-pt-view="stream">
		<div class="stream-bar corner">
			<input data-stream-filter placeholder="<?php esc_attr_e( 'Filter rows · vendor, category, txn id, amount range (e.g. >5000)…', 'publictech-terminal' ); ?>" />
			<form method="get" style="display:contents">
				<select name="schema" onchange="this.form.submit()">
					<?php foreach ( $pt_schemas as $alias ) : ?>
						<option value="<?php echo esc_attr( $alias ); ?>" <?php selected( $pt_schema, $alias ); ?>><?php echo esc_html( $alias ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="text" name="table" value="<?php echo esc_attr( $pt_table ); ?>" placeholder="table" />
				<button class="btn ghost" type="submit">⤓ <?php esc_html_e( 'Load', 'publictech-terminal' ); ?></button>
			</form>
			<?php if ( $pt_can_chart ) : ?>
				<a class="btn ghost" href="<?php echo esc_url( add_query_arg( 'fromStream', $pt_schema . '/' . $pt_table, home_url( '/chart-builder' ) ) ); ?>">◈ <?php esc_html_e( 'Build chart', 'publictech-terminal' ); ?></a>
			<?php endif; ?>
		</div>

		<table class="stream" data-stream data-schema="<?php echo esc_attr( $pt_schema ); ?>" data-table="<?php echo esc_attr( $pt_table ); ?>" data-page-size="25" data-limit="1000">
			<thead>
				<tr>
					<?php foreach ( $pt_columns as $pt_key => $pt_label ) : ?>
						<th data-sort="<?php echo esc_attr( $pt_key ); ?>"<?php echo in_array( $pt_key, $pt_numeric, true ) ? ' class="num"' : ''; ?>><?php echo esc_html( $pt_label ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php if ( $pt_columns ) : ?>
					<tr><td colspan="<?php echo (int) count( $pt_columns ); ?>"><div class="pt-loading"><?php esc_html_e( 'Streaming…', 'publictech-terminal' ); ?></div></td></tr>
				<?php else : ?>
					<tr><td><div class="pt-error"><?php esc_html_e( 'That table is not available here. Pick a schema and table above.', 'publictech-terminal' ); ?></div></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<div class="stream-foot" data-stream-foot role="status" aria-live="polite"></div>
	</section>
</main>

<?php get_footer();
