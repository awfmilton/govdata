<?php
/**
 * PHPStan bootstrap. Declares constants and lightweight stubs that exist at
 * runtime via wp-config.php / a sibling plugin so the analyser doesn't trip
 * on "undefined constant" errors during static analysis.
 *
 * @package PublicTech\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'PUBLICTECH_DB_CONNECTIONS' ) ) {
	define( 'PUBLICTECH_DB_CONNECTIONS', [
		'pt-finance-01'   => [ 'host' => 'localhost', 'name' => 'pt_finance',   'user' => 'pt', 'pass' => '' ],
		'pt-grants-02'    => [ 'host' => 'localhost', 'name' => 'pt_grants',    'user' => 'pt', 'pass' => '' ],
		'pt-contracts-03' => [ 'host' => 'localhost', 'name' => 'pt_contracts', 'user' => 'pt', 'pass' => '' ],
		'pt-registry-04'  => [ 'host' => 'localhost', 'name' => 'pt_registry',  'user' => 'pt', 'pass' => '' ],
	] );
}
if ( ! defined( 'PUBLICTECH_CORE_VERSION' ) )   define( 'PUBLICTECH_CORE_VERSION',   '1.0.0' );
if ( ! defined( 'PUBLICTECH_CORE_DIR' ) )       define( 'PUBLICTECH_CORE_DIR',       __DIR__ );
if ( ! defined( 'PUBLICTECH_CORE_URL' ) )       define( 'PUBLICTECH_CORE_URL',       'https://example.test/' );
if ( ! defined( 'PUBLICTECH_CORE_REST_NS' ) )   define( 'PUBLICTECH_CORE_REST_NS',   'publictech/v1' );
if ( ! defined( 'PUBLICTECH_TERMINAL_VERSION' ) ) define( 'PUBLICTECH_TERMINAL_VERSION', '1.0.0' );
if ( ! defined( 'PUBLICTECH_TERMINAL_URL' ) )   define( 'PUBLICTECH_TERMINAL_URL',   'https://example.test/' );
if ( ! defined( 'PUBLICTECH_TERMINAL_DIR' ) )   define( 'PUBLICTECH_TERMINAL_DIR',   __DIR__ );
if ( ! defined( 'PUBLICTECH_MCP_VERSION' ) )    define( 'PUBLICTECH_MCP_VERSION',    '1.0.0' );
if ( ! defined( 'PUBLICTECH_MCP_DIR' ) )        define( 'PUBLICTECH_MCP_DIR',        __DIR__ );
if ( ! defined( 'PUBLICTECH_MCP_NS' ) )         define( 'PUBLICTECH_MCP_NS',         'publictech-mcp/v1' );

if ( ! function_exists( 'publictech_db' ) ) {
	function publictech_db( string $alias ): object {
		throw new \RuntimeException( 'phpstan stub' );
	}
}
