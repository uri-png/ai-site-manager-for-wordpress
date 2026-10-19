<?php
/**
 * Admin dashboard: API key management, tool toggles, approval queue and the
 * audit log viewer (paginated, filterable).
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Site Manager settings screens.
 */
final class MCP_Admin {

	private const PAGE_SLUG = 'ai-site-manager';

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	public static function menu(): void {
		// Top-level sidebar entry; the five sections live as submenus.
		add_menu_page(
			__( 'AI Site Manager', 'ai-site-manager' ),
			__( 'AI Site Manager', 'ai-site-manager' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render' ),
			'dashicons-rest-api',
			80
		);

		foreach ( self::subpages() as $tab => $label ) {
			add_submenu_page( self::PAGE_SLUG, $label, $label, 'manage_options', self::page_slug( $tab ), array( self::class, 'render' ) );
		}
	}

	/**
	 * Tab slug => label map (single source for menu, routing and links).
	 *
	 * @return array<string,string>
	 */
	public static function subpages(): array {
		return array(
			'keys'      => __( 'API Keys', 'ai-site-manager' ),
			'tools'     => __( 'Tools', 'ai-site-manager' ),
			'approvals' => __( 'Approvals', 'ai-site-manager' ),
			'clients'   => __( 'OAuth Clients', 'ai-site-manager' ),
			'logs'      => __( 'Audit Log', 'ai-site-manager' ),
			'settings'  => __( 'Settings', 'ai-site-manager' ),
		);
	}

	/**
	 * The WP page slug for a tab. The first tab reuses the parent slug.
	 */
	public static function page_slug( string $tab ): string {
		return 'keys' === $tab ? self::PAGE_SLUG : self::PAGE_SLUG . '-' . $tab;
	}

	/**
	 * Full wp-admin URL for a tab. Every menu/tab/form link must come from
	 * here so URLs always go through admin.php?page=...
	 */
	public static function tab_url( string $tab ): string {
		return admin_url( 'admin.php?page=' . self::page_slug( $tab ) );
	}

	/**
	 * Resolve the current tab from ?page= / ?tab=.
	 */
	public static function resolve_tab(): string {
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : self::PAGE_SLUG; // phpcs:ignore WordPress.Security.NonceVerification
		if ( str_starts_with( $page, self::PAGE_SLUG . '-' ) ) {
			$tab = substr( $page, strlen( self::PAGE_SLUG ) + 1 );
		} elseif ( isset( $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$tab = sanitize_key( (string) $_GET['tab'] ); // phpcs:ignore WordPress.Security.NonceVerification
		} else {
			$tab = 'keys';
		}
		return array_key_exists( $tab, self::subpages() ) ? $tab : 'keys';
	}

	public static function assets( string $hook ): void {
		if ( ! str_contains( $hook, self::PAGE_SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'wpmcp-admin', WPMCP_PLUGIN_URL . 'assets/admin.css', array(), WPMCP_VERSION );
	}

	/**
	 * Handle POST actions (create/revoke key, toggle tool, approve/deny, settings).
	 * Every action is nonce- and capability-checked.
	 */
	public static function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['wpmcp_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['wpmcp_action'] ) );
		check_admin_referer( 'wpmcp_' . $action );

		switch ( $action ) {
			case 'create_key':
				$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
				$label   = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '';
				$result  = MCP_Keys::create( $user_id, $label );
				if ( is_wp_error( $result ) ) {
					self::redirect( array( 'wpmcp_error' => $result->get_error_message() ) );
				}
				// Shown once; only the hash is stored.
				self::redirect(
					array(
						'wpmcp_new_key' => rawurlencode( $result['key'] ),
						'tab'           => 'keys',
					)
				);
				break;

			case 'revoke_key':
				MCP_Keys::revoke( isset( $_POST['key_id'] ) ? (int) $_POST['key_id'] : 0 );
				self::redirect( array( 'tab' => 'keys', 'wpmcp_notice' => 'key_revoked' ) );
				break;

			case 'toggle_tool':
				$tool = isset( $_POST['tool'] ) ? sanitize_key( (string) $_POST['tool'] ) : '';
				if ( $tool && MCP_Registry::get( $tool ) ) {
					MCP_Registry::set_enabled( $tool, ! empty( $_POST['enabled'] ) );
				}
				self::redirect( array( 'tab' => 'tools' ) );
				break;

			case 'toggle_category':
				$category = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['category'] ) ) : '';
				$enabled  = ! empty( $_POST['enabled'] );
				foreach ( MCP_Registry::all() as $name => $tool ) {
					if ( $tool->category === $category ) {
						MCP_Registry::set_enabled( $name, $enabled );
					}
				}
				self::redirect( array( 'tab' => 'tools' ) );
				break;

			case 'approve':
				MCP_Executor::approve( isset( $_POST['queue_id'] ) ? (int) $_POST['queue_id'] : 0, get_current_user_id() );
				self::redirect( array( 'tab' => 'approvals', 'wpmcp_notice' => 'decided' ) );
				break;

			case 'deny':
				MCP_Executor::deny( isset( $_POST['queue_id'] ) ? (int) $_POST['queue_id'] : 0, get_current_user_id() );
				self::redirect( array( 'tab' => 'approvals', 'wpmcp_notice' => 'decided' ) );
				break;

			case 'revoke_client':
				$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['client_id'] ) ) : '';
				if ( '' !== $client_id ) {
					MCP_OAuth::revoke_client( $client_id );
				}
				self::redirect( array( 'tab' => 'clients', 'wpmcp_notice' => 'client_revoked' ) );
				break;

			case 'save_settings':
				update_option( 'wpmcp_enabled', ! empty( $_POST['wpmcp_enabled'] ) ? 1 : 0, false );
				update_option( 'wpmcp_require_approval', ! empty( $_POST['wpmcp_require_approval'] ) ? 1 : 0, false );
				self::redirect( array( 'tab' => 'settings', 'wpmcp_notice' => 'saved' ) );
				break;
		}
	}

	private static function redirect( array $args ): void {
		$tab = isset( $args['tab'] ) ? (string) $args['tab'] : 'keys';
		unset( $args['tab'] );
		wp_safe_redirect( add_query_arg( $args, self::tab_url( $tab ) ) );
		exit;
	}

	/**
	 * Render the dashboard with tabs.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'ai-site-manager' ) );
		}

		$tab = self::resolve_tab();

		$new_key = isset( $_GET['wpmcp_new_key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['wpmcp_new_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- One-time notice value set by our own redirect.
		$error   = isset( $_GET['wpmcp_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['wpmcp_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- One-time notice value set by our own redirect.

		echo '<div class="wrap wpmcp">';
		echo '<h1>' . esc_html__( 'AI Site Manager MCP', 'ai-site-manager' ) . ' <span class="wpmcp-version">v' . esc_html( WPMCP_VERSION ) . '</span></h1>';

		if ( ! is_ssl() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Warning: this site is not serving HTTPS. Bearer tokens can be intercepted on plain HTTP - enable SSL before handing out API keys.', 'ai-site-manager' ) . '</p></div>';
		}
		if ( $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( isset( $_GET['wpmcp_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag from our own redirect.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Done.', 'ai-site-manager' ) . '</p></div>';
		}
		if ( $new_key ) {
			echo '<div class="notice notice-success wpmcp-new-key"><p><strong>' . esc_html__( 'New API key (shown once - copy it now):', 'ai-site-manager' ) . '</strong></p><p><code>' . esc_html( $new_key ) . '</code></p></div>';
		}

		echo '<p class="wpmcp-endpoint">' . esc_html__( 'MCP endpoint (JSON-RPC 2.0, POST):', 'ai-site-manager' ) . ' <code>' . esc_html( rest_url( 'wp-mcp/v1/mcp' ) ) . '</code> &nbsp;·&nbsp; ' . esc_html__( 'REST:', 'ai-site-manager' ) . ' <code>' . esc_html( rest_url( 'wp-mcp/v1/tools/list' ) ) . '</code> / <code>' . esc_html( rest_url( 'wp-mcp/v1/tools/execute' ) ) . '</code></p>';

		self::tabs( $tab );

		switch ( $tab ) {
			case 'tools':
				self::render_tools();
				break;
			case 'approvals':
				self::render_approvals();
				break;
			case 'clients':
				self::render_clients();
				break;
			case 'logs':
				self::render_logs();
				break;
			case 'settings':
				self::render_settings();
				break;
			default:
				self::render_keys();
		}

		echo '</div>';
	}

	private static function tabs( string $current ): void {
		$tabs = array(
			'keys'      => __( 'API Keys', 'ai-site-manager' ),
			'tools'     => __( 'Tools', 'ai-site-manager' ),
			'approvals' => sprintf(
				/* translators: %d: number of pending approval requests. */
				__( 'Approvals (%d)', 'ai-site-manager' ),
				count( MCP_Executor::pending() )
			),
			'clients'   => __( 'OAuth Clients', 'ai-site-manager' ),
			'logs'      => __( 'Audit Log', 'ai-site-manager' ),
			'settings'  => __( 'Settings', 'ai-site-manager' ),
		);
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::tab_url( $slug ) ), $current === $slug ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</h2>';
	}

	/* ---------------------------------------------------------------------
	 * Tab: API keys
	 * ------------------------------------------------------------------- */

	private static function render_keys(): void {
		echo '<h3>' . esc_html__( 'Create a scoped API key', 'ai-site-manager' ) . '</h3>';
		echo '<p>' . esc_html__( 'Every key is bound to a WordPress user and can only do what that user is allowed to do. Bind keys to a dedicated low-privilege user for production agents.', 'ai-site-manager' ) . '</p>';
		echo '<form method="post"><input type="hidden" name="wpmcp_action" value="create_key" />';
		wp_nonce_field( 'wpmcp_create_key' );
		echo '<table class="form-table"><tr><th><label for="wpmcp-label">' . esc_html__( 'Label', 'ai-site-manager' ) . '</label></th><td><input id="wpmcp-label" name="label" type="text" class="regular-text" placeholder="n8n production" /></td></tr>';
		echo '<tr><th><label for="wpmcp-user">' . esc_html__( 'Bound WordPress user', 'ai-site-manager' ) . '</label></th><td>';
		wp_dropdown_users( array( 'name' => 'user_id', 'id' => 'wpmcp-user', 'show_option_none' => __( 'Select a user', 'ai-site-manager' ) ) );
		echo '</td></tr></table>';
		submit_button( __( 'Create key', 'ai-site-manager' ) );
		echo '</form>';

		$keys = MCP_Keys::all();
		echo '<h3>' . esc_html__( 'Existing keys', 'ai-site-manager' ) . '</h3>';
		if ( ! $keys ) {
			echo '<p>' . esc_html__( 'No keys yet.', 'ai-site-manager' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Label', 'ai-site-manager' ) . '</th><th>' . esc_html__( 'Key prefix', 'ai-site-manager' ) . '</th><th>' . esc_html__( 'User', 'ai-site-manager' ) . '</th><th>' . esc_html__( 'Created', 'ai-site-manager' ) . '</th><th>' . esc_html__( 'Last used', 'ai-site-manager' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $keys as $key ) {
			$user = get_userdata( (int) $key->user_id );
			echo '<tr' . ( $key->revoked ? ' class="wpmcp-revoked"' : '' ) . '><td>' . (int) $key->id . '</td><td>' . esc_html( $key->label ) . '</td><td><code>' . esc_html( $key->key_prefix ) . '…</code></td><td>' . esc_html( $user ? $user->user_login : '#' . (int) $key->user_id ) . '</td><td>' . esc_html( $key->created_at ) . '</td><td>' . esc_html( $key->last_used_at ?: '—' ) . '</td><td>';
			if ( ! $key->revoked ) {
				echo '<form method="post" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( 'Revoke this key? Connected agents will stop working.', 'ai-site-manager' ) ) . '\')"><input type="hidden" name="wpmcp_action" value="revoke_key" /><input type="hidden" name="key_id" value="' . (int) $key->id . '" />';
				wp_nonce_field( 'wpmcp_revoke_key' );
				echo '<button class="button button-small">' . esc_html__( 'Revoke', 'ai-site-manager' ) . '</button></form>';
			} else {
				esc_html_e( 'Revoked', 'ai-site-manager' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ---------------------------------------------------------------------
	 * Tab: Tools
	 * ------------------------------------------------------------------- */

	private static function render_tools(): void {
		echo '<p>' . esc_html__( 'Tools are discovered from WordPress core, public custom post types, and WooCommerce when active. Destructive tools are OFF by default; when the approval setting is on, running one queues it for admin approval instead of executing.', 'ai-site-manager' ) . '</p>';

		foreach ( MCP_Registry::grouped() as $category => $tools ) {
			$all_on = count( array_filter( array_keys( $tools ), array( MCP_Registry::class, 'is_enabled' ) ) ) === count( $tools );
			echo '<h3>' . esc_html( $category ) . ' <span class="wpmcp-count">(' . count( $tools ) . ')</span></h3>';
			echo '<form method="post" style="margin-bottom:8px"><input type="hidden" name="wpmcp_action" value="toggle_category" /><input type="hidden" name="category" value="' . esc_attr( $category ) . '" /><input type="hidden" name="enabled" value="' . ( $all_on ? '0' : '1' ) . '" />';
			wp_nonce_field( 'wpmcp_toggle_category' );
			echo '<button class="button button-small">' . ( $all_on ? esc_html__( 'Disable all in category', 'ai-site-manager' ) : esc_html__( 'Enable all in category', 'ai-site-manager' ) ) . '</button></form>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Tool', 'ai-site-manager' ) . '</th><th>' . esc_html__( 'Description', 'ai-site-manager' ) . '</th><th>' . esc_html__( 'Risk', 'ai-site-manager' ) . '</th><th>' . esc_html__( 'Enabled', 'ai-site-manager' ) . '</th></tr></thead><tbody>';
			foreach ( $tools as $name => $tool ) {
				$enabled = MCP_Registry::is_enabled( $name );
				echo '<tr><td><code>' . esc_html( $name ) . '</code></td><td>' . esc_html( $tool->description ) . '</td><td>' . ( $tool->destructive ? '<span class="wpmcp-destructive">' . esc_html__( 'destructive', 'ai-site-manager' ) . '</span>' : esc_html__( 'standard', 'ai-site-manager' ) ) . '</td><td>';
				echo '<form method="post"><input type="hidden" name="wpmcp_action" value="toggle_tool" /><input type="hidden" name="tool" value="' . esc_attr( $name ) . '" /><input type="hidden" name="enabled" value="' . ( $enabled ? '0' : '1' ) . '" />';
				wp_nonce_field( 'wpmcp_toggle_tool' );
				echo '<button class="button button-small ' . ( $enabled ? 'button-primary' : '' ) . '">' . ( $enabled ? esc_html__( 'On', 'ai-site-manager' ) : esc_html__( 'Off', 'ai-site-manager' ) ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	/* ---------------------------------------------------------------------
	 * Tab: Approvals
	 * ------------------------------------------------------------------- */

	private static function render_approvals(): void {
		$pending = MCP_Executor::pending();
		if ( ! $pending ) {
			echo '<p>' . esc_html__( 'Nothing is waiting for approval.', 'ai-site-manager' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Tool', 'ai-site-manager' ) . '</th><th>' . esc_html__( 'Arguments', 'ai-site-manager' ) . '</th><th>' . esc_html__( 'Requested by user', 'ai-site-manager' ) . '</th><th>' . esc_html__( 'Requested at (UTC)', 'ai-site-manager' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $pending as $row ) {
			$user = get_userdata( (int) $row->user_id );
			echo '<tr><td>' . (int) $row->id . '</td><td><code>' . esc_html( $row->tool_name ) . '</code></td><td><code class="wpmcp-args">' . esc_html( (string) $row->arguments ) . '</code></td><td>' . esc_html( $user ? $user->user_login : '#' . (int) $row->user_id ) . '</td><td>' . esc_html( $row->created_at ) . '</td><td>';
			foreach ( array( 'approve' => __( 'Approve & run', 'ai-site-manager' ), 'deny' => __( 'Deny', 'ai-site-manager' ) ) as $action => $label ) {
				echo '<form method="post" style="display:inline"><input type="hidden" name="wpmcp_action" value="' . esc_attr( $action ) . '" /><input type="hidden" name="queue_id" value="' . (int) $row->id . '" />';
				wp_nonce_field( 'wpmcp_' . $action );
				echo '<button class="button button-small' . ( 'approve' === $action ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form> ';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ---------------------------------------------------------------------
	 * Tab: Audit log
	 * ------------------------------------------------------------------- */

	/* ---------------------------------------------------------------------
	 * Tab: OAuth clients
	 * ------------------------------------------------------------------- */

	private static function render_clients(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; no user input in the query.
		$clients = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'mcp_oauth_clients ORDER BY created_at DESC LIMIT 100' );

		echo '<p>' . esc_html__( 'Applications that registered themselves through the OAuth discovery flow (e.g. Claude). Revoking deletes the client and all of its tokens immediately.', 'ai-site-manager' ) . '</p>';
		if ( ! $clients ) {
			echo '<p><em>' . esc_html__( 'No OAuth clients registered yet.', 'ai-site-manager' ) . '</em></p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'ai-site-manager' ) . '</th><th>' . esc_html__( 'Client ID', 'ai-site-manager' ) . '</th><th>' . esc_html__( 'Redirect URIs', 'ai-site-manager' ) . '</th><th>' . esc_html__( 'Registered (UTC)', 'ai-site-manager' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $clients as $client ) {
			$uris = implode( '<br />', array_map( 'esc_html', json_decode( (string) $client->redirect_uris, true ) ?: array() ) );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $uris is built from esc_html()'d parts above.
			echo '<tr><td><strong>' . esc_html( (string) $client->client_name ) . '</strong></td><td><code>' . esc_html( (string) $client->client_id ) . '</code></td><td>' . $uris . '</td><td>' . esc_html( (string) $client->created_at ) . '</td><td>';
			echo '<form method="post" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( 'Revoke this client? Its access stops immediately.', 'ai-site-manager' ) ) . '\')"><input type="hidden" name="wpmcp_action" value="revoke_client" /><input type="hidden" name="client_id" value="' . esc_attr( (string) $client->client_id ) . '" />';
			wp_nonce_field( 'wpmcp_revoke_client' );
			submit_button( __( 'Revoke', 'ai-site-manager' ), 'delete small', 'submit', false );
			echo '</form></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_logs(): void {
		// Read-only log filters; no state changes, so no nonce needed.
		$status = isset( $_GET['log_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['log_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tool   = isset( $_GET['log_tool'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['log_tool'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['log_search'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['log_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per    = 20;

		$result = MCP_Logger::query( array_filter( array( 'status' => $status, 'tool_name' => $tool, 'search' => $search ) ), $paged, $per );
		$pages  = max( 1, (int) ceil( $result['total'] / $per ) );

		$base = self::tab_url( 'logs' );

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="wpmcp-log-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::page_slug( 'logs' ) ) . '" />';
		echo '<select name="log_status"><option value="">' . esc_html__( 'All statuses', 'ai-site-manager' ) . '</option>';
		foreach ( array( MCP_Logger::STATUS_SUCCESS, MCP_Logger::STATUS_FAILED, MCP_Logger::STATUS_PENDING ) as $s ) {
			echo '<option value="' . esc_attr( $s ) . '"' . selected( $status, $s, false ) . '>' . esc_html( ucfirst( $s ) ) . '</option>';
		}
		echo '</select> ';
		echo '<select name="log_tool"><option value="">' . esc_html__( 'All tools', 'ai-site-manager' ) . '</option>';
		foreach ( MCP_Logger::tool_names() as $name ) {
			echo '<option value="' . esc_attr( $name ) . '"' . selected( $tool, $name, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select> ';
		echo '<input type="search" name="log_search" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search…', 'ai-site-manager' ) . '" /> ';
		submit_button( __( 'Filter', 'ai-site-manager' ), 'secondary', 'filter', false );
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Time (UTC)', 'ai-site-manager' ) . '</th><th>' . esc_html__( 'Key', 'ai-site-manager' ) . '</th><th>' . esc_html__( 'Tool', 'ai-site-manager' ) . '</th><th>' . esc_html__( 'Status', 'ai-site-manager' ) . '</th><th>' . esc_html__( 'Payload / message', 'ai-site-manager' ) . '</th></tr></thead><tbody>';
		foreach ( $result['rows'] as $row ) {
			echo '<tr><td>' . (int) $row->id . '</td><td>' . esc_html( $row->created_at ) . '</td><td><code>' . esc_html( $row->key_prefix ) . '…</code></td><td><code>' . esc_html( $row->tool_name ) . '</code></td><td><span class="wpmcp-status wpmcp-status-' . esc_attr( $row->status ) . '">' . esc_html( $row->status ) . '</span></td>';
			echo '<td><details><summary>' . esc_html( mb_strimwidth( (string) $row->message, 0, 120, '…' ) ) . '</summary><code class="wpmcp-args">' . esc_html( (string) $row->arguments ) . '</code></details></td></tr>';
		}
		if ( ! $result['rows'] ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No log entries yet.', 'ai-site-manager' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			for ( $p = 1; $p <= $pages; $p++ ) {
				$url = add_query_arg( array( 'paged' => $p, 'log_status' => $status, 'log_tool' => $tool, 'log_search' => $search ), $base );
				echo $p === $paged ? ' <strong>' . (int) $p . '</strong> ' : ' <a class="button button-small" href="' . esc_url( $url ) . '">' . (int) $p . '</a> ';
			}
			echo '</div></div>';
		}
	}

	/* ---------------------------------------------------------------------
	 * Tab: Settings
	 * ------------------------------------------------------------------- */

	private static function render_settings(): void {
		echo '<form method="post"><input type="hidden" name="wpmcp_action" value="save_settings" />';
		wp_nonce_field( 'wpmcp_save_settings' );
		echo '<table class="form-table">';
		echo '<tr><th scope="row">' . esc_html__( 'MCP server enabled', 'ai-site-manager' ) . '</th><td><label><input type="checkbox" name="wpmcp_enabled" value="1" ' . checked( 1, (int) get_option( 'wpmcp_enabled', 1 ), false ) . ' /> ' . esc_html__( 'Accept API requests', 'ai-site-manager' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Human-in-the-loop', 'ai-site-manager' ) . '</th><td><label><input type="checkbox" name="wpmcp_require_approval" value="1" ' . checked( 1, (int) get_option( 'wpmcp_require_approval', 1 ), false ) . ' /> ' . esc_html__( 'Queue destructive tools for admin approval instead of executing immediately', 'ai-site-manager' ) . '</label></td></tr>';
		echo '</table>';
		submit_button();
		echo '</form>';
	}
}

