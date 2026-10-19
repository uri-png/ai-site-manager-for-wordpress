<?php
/**
 * REST server: registers the gateway endpoints, authenticates Bearer API keys
 * and dispatches tool execution.
 *
 * Endpoints (all under /wp-json/wp-mcp/v1):
 *   POST /mcp           - MCP-compatible JSON-RPC 2.0 endpoint (initialize,
 *                         tools/list, tools/call) for standard MCP clients.
 *   POST /tools/list    - plain REST introspection for simple integrations.
 *   POST /tools/execute - plain REST execution: {tool, arguments} or
 *                         {approval_id} to poll a pending approval.
 *   GET  /health        - unauthenticated liveness check (no site data).
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routing and Bearer-token authentication.
 */
final class MCP_REST_Server {

	private const NAMESPACE = 'wp-mcp/v1';

	/**
	 * Register routes on rest_api_init.
	 */
	public static function register_routes(): void {
		$authed = array( self::class, 'authenticate' );

		register_rest_route(
			self::NAMESPACE,
			'/mcp',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'handle_mcp' ),
					'permission_callback' => $authed,
				),
				// This server never opens SSE streams or sessions: GET and
				// DELETE get a spec-compliant 405 with an Allow header,
				// not WordPress's default 404 for unregistered methods.
				array(
					'methods'             => array( WP_REST_Server::READABLE, WP_REST_Server::DELETABLE ),
					'callback'            => array( self::class, 'method_not_allowed' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/tools/list',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_tools_list' ),
				'permission_callback' => $authed,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/tools/execute',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_tools_execute' ),
				'permission_callback' => $authed,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn() => new WP_REST_Response(
					array(
						'status'  => 'ok',
						'version' => WPMCP_VERSION,
					),
					200
				),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Add RFC 6750 WWW-Authenticate to our 401s so MCP clients can tell the
	 * endpoint expects a Bearer token (and future OAuth discovery can hang
	 * off the same header).
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 */
	public static function add_auth_challenge( $response, $server, $request ) {
		if ( 401 === $response->get_status() && str_starts_with( (string) $request->get_route(), '/wp-mcp/v1' ) ) {
			$response->header( 'WWW-Authenticate', 'Bearer realm="wp-mcp-gateway"' );
		}
		return $response;
	}

	/**
	 * Permission callback: resolve the Bearer key and become its WP user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public static function authenticate( WP_REST_Request $request ) {
		if ( ! get_option( 'wpmcp_enabled', 1 ) ) {
			return new WP_Error( 'wpmcp_disabled', 'The MCP gateway is disabled.', array( 'status' => 503 ) );
		}

		$token = self::extract_token( $request );
		if ( null === $token ) {
			return new WP_Error( 'wpmcp_missing_token', 'Provide an API key as "Authorization: Bearer <key>".', array( 'status' => 401 ) );
		}

		$key = MCP_Keys::resolve( $token );
		if ( $key ) {
			wp_set_current_user( (int) $key->user_id );
			$request->set_param( '_wpmcp_api_key', $key );
			return true;
		}

		// OAuth 2.1 access token (from the built-in authorization server).
		$oauth_user = MCP_OAuth::resolve_access_token( $token );
		if ( null !== $oauth_user ) {
			wp_set_current_user( $oauth_user );
			$request->set_param(
				'_wpmcp_api_key',
				(object) array(
					'id'         => 0,
					'key_prefix' => 'oauth',
					'user_id'    => $oauth_user,
					'label'      => 'OAuth client',
				)
			);
			return true;
		}

		return new WP_Error( 'wpmcp_invalid_token', 'Invalid or revoked API key.', array( 'status' => 401 ) );
	}

	/**
	 * POST /mcp - MCP JSON-RPC 2.0 endpoint.
	 */
	public static function handle_mcp( WP_REST_Request $request ): WP_REST_Response {
		return MCP_MCP::handle( $request, $request->get_param( '_wpmcp_api_key' ) );
	}

	/**
	 * GET/DELETE /mcp - this server does not stream or keep sessions.
	 */
	public static function method_not_allowed( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => null,
				'error'   => array(
					'code'    => -32601,
					'message' => 'This MCP server accepts POST only (stateless Streamable HTTP, no SSE streams or sessions).',
				),
			),
			405
		);
		$response->header( 'Allow', 'POST' );
		$response->header( 'Content-Type', 'application/json' );
		return $response;
	}

	/**
	 * POST /tools/list - plain introspection for simple integrations.
	 */
	public static function handle_tools_list( WP_REST_Request $request ): WP_REST_Response {
		$tools = array();
		foreach ( MCP_Registry::all() as $name => $tool ) {
			$tools[] = array(
				'name'        => $name,
				'title'       => $tool->title,
				'description' => $tool->description,
				'category'    => $tool->category,
				'destructive' => $tool->destructive,
				'enabled'     => MCP_Registry::is_enabled( $name ),
				'inputSchema' => $tool->input_schema,
			);
		}
		return new WP_REST_Response( array( 'tools' => $tools ), 200 );
	}

	/**
	 * POST /tools/execute - run one tool, or poll a pending approval.
	 *
	 * Body: {"tool": "posts_create", "arguments": {...}}
	 *    or {"approval_id": 123}
	 */
	public static function handle_tools_execute( WP_REST_Request $request ): WP_REST_Response {
		$api_key = $request->get_param( '_wpmcp_api_key' );
		$body    = $request->get_json_params();
		$body    = is_array( $body ) ? $body : array();

		if ( isset( $body['approval_id'] ) ) {
			$status = MCP_Executor::approval_status( (int) $body['approval_id'] );
			if ( null === $status ) {
				return new WP_REST_Response( array( 'ok' => false, 'error' => 'Approval request not found.' ), 404 );
			}
			return new WP_REST_Response( array( 'ok' => true, 'approval' => $status ), 200 );
		}

		$tool = isset( $body['tool'] ) ? (string) $body['tool'] : '';
		$args = isset( $body['arguments'] ) && is_array( $body['arguments'] ) ? $body['arguments'] : array();

		if ( '' === $tool ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Missing "tool".' ), 400 );
		}

		$result = MCP_Executor::execute( $tool, $args, $api_key );

		$http = $result['ok'] ? 200 : 403;
		if ( ! $result['ok'] && str_starts_with( (string) ( $result['error'] ?? '' ), 'Unknown tool' ) ) {
			$http = 404;
		} elseif ( ! $result['ok'] && str_starts_with( (string) ( $result['error'] ?? '' ), 'Invalid arguments' ) ) {
			$http = 400;
		}

		return new WP_REST_Response( $result, $http );
	}

	/**
	 * Pull the Bearer token from the Authorization header, falling back to the
	 * X-MCP-API-Key header for hosts that strip Authorization.
	 */
	private static function extract_token( WP_REST_Request $request ): ?string {
		$header = (string) $request->get_header( 'authorization' );
		if ( preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', $header, $m ) ) {
			return $m[1];
		}

		$fallback = (string) $request->get_header( 'x-mcp-api-key' );
		if ( '' !== $fallback ) {
			return $fallback;
		}

		// Some CGI/FastCGI setups drop the header entirely; try the raw server vars.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $var ) {
			if ( ! empty( $_SERVER[ $var ] ) && preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', (string) $_SERVER[ $var ], $m ) ) {
				return $m[1];
			}
		}
		// phpcs:enable

		return null;
	}
}

