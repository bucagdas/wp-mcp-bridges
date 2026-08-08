<?php
/**
 * Plugin Name: Contact Form 7 MCP Ability
 * Plugin URI: https://github.com/bucagdas/wp-mcp-bridges/tree/main/contact-form-7-mcp-ability
 * Description: Full-coverage Contact Form 7 abilities for MCP. Form CRUD, form tags, mail templates, messages, additional settings, config validation, status and test submission.
 * Version: 1.0.4
 * Requires at least: 7.0
 * Requires PHP: 8.0
 * Author: bucagdas
 * Author URI: https://github.com/bucagdas/
 * Text Domain: contact-form-7-mcp-ability
 * Requires Plugins: contact-form-7
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace CF7MCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/abilities-forms.php';
require_once __DIR__ . '/includes/abilities-mail.php';
require_once __DIR__ . '/includes/abilities-site.php';

require_once __DIR__ . '/includes/plugin-update-checker/plugin-update-checker.php';
\YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::buildUpdateChecker(
	'https://raw.githubusercontent.com/bucagdas/wp-mcp-bridges/main/contact-form-7-mcp-ability/update.json',
	__FILE__,
	'contact-form-7-mcp-ability'
);

class Plugin {

	const CATEGORY = 'contact-form-7-mcp';

	/**
	 * Keys matching this pattern are stripped from read output and
	 * refused on write. CF7 core has no license/API-key surface itself,
	 * but third-party module settings can appear in additional_settings.
	 */
	const SENSITIVE_PATTERN = '/(api[_-]?key|token|secret|password|licen[cs]e|credential|authoriz)/i';

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Contact Form 7 MCP', 'contact-form-7-mcp-ability' ),
				'description' => __( 'Full-coverage abilities for Contact Form 7: form CRUD, form tags, mail templates, messages, settings, validation and test submission.', 'contact-form-7-mcp-ability' ),
			)
		);
	}

	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! class_exists( 'WPCF7_ContactForm' ) ) {
			return;
		}
		Forms::register();
		Mail::register();
		Site::register();
	}

	// ---------------------------------------------------------------------
	// Shared helpers
	// ---------------------------------------------------------------------

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

	public static function is_sensitive_key( string $key ): bool {
		return (bool) preg_match( self::SENSITIVE_PATTERN, $key );
	}

	/**
	 * Fetch a WPCF7_ContactForm by id, or a WP_Error.
	 */
	public static function get_form( int $id ) {
		$form = \wpcf7_contact_form( $id );
		if ( ! $form ) {
			return new \WP_Error( 'form_not_found', __( 'No contact form exists with the given ID.', 'contact-form-7-mcp-ability' ) );
		}
		return $form;
	}

	/**
	 * Serialize a WPCF7_ContactForm into a plain array for ability output.
	 */
	public static function form_to_array( \WPCF7_ContactForm $form ): array {
		return array(
			'id'                  => $form->id(),
			'name'                => $form->name(),
			'title'               => $form->title(),
			'locale'              => $form->locale(),
			'form'                => $form->prop( 'form' ),
			'mail'                => $form->prop( 'mail' ),
			'mail_2'              => $form->prop( 'mail_2' ),
			'messages'            => $form->prop( 'messages' ),
			'additional_settings' => $form->prop( 'additional_settings' ),
		);
	}

	// ---------------------------------------------------------------------
	// Shared permission callbacks
	// ---------------------------------------------------------------------

	public static function permission_read_forms( $input = null ): bool {
		return current_user_can( 'wpcf7_read_contact_forms' );
	}

	public static function permission_read_form( $input = null ) {
		$id = self::resolve_id( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return current_user_can( 'wpcf7_read_contact_form', $id );
	}

	public static function permission_edit_forms( $input = null ): bool {
		return current_user_can( 'wpcf7_edit_contact_forms' );
	}

	public static function permission_edit_form( $input = null ) {
		$id = self::resolve_id( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return current_user_can( 'wpcf7_edit_contact_form', $id );
	}

	public static function permission_delete_form( $input = null ) {
		$id = self::resolve_id( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return current_user_can( 'wpcf7_delete_contact_form', $id );
	}

	/**
	 * Resolves the required `id` from $input as a positive integer, or a
	 * WP_Error if missing/invalid. The MCP Adapter's execute-ability tool
	 * skips schema validation before calling an ability's permission
	 * callback, so a wrong/missing parameter would otherwise silently
	 * resolve to 0 here and surface only as a generic "Permission denied"
	 * — see docs/KOPRU-EKSIKLERI.md.
	 */
	private static function resolve_id( $input ) {
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		if ( $id <= 0 ) {
			return new \WP_Error( 'missing_id', __( 'Provide "id" (a positive integer form ID).', 'contact-form-7-mcp-ability' ) );
		}
		return $id;
	}
}

add_action( 'plugins_loaded', array( __NAMESPACE__ . '\\Plugin', 'init' ) );
