<?php
/**
 * Template Name: PT — About the Project
 *
 * A static, content-only page that explains what the project is, what it does,
 * the data-engineering work behind it (cleaning, deduplication, normalization,
 * full-text search, the postal-code → federal-riding spatial match, and the
 * parliamentary linkage), and how it differs from the raw government open-data
 * portals.
 *
 * Intentionally describes the *methodology and data provenance* only — no host
 * names, credentials, connection topology, internal schema names, or other
 * back-end infrastructure are referenced here. Renders without touching any
 * database, so it is always available even if the data tier is offline.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

get_header(); ?>

<main>
<section class="view active" data-pt-view="about">

	<!-- ═══ HERO ══════════════════════════════════════════════════════════ -->
	<div class="hero corner" style="margin-bottom:18px">
		<div class="hero-grid">
			<div>
				<div class="mono uc" style="color:var(--accent);font-size:10px;letter-spacing:0.22em;margin-bottom:10px">
					◇ <?php esc_html_e( 'About · Public Records · Audit Workspace', 'publictech-terminal' ); ?>
				</div>
				<h2 style="margin:0 0 10px;font-size:26px;font-weight:700;letter-spacing:0.02em">
					<?php
					/* translators: %s: site name */
					printf( esc_html__( 'What %s is, and the work behind it', 'publictech-terminal' ), '<span class="acc">' . esc_html( get_bloginfo( 'name' ) ) . '</span>' );
					?>
				</h2>
				<p class="lede" style="max-width:680px">
					<?php esc_html_e( 'An independent public-records terminal that makes Canada\'s federal grants, contributions, and Members of Parliament genuinely searchable — unified across two decades, cleaned, linked to the federal riding each dollar touched, and connected to the MPs who represented those ridings over time.', 'publictech-terminal' ); ?>
				</p>
				<div class="ctas">
					<a class="cta ghost" href="<?php echo esc_url( get_post_type_archive_link( 'member-parliament' ) ); ?>">⌕ <?php esc_html_e( 'Browse MPs', 'publictech-terminal' ); ?></a>
					<a class="cta ghost" href="<?php echo esc_url( home_url( '/stream' ) ); ?>">≡ <?php esc_html_e( 'Stream Transactions', 'publictech-terminal' ); ?></a>
					<a class="cta ghost" href="<?php echo esc_url( home_url( '/ai' ) ); ?>">▶ <?php esc_html_e( 'Ask the AI Console', 'publictech-terminal' ); ?></a>
				</div>
			</div>
			<div class="hero-stats">
				<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Agreements', 'publictech-terminal' ); ?></div><div class="val">1.26M</div></div>
				<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Coverage', 'publictech-terminal' ); ?></div><div class="val">2004+</div></div>
				<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Ridings', 'publictech-terminal' ); ?></div><div class="val">343</div></div>
				<div class="hero-stat"><div class="lab"><?php esc_html_e( 'Parliaments', 'publictech-terminal' ); ?></div><div class="val">35–45</div></div>
			</div>
		</div>
	</div>

	<!-- ═══ KPI STRIP — HEADLINE NUMBERS ══════════════════════════════════ -->
	<div class="kpis" style="margin-bottom:18px">
		<div class="kpi corner">
			<div class="lab"><?php esc_html_e( 'Grant & Contribution Agreements', 'publictech-terminal' ); ?></div>
			<div class="val" style="font-size:20px">1,264,823</div>
			<div class="delta dim"><?php esc_html_e( 'Cleaned, de-duplicated, one record per agreement', 'publictech-terminal' ); ?></div>
		</div>
		<div class="kpi">
			<div class="lab"><?php esc_html_e( 'Federal Ridings Mapped', 'publictech-terminal' ); ?></div>
			<div class="val">343 + 338</div>
			<div class="delta dim"><?php esc_html_e( '2023 and 2013 boundary sets', 'publictech-terminal' ); ?></div>
		</div>
		<div class="kpi">
			<div class="lab"><?php esc_html_e( 'Parliamentarians', 'publictech-terminal' ); ?></div>
			<div class="val">1,345</div>
			<div class="delta dim"><?php esc_html_e( '1,892 mandates · 1994 to today', 'publictech-terminal' ); ?></div>
		</div>
		<div class="kpi">
			<div class="lab"><?php esc_html_e( 'MP Profile Pages', 'publictech-terminal' ); ?></div>
			<div class="val">394</div>
			<div class="delta dim"><?php esc_html_e( 'Each linked to its riding\'s spending', 'publictech-terminal' ); ?></div>
		</div>
	</div>

	<!-- ═══ WHAT IT IS ════════════════════════════════════════════════════ -->
	<div class="panel corner" style="margin-bottom:14px">
		<div class="hd"><h3>◇ <?php esc_html_e( 'What this is', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'THE SHORT VERSION', 'publictech-terminal' ); ?></span></div>
		<div class="bd" style="font-size:13px;color:var(--ink-1);line-height:1.75;display:flex;flex-direction:column;gap:12px">
			<p style="margin:0">
				The Government of Canada already publishes a great deal of spending data — every grant and
				contribution agreement, every quarter, as part of its proactive-disclosure obligations. The
				problem is that the data is published the way a <em>filing system</em> works, not the way a
				<em>question</em> works. It arrives as hundreds of separate spreadsheets, one per department per
				period, with shifting columns, inconsistent encodings, duplicate and amended rows, and — critically —
				no connection between a dollar that was spent and the place or the elected representative it touched.
			</p>
			<p style="margin:0">
				This project takes that raw material and turns it into a single, coherent, queryable record of
				public money. It unifies roughly <strong style="color:var(--ink-0)">1.26&nbsp;million</strong> grant
				and contribution agreements spanning agreement-start years from <strong style="color:var(--ink-0)">2004 to
				the present</strong>, cleans and de-duplicates them, ties every agreement to the
				<strong style="color:var(--ink-0)">federal electoral district</strong> it falls in, and connects every
				riding to the <strong style="color:var(--ink-0)">Members of Parliament</strong> who have represented it
				across the last three decades. The result is a public-records terminal where you can ask
				"how much federal money flowed into <em>this</em> riding, under <em>which</em> programs, to
				<em>whom</em>, and while <em>which</em> MP held the seat?" — and get an answer in seconds.
			</p>
		</div>
	</div>

	<!-- ═══ WHAT IT DOES ══════════════════════════════════════════════════ -->
	<div class="panel corner" style="margin-bottom:14px">
		<div class="hd"><h3>◇ <?php esc_html_e( 'What it does', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'FOR THE PUBLIC', 'publictech-terminal' ); ?></span></div>
		<div class="bd">
			<div class="featured" style="margin-top:4px">
				<div class="featured-q">
					<div class="lab">// <?php esc_html_e( 'MP Directory & Profiles', 'publictech-terminal' ); ?></div>
					<div class="q"><?php esc_html_e( 'Search every Member of Parliament by party, region, and postal code. Each profile shows office-expenditure trends and the grant & contribution spending in that member\'s riding.', 'publictech-terminal' ); ?></div>
				</div>
				<div class="featured-q">
					<div class="lab">// <?php esc_html_e( 'Riding Reports', 'publictech-terminal' ); ?></div>
					<div class="q"><?php esc_html_e( 'One report per federal riding: all-time totals, spending by year, top recipients, recipient-type split, leading programs, and a scrutiny-keyword scan.', 'publictech-terminal' ); ?></div>
				</div>
				<div class="featured-q">
					<div class="lab">// <?php esc_html_e( 'Recipient / Organisation Pages', 'publictech-terminal' ); ?></div>
					<div class="q"><?php esc_html_e( 'Per-recipient funding histories — totals by year, by program, and by department — for the organisations that received public money.', 'publictech-terminal' ); ?></div>
				</div>
				<div class="featured-q">
					<div class="lab">// <?php esc_html_e( 'Streaming Transactions', 'publictech-terminal' ); ?></div>
					<div class="q"><?php esc_html_e( 'Scroll through the raw agreement records directly — millions of rows streamed without waiting for the whole result set to load.', 'publictech-terminal' ); ?></div>
				</div>
				<div class="featured-q">
					<div class="lab">// <?php esc_html_e( 'AI Console & Chart Builder', 'publictech-terminal' ); ?></div>
					<div class="q"><?php esc_html_e( 'Ask questions in plain language and get a table or chart back, or build and save your own visualisations from the underlying data.', 'publictech-terminal' ); ?></div>
				</div>
				<div class="featured-q">
					<div class="lab">// <?php esc_html_e( 'Open Query Access', 'publictech-terminal' ); ?></div>
					<div class="q"><?php esc_html_e( 'A read-only programmatic endpoint lets analysts, journalists, and AI assistants query the same cleaned data conversationally.', 'publictech-terminal' ); ?></div>
				</div>
			</div>
		</div>
	</div>

	<!-- ═══ THE DATA ENGINEERING STORY ════════════════════════════════════ -->
	<div class="panel corner" style="margin-bottom:14px">
		<div class="hd"><h3>◇ <?php esc_html_e( 'The work behind it — from raw files to a queryable record', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'DATA ENGINEERING', 'publictech-terminal' ); ?></span></div>
		<div class="bd" style="font-size:13px;color:var(--ink-1);line-height:1.75;display:flex;flex-direction:column;gap:14px">
			<p style="margin:0">
				Most of the effort in this project is invisible on the surface: it is the data engineering that
				turns dozens of messy, government-issued spreadsheets into one trustworthy dataset. That work runs
				as a repeatable pipeline, and each stage solves a real problem in the source data.
			</p>

			<div style="overflow-x:auto">
				<table class="stream" style="min-width:680px">
					<thead>
						<tr>
							<th style="width:200px"><?php esc_html_e( 'Stage', 'publictech-terminal' ); ?></th>
							<th><?php esc_html_e( 'What it solves', 'publictech-terminal' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td style="font-weight:600;color:var(--ink-0)"><?php esc_html_e( '1 · Ingest & standardise', 'publictech-terminal' ); ?></td>
							<td><?php esc_html_e( 'Annual exports arrive with drifting column layouts and a mix of comma- and pipe-delimited files. The pipeline detects the format of each file and reads them all into one consistent shape.', 'publictech-terminal' ); ?></td>
						</tr>
						<tr>
							<td style="font-weight:600;color:var(--ink-0)"><?php esc_html_e( '2 · Clean', 'publictech-terminal' ); ?></td>
							<td><?php esc_html_e( 'Empty-value placeholders ("-", "N/A", year-zero dates) are unified to true nulls; timestamps are normalised to plain dates; HTML entities in names and titles are decoded; dollar figures are parsed to exact decimals; and verbose category text is mapped to consistent codes.', 'publictech-terminal' ); ?></td>
						</tr>
						<tr>
							<td style="font-weight:600;color:var(--ink-0)"><?php esc_html_e( '3 · De-duplicate', 'publictech-terminal' ); ?></td>
							<td><?php esc_html_e( 'Reference numbers repeat across departments and amendments re-state the same agreement many times. Records are keyed by a department-aware composite identifier so each agreement — and each later amendment to it — is counted once and only once.', 'publictech-terminal' ); ?></td>
						</tr>
						<tr>
							<td style="font-weight:600;color:var(--ink-0)"><?php esc_html_e( '4 · Normalise', 'publictech-terminal' ); ?></td>
							<td><?php esc_html_e( 'A single flat table is reshaped into a proper relational model: recipients, programs, locations, and industry codes are each de-duplicated into their own reference tables, with agreements and amendments linked back to them.', 'publictech-terminal' ); ?></td>
						</tr>
						<tr>
							<td style="font-weight:600;color:var(--ink-0)"><?php esc_html_e( '5 · Index for search', 'publictech-terminal' ); ?></td>
							<td><?php esc_html_e( 'Full-text indexing across recipient names, titles, programs, and descriptions turns scans that once took minutes into sub-second lookups, so free-text search across all 1.26 million records is instant.', 'publictech-terminal' ); ?></td>
						</tr>
						<tr>
							<td style="font-weight:600;color:var(--ink-0)"><?php esc_html_e( '6 · Verify & track changes', 'publictech-terminal' ); ?></td>
							<td><?php esc_html_e( 'Every refresh is checked row-for-row against the source (counts, dollar totals, valid codes), and every field change is logged — distinguishing a genuine government revision from a cleaning correction — so the dataset has an auditable history.', 'publictech-terminal' ); ?></td>
						</tr>
						<tr>
							<td style="font-weight:600;color:var(--ink-0)"><?php esc_html_e( '7 · Pre-compute', 'publictech-terminal' ); ?></td>
							<td><?php esc_html_e( 'Riding, MP, and recipient rollups are calculated ahead of time on a schedule, so public pages render from a fast precomputed snapshot instead of running heavy aggregates on every visit.', 'publictech-terminal' ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>

			<p style="margin:0;font-size:11px;color:var(--ink-2)">
				<?php esc_html_e( 'A guarded one-pass deduplication of the largest yearly files brought the working dataset down to its final 1,264,823 unique agreements; full-text search keywords are matched one term at a time to stay within the search engine\'s result limits and still return instantly.', 'publictech-terminal' ); ?>
			</p>
		</div>
	</div>

	<!-- ═══ THE POSTAL CODE → RIDING MATCH (THE HEADLINE WORK) ════════════ -->
	<div class="panel corner" style="margin-bottom:14px;border-color:var(--accent)">
		<div class="hd"><h3>◇ <?php esc_html_e( 'Matching postal codes to riding boundaries', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'THE KEY GEOGRAPHIC WORK', 'publictech-terminal' ); ?></span></div>
		<div class="bd" style="font-size:13px;color:var(--ink-1);line-height:1.75;display:flex;flex-direction:column;gap:14px">
			<p style="margin:0">
				This is the piece of work that makes everything else possible, and the part the government's own
				portal does not do for you. The grants data records <em>where</em> money went only as a
				<strong style="color:var(--ink-0)">postal code</strong>. It never says which
				<strong style="color:var(--ink-0)">federal riding</strong> that is — and ridings are redrawn every
				decade or so, so even the boundaries themselves are a moving target. The official lookup that bridges
				the two (the Postal Code Federal Riding File) is not published on the open-data portal; it is
				distributed only on request. So the project <strong style="color:var(--accent)">built its own, from
				open data</strong>.
			</p>

			<div class="kvs" style="margin:0">
				<div class="kv"><span class="k" style="color:var(--accent)"><?php esc_html_e( 'Step 1 — Postal-code points', 'publictech-terminal' ); ?></span><span class="v" style="font-size:10px;color:var(--ink-2)"><?php esc_html_e( 'OPEN GEONAMES DATA', 'publictech-terminal' ); ?></span></div>
				<div class="kv" style="display:block;padding:10px 12px;font-size:12px;line-height:1.6">
					<?php esc_html_e( 'An open national gazetteer provides a latitude and longitude for every Canadian postal code. The pipeline reduces this to one representative geographic point per postal code and normalises the formatting to match the rest of the dataset.', 'publictech-terminal' ); ?>
				</div>

				<div class="kv"><span class="k" style="color:var(--accent)"><?php esc_html_e( 'Step 2 — Convert the boundary shapefile', 'publictech-terminal' ); ?></span><span class="v" style="font-size:10px;color:var(--ink-2)"><?php esc_html_e( 'ELECTIONS CANADA · SHP → POLYGONS', 'publictech-terminal' ); ?></span></div>
				<div class="kv" style="display:block;padding:10px 12px;font-size:12px;line-height:1.6">
					<?php esc_html_e( 'Elections Canada distributes the federal electoral-district boundaries as a shapefile — a bundle of geometry, attribute, projection, and index files describing each riding as a polygon. The pipeline reads those polygons (343 of them in the current 2023 boundary set) and re-projects both the polygons and the postal-code points into one common coordinate system (WGS 84 / latitude–longitude) so they can be measured against each other.', 'publictech-terminal' ); ?>
				</div>

				<div class="kv"><span class="k" style="color:var(--accent)"><?php esc_html_e( 'Step 3 — Point-in-polygon spatial join', 'publictech-terminal' ); ?></span><span class="v" style="font-size:10px;color:var(--ink-2)"><?php esc_html_e( 'GIS · WITHIN', 'publictech-terminal' ); ?></span></div>
				<div class="kv" style="display:block;padding:10px 12px;font-size:12px;line-height:1.6">
					<?php esc_html_e( 'A spatial join tests every postal-code point against every riding polygon and records which riding each point falls inside. Points that land outside all boundaries are flagged and dropped; the rest become a clean postal-code → riding lookup, carrying both the riding number and its English and French names.', 'publictech-terminal' ); ?>
				</div>

				<div class="kv"><span class="k" style="color:var(--accent)"><?php esc_html_e( 'Step 4 — Tag by era and load', 'publictech-terminal' ); ?></span><span class="v" style="font-size:10px;color:var(--ink-2)"><?php esc_html_e( 'REPRESENTATION ORDERS', 'publictech-terminal' ); ?></span></div>
				<div class="kv" style="display:block;padding:10px 12px;font-size:12px;line-height:1.6">
					<?php esc_html_e( 'The generated lookup is tagged with the boundary era it belongs to — the 2013 Representation Order (338 ridings) and the 2023 Representation Order (343 ridings) are both modelled — and loaded so that spending can be attributed to the correct riding for the correct period, even across a redistribution.', 'publictech-terminal' ); ?>
				</div>
			</div>

			<p style="margin:0">
				With that lookup in place, the chain is complete: a recipient's postal code resolves to a riding,
				the riding resolves to the MPs who held it in each era, and a single agreement signed in one year is
				attributed to the riding boundaries that were actually in effect when it was signed. A postal code
				that straddles two ridings is counted toward both. This is the mechanism behind every riding report
				and the riding-spending panel on every MP profile.
			</p>
		</div>
	</div>

	<!-- ═══ MPS & RIDINGS OVER TIME ═══════════════════════════════════════ -->
	<div class="panel corner" style="margin-bottom:14px">
		<div class="hd"><h3>◇ <?php esc_html_e( 'MPs and ridings, over time', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'A TEMPORAL MODEL, NOT A SNAPSHOT', 'publictech-terminal' ); ?></span></div>
		<div class="bd" style="font-size:13px;color:var(--ink-1);line-height:1.75;display:flex;flex-direction:column;gap:12px">
			<p style="margin:0">
				Parliament is not a snapshot — people are elected, re-elected, change parties, and switch ridings,
				and the ridings themselves are redrawn. To reflect that, the project models a person separately from
				their <em>mandates</em> (their individual terms in office). It assembles
				<strong style="color:var(--ink-0)">1,345 unique parliamentarians</strong> and
				<strong style="color:var(--ink-0)">1,892 mandates</strong> covering parliaments 35 through 45
				(1994 to today), reconciling several independent sources — an open parliamentary dataset, a roster of
				sitting members with their contact details and photos, and historical records — into one clean
				history, with name spellings, accents, and hyphenation matched across them.
			</p>
			<p style="margin:0;font-size:12px;color:var(--ink-2)">
				<?php esc_html_e( 'Because each agreement is attributed to the boundary era in effect when it was signed, the same model lets you ask a question by MP, by time period, or by riding — and, optionally, remap all spending onto a single chosen boundary set to compare like-for-like across decades.', 'publictech-terminal' ); ?>
			</p>
		</div>
	</div>

	<!-- ═══ HOW IT'S DIFFERENT ════════════════════════════════════════════ -->
	<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px" class="about-compare">
		<div class="panel corner">
			<div class="hd"><h3>◇ <?php esc_html_e( 'The government open-data portals', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'THE RAW SOURCE', 'publictech-terminal' ); ?></span></div>
			<div class="kvs">
				<div class="kv" style="display:block;padding:9px 12px;font-size:12px;line-height:1.55"><?php esc_html_e( 'Data is split into hundreds of separate files — by department, by year, by quarter — with column layouts that drift over time.', 'publictech-terminal' ); ?></div>
				<div class="kv" style="display:block;padding:9px 12px;font-size:12px;line-height:1.55"><?php esc_html_e( 'Duplicate rows, amendments, and cross-department reference collisions are left in; totals double-count unless you clean them yourself.', 'publictech-terminal' ); ?></div>
				<div class="kv" style="display:block;padding:9px 12px;font-size:12px;line-height:1.55"><?php esc_html_e( 'Location is given as a postal code only — there is no link to a federal riding, and no link to a Member of Parliament.', 'publictech-terminal' ); ?></div>
				<div class="kv" style="display:block;padding:9px 12px;font-size:12px;line-height:1.55"><?php esc_html_e( 'You search one dataset at a time; cross-year analysis, charts, and plain-language questions are on you.', 'publictech-terminal' ); ?></div>
				<div class="kv" style="display:block;padding:9px 12px;font-size:12px;line-height:1.55"><?php esc_html_e( 'Best suited to downloading bulk files and doing your own processing.', 'publictech-terminal' ); ?></div>
			</div>
		</div>
		<div class="panel corner" style="border-color:var(--accent)">
			<div class="hd"><h3>◇ <?php echo esc_html( get_bloginfo( 'name' ) ); ?></h3><span class="meta" style="color:var(--accent)"><?php esc_html_e( 'WHAT WE ADD', 'publictech-terminal' ); ?></span></div>
			<div class="kvs">
				<div class="kv" style="display:block;padding:9px 12px;font-size:12px;line-height:1.55;color:var(--ink-0)"><?php esc_html_e( 'Two decades of files unified into one consistent, cleaned record of 1.26 million agreements.', 'publictech-terminal' ); ?></div>
				<div class="kv" style="display:block;padding:9px 12px;font-size:12px;line-height:1.55;color:var(--ink-0)"><?php esc_html_e( 'De-duplicated and normalised, with an auditable change history separating government revisions from cleaning corrections.', 'publictech-terminal' ); ?></div>
				<div class="kv" style="display:block;padding:9px 12px;font-size:12px;line-height:1.55;color:var(--ink-0)"><?php esc_html_e( 'Every agreement geocoded to its federal riding via a purpose-built postal-code → boundary match, then linked to the MPs who held that seat.', 'publictech-terminal' ); ?></div>
				<div class="kv" style="display:block;padding:9px 12px;font-size:12px;line-height:1.55;color:var(--ink-0)"><?php esc_html_e( 'Instant full-text search, ready-made riding and MP reports, live charts, and a plain-language AI console over the whole dataset.', 'publictech-terminal' ); ?></div>
				<div class="kv" style="display:block;padding:9px 12px;font-size:12px;line-height:1.55;color:var(--ink-0)"><?php esc_html_e( 'Built for asking questions, not just downloading files — by people, journalists, and AI tools alike.', 'publictech-terminal' ); ?></div>
			</div>
		</div>
	</div>

	<!-- ═══ SOURCES & METHODOLOGY ═════════════════════════════════════════ -->
	<div style="display:grid;grid-template-columns:1fr 340px;gap:14px;align-items:start" class="about-foot">
		<div class="panel corner">
			<div class="hd"><h3>◇ <?php esc_html_e( 'Data sources', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'ALL OPEN / PUBLIC', 'publictech-terminal' ); ?></span></div>
			<div class="kvs">
				<div class="kv"><span class="k"><?php esc_html_e( 'Grants & contributions', 'publictech-terminal' ); ?></span><span class="v mono" style="font-size:10px;color:var(--accent)">search.open.canada.ca/grants</span></div>
				<div class="kv"><span class="k"><?php esc_html_e( 'Riding boundary shapefiles', 'publictech-terminal' ); ?></span><span class="v mono" style="font-size:10px;color:var(--accent)"><?php esc_html_e( 'Elections Canada', 'publictech-terminal' ); ?></span></div>
				<div class="kv"><span class="k"><?php esc_html_e( 'Postal-code coordinates', 'publictech-terminal' ); ?></span><span class="v mono" style="font-size:10px;color:var(--accent)"><?php esc_html_e( 'open GeoNames data', 'publictech-terminal' ); ?></span></div>
				<div class="kv"><span class="k"><?php esc_html_e( 'Members & contact details', 'publictech-terminal' ); ?></span><span class="v mono" style="font-size:10px;color:var(--accent)"><?php esc_html_e( 'House of Commons', 'publictech-terminal' ); ?></span></div>
				<div class="kv"><span class="k"><?php esc_html_e( 'Electoral & mandate history', 'publictech-terminal' ); ?></span><span class="v mono" style="font-size:10px;color:var(--accent)"><?php esc_html_e( 'open parliamentary dataset', 'publictech-terminal' ); ?></span></div>
			</div>
		</div>

		<div class="panel">
			<div class="hd"><h3>◇ <?php esc_html_e( 'Honest limits', 'publictech-terminal' ); ?></h3><span class="meta"><?php esc_html_e( 'METHODOLOGY', 'publictech-terminal' ); ?></span></div>
			<div class="bd" style="font-size:11px;color:var(--ink-2);line-height:1.65;display:flex;flex-direction:column;gap:8px">
				<p style="margin:0"><?php esc_html_e( 'Coverage of any given year reflects what departments actually disclosed; spending records begin in 2004.', 'publictech-terminal' ); ?></p>
				<p style="margin:0"><?php esc_html_e( 'Riding attribution is geographic — based on a recipient\'s postal code — and is a strong proxy, not a statement that a project physically sat at that address.', 'publictech-terminal' ); ?></p>
				<p style="margin:0"><?php esc_html_e( 'This is an independent project. It is not affiliated with, or endorsed by, the Government of Canada, Elections Canada, or the House of Commons.', 'publictech-terminal' ); ?></p>
			</div>
		</div>
	</div>

</section>
</main>

<style>
/* Stack the two-column comparison and footer grids on smaller screens. */
@media (max-width: 720px) {
	.about-compare { grid-template-columns: 1fr !important; }
	.about-foot    { grid-template-columns: 1fr !important; }
}
</style>

<?php
get_footer();
