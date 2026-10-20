<?php
/**
 * Core tool factory: builds MCP_Tool definitions for posts, pages, media,
 * users, site info, public custom post types, and WooCommerce when active.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovers and builds the v1 tool set.
 */
final class MCP_Tools_Core {

	/**
	 * Build the full tool map for this site.
	 *
	 * @return array<string,MCP_Tool>
	 */
	public static function discover(): array {
		$tools = array();

		foreach ( self::post_type_tools( 'post', 'posts', 'Posts' ) as $name => $tool ) {
			$tools[ $name ] = $tool;
		}
		foreach ( self::post_type_tools( 'page', 'pages', 'Pages' ) as $name => $tool ) {
			$tools[ $name ] = $tool;
		}
		foreach ( self::media_tools() as $name => $tool ) {
			$tools[ $name ] = $tool;
		}
		foreach ( self::user_tools() as $name => $tool ) {
			$tools[ $name ] = $tool;
		}
		if ( MCP_Tools_Yoast::is_active() ) {
			foreach ( MCP_Tools_Yoast::discover() as $name => $tool ) {
				$tools[ $name ] = $tool;
			}
		}
		if ( class_exists( 'MCP_Tools_Elementor' ) && MCP_Tools_Elementor::abilities_api_available() || defined( 'ELEMENTOR_VERSION' ) ) {
			foreach ( MCP_Tools_Elementor::discover() as $name => $tool ) {
				$tools[ $name ] = $tool;
			}
		}
		$tools['site_info'] = self::site_info_tool();
		foreach ( self::term_tools() as $name => $tool ) {
			$tools[ $name ] = $tool;
		}

		// Public custom post types (core types and WooCommerce types are handled above/below).
		$skip = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon' );
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $ptype => $obj ) {
			if ( in_array( $ptype, $skip, true ) ) {
				continue;
			}
			$slug = 'cpt_' . str_replace( '-', '_', sanitize_key( $ptype ) );
			foreach ( self::post_type_tools( $ptype, $slug, $obj->labels->name ?? $ptype, 'cpt:' . $ptype ) as $name => $tool ) {
				$tools[ $name ] = $tool;
			}
		}

		if ( class_exists( 'WooCommerce' ) || post_type_exists( 'product' ) ) {
			foreach ( self::post_type_tools( 'product', 'woo_products', 'WooCommerce Products', 'woocommerce' ) as $name => $tool ) {
				$tools[ $name ] = $tool;
			}
			foreach ( self::woo_order_tools() as $name => $tool ) {
				$tools[ $name ] = $tool;
			}
		}

		return $tools;
	}

	/**
	 * Standard list/get/create/update/delete tool set for one post type.
	 *
	 * @param string $ptype    Post type name.
	 * @param string $slug     Tool name prefix (MCP-safe).
	 * @param string $label    Human label for descriptions.
	 * @param string $category Admin UI grouping.
	 * @return array<string,MCP_Tool>
	 */
	private static function post_type_tools( string $ptype, string $slug, string $label, ?string $category = null ): array {
		$category = $category ?? $slug;

		$id_prop     = array( 'type' => 'integer', 'description' => 'Post ID.' );
		$write_props = array(
			'title'   => array( 'type' => 'string', 'description' => 'Post title.' ),
			'content' => array( 'type' => 'string', 'description' => 'Post content (HTML allowed within the user\'s kses privileges).' ),
			'excerpt' => array( 'type' => 'string', 'description' => 'Post excerpt.' ),
			'status'  => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'pending', 'private', 'future' ), 'description' => 'Post status.' ),
			'meta'    => array( 'type' => 'object', 'description' => 'Custom meta fields as key => scalar. Keys starting with "_" are protected: if any are present the WHOLE call fails and nothing is written - resend without them.' ),
			'featured_media' => array( 'type' => 'integer', 'description' => 'Attachment ID to set as the featured image.' ),
			'terms'   => array( 'type' => 'object', 'description' => 'Taxonomy terms as taxonomy => array of term IDs (e.g. {"category": [3], "post_tag": [7]}).' ),
		);

		return array(
			$slug . '_list'   => new MCP_Tool(
				$slug . '_list',
				"List {$label}",
				"List {$label} with optional filters. Returns ID, title, status, date and link for each item.",
				$category,
				array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'any' ), 'default' => 'publish' ),
						'search'   => array( 'type' => 'string', 'description' => 'Search term.' ),
						'per_page' => array( 'type' => 'integer', 'description' => 'Items per page (1-100, default 10).' ),
						'page'     => array( 'type' => 'integer', 'description' => 'Page number (default 1).' ),
					),
				),
				fn( array $args ) => self::run_list( $ptype, $args ),
				function ( array $args ) use ( $ptype ) {
					$status = $args['status'] ?? 'publish';
					if ( 'publish' === $status ) {
						return current_user_can( 'read' );
					}
					$obj = get_post_type_object( $ptype );
					return $obj && current_user_can( $obj->cap->edit_posts );
				}
			),
			$slug . '_get'    => new MCP_Tool(
				$slug . '_get',
				"Get {$label} item",
				"Get one {$label} item by ID, including content and custom meta fields.",
				$category,
				array(
					'type'       => 'object',
					'properties' => array( 'id' => $id_prop ),
					'required'   => array( 'id' ),
				),
				fn( array $args ) => self::run_get( $ptype, (int) $args['id'] ),
				function ( array $args ) use ( $ptype ) {
					$post = get_post( (int) ( $args['id'] ?? 0 ) );
					if ( ! $post || $post->post_type !== $ptype ) {
						return true; // Missing items fall through to the runner's not-found error.
					}
					if ( 'publish' === $post->post_status ) {
						return current_user_can( 'read' );
					}
					$obj = get_post_type_object( $ptype );
					return $obj && current_user_can( $obj->cap->edit_post, (int) $post->ID );
				}
			),
			$slug . '_create' => new MCP_Tool(
				$slug . '_create',
				"Create {$label} item",
				"Create a new {$label} item. Defaults to draft unless a status is given.",
				$category,
				array(
					'type'       => 'object',
					'properties' => $write_props,
					'required'   => array( 'title' ),
				),
				fn( array $args ) => self::run_create( $ptype, $args ),
				function ( array $args ) use ( $ptype ) {
					$obj = get_post_type_object( $ptype );
					if ( ! $obj || ! current_user_can( $obj->cap->edit_posts ) ) {
						return false;
					}
					if ( 'publish' === ( $args['status'] ?? 'draft' ) && ! current_user_can( $obj->cap->publish_posts ) ) {
						return false;
					}
					return true;
				}
			),
			$slug . '_update' => new MCP_Tool(
				$slug . '_update',
				"Update {$label} item",
				"Update fields of an existing {$label} item. Only provided fields change.",
				$category,
				array_merge(
					array(
						'type'     => 'object',
						'required' => array( 'id' ),
					),
					array( 'properties' => array_merge( array( 'id' => $id_prop ), $write_props ) )
				),
				fn( array $args ) => self::run_update( $ptype, $args ),
				function ( array $args ) use ( $ptype ) {
					$obj  = get_post_type_object( $ptype );
					$post = get_post( (int) ( $args['id'] ?? 0 ) );
					if ( ! $obj || ! $post || $post->post_type !== $ptype ) {
						return true; // Missing items fall through to the runner's not-found error.
					}
					if ( ! current_user_can( $obj->cap->edit_post, (int) $post->ID ) ) {
						return false;
					}
					if ( isset( $args['status'] ) && 'publish' === $args['status'] && ! current_user_can( $obj->cap->publish_post, (int) $post->ID ) ) {
						return false;
					}
					return true;
				}
			),
			$slug . '_delete' => new MCP_Tool(
				$slug . '_delete',
				"Delete {$label} item",
				"Move a {$label} item to trash, or delete it permanently with force=true.",
				$category,
				array(
					'type'       => 'object',
					'properties' => array(
						'id'    => $id_prop,
						'force' => array( 'type' => 'boolean', 'description' => 'true = permanently delete, false (default) = move to trash.' ),
					),
					'required'   => array( 'id' ),
				),
				fn( array $args ) => self::run_delete( $ptype, (int) $args['id'], ! empty( $args['force'] ) ),
				function ( array $args ) use ( $ptype ) {
					$obj  = get_post_type_object( $ptype );
					$post = get_post( (int) ( $args['id'] ?? 0 ) );
					if ( ! $obj || ! $post || $post->post_type !== $ptype ) {
						return true; // Missing items fall through to the runner's not-found error.
					}
					return current_user_can( $obj->cap->delete_post, (int) $post->ID );
				},
				true
			),
		);
	}

	/**
	 * Media library tools.
	 *
	 * @return array<string,MCP_Tool>
	 */
	private static function media_tools(): array {
		return array(
			'media_list'           => new MCP_Tool(
				'media_list',
				'List media',
				'List media library items with optional search. Returns ID, title, mime type and URL.',
				'media',
				array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string' ),
						'per_page' => array( 'type' => 'integer', 'description' => 'Items per page (1-100, default 10).' ),
						'page'     => array( 'type' => 'integer', 'description' => 'Page number (default 1).' ),
					),
				),
				fn( array $args ) => self::run_media_list( $args ),
				fn( array $args ) => current_user_can( 'upload_files' )
			),
			'media_get'            => new MCP_Tool(
				'media_get',
				'Get media item',
				'Get one media item by ID, including URL, alt text and caption.',
				'media',
				array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer', 'description' => 'Attachment ID.' ) ),
					'required'   => array( 'id' ),
				),
				fn( array $args ) => self::run_media_get( (int) $args['id'] ),
				fn( array $args ) => current_user_can( 'upload_files' )
			),
			'media_upload_from_url' => new MCP_Tool(
				'media_upload_from_url',
				'Upload media from URL',
				'Download a publicly reachable image/file URL into the media library and return the new attachment. The file is fetched server-side; nothing is executed.',
				'media',
				array(
					'type'       => 'object',
					'properties' => array(
						'url'     => array( 'type' => 'string', 'description' => 'Public http(s) URL of the file.' ),
						'title'   => array( 'type' => 'string' ),
						'alt'     => array( 'type' => 'string', 'description' => 'Alt text (images).' ),
						'post_id' => array( 'type' => 'integer', 'description' => 'Optional post to attach the file to.' ),
					),
					'required'   => array( 'url' ),
				),
				fn( array $args ) => self::run_media_upload( $args ),
				fn( array $args ) => current_user_can( 'upload_files' )
			),
			'media_upload'          => new MCP_Tool(
				'media_upload',
				'Upload media (base64)',
				'Upload an image sent directly as base64 data into the media library. Images only (JPEG, PNG, GIF, WebP), 5 MB max after decoding; a "data:image/...;base64," prefix is accepted and stripped. Optionally attach to a post and set it as that post\'s featured image.',
				'media',
				array(
					'type'       => 'object',
					'properties' => array(
						'data'         => array( 'type' => 'string', 'max_length' => 7340032, 'description' => 'Base64-encoded file content (data-URI prefix allowed; max 5 MB after decoding).' ),
						'filename'     => array( 'type' => 'string', 'description' => 'File name, e.g. "hero.png". The extension is corrected to match the detected image type.' ),
						'title'        => array( 'type' => 'string' ),
						'alt'          => array( 'type' => 'string', 'description' => 'Alt text.' ),
						'post_id'      => array( 'type' => 'integer', 'description' => 'Optional post to attach the file to.' ),
						'set_featured' => array( 'type' => 'boolean', 'description' => 'Also set as the featured image of post_id (requires post_id).' ),
					),
					'required'   => array( 'data', 'filename' ),
				),
				fn( array $args ) => self::run_media_upload_base64( $args ),
				fn( array $args ) => current_user_can( 'upload_files' )
			),
		);
	}

	/**
	 * Read-only user directory tools. User creation/editing is deliberately
	 * NOT exposed in v1 (privilege-escalation surface).
	 *
	 * @return array<string,MCP_Tool>
	 */
	private static function user_tools(): array {
		return array(
			'users_list' => new MCP_Tool(
				'users_list',
				'List users',
				'List site users (ID, login, display name, email, roles). Requires the list_users capability.',
				'users',
				array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string' ),
						'role'     => array( 'type' => 'string', 'description' => 'Filter by role slug.' ),
						'per_page' => array( 'type' => 'integer', 'description' => 'Items per page (1-100, default 10).' ),
						'page'     => array( 'type' => 'integer', 'description' => 'Page number (default 1).' ),
					),
				),
				fn( array $args ) => self::run_users_list( $args ),
				fn( array $args ) => current_user_can( 'list_users' )
			),
			'users_get'  => new MCP_Tool(
				'users_get',
				'Get user',
				'Get one user by ID (login, display name, email, roles, registered date).',
				'users',
				array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer', 'description' => 'User ID.' ) ),
					'required'   => array( 'id' ),
				),
				fn( array $args ) => self::run_users_get( (int) $args['id'] ),
				fn( array $args ) => current_user_can( 'list_users' )
			),
		);
	}

	/**
	 * Site information tool (read-only, any authenticated key user).
	 */
	private static function site_info_tool(): MCP_Tool {
		return new MCP_Tool(
			'site_info',
			'Site information',
			'Basic site facts: name, URL, WordPress version, active plugins, and the post types this gateway exposes.',
			'site',
			array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
			fn( array $args ) => array(
				'name'             => get_bloginfo( 'name' ),
				'url'              => home_url(),
				'wordpress'        => get_bloginfo( 'version' ),
				'plugin_version'   => WPMCP_VERSION,
				'woocommerce'      => class_exists( 'WooCommerce' ),
				'active_plugins'   => (array) get_option( 'active_plugins', array() ),
				'exposed_post_types' => array_values( get_post_types( array( 'public' => true ) ) ),
			),
			fn( array $args ) => is_user_logged_in()
		);
	}

	/**
	 * WooCommerce order tools (orders are not ordinary posts; they use
	 * shop_order caps and a fixed status set).
	 *
	 * @return array<string,MCP_Tool>
	 */
	private static function woo_order_tools(): array {
		$statuses = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );

		return array(
			'woo_orders_list'   => new MCP_Tool(
				'woo_orders_list',
				'List WooCommerce orders',
				'List orders with optional status filter. Returns ID, number, status, total, currency and date.',
				'woocommerce',
				array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array( 'type' => 'string', 'enum' => array_merge( $statuses, array( 'any' ) ), 'default' => 'any' ),
						'per_page' => array( 'type' => 'integer', 'description' => 'Items per page (1-100, default 10).' ),
						'page'     => array( 'type' => 'integer', 'description' => 'Page number (default 1).' ),
					),
				),
				function ( array $args ) {
					$query = new WC_Order_Query(
						array(
							'status'  => 'any' === ( $args['status'] ?? 'any' ) ? array_keys( wc_get_order_statuses() ) : array( 'wc-' . $args['status'] ),
							'limit'   => self::per_page( $args ),
							'page'    => self::page( $args ),
							'orderby' => 'date',
							'order'   => 'DESC',
						)
					);
					return array_map(
						fn( $order ) => array(
							'id'       => $order->get_id(),
							'number'   => $order->get_order_number(),
							'status'   => $order->get_status(),
							'total'    => $order->get_total(),
							'currency' => $order->get_currency(),
							'date'     => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
						),
						$query->get_orders()
					);
				},
				fn( array $args ) => current_user_can( 'edit_shop_orders' )
			),
			'woo_orders_get'    => new MCP_Tool(
				'woo_orders_get',
				'Get WooCommerce order',
				'Get one order by ID: status, totals, billing/shipping names, line items (name, qty, total).',
				'woocommerce',
				array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer', 'description' => 'Order ID.' ) ),
					'required'   => array( 'id' ),
				),
				function ( array $args ) {
					$order = wc_get_order( (int) $args['id'] );
					if ( ! $order ) {
						throw new RuntimeException( 'Order not found.' );
					}
					return array(
						'id'       => $order->get_id(),
						'number'   => $order->get_order_number(),
						'status'   => $order->get_status(),
						'total'    => $order->get_total(),
						'currency' => $order->get_currency(),
						'billing'  => array(
							'first_name' => $order->get_billing_first_name(),
							'last_name'  => $order->get_billing_last_name(),
							'email'      => $order->get_billing_email(),
						),
						'items'    => array_map(
							fn( $item ) => array(
								'name'  => $item->get_name(),
								'qty'   => $item->get_quantity(),
								'total' => $item->get_total(),
							),
							array_values( $order->get_items() )
						),
						'date'     => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
					);
				},
				fn( array $args ) => current_user_can( 'edit_shop_orders' )
			),
			'woo_orders_update' => new MCP_Tool(
				'woo_orders_update',
				'Update WooCommerce order status',
				'Change an order status, optionally adding an order note.',
				'woocommerce',
				array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array( 'type' => 'integer', 'description' => 'Order ID.' ),
						'status' => array( 'type' => 'string', 'enum' => $statuses ),
						'note'   => array( 'type' => 'string', 'description' => 'Optional order note.' ),
					),
					'required'   => array( 'id', 'status' ),
				),
				function ( array $args ) {
					$order = wc_get_order( (int) $args['id'] );
					if ( ! $order ) {
						throw new RuntimeException( 'Order not found.' );
					}
					$order->update_status( $args['status'], isset( $args['note'] ) ? sanitize_textarea_field( (string) $args['note'] ) : '', true );
					return array(
						'id'     => $order->get_id(),
						'status' => $order->get_status(),
					);
				},
				fn( array $args ) => current_user_can( 'edit_shop_order', (int) ( $args['id'] ?? 0 ) )
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Callback implementations
	 * ------------------------------------------------------------------- */

	private static function run_list( string $ptype, array $args ): array {
		$query = new WP_Query(
			array(
				'post_type'      => $ptype,
				'post_status'    => $args['status'] ?? 'publish',
				's'              => isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '',
				'posts_per_page' => self::per_page( $args ),
				'paged'          => self::page( $args ),
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);

		$items = array();
		foreach ( $query->posts as $post_id ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$items[] = self::post_summary( $post );
			}
		}
		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
			'page'  => self::page( $args ),
		);
	}

	private static function run_get( string $ptype, int $id ): array {
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== $ptype ) {
			throw new RuntimeException( 'Item not found.' );
		}
		$data            = self::post_summary( $post );
		$data['content'] = $post->post_content;
		$data['excerpt'] = $post->post_excerpt;
		$data['meta']    = self::public_meta( $id );
		return $data;
	}

	private static function run_create( string $ptype, array $args ): array {
		self::assert_meta_allowed( $args['meta'] ?? null );

		$postarr = array(
			'post_type'    => $ptype,
			'post_title'   => sanitize_text_field( (string) $args['title'] ),
			'post_content' => wp_kses_post( (string) ( $args['content'] ?? '' ) ),
			'post_excerpt' => wp_kses_post( (string) ( $args['excerpt'] ?? '' ) ),
			'post_status'  => $args['status'] ?? 'draft',
		);

		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( $id->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON-RPC error message, not HTML output.
		}

		self::apply_meta( $id, $args['meta'] ?? null );
		self::apply_terms_and_thumbnail( $id, $args );

		return array(
			'id'    => (int) $id,
			'link'  => get_permalink( $id ),
			'status' => get_post_status( $id ),
		);
	}

	private static function run_update( string $ptype, array $args ): array {
		$id   = (int) $args['id'];
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== $ptype ) {
			throw new RuntimeException( 'Item not found.' );
		}

		$postarr = array( 'ID' => $id );
		if ( isset( $args['title'] ) ) {
			$postarr['post_title'] = sanitize_text_field( (string) $args['title'] );
		}
		if ( isset( $args['content'] ) ) {
			$postarr['post_content'] = wp_kses_post( (string) $args['content'] );
		}
		if ( isset( $args['excerpt'] ) ) {
			$postarr['post_excerpt'] = wp_kses_post( (string) $args['excerpt'] );
		}
		if ( isset( $args['status'] ) ) {
			$postarr['post_status'] = $args['status'];
		}

		$result = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON-RPC error message, not HTML output.
		}

		self::apply_meta( $id, $args['meta'] ?? null );
		self::apply_terms_and_thumbnail( $id, $args );

		return array(
			'id'     => $id,
			'link'   => get_permalink( $id ),
			'status' => get_post_status( $id ),
		);
	}

	private static function run_delete( string $ptype, int $id, bool $force ): array {
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== $ptype ) {
			throw new RuntimeException( 'Item not found.' );
		}
		$deleted = wp_delete_post( $id, $force );
		if ( ! $deleted ) {
			throw new RuntimeException( 'Delete failed.' );
		}
		return array(
			'id'      => $id,
			'deleted' => true,
			'force'   => $force,
		);
	}

	private static function run_media_list( array $args ): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				's'              => isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '',
				'posts_per_page' => self::per_page( $args ),
				'paged'          => self::page( $args ),
				'fields'         => 'ids',
			)
		);
		$items = array();
		foreach ( $query->posts as $attachment_id ) {
			$items[] = array(
				'id'    => (int) $attachment_id,
				'title' => get_the_title( $attachment_id ),
				'mime'  => get_post_mime_type( $attachment_id ),
				'url'   => wp_get_attachment_url( $attachment_id ),
			);
		}
		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
			'page'  => self::page( $args ),
		);
	}

	private static function run_media_get( int $id ): array {
		if ( 'attachment' !== get_post_type( $id ) ) {
			throw new RuntimeException( 'Media item not found.' );
		}
		return array(
			'id'      => $id,
			'title'   => get_the_title( $id ),
			'mime'    => get_post_mime_type( $id ),
			'url'     => wp_get_attachment_url( $id ),
			'alt'     => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption' => wp_get_attachment_caption( $id ),
		);
	}

	private static function run_media_upload( array $args ): array {
		$url = esc_url_raw( (string) $args['url'] );
		if ( ! $url || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
			throw new InvalidArgumentException( 'A valid http(s) URL is required.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			throw new RuntimeException( 'Download failed: ' . $tmp->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON-RPC error message, not HTML output.
		}

		$file_array = array(
			'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ?: 'download' ),
			'tmp_name' => $tmp,
		);
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;

		$attachment_id = media_handle_sideload( $file_array, $post_id, isset( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : null );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			throw new RuntimeException( 'Sideload failed: ' . $attachment_id->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON-RPC error message, not HTML output.
		}

		if ( isset( $args['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $args['alt'] ) );
		}

		return array(
			'id'  => (int) $attachment_id,
			'url' => wp_get_attachment_url( $attachment_id ),
		);
	}

	/**
	 * Direct base64 image upload. Detection is by magic bytes, never by the
	 * claimed filename or mime; the stored extension is forced to match.
	 */
	private static function run_media_upload_base64( array $args ): array {
		$max_bytes = 5 * 1024 * 1024; // 5 MB decoded.

		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		if ( $post_id && ! get_post( $post_id ) ) {
			throw new InvalidArgumentException( 'post_id must be the ID of an existing post.' );
		}

		$data = (string) $args['data'];
		if ( str_starts_with( $data, 'data:' ) && str_contains( $data, ',' ) ) {
			$data = substr( $data, strpos( $data, ',' ) + 1 );
		}
		$data = preg_replace( '/\s+/', '', $data );
		if ( strlen( $data ) > (int) ( $max_bytes * 4 / 3 ) + 8 ) {
			throw new InvalidArgumentException( 'File too large: 5 MB maximum after base64 decoding.' );
		}
		$binary = base64_decode( $data, true );
		if ( false === $binary || '' === $binary ) {
			throw new InvalidArgumentException( 'data must be valid base64-encoded file content.' );
		}
		if ( strlen( $binary ) > $max_bytes ) {
			throw new InvalidArgumentException( 'File too large: 5 MB maximum after base64 decoding.' );
		}

		$mime = self::detect_image_mime( $binary );
		if ( null === $mime ) {
			throw new InvalidArgumentException( 'Unsupported file type. Only JPEG, PNG, GIF and WebP images are accepted (detected from content, not from the filename).' );
		}
		$ext_map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);

		$filename = sanitize_file_name( (string) $args['filename'] );
		$base     = preg_replace( '/\.[a-z0-9]+$/i', '', $filename );
		$filename = ( '' !== $base ? $base : 'upload' ) . '.' . $ext_map[ $mime ];

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$upload = wp_upload_bits( $filename, null, $binary );
		if ( ! empty( $upload['error'] ) ) {
			throw new RuntimeException( 'Upload failed: ' . $upload['error'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON-RPC error message, not HTML output.
		}

		$title         = isset( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : $base;
		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => $title,
				'post_mime_type' => $mime,
				'post_status'    => 'inherit',
				'post_parent'    => $post_id,
			),
			$upload['file'],
			$post_id
		);
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			wp_delete_file( $upload['file'] );
			throw new RuntimeException( 'Upload failed: could not create the attachment.' );
		}
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

		if ( isset( $args['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $args['alt'] ) );
		}
		if ( ! empty( $args['set_featured'] ) && $post_id ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}

		return array(
			'id'           => (int) $attachment_id,
			'url'          => wp_get_attachment_url( $attachment_id ),
			'mime'         => $mime,
			'attached_to'  => $post_id ? $post_id : null,
			'featured_set' => ! empty( $args['set_featured'] ) && $post_id ? true : false,
		);
	}

	/**
	 * Image type from magic bytes. Returns null for anything that is not a
	 * JPEG, PNG, GIF or WebP image.
	 */
	private static function detect_image_mime( string $binary ): ?string {
		if ( str_starts_with( $binary, "\xFF\xD8\xFF" ) ) {
			return 'image/jpeg';
		}
		if ( str_starts_with( $binary, "\x89PNG\r\n\x1a\n" ) ) {
			return 'image/png';
		}
		if ( str_starts_with( $binary, 'GIF87a' ) || str_starts_with( $binary, 'GIF89a' ) ) {
			return 'image/gif';
		}
		if ( strlen( $binary ) >= 12 && 'RIFF' === substr( $binary, 0, 4 ) && 'WEBP' === substr( $binary, 8, 4 ) ) {
			return 'image/webp';
		}
		return null;
	}

	/**
	 * Category/tag tools for public taxonomies.
	 *
	 * @return array<string,MCP_Tool>
	 */
	private static function term_tools(): array {
		$tax_prop = array( 'type' => 'string', 'description' => 'Taxonomy slug, e.g. "category" or "post_tag".' );
		return array(
			'terms_list'   => new MCP_Tool(
				'terms_list',
				'List taxonomy terms',
				'List terms of a public taxonomy (categories, tags, or any registered public taxonomy).',
				'site',
				array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy' => $tax_prop,
						'search'   => array( 'type' => 'string' ),
						'per_page' => array( 'type' => 'integer', 'description' => 'Items per page (1-100, default 100).' ),
					),
					'required'   => array( 'taxonomy' ),
				),
				fn( array $args ) => self::run_terms_list( $args ),
				fn( array $args ) => current_user_can( 'read' )
			),
			'terms_create' => new MCP_Tool(
				'terms_create',
				'Create taxonomy term',
				'Create a term (category, tag, or any public taxonomy the user may manage).',
				'site',
				array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy'    => $tax_prop,
						'name'        => array( 'type' => 'string', 'description' => 'Term name.' ),
						'slug'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
					),
					'required'   => array( 'taxonomy', 'name' ),
				),
				fn( array $args ) => self::run_terms_create( $args ),
				function ( array $args ) {
					$tax = get_taxonomy( sanitize_key( (string) ( $args['taxonomy'] ?? '' ) ) );
					if ( ! $tax || ! $tax->public ) {
						return true; // Unknown taxonomies fall through to the runner's explicit error.
					}
					return current_user_can( $tax->cap->manage_terms );
				}
			),
		);
	}

	private static function run_terms_list( array $args ): array {
		$tax = get_taxonomy( sanitize_key( (string) $args['taxonomy'] ) );
		if ( ! $tax || ! $tax->public ) {
			throw new RuntimeException( 'Unknown or non-public taxonomy: ' . (string) $args['taxonomy'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON-RPC error message, not HTML output.
		}
		$query = array(
			'taxonomy'   => $tax->name,
			'hide_empty' => false,
			'number'     => self::per_page( $args ),
		);
		if ( ! empty( $args['search'] ) ) {
			$query['search'] = sanitize_text_field( (string) $args['search'] );
		}
		$terms = get_terms( $query );
		if ( is_wp_error( $terms ) ) {
			throw new RuntimeException( $terms->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON-RPC error message, not HTML output.
		}
		return array(
			'items' => array_map(
				fn( $term ) => array(
					'id'          => (int) $term->term_id,
					'name'        => $term->name,
					'slug'        => $term->slug,
					'count'       => (int) $term->count,
					'description' => (string) $term->description,
				),
				(array) $terms
			),
		);
	}

	private static function run_terms_create( array $args ): array {
		$tax = get_taxonomy( sanitize_key( (string) $args['taxonomy'] ) );
		if ( ! $tax || ! $tax->public ) {
			throw new RuntimeException( 'Unknown or non-public taxonomy: ' . (string) $args['taxonomy'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON-RPC error message, not HTML output.
		}
		$extra = array();
		if ( ! empty( $args['slug'] ) ) {
			$extra['slug'] = sanitize_title( (string) $args['slug'] );
		}
		if ( ! empty( $args['description'] ) ) {
			$extra['description'] = sanitize_textarea_field( (string) $args['description'] );
		}
		$result = wp_insert_term( sanitize_text_field( (string) $args['name'] ), $tax->name, $extra );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON-RPC error message, not HTML output.
		}
		return array(
			'id'   => (int) $result['term_id'],
			'name' => (string) $args['name'],
		);
	}

	private static function run_users_list( array $args ): array {
		$query_args = array(
			'number' => self::per_page( $args ),
			'paged'  => self::page( $args ),
			// Full WP_User objects: a restricted field list leaves roles empty.
		);
		if ( ! empty( $args['search'] ) ) {
			$query_args['search'] = '*' . sanitize_text_field( (string) $args['search'] ) . '*';
		}
		if ( ! empty( $args['role'] ) ) {
			$query_args['role'] = sanitize_key( (string) $args['role'] );
		}

		$query = new WP_User_Query( $query_args );
		return array(
			'items' => array_map(
				fn( $user ) => array(
					'id'           => (int) $user->ID,
					'login'        => $user->user_login,
					'display_name' => $user->display_name,
					'email'        => $user->user_email,
					'roles'        => (array) $user->roles,
					'registered'   => $user->user_registered,
				),
				$query->get_results()
			),
			'total' => (int) $query->get_total(),
			'page'  => self::page( $args ),
		);
	}

	private static function run_users_get( int $id ): array {
		$user = get_userdata( $id );
		if ( ! $user ) {
			throw new RuntimeException( 'User not found.' );
		}
		return array(
			'id'           => (int) $user->ID,
			'login'        => $user->user_login,
			'display_name' => $user->display_name,
			'email'        => $user->user_email,
			'roles'        => (array) $user->roles,
			'registered'   => $user->user_registered,
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private static function post_summary( WP_Post $post ): array {
		// Drafts carry a zero post_date_gmt; fall back to the local date.
		$gmt  = (string) $post->post_date_gmt;
		$date = ( '' !== $gmt && '0000-00-00 00:00:00' !== $gmt ) ? mysql2date( 'c', $gmt ) : mysql2date( 'c', (string) $post->post_date );
		return array(
			'id'     => (int) $post->ID,
			'title'  => get_the_title( $post ),
			'status' => $post->post_status,
			'date'   => $date,
			'link'   => get_permalink( $post ),
		);
	}

	/**
	 * Public meta only: skip "_" prefixed keys so internal/protected meta
	 * (oEmbed caches, edit locks, thumbnail ids...) never leaks to agents.
	 */
	private static function public_meta( int $post_id ): array {
		$out = array();
		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			if ( str_starts_with( (string) $key, '_' ) ) {
				continue;
			}
			$out[ $key ] = 1 === count( $values ) ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
		}
		return $out;
	}

	/**
	 * Fail fast when a write carries protected meta keys.
	 */
	private static function assert_meta_allowed( $meta ): void {
		if ( ! is_array( $meta ) ) {
			return;
		}
		$rejected = array();
		foreach ( array_keys( $meta ) as $key ) {
			if ( str_starts_with( (string) $key, '_' ) ) {
				$rejected[] = (string) $key;
			}
		}
		if ( $rejected ) {
			throw new RuntimeException( 'Protected meta keys rejected (keys starting with "_" are reserved for WordPress internals): ' . implode( ', ', $rejected ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON-RPC error message, not HTML output.
		}
	}

	private static function apply_meta( int $post_id, $meta ): void {
		if ( ! is_array( $meta ) ) {
			return;
		}
		self::assert_meta_allowed( $meta );
		foreach ( $meta as $key => $value ) {
			$key = (string) $key;
			if ( ! is_scalar( $value ) ) {
				$value = wp_json_encode( $value );
			}
			update_post_meta( $post_id, sanitize_text_field( $key ), sanitize_textarea_field( (string) $value ) );
		}
	}

	/**
	 * Set featured image and taxonomy terms from tool arguments.
	 */
	private static function apply_terms_and_thumbnail( int $post_id, array $args ): void {
		if ( isset( $args['featured_media'] ) ) {
			$attachment = get_post( (int) $args['featured_media'] );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				throw new RuntimeException( 'featured_media must be the ID of an existing media item.' );
			}
			set_post_thumbnail( $post_id, (int) $attachment->ID );
		}
		if ( isset( $args['terms'] ) && is_array( $args['terms'] ) ) {
			foreach ( $args['terms'] as $taxonomy => $term_ids ) {
				$taxonomy = sanitize_key( (string) $taxonomy );
				$tax      = get_taxonomy( $taxonomy );
				if ( ! $tax || ! $tax->public ) {
					throw new RuntimeException( 'Unknown or non-public taxonomy: ' . $taxonomy ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON-RPC error message, not HTML output.
				}
				wp_set_object_terms( $post_id, array_map( 'intval', (array) $term_ids ), $taxonomy, false );
			}
		}
	}

	private static function per_page( array $args ): int {
		return min( 100, max( 1, (int) ( $args['per_page'] ?? 10 ) ) );
	}

	private static function page( array $args ): int {
		return max( 1, (int) ( $args['page'] ?? 1 ) );
	}
}


