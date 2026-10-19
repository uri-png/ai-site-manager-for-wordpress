<?php
/**
 * Tool executor: schema validation, capability enforcement, human-in-the-loop
 * approval queue, execution and audit logging.
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
 * Runs tools on behalf of authenticated key users.
 */
final class MCP_Executor {

	/**
	 * Execute a tool for the current (key-authenticated) user.
	 *
	 * @param string $name       Tool name.
	 * @param array  $arguments  Raw arguments from the agent.
	 * @param object $api_key    Resolved API key row (id, key_prefix, user_id, label).
	 * @return array{ok:bool,data?:mixed,error?:string,pending_approval?:int}
	 */
	public static function execute( string $name, array $arguments, object $api_key ): array {
		$log_base = array(
			'api_key_id' => (int) $api_key->id,
			'key_prefix' => (string) $api_key->key_prefix,
			'user_id'    => (int) $api_key->user_id,
			'tool_name'  => $name,
			'arguments'  => $arguments,
		);

		$tool = MCP_Registry::get( $name );
		if ( ! $tool ) {
			MCP_Logger::log( $log_base + array( 'status' => MCP_Logger::STATUS_FAILED, 'message' => 'Unknown tool.' ) );
			return array( 'ok' => false, 'error' => "Unknown tool: {$name}" );
		}

		if ( ! MCP_Registry::is_enabled( $name ) ) {
			MCP_Logger::log( $log_base + array( 'status' => MCP_Logger::STATUS_FAILED, 'message' => 'Tool is disabled by the site administrator.' ) );
			return array( 'ok' => false, 'error' => "Tool is disabled by the site administrator: {$name}" );
		}

		$valid = self::validate( $tool->exec_schema ?? $tool->input_schema, $arguments );
		if ( is_wp_error( $valid ) ) {
			MCP_Logger::log( $log_base + array( 'status' => MCP_Logger::STATUS_FAILED, 'message' => 'Validation: ' . $valid->get_error_message() ) );
			return array( 'ok' => false, 'error' => 'Invalid arguments: ' . $valid->get_error_message() );
		}

		try {
			$allowed = (bool) call_user_func( $tool->cap_check, $arguments );
		} catch ( Throwable $e ) {
			$allowed = false;
		}
		if ( ! $allowed ) {
			MCP_Logger::log( $log_base + array( 'status' => MCP_Logger::STATUS_FAILED, 'message' => 'Capability check failed for user ' . $api_key->user_id . '.' ) );
			return array( 'ok' => false, 'error' => 'The WordPress user bound to this API key is not allowed to run this action with these arguments.' );
		}

		// Human-in-the-loop: destructive tools can be queued for admin approval.
		if ( $tool->destructive && self::approval_required() ) {
			$queue_id = self::enqueue( $api_key, $tool->name, $arguments );
			if ( is_wp_error( $queue_id ) ) {
				MCP_Logger::log( $log_base + array( 'status' => MCP_Logger::STATUS_FAILED, 'message' => $queue_id->get_error_message() ) );
				return array( 'ok' => false, 'error' => 'Could not queue the action for approval.' );
			}
			MCP_Logger::log( $log_base + array( 'status' => MCP_Logger::STATUS_PENDING, 'message' => "Queued for approval (#{$queue_id})." ) );
			return array(
				'ok'                => true,
				'pending_approval'  => $queue_id,
				'data'              => array(
					'status'      => 'pending_approval',
					'approval_id' => $queue_id,
					'message'     => 'This destructive action is waiting for a site administrator to approve it. Check back with the approval ID.',
				),
			);
		}

		return self::run( $tool, $arguments, $log_base );
	}

	/**
	 * Actually invoke the tool callback and log the outcome.
	 *
	 * @param MCP_Tool $tool
	 * @param array    $arguments
	 * @param array    $log_base
	 * @return array{ok:bool,data?:mixed,error?:string}
	 */
	private static function run( MCP_Tool $tool, array $arguments, array $log_base ): array {
		try {
			$data = call_user_func( $tool->callback, $arguments );
			MCP_Logger::log( $log_base + array( 'status' => MCP_Logger::STATUS_SUCCESS, 'message' => self::summarize( $data ) ) );
			return array( 'ok' => true, 'data' => $data );
		} catch ( Throwable $e ) {
			MCP_Logger::log( $log_base + array( 'status' => MCP_Logger::STATUS_FAILED, 'message' => $e->getMessage() ) );
			return array( 'ok' => false, 'error' => $e->getMessage() );
		}
	}

	/**
	 * Insert a pending-approval row.
	 *
	 * @return int|WP_Error Queue ID.
	 */
	private static function enqueue( object $api_key, string $tool_name, array $arguments ) {
		global $wpdb;
		$ok = $wpdb->insert(
			MCP_DB::table( 'approval_queue' ),
			array(
				'api_key_id' => (int) $api_key->id,
				'user_id'    => (int) $api_key->user_id,
				'tool_name'  => $tool_name,
				'arguments'  => wp_json_encode( $arguments ),
				'status'     => 'pending',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : new WP_Error( 'wpmcp_queue_failed', 'Queue insert failed.' );
	}

	/**
	 * Approve a queued action: re-run capability check as the queued user and execute.
	 *
	 * @param int $queue_id  Queue row ID.
	 * @param int $admin_id  Approving administrator.
	 * @return array{ok:bool,data?:mixed,error?:string}
	 */
	public static function approve( int $queue_id, int $admin_id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MCP_DB::table( 'approval_queue' ) . ' WHERE id = %d LIMIT 1', $queue_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row || 'pending' !== $row->status ) {
			return array( 'ok' => false, 'error' => 'Approval request not found or already decided.' );
		}

		$tool = MCP_Registry::get( $row->tool_name );
		if ( ! $tool || ! MCP_Registry::is_enabled( $row->tool_name ) ) {
			return array( 'ok' => false, 'error' => 'The tool no longer exists or is disabled.' );
		}

		$arguments = json_decode( (string) $row->arguments, true );
		$arguments = is_array( $arguments ) ? $arguments : array();

		$previous_user = get_current_user_id();
		wp_set_current_user( (int) $row->user_id );
		$allowed = false;
		try {
			$allowed = (bool) call_user_func( $tool->cap_check, $arguments );
		} catch ( Throwable $e ) {
			$allowed = false;
		}

		if ( ! $allowed ) {
			wp_set_current_user( $previous_user );
			return array( 'ok' => false, 'error' => 'The queued user no longer has permission for this action.' );
		}

		$result = self::run(
			$tool,
			$arguments,
			array(
				'api_key_id' => (int) $row->api_key_id,
				'key_prefix' => '',
				'user_id'    => (int) $row->user_id,
				'tool_name'  => $row->tool_name,
				'arguments'  => $arguments,
			)
		);
		wp_set_current_user( $previous_user );

		$wpdb->update(
			MCP_DB::table( 'approval_queue' ),
			array(
				'status'     => $result['ok'] ? 'executed' : 'failed',
				'result'     => wp_json_encode( $result ),
				'decided_by' => $admin_id,
				'decided_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $queue_id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		return $result;
	}

	/**
	 * Deny a queued action.
	 */
	public static function deny( int $queue_id, int $admin_id ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			MCP_DB::table( 'approval_queue' ),
			array(
				'status'     => 'denied',
				'decided_by' => $admin_id,
				'decided_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $queue_id, 'status' => 'pending' ),
			array( '%s', '%d', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Status lookup so agents can poll a pending approval.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function approval_status( int $queue_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, tool_name, status, result, created_at, decided_at FROM ' . MCP_DB::table( 'approval_queue' ) . ' WHERE id = %d LIMIT 1', $queue_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row ) {
			return null;
		}
		$out = array(
			'approval_id' => (int) $row->id,
			'tool'        => $row->tool_name,
			'status'      => $row->status,
			'created_at'  => $row->created_at,
			'decided_at'  => $row->decided_at ?? null,
		);
		if ( 'executed' === $row->status && $row->result ) {
			$decoded       = json_decode( (string) $row->result, true );
			$out['result'] = is_array( $decoded ) && isset( $decoded['data'] ) ? $decoded['data'] : $decoded;
		}
		return $out;
	}

	/**
	 * Pending queue rows for the admin UI.
	 *
	 * @return array<int,object>
	 */
	public static function pending(): array {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM " . MCP_DB::table( 'approval_queue' ) . " WHERE status = 'pending' ORDER BY id ASC LIMIT 100" ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function approval_required(): bool {
		return (bool) get_option( 'wpmcp_require_approval', 1 );
	}

	/**
	 * Minimal JSON-Schema validation: type, required, enum, additional unknown
	 * properties are rejected to keep the agent contract tight.
	 *
	 * @param array $schema Tool input schema.
	 * @param array $args   Raw arguments.
	 * @return true|WP_Error
	 */
	private static function validate( array $schema, $args ) {
		if ( ! is_array( $args ) ) {
			return new WP_Error( 'wpmcp_invalid_args', 'Arguments must be a JSON object.' );
		}

		$props    = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		$required = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();

		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $args ) ) {
				return new WP_Error( 'wpmcp_missing_arg', sprintf( 'Missing required argument: %s', $field ) );
			}
		}

		foreach ( $args as $key => $value ) {
			if ( ( ! isset( $props[ $key ] ) || ! is_array( $props[ $key ] ) ) && true === ( $schema['additionalProperties'] ?? false ) ) {
				continue; // Pass-through schema: the target (e.g. a WordPress ability) validates the input itself.
			}
			if ( ! isset( $props[ $key ] ) || ! is_array( $props[ $key ] ) ) {
				return new WP_Error( 'wpmcp_unknown_arg', sprintf( 'Unknown argument: %s', $key ) );
			}
			$rule = $props[ $key ];

			if ( isset( $rule['type'] ) && ! self::type_matches( $rule['type'], $value ) ) {
				$want = is_array( $rule['type'] ) ? implode( '|', array_filter( array_map( 'strval', $rule['type'] ) ) ) : (string) $rule['type'];
				return new WP_Error( 'wpmcp_bad_type', sprintf( 'Argument %s must be of type %s.', $key, $want ) );
			}
			foreach ( array( 'anyOf', 'oneOf' ) as $combiner ) {
				if ( ! isset( $rule[ $combiner ] ) || ! is_array( $rule[ $combiner ] ) ) {
					continue;
				}
				$combiner_ok = false;
				foreach ( $rule[ $combiner ] as $sub ) {
					if ( ! is_array( $sub ) ) {
						continue;
					}
					if ( ! isset( $sub['type'] ) || self::type_matches( $sub['type'], $value ) ) {
						$combiner_ok = true;
						break;
					}
				}
				if ( ! $combiner_ok ) {
					return new WP_Error( 'wpmcp_bad_type', sprintf( 'Argument %s must match at least one %s branch.', $key, $combiner ) );
				}
			}
			if ( isset( $rule['enum'] ) && is_array( $rule['enum'] ) && ! in_array( $value, $rule['enum'], true ) ) {
				return new WP_Error( 'wpmcp_bad_enum', sprintf( 'Argument %s must be one of: %s', $key, implode( ', ', $rule['enum'] ) ) );
			}
			$max_len = isset( $rule['max_length'] ) && is_int( $rule['max_length'] ) ? $rule['max_length'] : 1 * MB_IN_BYTES;
			if ( isset( $rule['type'] ) && 'string' === $rule['type'] && is_string( $value ) && strlen( $value ) > $max_len ) {
				return new WP_Error( 'wpmcp_too_long', sprintf( 'Argument %s exceeds the %d MB limit.', $key, (int) ( $max_len / MB_IN_BYTES ) ) );
			}
		}
		return true;
	}

	private static function type_matches( $type, $value ): bool {
		// Union types (["string","null"]) match when any branch matches.
		if ( is_array( $type ) ) {
			foreach ( $type as $branch ) {
				if ( self::type_matches( $branch, $value ) ) {
					return true;
				}
			}
			return false;
		}
		// Missing/unknown constraint: do not reject.
		if ( ! is_string( $type ) || '' === $type ) {
			return true;
		}
		switch ( $type ) {
			case 'null':
				return null === $value;
			case 'string':
				return is_string( $value );
			case 'integer':
				return is_int( $value ) || ( is_string( $value ) && ctype_digit( ltrim( $value, '-' ) ) );
			case 'number':
				return is_int( $value ) || is_float( $value ) || is_numeric( $value );
			case 'boolean':
				return is_bool( $value );
			case 'array':
				return is_array( $value ) && ( array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) );
			case 'object':
				return is_array( $value );
			default:
				return true;
		}
	}

	private static function summarize( $data ): string {
		$json = wp_json_encode( $data );
		if ( false === $json ) {
			return '[unserializable result]';
		}
		return strlen( $json ) > 2000 ? substr( $json, 0, 2000 ) . '…[truncated]' : $json;
	}
}

