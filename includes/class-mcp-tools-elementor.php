<?php
/**
 * Elementor bridge: exposes Elementor's own MCP abilities (WordPress Abilities
 * API, category "elementor") through this plugin's MCP endpoint, so every call
 * gets our OAuth/API-key auth, per-tool ON/OFF switches, approval queue,
 * audit log and a pre-write backup.
 *
 * Elementor registers its abilities itself (Elementor 4.2+, Atomic Editor,
 * "MCP exposure" turned on, WordPress 6.9+ Abilities API and the WordPress MCP
 * Adapter plugin). This class never writes Elementor data directly except for
 * restoring a backup it took itself.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor ability bridge.
 */
class MCP_Tools_Elementor {

	private const BACKUP_META = '_asm_elementor_backups';
	private const MAX_BACKUPS = 5;

	/**
	 * Whether the WordPress Abilities API is present.
	 */
	public static function abilities_api_available(): bool {
		return function_exists( 'wp_get_abilities' ) && function_exists( 'wp_get_ability' );
	}

	/**
	 * Ability id prefixes bridged. Filterable, default Elementor only.
	 *
	 * @return string[]
	 */
	public static function prefixes(): array {
		$prefixes = apply_filters( 'wpmcp_ability_prefixes', array( 'elementor/' ) );
		return is_array( $prefixes ) ? array_values( array_filter( array_map( 'strval', $prefixes ) ) ) : array( 'elementor/' );
	}

	/**
	 * Bridged abilities keyed by ability id.
	 *
	 * @return array<string,object>
	 */
	public static function abilities(): array {
		if ( ! self::abilities_api_available() ) {
			return array();
		}
		$out = array();
		foreach ( (array) wp_get_abilities() as $key => $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}
			$id = (string) $ability->get_name();
			foreach ( self::prefixes() as $prefix ) {
				if ( str_starts_with( $id, $prefix ) ) {
					$out[ $id ] = $ability;
					break;
				}
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Whether an ability only reads. Unknown means write (safe default).
	 */
	public static function is_read_only( object $ability ): bool {
		$meta = method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : array();
		$ann  = is_array( $meta['annotations'] ?? null ) ? $meta['annotations'] : array();
		if ( array_key_exists( 'readonly', $ann ) ) {
			return true === $ann['readonly'];
		}
		return false;
	}

	/**
	 * Tool name for an ability id.
	 */
	public static function tool_name( string $id ): string {
		$slug = strtolower( (string) preg_replace( '/[^a-zA-Z0-9]+/', '_', $id ) );
		$slug = trim( $slug, '_' );
		if ( ! str_starts_with( $slug, 'elementor_' ) ) {
			$slug = 'ability_' . $slug;
		}
		return substr( $slug, 0, 64 );
	}

	/**
	 * Tool map: fixed helper tools plus one tool per bridged ability.
	 *
	 * @return array<string,MCP_Tool>
	 */
	public static function discover(): array {
		$tools = array();

		$tools['elementor_status'] = new MCP_Tool(
			'elementor_status',
			'Elementor connection status',
			'Check whether Elementor and its MCP abilities are available on this site: Elementor version, Atomic Editor, MCP exposure setting, WordPress Abilities API, MCP Adapter plugin, and how many Elementor abilities are bridged. Run this first when an Elementor tool is missing.',
			'elementor',
			array( 'type' => 'object', 'properties' => new stdClass() ),
			fn( array $args ) => self::run_status(),
			fn( array $args ) => current_user_can( 'edit_posts' ),
			false,
			array( 'readOnlyHint' => true )
		);

		$tools['elementor_backups_list'] = new MCP_Tool(
			'elementor_backups_list',
			'List Elementor backups of a page',
			'List the automatic backups of a page\'s Elementor data that were taken right before earlier AI edits (newest first, last ' . self::MAX_BACKUPS . ').',
			'elementor',
			array(
				'type'       => 'object',
				'properties' => array( 'post_id' => array( 'type' => 'integer', 'description' => 'Post or page ID.' ) ),
				'required'   => array( 'post_id' ),
			),
			fn( array $args ) => self::run_backups_list( (int) $args['post_id'] ),
			fn( array $args ) => self::can_edit( (int) ( $args['post_id'] ?? 0 ) ),
			false,
			array( 'readOnlyHint' => true )
		);

		$tools['elementor_backup_restore'] = new MCP_Tool(
			'elementor_backup_restore',
			'Restore an Elementor backup',
			'Put a page\'s Elementor data back to a backup taken before an earlier AI edit (get backup_id from elementor_backups_list), then clear Elementor CSS cache. The current state is backed up first, so the restore can be undone too.',
			'elementor',
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id'   => array( 'type' => 'integer', 'description' => 'Post or page ID.' ),
					'backup_id' => array( 'type' => 'string', 'description' => 'Backup id from elementor_backups_list.' ),
				),
				'required'   => array( 'post_id', 'backup_id' ),
			),
			fn( array $args ) => self::run_restore( (int) $args['post_id'], (string) $args['backup_id'] ),
			fn( array $args ) => self::can_edit( (int) ( $args['post_id'] ?? 0 ) ),
			true
		);

		foreach ( self::abilities() as $id => $ability ) {
			$name = self::tool_name( $id );
			if ( isset( $tools[ $name ] ) ) {
				continue;
			}
			$read_only = self::is_read_only( $ability );
			$schema    = method_exists( $ability, 'get_input_schema' ) ? (array) $ability->get_input_schema() : array();
			$mcp       = $schema;
			if ( ! $mcp ) {
				$mcp = array( 'type' => 'object', 'properties' => new stdClass() );
			}
			if ( empty( $mcp['type'] ) ) {
				$mcp['type'] = 'object';
			}
			$label = method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : $id;
			$desc  = method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';

			$tool = new MCP_Tool(
				$name,
				$label,
				'[Elementor ability ' . $id . '] ' . $desc,
				'elementor',
				$mcp,
				fn( array $args ) => self::run_ability( $id, $args ),
				fn( array $args ) => self::ability_allowed( $id, $args ),
				! $read_only,
				array( 'readOnlyHint' => $read_only )
			);
			// Elementor and WordPress validate the input against the real schema themselves.
			$tool->exec_schema = array(
				'type'                 => 'object',
				'required'             => isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array(),
				'additionalProperties' => true,
			);
			$tools[ $name ]    = $tool;
		}

		return $tools;
	}

	private static function can_edit( int $id ): bool {
		$post = get_post( $id );
		if ( ! $post ) {
			return current_user_can( 'edit_posts' );
		}
		return current_user_can( 'edit_post', $id );
	}

	private static function ability_input( object $ability, array $args ) {
		$schema = method_exists( $ability, 'get_input_schema' ) ? (array) $ability->get_input_schema() : array();
		if ( ! $schema && ! $args ) {
			return null;
		}
		return $args;
	}

	private static function ability_allowed( string $id, array $args ): bool {
		$ability = wp_get_ability( $id );
		if ( ! $ability || ! method_exists( $ability, 'check_permissions' ) ) {
			return false;
		}
		$ok = $ability->check_permissions( self::ability_input( $ability, $args ) );
		return true === $ok;
	}

	private static function run_ability( string $id, array $args ) {
		$ability = wp_get_ability( $id );
		if ( ! $ability ) {
			throw new RuntimeException( 'Elementor ability not available: ' . $id );
		}
		$backup = null;
		if ( ! self::is_read_only( $ability ) && ! empty( $args['post_id'] ) ) {
			$backup = self::backup( (int) $args['post_id'], $id );
		}
		$result = $ability->execute( self::ability_input( $ability, $args ) );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}
		if ( $backup && is_array( $result ) ) {
			$result['_backup_id'] = $backup;
		} elseif ( $backup ) {
			$result = array( 'result' => $result, '_backup_id' => $backup );
		}
		return $result;
	}

	/**
	 * Snapshot the page's Elementor data. Returns the backup id or null.
	 */
	private static function backup( int $post_id, string $reason ): ?string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		$data = get_post_meta( $post_id, '_elementor_data', true );
		if ( '' === $data || null === $data || false === $data ) {
			return null;
		}
		$page_settings = get_post_meta( $post_id, '_elementor_page_settings', true );
		$list          = get_post_meta( $post_id, self::BACKUP_META, true );
		$list          = is_array( $list ) ? $list : array();
		$id            = gmdate( 'YmdHis' ) . '-' . substr( md5( $reason . microtime() ), 0, 6 );
		array_unshift(
			$list,
			array(
				'id'      => $id,
				'time'    => gmdate( 'c' ),
				'reason'  => $reason,
				'user_id' => get_current_user_id(),
				'data'    => is_string( $data ) ? $data : wp_json_encode( $data ),
				'page'    => $page_settings,
			)
		);
		$list = array_slice( $list, 0, self::MAX_BACKUPS );
		update_post_meta( $post_id, self::BACKUP_META, $list );
		return $id;
	}

	private static function run_backups_list( int $post_id ): array {
		if ( ! get_post( $post_id ) ) {
			throw new RuntimeException( 'Item not found.' );
		}
		$list = get_post_meta( $post_id, self::BACKUP_META, true );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $b ) {
			$out[] = array(
				'backup_id' => $b['id'],
				'time'      => $b['time'],
				'before'    => $b['reason'],
				'bytes'     => strlen( (string) $b['data'] ),
			);
		}
		return array( 'post_id' => $post_id, 'backups' => $out );
	}

	private static function run_restore( int $post_id, string $backup_id ): array {
		if ( ! get_post( $post_id ) ) {
			throw new RuntimeException( 'Item not found.' );
		}
		$list  = get_post_meta( $post_id, self::BACKUP_META, true );
		$found = null;
		foreach ( is_array( $list ) ? $list : array() as $b ) {
			if ( ( $b['id'] ?? '' ) === $backup_id ) {
				$found = $b;
				break;
			}
		}
		if ( ! $found ) {
			throw new InvalidArgumentException( 'Backup not found. Use elementor_backups_list.' );
		}
		json_decode( (string) $found['data'] );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new RuntimeException( 'Backup data is not valid JSON; not restored.' );
		}
		$undo = self::backup( $post_id, 'restore of ' . $backup_id );
		update_post_meta( $post_id, '_elementor_data', function_exists( 'wp_slash' ) ? wp_slash( $found['data'] ) : $found['data'] );
		if ( ! empty( $found['page'] ) ) {
			update_post_meta( $post_id, '_elementor_page_settings', $found['page'] );
		}
		delete_post_meta( $post_id, '_elementor_css' );
		$cache = 'not available';
		try {
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
				$cache = 'cleared';
			}
		} catch ( Throwable $e ) {
			$cache = 'failed: ' . $e->getMessage();
		}
		return array(
			'post_id'  => $post_id,
			'restored' => $backup_id,
			'undo_id'  => $undo,
			'css_cache' => $cache,
		);
	}

	private static function run_status(): array {
		$abilities = self::abilities();
		$adapter   = class_exists( '\WP\MCP\Core\McpAdapter' );
		$exposure  = null;
		if ( class_exists( '\Elementor\MCP\Composer\Admin\McpSettingsController' ) && method_exists( '\Elementor\MCP\Composer\Admin\McpSettingsController', 'is_enabled' ) ) {
			try {
				$exposure = (bool) \Elementor\MCP\Composer\Admin\McpSettingsController::is_enabled();
			} catch ( Throwable $e ) {
				$exposure = null;
			}
		}
		$atomic = null;
		try {
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->experiments ) ) {
				$atomic = (bool) \Elementor\Plugin::$instance->experiments->is_feature_active( 'e_atomic_elements' );
			}
		} catch ( Throwable $e ) {
			$atomic = null;
		}
		$el_version = defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : null;
		$problems   = array();
		if ( ! $el_version ) {
			$problems[] = 'Elementor is not active.';
		} elseif ( version_compare( $el_version, '4.2', '<' ) ) {
			$problems[] = 'Elementor ' . $el_version . ' is older than 4.2 (needs the Elementor MCP module).';
		}
		if ( ! self::abilities_api_available() ) {
			$problems[] = 'WordPress Abilities API missing (needs WordPress 6.9+).';
		}
		if ( ! $adapter ) {
			$problems[] = 'WordPress MCP Adapter plugin is not active; Elementor only registers its abilities when it is.';
		}
		if ( false === $exposure ) {
			$problems[] = 'Turn on MCP exposure in WP admin > Elementor > Elementor MCP.';
		}
		if ( false === $atomic ) {
			$problems[] = 'Atomic Editor is off (Elementor > Settings > Editor > Atomic Editor).';
		}
		if ( $el_version && self::abilities_api_available() && ! $abilities && ! $problems ) {
			$problems[] = 'No Elementor abilities are registered; check the MCP exposure setting and the MCP Adapter plugin.';
		}
		$names = array();
		foreach ( $abilities as $id => $ability ) {
			$name    = self::tool_name( $id );
			$names[] = array(
				'ability'   => $id,
				'tool'      => $name,
				'read_only' => self::is_read_only( $ability ),
				'enabled'   => MCP_Registry::is_enabled( $name ),
			);
		}
		return array(
			'elementor_version'   => $el_version,
			'wordpress_version'   => get_bloginfo( 'version' ),
			'atomic_editor'       => $atomic,
			'mcp_exposure'        => $exposure,
			'abilities_api'       => self::abilities_api_available(),
			'mcp_adapter_plugin'  => $adapter,
			'bridged_abilities'   => count( $abilities ),
			'problems'            => $problems,
			'tools'               => $names,
			'note'                => 'Write tools are OFF by default and queued for admin approval; enable them under AI Site Manager > Tools.',
		);
	}
}
