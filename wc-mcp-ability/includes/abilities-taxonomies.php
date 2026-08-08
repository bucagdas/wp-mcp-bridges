<?php
/**
 * Product category and product tag CRUD abilities.
 *
 * @package WCMCPAbility
 */

namespace WCMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Taxonomies {

	public static function register(): void {

		// 1. List product categories (read-only).
		wp_register_ability(
			'wc-mcp/list-product-categories',
			array(
				'label'               => __( 'List product categories', 'wc-mcp-ability' ),
				'description'         => __( 'Returns all WooCommerce product categories with id, name, slug, parent, description and product count.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'search'   => array(
							'type'        => 'string',
							'description' => 'Optional search term to filter categories by name.',
						),
						'parent'   => array(
							'type'        => 'integer',
							'description' => 'Optional parent category id to list children of.',
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => 'Maximum number of categories to return. Default 100.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'array',
					'description' => 'List of product categories.',
					'items'       => array(
						'type' => 'object',
					),
				),
				'execute_callback'    => array( __CLASS__, 'cb_list_categories' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		// 2. Create product category.
		wp_register_ability(
			'wc-mcp/create-product-category',
			array(
				'label'               => __( 'Create product category', 'wc-mcp-ability' ),
				'description'         => __( 'Creates a new WooCommerce product category. The name is required; slug, description and parent are optional.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'name'        => array(
							'type'        => 'string',
							'description' => 'Category name.',
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => 'Optional URL slug. Auto-generated from name when omitted.',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'Optional category description.',
						),
						'parent'      => array(
							'type'        => 'integer',
							'description' => 'Optional parent category id.',
						),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'The created category.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_create_category' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, false, false ),
			)
		);

		// 3. Update product category.
		wp_register_ability(
			'wc-mcp/update-product-category',
			array(
				'label'               => __( 'Update product category', 'wc-mcp-ability' ),
				'description'         => __( 'Updates an existing WooCommerce product category identified by id. Provide only the fields to change.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'          => array(
							'type'        => 'integer',
							'description' => 'Category id to update.',
						),
						'name'        => array(
							'type'        => 'string',
							'description' => 'New name.',
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => 'New slug.',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'New description.',
						),
						'parent'      => array(
							'type'        => 'integer',
							'description' => 'New parent category id.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "old" (category state before the write) and "new" (after).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update_category' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		// 4. Delete product category (destructive).
		wp_register_ability(
			'wc-mcp/delete-product-category',
			array(
				'label'               => __( 'Delete product category', 'wc-mcp-ability' ),
				'description'         => __( 'Permanently deletes a WooCommerce product category identified by id. Requires confirm: true.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => 'Category id to delete.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true to proceed. Deletion cannot be undone.',
						),
					),
					'required'             => array( 'id', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "old" (the deleted category) and "new" (null).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_delete_category' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);

		// 5. List product tags (read-only).
		wp_register_ability(
			'wc-mcp/list-product-tags',
			array(
				'label'               => __( 'List product tags', 'wc-mcp-ability' ),
				'description'         => __( 'Returns all WooCommerce product tags with id, name, slug, description and product count.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'search'   => array(
							'type'        => 'string',
							'description' => 'Optional search term to filter tags by name.',
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => 'Maximum number of tags to return. Default 100.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'array',
					'description' => 'List of product tags.',
					'items'       => array(
						'type' => 'object',
					),
				),
				'execute_callback'    => array( __CLASS__, 'cb_list_tags' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		// 6. Create product tag.
		wp_register_ability(
			'wc-mcp/create-product-tag',
			array(
				'label'               => __( 'Create product tag', 'wc-mcp-ability' ),
				'description'         => __( 'Creates a new WooCommerce product tag. The name is required; slug and description are optional.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'name'        => array(
							'type'        => 'string',
							'description' => 'Tag name.',
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => 'Optional URL slug. Auto-generated from name when omitted.',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'Optional tag description.',
						),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'The created tag.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_create_tag' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, false, false ),
			)
		);

		// 7. Update product tag.
		wp_register_ability(
			'wc-mcp/update-product-tag',
			array(
				'label'               => __( 'Update product tag', 'wc-mcp-ability' ),
				'description'         => __( 'Updates an existing WooCommerce product tag identified by id. Provide only the fields to change.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'          => array(
							'type'        => 'integer',
							'description' => 'Tag id to update.',
						),
						'name'        => array(
							'type'        => 'string',
							'description' => 'New name.',
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => 'New slug.',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'New description.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "old" (tag state before the write) and "new" (after).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update_tag' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		// 8. Delete product tag (destructive).
		wp_register_ability(
			'wc-mcp/delete-product-tag',
			array(
				'label'               => __( 'Delete product tag', 'wc-mcp-ability' ),
				'description'         => __( 'Permanently deletes a WooCommerce product tag identified by id. Requires confirm: true.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => 'Tag id to delete.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true to proceed. Deletion cannot be undone.',
						),
					),
					'required'             => array( 'id', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "old" (the deleted tag) and "new" (null).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_delete_tag' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Execute callbacks
	// ---------------------------------------------------------------------

	public static function cb_list_categories( $input ) {
		return self::list_terms( $input, 'product_cat' );
	}

	public static function cb_create_category( $input ) {
		return self::create_term( $input, 'product_cat' );
	}

	public static function cb_update_category( $input ) {
		return self::update_term( $input, 'product_cat' );
	}

	public static function cb_delete_category( $input ) {
		return self::delete_term( $input, 'product_cat' );
	}

	public static function cb_list_tags( $input ) {
		return self::list_terms( $input, 'product_tag' );
	}

	public static function cb_create_tag( $input ) {
		return self::create_term( $input, 'product_tag' );
	}

	public static function cb_update_tag( $input ) {
		return self::update_term( $input, 'product_tag' );
	}

	public static function cb_delete_tag( $input ) {
		return self::delete_term( $input, 'product_tag' );
	}

	/**
	 * Shared implementation for list/create/update/delete of a WooCommerce
	 * product taxonomy term (product_cat or product_tag) — the two share
	 * an identical shape (id, name, slug, parent*, description, count),
	 * differing only in the taxonomy string. *product_tag has no "parent".
	 */
	private static function list_terms( $input, string $taxonomy ) {
		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => isset( $input['per_page'] ) ? (int) $input['per_page'] : 100,
		);
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = (string) $input['search'];
		}
		if ( 'product_cat' === $taxonomy && isset( $input['parent'] ) ) {
			$args['parent'] = (int) $input['parent'];
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$out = array();
		foreach ( $terms as $term ) {
			$out[] = self::format_term( $term, $taxonomy );
		}
		return $out;
	}

	private static function create_term( $input, string $taxonomy ) {
		if ( empty( $input['name'] ) ) {
			return new \WP_Error( 'missing_name', __( 'A name is required.', 'wc-mcp-ability' ) );
		}

		$args = array();
		if ( ! empty( $input['slug'] ) ) {
			$args['slug'] = (string) $input['slug'];
		}
		if ( ! empty( $input['description'] ) ) {
			$args['description'] = (string) $input['description'];
		}
		if ( 'product_cat' === $taxonomy && ! empty( $input['parent'] ) ) {
			$args['parent'] = (int) $input['parent'];
		}

		$res = wp_insert_term( (string) $input['name'], $taxonomy, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		return self::read_term( (int) $res['term_id'], $taxonomy );
	}

	private static function update_term( $input, string $taxonomy ) {
		if ( empty( $input['id'] ) ) {
			return new \WP_Error( 'missing_id', __( 'An id is required.', 'wc-mcp-ability' ) );
		}

		$id  = (int) $input['id'];
		$old = self::read_term( $id, $taxonomy );
		if ( is_wp_error( $old ) ) {
			return $old;
		}

		$args = array();
		foreach ( array( 'name', 'slug', 'description' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$args[ $key ] = (string) $input[ $key ];
			}
		}
		if ( 'product_cat' === $taxonomy && isset( $input['parent'] ) ) {
			$args['parent'] = (int) $input['parent'];
		}

		$res = wp_update_term( $id, $taxonomy, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		return array(
			'id'  => $id,
			'old' => $old,
			'new' => self::read_term( $id, $taxonomy ),
		);
	}

	private static function delete_term( $input, string $taxonomy ) {
		if ( empty( $input['id'] ) ) {
			return new \WP_Error( 'missing_id', __( 'An id is required.', 'wc-mcp-ability' ) );
		}
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to delete this term.', 'wc-mcp-ability' ) );
		}

		$id  = (int) $input['id'];
		$old = self::read_term( $id, $taxonomy );
		if ( is_wp_error( $old ) ) {
			return $old;
		}

		$res = wp_delete_term( $id, $taxonomy );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( false === $res || 0 === $res ) {
			return new \WP_Error( 'delete_failed', __( 'The term could not be deleted.', 'wc-mcp-ability' ) );
		}

		return array(
			'id'  => $id,
			'old' => $old,
			'new' => null,
		);
	}

	/**
	 * Read a taxonomy term by id for {old,new} read-back.
	 *
	 * @return array|\WP_Error
	 */
	private static function read_term( int $id, string $taxonomy ) {
		$term = get_term( $id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( 'not_found', __( 'No term exists with this id.', 'wc-mcp-ability' ) );
		}
		return self::format_term( $term, $taxonomy );
	}

	private static function format_term( \WP_Term $term, string $taxonomy ): array {
		$out = array(
			'id'          => (int) $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'count'       => (int) $term->count,
		);
		if ( 'product_cat' === $taxonomy ) {
			$out['parent'] = (int) $term->parent;
		}
		return $out;
	}
}
