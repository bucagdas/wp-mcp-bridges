<?php
/**
 * Generic WooCommerce REST proxy ability.
 *
 * @package WCMCPAbility
 */

namespace WCMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Proxy {

	public static function register(): void {

		// Generic WooCommerce REST request (full store access).
		wp_register_ability(
			'wc-mcp/wc-request',
			array(
				'label'               => __( 'WooCommerce REST request', 'wc-mcp-ability' ),
				'description'         => __( 'Performs any WooCommerce REST API (wc/v3) request. Provide method (GET, POST, PUT or DELETE), endpoint (e.g. "products", "orders", "products/categories", "customers", "coupons", "settings", "reports") and optional params. This covers the full WooCommerce store: products, variations, orders, customers, coupons, shipping, taxes, settings and reports. POST/PUT/DELETE require confirm: true — this ability can create, modify or permanently delete any store data. Secret-shaped fields (API keys, tokens, passwords, nonces) are redacted from every response, including nested payment_gateways/settings field objects.', 'wc-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'method'   => array(
							'type'        => 'string',
							'description' => 'HTTP method: GET, POST, PUT or DELETE.',
						),
						'endpoint' => array(
							'type'        => 'string',
							'description' => 'WooCommerce REST endpoint after wc/v3/, e.g. "products" or "products/categories".',
						),
						'params'   => array(
							'type'        => 'object',
							'description' => 'Optional request parameters (query for GET/DELETE, body for POST/PUT).',
						),
						'confirm'  => array(
							'type'        => 'boolean',
							'description' => 'Must be true when method is POST, PUT or DELETE. Not required for GET.',
						),
					),
					'required'             => array( 'method', 'endpoint' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Response containing success flag, HTTP status and data (secret-shaped fields redacted).',
				),
				'execute_callback'    => array( __CLASS__, 'cb_wc_request' ),
				'permission_callback' => array( Plugin::class, 'permission' ),
				'meta'                => Plugin::meta( false, true, false ),
			)
		);
	}

	public static function cb_wc_request( $input ) {
		if ( empty( $input['method'] ) || empty( $input['endpoint'] ) ) {
			return new \WP_Error( 'missing_args', __( 'Both method and endpoint are required.', 'wc-mcp-ability' ) );
		}

		$method   = strtoupper( (string) $input['method'] );
		$endpoint = ltrim( (string) $input['endpoint'], '/' );
		$params   = ( isset( $input['params'] ) && is_array( $input['params'] ) ) ? $input['params'] : array();

		if ( in_array( $method, array( 'POST', 'PUT', 'DELETE' ), true ) && true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error( 'confirm_required', __( 'Pass confirm: true to perform a POST, PUT or DELETE request. This can create, modify or permanently delete store data.', 'wc-mcp-ability' ) );
		}

		$route   = '/wc/v3/' . $endpoint;
		$request = new \WP_REST_Request( $method, $route );

		if ( in_array( $method, array( 'GET', 'DELETE' ), true ) ) {
			foreach ( $params as $key => $value ) {
				$request->set_param( $key, $value );
			}
		} else {
			$request->set_body_params( $params );
		}

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$error = $response->as_error();
			return array(
				'success' => false,
				'status'  => $response->get_status(),
				'error'   => $error->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'status'  => $response->get_status(),
			'data'    => Plugin::redact( $response->get_data() ),
		);
	}
}
