<?php
/**
 * Main orchestrator.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin into WordPress.
 */
final class MCP_Plugin {

	private static ?MCP_Plugin $instance = null;

	public static function instance(): MCP_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( MCP_REST_Server::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( MCP_OAuth::class, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( MCP_REST_Server::class, 'add_auth_challenge' ), 10, 3 );
		add_action( 'init', array( MCP_OAuth::class, 'add_rewrite_rules' ), 1 );
		add_action( 'init', array( MCP_OAuth::class, 'maybe_serve_discovery' ), 5 );
		add_action( 'init', array( MCP_OAuth::class, 'maybe_serve_authorize' ), 6 );
		add_action( 'init', array( self::class, 'maybe_upgrade' ), 20 );
		add_action( 'wpmcp_daily_cleanup', array( MCP_OAuth::class, 'cleanup' ) );
		add_filter( 'query_vars', array( MCP_OAuth::class, 'query_vars' ) );
		if ( is_admin() ) {
			MCP_Admin::init();
		}
	}

	/**
	 * Activation: create all tables (gateway + OAuth) and register the
	 * well-known rewrite rules before flushing.
	 */
	public static function activate(): void {
		MCP_DB::activate();
		MCP_OAuth::activate();
		MCP_OAuth::add_rewrite_rules();
		flush_rewrite_rules();
		if ( ! wp_next_scheduled( 'wpmcp_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpmcp_daily_cleanup' );
		}
	}

	/**
	 * Upgrade routine: ZIP-upload replacements do not always re-run the
	 * activation hook, so new tables / rewrite rules are applied on the first
	 * request that notices a version change.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'wpmcp_version' ) === WPMCP_VERSION ) {
			return;
		}
		self::activate();
	}

	/**
	 * Deactivation hook. Data (keys, queue, logs) is intentionally kept so a
	 * re-activation picks up where it left off; uninstall removes it.
	 */
	public static function deactivate(): void {
		// Nothing to tear down: routes are registered at runtime only.
		wp_clear_scheduled_hook( 'wpmcp_daily_cleanup' );
	}
}
