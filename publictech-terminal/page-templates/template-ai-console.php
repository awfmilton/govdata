<?php
/**
 * Template Name: PT — AI Console
 *
 * Natural-language interface. Wired to /publictech/v1/ai/query via the
 * mcp-console.js bundle. Paid tiers can execute; free tier sees a CTA.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$pt_quota   = \PublicTech\Core\Access_Tiers::instance()->quota( get_current_user_id() ?: null );
$pt_schemas = \PublicTech\Core\Multi_DB::instance()->aliases();
$pt_can_chart = \PublicTech\Core\Chart_Spec::user_can_create( $pt_quota );

get_header(); ?>

<main>
	<section class="view active" data-pt-view="ai">
		<div class="ai-grid">
			<div>
				<div class="ai-prompt corner">
					<div class="ph"><span class="pulse"></span><?php esc_html_e( 'NATURAL LANGUAGE · TEXT-TO-SQL', 'publictech-terminal' ); ?></div>
					<textarea class="ai-input" id="aiPrompt" placeholder="<?php esc_attr_e( 'e.g. Find all green-tech grants in Ontario over $50k awarded between 2023 and 2025.', 'publictech-terminal' ); ?>"></textarea>
					<div class="ai-actions">
						<span class="hint"><?php esc_html_e( '⏎ to execute · ⇧⏎ for new line', 'publictech-terminal' ); ?></span>
						<button class="run" id="aiRun">▶ <?php esc_html_e( 'Execute Query', 'publictech-terminal' ); ?></button>
					</div>
					<div class="suggestions">
						<div class="sug"><div class="lab">// <?php esc_html_e( 'Suggested', 'publictech-terminal' ); ?></div><?php esc_html_e( 'Compare travel spending of all BC MPs in FY2024', 'publictech-terminal' ); ?></div>
						<div class="sug"><div class="lab">// <?php esc_html_e( 'Suggested', 'publictech-terminal' ); ?></div><?php esc_html_e( 'Vendors that received contracts from more than 3 MPs', 'publictech-terminal' ); ?></div>
						<div class="sug"><div class="lab">// <?php esc_html_e( 'Suggested', 'publictech-terminal' ); ?></div><?php esc_html_e( 'Flag any office expenditures > $10k without invoice records', 'publictech-terminal' ); ?></div>
						<div class="sug"><div class="lab">// <?php esc_html_e( 'Suggested', 'publictech-terminal' ); ?></div><?php esc_html_e( 'Defence contractors across Atlantic ridings', 'publictech-terminal' ); ?></div>
					</div>
				</div>

				<div class="resp-card">
					<div class="hd">
						<div class="tt">◇ <?php esc_html_e( 'Response', 'publictech-terminal' ); ?></div>
						<div class="switch ai-view-switch">
							<button class="on" data-view="chart"><?php esc_html_e( 'Chart',    'publictech-terminal' ); ?></button>
							<button data-view="table"><?php esc_html_e( 'Table',    'publictech-terminal' ); ?></button>
							<button data-view="sql"><?php esc_html_e( 'SQL',       'publictech-terminal' ); ?></button>
							<button data-view="json"><?php esc_html_e( 'Raw JSON', 'publictech-terminal' ); ?></button>
							<?php if ( $pt_can_chart ) : ?>
								<a class="ai-build-chart" id="aiBuildChart" data-pt-build-chart href="<?php echo esc_url( home_url( '/chart-builder' ) ); ?>" hidden>◈ <?php esc_html_e( 'Build chart from these rows', 'publictech-terminal' ); ?></a>
							<?php endif; ?>
						</div>
					</div>
					<div class="bd">
						<div id="aiResponse"><div class="dim"><?php esc_html_e( 'Run a query to see results.', 'publictech-terminal' ); ?></div></div>
					</div>
				</div>
			</div>

			<aside class="ai-side">
				<div class="panel corner">
					<div class="hd"><h3>◇ <?php esc_html_e( 'Connected Schemas', 'publictech-terminal' ); ?></h3><span class="meta"><?php echo esc_html( count( $pt_schemas ) ); ?>/<?php echo esc_html( count( $pt_schemas ) ); ?> OK</span></div>
					<div>
						<?php foreach ( $pt_schemas as $alias ) : ?>
							<div class="kv"><span class="k"><?php echo esc_html( $alias ); ?></span><span class="v" style="color:var(--pos)">live</span></div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="panel corner">
					<div class="hd"><h3>◇ <?php esc_html_e( 'Safety Layer', 'publictech-terminal' ); ?></h3><span class="meta">v<?php echo esc_html( PUBLICTECH_CORE_VERSION ); ?></span></div>
					<div class="bd" style="font-size:11px;color:var(--ink-1);line-height:1.6">
						<?php esc_html_e( 'All queries are auto-parameterised through the parent-theme PDO abstraction. Write operations are rejected at the SQL boundary and at the schema level. Read scope is bounded to your access tier.', 'publictech-terminal' ); ?>
					</div>
				</div>

				<?php if ( ! $pt_quota['mcp'] ) : ?>
					<div class="panel corner">
						<div class="hd"><h3>◇ <?php esc_html_e( 'Upgrade Required', 'publictech-terminal' ); ?></h3></div>
						<div class="bd pt-tier-locked">
							<?php esc_html_e( 'The AI Console is included with paid tiers. Upgrade to query in natural language.', 'publictech-terminal' ); ?><br>
							<a href="<?php echo esc_url( home_url( '/pricing' ) ); ?>"><?php esc_html_e( 'See plans →', 'publictech-terminal' ); ?></a>
						</div>
					</div>
				<?php endif; ?>
			</aside>
		</div>
	</section>
</main>

<?php get_footer();
