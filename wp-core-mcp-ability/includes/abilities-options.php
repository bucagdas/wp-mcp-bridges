<?php
/**
 * Whitelisted site option abilities.
 *
 * @package WPCoreMCPAbility
 */

namespace WPCoreMCPAbility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Options {

	public static function register(): void {

		wp_register_ability(
			'wp-core-mcp/get-option',
			array(
				'label'               => __( 'Get a site option', 'wp-core-mcp-ability' ),
				'description'         => __( 'Returns the value of one whitelisted WordPress option (site identity, reading, discussion, permalink settings). Only options on a fixed whitelist are readable; everything else — including any option matching a sensitive-key pattern — is refused. Use list-options to see the full whitelist and current values.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'name' => array(
							'type'        => 'string',
							'enum'        => Plugin::OPTION_WHITELIST,
							'description' => 'Option name to read.',
						),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "name" and "value".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_get' ),
				'permission_callback' => array( __CLASS__, 'permission_read' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/list-options',
			array(
				'label'               => __( 'List whitelisted site options', 'wp-core-mcp-ability' ),
				'description'         => __( 'Returns the current value of every whitelisted WordPress option in one call (site identity, reading, discussion, permalink settings).', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Key-value map of whitelisted option name to current value.',
				),
				'execute_callback'    => array( __CLASS__, 'cb_list' ),
				'permission_callback' => array( __CLASS__, 'permission_read' ),
				'meta'                => Plugin::meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-core-mcp/update-option',
			array(
				'label'               => __( 'Update a site option', 'wp-core-mcp-ability' ),
				'description'         => __( 'Updates one whitelisted WordPress option. Only options on the fixed writable whitelist can be written (a narrower list than get-option/list-options\' readable one — notably siteurl and home are excluded, since a wrong value there can make the whole site unreachable with no recovery path through this API); everything else is refused. admin_email additionally requires confirm: true, since changing it takes effect immediately and bypasses the confirmation-email step wp-admin\'s own Settings form uses. Returns {old,new} read back after the write.', 'wp-core-mcp-ability' ),
				'category'            => Plugin::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'name'    => array(
							'type'        => 'string',
							'enum'        => Plugin::OPTION_WRITE_WHITELIST,
							'description' => 'Option name to update.',
						),
						'value'   => array(
							'description' => 'New value.',
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Required (must be true) when name is admin_email.',
						),
					),
					'required'             => array( 'name', 'value' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Object with "name", "old" and "new".',
				),
				'execute_callback'    => array( __CLASS__, 'cb_update' ),
				'permission_callback' => array( __CLASS__, 'permission_manage' ),
				'meta'                => Plugin::meta( false, false, true ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Permissions
	// ---------------------------------------------------------------------

	public static function permission_read( $input = null ): bool {
		return current_user_can( 'manage_options' );
	}

	public static function permission_manage( $input = null ): bool {
		return current_user_can( 'manage_options' );
	}

	// ---------------------------------------------------------------------
	// Callbacks
	// ---------------------------------------------------------------------

	public static function cb_get( $input ) {
		$name = (string) $input['name'];
		if ( ! in_array( $name, Plugin::OPTION_WHITELIST, true ) ) {
			return new \WP_Error( 'not_whitelisted', __( 'This option is not on the readable whitelist.', 'wp-core-mcp-ability' ) );
		}
		return array(
			'name'  => $name,
			'value' => get_option( $name ),
		);
	}

	public static function cb_list() {
		$out = array();
		foreach ( Plugin::OPTION_WHITELIST as $name ) {
			$out[ $name ] = get_option( $name );
		}
		return $out;
	}

	public static function cb_update( $input ) {
		$name = (string) $input['name'];
		if ( ! in_array( $name, Plugin::OPTION_WRITE_WHITELIST, true ) ) {
			return new \WP_Error( 'not_whitelisted', __( 'This option is not on the writable whitelist.', 'wp-core-mcp-ability' ) );
		}
		if ( in_array( $name, Plugin::OPTION_CONFIRM_REQUIRED, true ) && true !== ( $input['confirm'] ?? false ) ) {
			return new \WP_Error(
				'confirm_required',
				sprintf(
					/* translators: %s: option name */
					__( 'Pass confirm: true to update "%s" — this option controls where critical site notifications (including password resets) are sent.', 'wp-core-mcp-ability' ),
					$name
				)
			);
		}

		$old = get_option( $name );
		update_option( $name, $input['value'] );
		$new = get_option( $name );

		return array(
			'name' => $name,
			'old'  => $old,
			'new'  => $new,
		);
	}
}
