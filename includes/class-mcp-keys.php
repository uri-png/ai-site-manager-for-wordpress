<?php
/**
 * Scoped API key manager: generation, hashed storage, lookup and revocation.
 *
 * Keys are shown to the admin exactly once at creation time. Only a keyed
 * HMAC-SHA256 hash is stored, so a database leak does not expose usable keys.
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
 * Manages Bearer API keys bound to WordPress users.
 */
final class MCP_Keys {

	/**
	 * Displayed prefix so admins (and logs) can tell keys apart without the secret.
	 */
	private const PREFIX = 'wpmcp_';

	/**
	 * Create a new API key bound to a WordPress user.
	 *
	 * @param int    $user_id WordPress user whose capabilities will bound the key.
	 * @param string $label   Human-readable label, e.g. "n8n production".
	 * @return array|WP_Error { id, key (plaintext, shown once), prefix, label, user_id }
	 */
	public static function create( int $user_id, string $label ) {
		global $wpdb;

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'wpmcp_invalid_user', __( 'API keys must be bound to an existing WordPress user.', 'ai-site-manager' ) );
		}

		$plaintext = self::PREFIX . bin2hex( random_bytes( 24 ) ); // 48 hex chars of entropy.
		$hash      = self::hash( $plaintext );
		$prefix    = substr( $plaintext, 0, 12 );

		$inserted = $wpdb->insert(
			MCP_DB::table( 'api_keys' ),
			array(
				'key_hash'   => $hash,
				'key_prefix' => $prefix,
				'user_id'    => $user_id,
				'label'      => sanitize_text_field( $label ),
				'created_at' => current_time( 'mysql', true ),
				'revoked'    => 0,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'wpmcp_key_store_failed', __( 'Could not store the API key.', 'ai-site-manager' ) );
		}

		return array(
			'id'      => (int) $wpdb->insert_id,
			'key'     => $plaintext,
			'prefix'  => $prefix,
			'label'   => sanitize_text_field( $label ),
			'user_id' => $user_id,
		);
	}

	/**
	 * Resolve a presented Bearer token to its stored row.
	 *
	 * @param string $token Raw token from the Authorization header.
	 * @return object|null Row with id, user_id, label, key_prefix; null when invalid or revoked.
	 */
	public static function resolve( string $token ): ?object {
		global $wpdb;

		$token = trim( $token );
		if ( '' === $token || 0 !== strpos( $token, self::PREFIX ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, key_prefix, user_id, label, revoked FROM ' . MCP_DB::table( 'api_keys' ) . ' WHERE key_hash = %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::hash( $token )
			)
		);

		if ( ! $row || (int) $row->revoked ) {
			return null;
		}

		$wpdb->update(
			MCP_DB::table( 'api_keys' ),
			array( 'last_used_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $row->id ),
			array( '%s' ),
			array( '%d' )
		);

		return $row;
	}

	/**
	 * List all keys (without secrets) for the admin UI.
	 *
	 * @return array<int,object>
	 */
	public static function all(): array {
		global $wpdb;
		return $wpdb->get_results(
			'SELECT id, key_prefix, user_id, label, created_at, last_used_at, revoked FROM ' . MCP_DB::table( 'api_keys' ) . ' ORDER BY id DESC' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		) ?: array();
	}

	/**
	 * Revoke a key by ID.
	 *
	 * @param int $key_id Key row ID.
	 * @return bool
	 */
	public static function revoke( int $key_id ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			MCP_DB::table( 'api_keys' ),
			array( 'revoked' => 1 ),
			array( 'id' => $key_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Keyed hash of a plaintext token. Keyed with the site auth salt so the
	 * hashes are useless off-site and cannot be rainbow-tabled.
	 *
	 * @param string $token Plaintext token.
	 * @return string 64-char hex HMAC.
	 */
	private static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}
}


