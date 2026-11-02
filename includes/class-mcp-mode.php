<?php
/**
 * Access mode: "Monitor & Guide" (read-only, default) versus "Full access"
 * (agents may change the site). The mode is chosen by a site administrator
 * only; no MCP tool or REST route can change it.
 *
 * Enforcement lives in MCP_Executor (execute, approve and run), so every
 * path that can change data - MCP tools/call, REST /tools/execute and the
 * approval queue - is blocked, not just hidden in the UI.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site-wide access mode.
 */
final class MCP_Mode {

	public const OPTION  = 'wpmcp_access_mode';
	public const META    = 'wpmcp_access_mode_meta';
	public const MONITOR = 'monitor';

	/**
	 * Third-party abilities whose code was reviewed from the owning
	 * plugin's official wordpress.org release and found to only read.
	 * Each entry is pinned to the exact reviewed plugin version AND the
	 * SHA-256 of the reviewed source files, so a different version, a
	 * modified file or a missing file fails closed. A new version needs a
	 * new review and a new entry here.
	 *
	 * ability id => [ version constant, directory constant, version => [ file => sha256 ] ]
	 */
	public const VETTED_SOURCES = array(
		'elementor/list-pages'         => array(
			'ELEMENTOR_VERSION',
			'ELEMENTOR_PATH',
			array(
				'4.2.3' => array(
					'modules/mcp/abilities/abstract-ability.php'   => '2cef4a9066a09bd609c5bed5b3f0cdc2c9d27e2cb2f54b5bdf3163c1ab71c56f',
					'modules/mcp/abilities/list-pages-ability.php' => 'b90d485824dc8f1ac46856e9097f80277404e56cc05b3b26e02c2147694aa53f',
				),
			),
		),
		'elementor/get-page-structure' => array(
			'ELEMENTOR_VERSION',
			'ELEMENTOR_PATH',
			array(
				'4.2.3' => array(
					'modules/mcp/abilities/abstract-ability.php'      => '2cef4a9066a09bd609c5bed5b3f0cdc2c9d27e2cb2f54b5bdf3163c1ab71c56f',
					'modules/mcp/abilities/get-structure-ability.php' => '506f8eaaba72c9f6055147b0953face01411adef59e5edfe4e13592f25a164e9',
				),
				'4.3.5' => array(
					'modules/mcp/abilities/abstract-ability.php' => 'f4b364dffec10b3765cba7ec616d8ce3356d75b641adec951b3f167780662680',
					'modules/mcp/abilities/get-structure-ability.php' => '5aaeb35cd3615a42d2f1c251be879bb8f24dd83f649b4549913b5b78610c4515',
					'modules/mcp/abilities/dynamic-tag-llm-resolver.php' => 'fe6791de85fa87368abf1e7a05f7f2cc16922af2b477f47a208edec6520e81aa',
					'modules/mcp/abilities/utils/widget-context-helper.php' => '4b8208a8c80c18a4c2f8021d8665b732ff4595ceb9eb7ad98d79619a656591f5',
					'modules/mcp/abilities/utils/element-default-styles-builder.php' => 'e1eb65c63063c947e65b0ea70d569733448fb2d3aba3d987df162d55071cdbf2',
					'modules/mcp/abilities/utils/element-tag-resolver.php' => 'c41c92484f883cd2b2e5f3a7933d6dab368488f1035de67a86384d9e8cd82900',
					'modules/mcp/abilities/utils/atomic-container-presentation.php' => 'eafcb68120482e34ec3c7906ce35cfa1d2a849e05552a0a45af688e1bd8f717b',
				),
			),
		),
		'elementor/list-posts'         => array(
			'ELEMENTOR_VERSION',
			'ELEMENTOR_PATH',
			array(
				'4.3.5' => array(
					'modules/mcp/abilities/abstract-ability.php' => 'f4b364dffec10b3765cba7ec616d8ce3356d75b641adec951b3f167780662680',
					'modules/mcp/abilities/list-posts-ability.php' => '55643a3e5792d8d17bd178b44175cf25d48c6a4da4cbe72d1cf1168b2ebc39b0',
					'modules/mcp/abilities/utils/prompt-loader.php' => 'cc36601264f2f722893b3ab6df89f3eefebb9842a8f2218c5d404133f51c2cf0',
				),
			),
		),
		'elementor/get-globals'        => array(
			'ELEMENTOR_VERSION',
			'ELEMENTOR_PATH',
			array(
				'4.2.3' => array(
					'modules/mcp/abilities/abstract-ability.php'     => '2cef4a9066a09bd609c5bed5b3f0cdc2c9d27e2cb2f54b5bdf3163c1ab71c56f',
					'modules/mcp/abilities/get-globals-ability.php'  => '76132fbbccbf08ccfe6eb8df923a3add83fa6478e41955812b25a3d9bdfcc321',
				),
			),
		),
		// Writes only one row to the plugin's own analytics log (event "ask"); no site content changes.
		'focusweb-ai-visibility/search-content' => array(
			'FOCUAIVI_VERSION',
			'FOCUAIVI_PLUGIN_DIR',
			array(
				'1.3.12' => array(
					'includes/class-aiv-rest.php'      => '3f2ea1a80284bdca898af95f59ce1d90661f289de42757544458386e97dec76c',
					'includes/class-aiv-agent-log.php' => '11e7213dc729f6691e1f1ebbe5c6b21238037ae5f21a298510c3d772a1c21a9a',
				),
				'1.3.11' => array(
					'includes/class-aiv-rest.php'      => '3f2ea1a80284bdca898af95f59ce1d90661f289de42757544458386e97dec76c',
					'includes/class-aiv-agent-log.php' => 'b214f9cc9f273091d4a9b41b6dadfa3a4730b391ad1537750f0892b962c4c223',
				),
			),
		),
	);

	/**
	 * Whether the installed plugin behind a reviewed ability is exactly the
	 * reviewed release.
	 */
	public static function vetted_ability_ok( string $ability_id ): bool {
		$entry = self::VETTED_SOURCES[ $ability_id ] ?? null;
		if ( ! $entry || ! defined( $entry[0] ) || ! defined( $entry[1] ) ) {
			return false;
		}
		return self::sources_match( $entry[2], (string) constant( $entry[0] ), (string) constant( $entry[1] ) );
	}

	/**
	 * @param array<string,array<string,string>> $versions Reviewed versions => file => sha256.
	 * @param string                             $version  Installed version.
	 * @param string                             $root     Installed plugin directory.
	 */
	public static function sources_match( array $versions, string $version, string $root ): bool {
		if ( ! isset( $versions[ $version ] ) ) {
			return false;
		}
		$root = rtrim( $root, '/\\' ) . '/';
		foreach ( $versions[ $version ] as $file => $hash ) {
			$path = $root . $file;
			if ( ! is_file( $path ) || ! hash_equals( $hash, (string) hash_file( 'sha256', $path ) ) ) {
				return false;
			}
		}
		return true;
	}

	public const FULL    = 'full';

	/**
	 * Current mode. Anything missing or unrecognised is Monitor (fail closed).
	 */
	public static function get(): string {
		return self::FULL === get_option( self::OPTION, self::MONITOR ) ? self::FULL : self::MONITOR;
	}

	public static function is_full(): bool {
		return self::FULL === self::get();
	}

	/**
	 * Whether an administrator has ever picked a mode (false = still on the default).
	 */
	public static function is_explicit(): bool {
		return false !== get_option( self::OPTION, false );
	}

	/**
	 * True when the current mode forbids running this tool.
	 */
	public static function blocks( MCP_Tool $tool ): bool {
		if ( self::is_full() ) {
			return false;
		}
		return ! $tool->is_read_only();
	}

	/**
	 * Persist a mode chosen by an administrator.
	 *
	 * @param string $mode    monitor|full.
	 * @param int    $user_id Administrator making the change.
	 */
	public static function set( string $mode, int $user_id ): bool {
		if ( self::MONITOR !== $mode && self::FULL !== $mode ) {
			return false;
		}
		$previous = self::get();
		update_option( self::OPTION, $mode, false );
		if ( $previous !== $mode ) {
			self::log_change( $previous, $mode, $user_id, MCP_Logger::STATUS_SUCCESS );
		}
		update_option(
			self::META,
			array(
				'by' => $user_id,
				'at' => gmdate( 'Y-m-d H:i:s' ),
			),
			false
		);
		return true;
	}

	/**
	 * Write an access-mode change (or a refused attempt) to the audit log.
	 *
	 * @param string $from    Previous mode.
	 * @param string $to      Requested mode.
	 * @param int    $user_id Administrator.
	 * @param string $status  Log status.
	 * @param string $note    Optional extra text.
	 */
	public static function log_change( string $from, string $to, int $user_id, string $status, string $note = '' ): void {
		if ( ! class_exists( 'MCP_Logger' ) ) {
			return;
		}
		$message = sprintf(
			/* translators: 1: previous mode label, 2: new mode label */
			__( 'Access mode changed: %1$s -> %2$s', 'ai-site-manager' ),
			self::label( $from ),
			self::label( $to )
		);
		if ( MCP_Logger::STATUS_SUCCESS !== $status ) {
			$message = sprintf(
				/* translators: 1: previous mode label, 2: requested mode label */
				__( 'Access mode change refused: %1$s -> %2$s', 'ai-site-manager' ),
				self::label( $from ),
				self::label( $to )
			);
		}
		if ( '' !== $note ) {
			$message .= ' (' . $note . ')';
		}
		MCP_Logger::log(
			array(
				'key_prefix' => 'admin',
				'user_id'    => $user_id,
				'tool_name'  => 'access_mode_change',
				'arguments'  => array( 'from' => $from, 'to' => $to ),
				'status'     => $status,
				'message'    => $message,
			)
		);
	}

	/**
	 * Short human label.
	 */
	public static function label( ?string $mode = null ): string {
		$mode = $mode ?? self::get();
		return self::FULL === $mode
			? __( 'Full access', 'ai-site-manager' )
			: __( 'Monitor & Guide (read-only)', 'ai-site-manager' );
	}

	/**
	 * One-sentence explanation for admins and consent screens.
	 */
	public static function summary(): string {
		return self::is_full()
			? __( 'Connected AI agents can change this site, within each key user\'s WordPress permissions. Destructive actions still need your approval.', 'ai-site-manager' )
			: __( 'Connected AI agents can read and advise only. Every action that would change the site is blocked.', 'ai-site-manager' );
	}

	/**
	 * Text for the AI agent (MCP instructions and error replies).
	 */
	public static function agent_notice(): string {
		return self::is_full()
			? 'Access mode: FULL ACCESS. You may make changes that the tools allow.'
			: 'Access mode: MONITOR & GUIDE (read-only). You can read, analyse and advise; every tool that changes the site is unavailable. Do not try to work around this. If a change is needed, describe it and tell the site owner they can switch to Full access in AI Site Manager > Settings & Status.';
	}

	/**
	 * Error returned when a change is attempted in Monitor mode.
	 */
	public static function blocked_message( string $tool_name ): string {
		return 'Blocked: this site is in Monitor & Guide (read-only) mode, so "' . $tool_name . '" cannot run. Describe the change to the site owner instead; they can switch to Full access in AI Site Manager > Settings & Status.';
	}

	/* ---------------------------------------------------------------------
	 * Persistent status indicator
	 * ------------------------------------------------------------------- */

	public static function init(): void {
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 100 );
		add_action( 'admin_notices', array( self::class, 'default_notice' ) );
	}

	/**
	 * Admin-bar badge, visible on every admin screen and on the front end
	 * for administrators.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 */
	public static function admin_bar( $bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$full  = self::is_full();
		$color = $full ? '#b32d2e' : '#00a32a';
		$bar->add_node(
			array(
				'id'    => 'wpmcp-mode',
				'title' => '<span style="background:' . $color . ';color:#fff;padding:2px 8px;border-radius:3px;font-weight:600;">AI: ' . esc_html( $full ? __( 'Full access', 'ai-site-manager' ) : __( 'Monitor only', 'ai-site-manager' ) ) . '</span>',
				'href'  => MCP_Admin::tab_url( 'settings' ),
				'meta'  => array( 'title' => self::summary() ),
			)
		);
	}

	/**
	 * Until an administrator picks a mode, say that the default is read-only.
	 */
	public static function default_notice(): void {
		if ( self::is_explicit() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p><strong>AI Site Manager:</strong> ' . esc_html__( 'connected AI agents are in Monitor & Guide (read-only) mode by default and cannot change this site.', 'ai-site-manager' ) . ' <a href="' . esc_url( MCP_Admin::tab_url( 'settings' ) ) . '">' . esc_html__( 'Choose an access mode', 'ai-site-manager' ) . '</a></p></div>';
	}

	/**
	 * Large status banner for the plugin's own pages.
	 */
	public static function render_banner(): void {
		$full = self::is_full();
		echo '<div class="wpmcp-mode-banner ' . ( $full ? 'wpmcp-mode-full' : 'wpmcp-mode-monitor' ) . '" role="status"><strong>' . esc_html( self::label() ) . '</strong> - ' . esc_html( self::summary() ) . ' <a href="' . esc_url( MCP_Admin::tab_url( 'settings' ) ) . '">' . esc_html__( 'Change', 'ai-site-manager' ) . '</a></div>';
	}

	/**
	 * Mode chooser (Settings tab).
	 */
	public static function render_chooser(): void {
		$full = self::is_full();
		$meta = get_option( self::META, array() );
		echo '<h3>' . esc_html__( 'Access mode', 'ai-site-manager' ) . '</h3>';
		echo '<form method="post"><input type="hidden" name="wpmcp_action" value="save_mode" />';
		wp_nonce_field( 'wpmcp_save_mode' );
		echo '<fieldset class="wpmcp-mode-choice">';
		echo '<label><input type="radio" name="wpmcp_access_mode" value="monitor" ' . checked( false, $full, false ) . ' /> <strong>' . esc_html__( 'Monitor & Guide (read-only) - default', 'ai-site-manager' ) . '</strong><br /><span class="description">' . esc_html__( 'The AI can read the site, analyse it and give advice. Nothing can be created, edited, uploaded or deleted.', 'ai-site-manager' ) . '</span></label><br /><br />';
		echo '<label><input type="radio" name="wpmcp_access_mode" value="full" ' . checked( true, $full, false ) . ' /> <strong>' . esc_html__( 'Full access', 'ai-site-manager' ) . '</strong><br /><span class="description">' . esc_html__( 'The AI can also make changes, limited by each key user\'s WordPress permissions and by the per-tool switches. Destructive tools stay off until you enable them and still wait for your approval.', 'ai-site-manager' ) . '</span></label>';
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'In Monitor mode only this plugin\'s own built-in read tools run. Tools bridged from other plugins are blocked, except a short list of read abilities whose exact plugin version and source files were reviewed (Elementor 4.2.3 list-pages, get-page-structure, get-globals, or 4.3.5 list-posts, get-page-structure; AI Visibility 1.3.11 and 1.3.12 search-content). Any other version or tool is blocked because its read-only label comes from the other plugin and cannot be verified.', 'ai-site-manager' ) . '</p>';
		echo '<p><label><input type="checkbox" name="wpmcp_confirm_full" value="1" /> ' . esc_html__( 'I understand that Full access lets connected AI agents change this site (required to switch to Full access).', 'ai-site-manager' ) . '</label></p>';
		submit_button( __( 'Save access mode', 'ai-site-manager' ), 'primary', 'submit', false );
		echo '</form>';
		if ( is_array( $meta ) && ! empty( $meta['at'] ) ) {
			$user = get_userdata( (int) ( $meta['by'] ?? 0 ) );
			echo '<p class="description">' . esc_html( sprintf( /* translators: 1: date, 2: user */ __( 'Last changed %1$s UTC by %2$s.', 'ai-site-manager' ), (string) $meta['at'], $user ? $user->user_login : '#' . (int) ( $meta['by'] ?? 0 ) ) ) . '</p>';
		}
	}
}
