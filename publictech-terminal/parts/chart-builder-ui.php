<?php
/**
 * Chart-builder drawer/panel markup. Behaviour lives in
 * assets/js/chart-builder-ui.js; this file just stamps the DOM.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$pt_chart_types = [
	'bar'     => __( 'Bar',      'publictech-terminal' ),
	'line'    => __( 'Line',     'publictech-terminal' ),
	'spline'  => __( 'Spline',   'publictech-terminal' ),
	'area'    => __( 'Area',     'publictech-terminal' ),
	'scatter' => __( 'Scatter',  'publictech-terminal' ),
	'step'    => __( 'Step',     'publictech-terminal' ),
	'pie'     => __( 'Pie',      'publictech-terminal' ),
	'donut'   => __( 'Donut',    'publictech-terminal' ),
	'gauge'   => __( 'Gauge',    'publictech-terminal' ),
];
?>
<div class="pt-builder">

	<header class="pt-builder-bar corner">
		<div class="pt-builder-title">
			<span class="pulse"></span>
			<?php esc_html_e( 'CHART BUILDER · v1', 'publictech-terminal' ); ?>
		</div>
		<div class="pt-builder-source-picker pt-seg" data-knob="source-type">
			<button class="on" data-val="static"><?php esc_html_e( 'Static',  'publictech-terminal' ); ?></button>
			<button data-val="rest"><?php esc_html_e( 'REST',   'publictech-terminal' ); ?></button>
			<button data-val="ai"><?php esc_html_e( 'AI Console', 'publictech-terminal' ); ?></button>
			<button data-val="stream"><?php esc_html_e( 'Stream', 'publictech-terminal' ); ?></button>
		</div>
		<div class="pt-builder-actions">
			<button class="btn ghost" id="pt-builder-preview-refresh"><?php esc_html_e( '↻ Refresh', 'publictech-terminal' ); ?></button>
			<button class="btn ghost" id="pt-builder-export-json"><?php esc_html_e( '⤓ JSON', 'publictech-terminal' ); ?></button>
		</div>
	</header>

	<div class="pt-builder-grid">

		<!-- ───────── Left column: chart type + data source ───────── -->
		<aside class="pt-builder-side panel corner">
			<div class="hd"><h3>◇ <?php esc_html_e( 'Chart Type', 'publictech-terminal' ); ?></h3></div>
			<div class="bd">
				<div class="pt-type-grid" data-knob="chart-type">
					<?php foreach ( $pt_chart_types as $slug => $label ) : ?>
						<button class="pt-type-tile <?php echo 'bar' === $slug ? 'on' : ''; ?>" data-val="<?php echo esc_attr( $slug ); ?>">
							<span class="ic ic-<?php echo esc_attr( $slug ); ?>" aria-hidden="true"></span>
							<span class="lab"><?php echo esc_html( $label ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="hd"><h3>◇ <?php esc_html_e( 'Data Source', 'publictech-terminal' ); ?></h3></div>
			<div class="bd">
				<!-- STATIC: raw JSON or CSV upload -->
				<div class="pt-source-block" data-source="static">
					<label class="pt-field-label" for="pt-static-csv"><?php esc_html_e( 'Paste rows (JSON or CSV)', 'publictech-terminal' ); ?></label>
					<textarea id="pt-static-csv" rows="8" placeholder='[{"month":"Jan","sales":120},{"month":"Feb","sales":180}]'></textarea>
					<div class="pt-field-row">
						<input type="file" id="pt-static-file" accept=".csv,.json,text/csv,application/json" />
						<button class="btn ghost" id="pt-static-apply"><?php esc_html_e( 'Apply rows', 'publictech-terminal' ); ?></button>
					</div>
				</div>

				<!-- REST: pick a saved schema/table -->
				<div class="pt-source-block" data-source="rest" hidden>
					<label class="pt-field-label" for="pt-rest-endpoint"><?php esc_html_e( 'Endpoint', 'publictech-terminal' ); ?></label>
					<input type="text" id="pt-rest-endpoint" placeholder="/publictech/v1/stream/pt-finance-01/expenditures?limit=200" />
					<button class="btn ghost" id="pt-rest-load"><?php esc_html_e( 'Load preview', 'publictech-terminal' ); ?></button>
				</div>

				<!-- AI Console: hydrate from cached query hash -->
				<div class="pt-source-block" data-source="ai" hidden>
					<label class="pt-field-label" for="pt-ai-query"><?php esc_html_e( 'Cached AI query hash', 'publictech-terminal' ); ?></label>
					<input type="text" id="pt-ai-query" placeholder="<?php esc_attr_e( 'Open AI Console, run a query, then click “Build chart”.', 'publictech-terminal' ); ?>" />
					<a class="btn ghost" href="<?php echo esc_url( home_url( '/ai' ) ); ?>"><?php esc_html_e( 'Open AI Console', 'publictech-terminal' ); ?></a>
				</div>

				<!-- Stream: hydrate from current stream filter state -->
				<div class="pt-source-block" data-source="stream" hidden>
					<label class="pt-field-label" for="pt-stream-target"><?php esc_html_e( 'Stream schema/table', 'publictech-terminal' ); ?></label>
					<input type="text" id="pt-stream-target" placeholder="pt-finance-01/expenditures" />
					<a class="btn ghost" href="<?php echo esc_url( home_url( '/stream' ) ); ?>"><?php esc_html_e( 'Open Stream', 'publictech-terminal' ); ?></a>
				</div>
			</div>
		</aside>

		<!-- ───────── Centre column: live preview ───────── -->
		<section class="pt-builder-canvas panel corner">
			<div class="hd">
				<h3>◇ <?php esc_html_e( 'Live Preview', 'publictech-terminal' ); ?></h3>
				<span class="meta" id="pt-builder-status"><?php esc_html_e( 'awaiting data', 'publictech-terminal' ); ?></span>
			</div>
			<div class="pt-builder-canvas-host" id="pt-builder-canvas" style="height:560px"></div>
			<div class="pt-builder-canvas-foot">
				<span class="dim" id="pt-builder-canvas-meta"></span>
				<button class="btn ghost" id="pt-builder-canvas-shuffle"><?php esc_html_e( 'Try sample data', 'publictech-terminal' ); ?></button>
			</div>
		</section>

		<!-- ───────── Right column: axis/series/save ───────── -->
		<aside class="pt-builder-side panel corner">
			<div class="hd"><h3>◇ <?php esc_html_e( 'Axes & Layout', 'publictech-terminal' ); ?></h3></div>
			<div class="bd">
				<div class="pt-field-row">
					<label class="pt-field-label" for="pt-axis-x-label"><?php esc_html_e( 'X axis label', 'publictech-terminal' ); ?></label>
					<input type="text" id="pt-axis-x-label" maxlength="64" />
				</div>
				<div class="pt-field-row">
					<label class="pt-field-label" for="pt-axis-x-type"><?php esc_html_e( 'X axis type', 'publictech-terminal' ); ?></label>
					<select id="pt-axis-x-type">
						<option value="category"><?php esc_html_e( 'Category', 'publictech-terminal' ); ?></option>
						<option value="indexed"><?php esc_html_e( 'Indexed (numeric)', 'publictech-terminal' ); ?></option>
						<option value="timeseries"><?php esc_html_e( 'Timeseries', 'publictech-terminal' ); ?></option>
						<option value="log"><?php esc_html_e( 'Log', 'publictech-terminal' ); ?></option>
					</select>
				</div>
				<div class="pt-field-row">
					<label class="pt-field-label" for="pt-axis-y-label"><?php esc_html_e( 'Y axis label', 'publictech-terminal' ); ?></label>
					<input type="text" id="pt-axis-y-label" maxlength="64" />
				</div>
				<div class="pt-field-row">
					<label class="pt-field-label"><?php esc_html_e( 'Gridlines', 'publictech-terminal' ); ?></label>
					<label class="pt-checkbox"><input type="checkbox" id="pt-grid-x"> <?php esc_html_e( 'X grid', 'publictech-terminal' ); ?></label>
					<label class="pt-checkbox"><input type="checkbox" id="pt-grid-y" checked> <?php esc_html_e( 'Y grid', 'publictech-terminal' ); ?></label>
				</div>
				<div class="pt-field-row">
					<label class="pt-field-label"><?php esc_html_e( 'Legend / Markers', 'publictech-terminal' ); ?></label>
					<label class="pt-checkbox"><input type="checkbox" id="pt-legend-show" checked> <?php esc_html_e( 'Show legend', 'publictech-terminal' ); ?></label>
					<label class="pt-checkbox"><input type="checkbox" id="pt-point-show"> <?php esc_html_e( 'Show points', 'publictech-terminal' ); ?></label>
				</div>
			</div>

			<div class="hd"><h3>◇ <?php esc_html_e( 'Series', 'publictech-terminal' ); ?></h3></div>
			<div class="bd">
				<div id="pt-series-list" class="pt-series-list">
					<div class="dim"><?php esc_html_e( 'Load data to see numeric columns.', 'publictech-terminal' ); ?></div>
				</div>
				<details class="pt-stacking">
					<summary><?php esc_html_e( 'Stacking', 'publictech-terminal' ); ?></summary>
					<p class="dim" style="margin-top:8px"><?php esc_html_e( 'Drag series into groups to stack them. Bar/area charts only.', 'publictech-terminal' ); ?></p>
					<div id="pt-stacking-groups"></div>
					<button class="btn ghost" id="pt-stacking-add"><?php esc_html_e( '+ Add group', 'publictech-terminal' ); ?></button>
				</details>
			</div>

			<div class="hd"><h3>◇ <?php esc_html_e( 'Save Chart', 'publictech-terminal' ); ?></h3></div>
			<div class="bd">
				<div class="pt-field-row">
					<label class="pt-field-label" for="pt-save-title"><?php esc_html_e( 'Title', 'publictech-terminal' ); ?></label>
					<input type="text" id="pt-save-title" maxlength="120" placeholder="<?php esc_attr_e( 'e.g. Top vendors by category, FY2024', 'publictech-terminal' ); ?>" />
				</div>
				<div class="pt-field-row">
					<label class="pt-field-label" for="pt-save-desc"><?php esc_html_e( 'Description', 'publictech-terminal' ); ?></label>
					<textarea id="pt-save-desc" rows="2" maxlength="600"></textarea>
				</div>
				<div class="pt-field-row">
					<label class="pt-field-label"><?php esc_html_e( 'Visibility', 'publictech-terminal' ); ?></label>
					<div class="segctl pt-seg" data-knob="visibility">
						<button class="on" data-val="private"><?php esc_html_e( 'Private',  'publictech-terminal' ); ?></button>
						<button data-val="public"><?php esc_html_e( 'Public · Gallery', 'publictech-terminal' ); ?></button>
					</div>
				</div>
				<div class="pt-save-actions">
					<button class="cta" id="pt-builder-save"><?php esc_html_e( '◆ Save chart', 'publictech-terminal' ); ?></button>
					<button class="cta ghost" id="pt-builder-fork" hidden><?php esc_html_e( '⑂ Fork to my account', 'publictech-terminal' ); ?></button>
				</div>
				<div class="pt-save-status dim" id="pt-builder-save-status"></div>
			</div>
		</aside>
	</div>
</div>
