<?php
/**
 * Customer CRUD abilities.
 *
 * WooCommerce customers are WordPress users (normally role "customer");
 * WC_Customer wraps user core fields plus billing/shipping address meta
 * and order-derived stats (order_count, total_spent). Native WooCommerce
 * ability coverage (products-query/product-create/... etc., see
 * HEDEF-SURUM.md) has no customer surface at all — this fills that gap.
 *
 * @package WCMCPAbility
 */

namespace WCMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Customers {

	const ADDRESS_FIELDS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

	public static function register(): void {

		wp_register_ability(
			'wc-mcp/list-customers',
			array(
				'label'               => __( 'List customers', 'wc-mcp-ability' ),
				'description'         => __( 'Lists WooCommerce customers (WordPress users). Filter by role (default "customer") and a search term matched against name/email/username.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'search'   => array(
							'type'        => 'string',
							'description' => 'Optional search term (name, email or username).',
						),
						'role'     => array(
							'type'        => 'string',
							'default'     => 'customer',
							'description' => 'WordPress role to filter by. Default "customer".',
						),
						'per_page' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 20,
							'description' => 'Maximum number of customers to return. Default 20.',
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
					'description' => 'Object with "total" and "customers" (array of summary objects).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wc-mcp/get-customer',
			array(
				'label'               => __( 'Get a customer', 'wc-mcp-ability' ),
				'description'         => __( 'Returns full customer detail: core fields, billing and shipping addresses, and order stats (order_count, total_spent).', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Customer (user) id.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Full customer detail.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wc-mcp/create-customer',
			array(
				'label'               => __( 'Create a customer', 'wc-mcp-ability' ),
				'description'         => __( 'Creates a new WooCommerce customer. email is required and must not already be registered; username is derived from the name/email when omitted, password is auto-generated when omitted (never returned). billing/shipping are optional address objects.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'email'      => array(
							'type'        => 'string',
							'description' => 'Customer email address. Required, must be unique.',
						),
						'username'   => array(
							'type'        => 'string',
							'description' => 'Optional login username. Derived from name/email when omitted.',
						),
						'password'   => array(
							'type'        => 'string',
							'description' => 'Optional login password. Auto-generated when omitted.',
						),
						'first_name' => array( 'type' => 'string' ),
						'last_name'  => array( 'type' => 'string' ),
						'billing'    => self::address_schema( true ),
						'shipping'   => self::address_schema( false ),
					),
					'required'             => array( 'email' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'The created customer (password never included).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_create' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, false, false ),
			)
		);

		wp_register_ability(
			'wc-mcp/update-customer',
			array(
				'label'               => __( 'Update a customer', 'wc-mcp-ability' ),
				'description'         => __( 'Updates one or more fields of an existing customer: email, first_name, last_name, billing, shipping. Provide only the fields to change. Password is not settable through this ability (use WordPress\'s own password-reset flow).', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'         => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Customer (user) id.',
						),
						'email'      => array( 'type' => 'string' ),
						'first_name' => array( 'type' => 'string' ),
						'last_name'  => array( 'type' => 'string' ),
						'billing'    => self::address_schema( true ),
						'shipping'   => self::address_schema( false ),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id" and "updated": per-field {old, new} (billing/shipping compared as whole objects).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);

		wp_register_ability(
			'wc-mcp/delete-customer',
			array(
				'label'               => __( 'Delete a customer', 'wc-mcp-ability' ),
				'description'         => __( 'Permanently deletes a customer account. Requires confirm: true. This deletes the WordPress user — it cannot be undone or trashed. Optionally reassign their posts/comments to another user id via reassign; their orders are NOT reassigned (WooCommerce keeps orders under the deleted customer\'s prior data as a guest-like record).', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Customer (user) id to delete.',
						),
						'confirm'  => array(
							'type'        => 'boolean',
							'description' => 'Must be true to proceed. Deletion cannot be undone.',
						),
						'reassign' => array(
							'type'        => 'integer',
							'description' => 'Optional user id to reassign this customer\'s posts/comments to. Omit or 0 for no reassignment.',
						),
					),
					'required'             => array( 'id', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "old" (the deleted customer summary) and "new" (null).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_delete' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, true, true ),
			)
		);
	}

	private static function address_schema( bool $with_contact ): array {
		$properties = array();
		foreach ( Customers::ADDRESS_FIELDS as $field ) {
			$properties[ $field ] = array( 'type' => 'string' );
		}
		if ( $with_contact ) {
			$properties['email'] = array( 'type' => 'string' );
			$properties['phone'] = array( 'type' => 'string' );
		}
		return array(
			'type'                 => 'object',
			'description'          => 'Optional address fields to set (any subset).',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	public static function cb_list( $input ) {
		$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$role     = isset( $input['role'] ) ? (string) $input['role'] : 'customer';

		$args = array(
			'role'   => $role,
			'number' => $per_page,
			'offset' => ( $page - 1 ) * $per_page,
			'fields' => 'ID',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['search']         = '*' . trim( (string) $input['search'] ) . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		$query = new \WP_User_Query( $args );
		$ids   = $query->get_results();

		$customers = array();
		foreach ( $ids as $id ) {
			$customer    = new \WC_Customer( (int) $id );
			$customers[] = self::format_summary( $customer );
		}

		return array(
			'total'     => (int) $query->get_total(),
			'customers' => $customers,
		);
	}

	public static function cb_get( $input ) {
		$id = (int) $input['id'];
		if ( ! get_userdata( $id ) ) {
			return new \WP_Error( 'customer_not_found', __( 'No user exists with the given ID.', 'wc-mcp-ability' ) );
		}
		return self::format_full( new \WC_Customer( $id ) );
	}

	public static function cb_create( $input ) {
		$email = (string) $input['email'];

		$new_customer_args = array();
		if ( ! empty( $input['first_name'] ) ) {
			$new_customer_args['first_name'] = (string) $input['first_name'];
		}
		if ( ! empty( $input['last_name'] ) ) {
			$new_customer_args['last_name'] = (string) $input['last_name'];
		}

		$username = ! empty( $input['username'] ) ? sanitize_user( (string) $input['username'], true ) : '';
		$password = ! empty( $input['password'] ) ? (string) $input['password'] : wp_generate_password();

		$user_id = wc_create_new_customer( $email, $username, $password, $new_customer_args );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$customer = new \WC_Customer( $user_id );
		if ( isset( $input['billing'] ) ) {
			self::apply_address( $customer, 'billing', (array) $input['billing'] );
		}
		if ( isset( $input['shipping'] ) ) {
			self::apply_address( $customer, 'shipping', (array) $input['shipping'] );
		}
		$customer->save();

		return self::format_full( new \WC_Customer( $user_id ) );
	}

	public static function cb_update( $input ) {
		$id = (int) $input['id'];
		if ( ! get_userdata( $id ) ) {
			return new \WP_Error( 'customer_not_found', __( 'No user exists with the given ID.', 'wc-mcp-ability' ) );
		}

		$customer = new \WC_Customer( $id );
		$updated  = array();

		if ( isset( $input['email'] ) ) {
			$updated['email'] = array( 'old' => $customer->get_email() );
			$customer->set_email( (string) $input['email'] );
		}
		if ( isset( $input['first_name'] ) ) {
			$updated['first_name'] = array( 'old' => $customer->get_first_name() );
			$customer->set_first_name( (string) $input['first_name'] );
		}
		if ( isset( $input['last_name'] ) ) {
			$updated['last_name'] = array( 'old' => $customer->get_last_name() );
			$customer->set_last_name( (string) $input['last_name'] );
		}
		if ( isset( $input['billing'] ) ) {
			$updated['billing'] = array( 'old' => self::read_address( $customer, 'billing' ) );
			self::apply_address( $customer, 'billing', (array) $input['billing'] );
		}
		if ( isset( $input['shipping'] ) ) {
			$updated['shipping'] = array( 'old' => self::read_address( $customer, 'shipping' ) );
			self::apply_address( $customer, 'shipping', (array) $input['shipping'] );
		}

		if ( empty( $updated ) ) {
			return new \WP_Error( 'no_fields', __( 'Provide at least one field to change.', 'wc-mcp-ability' ) );
		}

		$customer->save();

		$fresh = new \WC_Customer( $id );
		foreach ( array_keys( $updated ) as $field ) {
			if ( in_array( $field, array( 'billing', 'shipping' ), true ) ) {
				$updated[ $field ]['new'] = self::read_address( $fresh, $field );
			} else {
				$getter                  = 'get_' . $field;
				$updated[ $field ]['new'] = $fresh->$getter();
			}
		}

		return array(
			'id'      => $id,
			'updated' => $updated,
		);
	}

	public static function cb_delete( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to delete a customer.', 'wc-mcp-ability' ) );
		}

		$id = (int) $input['id'];
		if ( ! get_userdata( $id ) ) {
			return new \WP_Error( 'customer_not_found', __( 'No user exists with the given ID.', 'wc-mcp-ability' ) );
		}

		$old      = self::format_summary( new \WC_Customer( $id ) );
		$reassign = ! empty( $input['reassign'] ) ? (int) $input['reassign'] : null;

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$result = wp_delete_user( $id, $reassign );
		if ( ! $result ) {
			return new \WP_Error( 'delete_failed', __( 'The customer could not be deleted.', 'wc-mcp-ability' ) );
		}

		return array(
			'id'  => $id,
			'old' => $old,
			'new' => null,
		);
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	private static function apply_address( \WC_Customer $customer, string $type, array $fields ): void {
		$allowed = self::ADDRESS_FIELDS;
		if ( 'billing' === $type ) {
			$allowed = array_merge( $allowed, array( 'email', 'phone' ) );
		}
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $fields ) ) {
				$setter = "set_{$type}_{$field}";
				if ( method_exists( $customer, $setter ) ) {
					$customer->$setter( (string) $fields[ $field ] );
				}
			}
		}
	}

	private static function read_address( \WC_Customer $customer, string $type ): array {
		$allowed = self::ADDRESS_FIELDS;
		if ( 'billing' === $type ) {
			$allowed = array_merge( $allowed, array( 'email', 'phone' ) );
		}
		$out = array();
		foreach ( $allowed as $field ) {
			$getter          = "get_{$type}_{$field}";
			$out[ $field ]    = method_exists( $customer, $getter ) ? $customer->$getter() : null;
		}
		return $out;
	}

	private static function format_summary( \WC_Customer $customer ): array {
		return array(
			'id'           => $customer->get_id(),
			'email'        => $customer->get_email(),
			'username'     => $customer->get_username(),
			'first_name'   => $customer->get_first_name(),
			'last_name'    => $customer->get_last_name(),
			'display_name' => $customer->get_display_name(),
			'role'         => $customer->get_role(),
			'date_created' => $customer->get_date_created() ? $customer->get_date_created()->date( 'c' ) : null,
		);
	}

	private static function format_full( \WC_Customer $customer ): array {
		$summary                 = self::format_summary( $customer );
		$summary['billing']      = self::read_address( $customer, 'billing' );
		$summary['shipping']     = self::read_address( $customer, 'shipping' );
		$summary['order_count']  = $customer->get_order_count();
		$summary['total_spent']  = $customer->get_total_spent();
		$summary['is_paying_customer'] = $customer->get_is_paying_customer();
		return $summary;
	}
}
