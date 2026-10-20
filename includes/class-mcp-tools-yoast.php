<?php
/**
 * Yoast SEO adapter: read/write SEO meta through Yoast's own storage keys.
 *
 * Yoast keeps its fields in protected ("_" prefixed) post meta, which the
 * generic post tools deliberately refuse to touch. This adapter exposes the
 * safe, documented subset instead. Registered only when Yoast SEO is active.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Yoast meta tools.
 */
class MCP_Tools_Yoast {

	/**
	 * Yoast storage keys for the supported fields.
	 */
	private const KEYS = array(
		'title'            => '_yoast_wpseo_title',
		'meta_description' => '_yoast_wpseo_metadesc',
		'focus_keyphrase'  => '_yoast_wpseo_focuskw',
		'canonical'        => '_yoast_wpseo_canonical',
	);

	/**
	 * Whether Yoast SEO is active on this site.
	 */
	public static function is_active(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
	}

	/**
	 * Tool map.
	 *
	 * @return array<string,MCP_Tool>
	 */
	public static function discover(): array {
		$id_prop = array( 'type' => 'integer', 'description' => 'Post, page or CPT item ID.' );

		return array(
			'yoast_meta_get' => new MCP_Tool(
				'yoast_meta_get',
				'Get Yoast SEO meta',
				'Read the Yoast SEO fields of a post: SEO title, meta description, focus keyphrase, canonical URL and noindex flag.',
				'seo',
				array(
					'type'       => 'object',
					'properties' => array( 'id' => $id_prop ),
					'required'   => array( 'id' ),
				),
				fn( array $args ) => self::run_get( (int) $args['id'] ),
				fn( array $args ) => self::can_edit( (int) ( $args['id'] ?? 0 ) )
			),
			'yoast_meta_set' => new MCP_Tool(
				'yoast_meta_set',
				'Set Yoast SEO meta',
				'Update Yoast SEO fields of a post. Only the fields you pass are changed. noindex=true marks the post noindex; noindex=false restores indexing.',
				'seo',
				array(
					'type'       => 'object',
					'properties' => array(
						'id'               => $id_prop,
						'title'            => array( 'type' => 'string', 'description' => 'SEO title.' ),
						'meta_description' => array( 'type' => 'string', 'description' => 'Meta description.' ),
						'focus_keyphrase'  => array( 'type' => 'string', 'description' => 'Focus keyphrase.' ),
						'canonical'        => array( 'type' => 'string', 'description' => 'Canonical URL (http/https).' ),
						'noindex'          => array( 'type' => 'boolean', 'description' => 'true = noindex this post, false = allow indexing.' ),
					),
					'required'   => array( 'id' ),
				),
				fn( array $args ) => self::run_set( $args ),
				fn( array $args ) => self::can_edit( (int) ( $args['id'] ?? 0 ) )
			),
		);
	}

	/**
	 * Edit capability for the target post.
	 */
	private static function can_edit( int $id ): bool {
		$post = get_post( $id );
		if ( ! $post ) {
			return true; // Missing items fall through to the runner's not-found error.
		}
		$obj = get_post_type_object( $post->post_type );
		return $obj ? current_user_can( $obj->cap->edit_post, $id ) : false;
	}

	private static function run_get( int $id ): array {
		$post = get_post( $id );
		if ( ! $post ) {
			throw new RuntimeException( 'Item not found.' );
		}
		$out = array( 'id' => $id );
		foreach ( self::KEYS as $field => $key ) {
			$out[ $field ] = (string) get_post_meta( $id, $key, true );
		}
		$out['noindex'] = '1' === get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true );
		return $out;
	}

	private static function run_set( array $args ): array {
		$id   = (int) $args['id'];
		$post = get_post( $id );
		if ( ! $post ) {
			throw new RuntimeException( 'Item not found.' );
		}
		$updated = array();
		foreach ( self::KEYS as $field => $key ) {
			if ( ! array_key_exists( $field, $args ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $args[ $field ] );
			if ( 'canonical' === $field && '' !== $value ) {
				$value = esc_url_raw( $value );
				if ( ! $value || ! in_array( wp_parse_url( $value, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
					throw new InvalidArgumentException( 'canonical must be a valid http(s) URL.' );
				}
			}
			update_post_meta( $id, $key, $value );
			$updated[] = $field;
		}
		if ( array_key_exists( 'noindex', $args ) ) {
			if ( ! empty( $args['noindex'] ) ) {
				update_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', '1' );
			} else {
				delete_post_meta( $id, '_yoast_wpseo_meta-robots-noindex' );
			}
			$updated[] = 'noindex';
		}
		if ( ! $updated ) {
			throw new InvalidArgumentException( 'Nothing to update: pass at least one field.' );
		}
		return array(
			'id'      => $id,
			'updated' => $updated,
		);
	}
}


