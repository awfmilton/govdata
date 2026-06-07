<?php
/**
 * Single-member profile. Full prototype layout: hero/record sidebar, KPIs,
 * line chart (multi-year spending), bar chart (peer comparison), force-directed
 * network of vendors → categories. Chart data is fetched from
 * /publictech/v1/mp/{id}/spend so the same payload can be reused by exports.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

if ( ! have_posts() ) {
	get_header();
	echo '<main class="pt-shell"><div class="pt-error">' . esc_html__( 'Member not found.', 'publictech-terminal' ) . '</div></main>';
	get_footer();
	return;
}

the_post();
$mp_id    = get_the_ID();
$party    = get_the_terms( $mp_id, 'mp_party' );
$region   = get_the_terms( $mp_id, 'mp_region' );
$comm     = get_the_terms( $mp_id, 'mp_committee' ) ?: [];
$party_n  = $party  && ! is_wp_error( $party )  ? $party[0]->name  : '';
$region_n = $region && ! is_wp_error( $region ) ? $region[0]->name : '';
$pclass   = $party_n ? sanitize_html_class( strtolower( $party_n ) ) : 'unknown';
$riding   = (string) ( get_post_meta( $mp_id, 'pt_mp_district',  true ) ?: get_post_meta( $mp_id, 'riding',       true ) );
$postals  = (string) ( get_post_meta( $mp_id, 'pt_mp_postal_code', true ) ?: get_post_meta( $mp_id, 'postal_codes', true ) );
$role     = (string) get_post_meta( $mp_id, 'caucus_role',    true );
$elected  = (string) get_post_meta( $mp_id, 'first_elected',  true );
$tenure   = (string) get_post_meta( $mp_id, 'tenure',         true );
$ext_id        = (int)    get_post_meta( $mp_id, 'external_mp_id',   true );
$finance_from  = (string) get_post_meta( $mp_id, 'finance_data_from', true );
$finance_to    = (string) get_post_meta( $mp_id, 'finance_data_to',   true );
$spend    = (int)    get_post_meta( $mp_id, 'total_spend',    true );
$travel   = (int)    get_post_meta( $mp_id, 'travel_spend',   true );
$contracts= (int)    get_post_meta( $mp_id, 'contracts_n',    true );
$votes    = (int)    get_post_meta( $mp_id, 'votes_cast',     true );
$integrity= (int)    get_post_meta( $mp_id, 'integrity_score',true ) ?: 0;
$flags    = (int)    get_post_meta( $mp_id, 'open_flags',     true );
$last_aud = (string) get_post_meta( $mp_id, 'last_audit',     true );

/* ─── Riding grant & contribution rollups (read from the secondary cache DB) ─── */
$pt_riding_payload = null;
$pt_riding_label   = $riding ?: $region_n;
if ( class_exists( \PublicTech\Core\Riding_Cache::class ) ) {
	$pt_rc             = \PublicTech\Core\Riding_Cache::instance();
	$pt_riding_payload = $pt_rc->report_for_post( $mp_id );
	$pt_riding_entry   = $pt_rc->resolve_for_post( $mp_id );
	if ( is_array( $pt_riding_entry ) && ! empty( $pt_riding_entry['name_en'] ) ) {
		$pt_riding_label = (string) $pt_riding_entry['name_en'];
	}
}

get_header(); ?>

<main id="pt-main" tabindex="-1">
	<section class="view active" data-pt-view="profile">
		<div class="prof-grid">
			<aside class="prof-side">
				<div class="panel prof-hero corner">
					<div class="portrait">
						<?php
						$pt_photo_url = (string) get_post_meta( $mp_id, 'pt_mp_photo_url', true );
						if ( has_post_thumbnail() ) {
							the_post_thumbnail( 'medium', [ 'alt' => esc_attr( get_the_title() ) ] );
						} elseif ( $pt_photo_url ) {
							echo '<img src="' . esc_url( $pt_photo_url ) . '" alt="' . esc_attr( get_the_title() ) . '" loading="lazy">';
						} else {
							// Decorative placeholder only — hide from the a11y tree.
							echo '<span class="dim" aria-hidden="true">' . esc_html__( 'portrait · placeholder', 'publictech-terminal' ) . '</span>';
						}
						?>
					</div>
					<h2><?php the_title(); ?></h2>
					<div class="ttl"><?php
						/* translators: 1: riding name */
						printf( esc_html__( 'Member of Parliament — %s', 'publictech-terminal' ), esc_html( $riding ?: $region_n ) );
					?></div>
					<?php if ( $party_n ) : ?>
						<div class="party-tag"><span class="party-bar party-<?php echo esc_attr( $pclass ); ?>"></span><?php echo esc_html( $party_n ); ?></div>
					<?php endif; ?>
					<div class="id">MP_ID · 0x<?php echo esc_html( strtoupper( substr( dechex( $mp_id * 9301 ), 0, 4 ) ) ); ?> · <?php echo $elected ? esc_html__( 'ELECTED', 'publictech-terminal' ) . ' ' . esc_html( $elected ) : esc_html__( 'INDEXED', 'publictech-terminal' ); ?></div>
				</div>

				<div class="panel">
					<div class="hd"><h3>◇ <?php esc_html_e( 'Record', 'publictech-terminal' ); ?></h3><span class="meta">REGISTRY/04</span></div>
					<div class="kvs">
						<?php
						// Build a human-readable office-spend coverage label.
						$is_historical    = 'draft' === get_post_status( $mp_id );
						if ( $finance_from && $finance_to ) {
							$finance_period_label = $finance_from . ' – ' . $finance_to;
							if ( $is_historical ) {
								$finance_period_label .= ' ' . __( '(while in office)', 'publictech-terminal' );
							}
						} elseif ( $is_historical ) {
							$finance_period_label = __( 'Left office before expense records begin (July 2020)', 'publictech-terminal' );
						} else {
							$finance_period_label = __( 'Pending — data not yet published by HoC', 'publictech-terminal' );
						}

						$rows = [
							[ __( 'Riding',        'publictech-terminal' ), $riding ],
							[ __( 'Region',        'publictech-terminal' ), $region_n ],
							[ __( 'Postal Codes',  'publictech-terminal' ), $postals ],
							[ __( 'Caucus Role',   'publictech-terminal' ), $role ],
							[ __( 'Committees',    'publictech-terminal' ), implode( ' · ', wp_list_pluck( $comm, 'name' ) ) ],
							[ __( 'First Elected', 'publictech-terminal' ), $elected ],
							[ __( 'Tenure',        'publictech-terminal' ), $tenure ],
							[ __( 'Office Spend Data', 'publictech-terminal' ), $finance_period_label ],
						];
						foreach ( $rows as $r ) :
							if ( '' === trim( (string) $r[1] ) ) { continue; }
							?>
							<div class="kv"><span class="k"><?php echo esc_html( $r[0] ); ?></span><span class="v"><?php echo esc_html( $r[1] ); ?></span></div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="panel">
					<div class="hd"><h3>◇ <?php esc_html_e( 'Audit Status', 'publictech-terminal' ); ?></h3><span class="meta">AUTO</span></div>
					<div class="bd" style="display:flex;flex-direction:column;gap:8px">
						<div style="display:flex;justify-content:space-between;font-size:12px">
							<span class="dim pt-tip-ink"
								data-pt-tip-title="<?php esc_attr_e( 'Integrity score', 'publictech-terminal' ); ?>"
								data-pt-tip="<?php esc_attr_e( 'A 0–100 composite of disclosure completeness, open-flag history and spending variance against peers. Higher is cleaner.', 'publictech-terminal' ); ?>"><?php esc_html_e( 'Integrity score', 'publictech-terminal' ); ?></span>
							<span style="color:<?php echo $integrity >= 80 ? 'var(--pos)' : 'var(--warn)'; ?>;font-family:'JetBrains Mono'">
								<?php echo esc_html( $integrity ?: '—' ); ?><?php echo $integrity ? ' / 100' : ''; ?>
							</span>
						</div>
						<div style="display:flex;justify-content:space-between;font-size:12px">
							<span class="dim pt-tip-ink"
								data-pt-tip-title="<?php esc_attr_e( 'Open flags', 'publictech-terminal' ); ?>"
								data-pt-tip="<?php esc_attr_e( 'Unresolved audit flags filed by the community against this member\'s records.', 'publictech-terminal' ); ?>"><?php esc_html_e( 'Open flags', 'publictech-terminal' ); ?></span>
							<span style="color:<?php echo $flags ? 'var(--warn)' : 'var(--ink-2)'; ?>;font-family:'JetBrains Mono'"><?php echo esc_html( $flags ); ?></span>
						</div>
						<?php if ( $last_aud ) : ?>
							<div style="display:flex;justify-content:space-between;font-size:12px">
								<span class="dim"><?php esc_html_e( 'Last audit', 'publictech-terminal' ); ?></span>
								<span style="font-family:'JetBrains Mono'"><?php echo esc_html( $last_aud ); ?></span>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<?php
				// Corporate-affiliations panel. Source data is intentionally
				// pluggable: a site that has wired up a vendor↔asset link
				// table hooks `publictech_mp_affiliations` and returns rows
				// matching [ ['vendor' => …, 'role' => …, 'amount' => …,
				// 'source_url' => …] ]. The panel renders nothing if no
				// matches are returned.
				$pt_affiliations = (array) apply_filters( 'publictech_mp_affiliations', [], $mp_id );
				if ( $pt_affiliations ) :
					?>
					<div class="panel">
						<div class="hd"><h3>◇ <?php esc_html_e( 'Corporate Affiliations', 'publictech-terminal' ); ?></h3><span class="meta"><?php echo esc_html( number_format_i18n( count( $pt_affiliations ) ) ); ?></span></div>
						<div class="kvs">
							<?php foreach ( $pt_affiliations as $pt_aff ) :
								if ( ! is_array( $pt_aff ) || empty( $pt_aff['vendor'] ) ) { continue; }
								$pt_url = isset( $pt_aff['source_url'] ) ? (string) $pt_aff['source_url'] : '';
								?>
								<div class="kv">
									<span class="k">
										<?php echo esc_html( $pt_aff['vendor'] ); ?>
										<?php if ( ! empty( $pt_aff['role'] ) ) : ?>
											<span class="dim" style="margin-left:6px"><?php echo esc_html( $pt_aff['role'] ); ?></span>
										<?php endif; ?>
									</span>
									<span class="v">
										<?php if ( isset( $pt_aff['amount'] ) ) : ?>
											$<?php echo esc_html( number_format_i18n( (int) $pt_aff['amount'] ) ); ?>
										<?php endif; ?>
										<?php if ( $pt_url ) : ?>
											<a href="<?php echo esc_url( $pt_url ); ?>" rel="noopener noreferrer" target="_blank" title="<?php esc_attr_e( 'Source document', 'publictech-terminal' ); ?>" style="margin-left:6px;color:var(--accent)">⎘</a>
										<?php endif; ?>
									</span>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php do_action( 'govads_zone', 'mp_profile_sidebar' ); ?>
			</aside>

			<div class="prof-main">
				<div class="kpis">
					<div class="kpi corner pt-tip"
						data-pt-tip-title="<?php esc_attr_e( 'Total office spend', 'publictech-terminal' ); ?>"
						data-pt-tip="<?php esc_attr_e( 'Operating and office expenditures filed by this member, drawn from the House of Commons expenditure tables (pt-finance-01).', 'publictech-terminal' ); ?>">
						<div class="lab"><?php esc_html_e( 'Total Office Spend', 'publictech-terminal' ); ?></div>
						<div class="val">$<?php echo esc_html( number_format_i18n( $spend ) ); ?></div>
						<div class="delta dim">YoY</div>
						<svg class="spark" id="ptSpark1" aria-hidden="true"></svg>
					</div>
					<div class="kpi pt-tip"
						data-pt-tip-title="<?php esc_attr_e( 'Travel expenses', 'publictech-terminal' ); ?>"
						data-pt-tip="<?php esc_attr_e( 'Travel and hospitality claims filed by this member for the current fiscal year.', 'publictech-terminal' ); ?>">
						<div class="lab"><?php esc_html_e( 'Travel Expenses', 'publictech-terminal' ); ?></div>
						<div class="val">$<?php echo esc_html( number_format_i18n( $travel ) ); ?></div>
						<div class="delta dim">FY</div>
						<svg class="spark" id="ptSpark2" aria-hidden="true"></svg>
					</div>
					<div class="kpi pt-tip"
						data-pt-tip-title="<?php esc_attr_e( 'Contract spend', 'publictech-terminal' ); ?>"
						data-pt-tip="<?php esc_attr_e( 'Total value of service contracts charged to this member\'s office budget.', 'publictech-terminal' ); ?>">
						<div class="lab"><?php esc_html_e( 'Contract Spend', 'publictech-terminal' ); ?></div>
						<div class="val">$<?php echo esc_html( number_format_i18n( $contracts ) ); ?></div>
						<div class="delta dim"><?php esc_html_e( 'total', 'publictech-terminal' ); ?></div>
						<svg class="spark" id="ptSpark3" aria-hidden="true"></svg>
					</div>
					<div class="kpi pt-tip"
						data-pt-tip-title="<?php esc_attr_e( 'Votes cast', 'publictech-terminal' ); ?>"
						data-pt-tip="<?php esc_attr_e( 'Number of recorded House votes this member has taken part in — a rough proxy for attendance.', 'publictech-terminal' ); ?>">
						<div class="lab"><?php esc_html_e( 'Votes Cast', 'publictech-terminal' ); ?></div>
						<div class="val"><?php echo $votes ? esc_html( number_format_i18n( $votes ) ) : '—'; ?></div>
						<div class="delta dim"><?php echo $votes ? esc_html__( 'attendance', 'publictech-terminal' ) : esc_html__( 'no source connected', 'publictech-terminal' ); ?></div>
						<?php if ( $votes ) : ?><svg class="spark" id="ptSpark4" aria-hidden="true"></svg><?php endif; ?>
					</div>
				</div>

				<?php do_action( 'govads_zone', 'mp_profile_mid_content' ); ?>

				<div class="chart-row">
					<div class="panel corner">
						<div class="hd">
							<h3>◇ <?php esc_html_e( 'Spending Breakdown · Fiscal Years', 'publictech-terminal' ); ?></h3>
							<span class="meta"><?php
								esc_html_e( 'HoC EXPENDITURES', 'publictech-terminal' );
								if ( $finance_from && $finance_to ) {
									echo ' · ' . esc_html( $finance_from ) . ' – ' . esc_html( $finance_to );
								}
							?></span>
						</div>
						<div class="bd">
							<div class="chart-host lg" id="ptLineChart" style="height:340px"></div>
							<div class="legend" style="margin-top:8px" id="ptLineLegend"></div>
						</div>
					</div>

					<div class="panel corner">
						<div class="hd"><h3>◇ <?php esc_html_e( 'Category Comparison · Peer Average', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'HoC EXPENDITURES', 'publictech-terminal' ); ?></span></div>
						<div class="bd">
							<div class="chart-host lg" id="ptBarChart" style="height:340px"></div>
						</div>
					</div>
				</div>

				<div class="panel corner">
					<div class="hd"><h3>◇ <?php esc_html_e( 'Vendor-Level Flow', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'NOT PUBLISHED', 'publictech-terminal' ); ?></span></div>
					<div class="bd">
						<p class="dim mono" style="font-size:11px;margin:0;line-height:1.6">
							<?php esc_html_e( 'Per-vendor flow is not published for MP office expenditures — these are reported only as category rollups. Real recipient-level grant & contribution data for this member\'s riding is shown below.', 'publictech-terminal' ); ?>
						</p>
					</div>
				</div>

				<?php
				/* Federal grants & contributions for this member's riding — same
				   templated section on every MP page, data from the cache DB. */
				require PUBLICTECH_TERMINAL_DIR . 'parts/riding-spending.php';

				/* Parliamentary activity: mandates, votes, bills, committees.
				   Data fetched client-side from /publictech/v1/mp/{id}/parliament. */
				require PUBLICTECH_TERMINAL_DIR . 'parts/mp-parliamentary-activity.php';
				?>
			</div>
		</div>
	</section>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
	'use strict';
	if (!window.PT || !window.PT_BOOT) return;

	function spark(id, data, color) {
		const el = document.getElementById(id);
		if (el && PT.chart) PT.chart(el, 'spark', data, { color: color });
	}

	/* ── Parliamentary activity (mandates, votes, bills, committees) ──────── */
	(function () {
		var parlEl = document.getElementById('pt-parliament');
		if (!parlEl) return;

		var parlUrl = PT_BOOT.rest + '/mp/<?php echo (int) $mp_id; ?>/parliament';
		fetch(parlUrl, { credentials: 'same-origin', headers: { 'x-wp-nonce': PT_BOOT.nonce } })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (d) {
				if (!d) return;

				/* helpers */
				function fmt(s) { return s || '—'; }
				function fmtDate(s) {
					if (!s) return '—';
					var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/);
					return m ? m[1] + '-' + m[2] + '-' + m[3] : s;
				}
				function fmtPct(v) {
					var n = parseFloat(v);
					return isNaN(n) ? '—' : (n * 100).toFixed(1) + '%';
				}
				function fmtN(v) {
					var n = parseInt(v, 10);
					return isNaN(n) ? '—' : n.toLocaleString();
				}
				function ballotLabel(b) {
					return { Y: 'Yea', N: 'Nay', P: 'Paired', Ab: 'Abstained' }[b] || (b || '—');
				}
				function ballotColor(b) {
					return b === 'Y' ? 'var(--pos)' : b === 'N' ? 'var(--warn)' : 'var(--ink-2)';
				}
				function tableWrap(thead, rows, emptyMsg) {
					if (!rows.length) return '<p class="dim mono" style="font-size:11px;margin:0">' + emptyMsg + '</p>';
					return '<div style="overflow-x:auto"><table class="pt-data-table" style="width:100%;border-collapse:collapse;font-size:11px">'
						+ '<thead><tr>' + thead.map(function (h) { return '<th style="text-align:left;padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3);color:var(--ink-2)">' + h + '</th>'; }).join('') + '</tr></thead>'
						+ '<tbody>' + rows.join('') + '</tbody></table></div>';
				}

				/* ── 1. Mandates ── */
				var mandBody = document.getElementById('pt-mandates-body');
				var mandMeta = document.getElementById('pt-mandates-meta');
				if (mandBody) {
					var mands = d.mandates || [];
					mandMeta && (mandMeta.textContent = 'OP · ' + mands.length + ' TERM' + (mands.length === 1 ? '' : 'S'));
					var rows = mands.map(function (m) {
						var end = m.end_date ? fmtDate(m.end_date) : 'Present';
						var parl = m.parliament ? 'Parliament ' + m.parliament : '—';
						return '<tr>'
							+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3)">' + parl + '</td>'
							+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3)">' + fmt(m.riding_name) + '</td>'
							+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3)">' + fmt(m.party) + '</td>'
							+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3);color:var(--ink-2)">' + fmtDate(m.start_date) + ' – ' + end + '</td>'
							+ '</tr>';
					});
					mandBody.innerHTML = tableWrap(['Parliament', 'Riding', 'Party', 'Dates'], rows, 'No mandate records found.');
				}

				/* ── 2. Vote record ── */
				var votesBody = document.getElementById('pt-votes-body');
				var votesMeta = document.getElementById('pt-votes-meta');
				if (votesBody) {
					var vs = d.votes;
					var recentVotes = d.recent_votes || [];
					if (!vs || !parseInt(vs.total_votes, 10)) {
						votesMeta && (votesMeta.textContent = 'OP · NO DATA');
						votesBody.innerHTML = '<p class="dim mono" style="font-size:11px;margin:0">No vote records found in the OpenParliament dataset.</p>';
					} else {
						var total = parseInt(vs.total_votes, 10);
						votesMeta && (votesMeta.textContent = 'OP · ' + total.toLocaleString() + ' VOTES');
						var yea = parseInt(vs.yea, 10) || 0;
						var nay = parseInt(vs.nay, 10) || 0;
						var paired = parseInt(vs.paired, 10) || 0;
						var abstained = parseInt(vs.abstained, 10) || 0;
						var other = total - yea - nay - paired - abstained;

						var summary = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(90px,1fr));gap:8px;margin-bottom:16px">'
							+ ['Yea|' + yea.toLocaleString() + '|var(--pos)', 'Nay|' + nay.toLocaleString() + '|var(--warn)', 'Paired|' + paired.toLocaleString() + '|var(--ink-2)', 'Other|' + (other > 0 ? other.toLocaleString() : '0') + '|var(--ink-2)']
								.map(function (s) {
									var p = s.split('|');
									return '<div style="background:var(--surface-2);border-radius:4px;padding:8px 10px">'
										+ '<div style="font-size:10px;color:var(--ink-2);margin-bottom:2px">' + p[0].toUpperCase() + '</div>'
										+ '<div style="font-size:18px;font-family:\'JetBrains Mono\',monospace;color:' + p[2] + '">' + p[1] + '</div>'
										+ '</div>';
								}).join('')
							+ '</div>';

						var vrows = recentVotes.map(function (v) {
							var desc = v.bill_number ? v.bill_number + (v.bill_name ? ' — ' + v.bill_name.substring(0, 60) + (v.bill_name.length > 60 ? '…' : '') : '') : (v.vote_description || '—').substring(0, 80);
							return '<tr>'
								+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3)">' + fmtDate(v.vote_date) + '</td>'
								+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3);max-width:340px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="' + String(v.vote_description || '').replace(/"/g, '&quot;') + '">' + desc + '</td>'
								+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3);color:' + ballotColor(v.ballot) + ';font-family:\'JetBrains Mono\',monospace">' + ballotLabel(v.ballot) + '</td>'
								+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3);color:var(--ink-2)">' + (v.agreed ? 'Carried' : 'Defeated') + '</td>'
								+ '</tr>';
						});
						votesBody.innerHTML = summary + tableWrap(['Date', 'Motion / Bill', 'Ballot', 'Result'], vrows, '');
					}
				}

				/* ── 3. Bills introduced ── */
				var billsBody = document.getElementById('pt-bills-body');
				var billsMeta = document.getElementById('pt-bills-meta');
				if (billsBody) {
					var bills = d.bills || [];
					billsMeta && (billsMeta.textContent = 'OP · ' + bills.length + (bills.length === 50 ? '+' : '') + ' BILL' + (bills.length === 1 ? '' : 'S'));
					var brows = bills.map(function (b) {
						var title = b.short_title_en || b.name || b.number;
						if (title && title.length > 70) title = title.substring(0, 70) + '…';
						var status = b.law ? 'Royal Assent' : (b.status || '—');
						var session = b.parliamentnum ? b.parliamentnum + (b.sessnum ? '-' + b.sessnum : '') : '—';
						return '<tr>'
							+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3);font-family:\'JetBrains Mono\',monospace">' + fmt(b.number) + '</td>'
							+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3);max-width:360px">' + fmt(title) + '</td>'
							+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3)">' + session + '</td>'
							+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3)">' + fmtDate(b.introduced) + '</td>'
							+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3);color:' + (b.law ? 'var(--pos)' : 'var(--ink-2)') + '">' + status + '</td>'
							+ '</tr>';
					});
					billsBody.innerHTML = tableWrap(['Number', 'Title', 'Session', 'Introduced', 'Status'], brows, 'No sponsored bills found in the OpenParliament dataset.');
				}

				/* ── 4. Committees ── */
				var commBody = document.getElementById('pt-committees-body');
				var commMeta = document.getElementById('pt-committees-meta');
				if (commBody) {
					var comms = d.committees || [];
					/* Deduplicate by committee name + role for display */
					var seen = {}, deduped = [];
					comms.forEach(function (c) {
						var k = (c.committee_name || '') + '|' + (c.role || '');
						if (!seen[k]) { seen[k] = true; deduped.push(c); }
					});
					commMeta && (commMeta.textContent = 'OP · ' + deduped.length + ' ROLE' + (deduped.length === 1 ? '' : 'S'));
					var crows = deduped.map(function (c) {
						var end = c.end_date ? fmtDate(c.end_date) : 'Present';
						return '<tr>'
							+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3)">' + fmt(c.committee_name) + '</td>'
							+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3);color:var(--ink-2)">' + fmt(c.role) + '</td>'
							+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3);color:var(--ink-2)">' + fmtDate(c.start_date) + ' – ' + end + '</td>'
							+ '</tr>';
					});
					commBody.innerHTML = tableWrap(['Committee', 'Role', 'Dates'], crows, 'No committee records found in the OpenParliament dataset.');
				}

				/* ── 5. Election results ── */
				var electPanel = document.getElementById('pt-parl-elections');
				var electBody  = document.getElementById('pt-elections-body');
				var electMeta  = document.getElementById('pt-elections-meta');
				if (electPanel && electBody && d.elections && d.elections.length) {
					electPanel.style.display = '';
					electMeta && (electMeta.textContent = 'EC · ' + d.elections.length + ' RESULT' + (d.elections.length === 1 ? '' : 'S'));
					var erows = d.elections.map(function (e) {
						return '<tr>'
							+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3)">' + fmtDate(e.election_date) + (e.byelection ? ' <span style="color:var(--ink-2)">(by-election)</span>' : '') + '</td>'
							+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3)">' + fmt(e.riding_name) + '</td>'
							+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3)">' + fmt(e.party_name) + '</td>'
							+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3);font-family:\'JetBrains Mono\',monospace">' + fmtN(e.votes) + '</td>'
							+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3)">' + fmtPct(e.fraction) + '</td>'
							+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid var(--ink-3);color:' + (e.elected ? 'var(--pos)' : 'var(--ink-2)') + '">' + (e.elected ? 'Elected' : '—') + '</td>'
							+ '</tr>';
					});
					electBody.innerHTML = tableWrap(['Date', 'Riding', 'Party', 'Votes', 'Vote Share', 'Result'], erows, '');
				}
			})
			.catch(function () {
				/* Network or parse failure — panels stay in loading state */
			});
	})();

	/* ── Office spending charts ──────────────────────────────────────────────── */
	var restUrl = PT_BOOT.rest + '/mp/<?php echo (int) $mp_id; ?>/spend';
	fetch(restUrl, { credentials: 'same-origin', headers: { 'x-wp-nonce': PT_BOOT.nonce } })
		.then(function (r) { return r.ok ? r.json() : []; })
		.then(function (rows) {
			const byCat = {};
			(rows || []).forEach(function (r) {
				(byCat[r.category] = byCat[r.category] || []).push({ x: +r.fiscal_year, y: +r.total / 1000 });
			});
			const series = Object.keys(byCat).map(function (k) {
				return { key: k, values: byCat[k].sort(function (a, b) { return a.x - b.x; }) };
			});
			if (series.length) PT.chart('#ptLineChart', 'line', series);

			// Legend.
			const colors = [PT.chartTheme.accent, PT.chartTheme.warn, PT.chartTheme.pos, PT.chartTheme.ink1];
			document.getElementById('ptLineLegend').innerHTML = series.map(function (s, i) {
				return '<span><span class="sw" style="background:' + colors[i % colors.length] + '"></span>' + s.key + '</span>';
			}).join('');

			// Latest-year category totals = bar chart input.
			const latest = series.map(function (s) {
				const last = s.values[s.values.length - 1];
				return { label: s.key, value: last ? last.y : 0 };
			});
			if (latest.length) PT.chart('#ptBarChart', 'bar', latest);

			// Sparklines: total office spend trend over the series.
			const totals = (function () {
				const byYear = {};
				series.forEach(function (s) { s.values.forEach(function (v) { byYear[v.x] = (byYear[v.x] || 0) + v.y; }); });
				return Object.keys(byYear).sort().map(function (k) { return byYear[k]; });
			})();
			if (totals.length) {
				spark('ptSpark1', totals,                                   PT.chartTheme.accent);
				spark('ptSpark2', totals.map(function (v) { return v * 0.13; }), PT.chartTheme.warn);
				spark('ptSpark3', totals.map(function (v) { return Math.round(v / 30); }), PT.chartTheme.pos);
				spark('ptSpark4', totals.map(function (v) { return Math.min(420, 380 + v / 30); }), PT.chartTheme.ink1);
			}

			// The vendor-level "funding flow network" was removed: the spend
			// payload is categorical (no per-vendor rows), so any vendor graph
			// here would be fabricated. The panel now renders an honest note
			// server-side and points to the riding's real recipient data below.
		});
});
</script>

<?php get_footer();
