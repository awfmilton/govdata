<?php
/**
 * Plugin Name:       PublicTech MCP Server
 * Plugin URI:        https://publictech.audit/mcp
 * Description:       Model Context Protocol server for PublicTech. Lets remote AI clients (Claude Desktop, Cursor, custom agents) discover and call tools that read from the audit-terminal database via the PublicTech Core multi-DB layer. Bearer-token authenticated, tier-throttled, strictly read-only.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Tested up to:      6.8
 * Requires PHP:      8.1
 * Author:            Alexander Milton
 * License:           MPL-2.0
 * License URI:       https://mozilla.org/MPL/2.0/
 * Text Domain:       publictech-mcp
 *
 * The MCP transport here is JSON-RPC 2.0 over HTTP (single endpoint that
 * accepts POST). Streamable HTTP / SSE upgrade lives behind the
 * `publictech_mcp_transport` filter for sites that want to opt in.
 *
 * Wire your client to:  https://YOUR-SITE/wp-json/publictech-mcp/v1/rpc
 * Authenticate with:    Authorization: Bearer pt_xxxxxxxxxxxxxxxx
 *
 * @package PublicTech\MCP
 */

defined( 'ABSPATH' ) || exit;

define( 'PUBLICTECH_MCP_VERSION', '1.0.0' );
define( 'PUBLICTECH_MCP_DIR',     plugin_dir_path( __FILE__ ) );
define( 'PUBLICTECH_MCP_NS',      'publictech-mcp/v1' );

require_once PUBLICTECH_MCP_DIR . 'src/class-server.php';
require_once PUBLICTECH_MCP_DIR . 'src/class-tools.php';
require_once PUBLICTECH_MCP_DIR . 'src/class-resources.php';

add_action( 'rest_api_init', static function () {
	if ( ! class_exists( '\PublicTech\Core\Multi_DB' ) ) {
		return;
	}
	\PublicTech\MCP\Server::instance()->register_routes();
} );

register_activation_hook( __FILE__, static function () {
	if ( ! class_exists( '\PublicTech\Core\Multi_DB' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die( esc_html__( 'PublicTech Core (parent theme) must be active before enabling the MCP server.', 'publictech-mcp' ) );
	}
} );
