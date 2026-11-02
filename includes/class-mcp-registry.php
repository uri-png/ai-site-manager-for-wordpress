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
			$snapshot    = self::snapshot_verified_reads( self::$tools );

			/**
			 * Filter the discovered tool map. Plugins can register their own
			 * MCP_Tool instances or remove core ones.
			 *
			 * @param array<string,MCP_Tool> $tools Tool map keyed by tool name.
			 */
			self::$tools = apply_filters( 'wpmcp_tools', self::$tools );
			self::apply_verification( self::$tools, $snapshot );
		}
		return self::$tools;
	}

	/**
	 * Snapshot this plugin's own read tools right after discovery, before the
	 * wpmcp_tools filter can touch them. Maps name => [tool object id,
	 * callback object id]. Bridged abilities (category "elementor") and
	 * ability meta tools only qualify by explicit name.
	 *
	 * @param array<string,MCP_Tool> $tools Freshly discovered tools.
	 * @return array<string,array{0:int,1:int}>
	 */
	public static function snapshot_verified_reads( array $tools ): array {
		$explicit = array( 'elementor_status', 'elementor_backups_list', 'abilities_list' );
		$snapshot = array();
		foreach ( $tools as $name => $tool ) {
			$name = (string) $name;
			$ok   = in_array( $name, $explicit, true );
			if ( ! $ok && 'elementor' !== $tool->category && 'abilities' !== $tool->category ) {
				$ok = str_ends_with( $name, '_list' ) || str_ends_with( $name, '_get' ) || 'site_info' === $name;
			}
			if ( ! $ok && 'elementor' === $tool->category && ( $tool->annotations['readOnlyHint'] ?? false ) === true ) {
				// Bridged ability: needs a reviewed id, the exact reviewed plugin release
				// (version + file hashes), AND the ability must still say it only reads.
				foreach ( array_keys( MCP_Mode::VETTED_SOURCES ) as $vetted_id ) {
					if ( MCP_Tools_Elementor::tool_name( $vetted_id ) === $name && MCP_Mode::vetted_ability_ok( $vetted_id ) ) {
						$ok = true;
						break;
					}
				}
			}
			if ( $ok && ! $tool->destructive ) {
				$snapshot[ $name ] = array( spl_object_id( $tool ), spl_object_id( $tool->callback ) );
			}
		}
		return $snapshot;
	}

	/**
	 * Apply verification after the filter ran: a tool is verified only if it
	 * is the very same object and still has the very same callback as at
	 * discovery. Replaced tools, name collisions, swapped callbacks and flags
	 * set by other code all end up unverified.
	 *
	 * @param array<string,MCP_Tool>             $tools    Final tool map.
	 * @param array<string,array{0:int,1:int}>   $snapshot From snapshot_verified_reads().
	 */
	public static function apply_verification( array $tools, array $snapshot ): void {
		foreach ( $tools as $name => $tool ) {
			$name                = (string) $name;
			$tool->verified_read = isset( $snapshot[ $name ] )
				&& $snapshot[ $name ][0] === spl_object_id( $tool )
				&& $snapshot[ $name ][1] === spl_object_id( $tool->callback )
				&& ! $tool->destructive;
		}
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
	 * Tools an agent may see and run right now: enabled and allowed by the
	 * access mode (in Monitor mode only read-only tools).
	 *
	 * @return array<string,MCP_Tool>
	 */
	public static function available(): array {
		return array_filter( self::enabled(), fn( MCP_Tool $tool ) => ! MCP_Mode::blocks( $tool ) );
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


