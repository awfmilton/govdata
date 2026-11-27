<?php
/**
 * Directory view — MP archive. Full prototype layout: search bar with shortcut,
 * facets sidebar (party / region / postal / committee / spend), results grid,
 * pagination. Server-renders the first page; the JS layer drives subsequent
 * AJAX updates against /publictech/v1/mp/search.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$party_terms     = get_terms( [ 'taxonomy' => 'mp_party',     'hide_empty' => false ] );
$region_terms    = get_terms( [ 'taxonomy' => 'mp_region',    'hide_empty' => false ] );
$committee_terms = get_terms( [ 'taxonomy' => 'mp_committee', 'hide_empty' => false ] );
$total           = (int) ( wp_count_posts( 'member-parliament' )->publish ?? 0 );

// Party / region archives (/party/<slug>/, /region/<xx>/) render this directory pre-filtered to their term. They used to
// fall through to index.php, which printed each MP's title over an empty box. The preset tells search.js which facet the
// page already applies, so its first client-side search keeps the filter instead of re-listing all members.
$pt_preset = [];
$pt_term   = ( is_tax( 'mp_party' ) || is_tax( 'mp_region' ) ) ? get_queried_object() : null;
if ( $pt_term instanceof WP_Term ) {
	$pt_preset[ 'mp_party' === $pt_term->taxonomy ? 'party' : 'region' ] = [ $pt_term->slug ];
	$total = (int) $pt_term->count;
}

get_header(); ?>

<main id="pt-main" tabindex="-1">
	<section class="view active" data-pt-view="directory">
		<div class="search-bar corner">
			<div class="icon" aria-hidden="true">⌕</div>
			<label class="pt-sr-only" for="globalSearch"><?php esc_html_e( 'Search', 'publictech-terminal' ); ?></label>
			<input id="globalSearch" placeholder="<?php esc_attr_e( 'Search by name, riding, party, postal code, committee…', 'publictech-terminal' ); ?>" autocomplete="off" />
			<div class="shortcut" aria-hidden="true"><kbd>⌘</kbd><kbd>K</kbd></div>
		</div>

		<?php if ( $pt_term instanceof WP_Term ) : ?>
			<h1 class="pt-dir-title" style="margin:12px 0 4px"><?php
				/* translators: %s: party or region name */
				printf( esc_html__( 'Members of Parliament — %s', 'publictech-terminal' ), esc_html( $pt_term->name ) );
			?></h1>
			<script>window.PT_DIRECTORY_PRESET = <?php echo wp_json_encode( $pt_preset ); ?>;</script>
		<?php endif; ?>

		<?php do_action( 'govads_zone', 'archive_after_search' ); ?>

		<div class="chips" id="ptActiveChips" aria-live="polite"></div>

		<div class="dir-grid">
			<aside class="panel facets" aria-label="<?php esc_attr_e( 'Filter facets', 'publictech-terminal' ); ?>">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Facets', 'publictech-terminal' ); ?></h3><span class="meta" id="ptFacetCount"></span></div>

				<div class="group">
					<h4><?php esc_html_e( 'Party', 'publictech-terminal' ); ?> <span class="count"><?php echo esc_html( count( $party_terms ) ); ?></span></h4>
					<?php foreach ( $party_terms as $t ) :
						$slug = sanitize_html_class( strtolower( $t->slug ) ); ?>
						<div class="check"><label>
							<input type="checkbox" data-pt-facet="party" value="<?php echo esc_attr( $t->slug ); ?>"<?php checked( in_array( $t->slug, $pt_preset['party'] ?? [], true ) ); ?> />
							<span class="party-bar party-<?php echo esc_attr( $slug ); ?>"></span><?php echo esc_html( $t->name ); ?>
						</label><span class="n"><?php echo esc_html( $t->count ); ?></span></div>
					<?php endforeach; ?>
				</div>

				<div class="group">
					<h4><?php esc_html_e( 'Region', 'publictech-terminal' ); ?> <span class="count"><?php echo esc_html( count( $region_terms ) ); ?></span></h4>
					<?php foreach ( $region_terms as $t ) : ?>
						<div class="check"><label>
							<input type="checkbox" data-pt-facet="region" value="<?php echo esc_attr( $t->slug ); ?>"<?php checked( in_array( $t->slug, $pt_preset['region'] ?? [], true ) ); ?> />
							<?php echo esc_html( $t->name ); ?>
						</label><span class="n"><?php echo esc_html( $t->count ); ?></span></div>
					<?php endforeach; ?>
				</div>

				<div class="group">
					<h4><?php esc_html_e( 'Spend Range (CAD)', 'publictech-terminal' ); ?></h4>
					<div class="range">
						<input data-pt-facet="spend_gt" type="number" inputmode="numeric" placeholder="$0" />
						<span class="dim">→</span>
						<input data-pt-facet="spend_lt" type="number" inputmode="numeric" placeholder="$2.5M" />
					</div>
				</div>

				<div class="group">
					<h4><?php esc_html_e( 'Postal Code', 'publictech-terminal' ); ?></h4>
					<div class="range">
						<input data-pt-facet="postal" type="text" placeholder="K1A · M5V · V6B …" autocomplete="off" />
					</div>
				</div>

				<?php if ( $committee_terms ) : ?>
					<div class="group">
						<h4><?php esc_html_e( 'Committee', 'publictech-terminal' ); ?> <span class="count"><?php echo esc_html( count( $committee_terms ) ); ?></span></h4>
						<?php foreach ( $committee_terms as $t ) : ?>
							<div class="check"><label>
								<input type="checkbox" data-pt-facet="committee" value="<?php echo esc_attr( $t->slug ); ?>" />
								<?php echo esc_html( $t->name ); ?>
							</label><span class="n"><?php echo esc_html( $t->count ); ?></span></div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php do_action( 'govads_zone', 'archive_sidebar' ); ?>
			</aside>

			<div>
				<div class="res-meta">
					<span id="mpResultsMeta" role="status" aria-live="polite">
						<?php
						/* translators: %d: total number of members */
						printf( esc_html__( 'Showing %d records', 'publictech-terminal' ), (int) $total );
						?>
					</span>
					<div class="right">
						<span><?php esc_html_e( 'SORT:', 'publictech-terminal' ); ?></span>
						<a data-pt-sort="spend DESC" class="on"><?php esc_html_e( 'Highest Spend', 'publictech-terminal' ); ?> ▾</a>
						<a data-pt-sort="name ASC"><?php esc_html_e( 'Name A–Z', 'publictech-terminal' ); ?></a>
						<a data-pt-sort="riding ASC"><?php esc_html_e( 'Riding A–Z', 'publictech-terminal' ); ?></a>
						<a data-pt-sort="year ASC"><?php esc_html_e( 'Year Elected', 'publictech-terminal' ); ?></a>
					</div>
				</div>

				<div class="results" id="mpResults" aria-busy="false">
					<?php
					if ( have_posts() ) :
						while ( have_posts() ) :
							the_post();
							$mp_id    = get_the_ID();
							$party    = get_the_terms( $mp_id, 'mp_party' );
							$region   = get_the_terms( $mp_id, 'mp_region' );
							$party_n  = $party  && ! is_wp_error( $party )  ? $party[0]->name  : '';
							$region_n = $region && ! is_wp_error( $region ) ? $region[0]->name : '';
							$riding   = (string) ( get_post_meta( $mp_id, 'pt_mp_district', true ) ?: get_post_meta( $mp_id, 'riding', true ) );
							$spend    = (int) get_post_meta( $mp_id, 'total_spend', true );
							$flags    = (int) get_post_meta( $mp_id, 'open_flags',  true );
							$votes    = (int) get_post_meta( $mp_id, 'votes_cast',  true );
							$initials = strtoupper( substr( wp_strip_all_tags( get_the_title() ), 0, 2 ) );
							$pclass   = $party_n ? sanitize_html_class( strtolower( $party_n ) ) : 'unknown';
							?>
							<a class="mp-card" href="<?php echo esc_url( get_permalink() ); ?>" data-id="<?php echo (int) $mp_id; ?>">
								<?php $photo = (string) ( get_the_post_thumbnail_url( $mp_id, 'pt-mp-card' ) ?: get_post_meta( $mp_id, 'pt_mp_photo_url', true ) ); ?>
								<div class="mp-portrait" aria-hidden="true"><?php if ( $photo ) : ?><img src="<?php echo esc_url( $photo ); ?>" alt="" loading="lazy" width="44" height="44" style="width:100%;height:100%;object-fit:cover;border-radius:inherit" onerror="this.remove()"><?php endif; ?><?php echo $photo ? '' : esc_html( $initials ); ?></div>
								<div class="mp-info">
									<div class="name"><?php the_title(); ?></div>
									<div class="meta">
										<span><span class="party-bar party-<?php echo esc_attr( $pclass ); ?>"></span><?php echo esc_html( $party_n ); ?></span>
										<?php if ( $riding || $region_n ) : ?>
											<span class="dim">·</span>
											<span><?php echo esc_html( trim( $riding . ( $region_n ? ', ' . $region_n : '' ), ', ' ) ); ?></span>
										<?php endif; ?>
									</div>
								</div>
								<div class="mp-stats">
									<b>$<?php echo esc_html( number_format_i18n( $spend ) ); ?></b>
									<?php /* Votes cast in the House of Commons (database/sync-mp-votes.php); links to the profile's Votes panel. The card is itself a link, so this uses a keyboard-accessible role=link instead of a nested <a>. */ ?>
									<span class="mp-votes-link" role="link" tabindex="0" data-href="<?php echo esc_url( get_permalink() . '#pt-parl-votes' ); ?>" title="<?php esc_attr_e( 'House of Commons votes cast — see the voting record', 'publictech-terminal' ); ?>" style="text-decoration:underline dotted;cursor:pointer"><?php echo esc_html( number_format_i18n( $votes ) ); ?> <?php esc_html_e( 'votes', 'publictech-terminal' ); ?></span><br>
									<span style="color:<?php echo $flags > 2 ? 'var(--warn)' : 'var(--ink-3)'; ?>">
										<?php echo esc_html( number_format_i18n( $flags ) ); ?> <?php echo esc_html( _n( 'flag', 'flags', $flags, 'publictech-terminal' ) ); ?>
									</span>
								</div>
							</a>
							<?php
						endwhile;
					else :
						?>
						<div class="pt-error"><?php esc_html_e( 'No matching records.', 'publictech-terminal' ); ?></div>
					<?php endif; ?>
				</div>

				<?php do_action( 'govads_zone', 'archive_before_pagination' ); ?>

				<div class="stream-foot" style="margin-top:6px">
					<span><?php
						global $wp_query;
						/* translators: 1: current page, 2: max pages, 3: per page */
						printf( esc_html__( 'Page %1$s of %2$s · %3$s per page', 'publictech-terminal' ), '<b style="color:var(--ink-0)">' . esc_html( max( 1, (int) get_query_var( 'paged' ) ) ) . '</b>', esc_html( (int) $wp_query->max_num_pages ), 24 );
					?></span>
					<div class="pager">
						<?php
						echo paginate_links( [
							'mid_size'  => 2,
							'prev_text' => '‹',
							'next_text' => '›',
							'type'      => 'list',
						] );
						?>
					</div>
				</div>
			</div>
		</div>
	</section>
</main>

<script>
document.addEventListener('click', function (e) { var el = e.target.closest && e.target.closest('.mp-votes-link'); if (!el) return; e.preventDefault(); e.stopPropagation(); window.location.href = el.getAttribute('data-href'); }, true);
document.addEventListener('keydown', function (e) { if (e.key !== 'Enter' && e.key !== ' ') return; var el = e.target.closest && e.target.closest('.mp-votes-link'); if (!el) return; e.preventDefault(); window.location.href = el.getAttribute('data-href'); });
</script>
<?php get_footer();
