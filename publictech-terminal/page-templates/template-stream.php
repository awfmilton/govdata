<?php
/**
 * Template Name: PT — Stream Terminal
 *
 * Live JSON-stream table over a configurable schema/table. Defaults to the
 * pt-finance-01.expenditures view but honours ?schema=…&table=… query params.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$pt_schemas = \PublicTech\Core\Multi_DB::instance()->aliases();
$pt_schema  = sanitize_key( (string) ( $_GET['schema'] ?? 'pt-finance-01' ) );
$pt_table   = sanitize_key( (string) ( $_GET['table']  ?? 'expenditures' ) );
$pt_can_chart = \PublicTech\Core\Chart_Spec::user_can_create(
	\PublicTech\Core\Access_Tiers::instance()->quota( get_current_user_id() ?: null )
);

get_header(); ?>

<main>
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
					<th data-sort="txn_id">TXN ID</th>
					<th data-sort="date"><?php esc_html_e( 'Date', 'publictech-terminal' ); ?></th>
					<th data-sort="vendor"><?php esc_html_e( 'Vendor / Payee', 'publictech-terminal' ); ?></th>
					<th data-sort="category"><?php esc_html_e( 'Category', 'publictech-terminal' ); ?></th>
					<th data-sort="region"><?php esc_html_e( 'Region', 'publictech-terminal' ); ?></th>
					<th data-sort="amount_cad" class="num"><?php esc_html_e( 'Amount (CAD)', 'publictech-terminal' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td colspan="6"><div class="pt-loading"><?php esc_html_e( 'Streaming…', 'publictech-terminal' ); ?></div></td></tr>
			</tbody>
		</table>
		<div class="stream-foot" data-stream-foot></div>
	</section>
</main>

<?php get_footer();
