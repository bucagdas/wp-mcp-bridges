<?php
/**
 * Order creation and refund abilities.
 *
 * WooCommerce's native abilities cover orders-query (read), order-add-note
 * and order-update-status, but NOT creating an order or refunding one —
 * the REST-derived orders-create ability only registers on WooCommerce's
 * own separate (deprecated) MCP endpoint, not the shared MCP Adapter
 * default server. This fills that specific gap; it does not duplicate
 * list/get/status/note, which the native abilities already cover.
 *
 * @package WCMCPAbility
 */

namespace WCMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Orders {

	const ADDRESS_FIELDS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

	public static function register(): void {

		wp_register_ability(
			'wc-mcp/create-order',
			array(
				'label'               => __( 'Create an order', 'wc-mcp-ability' ),
				'description'         => __( 'Creates a new WooCommerce order with one or more line items. line_items is required (product_id + optional quantity/variation_id per line); each product/variation is validated to exist before anything is created — the order is rolled back (deleted) if any line item is invalid. customer_id defaults to 0 (guest order). set_paid: true marks the order paid (reduces stock, moves to processing/completed) via the same path a real checkout uses — omit it to leave the order in its given status (default "pending") for manual payment/review.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'customer_id'          => array(
							'type'        => 'integer',
							'default'     => 0,
							'description' => 'Existing customer (user) id. 0 or omitted = guest order.',
						),
						'status'               => array(
							'type'        => 'string',
							'default'     => 'pending',
							'description' => 'Initial order status. Default "pending". Ignored if set_paid: true.',
						),
						'customer_note'        => array( 'type' => 'string' ),
						'line_items'           => array(
							'type'        => 'array',
							'description' => 'At least one required.',
							'items'       => array(
								'type'                 => 'object',
								'properties'           => array(
									'product_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
									'quantity'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
									'variation_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Required if product_id is a variable product.' ),
								),
								'required'             => array( 'product_id' ),
								'additionalProperties' => false,
							),
						),
						'billing'              => self::address_schema(),
						'shipping'             => self::address_schema(),
						'payment_method'       => array( 'type' => 'string', 'description' => 'Payment gateway id, e.g. "bacs".' ),
						'payment_method_title' => array( 'type' => 'string' ),
						'set_paid'             => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'true = mark the order as paid immediately (reduces stock, status becomes processing/completed).',
						),
					),
					'required'             => array( 'line_items' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'The created order.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_create_order' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, false, false ),
			)
		);

		wp_register_ability(
			'wc-mcp/create-order-refund',
			array(
				'label'               => __( 'Refund an order', 'wc-mcp-ability' ),
				'description'         => __( 'Creates a refund against an existing order. This is a financial operation — confirm: true is required. Two ways to say how much: (1) pass amount yourself, the long-standing behaviour, where line_items only restock/apportion specific items and does not affect the sum refunded; or (2) pass compute_totals: true with line_items quantities and let WooCommerce derive every per-line amount from the order\'s stored prices and taxes, validated against what has already been refunded. Use preview-order-refund first if you want to see those numbers before committing. compute_totals needs WooCommerce 11.1.0 or newer. refund_payment defaults to false, meaning this only records the refund in WooCommerce — it does NOT call the payment gateway to actually reverse the charge; set refund_payment: true to also attempt a real gateway-side refund (only works for gateways that support it and may fail if the gateway account/API is not configured).', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'order_id'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Order id to refund.',
						),
						'amount'         => array(
							'type'        => 'string',
							'description' => 'Total amount to refund, e.g. "10.00". Required unless compute_totals is true, in which case it is an optional override of the computed sum.',
						),
						'compute_totals' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'true = derive the per-line refund amounts from line_items quantities using the order\'s stored prices and taxes, instead of supplying amount yourself. Requires WooCommerce 11.1.0 or newer.',
						),
						'reason'         => array( 'type' => 'string' ),
						'line_items'     => array(
							'type'        => 'array',
							'description' => 'Optional itemized breakdown, for restocking and per-item reporting. Does not itself determine the refunded amount — amount does.',
							'items'       => array(
								'type'                 => 'object',
								'properties'           => array(
									'item_id'      => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Order line item id (from the order\'s line_items).' ),
									'quantity'     => array( 'type' => 'integer', 'minimum' => 0 ),
									'refund_total' => array( 'type' => 'string' ),
								),
								'required'             => array( 'item_id' ),
								'additionalProperties' => false,
							),
						),
						'restock_items'  => array( 'type' => 'boolean', 'default' => false ),
						'refund_payment' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'true = also attempt a real refund through the order\'s payment gateway. false (default) only records the refund in WooCommerce.',
						),
						'confirm'        => array(
							'type'        => 'boolean',
							'description' => 'Must be true to proceed. This is a financial operation.',
						),
					),
					'required'             => array( 'order_id', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with refund_id, amount, and the order\'s old/new total_refunded and status.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_create_refund' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, true, false ),
			)
		);

		// The route this verb wraps does not exist before WooCommerce 11.1.0, so
		// on an older store the verb is not registered at all rather than offered
		// and then failing with a 404.
		if ( self::has_server_side_refund_math() ) {
			wp_register_ability(
				'wc-mcp/preview-order-refund',
				array(
					'label'               => __( 'Preview an order refund', 'wc-mcp-ability' ),
					'description'         => __( 'Computes what a refund would come to, without creating one and without changing anything. Give the order and the line items (quantity, or an explicit refund_total per line) and WooCommerce returns the per-line breakdown plus subtotal, tax, total and how much of the order is still refundable — using the order\'s stored prices and its refund history, so tax and rounding do not have to be worked out by hand. Pair it with create-order-refund: preview to get the number, then create the refund with that amount, or pass compute_totals: true there to have the same engine do both in one call.', 'wc-mcp-ability' ),
					'category'            => Plugin::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'order_id'   => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'Order id to preview a refund for.',
							),
							'line_items' => array(
								'type'        => 'array',
								'minItems'    => 1,
								'description' => 'Lines to include in the preview. Each needs item_id plus either quantity or refund_total.',
								'items'       => array(
									'type'                 => 'object',
									'properties'           => array(
										'item_id'      => array(
											'type'        => 'integer',
											'minimum'     => 1,
											'description' => 'Order line item id (from the order\'s line_items). Same parameter name create-order-refund uses.',
										),
										'quantity'     => array(
											'type'        => 'integer',
											'minimum'     => 1,
											'description' => 'How many of this line to refund. Required when refund_total is omitted.',
										),
										'refund_total' => array(
											'type'        => 'number',
											'description' => 'Tax-inclusive amount for this line, instead of deriving it from quantity. Must be non-zero and carry the line\'s own sign (negative for a discount or credit line).',
										),
									),
									'required'             => array( 'item_id' ),
									'additionalProperties' => false,
								),
							),
						),
						'required'             => array( 'order_id', 'line_items' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => 'WooCommerce\'s computed preview: per-line breakdown plus subtotal, tax, total and max_refundable. Nothing is written.',
					),
					'execute_callback'    => array( __CLASS__, 'cb_preview_refund' ),
					'permission_callback' => array( Plugin::class, 'permission' ),
					'meta'                => Plugin::meta( true, false, true ),
				)
			);
		}
	}

	/**
	 * Whether this store can do refund arithmetic server-side.
	 *
	 * Both the preview route and the compute_totals create mode arrived in
	 * WooCommerce 11.1.0. The calculation engine behind them lives in
	 * src/Internal/, which is not a public API, so the only supported way in is
	 * the REST surface — hence a version check rather than a class_exists().
	 */
	private static function has_server_side_refund_math(): bool {
		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '11.1.0', '>=' );
	}

	/**
	 * Translate this bridge's line item shape into the one the refund routes want.
	 *
	 * The bridge says item_id everywhere (create-order-refund has since 2.4.0);
	 * WooCommerce's refund engine keys lines by line_item_id. Mapping here keeps
	 * a preview and the create call that follows it written the same way.
	 *
	 * @param mixed $line_items Raw line_items input.
	 * @return array
	 */
	private static function refund_line_items( $line_items ): array {
		$out = array();
		foreach ( (array) $line_items as $line ) {
			if ( ! is_array( $line ) || empty( $line['item_id'] ) ) {
				continue;
			}
			$entry = array( 'line_item_id' => (int) $line['item_id'] );
			if ( isset( $line['quantity'] ) ) {
				$entry['quantity'] = (int) $line['quantity'];
			}
			if ( isset( $line['refund_total'] ) ) {
				$entry['refund_total'] = (float) $line['refund_total'];
			}
			$out[] = $entry;
		}
		return $out;
	}

	private static function address_schema(): array {
		$properties = array();
		foreach ( self::ADDRESS_FIELDS as $field ) {
			$properties[ $field ] = array( 'type' => 'string' );
		}
		$properties['email'] = array( 'type' => 'string' );
		$properties['phone'] = array( 'type' => 'string' );
		return array(
			'type'                 => 'object',
			'description'          => 'Optional address fields (any subset).',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	public static function cb_create_order( $input ) {
		if ( empty( $input['line_items'] ) || ! is_array( $input['line_items'] ) ) {
			return new \WP_Error( 'missing_line_items', __( 'At least one line item is required.', 'wc-mcp-ability' ) );
		}

		$order = wc_create_order(
			array(
				'status'        => isset( $input['status'] ) ? (string) $input['status'] : 'pending',
				'customer_id'   => isset( $input['customer_id'] ) ? (int) $input['customer_id'] : 0,
				'customer_note' => isset( $input['customer_note'] ) ? (string) $input['customer_note'] : null,
				'created_via'   => 'wc-mcp',
			)
		);
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		foreach ( $input['line_items'] as $i => $line ) {
			if ( empty( $line['product_id'] ) ) {
				$order->delete( true );
				return new \WP_Error( 'invalid_line_item', sprintf( __( 'line_items[%d] is missing product_id.', 'wc-mcp-ability' ), $i ) );
			}

			$product_id = (int) $line['product_id'];
			$product    = wc_get_product( $product_id );
			if ( ! $product ) {
				$order->delete( true );
				return new \WP_Error( 'product_not_found', sprintf( __( 'No product exists with id %1$d (line_items[%2$d]).', 'wc-mcp-ability' ), $product_id, $i ) );
			}

			if ( ! empty( $line['variation_id'] ) ) {
				$variation_id = (int) $line['variation_id'];
				$variation    = wc_get_product( $variation_id );
				if ( ! $variation || $variation->get_parent_id() !== $product_id ) {
					$order->delete( true );
					return new \WP_Error( 'invalid_variation', sprintf( __( 'variation_id %1$d does not belong to product %2$d (line_items[%3$d]).', 'wc-mcp-ability' ), $variation_id, $product_id, $i ) );
				}
				$product = $variation;
			} elseif ( $product->is_type( 'variable' ) ) {
				$order->delete( true );
				return new \WP_Error( 'missing_variation', sprintf( __( 'product_id %1$d is a variable product; variation_id is required (line_items[%2$d]).', 'wc-mcp-ability' ), $product_id, $i ) );
			}

			$qty    = isset( $line['quantity'] ) ? max( 1, (int) $line['quantity'] ) : 1;
			$result = $order->add_product( $product, $qty );
			if ( ! $result ) {
				$order->delete( true );
				return new \WP_Error( 'add_product_failed', sprintf( __( 'Could not add product %1$d to the order (line_items[%2$d]).', 'wc-mcp-ability' ), $product->get_id(), $i ) );
			}
		}

		if ( isset( $input['billing'] ) ) {
			$order->set_address( (array) $input['billing'], 'billing' );
		}
		if ( isset( $input['shipping'] ) ) {
			$order->set_address( (array) $input['shipping'], 'shipping' );
		}
		if ( ! empty( $input['payment_method'] ) ) {
			$order->set_payment_method( (string) $input['payment_method'] );
		}
		if ( ! empty( $input['payment_method_title'] ) ) {
			$order->set_payment_method_title( (string) $input['payment_method_title'] );
		}

		$order->calculate_totals();

		if ( ! empty( $input['set_paid'] ) ) {
			$order->payment_complete();
		} else {
			$order->save();
		}

		return self::format_order( wc_get_order( $order->get_id() ) );
	}

	public static function cb_create_refund( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to create a refund. This is a financial operation.', 'wc-mcp-ability' ) );
		}

		$order_id = (int) $input['order_id'];
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'order_not_found', __( 'No order exists with the given ID.', 'wc-mcp-ability' ) );
		}

		$compute_totals = ! empty( $input['compute_totals'] );

		if ( $compute_totals && ! self::has_server_side_refund_math() ) {
			return new \WP_Error(
				'compute_totals_unsupported',
				__( 'compute_totals needs WooCommerce 11.1.0 or newer. On this store, work out the refund yourself and pass "amount".', 'wc-mcp-ability' )
			);
		}

		// Without compute_totals the amount is the whole instruction, so a missing
		// one is refused here rather than silently refunding 0.00.
		if ( ! $compute_totals && ! isset( $input['amount'] ) ) {
			return new \WP_Error(
				'amount_required',
				__( 'Provide "amount", or pass compute_totals: true to have WooCommerce derive it from line_items quantities.', 'wc-mcp-ability' )
			);
		}

		if ( $compute_totals ) {
			return self::create_refund_with_computed_totals( $order, $input );
		}

		$line_items_arg = array();
		if ( ! empty( $input['line_items'] ) && is_array( $input['line_items'] ) ) {
			foreach ( $input['line_items'] as $line ) {
				if ( empty( $line['item_id'] ) ) {
					continue;
				}
				$entry = array();
				if ( isset( $line['quantity'] ) ) {
					$entry['qty'] = (int) $line['quantity'];
				}
				if ( isset( $line['refund_total'] ) ) {
					$entry['refund_total'] = (string) $line['refund_total'];
				}
				$line_items_arg[ (int) $line['item_id'] ] = $entry;
			}
		}

		$old_total_refunded = $order->get_total_refunded();

		$refund = wc_create_refund(
			array(
				'amount'         => (string) $input['amount'],
				'reason'         => isset( $input['reason'] ) ? (string) $input['reason'] : null,
				'order_id'       => $order_id,
				'line_items'     => $line_items_arg,
				'refund_payment' => ! empty( $input['refund_payment'] ),
				'restock_items'  => ! empty( $input['restock_items'] ),
			)
		);
		if ( is_wp_error( $refund ) ) {
			return $refund;
		}

		$fresh = wc_get_order( $order_id );

		return array(
			'order_id'           => $order_id,
			'refund_id'          => $refund->get_id(),
			'amount'             => $refund->get_amount(),
			'reason'             => $refund->get_reason(),
			'old_total_refunded' => $old_total_refunded,
			'new_total_refunded' => $fresh->get_total_refunded(),
			'order_status'       => $fresh->get_status(),
		);
	}

	/**
	 * Create a refund whose per-line amounts WooCommerce works out itself.
	 *
	 * Goes through the wc/v3 route rather than wc_create_refund(): the arithmetic
	 * lives in src/Internal/, which third parties are not meant to call, and the
	 * route is the supported way to reach it.
	 *
	 * The route's own defaults are the trap here. api_refund and api_restock both
	 * default to true there, while this ability has always defaulted
	 * refund_payment and restock_items to false. Both are therefore passed
	 * explicitly on every call, so turning compute_totals on cannot quietly start
	 * charging a gateway or moving stock.
	 *
	 * @param \WC_Order $order The order being refunded.
	 * @param array     $input Ability input.
	 * @return array|\WP_Error
	 */
	private static function create_refund_with_computed_totals( \WC_Order $order, array $input ) {
		$line_items = self::refund_line_items( $input['line_items'] ?? array() );
		if ( empty( $line_items ) ) {
			return new \WP_Error(
				'missing_line_items',
				__( 'compute_totals derives the amount from line_items, so at least one line item with an item_id is required.', 'wc-mcp-ability' )
			);
		}

		$order_id           = $order->get_id();
		$old_total_refunded = $order->get_total_refunded();

		$body = array(
			'line_items'  => $line_items,
			'api_refund'  => ! empty( $input['refund_payment'] ),
			'api_restock' => ! empty( $input['restock_items'] ),
		);
		if ( isset( $input['reason'] ) ) {
			$body['reason'] = (string) $input['reason'];
		}
		// Only send amount when the caller actually gave one: the route treats the
		// parameter being present at all as an override of the computed sum.
		if ( isset( $input['amount'] ) ) {
			$body['amount'] = (string) $input['amount'];
		}

		$request = new \WP_REST_Request( 'POST', '/wc/v3/orders/' . $order_id . '/refunds' );
		$request->set_param( 'order_id', $order_id );
		$request->set_param( 'compute_totals', true );
		$request->set_body_params( array_merge( $body, array( 'compute_totals' => true ) ) );

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$error = $response->as_error();
			return new \WP_Error( $error->get_error_code(), $error->get_error_message(), array( 'status' => $response->get_status() ) );
		}

		$data      = $response->get_data();
		$refund_id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$fresh     = wc_get_order( $order_id );

		return array(
			'order_id'           => $order_id,
			'refund_id'          => $refund_id,
			'amount'             => $data['amount'] ?? null,
			'reason'             => $data['reason'] ?? null,
			'computed_totals'    => true,
			'line_items'         => $data['line_items'] ?? array(),
			'old_total_refunded' => $old_total_refunded,
			'new_total_refunded' => $fresh ? $fresh->get_total_refunded() : null,
			'order_status'       => $fresh ? $fresh->get_status() : null,
		);
	}

	/**
	 * Ask WooCommerce what a refund would come to, without creating one.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function cb_preview_refund( $input ) {
		$order_id = isset( $input['order_id'] ) ? (int) $input['order_id'] : 0;
		if ( $order_id < 1 ) {
			return new \WP_Error( 'missing_order_id', __( 'Provide "order_id" — the order to preview a refund for.', 'wc-mcp-ability' ) );
		}

		$line_items = self::refund_line_items( $input['line_items'] ?? array() );
		if ( empty( $line_items ) ) {
			return new \WP_Error( 'missing_line_items', __( 'At least one line item with an item_id is required.', 'wc-mcp-ability' ) );
		}

		$request = new \WP_REST_Request( 'POST', '/wc/v3/orders/' . $order_id . '/refunds/preview' );
		$request->set_param( 'order_id', $order_id );
		$request->set_body_params(
			array(
				'order_id'   => $order_id,
				'line_items' => $line_items,
			)
		);

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$error = $response->as_error();
			return new \WP_Error( $error->get_error_code(), $error->get_error_message(), array( 'status' => $response->get_status() ) );
		}

		return array(
			'order_id' => $order_id,
			'preview'  => $response->get_data(),
		);
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	private static function read_address( \WC_Order $order, string $type ): array {
		$fields = self::ADDRESS_FIELDS;
		if ( 'billing' === $type ) {
			$fields = array_merge( $fields, array( 'email', 'phone' ) );
		}
		$out = array();
		foreach ( $fields as $field ) {
			$getter        = "get_{$type}_{$field}";
			$out[ $field ] = method_exists( $order, $getter ) ? $order->$getter() : null;
		}
		return $out;
	}

	private static function format_order( \WC_Order $order ): array {
		$line_items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$line_items[] = array(
				'id'           => (int) $item_id,
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'name'         => $item->get_name(),
				'quantity'     => $item->get_quantity(),
				'total'        => $item->get_total(),
			);
		}

		return array(
			'id'                   => $order->get_id(),
			'status'               => $order->get_status(),
			'customer_id'          => $order->get_customer_id(),
			'currency'             => $order->get_currency(),
			'total'                => $order->get_total(),
			'payment_method'       => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'line_items'           => $line_items,
			'billing'              => self::read_address( $order, 'billing' ),
			'shipping'             => self::read_address( $order, 'shipping' ),
			'date_created'         => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
		);
	}
}
