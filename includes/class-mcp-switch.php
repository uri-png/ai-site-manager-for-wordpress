<?php
/**
 * One-click switch from AI Site Manager to FocusWeb MCP, its successor on
 * WordPress.org.
 *
 * Nothing happens on its own. An administrator clicks "Switch to FocusWeb
 * MCP", and then this plugin:
 *   1. installs FocusWeb MCP from WordPress.org, if it is not installed yet,
 *   2. activates it,
 *   3. deactivates AI Site Manager.
 * On the next page load, FocusWeb MCP copies this plugin's keys, OAuth
 * clients and tokens, settings, queue, log and Elementor backups, and keeps
 * answering the old /wp-json/wp-mcp/v1 address, so connected agents keep
 * working. AI Site Manager's own data is left untouched, so going back means
 * re-activating it.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Switch to FocusWeb MCP.
 */
final class MCP_Switch {

	public const SLUG     = 'focusweb-mcp';
	public const BASENAME = 'focusweb-mcp/focusweb-mcp.php';
	private const ACTION  = 'wpmcp_switch';
	private const CACHE   = 'wpmcp_successor_package';

	public static function init(): void {
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * 'active' | 'installed' | 'missing'.
	 */
	public static function state(): string {
		if ( defined( 'FOCUMCP_VERSION' ) ) {
			return 'active';
		}
		return file_exists( WP_PLUGIN_DIR . '/' . self::BASENAME ) ? 'installed' : 'missing';
	}

	/**
	 * Where FocusWeb MCP can be installed from: the WordPress.org download link,
	 * or a ZIP URL/path given through the 'wpmcp_focusweb_mcp_package' filter
	 * (for testing before the WordPress.org listing exists). '' when unknown.
	 *
	 * @param bool $lookup Ask WordPress.org when nothing is cached (admin screens of this plugin only).
	 */
	public static function package( bool $lookup = false ): string {
		$override = (string) apply_filters( 'wpmcp_focusweb_mcp_package', '' );
		if ( '' !== $override ) {
			return $override;
		}
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return (string) ( $cached['url'] ?? '' );
		}
		if ( ! $lookup ) {
			return '';
		}
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		$info = plugins_api( 'plugin_information', array( 'slug' => self::SLUG, 'fields' => array( 'sections' => false ) ) );
		$url  = ( ! is_wp_error( $info ) && is_object( $info ) && ! empty( $info->download_link ) ) ? (string) $info->download_link : '';
		set_transient( self::CACHE, array( 'url' => $url ), $url ? DAY_IN_SECONDS : 6 * HOUR_IN_SECONDS );
		return $url;
	}

	/**
	 * Whether the switch can be offered right now.
	 */
	public static function can_offer( bool $lookup = false ): bool {
		if ( is_multisite() || ! current_user_can( 'activate_plugins' ) ) {
			return false;
		}
		if ( 'missing' !== self::state() ) {
			return true;
		}
		return current_user_can( 'install_plugins' ) && wp_is_file_mod_allowed( self::ACTION ) && '' !== self::package( $lookup );
	}

	private static function button( string $label ): string {
		$confirm = __( 'Switch to FocusWeb MCP now? Your API keys, connected AI agents, settings and logs are carried over and existing connections keep working. AI Site Manager will be deactivated (its data is kept).', 'ai-site-manager' );
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js( $confirm ) . '\');">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />'
			. wp_nonce_field( self::ACTION, '_wpnonce', true, false )
			. '<button type="submit" class="button button-primary">' . esc_html( $label ) . '</button></form>';
	}

	private static function label(): string {
		switch ( self::state() ) {
			case 'active':
				return __( 'Finish the switch', 'ai-site-manager' );
			case 'installed':
				return __( 'Activate FocusWeb MCP and switch', 'ai-site-manager' );
			default:
				return __( 'Install FocusWeb MCP and switch', 'ai-site-manager' );
		}
	}

	/**
	 * Admin notice on this plugin's pages, the Plugins screen and the Dashboard.
	 * The WordPress.org lookup only runs on this plugin's own pages.
	 */
	public static function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		$own    = str_contains( $id, 'ai-site-manager' );
		if ( ! $own && ! in_array( $id, array( 'plugins', 'dashboard' ), true ) ) {
			return;
		}
		if ( 'active' === self::state() ) {
			return; // FocusWeb MCP shows its own "deactivate AI Site Manager" notice; the box on Settings & Status has the button.
		}
		if ( ! self::can_offer( $own ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'AI Site Manager is now FocusWeb MCP.', 'ai-site-manager' ) . '</strong> '
			. esc_html__( 'Future updates come from WordPress.org under the new name. Switching takes one click: API keys, connected AI agents, settings and logs are carried over, and existing connections keep working.', 'ai-site-manager' )
			. '</p><p>' . self::button( self::label() ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- button() escapes every part.
	}

	/**
	 * Box at the top of Settings & Status.
	 */
	public static function render_box(): void {
		if ( is_multisite() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'FocusWeb MCP is the successor of this plugin. On multisite, install and network-activate FocusWeb MCP from Network Admin, then deactivate AI Site Manager.', 'ai-site-manager' ) . '</p></div>';
			return;
		}
		echo '<div class="notice notice-info inline" style="padding:12px 14px"><h3 style="margin:0 0 6px">' . esc_html__( 'Move to FocusWeb MCP', 'ai-site-manager' ) . '</h3><p>'
			. esc_html__( 'FocusWeb MCP is the successor of AI Site Manager, published on WordPress.org. It has the same features; updates come from WordPress.org. Switching carries over API keys, connected AI agents (OAuth), settings, the approval queue, the audit log and Elementor backups, and the current connector address keeps working, so nothing has to be reconnected.', 'ai-site-manager' )
			. '</p>';
		if ( self::can_offer( true ) ) {
			echo '<p>' . self::button( self::label() ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- button() escapes every part.
		} elseif ( 'missing' === self::state() && '' === self::package() ) {
			echo '<p><em>' . esc_html__( 'FocusWeb MCP is not available on WordPress.org yet. This box will offer the switch as soon as it is.', 'ai-site-manager' ) . '</em></p>';
		} else {
			echo '<p><em>' . esc_html__( 'Your account cannot install or activate plugins on this site. Ask an administrator to make the switch.', 'ai-site-manager' ) . '</em></p>';
		}
		echo '</div>';
	}

	/**
	 * admin-post handler.
	 */
	public static function handle(): void {
		check_admin_referer( self::ACTION );
		if ( is_multisite() || ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to switch plugins on this site.', 'ai-site-manager' ), 403 );
		}
		$result = self::run();
		if ( is_wp_error( $result ) ) {
			MCP_Logger::log( array( 'tool_name' => 'switch_to_focusweb_mcp', 'user_id' => get_current_user_id(), 'status' => MCP_Logger::STATUS_FAILED, 'message' => $result->get_error_message() ) );
			wp_safe_redirect( add_query_arg( 'wpmcp_error', rawurlencode( $result->get_error_message() ), MCP_Admin::tab_url( 'settings' ) ) );
			exit;
		}
		MCP_Logger::log( array( 'tool_name' => 'switch_to_focusweb_mcp', 'user_id' => get_current_user_id(), 'status' => MCP_Logger::STATUS_SUCCESS, 'message' => 'Switched to FocusWeb MCP; AI Site Manager deactivated (data kept).' ) );
		// The next request runs without AI Site Manager, so FocusWeb MCP imports the data there.
		wp_safe_redirect( admin_url( 'admin.php?page=focusweb-mcp' ) );
		exit;
	}

	/**
	 * Install (if needed), activate FocusWeb MCP, deactivate this plugin.
	 * This plugin is only deactivated once FocusWeb MCP is active.
	 *
	 * @return true|WP_Error
	 */
	public static function run() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( 'missing' === self::state() ) {
			$installed = self::install();
			if ( is_wp_error( $installed ) ) {
				return $installed;
			}
		}

		if ( ! is_plugin_active( self::BASENAME ) ) {
			$activated = activate_plugin( self::BASENAME );
			if ( is_wp_error( $activated ) ) {
				return new WP_Error( 'wpmcp_switch', sprintf( 'FocusWeb MCP could not be activated: %s AI Site Manager stays active.', $activated->get_error_message() ) );
			}
		}
		if ( ! is_plugin_active( self::BASENAME ) ) {
			return new WP_Error( 'wpmcp_switch', 'FocusWeb MCP did not activate. AI Site Manager stays active.' );
		}

		deactivate_plugins( plugin_basename( WPMCP_PLUGIN_FILE ), true );
		delete_transient( self::CACHE );
		return true;
	}

	/**
	 * Install FocusWeb MCP with the core upgrader, accepting only a package
	 * whose top folder is focusweb-mcp and whose main file says FocusWeb MCP.
	 *
	 * @return true|WP_Error
	 */
	private static function install() {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return new WP_Error( 'wpmcp_switch', 'Your account cannot install plugins. Ask an administrator to make the switch.' );
		}
		if ( ! wp_is_file_mod_allowed( self::ACTION ) ) {
			return new WP_Error( 'wpmcp_switch', 'File changes are disabled on this site (DISALLOW_FILE_MODS). Install FocusWeb MCP manually, then click again.' );
		}
		$package = self::package( true );
		if ( '' === $package ) {
			return new WP_Error( 'wpmcp_switch', 'FocusWeb MCP is not available on WordPress.org yet.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$check = static function ( $source ) {
			if ( is_wp_error( $source ) ) {
				return $source;
			}
			$main = trailingslashit( $source ) . 'focusweb-mcp.php';
			$data = is_readable( $main ) ? get_file_data( $main, array( 'Name' => 'Plugin Name' ) ) : array( 'Name' => '' );
			if ( 'focusweb-mcp' !== basename( untrailingslashit( $source ) ) || 'FocusWeb MCP' !== $data['Name'] ) {
				return new WP_Error( 'wpmcp_switch', 'The downloaded package is not FocusWeb MCP. Nothing was installed.' );
			}
			return $source;
		};
		add_filter( 'upgrader_source_selection', $check, 20 );
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $package );
		remove_filter( 'upgrader_source_selection', $check, 20 );
		wp_clean_plugins_cache( true );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'wpmcp_switch', 'Installing FocusWeb MCP failed: ' . $result->get_error_message() );
		}
		if ( is_wp_error( $skin->result ) ) {
			return new WP_Error( 'wpmcp_switch', 'Installing FocusWeb MCP failed: ' . $skin->result->get_error_message() );
		}
		if ( true !== $result || ! file_exists( WP_PLUGIN_DIR . '/' . self::BASENAME ) ) {
			$messages = $skin->get_upgrade_messages();
			return new WP_Error( 'wpmcp_switch', 'Installing FocusWeb MCP did not complete.' . ( $messages ? ' ' . wp_strip_all_tags( (string) end( $messages ) ) : '' ) );
		}
		return true;
	}
}
