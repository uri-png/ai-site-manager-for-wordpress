<?php
/**
 * Database layer: custom tables for API keys, the approval queue and audit logs.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// All queries in this file run against the plugin's own custom tables, with
// values passed through $wpdb->prepare() and table names derived from
// $wpdb->prefix. Results are short-lived request data, so no object caching.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter

/**
 * Creates and upgrades the plugin tables with dbDelta.
 */
final class MCP_DB {

	/**
	 * Run on plugin activation. Creates all custom tables.
	 */
	public static function activate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$api_keys = $wpdb->prefix . 'mcp_api_keys';
		$queue    = $wpdb->prefix . 'mcp_approval_queue';
		$logs     = $wpdb->prefix . 'mcp_audit_logs';

		$sql = array();

		$sql[] = "CREATE TABLE {$api_keys} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			key_hash char(64) NOT NULL,
			key_prefix varchar(16) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			label varchar(190) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			last_used_at datetime DEFAULT NULL,
			revoked tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY key_hash (key_hash),
			KEY user_id (user_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$queue} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			api_key_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			tool_name varchar(190) NOT NULL,
			arguments longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			result longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			decided_by bigint(20) unsigned DEFAULT NULL,
			decided_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY tool_name (tool_name)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			api_key_id bigint(20) unsigned DEFAULT NULL,
			key_prefix varchar(16) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned DEFAULT NULL,
			tool_name varchar(190) NOT NULL DEFAULT '',
			arguments longtext DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'success',
			message longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status),
			KEY tool_name (tool_name)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		add_option( 'wpmcp_version', WPMCP_VERSION );
	}

	/**
	 * Fully-qualified table name for one of the plugin tables.
	 *
	 * @param string $table One of: api_keys, approval_queue, audit_logs.
	 * @return string
	 */
	public static function table( string $table ): string {
		global $wpdb;
		$map = array(
			'api_keys'       => $wpdb->prefix . 'mcp_api_keys',
			'approval_queue' => $wpdb->prefix . 'mcp_approval_queue',
			'audit_logs'     => $wpdb->prefix . 'mcp_audit_logs',
		);
		return $map[ $table ] ?? '';
	}
}


