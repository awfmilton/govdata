<?php
/**
 * MCP tools catalog. Each tool is a typed wrapper around a read query against
 * the multi-DB layer; arguments are JSON-schema validated before any SQL is
 * built, and results are capped to the caller's tier-allowed row count.
 *
 * @package PublicTech\MCP
 */

namespace PublicTech\MCP;

defined( 'ABSPATH' ) || exit;

use PublicTech\Core\Access_Tiers;

final class Tools {
	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	/** @return array<int,array<string,mixed>> */
	public function definitions(): array {
		return [
			[
				'name'        => 'mp.search',
				'description' => 'Search Members of Parliament by name, party, region or postal-code prefix. Returns id, name, party, region, riding, total_spend, open_flags.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'q'        => [ 'type' => 'string',  'description' => 'Free-text search across name and riding.' ],
						'party'    => [ 'type' => 'array',   'items' => [ 'type' => 'string' ] ],
						'region'   => [ 'type' => 'array',   'items' => [ 'type' => 'string' ] ],
						'postal'   => [ 'type' => 'string',  'description' => 'Postal-code prefix, e.g. K1A or V8L.' ],
						'limit'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 25 ],
					],
				],
			],
			[
				'name'        => 'mp.spending',
				'description' => 'Return spending for a Member of Parliament, grouped by fiscal year and category.',
				'inputSchema' => [
					'type'       => 'object',
					'required'   => [ 'mp_id' ],
					'properties' => [
						'mp_id'     => [ 'type' => 'integer', 'description' => 'WordPress post id of the member.' ],
						'from_year' => [ 'type' => 'integer', 'minimum' => 1990, 'maximum' => 2100 ],
						'to_year'   => [ 'type' => 'integer', 'minimum' => 1990, 'maximum' => 2100 ],
					],
				],
			],
			[
				'name'        => 'transactions.stream',
				'description' => 'Stream raw transactions from a configured schema/table. Honour your tier row cap. Filters are applied as ANDed equality constraints.',
				'inputSchema' => [
					'type'       => 'object',
					'required'   => [ 'schema', 'table' ],
					'properties' => [
						'schema'  => [ 'type' => 'string' ],
						'table'   => [ 'type' => 'string' ],
						'filters' => [ 'type' => 'array' ],
						'order'   => [ 'type' => 'string' ],
						'limit'   => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 250000 ],
					],
				],
			],
			[
				'name'        => 'grants.search',
				'description' => 'Search the grants/contributions schema (pt-grants-02). Supports recipient name, region, category, amount range.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'recipient' => [ 'type' => 'string' ],
						'region'    => [ 'type' => 'string' ],
						'category'  => [ 'type' => 'string' ],
						'min_cad'   => [ 'type' => 'number' ],
						'max_cad'   => [ 'type' => 'number' ],
						'from_date' => [ 'type' => 'string', 'format' => 'date' ],
						'to_date'   => [ 'type' => 'string', 'format' => 'date' ],
						'limit'     => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 100 ],
					],
				],
			],
			[
				'name'        => 'schemas.health',
				'description' => 'Return live connection latency + status for every configured database alias.',
				'inputSchema' => [ 'type' => 'object', 'properties' => [] ],
			],
		];
	}

	public function available( ?int $user_id ): array {
		$quota = Access_Tiers::instance()->quota( $user_id );
		$tools = $this->definitions();

		// Free-tier callers don't see streaming.
		if ( '*' !== $quota['schemas'] ) {
			$tools = array_filter( $tools, static fn( $t ) => $t['name'] !== 'transactions.stream' );
		}
		return [ 'tools' => array_values( $tools ) ];
	}

	public function call( ?int $user_id, string $name, array $args, int $row_cap ): array {
		$quota = Access_Tiers::instance()->quota( $user_id );

		$payload = match ( $name ) {
			'mp.search'           => $this->mp_search( $args, $row_cap ),
			'mp.spending'         => $this->mp_spending( $args ),
			'transactions.stream' => $this->stream( $args, $row_cap, $quota ),
			'grants.search'       => $this->grants_search( $args, $row_cap ),
			'schemas.health'      => \PublicTech\Core\Multi_DB::instance()->health(),
			default               => throw new \RuntimeException( 'Unknown tool: ' . $name, -32601 ),
		};

		return [
			'content' => [
				[ 'type' => 'text', 'text' => wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ],
			],
		];
	}

	/* ----- implementations ----- */

	private function mp_search( array $a, int $cap ): array {
		$limit = min( (int) ( $a['limit'] ?? 25 ), $cap );
		$args = [
			'post_type'      => 'member-parliament',
			'posts_per_page' => $limit,
			's'              => (string) ( $a['q'] ?? '' ),
		];
		if ( ! empty( $a['party'] ) ) {
			$args['tax_query'][] = [ 'taxonomy' => 'mp_party', 'field' => 'slug', 'terms' => array_map( 'sanitize_title', (array) $a['party'] ) ];
		}
		if ( ! empty( $a['region'] ) ) {
			$args['tax_query'][] = [ 'taxonomy' => 'mp_region', 'field' => 'slug', 'terms' => array_map( 'sanitize_title', (array) $a['region'] ) ];
		}
		if ( ! empty( $a['postal'] ) ) {
			$args['meta_query'][] = [ 'key' => 'postal_codes', 'value' => sanitize_text_field( (string) $a['postal'] ), 'compare' => 'LIKE' ];
		}
		$q = new \WP_Query( $args );
		return [ 'total' => (int) $q->found_posts, 'rows' => array_map( [ $this, 'shape_mp' ], $q->posts ) ];
	}

	private function mp_spending( array $a ): array {
		$mp_id = (int) ( $a['mp_id'] ?? 0 );
		if ( $mp_id <= 0 ) {
			throw new \RuntimeException( 'mp_id is required', -32602 );
		}
		$ext = (int) get_post_meta( $mp_id, 'external_mp_id', true );
		if ( ! $ext ) {
			throw new \RuntimeException( 'mp_id is not linked to a finance row', -32004 );
		}
		$from = (int) ( $a['from_year'] ?? 2018 );
		$to   = (int) ( $a['to_year']   ?? (int) gmdate( 'Y' ) );
		return publictech_db( 'pt-finance-01' )->select(
			'SELECT fiscal_year, category, SUM(amount_cad) AS total
			   FROM expenditures
			  WHERE mp_id = ? AND fiscal_year BETWEEN ? AND ?
			  GROUP BY fiscal_year, category
			  ORDER BY fiscal_year, category',
			[ $ext, $from, $to ]
		);
	}

	private function stream( array $a, int $cap, array $quota ): array {
		$schema = sanitize_key( (string) ( $a['schema'] ?? '' ) );
		$table  = sanitize_key( (string) ( $a['table']  ?? '' ) );
		if ( '*' !== $quota['schemas'] && ! in_array( $schema, (array) $quota['schemas'], true ) ) {
			throw new \RuntimeException( 'Schema not included in your tier.', -32003 );
		}
		$cols = apply_filters( "publictech_columns_{$schema}_{$table}", [], $schema, $table );
		if ( ! $cols ) {
			throw new \RuntimeException( "No exposed columns for {$schema}.{$table}", -32004 );
		}
		$limit = min( (int) ( $a['limit'] ?? 1000 ), $cap );
		$stmt  = publictech_db( $schema )->find( $table, (array) ( $a['filters'] ?? [] ), $cols, (string) ( $a['order'] ?? '' ), $limit );
		return $stmt->fetchAll();
	}

	private function grants_search( array $a, int $cap ): array {
		$filters = [];
		$columns = [
			'recipient_name' => '`disbursements`.`recipient_name`',
			'region'         => '`disbursements`.`region`',
			'category'       => '`disbursements`.`category`',
			'amount_cad'     => '`disbursements`.`amount_cad`',
			'awarded_date'   => '`disbursements`.`awarded_date`',
		];
		if ( ! empty( $a['recipient'] ) ) $filters[] = [ 'col' => 'recipient_name', 'op' => 'LIKE', 'val' => '%' . sanitize_text_field( $a['recipient'] ) . '%' ];
		if ( ! empty( $a['region'] ) )    $filters[] = [ 'col' => 'region',         'op' => '=',    'val' => sanitize_text_field( $a['region'] ) ];
		if ( ! empty( $a['category'] ) )  $filters[] = [ 'col' => 'category',       'op' => '=',    'val' => sanitize_text_field( $a['category'] ) ];
		if ( isset( $a['min_cad'] ) )     $filters[] = [ 'col' => 'amount_cad',     'op' => '>=',   'val' => (float) $a['min_cad'] ];
		if ( isset( $a['max_cad'] ) )     $filters[] = [ 'col' => 'amount_cad',     'op' => '<=',   'val' => (float) $a['max_cad'] ];
		if ( ! empty( $a['from_date'] ) && ! empty( $a['to_date'] ) ) {
			$filters[] = [ 'col' => 'awarded_date', 'op' => 'BETWEEN', 'val' => [ $a['from_date'], $a['to_date'] ] ];
		}
		$limit = min( (int) ( $a['limit'] ?? 100 ), $cap );
		$stmt  = publictech_db( 'pt-grants-02' )->find( 'disbursements', $filters, $columns, 'amount_cad DESC', $limit );
		return $stmt->fetchAll();
	}

	private function shape_mp( \WP_Post $p ): array {
		return [
			'id'     => $p->ID,
			'name'   => $p->post_title,
			'party'  => wp_get_object_terms( $p->ID, 'mp_party',  [ 'fields' => 'names' ] )[0] ?? '',
			'region' => wp_get_object_terms( $p->ID, 'mp_region', [ 'fields' => 'names' ] )[0] ?? '',
			'riding' => (string) get_post_meta( $p->ID, 'riding', true ),
			'spend'  => (int) get_post_meta( $p->ID, 'total_spend', true ),
			'flags'  => (int) get_post_meta( $p->ID, 'open_flags',  true ),
			'url'    => get_permalink( $p->ID ),
		];
	}
}
