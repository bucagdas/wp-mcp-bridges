<?php
/**
 * Plugin Name: WooCommerce MCP Ability
 * Plugin URI: https://github.com/bucagdas/wp-mcp-bridges/tree/main/wc-mcp-ability
 * Description: WooCommerce abilities for MCP. Product/taxonomy/customer/coupon CRUD plus a generic wc/v3 REST request for the rest of the store.
 * Version: 2.7.2
 * Requires at least: 7.0
 * Requires PHP: 8.0
 * Author: bucagdas
 * Author URI: https://github.com/bucagdas/
 * Text Domain: wc-mcp-ability
 * Requires Plugins: woocommerce
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace WCMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/abilities-taxonomies.php';
require_once __DIR__ . '/includes/abilities-proxy.php';
require_once __DIR__ . '/includes/abilities-customers.php';
require_once __DIR__ . '/includes/abilities-coupons.php';
require_once __DIR__ . '/includes/abilities-orders.php';
require_once __DIR__ . '/includes/abilities-variations.php';
require_once __DIR__ . '/includes/abilities-payment-gateways.php';
require_once __DIR__ . '/includes/abilities-product-taxonomy-images.php';

require_once __DIR__ . '/includes/plugin-update-checker/plugin-update-checker.php';
\YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::buildUpdateChecker(
	'https://raw.githubusercontent.com/bucagdas/wp-mcp-bridges/main/wc-mcp-ability/update.json',
	__FILE__,
	'wc-mcp-ability'
);

class Plugin {

	const CATEGORY = 'wc-mcp';

	/**
	 * Matches field/setting names that carry secret material: payment gateway
	 * keys/secrets, tokens, passwords, nonces. Deliberately requires a prefix
	 * before "key" (api_key, secret_key, ...) so it does not false-positive on
	 * WooCommerce's own meta_data "key" field (the generic name of a custom
	 * field, e.g. {id, key: "_billing_note", value: "..."}), and matches
	 * "authoriz" rather than bare "auth" so it does not catch WordPress's
	 * unrelated post "author" field. Deliberately does NOT match
	 * "publishable_key" — Stripe/payment-gateway publishable keys are
	 * designed to be exposed client-side, unlike secret/private keys.
	 */
	const SENSITIVE_PATTERN = '/(secret|token|password|nonce|authoriz|(?:api|private|client|consumer|access)[_-]?key)/i';

	const REDACTED = '***REDACTED***';

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Register the ability category. Must run on wp_abilities_api_categories_init.
	 */
	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'WooCommerce MCP', 'wc-mcp-ability' ),
				'description' => __( 'Full-access WooCommerce store management abilities.', 'wc-mcp-ability' ),
			)
		);
	}

	/**
	 * Register all abilities. Must run on wp_abilities_api_init.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		Taxonomies::register();
		Proxy::register();
		Customers::register();
		Coupons::register();
		Orders::register();
		Variations::register();
		PaymentGateways::register();
		ProductTaxonomyImages::register();
	}

	/**
	 * Build the meta array for an ability.
	 */
	public static function meta( bool $readonly, bool $destructive, bool $idempotent ): array {
		return array(
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => true,
			),
			'annotations'  => array(
				'readonly'    => $readonly,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
		);
	}

	/**
	 * Permission check for all abilities.
	 */
	public static function permission( $input = null ): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Recursively redact secret-shaped fields from any WooCommerce data
	 * structure headed for a response (wc-request passthrough output,
	 * payment gateway settings, ...).
	 *
	 * Handles two shapes:
	 * 1. Flat: any string array key matching SENSITIVE_PATTERN (e.g. a
	 *    top-level "secret_key" settings entry) has its whole value replaced.
	 * 2. WooCommerce's settings/meta_data field shape, {id|key: "<name>",
	 *    value: ...} (used by payment_gateways settings and post meta_data)
	 *    — matches against the "id"/"key" name and, if sensitive, masks
	 *    just the sibling "value"/"default" instead of the whole object.
	 *
	 * @param mixed $data
	 * @return mixed
	 */
	public static function redact( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$name_field = null;
		if ( isset( $data['key'] ) && is_string( $data['key'] ) ) {
			$name_field = $data['key'];
		} elseif ( isset( $data['id'] ) && is_string( $data['id'] ) ) {
			$name_field = $data['id'];
		}
		if ( null !== $name_field && array_key_exists( 'value', $data ) && preg_match( self::SENSITIVE_PATTERN, $name_field ) ) {
			$data['value'] = self::REDACTED;
			if ( array_key_exists( 'default', $data ) ) {
				$data['default'] = self::REDACTED;
			}
		}

		$out = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && preg_match( self::SENSITIVE_PATTERN, $key ) ) {
				$out[ $key ] = self::REDACTED;
				continue;
			}
			$out[ $key ] = is_array( $value ) ? self::redact( $value ) : $value;
		}
		return $out;
	}
}

add_action( 'plugins_loaded', array( __NAMESPACE__ . '\\Plugin', 'init' ) );
