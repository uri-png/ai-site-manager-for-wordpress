<?php
/**
 * Minimal OAuth 2.1 authorization server so claude.ai custom connectors can
 * sign in without static headers.
 *
 * Implements what MCP clients actually use:
 *  - RFC 9728 protected-resource metadata:  /.well-known/oauth-protected-resource
 *  - RFC 8414 authorization-server metadata: /.well-known/oauth-authorization-server
 *  - RFC 7591 dynamic client registration:  POST /wp-json/wp-mcp/v1/oauth/register
 *  - Authorization endpoint (PKCE S256):    GET  /oauth/authorize (browser path; the REST route redirects here)
 *  - Token endpoint:                        POST /wp-json/wp-mcp/v1/oauth/token
 *    (grant_types: authorization_code, refresh_token - rotated)
 *
 * Access tokens are bound to the WordPress user who approved the consent
 * screen; the gateway runs their tools with that user's capabilities.
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
 * OAuth 2.1 server (public clients, PKCE only).
 */
final class MCP_OAuth {

	private const ACCESS_TTL  = HOUR_IN_SECONDS;
	private const REFRESH_TTL = 30 * DAY_IN_SECONDS;
	private const CODE_TTL    = 10 * MINUTE_IN_SECONDS;

	/* ---------------------------------------------------------------------
	 * Tables (activation)
	 * ------------------------------------------------------------------- */

	public static function activate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}mcp_oauth_clients (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				client_id varchar(64) NOT NULL,
				client_name varchar(190) NOT NULL DEFAULT '',
				redirect_uris text NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY client_id (client_id)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}mcp_oauth_tokens (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token_hash char(64) NOT NULL,
				token_type varchar(20) NOT NULL,
				client_id varchar(64) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				expires_at datetime NOT NULL,
				revoked tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY user_id (user_id)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}mcp_oauth_codes (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				code_hash char(64) NOT NULL,
				client_id varchar(64) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				redirect_uri text NOT NULL,
				code_challenge varchar(190) NOT NULL,
				expires_at datetime NOT NULL,
				used tinyint(1) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY code_hash (code_hash)
			) {$charset_collate};"
		);
	}

	/* ---------------------------------------------------------------------
	 * Discovery (domain-root well-known URLs via rewrite rules)
	 * ------------------------------------------------------------------- */

	public static function add_rewrite_rules(): void {
		add_rewrite_rule( '^\.well-known/oauth-protected-resource/?$', 'index.php?wpmcp_discovery=protected-resource', 'top' );
		add_rewrite_rule( '^\.well-known/oauth-authorization-server/?$', 'index.php?wpmcp_discovery=authorization-server', 'top' );
	}

	public static function query_vars( array $vars ): array {
		$vars[] = 'wpmcp_discovery';
		return $vars;
	}

	/**
	 * Serve the two discovery documents. Runs early on 'init' and matches the
	 * request path directly, so it works even when rewrite rules were never
	 * flushed (e.g. plugin replaced via ZIP upload, which does not always run
	 * activation reliably on every host).
	 */
	public static function maybe_serve_discovery(): void {
		$which = self::discovery_request();
		if ( ! $which ) {
			return;
		}

		// Discovery docs are public and fetched cross-origin by MCP clients
		// (e.g. the claude.ai frontend), so they must carry CORS headers.
		self::out_header( 'Access-Control-Allow-Origin: *' );
		self::out_header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
		self::out_header( 'Access-Control-Allow-Headers: Content-Type' );
		if ( 'OPTIONS' === (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			status_header( 204 );
			self::terminate();
		}

		status_header( 200 );
		self::out_header( 'Content-Type: application/json; charset=utf-8' );
		self::out_header( 'Cache-Control: no-store' );
		echo wp_json_encode( self::discovery_doc( $which ) );
		self::terminate();
	}

	/**
	 * Send a raw header; recorded instead under tests.
	 */
	private static function out_header( string $header ): void {
		if ( defined( 'WPMCP_TESTING' ) && WPMCP_TESTING ) {
			$GLOBALS['wpmcp_test_headers'][] = $header;
			return;
		}
		header( $header );
	}

	/**
	 * End the request after short-circuit serving; soft-returns under tests.
	 */
	private static function terminate(): void {
		if ( defined( 'WPMCP_TESTING' ) && WPMCP_TESTING ) {
			throw new WpmcpTestExit();
		}
		exit;
	}

	/**
	 * Which discovery document (if any) the current request asks for.
	 * Matches both the rewrite-rule path and the raw request URI.
	 *
	 * @return string '' | 'protected-resource' | 'authorization-server'
	 */
	public static function discovery_request(): string {
		$via_query_var = (string) get_query_var( 'wpmcp_discovery' );
		foreach ( array( 'protected-resource', 'authorization-server' ) as $kind ) {
			if ( $via_query_var === $kind ) {
				return $kind;
			}
		}

		$request_path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home_path    = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( $home_path && str_starts_with( $request_path, $home_path ) ) {
			$request_path = substr( $request_path, strlen( $home_path ) );
		}
		$request_path = trim( $request_path, '/' );

		if ( '.well-known/oauth-protected-resource' === $request_path ) {
			return 'protected-resource';
		}
		if ( '.well-known/oauth-authorization-server' === $request_path ) {
			return 'authorization-server';
		}
		return '';
	}

	/**
	 * Build a discovery document.
	 *
	 * @return array<string,mixed>
	 */
	public static function discovery_doc( string $which ): array {
		$base = rest_url( 'wp-mcp/v1' );

		if ( 'protected-resource' === $which ) {
			return array(
				'resource'                 => $base . '/mcp',
				'authorization_servers'    => array( home_url() ),
				'bearer_methods_supported' => array( 'header' ),
			);
		}

		return array(
			'issuer'                                => home_url(),
			'authorization_endpoint'                => home_url( '/oauth/authorize' ), // Browser flow lives outside REST: REST cookie auth drops the user without X-WP-Nonce.
			'token_endpoint'                        => $base . '/oauth/token',
			'registration_endpoint'                 => $base . '/oauth/register',
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'revocation_endpoint'                   => $base . '/oauth/revoke',
		);
	}

	/* ---------------------------------------------------------------------
	 * REST routes
	 * ------------------------------------------------------------------- */

	public static function register_routes(): void {
		register_rest_route(
			'wp-mcp/v1',
			'/oauth/register',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_register' ),
				'permission_callback' => '__return_true', // DCR is public by design (RFC 7591).
			)
		);
		register_rest_route(
			'wp-mcp/v1',
			'/oauth/authorize',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_authorize' ),
				'permission_callback' => '__return_true', // Login enforced inside.
			)
		);
		register_rest_route(
			'wp-mcp/v1',
			'/oauth/revoke',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_revoke' ),
				'permission_callback' => '__return_true', // RFC 7009: the token is the credential.
			)
		);
		register_rest_route(
			'wp-mcp/v1',
			'/oauth/token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_token' ),
				'permission_callback' => '__return_true', // PKCE/code is the credential.
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Dynamic client registration (RFC 7591, public clients)
	 * ------------------------------------------------------------------- */

	public static function handle_register( WP_REST_Request $request ): WP_REST_Response {
		if ( ! self::throttle( 'register', 10, HOUR_IN_SECONDS ) ) {
			return self::oauth_error( 'rate_limited', 'Too many registration attempts. Try again later.', 429 );
		}
		global $wpdb;
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();

		$uris = $body['redirect_uris'] ?? null;
		if ( ! is_array( $uris ) || ! $uris ) {
			return self::oauth_error( 'invalid_client_metadata', 'redirect_uris is required (array of absolute https URIs).', 400 );
		}
		foreach ( $uris as $uri ) {
			$scheme = wp_parse_url( (string) $uri, PHP_URL_SCHEME );
			if ( ! is_string( $uri ) || ! in_array( $scheme, array( 'https', 'http' ), true ) ) {
				return self::oauth_error( 'invalid_redirect_uri', 'Every redirect_uri must be an absolute http(s) URI.', 400 );
			}
			// http is only tolerated for loopback redirect URIs (native apps, Claude Code).
			if ( 'http' === $scheme && ! preg_match( '#^http://(localhost|127\.0\.0\.1)(:\d+)?/#', $uri ) ) {
				return self::oauth_error( 'invalid_redirect_uri', 'http redirect_uris are only allowed on loopback.', 400 );
			}
		}

		$client_id = wp_generate_uuid4();
		$wpdb->insert(
			$wpdb->prefix . 'mcp_oauth_clients',
			array(
				'client_id'     => $client_id,
				'client_name'   => sanitize_text_field( (string) ( $body['client_name'] ?? 'MCP client' ) ),
				'redirect_uris' => wp_json_encode( array_map( 'strval', $uris ) ),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		self::audit( 'register', 'Client "' . sanitize_text_field( (string) ( $body['client_name'] ?? 'MCP client' ) ) . '" registered (' . $client_id . ') for: ' . implode( ', ', array_map( 'strval', $uris ) ) );

		return new WP_REST_Response(
			array(
				'client_id'                  => $client_id,
				'client_name'                => sanitize_text_field( (string) ( $body['client_name'] ?? 'MCP client' ) ),
				'redirect_uris'              => array_map( 'strval', $uris ),
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'none',
			),
			201
		);
	}

	/**
	 * Write an OAuth lifecycle event to the audit log (admin-visible).
	 */
	private static function audit( string $step, string $message, string $status = MCP_Logger::STATUS_SUCCESS ): void {
		MCP_Logger::log(
			array(
				'user_id'   => get_current_user_id(),
				'tool_name' => 'oauth.' . $step,
				'status'    => $status,
				'message'   => $message,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Authorization endpoint: wp-login + consent, PKCE code issuance
	 * ------------------------------------------------------------------- */

	public static function handle_authorize( WP_REST_Request $request ) {
		// The interactive flow must not run inside the REST API: WordPress
		// zeroes the cookie-authenticated user on REST requests without an
		// X-WP-Nonce, so a plain browser navigation always looks logged-out.
		// Bounce to the front-end endpoint, which runs on a normal request.
		$args = array();
		foreach ( array( 'response_type', 'client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'scope', 'resource' ) as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value && '' !== $value ) {
				$args[ $field ] = is_array( $value ) ? implode( ' ', $value ) : (string) $value;
			}
		}
		$response = new WP_REST_Response( array( 'redirect' => home_url( '/oauth/authorize' ) ), 302 );
		$response->header( 'Location', add_query_arg( $args, home_url( '/oauth/authorize' ) ) );
		return $response;
	}

	/**
	 * Serve the browser authorization endpoint on a normal front-end request
	 * (cookie auth intact). Matches the raw request path, like the discovery
	 * handler, so it does not depend on flushed rewrite rules.
	 */
	public static function maybe_serve_authorize(): void {
		$request_path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home_path    = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( $home_path && str_starts_with( $request_path, $home_path ) ) {
			$request_path = substr( $request_path, strlen( $home_path ) );
		}
		if ( 'oauth/authorize' !== trim( $request_path, '/' ) ) {
			return;
		}

		$params = array();
		foreach ( array( 'response_type', 'client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'scope', 'resource', 'wpmcp_consent', 'decision', '_wpnonce' ) as $field ) {
			if ( isset( $_GET[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$params[ $field ] = sanitize_text_field( wp_unslash( (string) $_GET[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
		self::authorize_flow( $params );
		self::terminate();
	}

	/**
	 * The interactive authorize/consent flow. Runs on a front-end request so
	 * the visitor's auth cookie applies. Never returns: redirects or renders.
	 *
	 * @param array<string,string> $params Sanitized request parameters.
	 */
	public static function authorize_flow( array $params ): void {
		$get = function ( string $key ) use ( $params ): string {
			return isset( $params[ $key ] ) ? (string) $params[ $key ] : '';
		};

		$client_id        = $get( 'client_id' );
		$redirect_uri     = $get( 'redirect_uri' );
		$state            = $get( 'state' );
		$code_challenge   = $get( 'code_challenge' );
		$challenge_method = $get( 'code_challenge_method' );
		$response_type    = $get( 'response_type' );

		if ( 'code' !== $response_type || '' === $client_id || '' === $redirect_uri ) {
			self::browser_error( 400, 'invalid_request', 'Required: response_type=code, client_id, redirect_uri.' );
		}
		if ( 'S256' !== $challenge_method || '' === $code_challenge ) {
			self::browser_error( 400, 'invalid_request', 'PKCE with code_challenge_method=S256 is required.' );
		}

		$client = self::find_client( $client_id );
		if ( ! $client ) {
			self::browser_error( 400, 'invalid_client', 'Unknown client_id.' );
		}
		if ( ! in_array( $redirect_uri, json_decode( (string) $client->redirect_uris, true ) ?: array(), true ) ) {
			self::browser_error( 400, 'invalid_request', 'redirect_uri does not match the registered URIs.' );
		}

		self::audit( 'authorize', 'Authorize requested for client "' . $client->client_name . '" (' . $client_id . ').' );

		// Step 1: require a logged-in WordPress user; bounce through wp-login.
		if ( ! is_user_logged_in() ) {
			self::audit( 'authorize', 'Visitor not logged in; bouncing to wp-login (client ' . $client_id . ').' );
			$args = $params;
			unset( $args['wpmcp_consent'], $args['decision'], $args['_wpnonce'] );
			wp_safe_redirect( wp_login_url( add_query_arg( $args, home_url( '/oauth/authorize' ) ) ) );
			exit;
		}

		// Step 2: consent form submission.
		if ( '1' === $get( 'wpmcp_consent' ) ) {
			check_admin_referer( 'wpmcp_consent_' . $client_id );

			if ( 'deny' === $get( 'decision' ) ) {
				self::audit( 'consent', 'Consent denied for client "' . $client->client_name . '".', MCP_Logger::STATUS_FAILED );
				// External callback host is expected here; the URI was
				// validated against the registered list above.
				wp_redirect( add_query_arg( array( 'error' => 'access_denied', 'state' => $state ), $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect
				exit;
			}

			$code = bin2hex( random_bytes( 32 ) );
			global $wpdb;
			$wpdb->insert(
				$wpdb->prefix . 'mcp_oauth_codes',
				array(
					'code_hash'      => self::hash( $code ),
					'client_id'      => $client_id,
					'user_id'        => get_current_user_id(),
					'redirect_uri'   => $redirect_uri,
					'code_challenge' => $code_challenge,
					'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + self::CODE_TTL ),
					'used'           => 0,
				),
				array( '%s', '%s', '%d', '%s', '%s', '%s', '%d' )
			);

			self::audit( 'consent', 'Consent granted for client "' . $client->client_name . '"; code issued.' );
			wp_redirect( add_query_arg( array( 'code' => $code, 'state' => $state ), $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect
			exit;
		}

		// Step 3: show the consent screen.
		$user = wp_get_current_user();
		self::out_header( 'Content-Type: text/html; charset=utf-8' );
		$action = esc_url( home_url( '/oauth/authorize' ) );
		$hidden = '';
		foreach ( array( 'response_type', 'client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'scope', 'resource' ) as $field ) {
			$value = $get( $field );
			if ( '' !== $value ) {
				$hidden .= '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( $value ) . '" />';
			}
		}
		// Both built from esc_attr()'d parts above, and wp_nonce_field() output.
		$nonce = wp_nonce_field( 'wpmcp_consent_' . $client_id, '_wpnonce', true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Authorize MCP client</title>
<style>body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f0f0f1;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}.card{background:#fff;border:1px solid #c3c4c7;border-radius:8px;max-width:420px;padding:32px}h1{font-size:20px}.btn{display:inline-block;padding:10px 20px;border-radius:4px;border:0;font-size:14px;cursor:pointer;text-decoration:none}.yes{background:#2271b1;color:#fff}.no{background:#f6f7f7;border:1px solid #c3c4c7;color:#2c3338;margin-left:8px}.muted{color:#646970;font-size:13px}</style>
</head><body><div class="card">';
		echo '<h1>' . esc_html( $client->client_name ) . ' wants to connect</h1>';
		echo '<p>It will act on this site as <strong>' . esc_html( $user->user_login ) . '</strong>, limited to that account\'s WordPress permissions.</p>';
		echo '<p class="muted">Site: ' . esc_html( home_url() ) . '</p>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $action is esc_url()'d, $hidden built from esc_attr()'d parts, $nonce is wp_nonce_field() output.
		echo '<form method="get" action="' . $action . '">' . $hidden . $nonce . '<input type="hidden" name="wpmcp_consent" value="1" />';
		echo '<button class="btn yes" type="submit" name="decision" value="allow">Allow</button>';
		echo '<button class="btn no" type="submit" name="decision" value="deny">Deny</button>';
		echo '</form></div></body></html>';
	}

	/**
	 * Render a browser-facing OAuth error and stop.
	 */
	private static function browser_error( int $status, string $error, string $description ): void {
		self::audit( 'authorize', $error . ': ' . $description, MCP_Logger::STATUS_FAILED );
		status_header( $status );
		self::out_header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html><head><meta charset="utf-8"><title>Authorization error</title></head><body style="font-family:sans-serif;max-width:480px;margin:4em auto"><h1>Authorization error</h1><p><strong>' . esc_html( $error ) . '</strong>: ' . esc_html( $description ) . '</p></body></html>';
		self::terminate();
	}

	/* ---------------------------------------------------------------------
	 * Token revocation (RFC 7009)
	 * ------------------------------------------------------------------- */

	public static function handle_revoke( WP_REST_Request $request ): WP_REST_Response {
		if ( ! self::throttle( 'revoke', 30, MINUTE_IN_SECONDS ) ) {
			return self::oauth_error( 'rate_limited', 'Too many requests. Try again later.', 429 );
		}
		$params = $request->get_body_params();
		$token  = (string) ( $params['token'] ?? '' );
		if ( '' !== $token ) {
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'mcp_oauth_tokens',
				array( 'revoked' => 1 ),
				array( 'token_hash' => self::hash( $token ) ),
				array( '%d' ),
				array( '%s' )
			);
			self::audit( 'revoke', 'Token revocation requested (' . substr( $token, 0, 12 ) . '...).' );
		}
		// RFC 7009: always 200, whether or not the token existed.
		return new WP_REST_Response( new stdClass(), 200 );
	}

	/* ---------------------------------------------------------------------
	 * Housekeeping: throttle, cleanup, client revocation
	 * ------------------------------------------------------------------- */

	/**
	 * Per-IP sliding-window throttle (transient backed).
	 */
	private static function throttle( string $bucket, int $limit, int $window ): bool {
		$ip    = (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$key   = 'wpmcp_rl_' . $bucket . '_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}

	/**
	 * Daily cron: drop expired codes and tokens.
	 */
	public static function cleanup(): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'mcp_oauth_codes WHERE expires_at < %s', $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'mcp_oauth_tokens WHERE expires_at < %s', $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Revoke a registered client and every code/token it holds (admin action).
	 */
	public static function revoke_client( string $client_id ): void {
		global $wpdb;
		$client = self::find_client( $client_id );
		if ( ! $client ) {
			return;
		}
		$wpdb->delete( $wpdb->prefix . 'mcp_oauth_tokens', array( 'client_id' => $client_id ), array( '%s' ) );
		$wpdb->delete( $wpdb->prefix . 'mcp_oauth_codes', array( 'client_id' => $client_id ), array( '%s' ) );
		$wpdb->delete( $wpdb->prefix . 'mcp_oauth_clients', array( 'client_id' => $client_id ), array( '%s' ) );
		self::audit( 'revoke', 'Client "' . $client->client_name . '" (' . $client_id . ') revoked by an administrator; its tokens were deleted.' );
	}

	/* ---------------------------------------------------------------------
	 * Token endpoint
	 * ------------------------------------------------------------------- */

	public static function handle_token( WP_REST_Request $request ): WP_REST_Response {
		if ( ! self::throttle( 'token', 30, MINUTE_IN_SECONDS ) ) {
			return self::oauth_error( 'rate_limited', 'Too many token requests. Try again later.', 429 );
		}
		$params = $request->get_body_params();
		$grant  = (string) ( $params['grant_type'] ?? '' );

		if ( 'authorization_code' === $grant ) {
			return self::grant_authorization_code( $params );
		}
		if ( 'refresh_token' === $grant ) {
			return self::grant_refresh_token( $params );
		}
		return self::oauth_error( 'unsupported_grant_type', 'Supported: authorization_code, refresh_token.', 400 );
	}

	private static function grant_authorization_code( array $params ): WP_REST_Response {
		global $wpdb;

		foreach ( array( 'code', 'redirect_uri', 'client_id', 'code_verifier' ) as $field ) {
			if ( empty( $params[ $field ] ) ) {
				return self::oauth_error( 'invalid_request', "Missing {$field}.", 400 );
			}
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mcp_oauth_codes WHERE code_hash = %s LIMIT 1', self::hash( (string) $params['code'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row || $row->used || strtotime( $row->expires_at ) < time() ) {
			return self::oauth_error( 'invalid_grant', 'Code is unknown, used, or expired.', 400 );
		}
		if ( $row->client_id !== $params['client_id'] || $row->redirect_uri !== $params['redirect_uri'] ) {
			return self::oauth_error( 'invalid_grant', 'client_id / redirect_uri mismatch.', 400 );
		}

		// PKCE S256.
		$computed = rtrim( strtr( base64_encode( hash( 'sha256', (string) $params['code_verifier'], true ) ), '+/', '-_' ), '=' );
		if ( ! hash_equals( (string) $row->code_challenge, $computed ) ) {
			return self::oauth_error( 'invalid_grant', 'PKCE verification failed.', 400 );
		}

		$wpdb->update( $wpdb->prefix . 'mcp_oauth_codes', array( 'used' => 1 ), array( 'id' => (int) $row->id ), array( '%d' ), array( '%d' ) );

		self::audit( 'token', 'Authorization code exchanged for tokens (client ' . $row->client_id . ', user ' . (int) $row->user_id . ').' );

		return self::issue_tokens( (int) $row->user_id, $row->client_id );
	}

	private static function grant_refresh_token( array $params ): WP_REST_Response {
		global $wpdb;

		if ( empty( $params['refresh_token'] ) || empty( $params['client_id'] ) ) {
			return self::oauth_error( 'invalid_request', 'Missing refresh_token or client_id.', 400 );
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . $wpdb->prefix . "mcp_oauth_tokens WHERE token_hash = %s AND token_type = 'refresh' LIMIT 1", self::hash( (string) $params['refresh_token'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row || $row->revoked || strtotime( $row->expires_at ) < time() || $row->client_id !== $params['client_id'] ) {
			return self::oauth_error( 'invalid_grant', 'Refresh token is unknown, revoked, or expired.', 400 );
		}

		// Rotate: revoke the presented refresh token, issue a fresh pair.
		$wpdb->update( $wpdb->prefix . 'mcp_oauth_tokens', array( 'revoked' => 1 ), array( 'id' => (int) $row->id ), array( '%d' ), array( '%d' ) );

		return self::issue_tokens( (int) $row->user_id, $row->client_id );
	}

	private static function issue_tokens( int $user_id, string $client_id ): WP_REST_Response {
		global $wpdb;

		$access  = 'mcpat_' . bin2hex( random_bytes( 24 ) );
		$refresh = 'mcprt_' . bin2hex( random_bytes( 24 ) );

		foreach (
			array(
				array( $access, 'access', self::ACCESS_TTL ),
				array( $refresh, 'refresh', self::REFRESH_TTL ),
			) as $token
		) {
			$wpdb->insert(
				$wpdb->prefix . 'mcp_oauth_tokens',
				array(
					'token_hash' => self::hash( $token[0] ),
					'token_type' => $token[1],
					'client_id'  => $client_id,
					'user_id'    => $user_id,
					'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $token[2] ),
					'revoked'    => 0,
					'created_at' => current_time( 'mysql', true ),
				),
				array( '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
			);
		}

		$response = new WP_REST_Response(
			array(
				'access_token'  => $access,
				'token_type'    => 'Bearer',
				'expires_in'    => self::ACCESS_TTL,
				'refresh_token' => $refresh,
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/* ---------------------------------------------------------------------
	 * Access-token resolution for the gateway
	 * ------------------------------------------------------------------- */

	/**
	 * Resolve a Bearer access token to a user ID (or null).
	 *
	 * @param string $token Raw token.
	 * @return int|null
	 */
	public static function resolve_access_token( string $token ): ?int {
		global $wpdb;
		if ( ! str_starts_with( $token, 'mcpat_' ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT user_id, expires_at, revoked FROM " . $wpdb->prefix . "mcp_oauth_tokens WHERE token_hash = %s AND token_type = 'access' LIMIT 1", self::hash( $token ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row || $row->revoked || strtotime( $row->expires_at ) < time() ) {
			return null;
		}
		return (int) $row->user_id;
	}

	/* ------------------------------------------------------------------- */

	private static function find_client( string $client_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'mcp_oauth_clients WHERE client_id = %s LIMIT 1', $client_id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	private static function oauth_error( string $code, string $description, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( array( 'error' => $code, 'error_description' => $description ), $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
