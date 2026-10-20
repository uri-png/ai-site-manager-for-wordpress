<?php
/**
 * MCP protocol handler: a stateless JSON-RPC 2.0 endpoint implementing the
 * Streamable HTTP transport with plain JSON responses (no SSE - PHP/WordPress
 * cannot hold streaming connections, and the 2026-07-28 spec revision removed
 * the GET stream entirely).
 *
 * Supported methods: initialize, ping, notifications/initialized, tools/list,
 * tools/call. Sessions are intentionally not used (they are optional).
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Speaks MCP (JSON-RPC 2.0) with clients such as Claude and n8n.
 */
final class MCP_MCP {

	/**
	 * Protocol revisions this server can negotiate. Stateless JSON responses
	 * are valid across all of these.
	 */
	private const SUPPORTED_VERSIONS = array(
		'2024-11-05',
		'2025-03-26',
		'2025-06-18',
		'2025-11-25',
		'2026-07-28',
	);

	private const LATEST_VERSION = '2025-11-25';

	/**
	 * Handle one POST to /wp-json/wp-mcp/v1/mcp.
	 *
	 * @param WP_REST_Request $request Authenticated request (key already resolved).
	 * @param object          $api_key Resolved API key row.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request, object $api_key ): WP_REST_Response {
		$body = json_decode( $request->get_body(), true );

		if ( ! is_array( $body ) || ( $body['jsonrpc'] ?? '' ) !== '2.0' || ! isset( $body['method'] ) ) {
			return self::rpc_error( null, -32700, 'Parse error: expected a JSON-RPC 2.0 message.', 400 );
		}

		$id     = $body['id'] ?? null;
		$method = (string) $body['method'];
		$params = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();

		// 2026-07-28 clients mirror the negotiated version into a header and
		// body _meta; when both are present they must agree.
		$header_version = (string) $request->get_header( 'mcp-protocol-version' );
		$meta_version   = $body['_meta']['io.modelcontextprotocol/protocolVersion'] ?? null;
		if ( $header_version && $meta_version && $header_version !== $meta_version ) {
			return self::rpc_error( $id, -32000, 'HeaderMismatch: MCP-Protocol-Version header does not match the body _meta protocol version.', 400 );
		}

		// Notifications (and client responses) get 202 Accepted with no body.
		if ( null === $id ) {
			return new WP_REST_Response( null, 202 );
		}

		switch ( $method ) {
			case 'initialize':
				return self::initialize( $id, $params );

			case 'ping':
				return self::rpc_result( $id, new stdClass() );

			case 'tools/list':
				return self::tools_list( $id );

			case 'tools/call':
				return self::tools_call( $id, $params, $api_key );

			default:
				return self::rpc_error( $id, -32601, "Method not found: {$method}", 404 );
		}
	}

	/**
	 * initialize: negotiate the protocol version and declare capabilities.
	 */
	private static function initialize( $id, array $params ): WP_REST_Response {
		$requested = (string) ( $params['protocolVersion'] ?? '' );
		$version   = in_array( $requested, self::SUPPORTED_VERSIONS, true ) ? $requested : self::LATEST_VERSION;

		return self::rpc_result(
			$id,
			array(
				'protocolVersion' => $version,
				'capabilities'    => array(
					'tools' => array( 'listChanged' => false ),
				),
				'serverInfo'      => array(
					'name'    => 'ai-site-manager',
					'title'   => 'AI Site Manager (' . get_bloginfo( 'name' ) . ')',
					'version' => WPMCP_VERSION,
				),
				'instructions'    => 'WordPress tools exposed by AI Site Manager. Destructive actions may require administrator approval; a pending_approval result includes an approval_id to poll.',
			)
		);
	}

	/**
	 * tools/list: every admin-enabled tool, in MCP schema form.
	 */
	private static function tools_list( $id ): WP_REST_Response {
		$tools = array();
		foreach ( MCP_Registry::enabled() as $tool ) {
			$tools[] = $tool->to_mcp_schema();
		}
		return self::rpc_result( $id, array( 'tools' => $tools ) );
	}

	/**
	 * tools/call: validate, authorize and run one tool.
	 */
	private static function tools_call( $id, array $params, object $api_key ): WP_REST_Response {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		if ( '' === $name ) {
			return self::rpc_error( $id, -32602, 'Invalid params: "name" is required.', 400 );
		}

		$result = MCP_Executor::execute( $name, $args, $api_key );

		if ( $result['ok'] ) {
			$text = wp_json_encode( $result['data'] ?? null );
			return self::rpc_result(
				$id,
				array(
					'content'           => array(
						array(
							'type' => 'text',
							'text' => false === $text ? 'null' : $text,
						),
					),
					'structuredContent' => $result['data'] ?? null,
					'isError'           => false,
				)
			);
		}

		return self::rpc_result(
			$id,
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => (string) ( $result['error'] ?? 'Tool execution failed.' ),
					),
				),
				'isError' => true,
			)
		);
	}

	/**
	 * Wrap a JSON-RPC result.
	 *
	 * @param mixed $id     Request id.
	 * @param mixed $result Result payload.
	 */
	private static function rpc_result( $id, $result ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
		$response->header( 'Content-Type', 'application/json' );
		return $response;
	}

	/**
	 * Wrap a JSON-RPC error.
	 */
	private static function rpc_error( $id, int $code, string $message, int $http_status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			$http_status
		);
		$response->header( 'Content-Type', 'application/json' );
		return $response;
	}
}


