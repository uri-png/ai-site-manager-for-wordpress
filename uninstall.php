<?php
/**
 * Uninstall: drop the plugin tables and options. Only runs on explicit delete.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcp_api_keys" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcp_approval_queue" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcp_audit_logs" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcp_oauth_clients" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcp_oauth_codes" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcp_oauth_tokens" );
// phpcs:enable

wp_clear_scheduled_hook( 'wpmcp_daily_cleanup' );

delete_option( 'wpmcp_version' );
delete_option( 'wpmcp_enabled' );
delete_option( 'wpmcp_require_approval' );
delete_option( 'wpmcp_tool_overrides' );


