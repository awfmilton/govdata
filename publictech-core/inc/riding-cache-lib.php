<?php
/**
 * Riding-cache pure helpers.
 *
 * Stateless functions shared by the riding-cache warmer (which writes the
 * per-riding rollups into the secondary cache database) and the read path
 * (templates + REST). Everything here is deliberately free of WordPress and
 * database side effects so it can be unit-tested in isolation — the only WP
 * touch point is `apply_filters()`, which the test bootstrap shims.
 *
 * The data model: for each federal riding we precompute a JSON "report"
 * payload (all-time total, spending by year, top recipients, recipient-type
 * split, top programs, and a controversial-keyword scan) and store it in the
 * cache DB. The MP profile and riding-report templates then read that JSON
 * instead of ever touching the primary grantconts database.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Columns on grantconts.master_table that the keyword scan searches.
 *
 * Concatenated (see {@see pt_riding_keyword_text_sql()}) so a single LIKE per
 * keyword covers recipient names, the agreement title, the program, and the
 * free-text description / purpose fields.
 *
 * @return string[]
 */
function pt_riding_keyword_text_columns(): array {
	return [
		'recipient_legal_name',
		'recipient_operating_name',
		'agreement_title_en',
		'prog_name_en',
		'prog_purpose_en',
		'owner_org_en',
		'description_eng',
	];
}

/**
 * The 20 controversial keywords/phrases tracked per riding.
 *
 * Each entry is [ key, label, patterns[] ]; a row matches the keyword when the
 * concatenated text contains ANY pattern (case-insensitive substring). The
 * label is what the UI renders; the key is a stable slug stored in the cache
 * JSON so the front end can reference a keyword without re-deriving it.
 *
 * Patterns are trusted constants (not user input). They are still escaped for
 * LIKE in {@see pt_sql_like_contains()} before reaching SQL.
 *
 * Override via the `publictech_riding_keywords` filter.
 *
 * @return array<int,array{key:string,label:string,patterns:string[]}>
 */
function pt_riding_keywords(): array {
	$keywords = [
		[ 'key' => 'anti_hate_network', 'label' => 'Canadian Anti-Hate Network', 'patterns' => [ 'Canadian Anti-Hate Network', 'Anti-Hate Network' ] ],
		[ 'key' => 'anti_hate',         'label' => 'Anti-hate',                    'patterns' => [ 'anti-hate', 'anti hate' ] ],
		[ 'key' => 'egale',             'label' => 'EGALE / Egale Canada',         'patterns' => [ 'Egale' ] ],
		[ 'key' => 'nccm',              'label' => 'National Council of Canadian Muslims', 'patterns' => [ 'National Council of Canadian Muslims', 'NCCM' ] ],
		[ 'key' => 'pride',             'label' => 'Pride',                        'patterns' => [ 'Pride', 'Fierté' ] ],
		[ 'key' => 'anti_racism',       'label' => 'Anti-racism',                  'patterns' => [ 'anti-racism', 'anti racism' ] ],
		[ 'key' => 'dei',               'label' => 'DEI / diversity, equity, inclusion', 'patterns' => [ 'diversity, equity', 'equity, diversity', 'diversity and inclusion', 'diversity, equity and inclusion' ] ],
		[ 'key' => 'media_bailout',     'label' => 'Media bailout / Periodical Fund', 'patterns' => [ 'Canada Periodical Fund', 'Periodical Fund', 'media bailout' ] ],
		[ 'key' => 'indigenous',        'label' => 'Indigenous / First Nations',   'patterns' => [ 'Indigenous', 'First Nation' ] ],
		[ 'key' => 'tides',             'label' => 'Tides Foundation / Tides Canada', 'patterns' => [ 'Tides Canada', 'Tides Foundation' ] ],
		[ 'key' => 'black_led',         'label' => 'Black-led / anti-Black racism', 'patterns' => [ 'Black-led', 'anti-Black racism', 'anti-Black' ] ],
		[ 'key' => 'lgbtq',             'label' => 'LGBTQ / 2SLGBTQI+',            'patterns' => [ 'LGBTQ', '2SLGBTQ', 'LGBT' ] ],
		[ 'key' => 'climate_justice',   'label' => 'Climate justice',              'patterns' => [ 'climate justice', 'environmental justice' ] ],
		[ 'key' => 'fraser_institute',  'label' => 'Fraser Institute',             'patterns' => [ 'Fraser Institute' ] ],
		[ 'key' => 'antifa',            'label' => 'Antifa',                       'patterns' => [ 'Antifa' ] ],
		[ 'key' => 'canadian_heritage', 'label' => 'Canadian Heritage',            'patterns' => [ 'Canadian Heritage' ] ],
		[ 'key' => 'public_safety',     'label' => 'Public Safety',                'patterns' => [ 'Public Safety' ] ],
		[ 'key' => 'wage_subsidies',    'label' => 'Wage subsidies / journalism',  'patterns' => [ 'wage subsidy', 'wage subsidies', 'journalism' ] ],
		[ 'key' => 'reconciliation',    'label' => 'Reconciliation',               'patterns' => [ 'reconciliation' ] ],
		[ 'key' => 'far_right',         'label' => 'Far-right',                    'patterns' => [ 'far-right', 'far right' ] ],
	];

	/**
	 * Filter the controversial-keyword catalogue used by the riding cache.
	 *
	 * @param array<int,array{key:string,label:string,patterns:string[]}> $keywords
	 */
	$filtered = apply_filters( 'publictech_riding_keywords', $keywords );

	return is_array( $filtered ) && $filtered ? $filtered : $keywords;
}

/**
 * Build the SQL expression that concatenates the keyword-search text columns.
 *
 * @param string $alias Table alias used in the query (e.g. "m").
 */
function pt_riding_keyword_text_sql( string $alias = 'm' ): string {
	$alias = preg_replace( '/[^A-Za-z0-9_]/', '', $alias ) ?: 'm';
	$cols  = [];
	foreach ( pt_riding_keyword_text_columns() as $col ) {
		$cols[] = "`{$alias}`.`{$col}`";
	}
	return "CONCAT_WS(' ', " . implode( ', ', $cols ) . ')';
}

/**
 * Quote and escape a needle as a MySQL `LIKE '%needle%'` literal.
 *
 * LIKE wildcards (`%`, `_`) and the escape char (`\`) are neutralised so the
 * needle matches literally, and single quotes are doubled for SQL-string
 * safety. Inputs are trusted constants, but this keeps the contract honest.
 */
function pt_sql_like_contains( string $needle ): string {
	$escaped = str_replace( [ '\\', '%', '_' ], [ '\\\\', '\\%', '\\_' ], $needle );
	$quoted  = str_replace( "'", "''", $escaped );
	return "'%" . $quoted . "%'";
}

/**
 * Build the boolean SQL fragment that is true when the text matches a keyword.
 *
 * @param string[] $patterns
 * @param string   $text_sql The concatenated-text SQL expression to match against.
 */
function pt_riding_keyword_match_sql( array $patterns, string $text_sql ): string {
	$ors = [];
	foreach ( $patterns as $pattern ) {
		$pattern = (string) $pattern;
		if ( '' === $pattern ) {
			continue;
		}
		$ors[] = $text_sql . ' LIKE ' . pt_sql_like_contains( $pattern );
	}
	if ( ! $ors ) {
		return '0';
	}
	return '(' . implode( ' OR ', $ors ) . ')';
}

/**
 * Normalise a riding name for fuzzy matching between data sources.
 *
 * Elections Canada riding names differ across tables in punctuation only
 * (em-dash vs hyphen, accents, casing, doubled spaces). Reduce to a canonical
 * lower-case ASCII-ish form so "Thunder Bay—Rainy River" and
 * "Thunder Bay-Rainy River" compare equal.
 */
function pt_normalize_riding_name( string $name ): string {
	$name = trim( $name );
	if ( '' === $name ) {
		return '';
	}
	// Lower-case (multibyte-aware when available).
	$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );

	// Fold the most common French accents found in riding names to ASCII.
	$accents = [
		'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a',
		'ç' => 'c',
		'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
		'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'í' => 'i',
		'ñ' => 'n',
		'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o',
		'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
		'œ' => 'oe', 'æ' => 'ae',
		'ÿ' => 'y',
	];
	$name = strtr( $name, $accents );

	// Normalise every dash variant (hyphen, en/em dash, minus) to a space, then
	// collapse runs of non-alphanumerics to a single space.
	$name = preg_replace( '/[\x{2010}-\x{2015}\x{2212}\-]+/u', ' ', $name ) ?? $name;
	$name = preg_replace( '/[^a-z0-9]+/u', ' ', $name ) ?? $name;
	$name = preg_replace( '/\s+/', ' ', $name ) ?? $name;

	return trim( $name );
}

/**
 * Build a stable URL-friendly slug for a riding (used as a cache lookup key
 * and as the `pt_riding_slug` value the riding-report template expects).
 */
function pt_riding_slug( string $name ): string {
	$normal = pt_normalize_riding_name( $name );
	$slug   = str_replace( ' ', '-', $normal );
	$slug   = preg_replace( '/-+/', '-', $slug ) ?? $slug;
	return trim( $slug, '-' );
}

/**
 * Map a federal riding number to a province/territory code.
 *
 * The first two digits of a Representation-Order FED number encode the
 * province (Statistics Canada SGC). Returns '' when unknown.
 */
function pt_province_from_riding_number( string $riding_number ): string {
	$digits = preg_replace( '/\D/', '', $riding_number );
	if ( strlen( (string) $digits ) < 2 ) {
		return '';
	}
	$prefix = substr( (string) $digits, 0, 2 );
	$map    = [
		'10' => 'NL', '11' => 'PE', '12' => 'NS', '13' => 'NB', '24' => 'QC',
		'35' => 'ON', '46' => 'MB', '47' => 'SK', '48' => 'AB', '59' => 'BC',
		'60' => 'YT', '61' => 'NT', '62' => 'NU',
	];
	return $map[ $prefix ] ?? '';
}

/**
 * Heuristic governing-party label for a federal agreement year.
 *
 * Used purely for the era-comparison colouring in the report (the federal
 * governing party is a national fact, identical for every riding). Pre-2006 is
 * the Chrétien/Martin Liberal era, 2006–2014 Harper-era Conservative, 2015+
 * the Trudeau-era Liberal.
 */
function pt_governing_party_for_year( int $year ): string {
	if ( $year >= 2006 && $year <= 2014 ) {
		return 'Conservative';
	}
	return 'Liberal';
}

/**
 * Collect the top-N rows per group from a stream that is already ordered by
 * group and then by the metric descending.
 *
 * Memory is bounded to N rows per group regardless of how large the input
 * stream is — the warmer relies on this to roll up top recipients/programs
 * across millions of source rows without buffering them.
 *
 * @param iterable<array<string,mixed>> $rows      Ordered rows.
 * @param string                        $group_key Row key identifying the group.
 * @param int                           $limit     Max rows to keep per group.
 * @return array<string,array<int,array<string,mixed>>> group => kept rows
 */
function pt_collect_top_per_group( iterable $rows, string $group_key, int $limit = 10 ): array {
	$limit = max( 1, $limit );
	$out   = [];
	foreach ( $rows as $row ) {
		$group = (string) ( $row[ $group_key ] ?? '' );
		if ( '' === $group ) {
			continue;
		}
		if ( ! isset( $out[ $group ] ) ) {
			$out[ $group ] = [];
		}
		if ( count( $out[ $group ] ) < $limit ) {
			$out[ $group ][] = $row;
		}
	}
	return $out;
}

/**
 * Format a recipient-type code to a human label, falling back to a built-in
 * map when the source `recipient_types` table doesn't resolve it.
 *
 * @param array<string,string> $lookup code => description from recipient_types.
 */
function pt_recipient_type_label( ?string $code, array $lookup ): string {
	$code = strtoupper( trim( (string) $code ) );
	if ( '' === $code ) {
		return 'Unknown / Unspecified';
	}
	if ( isset( $lookup[ $code ] ) && '' !== trim( $lookup[ $code ] ) ) {
		return $lookup[ $code ];
	}
	$builtin = [
		'A' => 'Aboriginal / Indigenous',
		'F' => 'For-profit',
		'G' => 'Government',
		'I' => 'Individual / Sole Proprietor',
		'N' => 'Not-for-profit / Charity',
		'P' => 'Academia',
		'O' => 'Other',
	];
	return $builtin[ $code ] ?? ( 'Type ' . $code );
}

/**
 * Format a dollar amount in compact form ($1.23B / $4.5M / $12K / $340).
 */
function pt_format_money_short( float $value ): string {
	$sign = $value < 0 ? '-' : '';
	$abs  = abs( $value );
	if ( $abs >= 1_000_000_000 ) {
		return $sign . '$' . number_format( $abs / 1_000_000_000, 2 ) . 'B';
	}
	if ( $abs >= 1_000_000 ) {
		return $sign . '$' . number_format( $abs / 1_000_000, 1 ) . 'M';
	}
	if ( $abs >= 1_000 ) {
		return $sign . '$' . number_format( $abs / 1_000, 0 ) . 'K';
	}
	return $sign . '$' . number_format( $abs, 0 );
}

/**
 * Format a dollar amount as a full grouped integer ($1,234,567 / -$5,000).
 */
function pt_format_money( float $value ): string {
	return ( $value < 0 ? '-$' : '$' ) . number_format( abs( $value ), 0 );
}

/**
 * Assemble the final per-riding report payload from the raw aggregate parts.
 *
 * Keeping this a pure transform (no DB, no WP) means the exact JSON shape the
 * front end consumes is unit-tested. The warmer gathers `$parts` from SQL and
 * hands them here; the result is JSON-encoded into the cache row.
 *
 * @param array{
 *   riding:array<string,mixed>,
 *   main:array<string,mixed>,
 *   years:array<int,array<string,mixed>>,
 *   recipients:array<int,array<string,mixed>>,
 *   recipient_types:array<int,array<string,mixed>>,
 *   programs:array<int,array<string,mixed>>,
 *   keywords:array<int,array<string,mixed>>,
 *   generated_at?:string
 * } $parts
 * @return array<string,mixed>
 */
function pt_assemble_riding_payload( array $parts ): array {
	$main = $parts['main'] ?? [];

	// ── Yearly series, sorted ascending, with the governing-party era tag. ──
	$years = [];
	foreach ( (array) ( $parts['years'] ?? [] ) as $row ) {
		$year = (int) ( $row['year'] ?? 0 );
		if ( $year <= 0 ) {
			continue;
		}
		$years[] = [
			'year'                 => $year,
			'party'                => pt_governing_party_for_year( $year ),
			'total'                => (float) ( $row['total'] ?? 0 ),
			'positive_total'       => (float) ( $row['positive_total'] ?? 0 ),
			'negative_total'       => (float) ( $row['negative_total'] ?? 0 ),
			'positive_agreements'  => (int) ( $row['positive_agreements'] ?? 0 ),
			'negative_agreements'  => (int) ( $row['negative_agreements'] ?? 0 ),
		];
	}
	usort( $years, static fn( $a, $b ) => $a['year'] <=> $b['year'] );

	// ── Peak year by net total. ──
	$peak = [ 'year' => 0, 'total' => 0.0 ];
	foreach ( $years as $y ) {
		if ( $y['total'] > $peak['total'] ) {
			$peak = [ 'year' => $y['year'], 'total' => $y['total'] ];
		}
	}

	// ── Recipient-type split, sorted by total descending. ──
	$rec_types = [];
	foreach ( (array) ( $parts['recipient_types'] ?? [] ) as $row ) {
		$rec_types[] = [
			'label'       => (string) ( $row['label'] ?? 'Unknown' ),
			'total'       => (float) ( $row['total'] ?? 0 ),
			'agreements'  => (int) ( $row['agreements'] ?? 0 ),
		];
	}
	usort( $rec_types, static fn( $a, $b ) => $b['total'] <=> $a['total'] );
	$top_rec_type = $rec_types[0]['label'] ?? '';

	// ── Top recipients (already capped by the warmer), sorted by total. ──
	$recipients = [];
	foreach ( (array) ( $parts['recipients'] ?? [] ) as $row ) {
		$recipients[] = [
			'name'        => (string) ( $row['name'] ?? '' ),
			'total'       => (float) ( $row['total'] ?? 0 ),
			'agreements'  => (int) ( $row['agreements'] ?? 0 ),
		];
	}
	usort( $recipients, static fn( $a, $b ) => $b['total'] <=> $a['total'] );

	// ── Top programs, sorted by agreement count. ──
	$programs = [];
	foreach ( (array) ( $parts['programs'] ?? [] ) as $row ) {
		$programs[] = [
			'name'        => (string) ( $row['name'] ?? '' ),
			'agreements'  => (int) ( $row['agreements'] ?? 0 ),
			'total'       => (float) ( $row['total'] ?? 0 ),
		];
	}
	usort( $programs, static fn( $a, $b ) => $b['agreements'] <=> $a['agreements'] );

	// ── Keyword scan, sorted by total descending. ──
	$keywords = [];
	foreach ( (array) ( $parts['keywords'] ?? [] ) as $row ) {
		$keywords[] = [
			'key'         => (string) ( $row['key'] ?? '' ),
			'label'       => (string) ( $row['label'] ?? '' ),
			'total'       => (float) ( $row['total'] ?? 0 ),
			'agreements'  => (int) ( $row['agreements'] ?? 0 ),
		];
	}
	usort( $keywords, static fn( $a, $b ) => $b['total'] <=> $a['total'] );

	return [
		'schema_version' => 1,
		'riding'         => $parts['riding'] ?? [],
		'totals'         => [
			'total_value'          => (float) ( $main['total'] ?? 0 ),
			'positive_total'       => (float) ( $main['positive_total'] ?? 0 ),
			'negative_total'       => (float) ( $main['negative_total'] ?? 0 ),
			'agreement_count'      => (int) ( $main['agreements'] ?? 0 ),
			'negative_agreements'  => (int) ( $main['negative_agreements'] ?? 0 ),
			'first_year'           => (int) ( $main['first_year'] ?? 0 ),
			'last_year'            => (int) ( $main['last_year'] ?? 0 ),
			'max_agreement_value'  => (float) ( $main['max_value'] ?? 0 ),
			'peak_year'            => $peak['year'],
			'peak_year_total'      => $peak['total'],
			'top_recipient_type'   => $top_rec_type,
		],
		'by_year'          => $years,
		'top_recipients'   => $recipients,
		'by_recipient_type' => $rec_types,
		'top_programs'     => $programs,
		'by_keyword'       => $keywords,
		'generated_at'     => (string) ( $parts['generated_at'] ?? gmdate( 'c' ) ),
	];
}
