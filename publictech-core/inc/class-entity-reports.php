<?php
/**
 * Entity funding reports.
 *
 * Ties the source database (grantconts, read-only) to the cache database
 * (govdata_cache_01) and the front-end pages:
 *
 *   1. A filterable registry of the organisations that get a dedicated page.
 *   2. build_snapshot() — a pure roll-up of raw agreement rows into the JSON
 *      payload the page renders (total funding, by-year, by-program /
 *      "initiative", by-department, keyword hits, top agreements). Pure so it is
 *      unit-tested without a database.
 *   3. sync_entity()/sync_all() — read grantconts, build the snapshot, and write
 *      it to the cache via the Cache_DB stored-procedure gateway. Run from the
 *      CLI refresh script and from a weekly cron.
 *   4. cached_snapshot() — page-level (WordPress transient) cache in front of
 *      the cache DB so a page render does not touch MySQL on every hit.
 *   5. REST routes for the in-page live search and a JSON summary.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Entity_Reports {
	private static ?self $instance = null;
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/** Default source connection alias (declared in PUBLICTECH_DB_CONNECTIONS). */
	public const SOURCE_ALIAS = 'pt-grantconts-01';

	/** Weekly refresh cron hook. */
	public const CRON_HOOK = 'publictech_sync_entities';

	/** Page-level transient TTL (seconds). */
	public const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'init', [ $this, 'schedule' ] );
		add_action( self::CRON_HOOK, [ self::class, 'sync_all' ] );
	}

	/* ───────────────────────── Registry ───────────────────────── */

	/**
	 * The organisations that get a dedicated funding page.
	 *
	 * Presentation is deliberately neutral and factual: every entity uses the
	 * same template and the same public open-data source. Edit this list (add,
	 * remove, retune match names / keywords) through the
	 * `publictech_funding_entities` filter without touching core.
	 *
	 * Each entry:
	 *   slug        URL + cache key (lowercase, dashed)
	 *   name        Display name
	 *   legal_names Recipient-name fragments matched (LIKE) against grantconts
	 *   keywords    Terms rolled up for the "funding by keyword / initiative" view
	 *   blurb       One-line factual description of the organisation
	 *
	 * @return array<int,array{slug:string,name:string,legal_names:array<int,string>,keywords:array<int,string>,blurb:string}>
	 */
	public static function registry(): array {
		$entities = [
			[
				'slug'        => 'canadian-anti-hate-network',
				'name'        => 'Canadian Anti-Hate Network',
				'legal_names' => [ 'Canadian Anti-Hate Network', 'Canadian Anti Hate Network' ],
				'keywords'    => [ 'anti-hate', 'hate', 'extremism', 'far-right', 'radicalization' ],
				'blurb'       => 'A non-profit that researches and reports on hate groups and extremism in Canada.',
			],
			[
				'slug'        => 'egale-canada',
				'name'        => 'Egale Canada',
				'legal_names' => [ 'Egale Canada', 'Egale Canada Human Rights Trust' ],
				'keywords'    => [ '2SLGBTQI', 'LGBTQ', 'gender', 'inclusion', 'homelessness' ],
				'blurb'       => 'A national organisation focused on 2SLGBTQI rights, education and research.',
			],
			[
				'slug'        => 'national-council-of-canadian-muslims',
				'name'        => 'National Council of Canadian Muslims',
				'legal_names' => [ 'National Council of Canadian Muslims', 'Canadian Council on American-Islamic Relations' ],
				'keywords'    => [ 'anti-racism', 'Islamophobia', 'Muslim', 'community', 'civil liberties' ],
				'blurb'       => 'A Muslim civil-liberties and advocacy organisation.',
			],
			[
				'slug'        => 'fierte-canada-pride',
				'name'        => 'Fierté Canada Pride',
				'legal_names' => [ 'Fierté Canada Pride', 'Fierte Canada Pride', 'Canada Pride' ],
				'keywords'    => [ 'Pride', '2SLGBTQI', 'festival', 'community', 'safety' ],
				'blurb'       => 'A national association of Pride festival organisations.',
			],
			[
				'slug'        => 'canadian-climate-institute',
				'name'        => 'Canadian Climate Institute',
				'legal_names' => [ 'Canadian Climate Institute', 'Canadian Institute for Climate Choices' ],
				'keywords'    => [ 'climate', 'emissions', 'net zero', 'environment', 'energy' ],
				'blurb'       => 'A climate-policy research organisation.',
			],
			[
				'slug'        => 'amnesty-international-canada',
				'name'        => 'Amnesty International Canada',
				'legal_names' => [ 'Amnesty International Canada', 'Amnesty International Canadian Section' ],
				'keywords'    => [ 'human rights', 'advocacy', 'refugees', 'justice' ],
				'blurb'       => 'The Canadian section of the global human-rights organisation.',
			],
			[
				'slug'        => 'canadian-association-of-elizabeth-fry-societies',
				'name'        => 'Canadian Association of Elizabeth Fry Societies',
				'legal_names' => [ 'Canadian Association of Elizabeth Fry Societies', 'Elizabeth Fry' ],
				'keywords'    => [ 'women', 'justice', 'incarceration', 'reintegration' ],
				'blurb'       => 'A federation supporting women and gender-diverse people in the justice system.',
			],
			[
				'slug'        => 'canadian-council-for-refugees',
				'name'        => 'Canadian Council for Refugees',
				'legal_names' => [ 'Canadian Council for Refugees', 'Conseil canadien pour les réfugiés' ],
				'keywords'    => [ 'refugees', 'immigration', 'settlement', 'newcomers' ],
				'blurb'       => 'A national network of refugee- and immigrant-serving organisations.',
			],
			[
				'slug'        => 'centre-for-israel-and-jewish-affairs',
				'name'        => 'Centre for Israel and Jewish Affairs',
				'legal_names' => [ 'Centre for Israel and Jewish Affairs', 'Centre for Israel & Jewish Affairs' ],
				'keywords'    => [ 'antisemitism', 'Jewish', 'community', 'security' ],
				'blurb'       => 'The advocacy agent of the Jewish Federations of Canada.',
			],
			[
				'slug'        => 'pride-toronto',
				'name'        => 'Pride Toronto',
				'legal_names' => [ 'Pride Toronto' ],
				'keywords'    => [ 'Pride', '2SLGBTQI', 'festival', 'Toronto' ],
				'blurb'       => "Organiser of Toronto's annual Pride festival.",
			],
		];

		/**
		 * Filter the funding-report entity registry.
		 *
		 * @param array $entities Registry entries.
		 */
		$entities = (array) apply_filters( 'publictech_funding_entities', $entities );

		// Normalise so downstream code can rely on the shape.
		$out = [];
		foreach ( $entities as $e ) {
			if ( empty( $e['slug'] ) || empty( $e['name'] ) ) {
				continue;
			}
			$out[] = [
				'slug'        => sanitize_title( (string) $e['slug'] ),
				'name'        => (string) $e['name'],
				'legal_names' => array_values( array_filter( array_map( 'strval', (array) ( $e['legal_names'] ?? [] ) ) ) ),
				'keywords'    => array_values( array_filter( array_map( 'strval', (array) ( $e['keywords'] ?? [] ) ) ) ),
				'blurb'       => (string) ( $e['blurb'] ?? '' ),
			];
		}
		return $out;
	}

	/**
	 * Look up a single registry entry by slug.
	 *
	 * @return array{slug:string,name:string,legal_names:array<int,string>,keywords:array<int,string>,blurb:string}|null
	 */
	public static function entity( string $slug ): ?array {
		$slug = sanitize_title( $slug );
		foreach ( self::registry() as $e ) {
			if ( $e['slug'] === $slug ) {
				return $e;
			}
		}
		return null;
	}

	/* ───────────────────── Source query + roll-up ───────────────────── */

	/**
	 * Build the SELECT (and bound params) that pulls every agreement matching an
	 * entity's recipient-name fragments from the denormalised master_table.
	 *
	 * @return array{0:string,1:array<int,string>} [sql, params]
	 */
	public static function source_query( array $entity, int $limit = 50000 ): array {
		$frags  = [];
		$params = [];
		foreach ( (array) ( $entity['legal_names'] ?? [] ) as $needle ) {
			$needle = trim( (string) $needle );
			if ( '' === $needle ) {
				continue;
			}
			$like     = '%' . $needle . '%';
			$frags[]  = '`recipient_legal_name` LIKE ?';
			$params[] = $like;
			$frags[]  = '`recipient_operating_name` LIKE ?';
			$params[] = $like;
		}
		if ( ! $frags ) {
			return [ '', [] ];
		}
		$limit = max( 1, min( 200000, $limit ) );
		$sql   = 'SELECT `ref_number`, `agreement_number`, `agreement_value`, '
			. '`agreement_start_date`, `year`, `fiscal_year`, `agreement_title_en`, '
			. '`description_eng`, `prog_name_en`, `prog_purpose_en`, `owner_org_en`, '
			. '`recipient_legal_name`, `recipient_operating_name`, `recipient_city`, '
			. '`recipient_province` '
			. 'FROM `master_table` WHERE ' . implode( ' OR ', $frags )
			. ' ORDER BY `agreement_value` DESC LIMIT ' . $limit;
		return [ $sql, $params ];
	}

	/**
	 * Pure roll-up of raw agreement rows into the cached snapshot payload.
	 *
	 * Methodology mirrors the riding report: rows are summed as a NET total
	 * (amendment rows carry positive top-ups and negative reductions), and the
	 * positive / negative split is reported alongside so the netting is visible.
	 *
	 * @param array<int,array<string,mixed>> $rows   Raw master_table rows.
	 * @param array<string,mixed>            $entity Registry entry.
	 * @return array<string,mixed>
	 */
	public static function build_snapshot( array $rows, array $entity ): array {
		$by_year    = [];
		$by_program = [];
		$by_dept    = [];
		$keywords   = array_map( 'strval', (array) ( $entity['keywords'] ?? [] ) );
		$kw_hits    = [];
		foreach ( $keywords as $kw ) {
			$kw_hits[ $kw ] = [ 'keyword' => $kw, 'total' => 0.0, 'count' => 0 ];
		}
		$matched_names = [];

		$total = 0.0;
		$pos   = 0.0;
		$neg   = 0.0;
		$max   = 0.0;
		$first_year = null;
		$last_year  = null;

		foreach ( $rows as $r ) {
			$value = (float) ( $r['agreement_value'] ?? 0 );
			$year  = (int) ( $r['year'] ?? 0 );
			$prog  = trim( (string) ( $r['prog_name_en'] ?? '' ) );
			if ( '' === $prog ) {
				$prog = trim( (string) ( $r['prog_purpose_en'] ?? '' ) );
			}
			if ( '' === $prog ) {
				$prog = 'Unspecified';
			}
			$dept = trim( (string) ( $r['owner_org_en'] ?? '' ) );
			if ( '' === $dept ) {
				$dept = 'Unspecified';
			}
			$legal = trim( (string) ( $r['recipient_legal_name'] ?? '' ) );

			$total += $value;
			if ( $value >= 0 ) {
				$pos += $value;
			} else {
				$neg += $value;
			}
			if ( $value > $max ) {
				$max = $value;
			}

			// Year roll-up (only plausible calendar years feed the trend charts).
			if ( $year >= 1900 && $year <= 2100 ) {
				if ( ! isset( $by_year[ $year ] ) ) {
					$by_year[ $year ] = [ 'year' => $year, 'total' => 0.0, 'positive' => 0.0, 'negative' => 0.0, 'pos_count' => 0, 'neg_count' => 0 ];
				}
				$by_year[ $year ]['total'] += $value;
				if ( $value >= 0 ) {
					$by_year[ $year ]['positive'] += $value;
					$by_year[ $year ]['pos_count']++;
				} else {
					$by_year[ $year ]['negative'] += $value;
					$by_year[ $year ]['neg_count']++;
				}
				$first_year = null === $first_year ? $year : min( $first_year, $year );
				$last_year  = null === $last_year ? $year : max( $last_year, $year );
			}

			// Program ("initiative") roll-up.
			if ( ! isset( $by_program[ $prog ] ) ) {
				$by_program[ $prog ] = [ 'program' => $prog, 'total' => 0.0, 'count' => 0 ];
			}
			$by_program[ $prog ]['total'] += $value;
			$by_program[ $prog ]['count']++;

			// Department roll-up.
			if ( ! isset( $by_dept[ $dept ] ) ) {
				$by_dept[ $dept ] = [ 'department' => $dept, 'total' => 0.0, 'count' => 0 ];
			}
			$by_dept[ $dept ]['total'] += $value;
			$by_dept[ $dept ]['count']++;

			// Keyword hits across title / description / program text.
			if ( $keywords ) {
				$haystack = strtolower(
					(string) ( $r['agreement_title_en'] ?? '' ) . ' '
					. (string) ( $r['description_eng'] ?? '' ) . ' '
					. $prog . ' '
					. (string) ( $r['prog_purpose_en'] ?? '' )
				);
				foreach ( $keywords as $kw ) {
					if ( '' !== $kw && str_contains( $haystack, strtolower( $kw ) ) ) {
						$kw_hits[ $kw ]['total'] += $value;
						$kw_hits[ $kw ]['count']++;
					}
				}
			}

			if ( '' !== $legal ) {
				$matched_names[ $legal ] = ( $matched_names[ $legal ] ?? 0 ) + 1;
			}
		}

		// Sort + trim the grouped views.
		ksort( $by_year );
		$by_year = array_map( static function ( $row ) {
			$row['total']    = round( $row['total'], 2 );
			$row['positive'] = round( $row['positive'], 2 );
			$row['negative'] = round( $row['negative'], 2 );
			return $row;
		}, array_values( $by_year ) );

		$by_program = array_values( $by_program );
		usort( $by_program, static fn( $a, $b ) => $b['total'] <=> $a['total'] );
		$by_program = array_slice( array_map( static function ( $row ) {
			$row['total'] = round( $row['total'], 2 );
			return $row;
		}, $by_program ), 0, 15 );

		$by_dept = array_values( $by_dept );
		usort( $by_dept, static fn( $a, $b ) => $b['total'] <=> $a['total'] );
		$by_dept = array_slice( array_map( static function ( $row ) {
			$row['total'] = round( $row['total'], 2 );
			return $row;
		}, $by_dept ), 0, 12 );

		$kw_hits = array_values( $kw_hits );
		usort( $kw_hits, static fn( $a, $b ) => $b['total'] <=> $a['total'] );
		$kw_hits = array_map( static function ( $row ) {
			$row['total'] = round( $row['total'], 2 );
			return $row;
		}, $kw_hits );

		// Peak year.
		$peak = [ 'year' => 0, 'total' => 0.0 ];
		foreach ( $by_year as $row ) {
			if ( $row['total'] > $peak['total'] ) {
				$peak = [ 'year' => $row['year'], 'total' => $row['total'] ];
			}
		}

		// Matched names, most frequent first.
		arsort( $matched_names );
		$matched = [];
		foreach ( $matched_names as $nm => $cnt ) {
			$matched[] = [ 'name' => $nm, 'count' => $cnt ];
		}
		$matched = array_slice( $matched, 0, 10 );

		// Top agreements (rows already arrive value-desc, but sort defensively).
		$sorted = $rows;
		usort( $sorted, static fn( $a, $b ) => (float) ( $b['agreement_value'] ?? 0 ) <=> (float) ( $a['agreement_value'] ?? 0 ) );

		$top = [];
		foreach ( array_slice( $sorted, 0, 25 ) as $r ) {
			$top[] = [
				'ref_number'  => (string) ( $r['ref_number'] ?? '' ),
				'value'       => round( (float) ( $r['agreement_value'] ?? 0 ), 2 ),
				'year'        => (int) ( $r['year'] ?? 0 ),
				'fiscal_year' => (string) ( $r['fiscal_year'] ?? '' ),
				'title'       => self::clip( (string) ( $r['agreement_title_en'] ?? '' ), 200 ),
				'program'     => (string) ( $r['prog_name_en'] ?? '' ),
				'department'  => (string) ( $r['owner_org_en'] ?? '' ),
				'description' => self::clip( (string) ( $r['description_eng'] ?? '' ), 240 ),
			];
		}

		// Searchable agreement rows seeded into the cache (capped).
		$agreements = [];
		foreach ( array_slice( $sorted, 0, 2500 ) as $r ) {
			$agreements[] = [
				'ref_number'               => (string) ( $r['ref_number'] ?? '' ),
				'recipient_legal_name'     => (string) ( $r['recipient_legal_name'] ?? '' ),
				'recipient_operating_name' => (string) ( $r['recipient_operating_name'] ?? '' ),
				'title'                    => self::clip( (string) ( $r['agreement_title_en'] ?? '' ), 800 ),
				'description'              => self::clip( (string) ( $r['description_eng'] ?? '' ), 500 ),
				'program'                  => self::clip( (string) ( $r['prog_name_en'] ?? '' ), 255 ),
				'department'               => self::clip( (string) ( $r['owner_org_en'] ?? '' ), 255 ),
				'value'                    => round( (float) ( $r['agreement_value'] ?? 0 ), 2 ),
				'fiscal_year'              => (string) ( $r['fiscal_year'] ?? '' ),
				'year'                     => (int) ( $r['year'] ?? 0 ),
				'start_date'               => self::date_or_empty( (string) ( $r['agreement_start_date'] ?? '' ) ),
			];
		}

		$count = count( $rows );
		$span  = ( $first_year && $last_year ) ? max( 1, ( $last_year - $first_year + 1 ) ) : 1;

		return [
			'slug'          => (string) ( $entity['slug'] ?? '' ),
			'entity_name'   => (string) ( $entity['name'] ?? '' ),
			'blurb'         => (string) ( $entity['blurb'] ?? '' ),
			'generated_at'  => gmdate( 'c' ),
			'keywords'      => $keywords,
			'matched_names' => $matched,
			'totals'        => [
				'total_funding'    => round( $total, 2 ),
				'positive_funding' => round( $pos, 2 ),
				'negative_funding' => round( $neg, 2 ),
				'agreement_count'  => $count,
				'first_year'       => $first_year,
				'last_year'        => $last_year,
				'avg_per_year'     => round( $total / $span, 2 ),
				'max_agreement'    => round( $max, 2 ),
				'peak_year'        => $peak,
			],
			'by_year'       => $by_year,
			'by_program'    => $by_program,
			'by_department' => $by_dept,
			'keyword_hits'  => $kw_hits,
			'top_agreements' => $top,
			'agreements'    => $agreements,
		];
	}

	private static function clip( string $text, int $len ): string {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) ?? '' );
		if ( strlen( $text ) <= $len ) {
			return $text;
		}
		return rtrim( substr( $text, 0, $len - 1 ) ) . '…';
	}

	private static function date_or_empty( string $date ): string {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}

	/* ───────────────────────── Sync (write path) ───────────────────────── */

	/**
	 * Pull one entity from the source DB, roll it up, and upsert into the cache.
	 *
	 * @return array{ok:bool,reason?:string,rows?:int,total?:float}
	 */
	public static function sync_entity( array $entity, string $source_alias = self::SOURCE_ALIAS ): array {
		[ $sql, $params ] = self::source_query( $entity );
		if ( '' === $sql ) {
			return [ 'ok' => false, 'reason' => 'no match patterns' ];
		}
		try {
			$rows = publictech_db( $source_alias )->select( $sql, $params );
		} catch ( \Throwable $e ) {
			error_log( '[PublicTech] source read failed for ' . ( $entity['slug'] ?? '?' ) . ': ' . $e->getMessage() );
			return [ 'ok' => false, 'reason' => 'read failed: ' . $e->getMessage() ];
		}

		$snapshot           = self::build_snapshot( $rows, $entity );
		$snapshot['source'] = $source_alias;

		$ok = Cache_DB::instance()->upsert_snapshot(
			(string) $entity['slug'],
			(string) $entity['name'],
			implode( ', ', (array) ( $entity['keywords'] ?? [] ) ),
			$source_alias,
			$snapshot
		);

		if ( ! $ok ) {
			return [ 'ok' => false, 'reason' => 'cache db upsert failed' ];
		}

		delete_transient( 'pt_entity_snap_' . $entity['slug'] );

		return [
			'ok'    => true,
			'rows'  => count( $rows ),
			'total' => $snapshot['totals']['total_funding'],
		];
	}

	/**
	 * Sync every registered entity. Cron entry point and CLI helper.
	 *
	 * @return array<string,array{ok:bool,reason?:string,rows?:int,total?:float}>
	 */
	public static function sync_all( string $source_alias = self::SOURCE_ALIAS ): array {
		$results = [];
		foreach ( self::registry() as $entity ) {
			$results[ $entity['slug'] ] = self::sync_entity( $entity, $source_alias );
		}
		return $results;
	}

	/* ───────────────────────── Read path (page) ───────────────────────── */

	/**
	 * Page-level cache: serve the snapshot from a transient, falling back to the
	 * cache DB on a miss. Returns null when nothing is cached yet.
	 *
	 * @return array{name:string,total:float,count:int,updated_at:string,payload:array<string,mixed>}|null
	 */
	public static function cached_snapshot( string $slug ): ?array {
		$slug = sanitize_title( $slug );
		$key  = 'pt_entity_snap_' . $slug;

		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$snap = Cache_DB::instance()->get_snapshot( $slug );
		if ( null === $snap ) {
			return null;
		}
		set_transient( $key, $snap, self::CACHE_TTL );
		return $snap;
	}

	/* ───────────────────────── Cron ───────────────────────── */

	public function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'next monday 06:00 UTC' ), 'weekly', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/* ───────────────────────── REST ───────────────────────── */

	public function register_routes(): void {
		register_rest_route( PUBLICTECH_CORE_REST_NS, '/entity/(?P<slug>[a-z0-9\-]+)/search', [
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'rest_search' ],
			'args'                => [
				'q'     => [ 'type' => 'string',  'default' => '' ],
				'limit' => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 500 ],
			],
		] );

		register_rest_route( PUBLICTECH_CORE_REST_NS, '/entity/(?P<slug>[a-z0-9\-]+)/summary', [
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'rest_summary' ],
		] );
	}

	/**
	 * In-page live search. This is the route that connects to the cache database
	 * on demand (via the search stored procedure) for the entity in question.
	 */
	public function rest_search( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		if ( class_exists( __NAMESPACE__ . '\\Throttle' )
			&& ( $gate = Throttle::instance()->enforce_rest( $req ) ) instanceof \WP_Error ) {
			return $gate;
		}

		$slug  = sanitize_title( (string) $req['slug'] );
		$query = sanitize_text_field( (string) $req->get_param( 'q' ) );
		$limit = (int) $req->get_param( 'limit' );

		$rows = Cache_DB::instance()->search_agreements( $slug, $query, $limit );

		$resp = rest_ensure_response( [
			'slug'  => $slug,
			'query' => $query,
			'count' => count( $rows ),
			'rows'  => $rows,
		] );
		$resp->header( 'Cache-Control', 'public, max-age=30' );
		return $resp;
	}

	/**
	 * JSON summary (totals + grouped roll-ups, without the heavy agreements list).
	 */
	public function rest_summary( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$slug = sanitize_title( (string) $req['slug'] );
		$snap = self::cached_snapshot( $slug );
		if ( null === $snap ) {
			return new \WP_Error( 'pt_not_synced', 'No cached data for this entity yet.', [ 'status' => 404 ] );
		}
		$payload = $snap['payload'];
		unset( $payload['agreements'] );
		$resp = rest_ensure_response( [
			'name'       => $snap['name'],
			'total'      => $snap['total'],
			'count'      => $snap['count'],
			'updated_at' => $snap['updated_at'],
			'summary'    => $payload,
		] );
		$resp->header( 'Cache-Control', 'public, max-age=300' );
		return $resp;
	}

	/* ───────────────────────── Page scaffolding ───────────────────────── */

	/**
	 * Create or update a WordPress Page per registered entity, wired to the
	 * entity-report template. Idempotent — matched on the pt_entity_slug meta.
	 *
	 * @return array<string,int> slug => post ID
	 */
	public static function scaffold_pages(): array {
		$template = 'page-templates/template-entity-report.php';
		$out      = [];

		// Optional shared parent page at /groups.
		$parent = get_page_by_path( 'groups' );
		$parent_id = $parent instanceof \WP_Post ? $parent->ID : wp_insert_post( [
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Funding Reports',
			'post_name'    => 'groups',
			'post_content' => 'Federal grant &amp; contribution funding reports by organisation. Data from public Government of Canada open data.',
		] );

		foreach ( self::registry() as $entity ) {
			$existing = get_posts( [
				'post_type'      => 'page',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'meta_key'       => 'pt_entity_slug',
				'meta_value'     => $entity['slug'],
				'fields'         => 'ids',
			] );

			$post_data = [
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $entity['name'] . ' — Federal Funding',
				'post_name'    => $entity['slug'],
				'post_parent'  => is_int( $parent_id ) ? $parent_id : 0,
				'post_content' => '',
			];

			if ( ! empty( $existing[0] ) ) {
				$post_data['ID'] = (int) $existing[0];
				$post_id         = wp_update_post( $post_data, true );
			} else {
				$post_id = wp_insert_post( $post_data, true );
			}
			if ( is_wp_error( $post_id ) ) {
				continue;
			}

			update_post_meta( $post_id, '_wp_page_template', $template );
			update_post_meta( $post_id, 'pt_entity_slug', $entity['slug'] );
			$out[ $entity['slug'] ] = (int) $post_id;
		}

		return $out;
	}
}
