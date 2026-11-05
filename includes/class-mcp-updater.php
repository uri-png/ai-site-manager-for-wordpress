<?php
/**
 * Manual "update from GitHub" for administrators.
 *
 * Nothing runs on its own: no scheduled checks, no automatic updates. An
 * administrator clicks "Check GitHub for updates", sees the result, and then
 * clicks "Update now" to install the release.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks the project's public GitHub releases and installs one on request.
 */
final class MCP_Updater {

	private const REPO          = 'uri-png/ai-site-manager-for-wordpress';
	private const DOWNLOAD_BASE = 'https://github.com/uri-png/ai-site-manager-for-wordpress/releases/download/';
	private const CACHE_KEY     = 'wpmcp_github_release';
	private const CACHE_TTL     = 600;
	private const SEEN_OPTION   = 'wpmcp_update_seen';

	/**
	 * Latest GitHub release: array( version, url, asset_url, sha256 ) or WP_Error.
	 *
	 * @param bool $force Skip the short cache.
	 * @return array<string,string>|WP_Error
	 */
	public static function latest( bool $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'ai-site-manager/' . WPMCP_VERSION,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'wpmcp_gh', sprintf( 'Could not reach GitHub: %s', $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 403 === $code || 429 === $code ) {
			return new WP_Error( 'wpmcp_gh', 'GitHub is limiting requests from this server. Try again in a few minutes.' );
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'wpmcp_gh', sprintf( 'GitHub returned HTTP %d.', $code ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return new WP_Error( 'wpmcp_gh', 'Unexpected answer from GitHub.' );
		}

		$version = ltrim( (string) $data['tag_name'], 'vV' );
		if ( ! preg_match( '/^\d+(\.\d+){1,3}$/', $version ) ) {
			return new WP_Error( 'wpmcp_gh', 'The latest GitHub release has an unrecognised version tag.' );
		}

		// Only the plugin ZIP attached to the release (right folder name), never the auto-generated source archive.
		$asset_url = '';
		$sha256    = '';
		$expected  = 'ai-site-manager-' . $version . '.zip';
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( is_array( $asset ) && isset( $asset['name'], $asset['browser_download_url'] ) && $expected === $asset['name'] ) {
				$asset_url = (string) $asset['browser_download_url'];
				if ( isset( $asset['digest'] ) && preg_match( '/^sha256:([0-9a-f]{64})$/i', (string) $asset['digest'], $m ) ) {
					$sha256 = strtolower( $m[1] );
				}
				break;
			}
		}
		if ( '' === $asset_url || 0 !== strpos( $asset_url, self::DOWNLOAD_BASE ) ) {
			return new WP_Error( 'wpmcp_gh', sprintf( 'Release %s has no %s file attached, so it cannot be installed from here.', $version, $expected ) );
		}

		$release = array(
			'version'   => $version,
			'url'       => isset( $data['html_url'] ) ? esc_url_raw( (string) $data['html_url'] ) : 'https://github.com/' . self::REPO . '/releases',
			'asset_url' => $asset_url,
			'sha256'    => $sha256,
		);
		set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
		update_option(
			self::SEEN_OPTION,
			array(
				'version' => $version,
				'url'     => $release['url'],
				'time'    => time(),
			),
			false
		);
		return $release;
	}

	/**
	 * Install the latest release. $expected_version is the version the admin saw;
	 * if GitHub has moved on since, nothing is installed.
	 *
	 * @return true|WP_Error
	 */
	public static function install( string $expected_version ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return new WP_Error( 'wpmcp_gh', 'You are not allowed to update plugins on this site.' );
		}
		if ( ! wp_is_file_mod_allowed( 'wpmcp_manual_update' ) ) {
			return new WP_Error( 'wpmcp_gh', 'File changes are disabled on this site (DISALLOW_FILE_MODS).' );
		}
		$release = self::latest( true );
		if ( is_wp_error( $release ) ) {
			return $release;
		}
		if ( $release['version'] !== $expected_version ) {
			return new WP_Error( 'wpmcp_gh', sprintf( 'GitHub now lists version %s, not %s. Check again before updating.', $release['version'], $expected_version ) );
		}
		if ( ! version_compare( $release['version'], WPMCP_VERSION, '>' ) ) {
			return new WP_Error( 'wpmcp_gh', 'This site already has that version or a newer one.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$tmp = download_url( $release['asset_url'], 60 );
		if ( is_wp_error( $tmp ) ) {
			return new WP_Error( 'wpmcp_gh', sprintf( 'Download failed: %s', $tmp->get_error_message() ) );
		}
		if ( '' !== $release['sha256'] && ! hash_equals( $release['sha256'], strtolower( (string) hash_file( 'sha256', $tmp ) ) ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'wpmcp_gh', 'The downloaded file does not match the checksum GitHub lists. Nothing was installed.' );
		}

		$archive_check = self::validate_archive( $tmp, $release['version'] );
		if ( is_wp_error( $archive_check ) ) {
			wp_delete_file( $tmp );
			return $archive_check;
		}

		$basename   = plugin_basename( WPMCP_PLUGIN_FILE );
		$was_active = is_plugin_active( $basename );
		$network    = is_plugin_active_for_network( $basename );

		// Hand the already-verified local file to the core upgrader, which keeps the folder name and rolls back on failure.
		$use_local = static function ( $reply, $package ) use ( $tmp, $release ) {
			return $package === $release['asset_url'] ? $tmp : $reply;
		};
		$inject = static function ( $transient ) use ( $basename, $release ) {
			if ( ! is_object( $transient ) ) {
				$transient = new stdClass();
			}
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			$transient->response[ $basename ] = (object) array(
				'id'          => 'github.com/' . self::REPO,
				'slug'        => dirname( $basename ),
				'plugin'      => $basename,
				'new_version' => $release['version'],
				'url'         => $release['url'],
				'package'     => $release['asset_url'],
			);
			return $transient;
		};
		$check_source = static function ( $source ) use ( $release ) {
			if ( is_wp_error( $source ) ) {
				return $source;
			}
			$main = trailingslashit( $source ) . 'ai-site-manager.php';
			if ( ! is_readable( $main ) ) {
				return new WP_Error( 'wpmcp_gh', 'The ZIP does not contain ai-site-manager.php. Nothing was installed.' );
			}
			$data = get_file_data( $main, array( 'Version' => 'Version' ) );
			if ( $data['Version'] !== $release['version'] ) {
				return new WP_Error( 'wpmcp_gh', 'The ZIP version does not match the release. Nothing was installed.' );
			}
			return $source;
		};

		add_filter( 'upgrader_pre_download', $use_local, 10, 2 );
		add_filter( 'site_transient_update_plugins', $inject, 99 );
		add_filter( 'upgrader_source_selection', $check_source, 20 );

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $basename );

		remove_filter( 'upgrader_pre_download', $use_local, 10 );
		remove_filter( 'site_transient_update_plugins', $inject, 99 );
		remove_filter( 'upgrader_source_selection', $check_source, 20 );
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		delete_transient( self::CACHE_KEY );
		wp_clean_plugins_cache( true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_wp_error( $skin->result ) ) {
			return $skin->result;
		}
		if ( true !== $result && ! is_array( $result ) ) {
			$messages = $skin->get_upgrade_messages();
			return new WP_Error( 'wpmcp_gh', 'The update did not complete.' . ( $messages ? ' ' . wp_strip_all_tags( end( $messages ) ) : '' ) );
		}

		// The core upgrader deactivates a plugin while replacing it; put it back as it was.
		if ( $was_active && ! is_plugin_active( $basename ) ) {
			$activated = activate_plugin( $basename, '', $network, true );
			if ( is_wp_error( $activated ) ) {
				return new WP_Error( 'wpmcp_gh', 'Updated, but re-activation failed: ' . $activated->get_error_message() . ' Activate it from the Plugins screen.' );
			}
		}
		return true;
	}

	/**
	 * Plugins screen: "Check for updates" link in the plugin's action links.
	 *
	 * @param array<int|string,string> $links Existing links.
	 * @return array<int|string,string>
	 */
	public static function action_links( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}
		$url     = add_query_arg( 'wpmcp_checked', '1', MCP_Admin::tab_url( 'settings' ) );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'ai-site-manager' ) . '</a>';
		return $links;
	}

	/**
	 * Plugins screen: after a manual check found a newer release, show it under
	 * the plugin row with the same confirm-and-install form as Settings & Status.
	 * Reads only the result of the last manual check; makes no network request.
	 *
	 * @param string $plugin_file Plugin basename of the row.
	 */
	public static function plugin_row_notice( $plugin_file ): void {
		if ( ! current_user_can( 'update_plugins' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$seen = get_option( self::SEEN_OPTION );
		if ( ! is_array( $seen ) || empty( $seen['version'] ) || ! version_compare( (string) $seen['version'], WPMCP_VERSION, '>' ) || time() - (int) ( $seen['time'] ?? 0 ) > 7 * DAY_IN_SECONDS ) {
			return;
		}
		$version = (string) $seen['version'];
		$action  = MCP_Admin::tab_url( 'settings' );
		echo '<tr class="plugin-update-tr active"><td colspan="4" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p style="margin:0">';
		echo sprintf(
			/* translators: %s: new version number. */
			esc_html__( 'AI Site Manager %s is available on GitHub (found by your last manual check).', 'ai-site-manager' ),
			esc_html( $version )
		);
		$link = wp_nonce_url( add_query_arg( 'wpmcp_install', rawurlencode( $version ), $action ), 'wpmcp_install_update' );
		echo ' <a href="' . esc_url( $link ) . '" onclick="return confirm(\'' . esc_js( __( 'Install this version now? Back up the site first if it is a live site.', 'ai-site-manager' ) ) . '\');">' . esc_html__( 'Update now', 'ai-site-manager' ) . '</a>';
		echo '</p></div></td></tr>';
	}

	/**
	 * Inspect the ZIP before anything is extracted: one top-level folder named
	 * ai-site-manager, no path tricks, plugin header present with the expected
	 * version, sane size.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_archive( string $file, string $version ) {
		$fail = static function ( string $why ) {
			return new WP_Error( 'wpmcp_gh', 'The downloaded ZIP was rejected (' . $why . '). Nothing was installed.' );
		};
		if ( ! class_exists( 'ZipArchive' ) ) {
			return $fail( 'this server cannot inspect ZIP files' );
		}
		if ( filesize( $file ) > 20 * MB_IN_BYTES ) {
			return $fail( 'file too large' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $file ) ) {
			return $fail( 'not a valid ZIP' );
		}
		$count = $zip->numFiles;
		if ( $count < 1 || $count > 2000 ) {
			$zip->close();
			return $fail( 'unexpected number of files' );
		}
		$total = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = is_array( $stat ) ? (string) $stat['name'] : '';
			if ( '' === $name || false !== strpos( $name, '..' ) || false !== strpos( $name, '\\' ) || false !== strpos( $name, "\0" ) || '/' === $name[0] || preg_match( '#^[A-Za-z]:#', $name ) ) {
				$zip->close();
				return $fail( 'unsafe file path' );
			}
			if ( 0 !== strpos( $name, 'ai-site-manager/' ) ) {
				$zip->close();
				return $fail( 'unexpected folder layout' );
			}
			if ( preg_match( '/\.(phar|exe|sh|htaccess)$/i', $name ) ) {
				$zip->close();
				return $fail( 'unexpected file type' );
			}
			$total += (int) $stat['size'];
		}
		if ( $total > 60 * MB_IN_BYTES ) {
			$zip->close();
			return $fail( 'unpacked size too large' );
		}
		$main = $zip->getFromName( 'ai-site-manager/ai-site-manager.php' );
		$zip->close();
		if ( false === $main || ! preg_match( '/^[ \t\/*#@]*Plugin Name:\s*AI Site Manager\s*$/mi', $main ) || ! preg_match( '/^[ \t\/*#@]*Version:\s*' . preg_quote( $version, '/' ) . '\s*$/mi', $main ) ) {
			return $fail( 'plugin header does not match the release' );
		}
		return true;
	}

	/**
	 * Settings-tab box: current version, check button, update button.
	 */
	public static function render_box(): void {
		echo '<h3>' . esc_html__( 'Updates from GitHub', 'ai-site-manager' ) . '</h3>';
		echo '<p>' . sprintf(
			/* translators: %s: installed plugin version. */
			esc_html__( 'Installed version: %s. Updates are never automatic - nothing is checked or installed until you click.', 'ai-site-manager' ),
			'<strong>' . esc_html( WPMCP_VERSION ) . '</strong>'
		) . '</p>';

		$release = null;
		$checked = isset( $_GET['wpmcp_checked'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag from our own redirect.
		if ( $checked ) {
			$release = self::latest();
		}

		if ( is_wp_error( $release ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $release->get_error_message() ) . '</p></div>';
		} elseif ( is_array( $release ) ) {
			if ( version_compare( $release['version'], WPMCP_VERSION, '>' ) ) {
				echo '<div class="notice notice-info inline"><p>' . sprintf(
					/* translators: %s: new version number. */
					esc_html__( 'Version %s is available.', 'ai-site-manager' ),
					'<strong>' . esc_html( $release['version'] ) . '</strong>'
				) . ' <a href="' . esc_url( $release['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Release notes on GitHub', 'ai-site-manager' ) . '</a></p></div>';
				if ( current_user_can( 'update_plugins' ) ) {
					echo '<form method="post" onsubmit="return confirm(\'' . esc_js( __( 'Install this version now? Back up the site first if it is a live site.', 'ai-site-manager' ) ) . '\');"><input type="hidden" name="wpmcp_action" value="install_update" />';
					echo '<input type="hidden" name="wpmcp_version" value="' . esc_attr( $release['version'] ) . '" />';
					wp_nonce_field( 'wpmcp_install_update' );
					submit_button( sprintf( /* translators: %s: version number. */ __( 'Update now to %s', 'ai-site-manager' ), $release['version'] ), 'primary', 'submit', false );
					echo '</form>';
				}
			} else {
				echo '<div class="notice notice-success inline"><p>' . esc_html__( 'You are up to date.', 'ai-site-manager' ) . '</p></div>';
			}
		}

		echo '<form method="post" style="margin-top:12px"><input type="hidden" name="wpmcp_action" value="check_update" />';
		wp_nonce_field( 'wpmcp_check_update' );
		submit_button( __( 'Check GitHub for updates', 'ai-site-manager' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'Looks at the public releases of the project on GitHub and installs only the plugin ZIP attached to a release. Your keys, settings and logs are kept.', 'ai-site-manager' ) . '</p>';
	}
}
