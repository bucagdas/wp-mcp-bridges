<?php
/**
 * Product category/tag assignment and image assignment abilities.
 *
 * Native product-create/product-update abilities cover a narrow field
 * set (name, sku, description, price, stock) and have no way to assign
 * a product's categories, tags, featured image or gallery. This fills
 * that gap.
 * It does not duplicate product creation/pricing/stock, which the
 * native abilities and update-product-category/tag already cover.
 *
 * @package WCMCPAbility
 */

namespace WCMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProductTaxonomyImages {

	public static function register(): void {

		wp_register_ability(
			'wc-mcp/assign-product-terms',
			array(
				'label'               => __( 'Assign product categories/tags', 'wc-mcp-ability' ),
				'description'         => __( 'Sets a product\'s category and/or tag assignments. Each of category_ids/tag_ids, when given, REPLACES the full existing assignment for that taxonomy (not additive) — pass the complete desired list, including ids to keep. At least one of category_ids/tag_ids is required. Every id is validated to exist in the correct taxonomy before anything is written; the whole call is rejected if any id is invalid.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'           => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Product id.',
						),
						'category_ids' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Full replacement list of product_cat term ids. Empty array clears all categories.',
						),
						'tag_ids'      => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Full replacement list of product_tag term ids. Empty array clears all tags.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id" and "updated": per-field {old, new} (each a list of {id, name, slug}).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_assign_terms' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'wc-mcp/update-product-images',
			array(
				'label'               => __( 'Set product featured image/gallery', 'wc-mcp-ability' ),
				'description'         => __( 'Sets a product\'s featured image and/or gallery images. image_id: an existing media attachment id, or 0 to remove the featured image. gallery_image_ids, when given, REPLACES the full existing gallery (not additive) — pass the complete desired list, including ids to keep; empty array clears the gallery. Every id is validated to be a real image attachment before anything is written.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'                => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Product id.',
						),
						'image_id'          => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Featured image attachment id. 0 removes it.',
						),
						'gallery_image_ids' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Full replacement list of gallery attachment ids.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id" and "updated": per-field {old, new}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update_images' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	public static function cb_assign_terms( $input ) {
		if ( ! array_key_exists( 'category_ids', $input ) && ! array_key_exists( 'tag_ids', $input ) ) {
			return new \WP_Error( 'no_fields', __( 'Provide at least one of category_ids or tag_ids.', 'wc-mcp-ability' ) );
		}

		$product_id = (int) $input['id'];
		$product    = wc_get_product( $product_id );
		if ( ! $product ) {
			return new \WP_Error( 'product_not_found', __( 'No product exists with the given ID.', 'wc-mcp-ability' ) );
		}

		$updated = array();

		if ( array_key_exists( 'category_ids', $input ) ) {
			$ids = array_map( 'intval', (array) $input['category_ids'] );
			$err = self::validate_term_ids( $ids, 'product_cat' );
			if ( is_wp_error( $err ) ) {
				return $err;
			}
			$updated['category_ids'] = array( 'old' => self::format_terms( $product->get_category_ids(), 'product_cat' ) );
			$product->set_category_ids( $ids );
		}

		if ( array_key_exists( 'tag_ids', $input ) ) {
			$ids = array_map( 'intval', (array) $input['tag_ids'] );
			$err = self::validate_term_ids( $ids, 'product_tag' );
			if ( is_wp_error( $err ) ) {
				return $err;
			}
			$updated['tag_ids'] = array( 'old' => self::format_terms( $product->get_tag_ids(), 'product_tag' ) );
			$product->set_tag_ids( $ids );
		}

		$product->save();
		$fresh = wc_get_product( $product_id );

		if ( isset( $updated['category_ids'] ) ) {
			$updated['category_ids']['new'] = self::format_terms( $fresh->get_category_ids(), 'product_cat' );
		}
		if ( isset( $updated['tag_ids'] ) ) {
			$updated['tag_ids']['new'] = self::format_terms( $fresh->get_tag_ids(), 'product_tag' );
		}

		return array(
			'id'      => $product_id,
			'updated' => $updated,
		);
	}

	public static function cb_update_images( $input ) {
		if ( ! array_key_exists( 'image_id', $input ) && ! array_key_exists( 'gallery_image_ids', $input ) ) {
			return new \WP_Error( 'no_fields', __( 'Provide at least one of image_id or gallery_image_ids.', 'wc-mcp-ability' ) );
		}

		$product_id = (int) $input['id'];
		$product    = wc_get_product( $product_id );
		if ( ! $product ) {
			return new \WP_Error( 'product_not_found', __( 'No product exists with the given ID.', 'wc-mcp-ability' ) );
		}

		$updated = array();

		if ( array_key_exists( 'image_id', $input ) ) {
			$image_id = (int) $input['image_id'];
			if ( $image_id > 0 ) {
				$err = self::validate_attachment_ids( array( $image_id ) );
				if ( is_wp_error( $err ) ) {
					return $err;
				}
			}
			$updated['image_id'] = array( 'old' => (int) $product->get_image_id() );
			$product->set_image_id( $image_id ?: '' );
		}

		if ( array_key_exists( 'gallery_image_ids', $input ) ) {
			$ids = array_map( 'intval', (array) $input['gallery_image_ids'] );
			$err = self::validate_attachment_ids( $ids );
			if ( is_wp_error( $err ) ) {
				return $err;
			}
			$updated['gallery_image_ids'] = array( 'old' => array_map( 'intval', $product->get_gallery_image_ids() ) );
			$product->set_gallery_image_ids( $ids );
		}

		$product->save();
		$fresh = wc_get_product( $product_id );

		if ( isset( $updated['image_id'] ) ) {
			$updated['image_id']['new'] = (int) $fresh->get_image_id();
		}
		if ( isset( $updated['gallery_image_ids'] ) ) {
			$updated['gallery_image_ids']['new'] = array_map( 'intval', $fresh->get_gallery_image_ids() );
		}

		return array(
			'id'      => $product_id,
			'updated' => $updated,
		);
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * @return true|\WP_Error
	 */
	private static function validate_term_ids( array $ids, string $taxonomy ) {
		foreach ( $ids as $id ) {
			$term = get_term( $id, $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				return new \WP_Error(
					'invalid_term',
					sprintf(
						/* translators: 1: term id, 2: taxonomy name */
						__( 'No %2$s term exists with id %1$d.', 'wc-mcp-ability' ),
						$id,
						$taxonomy
					)
				);
			}
		}
		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function validate_attachment_ids( array $ids ) {
		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) ) {
				return new \WP_Error(
					'invalid_attachment',
					sprintf(
						/* translators: %d: attachment id */
						__( 'No media attachment exists with id %d.', 'wc-mcp-ability' ),
						$id
					)
				);
			}
		}
		return true;
	}

	private static function format_terms( array $ids, string $taxonomy ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$term = get_term( $id, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$out[] = array( 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug );
			}
		}
		return $out;
	}
}
