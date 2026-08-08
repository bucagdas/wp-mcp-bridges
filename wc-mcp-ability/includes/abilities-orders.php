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
				'description'         => __( 'Creates a refund against an existing order. This is a financial operation — confirm: true is required. amount is the total refund amount and is always required, even when line_items is also given (WooCommerce treats them as independent: amount is what gets refunded, line_items is only used to restock/apportion specific items). refund_payment defaults to false, meaning this only records the refund in WooCommerce — it does NOT call the payment gateway to actually reverse the charge; set refund_payment: true to also attempt a real gateway-side refund (only works for gateways that support it and may fail if the gateway account/API is not configured).', 'wc-mcp-ability' ),
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
							'description' => 'Total amount to refund, e.g. "10.00". Required.',
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
					'required'             => array( 'order_id', 'amount', 'confirm' ),
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
