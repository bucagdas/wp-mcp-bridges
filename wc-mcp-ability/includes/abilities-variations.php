<?php
/**
 * Product variation CRUD abilities (for variable products).
 *
 * Native WooCommerce ability coverage (product-create/product-update)
 * explicitly excludes the "variable" product type and has no variation
 * surface at all (see HEDEF-SURUM.md's native-ability inventory) —
 * this fills that gap.
 *
 * @package WCMCPAbility
 */

namespace WCMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Variations {

	/** input field => WC_Product_Variation setter suffix. */
	const FIELDS = array(
		'sku'             => 'sku',
		'regular_price'   => 'regular_price',
		'sale_price'      => 'sale_price',
		'description'     => 'description',
		'manage_stock'    => 'manage_stock',
		'stock_quantity'  => 'stock_quantity',
		'stock_status'    => 'stock_status',
		'weight'          => 'weight',
		'length'          => 'length',
		'width'           => 'width',
		'height'          => 'height',
	);

	const BOOLEAN_FIELDS = array( 'manage_stock' );
	const INTEGER_FIELDS = array( 'stock_quantity' );

	public static function register(): void {

		wp_register_ability(
			'wc-mcp/list-product-variations',
			array(
				'label'               => __( 'List product variations', 'wc-mcp-ability' ),
				'description'         => __( 'Lists the variations of a variable product, with id, sku, price, stock and attributes per variation.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'product_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Parent (variable) product id.',
						),
					),
					'required'             => array( 'product_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "product_id" and "variations" (array of summary objects).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wc-mcp/get-product-variation',
			array(
				'label'               => __( 'Get a product variation', 'wc-mcp-ability' ),
				'description'         => __( 'Returns full detail for one product variation by id.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Variation id.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Full variation detail.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wc-mcp/create-product-variation',
			array(
				'label'               => __( 'Create a product variation', 'wc-mcp-ability' ),
				'description'         => __( 'Creates a new variation under an existing variable product. product_id must reference a product of type "variable" (create/update-product-category do not create variable products — use wc-request for that). attributes is required: a map of attribute name to value, e.g. {"color": "Blue", "size": "Large"} (use the taxonomy slug, e.g. "pa_color", for global attributes). Both keys and values are normalized to lowercase slugs before saving (matching how the parent product\'s own attribute options are stored) — "Blue" and "blue" are equivalent input. All pricing/stock fields are optional.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => self::input_properties( true ),
					'required'             => array( 'product_id', 'attributes' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'The created variation.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_create' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, false, false ),
			)
		);

		wp_register_ability(
			'wc-mcp/update-product-variation',
			array(
				'label'               => __( 'Update a product variation', 'wc-mcp-ability' ),
				'description'         => __( 'Updates one or more fields of an existing product variation identified by id. Provide only the fields to change. Returns {old,new} per changed field, read back after the write.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => self::input_properties( false ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id" and "updated": per-field {old, new}.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'wc-mcp/delete-product-variation',
			array(
				'label'               => __( 'Delete a product variation', 'wc-mcp-ability' ),
				'description'         => __( 'Permanently deletes a product variation identified by id. Requires confirm: true.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Variation id to delete.',
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
					'description' => 'Object with "id", "old" (the deleted variation) and "new" (null).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_delete' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);
	}

	private static function input_properties( bool $for_create ): array {
		$properties = array();
		if ( $for_create ) {
			$properties['product_id'] = array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Parent (variable) product id.',
			);
			$properties['attributes'] = array(
				'type'        => 'object',
				'description' => 'Map of attribute name to value, e.g. {"color": "Blue"}.',
			);
			$properties['status'] = array(
				'type'        => 'string',
				'enum'        => array( 'publish', 'private' ),
				'default'     => 'publish',
				'description' => 'Default "publish". "private" hides the variation from purchase without deleting it.',
			);
		} else {
			$properties['id']         = array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Variation id to update.',
			);
			$properties['attributes'] = array(
				'type'        => 'object',
				'description' => 'Replaces the full attribute map if given. Keys/values are normalized to lowercase slugs, same as create-product-variation.',
			);
			$properties['status'] = array(
				'type' => 'string',
				'enum' => array( 'publish', 'private' ),
			);
		}

		$properties['sku']            = array( 'type' => 'string' );
		$properties['regular_price']  = array( 'type' => 'string' );
		$properties['sale_price']     = array( 'type' => 'string' );
		$properties['description']    = array( 'type' => 'string' );
		$properties['manage_stock']   = array( 'type' => 'boolean' );
		$properties['stock_quantity'] = array( 'type' => 'integer' );
		$properties['stock_status']   = array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) );
		$properties['weight']         = array( 'type' => 'string' );
		$properties['length']         = array( 'type' => 'string' );
		$properties['width']          = array( 'type' => 'string' );
		$properties['height']         = array( 'type' => 'string' );

		return $properties;
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	public static function cb_list( $input ) {
		$product_id = (int) $input['product_id'];
		$product    = wc_get_product( $product_id );
		if ( ! $product ) {
			return new \WP_Error( 'product_not_found', __( 'No product exists with the given ID.', 'wc-mcp-ability' ) );
		}
		if ( ! $product->is_type( 'variable' ) ) {
			return new \WP_Error( 'not_variable', __( 'This product is not a variable product and has no variations.', 'wc-mcp-ability' ) );
		}

		$variations = array();
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation ) {
				$variations[] = self::format_summary( $variation );
			}
		}

		return array(
			'product_id' => $product_id,
			'variations' => $variations,
		);
	}

	public static function cb_get( $input ) {
		$variation = self::load( (int) $input['id'] );
		if ( is_wp_error( $variation ) ) {
			return $variation;
		}
		return self::format_full( $variation );
	}

	public static function cb_create( $input ) {
		$product_id = (int) $input['product_id'];
		$product    = wc_get_product( $product_id );
		if ( ! $product ) {
			return new \WP_Error( 'product_not_found', __( 'No product exists with the given ID.', 'wc-mcp-ability' ) );
		}
		if ( ! $product->is_type( 'variable' ) ) {
			return new \WP_Error( 'not_variable', __( 'product_id must reference a product of type "variable".', 'wc-mcp-ability' ) );
		}

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_status( isset( $input['status'] ) ? (string) $input['status'] : 'publish' );
		$variation->set_attributes( self::sanitize_attributes( (array) $input['attributes'] ) );
		self::apply_fields( $variation, $input );
		$variation->save();

		// Variable products cache their child variation list; refresh it
		// so list-product-variations sees the new one immediately.
		wc_delete_product_transients( $product_id );

		return self::format_full( new \WC_Product_Variation( $variation->get_id() ) );
	}

	public static function cb_update( $input ) {
		$variation = self::load( (int) $input['id'] );
		if ( is_wp_error( $variation ) ) {
			return $variation;
		}

		$updated = array();

		if ( isset( $input['attributes'] ) ) {
			$updated['attributes'] = array( 'old' => $variation->get_attributes() );
			$variation->set_attributes( self::sanitize_attributes( (array) $input['attributes'] ) );
		}
		if ( isset( $input['status'] ) ) {
			$updated['status'] = array( 'old' => $variation->get_status() );
			$variation->set_status( (string) $input['status'] );
		}
		foreach ( self::FIELDS as $in_key => $suffix ) {
			if ( ! array_key_exists( $in_key, $input ) ) {
				continue;
			}
			$getter             = "get_{$suffix}";
			$setter             = "set_{$suffix}";
			$updated[ $in_key ] = array( 'old' => $variation->$getter() );
			$variation->$setter( self::cast_field( $in_key, $input[ $in_key ] ) );
		}

		if ( empty( $updated ) ) {
			return new \WP_Error( 'no_fields', __( 'Provide at least one field to change.', 'wc-mcp-ability' ) );
		}

		$variation->save();
		wc_delete_product_transients( $variation->get_parent_id() );

		$fresh = new \WC_Product_Variation( $variation->get_id() );
		foreach ( array_keys( $updated ) as $field ) {
			if ( 'attributes' === $field ) {
				$updated[ $field ]['new'] = $fresh->get_attributes();
			} elseif ( 'status' === $field ) {
				$updated[ $field ]['new'] = $fresh->get_status();
			} else {
				$getter                   = 'get_' . self::FIELDS[ $field ];
				$updated[ $field ]['new'] = $fresh->$getter();
			}
		}

		return array(
			'id'      => $fresh->get_id(),
			'updated' => $updated,
		);
	}

	public static function cb_delete( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to delete a product variation.', 'wc-mcp-ability' ) );
		}

		$variation = self::load( (int) $input['id'] );
		if ( is_wp_error( $variation ) ) {
			return $variation;
		}

		$old        = self::format_summary( $variation );
		$parent_id  = $variation->get_parent_id();
		$result     = $variation->delete( true );
		wc_delete_product_transients( $parent_id );

		if ( ! $result ) {
			return new \WP_Error( 'delete_failed', __( 'The variation could not be deleted.', 'wc-mcp-ability' ) );
		}

		return array(
			'id'  => $old['id'],
			'old' => $old,
			'new' => null,
		);
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * @return \WC_Product_Variation|\WP_Error
	 */
	private static function load( int $id ) {
		if ( $id <= 0 || 'product_variation' !== get_post_type( $id ) ) {
			return new \WP_Error( 'variation_not_found', __( 'No product variation exists with the given ID.', 'wc-mcp-ability' ) );
		}
		return new \WC_Product_Variation( $id );
	}

	private static function apply_fields( \WC_Product_Variation $variation, array $input ): void {
		foreach ( self::FIELDS as $in_key => $suffix ) {
			if ( array_key_exists( $in_key, $input ) ) {
				$setter = "set_{$suffix}";
				$variation->$setter( self::cast_field( $in_key, $input[ $in_key ] ) );
			}
		}
	}

	/**
	 * WC_Product_Variation::set_attributes() stores whatever key/value
	 * strings it's given verbatim as post meta ("attribute_{key}" =>
	 * value), but the rest of WooCommerce (variation matching at
	 * add-to-cart, and — found by testing this ability — even a fresh
	 * WC_Product_Variation::get_attributes() read of the SAME variation
	 * right after save()) only ever looks them up in sanitize_title()
	 * form (lowercase slug). Passing the raw display value ("Kirmizi")
	 * through unsanitized silently round-trips to an EMPTY value on
	 * reload, because the data store's read path checks for the
	 * lowercase meta key ("attribute_renk") which was never written.
	 * wp-admin's own variations metabox always submits pre-sanitized
	 * values for exactly this reason; this mirrors that.
	 */
	private static function sanitize_attributes( array $attrs ): array {
		$out = array();
		foreach ( $attrs as $key => $value ) {
			$out[ sanitize_title( (string) $key ) ] = sanitize_title( (string) $value );
		}
		return $out;
	}

	private static function cast_field( string $field, $value ) {
		if ( in_array( $field, self::BOOLEAN_FIELDS, true ) ) {
			return (bool) $value;
		}
		if ( in_array( $field, self::INTEGER_FIELDS, true ) ) {
			return '' === $value ? '' : (int) $value;
		}
		return (string) $value;
	}

	private static function format_summary( \WC_Product_Variation $variation ): array {
		return array(
			'id'             => $variation->get_id(),
			'sku'            => $variation->get_sku(),
			'status'         => $variation->get_status(),
			'attributes'     => $variation->get_attributes(),
			'regular_price'  => $variation->get_regular_price(),
			'sale_price'     => $variation->get_sale_price(),
			'stock_status'   => $variation->get_stock_status(),
			'stock_quantity' => $variation->get_stock_quantity(),
		);
	}

	private static function format_full( \WC_Product_Variation $variation ): array {
		$summary                  = self::format_summary( $variation );
		$summary['parent_id']     = $variation->get_parent_id();
		$summary['description']   = $variation->get_description();
		$summary['manage_stock']  = $variation->get_manage_stock();
		$summary['weight']        = $variation->get_weight();
		$summary['length']        = $variation->get_length();
		$summary['width']         = $variation->get_width();
		$summary['height']        = $variation->get_height();
		return $summary;
	}
}
