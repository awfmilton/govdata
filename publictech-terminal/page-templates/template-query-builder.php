<?php
/**
 * Template Name: PT — Query Builder
 *
 * Visual query builder over any exposed schema/table pair.
 * Column discovery: GET /publictech/v1/schema/{schema}/{table}
 * Data retrieval:   GET /publictech/v1/stream/{schema}/{table}
 * NLP fallback:     POST /publictech/v1/nlp/query
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$pt_schemas = \PublicTech\Core\Multi_DB::instance()->aliases();
// Default to the primary denormalised table — best for NL queries.
$pt_schema  = sanitize_key( (string) ( $_GET['schema'] ?? 'pt-grantconts-01' ) );
$pt_table   = sanitize_key( (string) ( $_GET['table']  ?? 'master_table' ) );

// Value catalogue: loaded by the NLP router when present.
// Path: database/nlp-value-catalogue.json (generated post-normalization).
// Only include URL when file is web-accessible in the child theme's assets dir.
$catalogue_path = PUBLICTECH_TERMINAL_DIR . 'assets/data/nlp-value-catalogue.json';
$catalogue_url  = file_exists( $catalogue_path )
	? esc_url_raw( PUBLICTECH_TERMINAL_URL . 'assets/data/nlp-value-catalogue.json' )
	: null;

$nlp_available = wp_script_is( 'compromise', 'enqueued' ) || wp_script_is( 'compromise', 'registered' );

get_header(); ?>

<main id="pt-main" tabindex="-1">
<section class="view active" data-pt-view="query-builder">

	<!-- ── HEADER: schema / table selector ────────────────────────────────── -->
	<div class="stream-bar corner" id="pt-qb-bar">
		<select id="pt-qb-schema" aria-label="<?php esc_attr_e( 'Schema', 'publictech-terminal' ); ?>">
			<?php foreach ( $pt_schemas as $alias ) : ?>
				<option value="<?php echo esc_attr( $alias ); ?>"
				        <?php selected( $pt_schema, $alias ); ?>>
					<?php echo esc_html( $alias ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<input id="pt-qb-table"
		       type="text"
		       value="<?php echo esc_attr( $pt_table ); ?>"
		       placeholder="<?php esc_attr_e( 'table name', 'publictech-terminal' ); ?>"
		       aria-label="<?php esc_attr_e( 'Table name', 'publictech-terminal' ); ?>" />
		<button class="btn" id="pt-qb-load-cols">
			⤓ <?php esc_html_e( 'Load Columns', 'publictech-terminal' ); ?>
		</button>
	</div>

	<!-- ── FILTER CONDITIONS ──────────────────────────────────────────────── -->
	<div class="panel corner" style="margin-bottom:12px">
		<div class="hd" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
			<h3>◇ <?php esc_html_e( 'Filter Conditions', 'publictech-terminal' ); ?></h3>
			<button class="btn ghost" id="pt-qb-add-condition">
				+ <?php esc_html_e( 'Add Condition', 'publictech-terminal' ); ?>
			</button>
		</div>
		<div class="bd" id="pt-qb-conditions">
			<div class="dim mono" id="pt-qb-conditions-empty" style="font-size:11px;padding:4px 0">
				<?php esc_html_e( 'Load columns to begin adding filter conditions.', 'publictech-terminal' ); ?>
			</div>
		</div>
	</div>

	<!-- ── SORT & LIMIT ───────────────────────────────────────────────────── -->
	<div class="panel corner" style="margin-bottom:12px">
		<div class="hd">
			<h3>◇ <?php esc_html_e( 'Sort &amp; Limit', 'publictech-terminal' ); ?></h3>
		</div>
		<div class="bd" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
			<label style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--ink-1)">
				<?php esc_html_e( 'Sort by', 'publictech-terminal' ); ?>
				<select id="pt-qb-sort-col" aria-label="<?php esc_attr_e( 'Sort column', 'publictech-terminal' ); ?>" style="min-width:160px">
					<option value=""><?php esc_html_e( '— none —', 'publictech-terminal' ); ?></option>
				</select>
			</label>
			<div id="pt-qb-sort-dir" class="pt-seg" style="font-size:11px">
				<button class="on" data-val="ASC">ASC ↑</button>
				<button data-val="DESC">DESC ↓</button>
			</div>
			<label style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--ink-1)">
				<?php esc_html_e( 'Limit', 'publictech-terminal' ); ?>
				<input id="pt-qb-limit"
				       type="number"
				       min="1"
				       max="1000"
				       value="100"
				       style="width:72px"
				       aria-label="<?php esc_attr_e( 'Row limit', 'publictech-terminal' ); ?>" />
			</label>
		</div>
	</div>

	<!-- ── ACTION BAR ─────────────────────────────────────────────────────── -->
	<div style="display:flex;gap:10px;align-items:center;margin-bottom:12px;flex-wrap:wrap">
		<button class="btn" id="pt-qb-run">
			▶ <?php esc_html_e( 'Run Query', 'publictech-terminal' ); ?>
		</button>
		<button class="btn ghost" id="pt-qb-clear">
			✕ <?php esc_html_e( 'Clear All', 'publictech-terminal' ); ?>
		</button>
		<button class="btn ghost" id="pt-qb-export" disabled>
			⤓ <?php esc_html_e( 'Export CSV', 'publictech-terminal' ); ?>
		</button>
		<span id="pt-qb-status" class="mono dim" style="font-size:11px;margin-left:auto" role="status" aria-live="polite"></span>
	</div>

	<!-- ── NL QUICK SEARCH ───────────────────────────────────────────────── -->
	<div class="panel corner" style="margin-bottom:12px">
		<div class="hd" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
			<h3>◇ <?php esc_html_e( 'NL Quick Search', 'publictech-terminal' ); ?></h3>
			<span class="meta mono dim" style="font-size:10px">
				<?php
				if ( $nlp_available ) {
					esc_html_e( 'COMPROMISE.JS · RULE-BASED', 'publictech-terminal' );
				} else {
					esc_html_e( 'RULE-BASED · REGEX FALLBACK', 'publictech-terminal' );
				}
				?>
			</span>
		</div>
		<div class="bd">
			<p class="dim" style="font-size:11px;margin:0 0 10px">
				<?php esc_html_e( 'Type a plain-language query. Recognized patterns populate the filter rows above. Unrecognized queries route to the AI layer when configured.', 'publictech-terminal' ); ?>
			</p>
			<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
				<input id="pt-qb-nl-input"
				       type="text"
				       placeholder="<?php esc_attr_e( 'e.g. grants over $500,000 in Ontario from Health Canada since 2020', 'publictech-terminal' ); ?>"
				       style="flex:1;min-width:280px"
				       aria-label="<?php esc_attr_e( 'Natural language query', 'publictech-terminal' ); ?>" />
				<button class="btn ghost" id="pt-qb-nl-apply">
					→ <?php esc_html_e( 'Apply', 'publictech-terminal' ); ?>
				</button>
			</div>
			<div id="pt-qb-nl-preview" class="mono dim" style="font-size:10px;margin-top:8px;min-height:16px"></div>
			<div class="dim" style="font-size:10px;margin-top:6px">
				<?php esc_html_e( 'AI layer: not configured · ', 'publictech-terminal' ); ?>
				<a href="<?php echo esc_url( home_url( '/ai-console' ) ); ?>" style="color:var(--ink-2)">
					<?php esc_html_e( 'Try AI Console →', 'publictech-terminal' ); ?>
				</a>
			</div>
		</div>
	</div>

	<!-- ── RESULTS ────────────────────────────────────────────────────────── -->
	<div id="pt-qb-results-wrap">
		<div class="dim mono" style="font-size:11px;padding:16px 0">
			<?php esc_html_e( 'Run a query to see results.', 'publictech-terminal' ); ?>
		</div>
	</div>

</section>
</main>

<?php
// Boot data for query-builder-ui.js: schema/table pre-selection + optional catalogue URL.
$boot_data = [
	'schema' => $pt_schema,
	'table'  => $pt_table,
];
if ( $catalogue_url ) {
	$boot_data['catalogueUrl'] = $catalogue_url;
}
?>
<script type="application/json" id="pt-qb-boot"
	<?php if ( $catalogue_url ) : ?>
		data-catalogue-url="<?php echo esc_attr( $catalogue_url ); ?>"
	<?php endif; ?>
><?php echo wp_json_encode( $boot_data ); ?></script>

<?php
// Inline the column list so it renders instantly with no REST round-trip. The
// /schema endpoint costs ~0.3s of WP bootstrap per call and only fires after all
// page JS loads (~1.2s perceived). query-builder-ui.js reads window.PT_QB_COLUMNS
// first and only falls back to the REST fetch if it's absent (e.g. table change).
// (Data Explorer already does this; the standalone Query Builder did not.)
$pt_inline_cols = class_exists( '\\PublicTech\\Core\\REST_API' )
	? \PublicTech\Core\REST_API::columns_for( (string) $pt_schema, (string) $pt_table )
	: [];
?>
<script>window.PT_QB_COLUMNS = <?php echo wp_json_encode( $pt_inline_cols ); ?>;</script>

<?php get_footer();
