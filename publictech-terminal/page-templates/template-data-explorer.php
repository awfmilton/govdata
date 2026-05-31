<?php
/**
 * Template Name: PT — Data Explorer
 *
 * Unified data exploration surface. Users choose between the structured filter
 * builder or the NL Quick Search. Results render in a table and an auto-generated
 * chart. Tier-based row limits are enforced server-side and notified client-side.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$uid        = get_current_user_id();
$tier       = \PublicTech\Core\Access_Tiers::instance()->quota( $uid ?: null );
$tier_label = ucfirst( \PublicTech\Core\Access_Tiers::instance()->user_tier( $uid ?: null ) );
$max_rows   = (int) $tier['max_rows'];

// reCAPTCHA site key — empty string if not configured yet.
$recaptcha_site_key = \PublicTech\Core\Captcha::site_key();

// Pre-configure for the primary grants table.
$pt_schema = 'pt-grantconts-01';
$pt_table  = 'master_table';

get_header(); ?>

<main>
<section class="view active" data-pt-view="data-explorer">

	<!-- ── PAGE HEADER ──────────────────────────────────────────────────── -->
	<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:16px">
		<div>
			<h1 class="page-title" style="margin:0 0 4px">
				<?php esc_html_e( 'Data Explorer', 'publictech-terminal' ); ?>
			</h1>
			<p class="dim" style="margin:0;font-size:11px">
				<?php esc_html_e( 'Government grants & contributions · pt-grantconts-01 / master_table', 'publictech-terminal' ); ?>
			</p>
		</div>
		<span class="pt-tier-badge mono" title="<?php printf( esc_attr__( 'Your tier allows up to %s rows per query', 'publictech-terminal' ), number_format( $max_rows ) ); ?>">
			<?php echo esc_html( $tier_label ); ?> · <?php echo esc_html( number_format( $max_rows ) ); ?> <?php esc_html_e( 'rows max', 'publictech-terminal' ); ?>
		</span>
	</div>

	<!-- ── TIER NOTIFICATION (hidden until JS triggers it) ──────────────── -->
	<div id="de-tier-notice" class="panel corner" style="display:none;margin-bottom:12px;border-left:3px solid var(--warn)">
		<div class="bd" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
			<span style="color:var(--warn);font-size:13px">⚠</span>
			<span id="de-tier-notice-text" style="flex:1;font-size:12px"></span>
			<?php if ( ! $uid ) : ?>
				<a class="btn ghost" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">
					<?php esc_html_e( 'Sign in', 'publictech-terminal' ); ?>
				</a>
			<?php elseif ( 'anonymous' === strtolower( $tier_label ) || 'free' === strtolower( $tier_label ) ) : ?>
				<a class="btn ghost" href="<?php echo esc_url( home_url( '/upgrade' ) ); ?>">
					<?php esc_html_e( 'Upgrade', 'publictech-terminal' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>

	<!-- ── MODE TABS ────────────────────────────────────────────────────── -->
	<div class="pt-seg" id="de-mode-tabs" style="margin-bottom:16px;font-size:12px">
		<button class="on" data-mode="filter" id="de-tab-filter">
			◇ <?php esc_html_e( 'Filter Builder', 'publictech-terminal' ); ?>
		</button>
		<button data-mode="nl" id="de-tab-nl">
			◈ <?php esc_html_e( 'Natural Language', 'publictech-terminal' ); ?>
		</button>
	</div>

	<!-- ── FILTER BUILDER PANEL ─────────────────────────────────────────── -->
	<div id="de-panel-filter">
		<?php // query-builder-ui.js reads schema/table from these elements at load
		      // time (captured as constants). The data-explorer UI is otherwise
		      // de-*; without these its loadColumns() bails before fetching. ?>
		<input type="hidden" id="pt-qb-schema" value="pt-grantconts-01">
		<input type="hidden" id="pt-qb-table" value="master_table">
		<div class="panel corner" style="margin-bottom:12px">
			<div class="hd" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
				<h3>◇ <?php esc_html_e( 'Filter Conditions', 'publictech-terminal' ); ?></h3>
				<button class="btn ghost" id="pt-qb-add-condition">
					+ <?php esc_html_e( 'Add Condition', 'publictech-terminal' ); ?>
				</button>
			</div>
			<div class="bd" id="pt-qb-conditions">
				<div class="dim mono" id="pt-qb-conditions-empty" style="font-size:11px;padding:4px 0">
					<?php esc_html_e( 'Loading columns…', 'publictech-terminal' ); ?>
				</div>
			</div>
		</div>

		<div class="panel corner" style="margin-bottom:12px">
			<div class="hd"><h3>◇ <?php esc_html_e( 'Sort &amp; Limit', 'publictech-terminal' ); ?></h3></div>
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
					<input id="pt-qb-limit" type="number" min="1"
					       max="<?php echo esc_attr( $max_rows ); ?>"
					       value="<?php echo esc_attr( min( 100, $max_rows ) ); ?>"
					       style="width:72px"
					       aria-label="<?php esc_attr_e( 'Row limit', 'publictech-terminal' ); ?>" />
				</label>
			</div>
		</div>
	</div>

	<!-- ── NL SEARCH PANEL ──────────────────────────────────────────────── -->
	<div id="de-panel-nl" style="display:none">
		<div class="panel corner" style="margin-bottom:12px">
			<div class="hd" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
				<h3>◈ <?php esc_html_e( 'Natural Language Search', 'publictech-terminal' ); ?></h3>
				<span class="meta mono dim" style="font-size:10px">COMPROMISE.JS · RULE-BASED</span>
			</div>
			<div class="bd">
				<p class="dim" style="font-size:11px;margin:0 0 12px">
					<?php esc_html_e( 'Describe what you\'re looking for in plain English. Recognized patterns are translated into database filters. Unrecognized queries route to the AI layer when configured.', 'publictech-terminal' ); ?>
				</p>
				<textarea id="pt-qb-nl-input"
				          rows="3"
				          placeholder="<?php esc_attr_e( 'e.g. grants over $1 million in Ontario to not-for-profits from Health Canada since 2020', 'publictech-terminal' ); ?>"
				          style="width:100%;box-sizing:border-box;resize:vertical"
				          aria-label="<?php esc_attr_e( 'Natural language query', 'publictech-terminal' ); ?>"></textarea>
				<div id="pt-qb-nl-preview" class="mono dim" style="font-size:10px;margin-top:6px;min-height:16px"></div>
			</div>
		</div>
	</div>

	<!-- ── ACTION BAR ───────────────────────────────────────────────────── -->
	<div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap">
		<button class="btn" id="de-run-btn">
			▶ <?php esc_html_e( 'Run', 'publictech-terminal' ); ?>
		</button>
		<button class="btn ghost" id="pt-qb-clear">
			✕ <?php esc_html_e( 'Clear', 'publictech-terminal' ); ?>
		</button>
		<button class="btn ghost" id="pt-qb-export" disabled>
			⤓ <?php esc_html_e( 'Export CSV', 'publictech-terminal' ); ?>
		</button>
		<span id="pt-qb-status" class="mono dim" style="font-size:11px;margin-left:auto"></span>
	</div>

	<!-- ── RESULTS: TABLE + CHART ───────────────────────────────────────── -->
	<div id="de-results-area" style="display:none">
		<div style="display:grid;grid-template-columns:1fr;gap:16px" id="de-results-grid">

			<!-- Results table -->
			<div>
				<div class="panel corner">
					<div class="hd" style="display:flex;align-items:center;justify-content:space-between">
						<h3>◇ <?php esc_html_e( 'Results', 'publictech-terminal' ); ?></h3>
						<span id="de-result-count" class="mono dim" style="font-size:10px"></span>
					</div>
					<div class="bd" style="overflow-x:auto" id="pt-qb-results-wrap">
					</div>
				</div>
			</div>

			<!-- Chart panel (shown after results load) -->
			<div id="de-chart-panel" style="display:none">
				<div class="panel corner">
					<div class="hd" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
						<h3>◈ <?php esc_html_e( 'Chart', 'publictech-terminal' ); ?></h3>
						<div style="display:flex;gap:6px;align-items:center">
							<select id="de-chart-type" aria-label="<?php esc_attr_e( 'Chart type', 'publictech-terminal' ); ?>" style="font-size:11px">
								<option value="bar"><?php esc_html_e( 'Bar', 'publictech-terminal' ); ?></option>
								<option value="line"><?php esc_html_e( 'Line', 'publictech-terminal' ); ?></option>
								<option value="area"><?php esc_html_e( 'Area', 'publictech-terminal' ); ?></option>
								<option value="spline"><?php esc_html_e( 'Spline', 'publictech-terminal' ); ?></option>
							</select>
							<button class="btn ghost" id="de-chart-refresh" style="font-size:11px;padding:5px 10px">
								↺ <?php esc_html_e( 'Redraw', 'publictech-terminal' ); ?>
							</button>
						</div>
					</div>
					<div class="bd">
						<div id="de-chart-host" style="width:100%;min-height:260px"></div>
					</div>
				</div>
			</div>

		</div>
	</div>

</section>
</main>

<!-- Boot data: schema/table fixed, tier limits, reCAPTCHA site key -->
<script type="application/json" id="pt-de-boot"><?php echo wp_json_encode( [
	'schema'        => $pt_schema,
	'table'         => $pt_table,
	'maxRows'       => $max_rows,
	'tierLabel'     => $tier_label,
	'recaptchaSiteKey' => $recaptcha_site_key,
] ); ?></script>

<?php get_footer();
