<?php
/**
 * Dynamic action registry: discovers and registers tools from WordPress core,
 * public custom post types, and WooCommerce (when active). Tracks per-tool
 * ON/OFF overrides from the admin UI.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds every discovered tool and its enabled state.
 */
final class MCP_Registry {

	private const OPTION = 'wpmcp_tool_overrides';

	/** @var array<string,MCP_Tool>|null */
	private static ?array $tools = null;

	/**
	 * Build (once per request) the full discovered tool map.
	 *
	 * @return array<string,MCP_Tool>
	 */
	public static function all(): array {
		if ( null === self::$tools ) {
			self::$tools = MCP_Tools_Core::discover();

			/**
			 * Filter the discovered tool map. Plugins can register their own
			 * MCP_Tool instances or remove core ones.
			 *
			 * @param array<string,MCP_Tool> $tools Tool map keyed by tool name.
			 */
			self::$tools = apply_filters( 'wpmcp_tools', self::$tools );
		}
		return self::$tools;
	}

	/**
	 * One tool by name.
	 *
	 * @param string $name Tool name.
	 */
	public static function get( string $name ): ?MCP_Tool {
		$tools = self::all();
		return $tools[ $name ] ?? null;
	}

	/**
	 * Whether a tool may be executed/listed. Non-destructive tools default ON;
	 * destructive tools default OFF until an admin opts in.
	 *
	 * @param string $name Tool name.
	 */
	public static function is_enabled( string $name ): bool {
		$tool = self::get( $name );
		if ( ! $tool ) {
			return false;
		}
		$overrides = self::overrides();
		if ( isset( $overrides[ $name ] ) ) {
			return 'on' === $overrides[ $name ];
		}
		return ! $tool->destructive;
	}

	/**
	 * All enabled tools.
	 *
	 * @return array<string,MCP_Tool>
	 */
	public static function enabled(): array {
		return array_filter( self::all(), fn( string $name ) => self::is_enabled( $name ), ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Persist an ON/OFF override for one tool.
	 *
	 * @param string $name    Tool name.
	 * @param bool   $enabled Desired state.
	 */
	public static function set_enabled( string $name, bool $enabled ): void {
		$overrides          = self::overrides();
		$overrides[ $name ] = $enabled ? 'on' : 'off';
		update_option( self::OPTION, $overrides, false );
	}

	/**
	 * Tools grouped by category for the admin UI.
	 *
	 * @return array<string,array<string,MCP_Tool>>
	 */
	public static function grouped(): array {
		$grouped = array();
		foreach ( self::all() as $name => $tool ) {
			$grouped[ $tool->category ][ $name ] = $tool;
		}
		ksort( $grouped );
		return $grouped;
	}

	/**
	 * @return array<string,string>
	 */
	private static function overrides(): array {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}
}
