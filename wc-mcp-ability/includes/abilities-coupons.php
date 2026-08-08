<?php
/**
 * Coupon CRUD abilities.
 *
 * WooCommerce coupons are a CPT (shop_coupon) wrapped by WC_Coupon.
 * Native WooCommerce ability coverage has no coupon surface at all —
 * this fills that gap.
 *
 * @package WCMCPAbility
 */

namespace WCMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Coupons {

	const DISCOUNT_TYPES = array( 'percent', 'fixed_cart', 'fixed_product' );

	/**
	 * input field => WC_Coupon setter suffix. Shared by create/update so
	 * both apply the same fields the same way.
	 */
	const FIELDS = array(
		'description'               => 'description',
		'discount_type'             => 'discount_type',
		'amount'                    => 'amount',
		'date_expires'              => 'date_expires',
		'individual_use'            => 'individual_use',
		'product_ids'               => 'product_ids',
		'excluded_product_ids'      => 'excluded_product_ids',
		'usage_limit'               => 'usage_limit',
		'usage_limit_per_user'      => 'usage_limit_per_user',
		'limit_usage_to_x_items'    => 'limit_usage_to_x_items',
		'free_shipping'             => 'free_shipping',
		'product_categories'        => 'product_categories',
		'excluded_product_categories' => 'excluded_product_categories',
		'exclude_sale_items'        => 'exclude_sale_items',
		'minimum_amount'            => 'minimum_amount',
		'maximum_amount'            => 'maximum_amount',
		'email_restrictions'        => 'email_restrictions',
	);

	public static function register(): void {

		wp_register_ability(
			'wc-mcp/list-coupons',
			array(
				'label'               => __( 'List coupons', 'wc-mcp-ability' ),
				'description'         => __( 'Lists WooCommerce coupons with code, discount_type, amount, status and usage_count. Filter by search term (matches code) and status.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'search'   => array(
							'type'        => 'string',
							'description' => 'Optional search term matched against the coupon code.',
						),
						'status'   => array(
							'type'        => 'string',
							'default'     => 'publish',
							'description' => 'Coupon post status filter, e.g. "publish", "draft", "any". Default "publish".',
						),
						'per_page' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 20,
							'description' => 'Maximum coupons to return. Default 20.',
						),
						'page'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'default'     => 1,
							'description' => 'Result page. Default 1.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "total" and "coupons" (array of summary objects).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wc-mcp/get-coupon',
			array(
				'label'               => __( 'Get a coupon', 'wc-mcp-ability' ),
				'description'         => __( 'Returns full coupon detail by id, including all restriction/usage fields.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Coupon id.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Full coupon detail.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wc-mcp/create-coupon',
			array(
				'label'               => __( 'Create a coupon', 'wc-mcp-ability' ),
				'description'         => __( 'Creates a new WooCommerce coupon. code is required and must not already be in use. discount_type defaults to "fixed_cart"; amount defaults to "0". All restriction/usage fields (product_ids, usage_limit, minimum_amount, email_restrictions, ...) are optional.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => self::input_properties( true ),
					'required'             => array( 'code' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'The created coupon.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_create' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, false, false ),
			)
		);

		wp_register_ability(
			'wc-mcp/update-coupon',
			array(
				'label'               => __( 'Update a coupon', 'wc-mcp-ability' ),
				'description'         => __( 'Updates one or more fields of an existing coupon identified by id. Provide only the fields to change. Returns {old,new} per changed field, read back after the write.', 'wc-mcp-ability' ),
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
			'wc-mcp/delete-coupon',
			array(
				'label'               => __( 'Delete a coupon', 'wc-mcp-ability' ),
				'description'         => __( 'Permanently deletes a coupon identified by id. Requires confirm: true. This does not affect orders that already used it.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Coupon id to delete.',
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
					'description' => 'Object with "id", "old" (the deleted coupon) and "new" (null).',
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
			$properties['code'] = array(
				'type'        => 'string',
				'description' => 'Coupon code. Must not already be in use.',
			);
		} else {
			$properties['id']   = array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Coupon id to update.',
			);
			$properties['code'] = array(
				'type'        => 'string',
				'description' => 'New coupon code. Must not already be in use by another coupon.',
			);
		}

		$properties['description']    = array( 'type' => 'string' );
		$properties['discount_type']  = array(
			'type'        => 'string',
			'enum'        => self::DISCOUNT_TYPES,
			'description' => 'percent, fixed_cart or fixed_product.',
		);
		$properties['amount']         = array( 'type' => 'string', 'description' => 'Decimal amount as a string, e.g. "10.00".' );
		$properties['date_expires']   = array( 'type' => 'string', 'description' => 'ISO 8601 date, e.g. "2026-12-31". Empty string clears it.' );
		$properties['individual_use'] = array( 'type' => 'boolean' );
		$properties['product_ids']    = array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) );
		$properties['excluded_product_ids']         = array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) );
		$properties['usage_limit']                  = array( 'type' => 'integer' );
		$properties['usage_limit_per_user']         = array( 'type' => 'integer' );
		$properties['limit_usage_to_x_items']       = array( 'type' => 'integer' );
		$properties['free_shipping']                = array( 'type' => 'boolean' );
		$properties['product_categories']           = array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) );
		$properties['excluded_product_categories']  = array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) );
		$properties['exclude_sale_items']           = array( 'type' => 'boolean' );
		$properties['minimum_amount']               = array( 'type' => 'string' );
		$properties['maximum_amount']                = array( 'type' => 'string' );
		$properties['email_restrictions']           = array( 'type' => 'array', 'items' => array( 'type' => 'string' ) );

		return $properties;
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	public static function cb_list( $input ) {
		$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$args = array(
			'post_type'      => 'shop_coupon',
			'post_status'    => isset( $input['status'] ) ? (string) $input['status'] : 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = (string) $input['search'];
		}

		$query   = new \WP_Query( $args );
		$coupons = array();
		foreach ( $query->posts as $post ) {
			$coupons[] = self::format_summary( new \WC_Coupon( $post->ID ) );
		}

		return array(
			'total'   => (int) $query->found_posts,
			'coupons' => $coupons,
		);
	}

	public static function cb_get( $input ) {
		$coupon = self::load( (int) $input['id'] );
		if ( is_wp_error( $coupon ) ) {
			return $coupon;
		}
		return self::format_full( $coupon );
	}

	public static function cb_create( $input ) {
		$code = trim( (string) $input['code'] );
		if ( '' === $code ) {
			return new \WP_Error( 'missing_code', __( 'A coupon code is required.', 'wc-mcp-ability' ) );
		}
		if ( wc_get_coupon_id_by_code( $code ) ) {
			return new \WP_Error( 'code_in_use', __( 'A coupon with this code already exists.', 'wc-mcp-ability' ) );
		}

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		self::apply_fields( $coupon, $input );
		$coupon->save();

		return self::format_full( new \WC_Coupon( $coupon->get_id() ) );
	}

	public static function cb_update( $input ) {
		$coupon = self::load( (int) $input['id'] );
		if ( is_wp_error( $coupon ) ) {
			return $coupon;
		}

		$updated = array();

		if ( isset( $input['code'] ) ) {
			$code = trim( (string) $input['code'] );
			if ( '' === $code ) {
				return new \WP_Error( 'missing_code', __( 'code cannot be empty.', 'wc-mcp-ability' ) );
			}
			$existing = wc_get_coupon_id_by_code( $code, $coupon->get_id() );
			if ( $existing ) {
				return new \WP_Error( 'code_in_use', __( 'Another coupon already uses this code.', 'wc-mcp-ability' ) );
			}
			$updated['code'] = array( 'old' => $coupon->get_code() );
			$coupon->set_code( $code );
		}

		foreach ( self::FIELDS as $in_key => $suffix ) {
			if ( ! array_key_exists( $in_key, $input ) ) {
				continue;
			}
			$getter = "get_{$suffix}";
			$setter = "set_{$suffix}";
			$updated[ $in_key ] = array( 'old' => $coupon->$getter() );
			$coupon->$setter( self::cast_field( $in_key, $input[ $in_key ] ) );
		}

		if ( empty( $updated ) ) {
			return new \WP_Error( 'no_fields', __( 'Provide at least one field to change.', 'wc-mcp-ability' ) );
		}

		$coupon->save();

		$fresh = new \WC_Coupon( $coupon->get_id() );
		foreach ( array_keys( $updated ) as $field ) {
			$getter                  = 'code' === $field ? 'get_code' : 'get_' . self::FIELDS[ $field ];
			$updated[ $field ]['new'] = $fresh->$getter();
		}

		return array(
			'id'      => $fresh->get_id(),
			'updated' => $updated,
		);
	}

	public static function cb_delete( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to delete a coupon.', 'wc-mcp-ability' ) );
		}

		$coupon = self::load( (int) $input['id'] );
		if ( is_wp_error( $coupon ) ) {
			return $coupon;
		}

		$old    = self::format_summary( $coupon );
		$result = $coupon->delete( true );
		if ( ! $result ) {
			return new \WP_Error( 'delete_failed', __( 'The coupon could not be deleted.', 'wc-mcp-ability' ) );
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
	 * @return \WC_Coupon|\WP_Error
	 */
	private static function load( int $id ) {
		if ( $id <= 0 || 'shop_coupon' !== get_post_type( $id ) ) {
			return new \WP_Error( 'coupon_not_found', __( 'No coupon exists with the given ID.', 'wc-mcp-ability' ) );
		}
		return new \WC_Coupon( $id );
	}

	private static function apply_fields( \WC_Coupon $coupon, array $input ): void {
		foreach ( self::FIELDS as $in_key => $suffix ) {
			if ( array_key_exists( $in_key, $input ) ) {
				$setter = "set_{$suffix}";
				$coupon->$setter( self::cast_field( $in_key, $input[ $in_key ] ) );
			}
		}
	}

	private static function cast_field( string $field, $value ) {
		$booleans = array( 'individual_use', 'free_shipping', 'exclude_sale_items' );
		$arrays   = array( 'product_ids', 'excluded_product_ids', 'product_categories', 'excluded_product_categories', 'email_restrictions' );
		$integers = array( 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items' );

		if ( in_array( $field, $booleans, true ) ) {
			return (bool) $value;
		}
		if ( in_array( $field, $arrays, true ) ) {
			return array_map( in_array( $field, array( 'email_restrictions' ), true ) ? 'sanitize_email' : 'intval', (array) $value );
		}
		if ( in_array( $field, $integers, true ) ) {
			return '' === $value ? '' : (int) $value;
		}
		return (string) $value;
	}

	private static function format_summary( \WC_Coupon $coupon ): array {
		return array(
			'id'            => $coupon->get_id(),
			'code'          => $coupon->get_code(),
			'discount_type' => $coupon->get_discount_type(),
			'amount'        => $coupon->get_amount(),
			'status'        => $coupon->get_status(),
			'usage_count'   => $coupon->get_usage_count(),
			'date_expires'  => $coupon->get_date_expires() ? $coupon->get_date_expires()->date( 'Y-m-d' ) : null,
		);
	}

	private static function format_full( \WC_Coupon $coupon ): array {
		$summary = self::format_summary( $coupon );
		foreach ( self::FIELDS as $in_key => $suffix ) {
			if ( isset( $summary[ $in_key ] ) ) {
				continue;
			}
			$getter             = "get_{$suffix}";
			$value               = $coupon->$getter();
			$summary[ $in_key ] = ( $value instanceof \WC_DateTime ) ? $value->date( 'c' ) : $value;
		}
		return $summary;
	}
}
