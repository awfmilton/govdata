<?php
/**
 * Parliamentary activity panels for the MP profile page.
 *
 * Renders HTML skeletons with data-pt-parl-* attributes. JavaScript in
 * single-member-parliament.php fetches /publictech/v1/mp/{id}/parliament
 * and injects the data at runtime.
 *
 * Expects the including template to have set:
 *   $mp_id     (int)  WordPress post ID of the member-parliament post.
 *
 * Data shown (all sourced from the grantconts OpenParliament tables):
 *   • Electoral mandate history  — mp_mandates + op_core_riding
 *   • Vote record                — op_votes_membervote + op_votes_vote
 *   • Bills introduced           — op_bills_bill + op_core_session
 *   • Committee memberships      — op_committees_committeemembership
 *   • Election results           — op_elections_electionresult (if available)
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$pt_mp_id = isset( $mp_id ) ? (int) $mp_id : 0;
if ( $pt_mp_id <= 0 ) {
	return;
}

$pt_op_pol_id = (int) get_post_meta( $pt_mp_id, 'op_politician_id', true );
?>

<div id="pt-parliament" data-mp-id="<?php echo esc_attr( $pt_mp_id ); ?>" style="margin-top:24px">

	<?php if ( ! $pt_op_pol_id ) : ?>
		<div class="panel corner">
			<div class="hd"><h3>◇ <?php esc_html_e( 'Parliamentary Record', 'publictech-terminal' ); ?></h3><span class="meta">OP</span></div>
			<div class="bd" style="font-size:12px;color:var(--ink-2);line-height:1.6">
				<?php esc_html_e( 'Parliamentary record not yet linked to the OpenParliament dataset for this member.', 'publictech-terminal' ); ?>
			</div>
		</div>
	<?php return; endif; ?>

	<!-- ── Electoral Mandate History ──────────────────────────────────────── -->
	<div class="panel corner" id="pt-parl-mandates">
		<div class="hd">
			<h3>◇ <?php esc_html_e( 'Electoral Mandate History', 'publictech-terminal' ); ?></h3>
			<span class="meta" id="pt-mandates-meta">OP · LOADING</span>
		</div>
		<div class="bd" id="pt-mandates-body">
			<div class="pt-parl-loading dim mono" style="font-size:11px"><?php esc_html_e( 'Loading…', 'publictech-terminal' ); ?></div>
		</div>
	</div>

	<!-- ── Vote Record ────────────────────────────────────────────────────── -->
	<div class="panel corner" id="pt-parl-votes" style="margin-top:18px">
		<div class="hd">
			<h3>◇ <?php esc_html_e( 'Parliamentary Vote Record', 'publictech-terminal' ); ?></h3>
			<span class="meta" id="pt-votes-meta">OP · LOADING</span>
		</div>
		<div class="bd" id="pt-votes-body">
			<div class="pt-parl-loading dim mono" style="font-size:11px"><?php esc_html_e( 'Loading…', 'publictech-terminal' ); ?></div>
		</div>
	</div>

	<!-- ── Bills Introduced ───────────────────────────────────────────────── -->
	<div class="panel corner" id="pt-parl-bills" style="margin-top:18px">
		<div class="hd">
			<h3>◇ <?php esc_html_e( 'Bills Introduced', 'publictech-terminal' ); ?></h3>
			<span class="meta" id="pt-bills-meta">OP · LOADING</span>
		</div>
		<div class="bd" id="pt-bills-body">
			<div class="pt-parl-loading dim mono" style="font-size:11px"><?php esc_html_e( 'Loading…', 'publictech-terminal' ); ?></div>
		</div>
	</div>

	<!-- ── Committee Memberships ──────────────────────────────────────────── -->
	<div class="panel corner" id="pt-parl-committees" style="margin-top:18px">
		<div class="hd">
			<h3>◇ <?php esc_html_e( 'Committee Memberships', 'publictech-terminal' ); ?></h3>
			<span class="meta" id="pt-committees-meta">OP · LOADING</span>
		</div>
		<div class="bd" id="pt-committees-body">
			<div class="pt-parl-loading dim mono" style="font-size:11px"><?php esc_html_e( 'Loading…', 'publictech-terminal' ); ?></div>
		</div>
	</div>

	<!-- ── Election Results (popular vote) ────────────────────────────────── -->
	<div class="panel corner" id="pt-parl-elections" style="margin-top:18px;display:none">
		<div class="hd">
			<h3>◇ <?php esc_html_e( 'Election Results', 'publictech-terminal' ); ?></h3>
			<span class="meta" id="pt-elections-meta">EC</span>
		</div>
		<div class="bd" id="pt-elections-body"></div>
	</div>

</div><!-- #pt-parliament -->
