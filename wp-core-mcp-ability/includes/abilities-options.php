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
							// Options take anything; the union is spelled out because
							// a property with no "type" makes core's schema validator
							// call _doing_it_wrong() and emit a PHP warning on every
							// single call.
							'type'        => array( 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ),
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

		if ( isset( self::REWRITE_OPTIONS[ $name ] ) ) {
			self::write_rewrite_option( $name, $input['value'] );
		} else {
			update_option( $name, $input['value'] );
		}

		$new = get_option( $name );

		return array(
			'name' => $name,
			'old'  => $old,
			'new'  => $new,
		);
	}

	/**
	 * Options that decide what the site's URLs look like, mapped to the
	 * WP_Rewrite setter that owns each one.
	 */
	const REWRITE_OPTIONS = array(
		'permalink_structure' => 'set_permalink_structure',
		'category_base'       => 'set_category_base',
		'tag_base'            => 'set_tag_base',
	);

	/**
	 * Writing one of these with update_option() looks like it worked and
	 * is worse than doing nothing: WordPress starts generating URLs in the
	 * new shape everywhere -- menus, canonical tags, sitemaps -- while the
	 * stored rewrite rules still describe the old one, so every new URL
	 * returns 404 and no error is raised anywhere. Measured on 7.1: after
	 * update-option set permalink_structure to /%postname%/, get_permalink()
	 * returned /audit-test/ and that URL 404'd, while the old dated URL kept
	 * answering 200 until the rules were flushed.
	 *
	 * wp-admin's own Permalinks screen goes through the WP_Rewrite setters
	 * and then calls flush_rewrite_rules() (wp-admin/options-permalink.php),
	 * so this does the same. The setters matter beyond the option write:
	 * set_permalink_structure() re-initialises the rewrite object and fires
	 * permalink_structure_changed, which other plugins listen for.
	 */
	private static function write_rewrite_option( string $name, $value ): void {
		global $wp_rewrite;

		$setter = self::REWRITE_OPTIONS[ $name ];

		if ( $wp_rewrite instanceof \WP_Rewrite && method_exists( $wp_rewrite, $setter ) ) {
			$wp_rewrite->$setter( is_string( $value ) ? $value : (string) $value );
		} else {
			update_option( $name, $value );
		}

		// The taxonomy permastructs are built once, on "init", from the
		// option values as they were when the request started, and
		// WP_Rewrite::init() does not rebuild them. Flushing without
		// rebuilding first regenerates the rules from the old base and
		// writes them back, so the new URLs 404 until something else
		// flushes again. Measured on 7.1: after category_base went from ""
		// to "konular", the permastruct was still /category/%category% and
		// /konular/announcements/ returned 404; calling this first made the
		// permastruct konular/%category% and the URL answer 200.
		if ( function_exists( 'create_initial_taxonomies' ) ) {
			create_initial_taxonomies();
		}

		flush_rewrite_rules();
	}
}
