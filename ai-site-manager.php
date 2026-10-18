<?php
/**
 * Plugin Name:       AI Site Manager
 * Plugin URI:        https://focusweb.co.il/
 * Description:       A secure, stateless MCP (Model Context Protocol) gateway for WordPress. Lets external AI agents (n8n, Claude, custom apps) discover and execute WordPress actions safely over HTTP POST (JSON-RPC 2.0), with scoped API keys, capability checks, human-in-the-loop approvals and a full audit log.
 * Version:           1.1.11
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            FocusWeb
 * Author URI:        https://focusweb.co.il/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-site-manager
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPMCP_VERSION', '1.1.11' );
define( 'WPMCP_PLUGIN_FILE', __FILE__ );
define( 'WPMCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPMCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WPMCP_PLUGIN_DIR . 'includes/class-mcp-db.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-mcp-logger.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-mcp-keys.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-mcp-tool.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-mcp-registry.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-mcp-tools-core.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-mcp-tools-yoast.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-mcp-executor.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-mcp-mcp.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-mcp-oauth.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-mcp-rest-server.php';
require_once WPMCP_PLUGIN_DIR . 'admin/class-mcp-admin.php';
require_once WPMCP_PLUGIN_DIR . 'includes/class-mcp-plugin.php';

register_activation_hook( __FILE__, array( 'MCP_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MCP_Plugin', 'deactivate' ) );

MCP_Plugin::instance();
