<?php
/**
 * Audit trail: every gateway request is written to {prefix}mcp_audit_logs
 * with timestamp, key identity, tool, sanitized payload, status and message.
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
 * Writes and reads audit log rows.
 */
final class MCP_Logger {

	public const STATUS_SUCCESS = 'success';
	public const STATUS_FAILED  = 'failed';
	public const STATUS_PENDING = 'pending';

	/**
	 * Record one gateway event.
	 *
	 * @param array{api_key_id?:?int,key_prefix?:string,user_id?:?int,tool_name?:string,arguments?:array,status?:string,message?:string} $event Event data.
	 */
	public static function log( array $event ): void {
		global $wpdb;

		$arguments = $event['arguments'] ?? null;
		if ( is_array( $arguments ) ) {
			$arguments = self::sanitize_for_log( $arguments );
			$arguments = wp_json_encode( $arguments );
		}

		$wpdb->insert(
			MCP_DB::table( 'audit_logs' ),
			array(
				'created_at' => current_time( 'mysql', true ),
				'api_key_id' => $event['api_key_id'] ?? null,
				'key_prefix' => isset( $event['key_prefix'] ) ? (string) $event['key_prefix'] : '',
				'user_id'    => $event['user_id'] ?? null,
				'tool_name'  => isset( $event['tool_name'] ) ? substr( (string) $event['tool_name'], 0, 190 ) : '',
				'arguments'  => is_string( $arguments ) ? $arguments : null,
				'status'     => $event['status'] ?? self::STATUS_SUCCESS,
				'message'    => isset( $event['message'] ) ? (string) $event['message'] : null,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Paginated, filterable log retrieval for the admin viewer.
	 *
	 * @param array{status?:string,tool_name?:string,search?:string} $filters Filters.
	 * @param int                                                    $page    1-based page.
	 * @param int                                                    $per_page Rows per page.
	 * @return array{rows:array<int,object>,total:int}
	 */
	public static function query( array $filters = array(), int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$table  = MCP_DB::table( 'audit_logs' );
		$where  = '1=1';
		$params = array();

		if ( ! empty( $filters['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = $filters['status'];
		}
		if ( ! empty( $filters['tool_name'] ) ) {
			$where   .= ' AND tool_name = %s';
			$params[] = $filters['tool_name'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$where   .= ' AND ( tool_name LIKE %s OR message LIKE %s OR key_prefix LIKE %s )';
			$like     = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$page     = max( 1, $page );
		$per_page = min( 100, max( 1, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$rows_sql  = "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";

		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, ...$params ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...array_merge( $params, array( $per_page, $offset ) ) ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Distinct tool names seen in the log, for the filter dropdown.
	 *
	 * @return array<int,string>
	 */
	public static function tool_names(): array {
		global $wpdb;
		return $wpdb->get_col( 'SELECT DISTINCT tool_name FROM ' . MCP_DB::table( 'audit_logs' ) . " WHERE tool_name <> '' ORDER BY tool_name" ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Strip values that must never sit in a log (passwords, tokens, blobs),
	 * and cap payload size.
	 *
	 * @param array $arguments Raw tool arguments.
	 * @return array
	 */
	private static function sanitize_for_log( array $arguments ): array {
		$deny = array( 'password', 'pass', 'token', 'secret', 'api_key', 'authorization', 'file_content' );
		$walk = function ( $value, $key ) use ( &$walk, $deny ) {
			if ( is_array( $value ) ) {
				$out = array();
				foreach ( $value as $k => $v ) {
					$out[ $k ] = $walk( $v, (string) $k );
				}
				return $out;
			}
			foreach ( $deny as $needle ) {
				if ( false !== stripos( (string) $key, $needle ) ) {
					return '[redacted]';
				}
			}
			if ( is_string( $value ) && strlen( $value ) > 2000 ) {
				return substr( $value, 0, 2000 ) . '…[truncated]';
			}
			return $value;
		};

		$clean = array();
		foreach ( $arguments as $key => $value ) {
			$clean[ $key ] = $walk( $value, (string) $key );
		}
		return $clean;
	}
}


