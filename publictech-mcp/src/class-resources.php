<?php
/**
 * MCP resources catalog. Exposes documentation-style resources that AI
 * clients can pull into their context window before issuing tool calls:
 * schema descriptions, tier limits, and a glossary of category codes.
 *
 * @package PublicTech\MCP
 */

namespace PublicTech\MCP;

defined( 'ABSPATH' ) || exit;

use PublicTech\Core\Access_Tiers;
use PublicTech\Core\Multi_DB;

final class Resources {
	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	public function available( ?int $user_id ): array {
		$out = [
			[ 'uri' => 'publictech://schemas', 'name' => 'Connected schemas', 'mimeType' => 'application/json' ],
			[ 'uri' => 'publictech://tier',    'name' => 'Your access tier',  'mimeType' => 'application/json' ],
			[ 'uri' => 'publictech://glossary','name' => 'Category glossary', 'mimeType' => 'application/json' ],
		];
		return [ 'resources' => $out ];
	}

	public function read( ?int $user_id, string $uri, int $row_cap ): array {
		$payload = match ( $uri ) {
			'publictech://schemas'  => Multi_DB::instance()->health(),
			'publictech://tier'     => Access_Tiers::instance()->quota( $user_id ),
			'publictech://glossary' => apply_filters( 'publictech_category_glossary', [
				'office'      => 'General office operations expenditure.',
				'travel'      => 'Travel claims for the member or their staff.',
				'hospitality' => 'Meals, receptions, and hosting expenses.',
				'contracted'  => 'Outside professional services.',
				'green_tech'  => 'Renewable energy and climate-tech disbursements.',
			] ),
			default => throw new \RuntimeException( 'Unknown resource URI', -32004 ),
		};
		return [
			'contents' => [
				[ 'uri' => $uri, 'mimeType' => 'application/json', 'text' => wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ],
			],
		];
	}
}
