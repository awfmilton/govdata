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

get_header(); ?>

<main id="pt-main" tabindex="-1">
	<section class="view active" data-pt-view="directory">
		<div class="search-bar corner">
			<div class="icon" aria-hidden="true">⌕</div>
			<label class="pt-sr-only" for="globalSearch"><?php esc_html_e( 'Search', 'publictech-terminal' ); ?></label>
			<input id="globalSearch" placeholder="<?php esc_attr_e( 'Search by name, riding, party, postal code, committee…', 'publictech-terminal' ); ?>" autocomplete="off" />
			<div class="shortcut" aria-hidden="true"><kbd>⌘</kbd><kbd>K</kbd></div>
		</div>

		<div class="chips" id="ptActiveChips" aria-live="polite"></div>

		<div class="dir-grid">
			<aside class="panel facets" aria-label="<?php esc_attr_e( 'Filter facets', 'publictech-terminal' ); ?>">
				<div class="hd"><h3>◇ <?php esc_html_e( 'Facets', 'publictech-terminal' ); ?></h3><span class="meta" id="ptFacetCount"></span></div>

				<div class="group">
					<h4><?php esc_html_e( 'Party', 'publictech-terminal' ); ?> <span class="count"><?php echo esc_html( count( $party_terms ) ); ?></span></h4>
					<?php foreach ( $party_terms as $t ) :
						$slug = sanitize_html_class( strtolower( $t->slug ) ); ?>
						<div class="check"><label>
							<input type="checkbox" data-pt-facet="party" value="<?php echo esc_attr( $t->slug ); ?>" />
							<span class="party-bar party-<?php echo esc_attr( $slug ); ?>"></span><?php echo esc_html( $t->name ); ?>
						</label><span class="n"><?php echo esc_html( $t->count ); ?></span></div>
					<?php endforeach; ?>
				</div>

				<div class="group">
					<h4><?php esc_html_e( 'Region', 'publictech-terminal' ); ?> <span class="count"><?php echo esc_html( count( $region_terms ) ); ?></span></h4>
					<?php foreach ( $region_terms as $t ) : ?>
						<div class="check"><label>
							<input type="checkbox" data-pt-facet="region" value="<?php echo esc_attr( $t->slug ); ?>" />
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
								<div class="mp-portrait" aria-hidden="true"><?php echo esc_html( $initials ); ?></div>
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
									<span><?php echo esc_html( number_format_i18n( $votes ) ); ?> <?php esc_html_e( 'votes', 'publictech-terminal' ); ?></span><br>
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

<?php get_footer();
