<?php
/**
 * Payment gateway listing and enable/disable abilities.
 *
 * WooCommerce native abilities have no payment gateway surface at all.
 * This deliberately exposes only enable/disable — NOT arbitrary settings
 * writes, since gateway settings routinely hold secret keys/API
 * credentials (Stripe secret key, PayPal client secret, ...). Reads go
 * through the same Plugin::redact() every wc-request response does.
 *
 * IMPORTANT (found by testing): always read the enabled state via
 * $gateway->get_option('enabled'), never the $gateway->enabled property.
 * WC_Payment_Gateway declares `public $enabled = 'yes'` as a hardcoded
 * class default and no core code — not the constructor, not
 * WC_Payment_Gateways::payment_gateways() — ever syncs it from the
 * persisted setting after construction, so it does not reflect writes
 * made via update_option(). get_option('enabled') always reads the live
 * settings array and is correct even against the cached singleton
 * WC()->payment_gateways() returns.
 *
 * @package WCMCPAbility
 */

namespace WCMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PaymentGateways {

	public static function register(): void {

		wp_register_ability(
			'wc-mcp/list-payment-gateways',
			array(
				'label'               => __( 'List payment gateways', 'wc-mcp-ability' ),
				'description'         => __( 'Lists all registered WooCommerce payment gateways (id, title, description, enabled, order) plus their settings fields. Secret-shaped settings (API keys, secrets, tokens) are redacted the same way wc-request redacts them — this is read-only and never returns credentials.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'id' => array(
							'type'        => 'string',
							'description' => 'Optional: return only the gateway with this id, e.g. "bacs". Omit to list all.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "gateways" (array of gateway objects).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wc-mcp/toggle-payment-gateway',
			array(
				'label'               => __( 'Enable or disable a payment gateway', 'wc-mcp-ability' ),
				'description'         => __( 'Enables or disables one payment gateway by id (e.g. "bacs", "cheque", "cod", or a third-party gateway\'s id). Only the enabled flag is written — this ability never writes gateway settings/credentials (use wp-admin for API key configuration). Requires confirm: true — disabling a gateway can block checkout for any customer who relied on it. Returns {old,new} for the enabled state, read back after the write.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'string',
							'description' => 'Gateway id, e.g. "bacs".',
						),
						'enabled' => array(
							'type'        => 'boolean',
							'description' => 'true = enable, false = disable.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Must be true. Toggling a gateway changes what customers can pay with.',
						),
					),
					'required'             => array( 'id', 'enabled', 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "id", "old" and "new" (enabled booleans).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_toggle' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	public static function cb_list( $input ) {
		$all = WC()->payment_gateways()->payment_gateways();

		if ( ! empty( $input['id'] ) ) {
			$id = (string) $input['id'];
			if ( ! isset( $all[ $id ] ) ) {
				return new \WP_Error( 'gateway_not_found', __( 'No payment gateway exists with this id.', 'wc-mcp-ability' ) );
			}
			$all = array( $id => $all[ $id ] );
		}

		$gateways = array();
		foreach ( $all as $gateway ) {
			$gateways[] = self::format_gateway( $gateway );
		}

		return array( 'gateways' => $gateways );
	}

	public static function cb_toggle( $input ) {
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to toggle a payment gateway.', 'wc-mcp-ability' ) );
		}

		$id  = (string) $input['id'];
		$all = WC()->payment_gateways()->payment_gateways();
		if ( ! isset( $all[ $id ] ) ) {
			return new \WP_Error( 'gateway_not_found', __( 'No payment gateway exists with this id.', 'wc-mcp-ability' ) );
		}

		/** @var \WC_Payment_Gateway $gateway */
		$gateway = $all[ $id ];
		$old     = 'yes' === $gateway->get_option( 'enabled' );
		$gateway->update_option( 'enabled', ! empty( $input['enabled'] ) ? 'yes' : 'no' );
		$new     = 'yes' === $gateway->get_option( 'enabled' );

		return array(
			'id'  => $id,
			'old' => $old,
			'new' => $new,
		);
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	private static function format_gateway( \WC_Payment_Gateway $gateway ): array {
		$settings = array();
		foreach ( $gateway->settings as $key => $value ) {
			$settings[ $key ] = $value;
		}

		return array(
			'id'            => $gateway->id,
			'title'         => $gateway->get_title(),
			'method_title'  => $gateway->get_method_title(),
			'description'   => $gateway->get_description(),
			'enabled'       => 'yes' === $gateway->get_option( 'enabled' ),
			'order'         => isset( $gateway->settings['order'] ) ? $gateway->settings['order'] : null,
			'settings'      => Plugin::redact( $settings ),
		);
	}
}
